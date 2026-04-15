<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\EvolutionService;

class MessagesController
{
    public function recent(): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('
            SELECT cm.id, cl.numero, cm.contenido, cm.direccion, cm.created_at
            FROM crm_mensajes cm
            JOIN crm_leads cl ON cm.lead_id = cl.id
            ORDER BY cm.created_at DESC
            LIMIT 10
        ');
        return ['messages' => $stmt->fetchAll()];
    }

    /**
     * POST /api/messages/send
     * Body: { lead_id, mensaje }
     */
    public function send(): array
    {
        $input   = json_decode(file_get_contents('php://input'), true);
        $leadId  = (int)($input['lead_id'] ?? 0);
        $mensaje = trim($input['mensaje'] ?? '');
        $userId  = (int)($_REQUEST['_user_id'] ?? 0);

        if (!$leadId || !$mensaje) {
            http_response_code(400);
            return ['error' => 'lead_id y mensaje son requeridos'];
        }

        $pdo = Database::connect();

        // 1. Buscar el lead + instancia
        $stmt = $pdo->prepare('
            SELECT l.*, i.nombre AS instancia_nombre
            FROM crm_leads l
            JOIN instancias i ON l.instancia_id = i.id
            WHERE l.id = ?
        ');
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();

        if (!$lead) {
            http_response_code(404);
            return ['error' => 'Lead no encontrado'];
        }

        $numero      = $lead['numero'];
        $instanciaId = (int)$lead['instancia_id'];
        $instNombre  = $lead['instancia_nombre'];

        // 3. Enviar via Evolution API
        $evoOk   = false;
        $msgIdWa = null;
        $evoError = null;

        try {
            $evo    = new EvolutionService($instNombre);
            $result = $evo->enviarTexto($numero, $mensaje, $quotedMsgId);
            $evoOk  = !empty($result['success']);
            if ($evoOk) {
                $msgIdWa = $result['data']['key']['id'] ?? null;
            } else {
                $evoError = $result['data']['message'] ?? 'Error en Evolution API';
            }
        } catch (\RuntimeException $e) {
            $evoError = $e->getMessage();
        }

        // 5. Guardar en crm_mensajes (siempre, incluso si falla Evolution)
        $stmt = $pdo->prepare('
            INSERT INTO crm_mensajes
                (lead_id, instancia_id, direccion, tipo, contenido, mensaje_id_wa, enviado_por_usuario_id, created_at)
            VALUES (?, ?, \'saliente\', \'text\', ?, ?, ?, NOW())
        ');
        $stmt->execute([$leadId, $instanciaId, $mensaje, $msgIdWa, $userId ?: null]);
        $msgId = (int)$pdo->lastInsertId();

        // 6. Actualizar crm_leads
        $pdo->prepare('
            UPDATE crm_leads
            SET ultimo_mensaje = ?, ultimo_mensaje_at = NOW(), ultimo_mensaje_direccion = \'saliente\'
            WHERE id = ?
        ')->execute([mb_substr($mensaje, 0, 200), $leadId]);

        // Actualizar primer_respuesta_at si es la primera respuesta del agente
        if (empty($lead['primer_respuesta_at'])) {
            $pdo->prepare('
                UPDATE crm_leads SET primer_respuesta_at = NOW() WHERE id = ? AND primer_respuesta_at IS NULL
            ')->execute([$leadId]);
        }

        return [
            'ok'      => true,
            'id'      => $msgId,
            'enviado' => $evoOk,
            'msg'     => $evoOk
                ? 'Mensaje enviado correctamente'
                : 'Guardado pero no enviado a WhatsApp' . ($evoError ? ": $evoError" : ''),
        ];
    }
}
