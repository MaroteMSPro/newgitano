<?php

namespace App\Controllers;

use App\Core\Database;

class BroadcastsController
{
    // ---- LISTAS ----

    public function lists(): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('
            SELECT l.*, i.nombre as instancia_nombre, u.nombre as usuario_nombre
            FROM difusion_listas l
            LEFT JOIN instancias i ON l.instancia_id = i.id
            LEFT JOIN usuarios u ON l.usuario_id = u.id
            ORDER BY l.created_at DESC
        ');
        return ['lists' => $stmt->fetchAll()];
    }

    public function showList(string $id): array
    {
        $pdo = Database::connect();
        $list = $pdo->prepare('
            SELECT l.*, i.nombre as instancia_nombre
            FROM difusion_listas l
            LEFT JOIN instancias i ON l.instancia_id = i.id
            WHERE l.id = ?
        ');
        $list->execute([$id]);
        $listData = $list->fetch();

        if (!$listData) {
            http_response_code(404);
            return ['error' => 'Lista no encontrada'];
        }

        $contacts = $pdo->prepare('
            SELECT c.id, c.nombre, c.numero
            FROM difusion_lista_contactos dlc
            JOIN contactos c ON dlc.contacto_id = c.id
            WHERE dlc.lista_id = ?
            ORDER BY c.nombre
        ');
        $contacts->execute([$id]);

        $envios = $pdo->prepare('
            SELECT * FROM difusion_envios WHERE lista_id = ? ORDER BY created_at DESC
        ');
        $envios->execute([$id]);

        return [
            'list' => $listData,
            'contacts' => $contacts->fetchAll(),
            'envios' => $envios->fetchAll(),
        ];
    }

    public function createList(): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);

        $nombre = trim($body['nombre'] ?? '');
        $instanciaId = (int)($body['instancia_id'] ?? 0);
        $contactIds = $body['contacto_ids'] ?? [];
        $numerosRaw = $body["numeros_raw"] ?? [];

        if (!$nombre || !$instanciaId) {
            http_response_code(400);
            return ['error' => 'Nombre e instancia requeridos'];
        }

        $stmt = $pdo->prepare('
            INSERT INTO difusion_listas (instancia_id, usuario_id, nombre, descripcion, total_contactos)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $instanciaId,
            $_REQUEST['_user_id'] ?? null,
            $nombre,
            $body['descripcion'] ?? '',
            count($contactIds),
        ]);
        $listId = $pdo->lastInsertId();

        if (!empty($contactIds)) {
            $ins = $pdo->prepare('INSERT IGNORE INTO difusion_lista_contactos (lista_id, contacto_id) VALUES (?, ?)');
            foreach ($contactIds as $cId) {
                $ins->execute([$listId, $cId]);
            }
        }


        // Procesar números pegados
        if (!empty($numerosRaw)) {
            $selC = $pdo->prepare("SELECT id FROM contactos WHERE numero = ? AND instancia_id = ?");
            $insC = $pdo->prepare('INSERT INTO contactos (instancia_id, tipo, numero, nombre, origen, activo, created_at) VALUES (?, "individual", ?, ?, "difusion", 1, NOW())');
            $insL = $pdo->prepare("INSERT IGNORE INTO difusion_lista_contactos (lista_id, contacto_id) VALUES (?, ?)");
            foreach ($numerosRaw as $num) {
                $num = preg_replace("/[^0-9]/", "", $num);
                if (strlen($num) < 10 || strlen($num) > 15) continue;
                $selC->execute([$num, $instanciaId]);
                $cId = $selC->fetchColumn();
                $selC->closeCursor();
                if (!$cId) {
                    $insC->execute([$instanciaId, $num, $num]);
                    $cId = $pdo->lastInsertId();
                }
                $insL->execute([$listId, $cId]);
            }
            $count = $pdo->prepare("SELECT COUNT(*) FROM difusion_lista_contactos WHERE lista_id = ?");
            $count->execute([$listId]);
            $pdo->prepare("UPDATE difusion_listas SET total_contactos = ? WHERE id = ?")->execute([$count->fetchColumn(), $listId]);
        }
        return ['ok' => true, 'id' => $listId];
    }

