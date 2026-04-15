<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\EvolutionService;

class TrackingController
{
    public function leads(): array
    {
        $pdo = Database::connect();
        $userId = $_REQUEST['_user_id'] ?? null;
        $rol = $_REQUEST['_user_rol'] ?? '';

        $etiquetas = $_GET['etiquetas'] ?? '';
        $vendedor = $_GET['vendedor'] ?? '';
        $soloSinResponder = ($_GET['solo_sin_responder'] ?? '') === '1';
        $soloMarcados = ($_GET['solo_marcados'] ?? '') === '1';

        $where = ["l.estado IN ('nuevo','asignado')"];
        $params = [];

        // User filter: non-admin only sees their own
        if ($rol !== 'admin') {
            $where[] = 'l.usuario_asignado_id = ?';
            $params[] = $userId;
        } elseif ($vendedor !== '') {
            $where[] = 'l.usuario_asignado_id = ?';
            $params[] = $vendedor;
        }

        // Tag filter
        if ($etiquetas !== '') {
            $tagIds = array_map('intval', explode(',', $etiquetas));
            $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
            $where[] = "l.id IN (SELECT lead_id FROM crm_lead_etiquetas WHERE etiqueta_id IN ($placeholders))";
            $params = array_merge($params, $tagIds);
        }

        if ($soloSinResponder) {
            $where[] = 'l.mensajes_sin_leer > 0';
        }

        if ($soloMarcados) {
            $where[] = 'l.marcado = 1';
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT l.id, l.nombre, l.numero, l.ultimo_mensaje, l.ultimo_mensaje_at,
                l.mensajes_sin_leer, l.marcado, l.estado,
                u.nombre as asignado_nombre,
                GROUP_CONCAT(DISTINCT e.nombre) as etiquetas,
                GROUP_CONCAT(DISTINCT e.color) as etiqueta_colores
            FROM crm_leads l
            LEFT JOIN usuarios u ON l.usuario_asignado_id = u.id
            LEFT JOIN crm_lead_etiquetas le ON le.lead_id = l.id
            LEFT JOIN crm_etiquetas e ON le.etiqueta_id = e.id
            WHERE $whereStr
            GROUP BY l.id
            ORDER BY l.ultimo_mensaje_at DESC
            LIMIT 500
        ");
        $stmt->execute($params);

        return ['leads' => $stmt->fetchAll()];
    }

    /**
     * POST /api/tracking/send
     * Envía un mensaje a leads seleccionados.
     * Body: { lead_ids[], mensaje, instancia_id? }
     * Retorna: { ok, enviados, errores }
     */
    public function send(): array
    {
        $input       = json_decode(file_get_contents('php://input'), true);
        $leadIds     = array_filter(array_map('intval', $input['lead_ids'] ?? []));
        $mensaje     = trim($input['mensaje'] ?? '');
        $instanciaId = isset($input['instancia_id']) ? (int)$input['instancia_id'] : null;
        $usuarioId   = (int)($_REQUEST['_user_id'] ?? 0);
        $userRol     = $_REQUEST['_user_rol'] ?? '';

        if (empty($leadIds) || !$mensaje) {
            http_response_code(400);
            return ['error' => 'lead_ids y mensaje son requeridos'];
        }

        $pdo = Database::connect();

        // Preparar statements
        $stmtLead = $pdo->prepare('
            SELECT l.id, l.numero, l.instancia_id, i.nombre AS inst_nombre
            FROM crm_leads l
            JOIN instancias i ON i.id = l.instancia_id
            WHERE l.id = ?
        ');
        $stmtMsg  = $pdo->prepare('
            INSERT INTO crm_mensajes (lead_id, instancia_id, direccion, tipo, contenido, enviado_por_usuario_id, created_at)
            VALUES (?, ?, \'saliente\', \'text\', ?, ?, NOW())
        ');
        $stmtUpdLead = $pdo->prepare('
            UPDATE crm_leads SET ultimo_mensaje = ?, ultimo_mensaje_at = NOW(), updated_at = NOW() WHERE id = ?
        ');

        $enviados = 0;
        $errores  = 0;
        $evoCache  = []; // Caché de EvolutionService por instancia_id

        foreach ($leadIds as $leadId) {
            $stmtLead->execute([$leadId]);
            $lead = $stmtLead->fetch();

            if (!$lead) {
                $errores++;
                continue;
            }

            // Non-admin solo puede enviar a sus leads
            if ($userRol !== 'admin') {
                // Verificar que el lead pertenece al usuario (verificación simple)
                $chk = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ? AND usuario_asignado_id = ?');
                $chk->execute([$leadId, $usuarioId]);
                if (!$chk->fetchColumn()) {
                    $errores++;
                    continue;
                }
            }

            // Instancia a usar: parámetro o la del lead
            $useInstId   = $instanciaId ?? (int)$lead['instancia_id'];
            $useInstNombre = null;

            if ($instanciaId && $instanciaId !== (int)$lead['instancia_id']) {
                // Obtener nombre de instancia especificada
                $stmtI = $pdo->prepare('SELECT nombre FROM instancias WHERE id = ?');
                $stmtI->execute([$instanciaId]);
                $useInstNombre = $stmtI->fetchColumn() ?: null;
            } else {
                $useInstNombre = $lead['inst_nombre'];
                $useInstId     = (int)$lead['instancia_id'];
            }

            if (!$useInstNombre) {
                $errores++;
                continue;
            }

            // Obtener o crear EvolutionService
            if (!isset($evoCache[$useInstId])) {
                try {
                    $evoCache[$useInstId] = new EvolutionService($useInstNombre);
                } catch (\RuntimeException $e) {
                    $errores++;
                    continue;
                }
            }
            $evo = $evoCache[$useInstId];

            try {
                $result = $evo->enviarTexto($lead['numero'], $mensaje);
                if (empty($result['success'])) {
                    $errores++;
                    continue;
                }

                // Guardar mensaje en CRM
                $stmtMsg->execute([$leadId, $useInstId, mb_substr($mensaje, 0, 5000), $usuarioId]);
                $stmtUpdLead->execute([mb_substr($mensaje, 0, 200), $leadId]);
                $enviados++;

                // Delay anti-spam entre mensajes
                if (count($leadIds) > 1) {
                    sleep(rand(2, 5));
                }
            } catch (\Throwable $e) {
                $errores++;
            }
        }

        return [
            'ok'      => true,
            'enviados' => $enviados,
            'errores' => $errores,
        ];
    }
}
