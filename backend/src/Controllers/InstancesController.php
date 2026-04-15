<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Plan;
use App\Services\EvolutionService;

class InstancesController
{
    public function userAvailable(): array
    {
        $pdo    = Database::connect();
        $userId = $_REQUEST['_user_id'] ?? null;
        $rol    = $_REQUEST['_user_rol'] ?? '';

        if ($rol === 'admin') {
            $stmt = $pdo->query('
                SELECT i.*,
                    (SELECT COUNT(*) FROM usuarios_instancias ui WHERE ui.instancia_id = i.id) as users_count
                FROM instancias i ORDER BY i.nombre
            ');
            return ['instances' => $stmt->fetchAll()];
        }

        $stmt = $pdo->prepare('
            SELECT i.*, 1 as _assigned,
                (SELECT COUNT(*) FROM usuarios_instancias ui WHERE ui.instancia_id = i.id) as users_count
            FROM instancias i
            JOIN usuarios_instancias ui ON ui.instancia_id = i.id AND ui.usuario_id = ?
            ORDER BY i.nombre
        ');
        $stmt->execute([$userId]);
        return ['instances' => $stmt->fetchAll()];
    }

    public function index(): array
    {
        $pdo  = Database::connect();
        $stmt = $pdo->query('
            SELECT i.*,
                (SELECT COUNT(*) FROM usuarios_instancias ui WHERE ui.instancia_id = i.id) as users_count,
                (SELECT COUNT(*) FROM contactos c WHERE c.instancia_id = i.id AND c.tipo = "individual") as contactos_count,
                (SELECT COUNT(*) FROM contactos c WHERE c.instancia_id = i.id AND c.tipo = "grupo") as grupos_count
            FROM instancias i
            ORDER BY i.nombre
        ');

        $instances   = $stmt->fetchAll();
        $maxInst     = Plan::maxInstancias();
        $usadasCount = count(array_filter($instances, fn($i) => ($i['estado'] ?? '') !== 'eliminado'));

        return [
            'instances'    => $instances,
            'max_instancias' => $maxInst,
            'usadas'       => $usadasCount,
        ];
    }

    /**
     * POST /api/instances/create — crea instancia en Evolution + DB
     */
    public function create(): array
    {
        $input  = json_decode(file_get_contents('php://input'), true);
        $nombre = trim($input['nombre'] ?? '');

        if (empty($nombre)) {
            http_response_code(400);
            return ['error' => 'El nombre de la instancia es requerido'];
        }

        // Sanitizar: sin espacios, sin especiales, lowercase
        $nombre = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nombre));
        if (strlen($nombre) < 1 || strlen($nombre) > 30) {
            http_response_code(400);
            return ['error' => 'El nombre debe tener entre 1 y 30 caracteres (solo letras y números, sin espacios)'];
        }

        $pdo = Database::connect();

        // Verificar límite de instancias del plan
        $stmt = $pdo->query("SELECT COUNT(*) FROM instancias WHERE estado != 'eliminado'");
        $count = (int)$stmt->fetchColumn();
        $max   = Plan::maxInstancias();

        if ($count >= $max) {
            http_response_code(403);
            return ['error' => "Límite de instancias alcanzado ($count/$max). Actualizá tu plan."];
        }

        // Crear instancia en Evolution API
        try {
            $evo = new EvolutionService($nombre);
        } catch (\RuntimeException $e) {
            http_response_code(503);
            return ['error' => $e->getMessage()];
        }

        $webhookUrl = $_ENV['WEBHOOK_URL'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/webhook');
        $evoResult  = $evo->crearInstancia($nombre, $webhookUrl);

        // Evolution v2 devuelve data.instance + data.hash
        if (empty($evoResult['success']) || empty($evoResult['data']['instance'])) {
            http_response_code(502);
            return ['error' => 'No se pudo crear la instancia en Evolution API', 'detail' => $evoResult];
        }

        $evoData = $evoResult['data'];
        $apiKey  = is_string($evoData['hash'] ?? '') ? ($evoData['hash'] ?? '') : '';

        // Guardar en DB
        $stmt = $pdo->prepare('
            INSERT INTO instancias (nombre, api_key, descripcion, numero, estado, created_at)
            VALUES (?, ?, ?, ?, "escaneando", NOW())
        ');
        $stmt->execute([
            $nombre,
            $apiKey,
            $input['descripcion'] ?? '',
            '',
        ]);
        $id = (int)$pdo->lastInsertId();

        return [
            'id'      => $id,
            'nombre'  => $nombre,
            'message' => 'Instancia creada. Escaneá el QR para conectar.',
        ];
    }

    public function store(): array
    {
        $input       = json_decode(file_get_contents('php://input'), true);
        $nombre      = trim($input['nombre'] ?? '');
        $apiKey      = trim($input['api_key'] ?? '');
        $descripcion = trim($input['descripcion'] ?? '');
        $numero      = trim($input['numero'] ?? '');

        if (empty($nombre) || empty($apiKey)) {
            http_response_code(400);
            return ['error' => 'Nombre y API Key requeridos'];
        }

        $pdo  = Database::connect();
        $stmt = $pdo->prepare('INSERT INTO instancias (nombre, api_key, descripcion, numero, estado, created_at) VALUES (?, ?, ?, ?, "desconectado", NOW())');
        $stmt->execute([$nombre, $apiKey, $descripcion, $numero]);

        return ['id' => $pdo->lastInsertId(), 'message' => 'Instancia creada'];
    }

    /**
     * GET /api/instances/{id}/qr
     */
    public function qr(string $id): array
    {
        $inst = $this->getInstancia($id);
        if (!$inst) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        try {
            $evo = new EvolutionService($inst['nombre']);

            // Si ya está conectada, no hay QR que mostrar
            $status = $evo->verificarConexion();
            if ($status['conectado']) {
                return ['state' => 'open', 'connected' => true];
            }

            $result = $evo->obtenerQR();

            // Normalizar respuesta: Evolution v2 puede devolver {code, base64} o {instance:{state}}
            if (isset($result['instance']['state'])) {
                $state = $result['instance']['state'];
                if ($state === 'open') {
                    return ['state' => 'open', 'connected' => true];
                }
                return ['state' => $state, 'connected' => false];
            }

        } catch (\RuntimeException $e) {
            http_response_code(503);
            return ['error' => $e->getMessage()];
        }

        // Normalizar: renombrar base64 → qr para el frontend
            if (isset($result["base64"])) {
                $result["qr"] = $result["base64"];
            }
            return $result;
    }

    /**
     * GET /api/instances/{id}/status — verifica en Evolution y actualiza DB
     */
    public function status(string $id): array
    {
        $pdo  = Database::connect();
        $inst = $this->getInstancia($id);

        if (!$inst) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        // Intentar verificar conexión real en Evolution
        try {
            $evo    = new EvolutionService($inst['nombre']);
            $conn   = $evo->verificarConexion();
            $estado = match($conn['estado'] ?? 'unknown') {
                'open'        => 'conectado',
                'connecting'  => 'escaneando',
                default       => 'desconectado',
            };

            // Actualizar estado en DB
            $pdo->prepare("UPDATE instancias SET estado = ? WHERE id = ?")
                ->execute([$estado, $id]);

            $inst['estado'] = $estado;

            return ['instance' => $inst, 'evo' => $conn];
        } catch (\RuntimeException $e) {
            // Sin Evolution configurado, devolver lo que tenemos en DB
            return ['instance' => $inst, 'warning' => $e->getMessage()];
        }
    }

    /**
     * POST /api/instances/{id}/disconnect
     */
    public function disconnect(string $id): array
    {
        $inst = $this->getInstancia($id);
        if (!$inst) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        try {
            $evo    = new EvolutionService($inst['nombre']);
            $result = $evo->desconectar();
        } catch (\RuntimeException $e) {
            http_response_code(503);
            return ['error' => $e->getMessage()];
        }

        $pdo = Database::connect();
        $pdo->prepare("UPDATE instancias SET estado = 'desconectado' WHERE id = ?")
            ->execute([$id]);

        return ['ok' => true, 'evo' => $result];
    }

    /**
     * POST /api/instances/{id}/restart
     */
    public function restart(string $id): array
    {
        $inst = $this->getInstancia($id);
        if (!$inst) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        try {
            $evo    = new EvolutionService($inst['nombre']);
            $result = $evo->reiniciar();
        } catch (\RuntimeException $e) {
            http_response_code(503);
            return ['error' => $e->getMessage()];
        }

        $pdo = Database::connect();
        $pdo->prepare("UPDATE instancias SET estado = 'escaneando' WHERE id = ?")
            ->execute([$id]);

        return ['ok' => true, 'evo' => $result];
    }

    public function update(string $id): array
    {
        $input  = json_decode(file_get_contents('php://input'), true);
        $pdo    = Database::connect();
        $fields = [];
        $params = [];

        foreach (['nombre', 'api_key', 'descripcion', 'numero'] as $f) {
            if (isset($input[$f])) {
                $fields[] = "$f = ?";
                $params[] = trim($input[$f]);
            }
        }
        if (empty($fields)) {
            return ['ok' => true];
        }
        $params[] = $id;
        $pdo->prepare("UPDATE instancias SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        return ['ok' => true];
    }

    public function setDefault(string $id): array
    {
        $pdo = Database::connect();
        $pdo->exec('UPDATE instancias SET es_default = 0');
        $pdo->prepare('UPDATE instancias SET es_default = 1 WHERE id = ?')->execute([$id]);
        return ['ok' => true];
    }

    public function getUsers(string $id): array
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare('
            SELECT u.id, u.nombre, u.rol
            FROM usuarios u
            JOIN usuarios_instancias ui ON ui.usuario_id = u.id
            WHERE ui.instancia_id = ?
        ');
        $stmt->execute([$id]);
        return ['users' => $stmt->fetchAll()];
    }

    public function setUsers(string $id): array
    {
        $input   = json_decode(file_get_contents('php://input'), true);
        $userIds = $input['user_ids'] ?? [];

        $pdo = Database::connect();
        $pdo->prepare('DELETE FROM usuarios_instancias WHERE instancia_id = ?')->execute([$id]);

        $stmt = $pdo->prepare('INSERT INTO usuarios_instancias (usuario_id, instancia_id) VALUES (?, ?)');
        foreach ($userIds as $uid) {
            $stmt->execute([(int)$uid, (int)$id]);
        }

        return ['ok' => true, 'count' => count($userIds)];
    }

    public function destroy(string $id): array
    {
        $pdo = Database::connect();
        $pdo->prepare('DELETE FROM usuarios_instancias WHERE instancia_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM instancias WHERE id = ?')->execute([$id]);
        return ['message' => 'Instancia eliminada'];
    }

    /**
     * POST /api/instances/{id}/setup-webhook
     * Configura el webhook de Evolution API para esta instancia
     */
    public function setupWebhook(string $id): array
    {
        $inst = $this->getInstancia($id);
        if (!$inst) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }

        try {
            $evo = new EvolutionService($inst['nombre']);
        } catch (\RuntimeException $e) {
            http_response_code(503);
            return ['error' => $e->getMessage()];
        }

        $webhookUrl = $_ENV['WEBHOOK_URL'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/webhook');
        $eventos    = ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE', 'SEND_MESSAGE'];

        $result = $evo->configurarWebhook($webhookUrl, $eventos);

        if (empty($result['success'])) {
            http_response_code(502);
            return [
                'error'  => 'No se pudo configurar el webhook',
                'detail' => $result,
            ];
        }

        return [
            'ok'       => true,
            'instancia' => $inst['nombre'],
            'webhook'  => $webhookUrl,
            'result'   => $result['data'] ?? null,
        ];
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function getInstancia(string $id): array|false
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM instancias WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
}
