<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\EvolutionService;

/**
 * WebhookController — Recibe eventos de Evolution API
 *
 * Eventos procesados:
 *  - messages.upsert       → mensaje entrante → lead + crm_mensajes
 *  - messages.update       → actualización de estado (leído, entregado)
 *  - connection.update     → estado de instancia (open/close)
 *  - send.message          → confirmación mensaje saliente
 */
class WebhookController
{
    private \PDO $pdo;

    public function handle(): array
    {
        // Responder rápido — Evolution espera 200 inmediato
        http_response_code(200);

        $body = file_get_contents('php://input');
        if (!$body) return ['ok' => true];

        $data = json_decode($body, true);
        if (!$data) return ['ok' => true];

        $this->pdo = Database::connect();

        $event    = strtolower($data['event'] ?? '');
        $instance = $data['instance'] ?? '';

        $this->log("EVENT: $event | INSTANCE: $instance");

        match (true) {
            in_array($event, ['messages.upsert', 'messages_upsert']) => $this->handleMessage($data),
            in_array($event, ['messages.update', 'messages_update']) => $this->handleMessageUpdate($data),
            in_array($event, ['connection.update', 'connection_update']) => $this->handleConnection($data),
            default => null,
        };

        return ['ok' => true];
    }

    // ==========================================
    // MENSAJE ENTRANTE
    // ==========================================

