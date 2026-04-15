<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Plan;
use App\Services\EvolutionService;

/**
 * MasivoController — Envío masivo multi-instancia
 * Usa tabla `envios_masivos` para log de envíos.
 */
class MasivoController
{
    /**
     * POST /api/masivo/send
     * Encola un envío masivo a los contactos de las instancias seleccionadas.
     * Body: { instancias_ids[], mensaje, delay_min, delay_max, limit }
     * Responde inmediato con {queued: true, total: N} y procesa en background.
     */
    public function send(): array
    {
        Plan::requerir('masivo');

        $input       = json_decode(file_get_contents('php://input'), true);
        $instIds     = array_filter(array_map('intval', $input['instancias_ids'] ?? []));
        $mensaje     = trim($input['mensaje'] ?? '');
        $delayMin    = max(5,  (int)($input['delay_min'] ?? 8));
        $delayMax    = max($delayMin, (int)($input['delay_max'] ?? 20));
        $limit       = min((int)($input['limit'] ?? 100), 500);
        $usuarioId   = (int)($_REQUEST['_user_id'] ?? 0);

        if (empty($instIds) || !$mensaje) {
            http_response_code(400);
            return ['error' => 'instancias_ids y mensaje son requeridos'];
        }

        $pdo = Database::connect();

        // Obtener total de contactos para respuesta rápida
        $total = 0;
        $rows  = [];
        foreach ($instIds as $instId) {
            $stmt = $pdo->prepare('
                SELECT c.numero, c.nombre, i.nombre AS inst_nombre
                FROM contactos c
                JOIN instancias i ON i.id = c.instancia_id
                WHERE c.instancia_id = ? AND c.activo = 1 AND c.numero != \'\' AND LENGTH(c.numero) > 8
                LIMIT ?
            ');
            $stmt->execute([$instId, $limit]);
            $contactos = $stmt->fetchAll();
            $total += count($contactos);
            $rows[] = ['instancia_id' => $instId, 'contactos' => $contactos];
        }

        // Registrar en envios_masivos
        $stmtLog = $pdo->prepare('
            INSERT INTO envios_masivos (usuario_id, nombre, tipo, mensaje, instancias_ids, total_instancias, estado, created_at)
            VALUES (?, ?, \'mensaje\', ?, ?, ?, \'procesando\', NOW())
        ');
        $stmtLog->execute([
            $usuarioId,
            'Masivo ' . date('d/m H:i'),
            $mensaje,
            json_encode(array_values($instIds)),
            count($instIds),
        ]);
        $envioId = (int)$pdo->lastInsertId();

        // Responder inmediato y procesar en background
        $this->flushResponse(['queued' => true, 'total' => $total, 'envio_id' => $envioId]);

        // Procesar en background
        $completadas = 0;
        $stmtUpdLog  = $pdo->prepare('UPDATE envios_masivos SET completadas = ?, estado = ? WHERE id = ?');

        foreach ($rows as $grupo) {
            $instId    = $grupo['instancia_id'];
            $contactos = $grupo['contactos'];
            if (empty($contactos)) continue;

            // Obtener nombre de instancia
            $stmtInst = $pdo->prepare('SELECT nombre FROM instancias WHERE id = ?');
            $stmtInst->execute([$instId]);
            $instNombre = $stmtInst->fetchColumn();
            if (!$instNombre) continue;

            try {
                $evo = new EvolutionService($instNombre);
            } catch (\RuntimeException $e) {
                continue;
            }

            $count = 0;
            foreach ($contactos as $c) {
                $numero = $c['numero'];
                try {
                    $evo->enviarTexto($numero, $mensaje);
                } catch (\Throwable $e) {
                    // Continuar aunque falle un número
                }

                $count++;
                // Delay anti-ban
                $delay = rand($delayMin, $delayMax);
                sleep($delay);
                usleep(rand(500, 2000) * 1000);

                // Micro-pausa cada 5 mensajes
                if ($count % 5 === 0) {
                    sleep(rand(5, 10));
                }
            }
            $completadas++;
        }

        // Marcar como completado
        $stmtUpdLog->execute([$completadas, 'completado', $envioId]);

        // Este return no llega al cliente (ya hicimos flush)
        return [];
    }

    /**
     * GET /api/masivo/status
     * Últimos envíos masivos.
     */
    public function status(): array
    {
        Plan::requerir('masivo');
        $pdo = Database::connect();

        $stmt = $pdo->query('
            SELECT em.*, u.nombre AS usuario_nombre
            FROM envios_masivos em
            LEFT JOIN usuarios u ON u.id = em.usuario_id
            ORDER BY em.created_at DESC
            LIMIT 50
        ');
        $envios = $stmt->fetchAll();

        // Parsear instancias_ids
        foreach ($envios as &$e) {
            $e['instancias_ids'] = json_decode($e['instancias_ids'] ?? '[]', true) ?? [];
        }
        unset($e);

        return ['envios' => $envios];
    }

    // ==========================================
    // PRIVADO
    // ==========================================

    /**
     * Envía la respuesta HTTP al cliente y continúa procesando en background.
     * Usa fastcgi_finish_request() si está disponible, si no register_shutdown_function().
     */
    private function flushResponse(array $data): void
    {
        $json = json_encode($data);

        // Si ya hay headers enviados, ignorar
        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Connection: close');
            header('Content-Length: ' . strlen($json));
        }

        echo $json;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            // Fallback: flush buffers
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }

        // Evitar timeout de PHP
        set_time_limit(300);
        ignore_user_abort(true);
    }
}
