<?php

namespace App\Controllers;

use App\Core\Database;

class CRMController
{
    public function leads(): array
    {
        $pdo    = Database::connect();
        $userId = (int)($_REQUEST['_user_id'] ?? 0);
        $estado = $_GET['estado'] ?? 'asignado';
        $search = trim($_GET['search'] ?? '');
        $usuario = $_GET['usuario'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $where = ['1=1'];
        $params = [];

        if ($estado === 'activos') {
            $where[] = "l.estado IN ('nuevo','asignado')";
        } elseif ($estado === 'cerrados') {
            $where[] = "l.estado IN ('cerrado_positivo','cerrado_negativo')";
        } elseif ($estado !== 'todos') {
            $where[] = 'l.estado = ?';
            $params[] = $estado;
        }

        if ($search !== '') {
            $where[] = '(l.nombre LIKE ? OR l.numero LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if ($usuario !== '') {
            $where[] = 'l.usuario_asignado_id = ?';
            $params[] = $usuario;
        }

        $instanciaId = $_GET['instancia_id'] ?? '';
        if ($instanciaId !== '') {
            $where[] = 'l.instancia_id = ?';
            $params[] = $instanciaId;
        }

        $whereStr = implode(' AND ', $where);

        $total = $pdo->prepare("SELECT COUNT(*) FROM crm_leads l WHERE $whereStr");
        $total->execute($params);
        $totalCount = (int)$total->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT l.*,
                u.nombre as asignado_nombre,
                -- Mensajes no leídos PARA ESTE USUARIO (desde su última lectura)
                (
                    SELECT COUNT(*)
                    FROM crm_mensajes m2
                    WHERE m2.lead_id = l.id
                      AND m2.direccion = 'entrante'
                      AND m2.created_at > COALESCE(
                          (SELECT ll.ultimo_visto FROM lead_lecturas ll
                           WHERE ll.lead_id = l.id AND ll.usuario_id = ?),
                          '1970-01-01'
                      )
                ) AS mensajes_sin_leer
            FROM crm_leads l
            LEFT JOIN usuarios u ON l.usuario_asignado_id = u.id
            WHERE $whereStr
            ORDER BY l.ultimo_mensaje_at DESC
            LIMIT $limit OFFSET $offset
        ");
        // Agregar userId al final de params (corresponde a :uid → se usa como ?)
        $allParams = array_merge([$userId], $params);
        $stmt->execute($allParams);

        return [
            'leads' => $stmt->fetchAll(),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalCount,
                'pages' => (int)ceil($totalCount / $limit),
            ],
        ];
    }

    public function messages(string $id): array
    {
        $pdo = Database::connect();

        // Get lead info
        $lead = $pdo->prepare('
            SELECT l.*, u.nombre as asignado_nombre
            FROM crm_leads l
            LEFT JOIN usuarios u ON l.usuario_asignado_id = u.id
            WHERE l.id = ?
        ');
        $lead->execute([$id]);
        $leadData = $lead->fetch();

        if (!$leadData) {
            http_response_code(404);
            return ['error' => 'Lead no encontrado'];
        }

        // Get messages
        $msgs = $pdo->prepare('
            SELECT m.*, eu.nombre as enviado_por
            FROM crm_mensajes m
            LEFT JOIN usuarios eu ON m.enviado_por_usuario_id = eu.id
            WHERE m.lead_id = ?
            ORDER BY m.created_at ASC
        ');
        $msgs->execute([$id]);

        return [
            'lead' => $leadData,
            'messages' => $msgs->fetchAll(),
        ];
    }

    public function users(): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('SELECT id, nombre, rol, crm_online, crm_activo FROM usuarios WHERE activo = 1 ORDER BY nombre');
        return ['users' => $stmt->fetchAll()];
    }

    public function tags(): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('SELECT * FROM crm_etiquetas WHERE activa = 1 ORDER BY orden');
        return ['tags' => $stmt->fetchAll()];
    }

