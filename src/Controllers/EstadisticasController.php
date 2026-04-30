<?php

namespace App\Controllers;

use App\Core\Database;

/**
 * EstadisticasController — Dashboard de estadísticas (solo admin)
 *
 * v1.1 — Fix: agrega `leads_total` y `activos` independientes del rango de fechas
 *        para que la columna LEADS de Rendimiento por vendedor refleje la carga real
 *        del vendedor (todos sus leads asignados), no solo los creados en el período.
 *        Limpia código basura del scalar() en `total_leads`.
 *        Reescribe top_instancias para evitar producto cartesiano leads × mensajes.
 */
class EstadisticasController
{
    /**
     * GET /api/estadisticas?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
     */
    public function index(): array
    {
        $pdo   = Database::connect();
        $desde = $_GET["desde"] ?? date("Y-m-d");
        $hasta = $_GET["hasta"] ?? date("Y-m-d");

        // Normalizar fechas
        $desdeFmt  = date("Y-m-d", strtotime($desde));
        $hastaFmt  = date("Y-m-d", strtotime($hasta));
        $hastaFin  = $hastaFmt . " 23:59:59";

        // ==========================================
        // KPIs PRINCIPALES (siempre filtrados por período)
        // ==========================================

        $totalLeads = (int)$this->scalar($pdo,
            "SELECT COUNT(*) FROM crm_leads WHERE created_at BETWEEN ? AND ?",
            [$desdeFmt, $hastaFin]
        );

        $cerradosPos = (int)$this->scalar($pdo,
            "SELECT COUNT(*) FROM crm_leads WHERE estado = 'cerrado_positivo' AND updated_at BETWEEN ? AND ?",
            [$desdeFmt, $hastaFin]
        );

        $cerradosNeg = (int)$this->scalar($pdo,
            "SELECT COUNT(*) FROM crm_leads WHERE estado = 'cerrado_negativo' AND updated_at BETWEEN ? AND ?",
            [$desdeFmt, $hastaFin]
        );

        $msgsEnt = (int)$this->scalar($pdo,
            "SELECT COUNT(*) FROM crm_mensajes WHERE direccion = 'entrante' AND created_at BETWEEN ? AND ?",
            [$desdeFmt, $hastaFin]
        );

        $msgsSal = (int)$this->scalar($pdo,
            "SELECT COUNT(*) FROM crm_mensajes WHERE direccion = 'saliente' AND created_at BETWEEN ? AND ?",
            [$desdeFmt, $hastaFin]
        );

        $promResp = $this->scalar($pdo,
            "SELECT AVG(tiempo_respuesta_seg) FROM crm_leads WHERE tiempo_respuesta_seg > 0 AND primer_respuesta_at BETWEEN ? AND ?",
            [$desdeFmt, $hastaFin]
        );

        // ==========================================
        // POR VENDEDOR
        //  - leads_periodo: leads creados en el rango de fechas
        //  - leads_total:   TODOS los leads asignados al vendedor (sin filtro de fecha)
        //  - activos:       leads activos AHORA (no cerrados), sin filtro de fecha
        //  - cerrados_*:    cerrados en el rango de fechas
        //  - prom_resp_seg: promedio de respuesta en el rango de fechas
        // ==========================================

        $vendStmt = $pdo->prepare("
            SELECT
                u.id,
                u.nombre,
                COUNT(DISTINCT CASE WHEN l.created_at BETWEEN ? AND ? THEN l.id END) AS leads_periodo,
                COUNT(DISTINCT l.id)                                                  AS leads_total,
                COUNT(DISTINCT CASE WHEN l.estado IN ('nuevo','asignado') THEN l.id END) AS activos,
                COUNT(DISTINCT CASE WHEN l.estado = 'cerrado_positivo' AND l.updated_at BETWEEN ? AND ? THEN l.id END) AS cerrados_pos,
                COUNT(DISTINCT CASE WHEN l.estado = 'cerrado_negativo' AND l.updated_at BETWEEN ? AND ? THEN l.id END) AS cerrados_neg,
                AVG(CASE WHEN l.tiempo_respuesta_seg > 0 AND l.primer_respuesta_at BETWEEN ? AND ? THEN l.tiempo_respuesta_seg END) AS prom_resp_seg
            FROM usuarios u
            LEFT JOIN crm_leads l ON l.usuario_asignado_id = u.id
            WHERE u.activo = 1
            GROUP BY u.id, u.nombre
            ORDER BY leads_total DESC
        ");
        $vendStmt->execute([
            $desdeFmt, $hastaFin,
            $desdeFmt, $hastaFin,
            $desdeFmt, $hastaFin,
            $desdeFmt, $hastaFin,
        ]);
        $vendedores = $vendStmt->fetchAll();

        // Msgs enviados por vendedor (en el rango de fechas)
        $msgsPorUser = $pdo->prepare("
            SELECT enviado_por_usuario_id AS uid, COUNT(*) AS total
            FROM crm_mensajes
            WHERE direccion = 'saliente'
              AND enviado_por_usuario_id IS NOT NULL
              AND created_at BETWEEN ? AND ?
            GROUP BY enviado_por_usuario_id
        ");
        $msgsPorUser->execute([$desdeFmt, $hastaFin]);
        $msgsMap = [];
        foreach ($msgsPorUser->fetchAll() as $r) {
            $msgsMap[$r["uid"]] = (int)$r["total"];
        }

        foreach ($vendedores as &$v) {
            $v["leads_periodo"] = (int)$v["leads_periodo"];
            $v["leads_total"]   = (int)$v["leads_total"];
            $v["activos"]       = (int)$v["activos"];
            $v["cerrados_pos"]  = (int)$v["cerrados_pos"];
            $v["cerrados_neg"]  = (int)$v["cerrados_neg"];
            $v["msgs_enviados"] = $msgsMap[$v["id"]] ?? 0;

            // Tasa de cierre: sobre leads_total (carga real, no solo del período)
            $cerr = $v["cerrados_pos"] + $v["cerrados_neg"];
            $v["tasa_cierre"]   = $v["leads_total"] > 0
                ? round($cerr / $v["leads_total"] * 100, 1)
                : 0;
            $v["prom_resp_seg"] = $v["prom_resp_seg"] ? (int)$v["prom_resp_seg"] : null;
        }
        unset($v);

        // ==========================================
        // TOP INSTANCIAS
        //  - Queries separadas para evitar producto cartesiano leads × mensajes
        // ==========================================

        // Leads por instancia (en el rango)
        $leadsPorInst = $pdo->prepare("
            SELECT i.id, i.nombre, COUNT(l.id) AS leads
            FROM instancias i
            LEFT JOIN crm_leads l ON l.instancia_id = i.id AND l.created_at BETWEEN ? AND ?
            GROUP BY i.id, i.nombre
        ");
        $leadsPorInst->execute([$desdeFmt, $hastaFin]);
        $instMap = [];
        foreach ($leadsPorInst->fetchAll() as $r) {
            $instMap[$r["id"]] = [
                "nombre"   => $r["nombre"],
                "leads"    => (int)$r["leads"],
                "msgs_sal" => 0,
                "msgs_ent" => 0,
            ];
        }

        // Mensajes por instancia (en el rango), separado por dirección
        $msgsPorInst = $pdo->prepare("
            SELECT instancia_id, direccion, COUNT(*) AS total
            FROM crm_mensajes
            WHERE created_at BETWEEN ? AND ?
            GROUP BY instancia_id, direccion
        ");
        $msgsPorInst->execute([$desdeFmt, $hastaFin]);
        foreach ($msgsPorInst->fetchAll() as $r) {
            $iid = $r["instancia_id"];
            if (!isset($instMap[$iid])) continue;
            if ($r["direccion"] === "saliente") {
                $instMap[$iid]["msgs_sal"] = (int)$r["total"];
            } elseif ($r["direccion"] === "entrante") {
                $instMap[$iid]["msgs_ent"] = (int)$r["total"];
            }
        }

        // Ordenar y tomar top 10
        $topInstancias = array_values($instMap);
        usort($topInstancias, fn($a, $b) => $b["leads"] <=> $a["leads"]);
        $topInstancias = array_slice($topInstancias, 0, 10);

        return [
            "desde"          => $desdeFmt,
            "hasta"          => $hastaFmt,
            "kpi"            => [
                "total_leads"    => $totalLeads,
                "cerrados_pos"   => $cerradosPos,
                "cerrados_neg"   => $cerradosNeg,
                "msgs_entrantes" => $msgsEnt,
                "msgs_salientes" => $msgsSal,
                "prom_resp_seg"  => $promResp ? (int)$promResp : null,
            ],
            "vendedores"     => $vendedores,
            "top_instancias" => $topInstancias,
        ];
    }

    private function scalar(\PDO $pdo, string $sql, array $params = []): mixed
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
