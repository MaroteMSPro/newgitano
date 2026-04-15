<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\EvolutionService;
use App\Core\Router;

class RecordatoriosController
{
    /**
     * GET /api/recordatorios
     * Lista recordatorios. Admin puede filtrar por lead_id/estado.
     * No-admin sólo ve los propios.
     */
    public function list(): array
    {
        $pdo    = Database::connect();
        $userId = (int)($_REQUEST['_user_id'] ?? 0);
        $rol    = $_REQUEST['_user_rol'] ?? '';

        $where  = ['1=1'];
        $params = [];

        if ($rol !== 'admin') {
            $where[]  = 'r.usuario_id = ?';
            $params[] = $userId;
        } else {
            if (!empty($_GET['lead_id'])) {
                $where[]  = 'r.lead_id = ?';
                $params[] = (int)$_GET['lead_id'];
            }
        }

        if (!empty($_GET['estado'])) {
            $where[]  = 'r.estado = ?';
            $params[] = $_GET['estado'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT
                r.*,
                l.nombre  AS lead_nombre,
                l.numero  AS lead_numero,
                i.nombre  AS instancia_nombre
            FROM recordatorios r
            LEFT JOIN crm_leads l   ON l.id = r.lead_id
            LEFT JOIN instancias i  ON i.id = r.instancia_id
            WHERE {$whereStr}
            ORDER BY r.fecha_hora ASC
        ");
        $stmt->execute($params);

        return ['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    /**
     * POST /api/recordatorios
     * Crea un recordatorio para el lead activo.
     */
    public function create(): array
    {
        $pdo    = Database::connect();
        $userId = (int)($_REQUEST['_user_id'] ?? 0);

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $leadId    = (int)($body['lead_id']    ?? 0);
        $mensaje   = trim($body['mensaje']      ?? '');
        $fechaHora = trim($body['fecha_hora']   ?? '');

        if (!$leadId || !$mensaje || !$fechaHora) {
            http_response_code(422);
            return ['error' => 'lead_id, mensaje y fecha_hora son requeridos'];
        }

        // Normalizar formato ISO (YYYY-MM-DDTHH:MM → YYYY-MM-DD HH:MM:SS)
        $fechaHora = str_replace('T', ' ', $fechaHora);
        if (strlen($fechaHora) === 16) {
            $fechaHora .= ':00';
        }

        // Validar que sea futura (al menos 1 minuto)
        $ts = strtotime($fechaHora);
        if (!$ts || $ts < (time() + 60)) {
            http_response_code(422);
            return ['error' => 'La fecha/hora debe ser al menos 1 minuto en el futuro'];
        }

        // Obtener lead: numero e instancia_id
        $stmtLead = $pdo->prepare('SELECT numero, instancia_id FROM crm_leads WHERE id = ?');
        $stmtLead->execute([$leadId]);
        $lead = $stmtLead->fetch(\PDO::FETCH_ASSOC);

        if (!$lead) {
            http_response_code(404);
            return ['error' => 'Lead no encontrado'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO recordatorios (lead_id, instancia_id, usuario_id, numero, mensaje, fecha_hora, estado)
            VALUES (?, ?, ?, ?, ?, ?, 'pendiente')
        ");
        $stmt->execute([
            $leadId,
            (int)$lead['instancia_id'],
            $userId,
            $lead['numero'],
            $mensaje,
            $fechaHora,
        ]);

        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    }

    /**
     * DELETE /api/recordatorios/{id}
     * Cancela un recordatorio (sólo el propio usuario o admin).
     */
    public function delete(): array
    {
        $pdo    = Database::connect();
        $userId = (int)($_REQUEST['_user_id'] ?? 0);
        $rol    = $_REQUEST['_user_rol'] ?? '';

        // Extraer id del path
        $uri  = $_SERVER['REQUEST_URI'];
        $id   = (int)basename(strtok($uri, '?'));

        if (!$id) {
            http_response_code(422);
            return ['error' => 'ID inválido'];
        }

        $stmt = $pdo->prepare('SELECT id, usuario_id, estado FROM recordatorios WHERE id = ?');
        $stmt->execute([$id]);
        $rec = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$rec) {
            http_response_code(404);
            return ['error' => 'Recordatorio no encontrado'];
        }

        if ($rol !== 'admin' && (int)$rec['usuario_id'] !== $userId) {
            http_response_code(403);
            return ['error' => 'Sin permiso para cancelar este recordatorio'];
        }

        if ($rec['estado'] !== 'pendiente') {
            http_response_code(409);
            return ['error' => 'Solo se pueden cancelar recordatorios pendientes'];
        }

        $pdo->prepare("UPDATE recordatorios SET estado = 'cancelado' WHERE id = ?")->execute([$id]);

        return ['ok' => true];
    }

    /**
     * POST /api/recordatorios/process
     * Procesa recordatorios vencidos. Protegido por token header.
     */
    public function process(): array
    {
        $token = $_SERVER['HTTP_X_PROCESS_TOKEN'] ?? '';
        if ($token !== 'rec_proc_2026_luxom') {
            http_response_code(403);
            return ['error' => 'Token inválido'];
        }

        $pdo     = Database::connect();
        $results = self::runPending($pdo);

        return ['ok' => true, 'processed' => $results];
    }

    /**
     * Llamado en el boot de index.php para procesar recordatorios pendientes.
     * Usa file lock para no ejecutarse más de una vez por minuto.
     */
    public static function processPending(\PDO $db): void
    {
        $lockFile = '/tmp/crm_rec_lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 60) {
            return;
        }
        touch($lockFile);

        self::runPending($db);
    }

    /**
     * Lógica central de procesamiento de recordatorios.
     */
    private static function runPending(\PDO $pdo): array
    {
        $stmt = $pdo->prepare("
            SELECT r.*, i.nombre AS instancia_nombre
            FROM recordatorios r
            JOIN instancias i ON i.id = r.instancia_id
            WHERE r.estado = 'pendiente'
              AND r.fecha_hora <= NOW()
            LIMIT 50
        ");
        $stmt->execute();
        $pendientes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $results = [];

        foreach ($pendientes as $rec) {
            try {
                // 1. Enviar por Evolution
                $evo    = new EvolutionService($rec['instancia_nombre']);
                $result = $evo->enviarTexto($rec['numero'], $rec['mensaje']);

                // 2. Guardar en crm_mensajes como mensaje saliente con flag recordatorio
                $msgIdWa = $result['data']['key']['id'] ?? null;
                $pdo->prepare("
                    INSERT INTO crm_mensajes
                        (lead_id, instancia_id, direccion, tipo, contenido,
                         mensaje_id_wa, enviado_por_usuario_id, es_recordatorio)
                    VALUES (?, ?, 'saliente', 'text', ?, ?, ?, 1)
                ")->execute([
                    $rec['lead_id'],
                    $rec['instancia_id'],
                    $rec['mensaje'],
                    $msgIdWa,
                    $rec['usuario_id'],
                ]);

                // 3. Marcar recordatorio como enviado
                $pdo->prepare("
                    UPDATE recordatorios
                    SET estado = 'enviado', enviado_at = NOW(), error_msg = NULL
                    WHERE id = ?
                ")->execute([$rec['id']]);

                $results[] = ['id' => $rec['id'], 'ok' => true];

            } catch (\Throwable $e) {
                $pdo->prepare("
                    UPDATE recordatorios
                    SET estado = 'error', error_msg = ?
                    WHERE id = ?
                ")->execute([substr($e->getMessage(), 0, 500), $rec['id']]);

                $results[] = ['id' => $rec['id'], 'ok' => false, 'error' => $e->getMessage()];
            }

            sleep(1); // Anti-bloqueo entre envíos
        }

        return $results;
    }
}