    private function handleMessage(array $data): void
    {
        $instance = $data['instance'] ?? '';
        $msgData  = $data['data'] ?? [];
        $key      = $msgData['key'] ?? [];

        $remoteJid    = $key['remoteJid']    ?? '';
        $remoteJidAlt = $key['remoteJidAlt'] ?? '';
        $fromMe       = (bool)($key['fromMe'] ?? false);
        $messageId    = $key['id'] ?? '';

        // Ignorar propios y status (pero NO grupos — los procesamos aparte)
        if ($fromMe) return;
        if (str_contains($remoteJid, 'status@'))  return;

        // ¿Es un mensaje de grupo?
        $esGrupo = str_contains($remoteJid, '@g.us');

        $numero = '';
        if ($esGrupo) {
            // Para grupos: el "número" es el JID sin @g.us (ej: "120363426007404125").
            // Cabe en varchar(20): los JIDs de grupo de WA son 18 dígitos, o con guión "12345-67890" (<=20).
            $numero = str_replace('@g.us', '', $remoteJid);
            if (strlen($numero) > 20) {
                $this->log("WARN: JID de grupo > 20 chars, se trunca. jid=$remoteJid");
                $numero = substr($numero, 0, 20);
            }
        } else {
            // Individuales: extraer número real (soporte @s.whatsapp.net y @lid)
            if (!empty($remoteJidAlt) && str_contains($remoteJidAlt, '@s.whatsapp.net')) {
                $numero = str_replace('@s.whatsapp.net', '', $remoteJidAlt);
            }
            if (empty($numero) && str_contains($remoteJid, '@s.whatsapp.net')) {
                $numero = str_replace('@s.whatsapp.net', '', $remoteJid);
            }

            if (empty($numero) || !is_numeric($numero) || strlen($numero) < 8 || strlen($numero) > 15) {
                $this->log("WARN: número inválido remoteJid=$remoteJid");
                return;
            }
        }

        // Buscar instancia en DB
        $stmt = $this->pdo->prepare("SELECT id FROM instancias WHERE nombre = ?");
        $stmt->execute([$instance]);
        $inst = $stmt->fetch();
        if (!$inst) {
            $this->log("WARN: instancia no encontrada: $instance");
            return;
        }
        $instanciaId = (int)$inst['id'];

        // Deduplicar
        $dup = $this->pdo->prepare("SELECT id FROM crm_mensajes WHERE mensaje_id_wa = ? AND instancia_id = ?");
        $dup->execute([$messageId, $instanciaId]);
        if ($dup->fetch()) return;

        // Extraer contenido
        $message   = $msgData['message'] ?? [];
        $pushName  = $msgData['pushName'] ?? '';
        $timestamp = isset($msgData['messageTimestamp']) ? (int)$msgData['messageTimestamp'] : time();

        [$tipo, $contenido] = $this->extractContent($message);
        $metadata = $this->extractMetadata($message, $remoteJid, $messageId, $tipo);

        // Buscar o crear contacto (tipo según $esGrupo)
        $tipoContacto = $esGrupo ? 'grupo' : 'individual';
        $stmt = $this->pdo->prepare("SELECT id, nombre FROM contactos WHERE instancia_id = ? AND numero = ?");
        $stmt->execute([$instanciaId, $numero]);
        $contacto = $stmt->fetch();

        if (!$contacto) {
            // Auto-crear contacto para que el lead lo pueda referenciar
            $nombreContacto = $esGrupo ? ($pushName ?: "Grupo $numero") : ($pushName ?: $numero);
            $ins = $this->pdo->prepare("
                INSERT INTO contactos (instancia_id, tipo, numero, nombre, origen, activo, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'chat', 1, NOW(), NOW())
            ");
            $ins->execute([$instanciaId, $tipoContacto, $numero, $nombreContacto]);
            $contacto = [
                'id'     => (int)$this->pdo->lastInsertId(),
                'nombre' => $nombreContacto,
            ];
        }

        $nombreDisplay = $pushName ?: ($contacto['nombre'] ?? $numero);

        // Buscar o crear lead
        $stmt = $this->pdo->prepare("SELECT * FROM crm_leads WHERE instancia_id = ? AND numero = ?");
        $stmt->execute([$instanciaId, $numero]);
        $lead = $stmt->fetch();

        if (!$lead) {
            $stmt = $this->pdo->prepare("
                INSERT INTO crm_leads
                    (instancia_id, contacto_id, numero, nombre, estado, ultimo_mensaje, ultimo_mensaje_at, mensajes_sin_leer)
                VALUES (?, ?, ?, ?, 'nuevo', ?, FROM_UNIXTIME(?), 1)
            ");
            $stmt->execute([
                $instanciaId,
                $contacto['id'],
                $numero,
                $nombreDisplay,
                mb_substr($contenido, 0, 200),
                $timestamp,
            ]);
            $leadId = (int)$this->pdo->lastInsertId();

            // Auto-asignar SOLO individuales (grupos quedan con usuario_asignado_id=NULL → los ven todos)
            if (!$esGrupo) {
                $this->autoAssignLead($leadId, $instanciaId);
            }
        } else {
            $leadId = (int)$lead['id'];

            // Actualizar nombre si no tenía uno y ahora llegó pushName
            if ($pushName && ($lead['nombre'] === $numero || empty($lead['nombre']))) {
                $this->pdo->prepare("UPDATE crm_leads SET nombre = ? WHERE id = ?")
                    ->execute([$nombreDisplay, $leadId]);
            }

            // Actualizar último mensaje
            $this->pdo->prepare("
                UPDATE crm_leads SET
                    ultimo_mensaje = ?, ultimo_mensaje_at = FROM_UNIXTIME(?),
                    mensajes_sin_leer = mensajes_sin_leer + 1,
                    marcado = 1
                WHERE id = ?
            ")->execute([mb_substr($contenido, 0, 200), $timestamp, $leadId]);
        }

        // Guardar mensaje
        $this->pdo->prepare("
            INSERT INTO crm_mensajes
                (lead_id, instancia_id, mensaje_id_wa, tipo, contenido, direccion, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, 'entrante', ?, FROM_UNIXTIME(?))
        ")->execute([$leadId, $instanciaId, $messageId, $tipo, $contenido, $metadata, $timestamp]);

        // Actualizar primer tiempo de respuesta si aplica
        if (!empty($lead) && !empty($lead['primer_mensaje_at']) && empty($lead['primer_respuesta_at'])) {
            // ya hay mensajes salientes previos → no aplica
        }

        $this->log("MSG OK lead=$leadId numero=$numero tipo=$tipo grupo=" . ($esGrupo ? '1' : '0'));

        // === AUTO-RESPUESTA === (solo para individuales — en grupos no tiene sentido)
        if (!$esGrupo) {
            $esNuevoLead = empty($lead);
            $this->procesarAutoRespuesta($leadId, $instanciaId, $inst['nombre'], $numero, $esNuevoLead);
        }
    }

    /**
     * Verifica y envía auto-respuesta si corresponde
     */
    private function procesarAutoRespuesta(int $leadId, int $instanciaId, string $instNombre, string $numero, bool $esNuevoLead): void
    {
        try {
            // Obtener auto-respuestas activas
            $stmt = $this->pdo->query('
                SELECT * FROM crm_auto_respuesta WHERE activa = 1 ORDER BY id ASC LIMIT 1
            ');
            $autoReply = $stmt->fetch();

            if (!$autoReply) return;

            // Si solo_nuevos=1, solo responder a leads nuevos
            if ($autoReply['solo_nuevos'] && !$esNuevoLead) {
                // Verificar si ya se envió auto-respuesta antes
                $chk = $this->pdo->prepare('SELECT auto_respuesta_enviada FROM crm_leads WHERE id = ?');
                $chk->execute([$leadId]);
                $row = $chk->fetch();
                if (!empty($row['auto_respuesta_enviada'])) return;
            }

            // Verificar que no se envió ya para este lead
            $chk = $this->pdo->prepare('SELECT auto_respuesta_enviada FROM crm_leads WHERE id = ?');
            $chk->execute([$leadId]);
            $row = $chk->fetch();
            if (!empty($row['auto_respuesta_enviada'])) return;

            $mensajeAuto = $autoReply['mensaje'];
            if (empty($mensajeAuto)) return;

            // Enviar via Evolution
            $evo    = new EvolutionService($instNombre);
            $result = $evo->enviarTexto($numero, $mensajeAuto);

            if (!empty($result['success'])) {
                $msgIdWa = $result['data']['key']['id'] ?? null;

                // Guardar en crm_mensajes como saliente
                $this->pdo->prepare('
                    INSERT INTO crm_mensajes
                        (lead_id, instancia_id, direccion, tipo, contenido, mensaje_id_wa, created_at)
                    VALUES (?, ?, \'saliente\', \'text\', ?, ?, NOW())
                ')->execute([$leadId, $instanciaId, $mensajeAuto, $msgIdWa]);

                // Marcar auto_respuesta_enviada en el lead
                $this->pdo->prepare('
                    UPDATE crm_leads SET auto_respuesta_enviada = 1, ultimo_mensaje_direccion = \'saliente\' WHERE id = ?
                ')->execute([$leadId]);

                $this->log("AUTO-REPLY sent to lead=$leadId numero=$numero");
            }
        } catch (\Throwable $e) {
            $this->log("AUTO-REPLY ERROR: " . $e->getMessage());
        }
    }

    // ==========================================
    // ACTUALIZACIÓN DE ESTADO DE MENSAJE
    // ==========================================

    private function handleMessageUpdate(array $data): void
    {
        $updates = $data['data'] ?? [];
        if (!isset($updates[0])) $updates = [$updates];

        foreach ($updates as $upd) {
            $msgId  = $upd['key']['id']     ?? '';
            $status = strtolower($upd['update']['status'] ?? '');
            if (!$msgId || !$status) continue;

            $estado = match ($status) {
                'delivery_ack', '3' => 'entregado',
                'read', '4'         => 'leido',
                'played', '5'       => 'reproducido',
                default             => null,
            };

            if ($estado) {
                $this->pdo->prepare("UPDATE crm_mensajes SET estado = ? WHERE mensaje_id_wa = ?")
                    ->execute([$estado, $msgId]);
            }
        }
    }

    // ==========================================
    // ESTADO DE CONEXIÓN DE INSTANCIA
    // ==========================================

    private function handleConnection(array $data): void
    {
        $instance = $data['instance'] ?? '';
        $state    = strtolower($data['data']['state'] ?? '');

        if (!$instance || !$state) return;

        $estado = match ($state) {
            'open'       => 'conectado',
            'connecting' => 'escaneando',
            'close'      => 'desconectado',
            default      => null,
        };

        if (!$estado) return;

        $this->pdo->prepare("UPDATE instancias SET estado = ? WHERE nombre = ?")
            ->execute([$estado, $instance]);

        $this->log("CONNECTION: $instance → $estado");
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function extractContent(array $message): array
    {
        if (!empty($message['conversation'])) {
            return ['text', $message['conversation']];
        }
        if (!empty($message['extendedTextMessage']['text'])) {
            return ['text', $message['extendedTextMessage']['text']];
        }
        if (!empty($message['imageMessage'])) {
            return ['image', $message['imageMessage']['caption'] ?? '[Imagen]'];
        }
        if (!empty($message['documentMessage'])) {
            return ['document', $message['documentMessage']['fileName'] ?? '[Documento]'];
        }
        if (!empty($message['audioMessage'])) {
            $secs = (int)($message['audioMessage']['seconds'] ?? 0);
            $dur  = $secs > 0 ? floor($secs / 60) . ':' . str_pad($secs % 60, 2, '0', STR_PAD_LEFT) : '';
            return ['audio', $dur ? "[Audio $dur]" : '[Audio]'];
        }
        if (!empty($message['videoMessage'])) {
            return ['video', $message['videoMessage']['caption'] ?? '[Video]'];
        }
        if (!empty($message['stickerMessage'])) {
            return ['sticker', '[Sticker]'];
        }
        if (!empty($message['locationMessage'])) {
            return ['location', '[Ubicación]'];
        }
        if (!empty($message['contactMessage'])) {
            return ['contact', '[Contacto]'];
        }
        return ['text', '[Mensaje no soportado]'];
    }

    private function extractMetadata(array $message, string $remoteJid, string $messageId, string $tipo): ?string
    {
        $meta = [];

        // Contexto de anuncio Meta
        $ctxInfo = $message['extendedTextMessage']['contextInfo']
            ?? $message['messageContextInfo']
            ?? null;

        if ($ctxInfo && !empty($ctxInfo['externalAdReply'])) {
            $ad = $ctxInfo['externalAdReply'];
            $meta['ad'] = [
                'title'       => $ad['title']      ?? null,
                'body'        => isset($ad['body']) ? mb_substr($ad['body'], 0, 500) : null,
                'source_url'  => $ad['sourceUrl']  ?? null,
                'source_type' => $ad['sourceType'] ?? null,
            ];
        }

        // Guardar key para descargar media después
        if (in_array($tipo, ['audio', 'image', 'video', 'document'])) {
            $meta['media_key'] = [
                'key' => ['remoteJid' => $remoteJid, 'id' => $messageId, 'fromMe' => false],
                'message_type' => $tipo,
            ];
        }

        return $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
    }

    private function log(string $msg): void
    {
        $dir = sys_get_temp_dir() . '/crm_webhooks';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $file = $dir . '/webhook_' . date('Y-m-d') . '.log';
        @file_put_contents($file, '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
    }
    /**
     * Auto-asigna un lead nuevo según config de distribución
     */
    private function autoAssignLead(int $leadId, int $instanciaId): void
    {
        $cfg = $this->pdo->query("SELECT * FROM crm_config LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        if (!$cfg || !$cfg['auto_asignar']) return;

        $modo = $cfg['modo_distribucion'] ?? 'round_robin';
        // Fallback global si usuarios_instancias.max_chats_activos = 0 (sin límite)
        $maxGlobal = (int)($cfg['max_chats_por_usuario'] ?? 0);

        // Candidatos:
        // - Pertenecen a la instancia (usuarios_instancias).
        // - Están online y activos.
        // - max_effective = max_chats_activos (si >0) o $maxGlobal (si >0) o 9999 (sin tope).
        // - chats_activos_actuales: leads activos (nuevo/asignado) SIN CONTAR GRUPOS.
        // - HAVING: no han llegado al tope.
        $stmt = $this->pdo->prepare("
            SELECT
              u.id,
              u.nombre,
              ui.max_chats_activos,
              (
                SELECT COUNT(*)
                FROM crm_leads l
                LEFT JOIN contactos c ON c.id = l.contacto_id
                WHERE l.usuario_asignado_id = u.id
                  AND l.instancia_id        = ?
                  AND l.estado IN ('nuevo','asignado')
                  AND (c.tipo IS NULL OR c.tipo <> 'grupo')
              ) AS chats_activos
            FROM usuarios u
            JOIN usuarios_instancias ui ON ui.usuario_id = u.id AND ui.instancia_id = ?
            WHERE u.crm_online = 1 AND u.crm_activo = 1 AND u.activo = 1
            HAVING (ui.max_chats_activos = 0 AND (? = 0 OR chats_activos < ?))
                OR (ui.max_chats_activos > 0 AND chats_activos < ui.max_chats_activos)
            ORDER BY u.id ASC
        ");
        $stmt->execute([$instanciaId, $instanciaId, $maxGlobal, $maxGlobal]);
        $candidatos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($candidatos)) {
            $this->log("AUTO-ASSIGN: sin candidatos con cupo para instancia $instanciaId (lead $leadId queda sin asignar)");
            return;
        }

        $elegido = null;

        if ($modo === 'least_chats') {
            usort($candidatos, fn($a, $b) => $a['chats_activos'] <=> $b['chats_activos']);
            $elegido = $candidatos[0];
        } else {
            // round-robin: elegir al que sigue después del último asignado
            $ultimo = $this->pdo->prepare("
                SELECT usuario_asignado_id FROM crm_leads
                WHERE instancia_id = ? AND usuario_asignado_id IS NOT NULL
                ORDER BY asignado_at DESC LIMIT 1
            ");
            $ultimo->execute([$instanciaId]);
            $ultimoId = (int)$ultimo->fetchColumn();

            $found = false;
            foreach ($candidatos as $c) {
                if ($found) { $elegido = $c; break; }
                if ($c['id'] == $ultimoId) $found = true;
            }
            if (!$elegido) $elegido = $candidatos[0];
        }

        $this->pdo->prepare("
            UPDATE crm_leads SET usuario_asignado_id = ?, estado = 'asignado', asignado_at = NOW() WHERE id = ?
        ")->execute([$elegido['id'], $leadId]);

        $maxLog = (int)$elegido['max_chats_activos'] ?: "global=$maxGlobal";
        $this->log("AUTO-ASSIGN: lead $leadId -> usuario {$elegido['nombre']} (id={$elegido['id']}, activos={$elegido['chats_activos']}, max=$maxLog, modo=$modo)");
    }
}
