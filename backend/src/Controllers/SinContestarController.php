<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\EvolutionService;

/**
 * SinContestarController — Leads sin respuesta (solo admin)
 */
class SinContestarController
{
    /**
     * GET /api/sin-contestar
     * Params: modo (nunca/pendiente/todos), vendedor_id, horas
     */
    public function list(): array
    {
        $pdo  = Database::connect();

        $modo          = $_GET['modo']        ?? 'todos';     // nunca | pendiente | todos
        $filtroVendedor = (int)($_GET['vendedor_id'] ?? 0);
        $filtroHoras   = (int)($_GET['horas']  ?? 0);

        $where  = "WHERE l.estado = 'asignado'";
        $params = [];

        if ($modo === 'nunca') {
            $where .= " AND EXISTS (SELECT 1 FROM crm_mensajes m WHERE m.lead_id = l.id AND m.direccion = 'entrante')";
            $where .= " AND NOT EXISTS (SELECT 1 FROM crm_mensajes m WHERE m.lead_id = l.id AND m.direccion = 'saliente')";
        } elseif ($modo === 'pendiente') {
            $where .= " AND EXISTS (
                SELECT 1 FROM crm_mensajes m1
                WHERE m1.lead_id = l.id AND m1.direccion = 'entrante'
                AND m1.created_at = (SELECT MAX(m2.created_at) FROM crm_mensajes m2 WHERE m2.lead_id = l.id)
            )";
        } else {
            // todos: nunca contestados + pendientes
            $where .= " AND (
                (EXISTS (SELECT 1 FROM crm_mensajes m WHERE m.lead_id = l.id AND m.direccion = 'entrante')
                 AND NOT EXISTS (SELECT 1 FROM crm_mensajes m WHERE m.lead_id = l.id AND m.direccion = 'saliente'))
                OR
                EXISTS (
                    SELECT 1 FROM crm_mensajes m1
                    WHERE m1.lead_id = l.id AND m1.direccion = 'entrante'
                    AND m1.created_at = (SELECT MAX(m2.created_at) FROM crm_mensajes m2 WHERE m2.lead_id = l.id)
                )
            )";
        }

        if ($filtroVendedor > 0) {
            $where  .= " AND l.usuario_asignado_id = ?";
            $params[] = $filtroVendedor;
        }

        if ($filtroHoras > 0) {
            $where  .= " AND l.ultimo_mensaje_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)";
            $params[] = $filtroHoras;
        }

        $stmt = $pdo->prepare("
            SELECT
                l.id,
                l.nombre,
                l.numero,
                l.ultimo_mensaje,
                l.ultimo_mensaje_at,
                l.marcado,
                l.usuario_asignado_id,
                u.nombre  AS vendedor_nombre,
                i.nombre  AS instancia_nombre,
                i.id      AS instancia_id,
                (SELECT COUNT(*) FROM crm_mensajes m WHERE m.lead_id = l.id AND m.direccion = 'entrante') AS msgs_entrantes,
                (SELECT COUNT(*) FROM crm_mensajes m WHERE m.lead_id = l.id AND m.direccion = 'saliente') AS msgs_salientes,
                TIMESTAMPDIFF(HOUR, l.ultimo_mensaje_at, NOW()) AS horas_sin_respuesta,
                GROUP_CONCAT(DISTINCT CONCAT(e.nombre, ':', e.color) SEPARATOR '|') AS etiquetas
            FROM crm_leads l
            LEFT JOIN usuarios  u  ON l.usuario_asignado_id = u.id
            LEFT JOIN instancias i  ON l.instancia_id = i.id
            LEFT JOIN crm_lead_etiquetas le ON l.id = le.lead_id
            LEFT JOIN crm_etiquetas      e  ON le.etiqueta_id = e.id AND e.activa = 1
            {$where}
            GROUP BY l.id
            ORDER BY l.ultimo_mensaje_at ASC
            LIMIT 500
        ");
        $stmt->execute($params);
        $leads = $stmt->fetchAll();

        // Agrupar por vendedor
        $porVendedor = [];
        foreach ($leads as $l) {
            $vid   = $l['usuario_asignado_id'] ?: 0;
            $vname = $l['vendedor_nombre']     ?: 'Sin asignar';
            if (!isset($porVendedor[$vid])) {
                $porVendedor[$vid] = ['nombre' => $vname, 'leads' => [], 'total' => 0];
            }
            // Parse etiquetas "nombre:color|..."
            $l['etiquetas'] = $l['etiquetas']
                ? array_map(function ($tag) {
                    [$nombre, $color] = explode(':', $tag, 2);
                    return ['nombre' => $nombre, 'color' => $color];
                }, explode('|', $l['etiquetas']))
                : [];

            $porVendedor[$vid]['leads'][] = $l;
            $porVendedor[$vid]['total']++;
        }

        uasort($porVendedor, fn($a, $b) => $b['total'] - $a['total']);

        // Vendedores para filtro
        $vendedoresStmt = $pdo->query("
            SELECT u.id, u.nombre
            FROM usuarios u
            WHERE u.activo = 1 AND u.crm_activo = 1
            ORDER BY u.nombre
        ");

        return [
            'vendedores_lista' => $vendedoresStmt->fetchAll(),
            'por_vendedor'     => array_values($porVendedor),
            'total'            => count($leads),
            'modo'             => $modo,
        ];
    }

    /**
     * POST /api/sin-contestar/enviar
     * Body: { lead_id, mensaje, instancia_id }
     */
    public function send(): array
    {
        $input      = json_decode(file_get_contents('php://input'), true);
        $leadId     = (int)($input['lead_id']     ?? 0);
        $mensaje    = trim($input['mensaje']      ?? '');
        $instanciaId = (int)($input['instancia_id'] ?? 0);
        $userId     = $_REQUEST['_user_id'] ?? null;

        if (!$leadId || !$mensaje) {
            http_response_code(400);
            return ['error' => 'lead_id y mensaje son requeridos'];
        }

        $pdo = Database::connect();

        // Obtener lead
        $stmt = $pdo->prepare('
            SELECT l.*, i.nombre AS inst_nombre, i.api_key AS inst_key
            FROM crm_leads l
            LEFT JOIN instancias i ON l.instancia_id = i.id
            WHERE l.id = ?
        ');
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();

        if (!$lead) {
            http_response_code(404);
            return ['error' => 'Lead no encontrado'];
        }

        // Si se especificó otra instancia, usarla
        if ($instanciaId && $instanciaId !== (int)$lead['instancia_id']) {
            $stmtI = $pdo->prepare('SELECT * FROM instancias WHERE id = ?');
            $stmtI->execute([$instanciaId]);
            $altInst = $stmtI->fetch();
            if ($altInst) {
                $lead['inst_nombre'] = $altInst['nombre'];
                $lead['inst_key']    = $altInst['api_key'];
            }
        }

        $instNombre = $lead['inst_nombre'] ?? '';
        if (empty($instNombre)) {
            http_response_code(422);
            return ['error' => 'El lead no tiene instancia asociada'];
        }

        // Enviar via EvolutionService
        try {
            $evo    = new EvolutionService($instNombre);
            $result = $evo->enviarTexto($lead['numero'], $mensaje);
        } catch (\RuntimeException $e) {
            http_response_code(503);
            return ['error' => $e->getMessage()];
        }

        if (empty($result['success'])) {
            http_response_code(502);
            return ['error' => 'Error al enviar mensaje', 'detail' => $result];
        }

        // Guardar mensaje en DB
        $stmtMsg = $pdo->prepare('
            INSERT INTO crm_mensajes
                (lead_id, instancia_id, direccion, tipo, contenido, enviado_por_usuario_id, created_at)
            VALUES (?, ?, "saliente", "texto", ?, ?, NOW())
        ');
        $stmtMsg->execute([
            $leadId,
            $lead['instancia_id'],
            $mensaje,
            $userId,
        ]);

        // Actualizar ultimo_mensaje del lead
        $pdo->prepare('UPDATE crm_leads SET ultimo_mensaje = ?, ultimo_mensaje_at = NOW() WHERE id = ?')
            ->execute([$mensaje, $leadId]);

        return ['ok' => true, 'message' => 'Mensaje enviado'];
    }
}
