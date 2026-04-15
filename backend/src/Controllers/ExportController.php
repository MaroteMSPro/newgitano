<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\EvolutionService;

/**
 * ExportController — Exportar contactos, chats y grupos desde Evolution API
 */
class ExportController
{
    // ==========================================
    // GET /api/export/instances
    // Lista instancias disponibles para elegir
    // ==========================================
    public function instances(): array
    {
        $pdo    = Database::connect();
        $userId = $_REQUEST['_user_id'] ?? null;
        $rol    = $_REQUEST['_user_rol'] ?? '';

        // Detectar si la tabla tiene 'instancia_id' o usa 'nombre' como identificador
        $cols      = $pdo->query("SHOW COLUMNS FROM instancias")->fetchAll(\PDO::FETCH_COLUMN);
        $hasInstId = in_array('instancia_id', $cols, true);

        $idField = $hasInstId ? 'instancia_id' : 'nombre';
        $select  = "SELECT id, nombre, {$idField} AS instancia_id, estado FROM instancias";

        // Filtro de estado compatible con ambos schemas
        $estadoFilter = $hasInstId
            ? "estado != 'eliminado'"
            : "estado IN ('conectado','desconectado','escaneando')";

        if ($rol === 'admin') {
            $stmt = $pdo->query("{$select} WHERE {$estadoFilter} ORDER BY nombre");
        } else {
            $stmt = $pdo->prepare("
                {$select}
                JOIN usuarios_instancias ui ON ui.instancia_id = id AND ui.usuario_id = ?
                WHERE {$estadoFilter}
                ORDER BY nombre
            ");
            $stmt->execute([$userId]);
        }

        return ['instances' => $stmt->fetchAll()];
    }

    // ==========================================
    // GET /api/export/contacts?instance_id=X
    // Retorna contactos de una instancia
    // ==========================================
    public function contacts(): array
    {
        $instanciaId = $_GET['instance_id'] ?? null;

        if (!$instanciaId) {
            http_response_code(400);
            return ['error' => 'instance_id requerido'];
        }

        $evo = $this->getEvo($instanciaId);
        if (!$evo) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        $res = $evo->obtenerContactos();

        // Evolution API devuelve array directo o dentro de 'data'
        $contactos = $res['data'] ?? $res;
        if (!is_array($contactos)) $contactos = [];

        // Limpiar y normalizar
        $resultado = [];
        foreach ($contactos as $c) {
            $jid = $c['id'] ?? $c['remoteJid'] ?? '';
            // Excluir grupos (contienen @g.us)
            if (str_contains($jid, '@g.us')) continue;
            $numero = str_replace(['@s.whatsapp.net', '@c.us'], '', $jid);
            $resultado[] = [
                'numero'  => $numero,
                'nombre'  => $c['pushName'] ?? $c['name'] ?? $c['notify'] ?? '',
                'jid'     => $jid,
            ];
        }

        return [
            'total'     => count($resultado),
            'contactos' => $resultado,
        ];
    }

    // ==========================================
    // GET /api/export/chats?instance_id=X
    // Retorna chats (individuales + grupos)
    // ==========================================
    public function chats(): array
    {
        $instanciaId = $_GET['instance_id'] ?? null;
        $tipo        = $_GET['tipo'] ?? 'todos'; // todos | individuales | grupos

        if (!$instanciaId) {
            http_response_code(400);
            return ['error' => 'instance_id requerido'];
        }

        $evo = $this->getEvo($instanciaId);
        if (!$evo) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        $res   = $evo->obtenerChats();
        $chats = $res['data'] ?? $res;
        if (!is_array($chats)) $chats = [];

        $resultado = [];
        foreach ($chats as $c) {
            $jid      = $c['id'] ?? $c['remoteJid'] ?? '';
            $esGrupo  = str_contains($jid, '@g.us');

            if ($tipo === 'individuales' && $esGrupo) continue;
            if ($tipo === 'grupos' && !$esGrupo) continue;

            $resultado[] = [
                'jid'           => $jid,
                'nombre'        => $c['name'] ?? $c['pushName'] ?? $c['subject'] ?? $jid,
                'tipo'          => $esGrupo ? 'grupo' : 'individual',
                'sin_leer'      => $c['unreadCount'] ?? 0,
                'ultimo_mensaje'=> $c['lastMessage']['messageTimestamp'] ?? null,
            ];
        }

        return [
            'total' => count($resultado),
            'chats' => $resultado,
        ];
    }

    // ==========================================
    // GET /api/export/groups?instance_id=X&sync=1
    // Retorna grupos desde DB local. Si no hay o sync=1, inicia scan chunk por chunk.
    // GET /api/export/groups?instance_id=X&scan_page=N  → escanea chunk a partir de página N
    // ==========================================
    public function groups(): array
    {
        $instanciaId = $_GET['instance_id'] ?? null;
        $forceSync   = ($_GET['sync']      ?? '0') === '1';
        $scanPage    = isset($_GET['scan_page']) ? (int)$_GET['scan_page'] : null;

        if (!$instanciaId) {
            http_response_code(400);
            return ['error' => 'instance_id requerido'];
        }

        $pdo = Database::connect();

        // ─── Modo scan progresivo ───────────────────────────────────────────
        if ($scanPage !== null || $forceSync) {
            $evo = $this->getEvo($instanciaId);
            if (!$evo) {
                http_response_code(404);
                return ['error' => 'Instancia no encontrada'];
            }

            $page  = ($scanPage ?? 1);
            $chunk = $evo->escanearChunkGrupos($page, 15); // 15 páginas × 50 msgs = 750 msgs por chunk

            // Guardar grupos nuevos detectados en este chunk
            $upsert = $pdo->prepare("
                INSERT INTO wa_grupos (instancia_id, jid, nombre, descripcion, size)
                VALUES (?, ?, ?, '', 0)
                ON DUPLICATE KEY UPDATE synced_at = synced_at
            ");
            foreach ($chunk['grupos'] as $g) {
                if (empty($g['jid'])) continue;
                $upsert->execute([$instanciaId, $g['jid'], $g['jid']]);
            }

            // Leer DB actual
            $stmt = $pdo->prepare("SELECT jid, nombre, descripcion, size, synced_at FROM wa_grupos WHERE instancia_id = ? ORDER BY nombre ASC");
            $stmt->execute([$instanciaId]);
            $grupos = $stmt->fetchAll();

            return [
                'total'       => count($grupos),
                'grupos'      => $grupos,
                'from_cache'  => false,
                'scanning'    => $chunk['nextPage'] !== null,
                'next_page'   => $chunk['nextPage'],
                'total_pages' => $chunk['totalPages'],
                'current_page'=> $page,
                'synced_at'   => $grupos[0]['synced_at'] ?? null,
            ];
        }

        // ─── Modo normal: leer desde DB local ─────────────────────────────
        $stmt = $pdo->prepare("SELECT jid, nombre, descripcion, size, synced_at FROM wa_grupos WHERE instancia_id = ? ORDER BY nombre ASC");
        $stmt->execute([$instanciaId]);
        $cached = $stmt->fetchAll();
        if (!empty($cached)) {
            return [
                'total'      => count($cached),
                'grupos'     => $cached,
                'from_cache' => true,
                'scanning'   => false,
                'synced_at'  => $cached[0]['synced_at'] ?? null,
            ];
        }

        // No hay cache → arrancar scan desde página 1
        $evo = $this->getEvo($instanciaId);
        if (!$evo) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        $chunk = $evo->escanearChunkGrupos(1, 15);
        $upsert = $pdo->prepare("
            INSERT INTO wa_grupos (instancia_id, jid, nombre, descripcion, size)
            VALUES (?, ?, ?, '', 0)
            ON DUPLICATE KEY UPDATE synced_at = synced_at
        ");
        foreach ($chunk['grupos'] as $g) {
            if (empty($g['jid'])) continue;
            $upsert->execute([$instanciaId, $g['jid'], $g['jid']]);
        }

        $stmt = $pdo->prepare("SELECT jid, nombre, descripcion, size, synced_at FROM wa_grupos WHERE instancia_id = ? ORDER BY nombre ASC");
        $stmt->execute([$instanciaId]);
        $grupos = $stmt->fetchAll();

        return [
            'total'       => count($grupos),
            'grupos'      => $grupos,
            'from_cache'  => false,
            'scanning'    => $chunk['nextPage'] !== null,
            'next_page'   => $chunk['nextPage'],
            'total_pages' => $chunk['totalPages'],
            'current_page'=> 1,
            'synced_at'   => $grupos[0]['synced_at'] ?? null,
        ];
    }

    // ==========================================
    // GET /api/export/search-groups-by-number?instance_id=X&numero=549...
    // Busca en el historial los grupos donde participó ese número
    // ==========================================
    public function searchGroupsByNumber(): array
    {
        $instanciaId = $_GET['instance_id'] ?? null;
        $numero      = preg_replace('/\D/', '', $_GET['numero'] ?? '');

        if (!$instanciaId || strlen($numero) < 7) {
            http_response_code(400);
            return ['error' => 'instance_id y numero son requeridos'];
        }

        $evo = $this->getEvo($instanciaId);
        if (!$evo) { http_response_code(404); return ['error' => 'Instancia no encontrada']; }

        // Buscar mensajes donde participant contiene ese número
        $res     = $evo->buscarMensajes(['key' => ['participant' => ['contains' => $numero]]], 50, 1);
        $payload = $res['data'] ?? $res;
        $meta    = $payload['messages'] ?? $res['messages'] ?? $payload;
        $recs    = $meta['records'] ?? [];

        $grupos = [];
        foreach ($recs as $m) {
            $jid = $m['key']['remoteJid'] ?? '';
            if (!str_ends_with($jid, '@g.us')) continue;
            if (isset($grupos[$jid])) continue;
            $grupos[$jid] = ['jid' => $jid, 'nombre' => $jid];
        }

        // También guardar los nuevos grupos encontrados en DB
        if (!empty($grupos)) {
            $pdo    = Database::connect();
            $upsert = $pdo->prepare("INSERT INTO wa_grupos (instancia_id, jid, nombre, descripcion, size) VALUES (?, ?, ?, '', 0) ON DUPLICATE KEY UPDATE synced_at = synced_at");
            foreach ($grupos as $g) {
                $upsert->execute([$instanciaId, $g['jid'], $g['jid']]);
            }
        }

        return ['total' => count($grupos), 'grupos' => array_values($grupos)];
    }

    // ==========================================
    // GET /api/export/groups/scan-page?instance_id=X&page=N
    // Escanea UNA página del historial y devuelve los JIDs de grupo encontrados.
    // El cliente JS llama esto en paralelo para múltiples páginas a la vez.
    // ==========================================
    public function groupsScanPage(): array
    {
        $instanciaId = $_GET['instance_id'] ?? null;
        $page        = max(1, (int)($_GET['page'] ?? 1));
        if (!$instanciaId) { http_response_code(400); return ['error' => 'instance_id requerido']; }

        $evo = $this->getEvo($instanciaId);
        if (!$evo) { http_response_code(404); return ['error' => 'Instancia no encontrada']; }

        $res     = $evo->buscarMensajes([], 50, $page);
        $payload = $res['data'] ?? $res;
        $meta    = $payload['messages'] ?? $res['messages'] ?? $payload;
        $recs    = $meta['records'] ?? [];
        $totalPages = (int)($meta['pages'] ?? 1);

        $jids = [];
        foreach ($recs as $m) {
            $jid = $m['key']['remoteJid'] ?? '';
            if (str_ends_with($jid, '@g.us') && !in_array($jid, $jids, true)) {
                $jids[] = $jid;
            }
        }

        // Guardar en DB los que se encontraron
        if (!empty($jids)) {
            $pdo    = \App\Core\Database::connect();
            $upsert = $pdo->prepare("INSERT INTO wa_grupos (instancia_id, jid, nombre, descripcion, size) VALUES (?, ?, ?, '', 0) ON DUPLICATE KEY UPDATE synced_at = synced_at");
            foreach ($jids as $jid) {
                $upsert->execute([$instanciaId, $jid, $jid]);
            }
        }

        return ['page' => $page, 'total_pages' => $totalPages, 'jids' => $jids];
    }

    // ==========================================
    // POST /api/export/groups/scan-all
    // Scan completo en servidor con curl_multi (paralelo).
    // Escanea TODAS las páginas del historial de una vez.
    // ==========================================
    public function groupsScanAll(): array
    {
        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $instanciaId = $body['instance_id'] ?? null;
        if (!$instanciaId) { http_response_code(400); return ['error' => 'instance_id requerido']; }

        $evo = $this->getEvo($instanciaId);
        if (!$evo) { http_response_code(404); return ['error' => 'Instancia no encontrada']; }

        @set_time_limit(300);

        // Obtener URL, KEY e instancia directamente desde Plan
        $evoUrl  = \App\Core\Plan::evoUrl();
        $evoKey  = \App\Core\Plan::evoKey();
        $pdo     = \App\Core\Database::connect();

        // Detectar nombre de instancia (evo_instance)
        $cols    = $pdo->query("SHOW COLUMNS FROM instancias")->fetchAll(\PDO::FETCH_COLUMN);
        $hasInstId = in_array('instancia_id', $cols, true);
        $idField   = $hasInstId ? 'instancia_id' : 'nombre';
        $stmt      = $pdo->prepare("SELECT {$idField} AS evo_instance FROM instancias WHERE id = ? LIMIT 1");
        $stmt->execute([$instanciaId]);
        $row       = $stmt->fetch();
        if (!$row) { http_response_code(404); return ['error' => 'Instancia no encontrada en DB']; }
        $instance  = $row['evo_instance'];

        // 1. Primera página para obtener total
        $firstUrl = rtrim($evoUrl, '/') . "/chat/findMessages/{$instance}";
        $firstBody = json_encode(['limit' => 50, 'page' => 1]);
        $ch = curl_init($firstUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $firstBody,
            CURLOPT_HTTPHEADER => ["apikey: $evoKey", "Content-Type: application/json"],
            CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $firstRes  = curl_exec($ch); curl_close($ch);
        $firstData = json_decode($firstRes, true) ?? [];
        $totalPages = (int)($firstData['messages']['pages'] ?? 0);
        if ($totalPages < 1) return ['error' => 'No se pudo obtener el total de páginas', 'raw' => substr($firstRes, 0, 200)];

        $grupos = [];
        // Extraer grupos de página 1
        foreach ($firstData['messages']['records'] ?? [] as $m) {
            $jid = $m['key']['remoteJid'] ?? '';
            if (str_ends_with($jid, '@g.us')) $grupos[$jid] = 1;
        }

        // 2. Procesar resto de páginas con curl_multi (PARALELO = 20)
        $PARALELOS = 20;
        $paginas   = range(2, $totalPages);

        foreach (array_chunk($paginas, $PARALELOS) as $batch) {
            $mh   = curl_multi_init();
            $chs  = [];
            foreach ($batch as $page) {
                $c = curl_init($firstUrl);
                curl_setopt_array($c, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['limit' => 50, 'page' => $page]),
                    CURLOPT_HTTPHEADER => ["apikey: $evoKey", "Content-Type: application/json"],
                    CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
                ]);
                curl_multi_add_handle($mh, $c);
                $chs[] = $c;
            }
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            foreach ($chs as $c) {
                $res  = curl_multi_getcontent($c);
                $data = json_decode($res, true) ?? [];
                foreach ($data['messages']['records'] ?? [] as $m) {
                    $jid = $m['key']['remoteJid'] ?? '';
                    if (str_ends_with($jid, '@g.us')) $grupos[$jid] = 1;
                }
                curl_multi_remove_handle($mh, $c);
                curl_close($c);
            }
            curl_multi_close($mh);
        }

        // 3. Guardar todos en DB (upsert)
        $upsert = $pdo->prepare("INSERT INTO wa_grupos (instancia_id, jid, nombre, descripcion, size) VALUES (?, ?, ?, '', 0) ON DUPLICATE KEY UPDATE synced_at = NOW()");
        foreach (array_keys($grupos) as $jid) {
            $upsert->execute([$instanciaId, $jid, $jid]);
        }

        // 4. Devolver lista completa
        $stmt = $pdo->prepare("SELECT jid, nombre, descripcion, size, synced_at FROM wa_grupos WHERE instancia_id = ? ORDER BY nombre ASC");
        $stmt->execute([$instanciaId]);
        $lista = $stmt->fetchAll();

        return [
            'ok'          => true,
            'total'       => count($lista),
            'total_pages' => $totalPages,
            'grupos'      => $lista,
        ];
    }

    // ==========================================
    // POST /api/export/groups/clear
    // Limpia el cache de grupos para una instancia (fuerza re-scan desde cero)
    // ==========================================
    public function groupsClear(): array
    {
        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $instanciaId = $body['instance_id'] ?? null;
        if (!$instanciaId) { http_response_code(400); return ['error' => 'instance_id requerido']; }
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("DELETE FROM wa_grupos WHERE instancia_id = ?");
        $stmt->execute([$instanciaId]);
        return ['ok' => true, 'deleted' => $stmt->rowCount()];
    }

    // ==========================================
    // POST /api/export/groups/sync-members
    // Actualiza el size de los grupos ya guardados en DB
    // ==========================================
    public function groupsSyncMembers(): array
    {
        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $instanciaId = $body['instance_id'] ?? $_GET['instance_id'] ?? null;

        if (!$instanciaId) {
            http_response_code(400);
            return ['error' => 'instance_id requerido'];
        }

        $pdo = Database::connect();
        $evo = $this->getEvo($instanciaId);
        if (!$evo) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        $stmt = $pdo->prepare("SELECT jid, nombre FROM wa_grupos WHERE instancia_id = ?");
        $stmt->execute([$instanciaId]);
        $grupos = $stmt->fetchAll();

        $update = $pdo->prepare("UPDATE wa_grupos SET size = ?, synced_at = NOW() WHERE instancia_id = ? AND jid = ?");
        $resultado = [];
        foreach ($grupos as $g) {
            try {
                $info = $evo->obtenerInfoGrupo($g['jid']);
                $size = $info['size'] ?? 0;
                $update->execute([$size, $instanciaId, $g['jid']]);
                $resultado[] = ['jid' => $g['jid'], 'size' => $size];
            } catch (\Exception $e) {
                $resultado[] = ['jid' => $g['jid'], 'size' => 0, 'error' => $e->getMessage()];
            }
        }

        return ['ok' => true, 'grupos' => $resultado];
    }

    // ==========================================
    // POST /api/export/groups/rename
    // Renombrar un grupo en la DB local
    // ==========================================
    public function groupRename(): array
    {
        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $instanciaId = $body['instance_id'] ?? null;
        $jid         = $body['jid']         ?? null;
        $nombre      = trim($body['nombre'] ?? '');

        if (!$instanciaId || !$jid || $nombre === '') {
            http_response_code(400);
            return ['error' => 'instance_id, jid y nombre son requeridos'];
        }

        $pdo  = Database::connect();
        $stmt = $pdo->prepare("UPDATE wa_grupos SET nombre = ? WHERE instancia_id = ? AND jid = ?");
        $stmt->execute([$nombre, $instanciaId, $jid]);

        return ['ok' => true, 'nombre' => $nombre];
    }

    // ==========================================
    // GET /api/export/group-history?instance_id=X&jid=JID&page=1&limit=100
    // Retorna historial de mensajes de un grupo
    // ==========================================
    public function groupHistory(): array
    {
        $instanciaId = $_GET['instance_id'] ?? null;
        $jid         = $_GET['jid'] ?? null;
        $limit       = min((int)($_GET['limit'] ?? 100), 500);
        $page        = max((int)($_GET['page'] ?? 1), 1);

        if (!$instanciaId || !$jid) {
            http_response_code(400);
            return ['error' => 'instance_id y jid son requeridos'];
        }

        $evo = $this->getEvo($instanciaId);
        if (!$evo) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        $res     = $evo->buscarMensajes(['key' => ['remoteJid' => $jid]], $limit, $page);
        // request() wrapper: {success, http_code, data}
        $payload = $res['data'] ?? $res;
        $meta    = $payload['messages'] ?? $res['messages'] ?? $payload;
        $recs    = $meta['records'] ?? [];
        if (!is_array($recs)) $recs = [];

        $mensajes = [];
        foreach ($recs as $m) {
            $key    = $m['key'] ?? [];
            $fromMe = (bool)($key['fromMe'] ?? false);
            // Quién envió (participante del grupo)
            $participantJid = $key['participant'] ?? $key['participantAlt'] ?? '';
            $numero = str_replace(['@s.whatsapp.net', '@c.us', '@lid', '@s.lid'], '', $participantJid);
            $nombre = $m['pushName'] ?? $numero;

            // Contenido del mensaje
            $msgContent = $m['message'] ?? [];
            $tipo        = $m['messageType'] ?? 'desconocido';
            $texto       = $msgContent['conversation']
                        ?? $msgContent['extendedTextMessage']['text']
                        ?? $msgContent['imageMessage']['caption']
                        ?? $msgContent['videoMessage']['caption']
                        ?? $msgContent['documentMessage']['fileName']
                        ?? '';
            if (empty($texto)) {
                $texto = match(true) {
                    str_contains($tipo, 'image')    => '[Imagen]',
                    str_contains($tipo, 'video')    => '[Video]',
                    str_contains($tipo, 'audio')    => '[Audio]',
                    str_contains($tipo, 'document') => '[Documento]',
                    str_contains($tipo, 'sticker')  => '[Sticker]',
                    str_contains($tipo, 'location') => '[Ubicación]',
                    default                         => "[{$tipo}]",
                };
            }

            $ts = $m['messageTimestamp'] ?? $m['messageTimestampUpdated'] ?? 0;

            $mensajes[] = [
                'id'        => $m['id'] ?? '',
                'fecha'     => $ts ? date('d/m/Y H:i:s', $ts) : '',
                'timestamp' => $ts,
                'from_me'   => $fromMe,
                'numero'    => $numero,
                'nombre'    => $nombre,
                'tipo'      => $tipo,
                'texto'     => $texto,
            ];
        }

        // Ordenar por timestamp ASC
        usort($mensajes, fn($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        return [
            'total'    => $meta['total'] ?? count($mensajes),
            'pages'    => $meta['pages'] ?? 1,
            'page'     => $page,
            'mensajes' => $mensajes,
        ];
    }

    // ==========================================
    // GET /api/export/contacts/csv?instance_id=X
    // Descarga CSV de contactos
    // ==========================================
    public function contactsCsv(): void
    {
        $data = $this->contacts();

        if (isset($data['error'])) {
            http_response_code(400);
            echo $data['error'];
            return;
        }

        $instanciaId = $_GET['instance_id'] ?? 'x';
        $filename = 'contactos_' . $instanciaId . '_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM Excel
        fputcsv($out, ['Número', 'Nombre', 'JID'], ';');

        foreach ($data['contactos'] as $c) {
            fputcsv($out, [$c['numero'], $c['nombre'], $c['jid']], ';');
        }
        fclose($out);
    }

    // ==========================================
    // GET /api/export/groups/csv?instance_id=X&participantes=1
    // Descarga CSV de grupos (y sus participantes si se pide)
    // ==========================================
    public function groupsCsv(): void
    {
        $data = $this->groups();

        if (isset($data['error'])) {
            http_response_code(400);
            echo $data['error'];
            return;
        }

        $instanciaId = $_GET['instance_id'] ?? 'x';
        $filename = 'grupos_' . $instanciaId . '_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        $conParticipantes = ($_GET['participantes'] ?? '0') === '1';

        if ($conParticipantes) {
            fputcsv($out, ['Grupo', 'Descripción', 'Miembros', 'Número participante', 'Rol'], ';');
            foreach ($data['grupos'] as $g) {
                if (empty($g['participantes'])) {
                    fputcsv($out, [$g['nombre'], $g['descripcion'], $g['size'], '', ''], ';');
                } else {
                    foreach ($g['participantes'] as $p) {
                        fputcsv($out, [$g['nombre'], $g['descripcion'], $g['size'], $p['numero'], $p['admin'] ?? ''], ';');
                    }
                }
            }
        } else {
            fputcsv($out, ['Nombre grupo', 'Descripción', 'Miembros', 'JID', 'Owner'], ';');
            foreach ($data['grupos'] as $g) {
                fputcsv($out, [$g['nombre'], $g['descripcion'], $g['size'], $g['jid'], $g['owner']], ';');
            }
        }

        fclose($out);
    }

    // ==========================================
    // GET /api/export/group-history?...&format=csv
    // También soporta CSV si format=csv
    // ==========================================
    public function groupHistoryCsv(): void
    {
        // Reutilizar groupHistory pero como CSV
        $data = $this->groupHistory();
        if (isset($data['error'])) {
            http_response_code(400);
            echo $data['error'];
            return;
        }

        $jid = preg_replace('/[^a-z0-9_\-@\.]/i', '_', $_GET['jid'] ?? 'grupo');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="historial_' . $jid . '_' . date('Ymd_His') . '.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Fecha', 'Número', 'Nombre', 'Mensaje', 'Tipo'], ';');
        foreach ($data['mensajes'] as $m) {
            fputcsv($out, [$m['fecha'], $m['numero'], $m['nombre'], $m['texto'], $m['tipo']], ';');
        }
        fclose($out);
    }

    // ==========================================
    // HELPER: obtener EvolutionService por instancia DB id
    // ==========================================
    private function getEvo(int|string $instanciaId): ?EvolutionService
    {
        $pdo  = Database::connect();

        // Detectar si la tabla usa 'instancia_id' o 'nombre' como identificador de Evolution
        $cols      = $pdo->query("SHOW COLUMNS FROM instancias")->fetchAll(\PDO::FETCH_COLUMN);
        $hasInstId = in_array('instancia_id', $cols, true);
        $idField   = $hasInstId ? 'instancia_id' : 'nombre';

        $stmt = $pdo->prepare("SELECT {$idField} AS evo_instance FROM instancias WHERE id = ? LIMIT 1");
        $stmt->execute([$instanciaId]);
        $row  = $stmt->fetch();

        if (!$row) return null;

        try {
            return new EvolutionService($row['evo_instance']);
        } catch (\Exception $e) {
            return null;
        }
    }
}
