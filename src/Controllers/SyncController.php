<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\EvolutionService;

/**
 * SyncController — Sincronización de datos desde Evolution API
 */
class SyncController
{
    /**
     * POST /api/sync/contacts
     * Importa contactos desde Evolution API para una instancia dada
     * Body: { instancia_id: N }
     */
    public function contacts(): array
    {
        $input      = json_decode(file_get_contents('php://input'), true);
        $instanciaId = (int)($input['instancia_id'] ?? 0);

        if (!$instanciaId) {
            http_response_code(400);
            return ['error' => 'instancia_id es requerido'];
        }

        $pdo = Database::connect();

        // Obtener instancia
        $stmt = $pdo->prepare('SELECT * FROM instancias WHERE id = ?');
        $stmt->execute([$instanciaId]);
        $inst = $stmt->fetch();

        if (!$inst) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        // Llamar Evolution API
        try {
            $evo      = new EvolutionService($inst['nombre']);
            $response = $evo->obtenerContactos();
        } catch (\RuntimeException $e) {
            http_response_code(503);
            return ['error' => 'Evolution API no disponible: ' . $e->getMessage()];
        }

        if (empty($response['success'])) {
            http_response_code(502);
            return ['error' => 'No se pudieron obtener contactos de Evolution', 'detail' => $response];
        }

        // La API devuelve un array de contactos
        $rawContacts = $response['data'] ?? [];

        // Normalizar: a veces viene anidado en 'contacts' o directo
        if (isset($rawContacts['contacts'])) {
            $rawContacts = $rawContacts['contacts'];
        } elseif (isset($rawContacts[0]['id'])) {
            // ya es array directo
        } elseif (!is_array($rawContacts)) {
            $rawContacts = [];
        }

        $importados  = 0;
        $actualizados = 0;
        $errores     = 0;

        $stmtSelect = $pdo->prepare('SELECT id FROM contactos WHERE instancia_id = ? AND numero = ?');
        $stmtInsert = $pdo->prepare('
            INSERT INTO contactos (instancia_id, numero, nombre, origen, activo, created_at)
            VALUES (?, ?, ?, \'sync\', 1, NOW())
        ');
        $stmtUpdate = $pdo->prepare('
            UPDATE contactos SET nombre = ?, updated_at = NOW() WHERE instancia_id = ? AND numero = ?
        ');

        foreach ($rawContacts as $c) {
            try {
                // Extraer número — viene como "5491122334455@s.whatsapp.net" o "5491122334455"
                $jid    = $c['remoteJid'] ?? $c['id'] ?? '';
                $numero = str_replace('@s.whatsapp.net', '', $jid);
                $numero = preg_replace('/[^0-9]/', '', $numero);

                if (empty($numero) || strlen($numero) < 8 || strlen($numero) > 15) continue;
                if (!is_numeric($numero)) continue;

                $nombre = $c['pushName'] ?? $c['name'] ?? $c['notify'] ?? $numero;

                $stmtSelect->execute([$instanciaId, $numero]);
                $existing = $stmtSelect->fetch();

                if ($existing) {
                    $stmtUpdate->execute([$nombre, $instanciaId, $numero]);
                    $actualizados++;
                } else {
                    $stmtInsert->execute([$instanciaId, $numero, $nombre]);
                    $importados++;
                }
            } catch (\Throwable $e) {
                $errores++;
            }
        }

        return [
            'ok'          => true,
            'instancia'   => $inst['nombre'],
            'total'       => count($rawContacts),
            'importados'  => $importados,
            'actualizados' => $actualizados,
            'errores'     => $errores,
        ];
    }

    /**
     * POST /api/sync/messages
     * Importa mensajes históricos desde Evolution API para una instancia
     * Body: { instancia_id: N, limit: 100 }
     */
    public function messages(): array
    {
        $input       = json_decode(file_get_contents('php://input'), true);
        $instanciaId = (int)($input['instancia_id'] ?? 0);
        $limit       = min((int)($input['limit'] ?? 100), 500);

        if (!$instanciaId) {
            http_response_code(400);
            return ['error' => 'instancia_id es requerido'];
        }

        $pdo = Database::connect();

        // Obtener instancia
        $stmt = $pdo->prepare('SELECT * FROM instancias WHERE id = ?');
        $stmt->execute([$instanciaId]);
        $inst = $stmt->fetch();

        if (!$inst) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        // Llamar Evolution API: POST /chat/findMessages/{instance}
        // Con filtro de mensajes entrantes y límite configurado
        try {
            $evo = new EvolutionService($inst['nombre']);
        } catch (\RuntimeException $e) {
            http_response_code(503);
            return ['error' => 'Evolution API no configurada: ' . $e->getMessage()];
        }

        // Hacer request directo para findMessages (no existe método en EvolutionService)
        $url     = \App\Core\Plan::evoUrl();
        $apiKey  = \App\Core\Plan::evoKey();
        $baseUrl = rtrim($url, '/');

        $payload = json_encode([
            'where' => ['key' => ['fromMe' => false]],
            'limit' => $limit,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $baseUrl . "/chat/findMessages/{$inst['nombre']}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $apiKey],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
            http_response_code(502);
            return ['error' => "Evolution findMessages falló (HTTP {$httpCode})", 'detail' => $curlErr ?: substr($response, 0, 200)];
        }

        $data = json_decode($response, true);
        // Evolution v2 envuelve en {messages: {records: [...]}}
        $records = $data['messages']['records'] ?? (is_array($data) ? $data : []);

        if (!is_array($records)) {
            http_response_code(502);
            return ['error' => 'Respuesta inesperada de Evolution', 'raw' => substr($response, 0, 300)];
        }

        // Preparar statements
        $stmtLead   = $pdo->prepare('SELECT id FROM crm_leads WHERE numero = ? AND instancia_id = ?');
        $stmtLeadIn = $pdo->prepare(
            'INSERT INTO crm_leads (instancia_id, numero, nombre, estado, created_at, updated_at) VALUES (?, ?, ?, \'nuevo\', NOW(), NOW())'
        );
        $stmtChkMsg = $pdo->prepare('SELECT id FROM crm_mensajes WHERE lead_id = ? AND mensaje_id_wa = ?');
        $stmtInsMsg = $pdo->prepare(
            'INSERT INTO crm_mensajes (lead_id, instancia_id, direccion, tipo, contenido, mensaje_id_wa, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmtUpdLead = $pdo->prepare(
            'UPDATE crm_leads SET ultimo_mensaje = ?, ultimo_mensaje_at = ?, updated_at = NOW() WHERE id = ?'
        );

        $importados = 0;
        $duplicados = 0;
        $errores    = 0;
        $leadCache  = [];

        foreach ($records as $m) {
            try {
                $msgKey  = $m['key'] ?? [];
                $msgId   = $msgKey['id'] ?? null;
                $fromMe  = (bool)($msgKey['fromMe'] ?? false);
                $remJid  = $msgKey['remoteJid'] ?? '';

                // Extraer número del JID
                $numero = preg_replace('/@.*/', '', $remJid);
                $numero = preg_replace('/[^0-9]/', '', $numero);
                if (strlen($numero) < 8 || strlen($numero) > 15) continue;

                // Timestamp
                $ts = $m['messageTimestamp'] ?? 0;
                if (is_array($ts)) $ts = $ts['low'] ?? 0;
                $ts = (int)$ts;
                $fechaMsg = $ts > 0 ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

                // Determinar tipo y contenido
                $msg       = $m['message'] ?? [];
                $tipo      = 'text';
                $contenido = '';

                if (!empty($msg['conversation'])) {
                    $contenido = $msg['conversation'];
                } elseif (!empty($msg['extendedTextMessage']['text'])) {
                    $contenido = $msg['extendedTextMessage']['text'];
                } elseif (!empty($msg['imageMessage'])) {
                    $tipo      = 'image';
                    $contenido = $msg['imageMessage']['caption'] ?? '[Imagen]';
                } elseif (!empty($msg['documentMessage'])) {
                    $tipo      = 'document';
                    $contenido = $msg['documentMessage']['fileName'] ?? '[Documento]';
                } elseif (!empty($msg['audioMessage'])) {
                    $tipo      = 'audio';
                    $contenido = '[Audio]';
                } elseif (!empty($msg['videoMessage'])) {
                    $tipo      = 'video';
                    $contenido = $msg['videoMessage']['caption'] ?? '[Video]';
                } elseif (!empty($msg['stickerMessage'])) {
                    $tipo      = 'sticker';
                    $contenido = '[Sticker]';
                } else {
                    $contenido = '[Mensaje]';
                }
                if (empty($contenido)) $contenido = '[Mensaje]';

                // Deduplicar por mensaje_id_wa
                if ($msgId) {
                    // Buscar o crear lead (cache por numero)
                    if (!isset($leadCache[$numero])) {
                        $stmtLead->execute([$numero, $instanciaId]);
                        $leadId = $stmtLead->fetchColumn();
                        if (!$leadId) {
                            $nombre = $m['pushName'] ?? $numero;
                            $stmtLeadIn->execute([$instanciaId, $numero, $nombre]);
                            $leadId = $pdo->lastInsertId();
                        }
                        $leadCache[$numero] = $leadId;
                    }
                    $leadId = $leadCache[$numero];

                    $stmtChkMsg->execute([$leadId, $msgId]);
                    if ($stmtChkMsg->fetchColumn()) {
                        $duplicados++;
                        continue;
                    }

                    $direccion = $fromMe ? 'saliente' : 'entrante';
                    $stmtInsMsg->execute([$leadId, $instanciaId, $direccion, $tipo, mb_substr($contenido, 0, 5000), $msgId, $fechaMsg]);
                    $importados++;

                    // Actualizar último mensaje del lead
                    $stmtUpdLead->execute([mb_substr($contenido, 0, 200), $fechaMsg, $leadId]);
                }
            } catch (\Throwable $e) {
                $errores++;
            }
        }

        return [
            'ok'         => true,
            'instancia'  => $inst['nombre'],
            'total_api'  => count($records),
            'importados' => $importados,
            'duplicados' => $duplicados,
            'errores'    => $errores,
        ];
    }

    /**
     * POST /api/sync/groups
     * Importa grupos y sus participantes desde Evolution API
     * Body: { instancia_id: N }
     */
    public function groups(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $instanciaId = (int)($input['instancia_id'] ?? 0);

        if (!$instanciaId) {
            http_response_code(400);
            return ['error' => 'instancia_id es requerido'];
        }

        $pdo = \App\Core\Database::connect();

        $stmt = $pdo->prepare('SELECT * FROM instancias WHERE id = ?');
        $stmt->execute([$instanciaId]);
        $inst = $stmt->fetch();

        if (!$inst) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        // Usar findContacts (rápido) en vez de fetchAllGroups (lento/timeout)
        try {
            $evo = new \App\Services\EvolutionService($inst['nombre']);
            $response = $evo->obtenerContactos();
        } catch (\RuntimeException $e) {
            http_response_code(503);
            return ['error' => 'Evolution API no disponible: ' . $e->getMessage()];
        }

        $allContacts = $response['data'] ?? $response;
        if (!is_array($allContacts)) $allContacts = [];

        // Filtrar solo grupos (@g.us)
        $rawGroups = array_filter($allContacts, fn($c) => str_contains($c['remoteJid'] ?? '', '@g.us'));

        $gruposImportados = 0;
        $errores = 0;

        // Reconectar DB (puede haber expirado durante la llamada a Evolution)
        $pdo = \App\Core\Database::connect();

        $stmtSelGrupo = $pdo->prepare('SELECT id FROM contactos WHERE instancia_id = ? AND numero = ? AND tipo = "grupo"');
        $stmtInsGrupo = $pdo->prepare('INSERT INTO contactos (instancia_id, tipo, numero, nombre, origen, activo, created_at) VALUES (?, "grupo", ?, ?, "sync", 1, NOW())');
        $stmtUpdGrupo = $pdo->prepare('UPDATE contactos SET nombre = ?, updated_at = NOW() WHERE id = ?');

        foreach ($rawGroups as $g) {
            try {
                $jid = $g['remoteJid'] ?? '';
                if (!str_contains($jid, '@g.us')) continue;

                $nombre = $g['pushName'] ?? $g['name'] ?? $jid;
                $numero = str_replace('@g.us', '', $jid);

                $stmtSelGrupo->execute([$instanciaId, $numero]);
                $existing = $stmtSelGrupo->fetch();

                if ($existing) {
                    $grupoId = $existing['id'];
                    $stmtUpdGrupo->execute([$nombre, $grupoId]);
                } else {
                    $stmtInsGrupo->execute([$instanciaId, $numero, $nombre]);
                    $grupoId = $pdo->lastInsertId();
                    $gruposImportados++;
                }

                $stmtSelGrupo->closeCursor();
            } catch (\Throwable $e) {
                $errores++;
            }
        }

        return [
            'ok' => true,
            'grupos_importados' => $gruposImportados,
            'errores' => $errores,
        ];
    }

    /**
     * GET /api/groups/{id}/participants
     * Devuelve participantes de un grupo
     */
    public function groupParticipants(): array
    {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            return ['error' => 'id requerido'];
        }

        $pdo = \App\Core\Database::connect();
        $stmt = $pdo->prepare('SELECT gp.numero, gp.nombre, gp.es_admin FROM grupo_participantes gp WHERE gp.contacto_id = ? ORDER BY gp.es_admin DESC, gp.nombre ASC');
        $stmt->execute([$id]);
        $participants = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'ok' => true,
            'total' => count($participants),
            'participantes' => $participants,
        ];
    }

    /**
     * GET /api/groups/{id}/export
     * Exporta números de participantes de un grupo (uno por línea)
     */
    public function exportGroupNumbers(): array
    {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            return ['error' => 'id requerido'];
        }

        $pdo = \App\Core\Database::connect();
        $stmt = $pdo->prepare('SELECT numero FROM grupo_participantes WHERE contacto_id = ? ORDER BY numero');
        $stmt->execute([$id]);
        $numbers = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return [
            'ok' => true,
            'total' => count($numbers),
            'numeros' => $numbers,
        ];
    }

    /**
     * POST /api/sync/group-participants
     * Sincroniza participantes de UN grupo desde Evolution API
     * Body: { contacto_id: N }
     * No duplica: usa ON DUPLICATE KEY UPDATE
     */
    public function groupParticipantsSync(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $contactoId = (int)($input['contacto_id'] ?? 0);

        if (!$contactoId) {
            http_response_code(400);
            return ['error' => 'contacto_id es requerido'];
        }

        $pdo = \App\Core\Database::connect();

        // Obtener el grupo
        $stmt = $pdo->prepare('SELECT c.*, i.nombre as inst_nombre FROM contactos c JOIN instancias i ON c.instancia_id = i.id WHERE c.id = ? AND c.tipo = "grupo"');
        $stmt->execute([$contactoId]);
        $grupo = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$grupo) {
            http_response_code(404);
            return ['error' => 'Grupo no encontrado'];
        }

        $groupJid = $grupo['numero'] . '@g.us';

        try {
            $evo = new \App\Services\EvolutionService($grupo['inst_nombre']);
            $res = $evo->obtenerParticipantesGrupo($groupJid);
        } catch (\RuntimeException $e) {
            http_response_code(503);
            return ['error' => 'Evolution API no disponible: ' . $e->getMessage()];
        }

        $participants = $res['participants'] ?? [];

        $insP = $pdo->prepare('INSERT INTO grupo_participantes (contacto_id, numero, nombre, es_admin, created_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), es_admin = VALUES(es_admin)');

        $importados = 0;
        $descartados = 0;

        foreach ($participants as $p) {
            $pJid = $p["phoneNumber"] ?? $p["id"] ?? "";
            $pNum = preg_replace('/@.*/', '', $pJid);
            $pNum = preg_replace('/[^0-9]/', '', $pNum);
            if (strlen($pNum) < 10 || strlen($pNum) > 15) { $descartados++; continue; }

            $pNom = $p['name'] ?? $p['pushName'] ?? $pNum;
            $esAdm = (($p['admin'] ?? '') === 'admin' || ($p['admin'] ?? '') === 'superadmin') ? 1 : 0;

            $insP->execute([$contactoId, $pNum, $pNom, $esAdm]);
            $importados++;
        }

        // Contar total en DB
        $total = $pdo->prepare('SELECT COUNT(*) FROM grupo_participantes WHERE contacto_id = ?');
        $total->execute([$contactoId]);
        $totalDB = (int)$total->fetchColumn();

        return [
            'ok' => true,
            'grupo' => $grupo['nombre'],
            'desde_evo' => count($participants),
            'importados' => $importados,
            'descartados' => $descartados,
            'total_db' => $totalDB,
        ];
    }
}