    public function addContacts(string $id): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);
        $contactIds = $body['contacto_ids'] ?? [];
        $numerosRaw = $body["numeros_raw"] ?? [];

        $ins = $pdo->prepare('INSERT IGNORE INTO difusion_lista_contactos (lista_id, contacto_id) VALUES (?, ?)');
        foreach ($contactIds as $cId) {
            $ins->execute([$id, $cId]);
        }

        $count = $pdo->prepare('SELECT COUNT(*) FROM difusion_lista_contactos WHERE lista_id = ?');
        $count->execute([$id]);
        $total = $count->fetchColumn();
        $pdo->prepare('UPDATE difusion_listas SET total_contactos = ? WHERE id = ?')->execute([$total, $id]);

        return ['ok' => true, 'total' => $total];
    }

    public function contactsForInstance(): array
    {
        $pdo = Database::connect();
        $instanciaId = $_GET['instancia_id'] ?? 0;
        $search = trim($_GET['search'] ?? '');

        $where = 'c.instancia_id = ? AND c.activo = 1';
        $params = [$instanciaId];

        if ($search) {
            $where .= ' AND (c.nombre LIKE ? OR c.numero LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $stmt = $pdo->prepare("SELECT c.id, c.nombre, c.numero FROM contactos c WHERE $where ORDER BY c.nombre LIMIT 200");
        $stmt->execute($params);
        return ['contacts' => $stmt->fetchAll()];
    }

    // ---- ENVÍOS ----

    public function sendToList(string $id): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);

        $tipo = $body['tipo'] ?? 'mensaje';
        $contenido = trim($body['contenido'] ?? '');

        if (!$contenido) {
            http_response_code(400);
            return ['error' => 'Contenido requerido'];
        }

        $count = $pdo->prepare('SELECT COUNT(*) FROM difusion_lista_contactos WHERE lista_id = ?');
        $count->execute([$id]);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare('
            INSERT INTO difusion_envios (lista_id, usuario_id, tipo, contenido, media_url, delay_min, delay_max, estado, programado_para, total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $estado = !empty($body['programado_para']) ? 'pendiente' : 'enviando';
        $stmt->execute([
            $id,
            $_REQUEST['_user_id'] ?? null,
            $tipo, $contenido,
            $body['media_url'] ?? null,
            $body['delay_min'] ?? 5, $body['delay_max'] ?? 15,
            $estado, $body['programado_para'] ?? null, $total,
        ]);

        return ['ok' => true, 'id' => $pdo->lastInsertId(), 'estado' => $estado];
    }

    public function deleteList(string $id): array
    {
        $pdo = Database::connect();
        $pdo->prepare('DELETE FROM difusion_lista_contactos WHERE lista_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM difusion_envios WHERE lista_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM difusion_listas WHERE id = ?')->execute([$id]);
        return ['ok' => true];
    }

    /**
     * GET /api/broadcasts/scheduled
     * Lista envíos programados (pendientes y pasados).
     */
    public function scheduled(): array
    {
        $pdo    = Database::connect();
        $userId = (int)($_REQUEST['_user_id'] ?? 0);
        $rol    = $_REQUEST['_user_rol'] ?? '';

        $where  = ["e.programado_para IS NOT NULL"];
        $params = [];

        if ($rol !== 'admin') {
            $where[]  = 'e.usuario_id = ?';
            $params[] = $userId;
        }

        if (!empty($_GET['estado'])) {
            $where[]  = 'e.estado = ?';
            $params[] = $_GET['estado'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT e.*,
                   l.nombre  AS lista_nombre,
                   u.nombre  AS usuario_nombre
            FROM difusion_envios e
            LEFT JOIN difusion_listas l ON l.id = e.lista_id
            LEFT JOIN usuarios u        ON u.id = e.usuario_id
            WHERE {$whereStr}
            ORDER BY e.programado_para ASC
        ");
        $stmt->execute($params);

        return ['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    /**
     * DELETE /api/broadcasts/scheduled/{id}
     * Cancela un envío programado pendiente.
     */
    public function cancelScheduled(): array
    {
        $pdo    = Database::connect();
        $userId = (int)($_REQUEST['_user_id'] ?? 0);
        $rol    = $_REQUEST['_user_rol'] ?? '';
        $id     = (int)basename(strtok($_SERVER['REQUEST_URI'], '?'));

        $stmt = $pdo->prepare('SELECT id, usuario_id, estado FROM difusion_envios WHERE id = ?');
        $stmt->execute([$id]);
        $envio = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$envio) { http_response_code(404); return ['error' => 'No encontrado']; }
        if ($rol !== 'admin' && (int)$envio['usuario_id'] !== $userId) {
            http_response_code(403); return ['error' => 'Sin permiso'];
        }
        if ($envio['estado'] !== 'pendiente') {
            http_response_code(409); return ['error' => 'Solo se pueden cancelar envíos pendientes'];
        }

        $pdo->prepare("UPDATE difusion_envios SET estado = 'cancelado' WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    }

    /**
     * Procesador en el boot — ejecuta difusiones programadas vencidas.
     */
    public static function processPending(\PDO $pdo): void
    {
        $lockFile = '/tmp/crm_broadcast_lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 60) return;
        touch($lockFile);

        $stmt = $pdo->prepare("
            SELECT e.*, l.instancia_id,
                   (SELECT i.nombre FROM instancias i WHERE i.id = l.instancia_id) AS instancia_nombre
            FROM difusion_envios e
            JOIN difusion_listas l ON l.id = e.lista_id
            WHERE e.estado = 'pendiente'
              AND e.programado_para <= NOW()
            LIMIT 5
        ");
        $stmt->execute();
        $envios = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($envios as $envio) {
            try {
                $pdo->prepare("UPDATE difusion_envios SET estado = 'enviando' WHERE id = ?")->execute([$envio['id']]);

                // Obtener contactos de la lista
                $contactos = $pdo->prepare("
                    SELECT c.numero FROM difusion_lista_contactos dlc
                    JOIN contactos c ON c.id = dlc.contacto_id
                    WHERE dlc.lista_id = ?
                ");
                $contactos->execute([$envio['lista_id']]);
                $numeros = $contactos->fetchAll(\PDO::FETCH_COLUMN);

                $evo      = new \App\Services\EvolutionService($envio['instancia_nombre']);
                $enviados = 0;
                $fallidos = 0;

                foreach ($numeros as $numero) {
                    try {
                        $evo->enviarTexto($numero, $envio['contenido']);
                        $enviados++;
                    } catch (\Throwable $e) {
                        $fallidos++;
                    }
                    $delay = rand($envio['delay_min'] ?? 5, $envio['delay_max'] ?? 15);
                    sleep(max(1, min($delay, 5))); // cap en 5s para no bloquear el boot
                }

                $pdo->prepare("
                    UPDATE difusion_envios
                    SET estado = 'completado', enviados = ?, fallidos = ?
                    WHERE id = ?
                ")->execute([$enviados, $fallidos, $envio['id']]);

            } catch (\Throwable $e) {
                $pdo->prepare("UPDATE difusion_envios SET estado = 'pendiente' WHERE id = ?")->execute([$envio['id']]);
                error_log('[Broadcasts] Error procesando envio ' . $envio['id'] . ': ' . $e->getMessage());
            }
        }
    }
}