    public function createTag(): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);
        $nombre = trim($body['nombre'] ?? '');
        $color = $body['color'] ?? '#25D366';

        if (!$nombre) { http_response_code(400); return ['error' => 'Nombre requerido']; }

        $stmt = $pdo->prepare('INSERT INTO crm_etiquetas (nombre, color, activa, orden) VALUES (?, ?, 1, 99)');
        $stmt->execute([$nombre, $color]);
        return ['id' => $pdo->lastInsertId(), 'ok' => true];
    }

    public function deleteTag(string $id): array
    {
        $pdo = Database::connect();
        $pdo->prepare('UPDATE crm_etiquetas SET activa = 0 WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM crm_lead_etiquetas WHERE etiqueta_id = ?')->execute([$id]);
        return ['ok' => true];
    }

    public function stats(): array
    {
        $pdo = Database::connect();
        $stats = $pdo->query("
            SELECT 
                SUM(estado IN ('nuevo','asignado')) as activos,
                SUM(estado IN ('cerrado_positivo','cerrado_negativo')) as cerrados,
                SUM(estado = 'nuevo') as nuevos,
                SUM(estado = 'asignado') as asignados
            FROM crm_leads
        ")->fetch();

        return ['stats' => $stats];
    }

    public function leadTags(string $id): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('
            SELECT e.id, e.nombre, e.color
            FROM crm_lead_etiquetas le
            JOIN crm_etiquetas e ON le.etiqueta_id = e.id
            WHERE le.lead_id = ?
        ');
        $stmt->execute([$id]);
        return ['tags' => $stmt->fetchAll()];
    }

    public function toggleTag(string $id): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);
        $tagId = (int)($body['tag_id'] ?? 0);

        if (!$tagId) {
            http_response_code(400);
            return ['error' => 'tag_id requerido'];
        }

        $exists = $pdo->prepare('SELECT id FROM crm_lead_etiquetas WHERE lead_id = ? AND etiqueta_id = ?');
        $exists->execute([$id, $tagId]);

        if ($exists->fetch()) {
            $pdo->prepare('DELETE FROM crm_lead_etiquetas WHERE lead_id = ? AND etiqueta_id = ?')->execute([$id, $tagId]);
            return ['action' => 'removed'];
        } else {
            $pdo->prepare('INSERT INTO crm_lead_etiquetas (lead_id, etiqueta_id) VALUES (?, ?)')->execute([$id, $tagId]);
            return ['action' => 'added'];
        }
    }

    public function updateStatus(string $id): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);
        $estado = $body['estado'] ?? '';

        $valid = ['nuevo', 'asignado', 'cerrado_positivo', 'cerrado_negativo'];
        if (!in_array($estado, $valid)) {
            http_response_code(400);
            return ['error' => 'Estado inválido'];
        }

        $pdo->prepare('UPDATE crm_leads SET estado = ? WHERE id = ?')->execute([$estado, $id]);
        return ['ok' => true, 'estado' => $estado];
    }

    public function transfer(string $id): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);
        $userId = (int)($body['usuario_id'] ?? 0);

        if (!$userId) {
            http_response_code(400);
            return ['error' => 'usuario_id requerido'];
        }

        $user = $pdo->prepare('SELECT id, nombre FROM usuarios WHERE id = ? AND activo = 1');
        $user->execute([$userId]);
        $userData = $user->fetch();

        if (!$userData) {
            http_response_code(404);
            return ['error' => 'Usuario no encontrado'];
        }

        $lead = $pdo->prepare('SELECT usuario_asignado_id FROM crm_leads WHERE id = ?');
        $lead->execute([$id]);
        $leadData = $lead->fetch();

        $pdo->prepare('
            UPDATE crm_leads SET usuario_asignado_id = ?, estado = "asignado", reasignado = 1, reasignado_de_usuario_id = ?, reasignado_at = NOW()
            WHERE id = ?
        ')->execute([$userId, $leadData['usuario_asignado_id'], $id]);

        return ['ok' => true, 'asignado_a' => $userData['nombre']];
    }

    public function attachFile(string $id): array
    {
        if (empty($_FILES['archivo'])) {
            http_response_code(400);
            return ['error' => 'Archivo requerido'];
        }

        $file = $_FILES['archivo'];
        $caption = $_POST['caption'] ?? '';
        $userId = $_REQUEST['_user_id'] ?? null;

        $uploadDir = 'assets/uploads/chat/';
        $basePath = '/home/u695160153/domains/luxom.com.ar/public_html/crm/';
        $fullDir = $basePath . $uploadDir;

        if (!is_dir($fullDir)) mkdir($fullDir, 0755, true);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'chat_' . $id . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $destPath = $fullDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            http_response_code(500);
            return ['error' => 'Error al guardar archivo'];
        }

        // Determine type
        $mime = $file['type'] ?? '';
        $tipo = 'document';
        if (str_starts_with($mime, 'image/')) $tipo = 'image';
        elseif (str_starts_with($mime, 'video/')) $tipo = 'video';
        elseif (str_starts_with($mime, 'audio/')) $tipo = 'audio';

        // Save as message
        $pdo = Database::connect();
        $pdo->prepare('
            INSERT INTO crm_mensajes (lead_id, instancia_id, contenido, tipo, media_url, direccion, enviado_por_usuario_id, created_at)
            SELECT ?, l.instancia_id, ?, ?, ?, "saliente", ?, NOW()
            FROM crm_leads l WHERE l.id = ?
        ')->execute([$id, $caption ?: $file['name'], $tipo, $uploadDir . $filename, $userId, $id]);

        // Update lead
        $pdo->prepare('UPDATE crm_leads SET ultimo_mensaje = ?, ultimo_mensaje_at = NOW() WHERE id = ?')
            ->execute([$caption ?: '📎 ' . $file['name'], $id]);

        return ['ok' => true, 'path' => $uploadDir . $filename];
    }

    public function togglePin(string $id): array
    {
        $pdo = Database::connect();
        $lead = $pdo->prepare('SELECT marcado FROM crm_leads WHERE id = ?');
        $lead->execute([$id]);
        $current = $lead->fetchColumn();
        $new = $current ? 0 : 1;
        $pdo->prepare('UPDATE crm_leads SET marcado = ? WHERE id = ?')->execute([$new, $id]);
        return ['ok' => true, 'marcado' => $new];
    }

    public function markUnread(string $id): array
    {
        $pdo    = Database::connect();
        $userId = (int)($_REQUEST['_user_id'] ?? 0);

        // Borrar la lectura del usuario actual → el lead vuelve a aparecer como no leído solo para él
        $pdo->prepare('DELETE FROM lead_lecturas WHERE lead_id = ? AND usuario_id = ?')
            ->execute([$id, $userId]);

        return ['ok' => true];
    }

    public function markRead(string $id): array
    {
        $pdo    = Database::connect();
        $userId = (int)($_REQUEST['_user_id'] ?? 0);

        // Registrar cuándo este usuario leyó este lead (UPSERT)
        // Solo actualiza para el usuario que abrió el chat — no afecta a los demás
        $pdo->prepare('
            INSERT INTO lead_lecturas (lead_id, usuario_id, ultimo_visto)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE ultimo_visto = NOW()
        ')->execute([$id, $userId]);

        return ['ok' => true];
    }

    public function shortcuts(): array
    {
        $pdo = Database::connect();
        $userId = $_REQUEST['_user_id'] ?? null;
        $stmt = $pdo->prepare('
            SELECT id, nombre, atajo, tipo, contenido 
            FROM crm_atajos 
            WHERE activo = 1 AND (usuario_id = ? OR usuario_id IS NULL)
            ORDER BY orden, nombre
        ');
        $stmt->execute([$userId]);
        return ['shortcuts' => $stmt->fetchAll()];
    }

    public function gestion(): array
    {
        $pdo = Database::connect();

        // Config
        $config = $pdo->query('SELECT * FROM crm_config LIMIT 1')->fetch();

        // Round robin last assigned
        $rr = $pdo->query('
            SELECT rr.instancia_id, u.nombre as ultimo_usuario, u.id as ultimo_usuario_id
            FROM crm_round_robin rr
            JOIN usuarios u ON rr.ultimo_usuario_id = u.id
            ORDER BY rr.instancia_id LIMIT 1
        ')->fetch();

        // Leads sin asignar
        $sinAsignar = $pdo->query("
            SELECT l.id, l.nombre, l.numero, l.ultimo_mensaje, l.ultimo_mensaje_at
            FROM crm_leads l
            WHERE l.estado = 'nuevo' AND l.usuario_asignado_id IS NULL
            ORDER BY l.ultimo_mensaje_at DESC
        ")->fetchAll();

        // Users with their stats and leads
        $usersStmt = $pdo->query("
            SELECT u.id, u.nombre, u.crm_online,
                SUM(l.estado IN ('nuevo','asignado')) as activos,
                SUM(l.estado = 'cerrado_positivo') as positivos,
                SUM(l.estado = 'cerrado_negativo') as negativos,
                COUNT(l.id) as total
            FROM usuarios u
            LEFT JOIN crm_leads l ON l.usuario_asignado_id = u.id
            WHERE u.activo = 1 AND u.crm_activo = 1
            GROUP BY u.id
            ORDER BY u.nombre
        ");
        $usersData = $usersStmt->fetchAll();

        // Get leads per user (activos only)
        $userLeads = [];
        foreach ($usersData as $u) {
            $stmt = $pdo->prepare("
                SELECT l.id, l.nombre, l.numero, l.estado, l.ultimo_mensaje, l.ultimo_mensaje_at,
                    GROUP_CONCAT(e.nombre) as etiquetas,
                    GROUP_CONCAT(e.color) as etiqueta_colores
                FROM crm_leads l
                LEFT JOIN crm_lead_etiquetas le ON le.lead_id = l.id
                LEFT JOIN crm_etiquetas e ON le.etiqueta_id = e.id
                WHERE l.usuario_asignado_id = ? AND l.estado IN ('nuevo','asignado')
                GROUP BY l.id
                ORDER BY l.ultimo_mensaje_at DESC
                LIMIT 50
            ");
            $stmt->execute([$u['id']]);
            $userLeads[$u['id']] = $stmt->fetchAll();
        }

        return [
            'config' => $config,
            'round_robin' => $rr ?: null,
            'sin_asignar' => $sinAsignar,
            'users' => $usersData,
            'user_leads' => $userLeads,
        ];
    }

    public function toggleOnline(): array
    {
        $user = $GLOBALS['auth_user'];
        $pdo = Database::connect();

        // Toggle crm_online
        $stmt = $pdo->prepare('UPDATE usuarios SET crm_online = NOT crm_online WHERE id = ?');
        $stmt->execute([$user['id']]);

        // Get new value
        $stmt = $pdo->prepare('SELECT crm_online FROM usuarios WHERE id = ?');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        return ['crm_online' => (bool)$row['crm_online']];
    }

    /**
     * GET /api/crm/media?msg_id=X
     * Descarga media (audio/imagen/video/doc) desde Evolution API
     * Devuelve base64 para reproducir en el frontend
     */
    public function media(): array
    {
        $msgId = (int)($_GET['msg_id'] ?? 0);
        if (!$msgId) {
            http_response_code(400);
            return ['error' => 'msg_id requerido'];
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT cm.*, i.nombre as inst_nombre FROM crm_mensajes cm JOIN crm_leads cl ON cm.lead_id = cl.id JOIN instancias i ON cl.instancia_id = i.id WHERE cm.id = ?');
        $stmt->execute([$msgId]);
        $msg = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$msg) {
            http_response_code(404);
            return ['error' => 'Mensaje no encontrado'];
        }

        if (!in_array($msg['tipo'], ['audio', 'image', 'video', 'document'])) {
            http_response_code(400);
            return ['error' => 'Mensaje no es media'];
        }

        $metadata = json_decode($msg['metadata'] ?? '{}', true);
        $mediaKey = $metadata['media_key'] ?? null;

        if (!$mediaKey) {
            http_response_code(404);
            return ['error' => 'Media key no disponible'];
        }

        // Llamar Evolution API para obtener base64
        $evoUrl = \App\Core\Plan::evoUrl();
        $evoKey = \App\Core\Plan::evoKey();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($evoUrl, '/') . '/chat/getBase64FromMediaMessage/' . $msg['inst_nombre'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . $evoKey],
            CURLOPT_POSTFIELDS => json_encode(['message' => $mediaKey]),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            http_response_code(502);
            return ['error' => 'Evolution API error', 'http_code' => $httpCode];
        }

        $data = json_decode($response, true);
        $base64 = $data['base64'] ?? $data['data'] ?? null;
        $mimetype = $data['mimetype'] ?? $data['mimeType'] ?? null;

        if (!$base64) {
            http_response_code(502);
            return ['error' => 'No se pudo obtener media'];
        }

        return [
            'ok' => true,
            'base64' => $base64,
            'mimetype' => $mimetype,
            'tipo' => $msg['tipo'],
        ];
    }

    public function updateAlias(string $id): array
    {
        $pdo = Database::connect();
        // Verificar que el lead exista
        $stmt = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            return ['error' => 'Lead no encontrado'];
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $alias = trim($body['alias'] ?? '');

        if ($alias === '') {
            // Si está vacío, establecer NULL
            $stmt = $pdo->prepare('UPDATE crm_leads SET nombre_personalizado = NULL WHERE id = ?');
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare('UPDATE crm_leads SET nombre_personalizado = ? WHERE id = ?');
            $stmt->execute([$alias, $id]);
        }

        return ['ok' => true, 'alias' => $alias === '' ? null : $alias];
    }
}
