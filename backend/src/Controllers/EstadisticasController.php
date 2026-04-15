<?php

namespace App\Controllers;

use App\Core\Database;

/**
 * EstadisticasController — Dashboard de estadísticas (solo admin)
 */
class EstadisticasController
{
    /**
     * GET /api/estadisticas?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
     */
    public function index(): array
    {
        $pdo   = Database::connect();
        $desde = $_GET['desde'] ?? date('Y-m-d');
        $hasta = $_GET['hasta'] ?? date('Y-m-d');

        // Normalizar fechas
        $desdeFmt  = date('Y-m-d', strtotime($desde));
        $hastaFmt  = date('Y-m-d', strtotime($hasta));
        $hastaFin  = $hastaFmt . ' 23:59:59';

        // ==========================================
        // KPIs PRINCIPALES
        // ==========================================

        $totalLeads = (int)$pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE created_at BETWEEN ? AND ?")
            ->execute([$desdeFmt, $hastaFin]) && false ?: $this->scalar($pdo,
                "SELECT COUNT(*) FROM crm_leads WHERE created_at BETWEEN ? AND ?",
                [$desdeFmt, $hastaFin]
            );

        $cerradosPos = $this->scalar($pdo,
            "SELECT COUNT(*) FROM crm_leads WHERE estado = 'cerrado_positivo' AND updated_at BETWEEN ? AND ?",
            [$desdeFmt, $hastaFin]
        );

        $cerradosNeg = $this->scalar($pdo,
            "SELECT COUNT(*) FROM crm_leads WHERE estado = 'cerrado_negativo' AND updated_at BETWEEN ? AND ?",
            [$desdeFmt, $hastaFin]
        );

        $msgsEnt = $this->scalar($pdo,
            "SELECT COUNT(*) FROM crm_mensajes WHERE direccion = 'entrante' AND created_at BETWEEN ? AND ?",
            [$desdeFmt, $hastaFin]
        );

        $msgsSal = $this->scalar($pdo,
            "SELECT COUNT(*) FROM crm_mensajes WHERE direccion = 'saliente' AND created_at BETWEEN ? AND ?",
            [$desdeFmt, $hastaFin]
        );

        $promResp = $this->scalar($pdo,
            "SELECT AVG(tiempo_respuesta_seg) FROM crm_leads WHERE tiempo_respuesta_seg > 0 AND primer_respuesta_at BETWEEN ? AND ?",
            [$desdeFmt, $hastaFin]
        );

        // ==========================================
        // POR VENDEDOR
        // ==========================================

        $vendStmt = $pdo->prepare("
            SELECT
                u.id,
                u.nombre,
                COUNT(DISTINCT CASE WHEN l.created_at BETWEEN ? AND ? THEN l.id END)                                    AS leads_periodo,
                COUNT(DISTINCT CASE WHEN l.estado = 'cerrado_positivo' AND l.updated_at BETWEEN ? AND ? THEN l.id END)  AS cerrados_pos,
                COUNT(DISTINCT CASE WHEN l.estado = 'cerrado_negativo' AND l.updated_at BETWEEN ? AND ? THEN l.id END)  AS cerrados_neg,
                COUNT(DISTINCT CASE WHEN l.estado = 'asignado' THEN l.id END)                                           AS activos,
                AVG(CASE WHEN l.tiempo_respuesta_seg > 0 AND l.primer_respuesta_at BETWEEN ? AND ? THEN l.tiempo_respuesta_seg END) AS prom_resp_seg
            FROM usuarios u
            LEFT JOIN crm_leads l ON l.usuario_asignado_id = u.id
            WHERE u.activo = 1
            GROUP BY u.id, u.nombre
            ORDER BY leads_periodo DESC
        ");
        $vendStmt->execute([
            $desdeFmt, $hastaFin,
            $desdeFmt, $hastaFin,
            $desdeFmt, $hastaFin,
            $desdeFmt, $hastaFin,
        ]);
        $vendedores = $vendStmt->fetchAll();

        // Msgs enviados por vendedor
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
            $msgsMap[$r['uid']] = (int)$r['total'];
        }

        foreach ($vendedores as &$v) {
            $v['msgs_enviados'] = $msgsMap[$v['id']] ?? 0;
            $leads_p            = (int)$v['leads_periodo'];
            $cerr               = (int)$v['cerrados_pos'] + (int)$v['cerrados_neg'];
            $v['tasa_cierre']   = $leads_p > 0 ? round($cerr / $leads_p * 100, 1) : 0;
            $v['prom_resp_seg'] = $v['prom_resp_seg'] ? (int)$v['prom_resp_seg'] : null;
        }
        unset($v);

        // ==========================================
        // TOP INSTANCIAS
        // ==========================================

        $instStmt = $pdo->prepare("
            SELECT i.nombre,
                COUNT(DISTINCT CASE WHEN l.created_at BETWEEN ? AND ? THEN l.id END) AS leads,
                COUNT(DISTINCT CASE WHEN m.created_at BETWEEN ? AND ? AND m.direccion = 'saliente' THEN m.id END) AS msgs_sal
            FROM instancias i
            LEFT JOIN crm_leads    l ON l.instancia_id = i.id
            LEFT JOIN crm_mensajes m ON m.instancia_id = i.id
            GROUP BY i.id, i.nombre
            ORDER BY leads DESC
            LIMIT 10
        ");
        $instStmt->execute([$desdeFmt, $hastaFin, $desdeFmt, $hastaFin]);
        $topInstancias = $instStmt->fetchAll();

        return [
            'desde'          => $desdeFmt,
            'hasta'          => $hastaFmt,
            'kpi'            => [
                'total_leads'    => (int)$totalLeads,
                'cerrados_pos'   => (int)$cerradosPos,
                'cerrados_neg'   => (int)$cerradosNeg,
                'msgs_entrantes' => (int)$msgsEnt,
                'msgs_salientes' => (int)$msgsSal,
                'prom_resp_seg'  => $promResp ? (int)$promResp : null,
            ],
            'vendedores'     => $vendedores,
            'top_instancias' => $topInstancias,
        ];
    }

    private function scalar(\PDO $pdo, string $sql, array $params = []): mixed
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
