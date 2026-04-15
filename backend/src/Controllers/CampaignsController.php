<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Plan;

class CampaignsController
{
    /**
     * Asegura que las columnas de anti-bloqueo existan en la tabla campanas.
     * Usa IF NOT EXISTS o try/catch para no fallar si ya existen.
     */
    private function ensureAntiBloqueoColumns(\PDO $pdo): void
    {
        $columns = [
            'delay_min'          => 'INT NOT NULL DEFAULT 10',
            'delay_max'          => 'INT NOT NULL DEFAULT 25',
            'delay_entre_tandas' => 'INT NOT NULL DEFAULT 180',
            'contactos_por_tanda'=> 'INT NOT NULL DEFAULT 10',
            'delay_progresivo'   => 'TINYINT(1) NOT NULL DEFAULT 0',
        ];

        // Obtener columnas actuales
        $existentes = [];
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM campanas")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                $existentes[] = $c['Field'];
            }
        } catch (\PDOException $e) {
            return;
        }

        foreach ($columns as $col => $def) {
            if (!in_array($col, $existentes)) {
                try {
                    $pdo->exec("ALTER TABLE campanas ADD COLUMN `{$col}` {$def}");
                } catch (\PDOException $e) {
                    // columna ya existe u otro error menor — continuar
                }
            }
        }
    }

    public function recent(): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('
            SELECT id, nombre, estado, total_contactos, enviados, fallidos, created_at 
            FROM campanas 
            ORDER BY created_at DESC 
            LIMIT 5
        ');
        return ['campaigns' => $stmt->fetchAll()];
    }

    public function index(): array
    {
        $pdo = Database::connect();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $total = (int)$pdo->query('SELECT COUNT(*) FROM campanas')->fetchColumn();
        $stmt = $pdo->query("
            SELECT c.*, i.nombre as instancia_nombre, u.nombre as usuario_nombre
            FROM campanas c
            LEFT JOIN instancias i ON c.instancia_id = i.id
            LEFT JOIN usuarios u ON c.usuario_id = u.id
            ORDER BY c.created_at DESC
            LIMIT $limit OFFSET $offset
        ");

        return [
            'campaigns' => $stmt->fetchAll(),
            'pagination' => [
                'page' => $page,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
        ];
    }

    public function show(string $id): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('
            SELECT c.*, i.nombre as instancia_nombre, u.nombre as usuario_nombre
            FROM campanas c
            LEFT JOIN instancias i ON c.instancia_id = i.id
            LEFT JOIN usuarios u ON c.usuario_id = u.id
            WHERE c.id = ?
        ');
        $stmt->execute([$id]);
        $campaign = $stmt->fetch();

        if (!$campaign) {
            http_response_code(404);
            return ['error' => 'Campaña no encontrada'];
        }

        // Get send stats
        $envios = $pdo->prepare('
            SELECT estado, COUNT(*) as total
            FROM campana_envios WHERE campana_id = ?
            GROUP BY estado
        ');
        $envios->execute([$id]);

        return [
            'campaign' => $campaign,
            'envios_stats' => $envios->fetchAll(),
        ];
    }

    public function instances(): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('
            SELECT i.id, i.nombre, i.numero, i.estado, COUNT(c.id) as contactos
            FROM instancias i
            LEFT JOIN contactos c ON c.instancia_id = i.id
            GROUP BY i.id
            ORDER BY i.nombre
        ');
        return ['instances' => $stmt->fetchAll()];
    }

    public function store(): array
    {
        Plan::requerir('campanas');

        $pdo = Database::connect();
        $this->ensureAntiBloqueoColumns($pdo);

        $body = json_decode(file_get_contents('php://input'), true);

        $nombre = trim($body['nombre'] ?? '');
        $mensaje = trim($body['mensaje'] ?? '');
        $instancias = $body['instancias'] ?? [];
        $estado = $body['estado'] ?? 'borrador';
        $delayMin          = (int)($body['delay_min']           ?? 10);
        $delayMax          = (int)($body['delay_max']           ?? 25);
        $contactosPorTanda = (int)($body['contactos_por_tanda'] ?? 10);
        $delayEntreTandas  = (int)($body['delay_entre_tandas']  ?? 180);
        $delayProgresivo   = (bool)($body['delay_progresivo']   ?? false);
        $mediaUrl = $body['media_url'] ?? null;
        $mediaType = $body['media_type'] ?? null;

        if (!$nombre || !$mensaje || empty($instancias)) {
            http_response_code(400);
            return ['error' => 'Nombre, mensaje e instancias son requeridos'];
        }

        // Get user from middleware
        $userId = $_REQUEST['_user_id'] ?? null;

        $ids = [];
        foreach ($instancias as $instanciaId) {
            // Count contacts for this instance
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM contactos WHERE instancia_id = ? AND activo = 1');
            $countStmt->execute([$instanciaId]);
            $totalContactos = (int)$countStmt->fetchColumn();

            $stmt = $pdo->prepare('
                INSERT INTO campanas (instancia_id, usuario_id, nombre, mensaje, estado, delay_min, delay_max,
                    contactos_por_tanda, delay_entre_tandas, delay_progresivo, total_contactos, media_url, media_type, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $instanciaId, $userId, $nombre, $mensaje, $estado,
                $delayMin, $delayMax, $contactosPorTanda, $delayEntreTandas,
                $delayProgresivo ? 1 : 0,
                $totalContactos, $mediaUrl, $mediaType,
            ]);
            $ids[] = $pdo->lastInsertId();
        }

        return ['ok' => true, 'ids' => $ids, 'estado' => $estado];
    }

    public function updateStatus(string $id): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);
        $estado = $body['estado'] ?? '';

        $valid = ['borrador', 'activa', 'pausada', 'cancelada', 'completada'];
        if (!in_array($estado, $valid)) {
            http_response_code(400);
            return ['error' => 'Estado inválido'];
        }

        $pdo->prepare('UPDATE campanas SET estado = ? WHERE id = ?')->execute([$estado, $id]);
        return ['ok' => true];
    }

    public function destroy(string $id): array
    {
        $pdo = Database::connect();
        $pdo->prepare('DELETE FROM campana_envios WHERE campana_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM campanas WHERE id = ?')->execute([$id]);
        return ['ok' => true];
    }
}
