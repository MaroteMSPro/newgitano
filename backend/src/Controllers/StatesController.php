<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Plan;
use App\Services\EvolutionService;

class StatesController
{
    public function index(): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('
            SELECT e.*, i.nombre as instancia_nombre, u.nombre as usuario_nombre
            FROM estados e
            LEFT JOIN instancias i ON e.instancia_id = i.id
            LEFT JOIN usuarios u ON e.usuario_id = u.id
            ORDER BY e.created_at DESC
        ');
        return ['states' => $stmt->fetchAll()];
    }

    public function store(): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);

        $stmt = $pdo->prepare('
            INSERT INTO estados (instancia_id, usuario_id, tipo, contenido, media_url, background_color, font_type, programado_para, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pendiente")
        ');
        $stmt->execute([
            $body['instancia_id'], $body['usuario_id'] ?? $_REQUEST['_user_id'],
            $body['tipo'] ?? 'texto', $body['contenido'] ?? '',
            $body['media_url'] ?? null, $body['background_color'] ?? '#075E54',
            $body['font_type'] ?? 0, $body['programado_para'] ?? null,
        ]);

        return ['ok' => true, 'id' => $pdo->lastInsertId()];
    }

    public function cancel(string $id): array
    {
        $pdo = Database::connect();
        $pdo->prepare("UPDATE estados SET estado = 'cancelado' WHERE id = ? AND estado = 'pendiente'")->execute([$id]);
        return ['ok' => true];
    }

    public function destroy(string $id): array
    {
        $pdo = Database::connect();
        $pdo->prepare('DELETE FROM estados WHERE id = ?')->execute([$id]);
        return ['ok' => true];
    }

    // ==========================================
    // ESTADOS PROGRAMADOS
    // ==========================================

    /**
     * GET /api/states/scheduled
     * Admin: ve todos. Usuario: solo los suyos.
     * Filtros: ?mes=2026-03, ?estado=pendiente, ?usuario_id=X, ?instancia_id=X
     */
    public function scheduled(): array
    {
        $pdo      = Database::connect();
        $userId   = (int)($_REQUEST['_user_id'] ?? 0);
        $userRol  = $_REQUEST['_user_rol'] ?? 'usuario';
        $isAdmin  = $userRol === 'admin';

        $where  = ['1=1'];
        $params = [];

        // Restricción por usuario si no es admin
        if (!$isAdmin) {
            $where[]  = 'ep.usuario_id = ?';
            $params[] = $userId;
        } elseif (!empty($_GET['usuario_id'])) {
            $where[]  = 'ep.usuario_id = ?';
            $params[] = (int)$_GET['usuario_id'];
        }

        if (!empty($_GET['instancia_id'])) {
            $where[]  = 'ep.instancia_id = ?';
            $params[] = (int)$_GET['instancia_id'];
        }

        if (!empty($_GET['estado'])) {
            $where[]  = 'ep.estado = ?';
            $params[] = $_GET['estado'];
        }

        // Filtro por mes YYYY-MM
        if (!empty($_GET['mes']) && preg_match('/^\d{4}-\d{2}$/', $_GET['mes'])) {
            [$anio, $mes] = explode('-', $_GET['mes']);
            $where[]  = 'YEAR(ep.fecha_hora) = ? AND MONTH(ep.fecha_hora) = ?';
            $params[] = (int)$anio;
            $params[] = (int)$mes;
        }

        $sql = '
            SELECT ep.*,
                   i.nombre  AS instancia_nombre,
                   u.nombre  AS usuario_nombre
            FROM   estados_programados ep
            LEFT JOIN instancias i ON ep.instancia_id = i.id
            LEFT JOIN usuarios   u ON ep.usuario_id   = u.id
            WHERE  ' . implode(' AND ', $where) . '
            ORDER  BY ep.fecha_hora ASC
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return ['scheduled' => $stmt->fetchAll()];
    }

    /**
     * POST /api/states/schedule
     * Body: { instancias_ids[], tipo, contenido, caption, fecha_hora }
     */
    public function schedule(): array
    {
        $pdo     = Database::connect();
        $input   = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId  = (int)($_REQUEST['_user_id'] ?? 0);
        $userRol = $_REQUEST['_user_rol'] ?? 'usuario';
        $isAdmin = $userRol === 'admin';

        $instIds   = array_filter(array_map('intval', $input['instancias_ids'] ?? []));
        $tipo      = in_array($input['tipo'] ?? '', ['text','image']) ? $input['tipo'] : 'text';
        $contenido = trim($input['contenido'] ?? '');
        $caption   = trim($input['caption'] ?? '');
        $fechaHora = trim($input['fecha_hora'] ?? '');

        // Validaciones
        if (empty($instIds)) {
            http_response_code(400);
            return ['error' => 'instancias_ids requerido'];
        }
        if (!$contenido) {
            http_response_code(400);
            return ['error' => 'contenido requerido'];
        }
        if (!$fechaHora) {
            http_response_code(400);
            return ['error' => 'fecha_hora requerida'];
        }

        $fechaTs = strtotime($fechaHora);
        if (!$fechaTs || $fechaTs < (time() + 60)) {
            http_response_code(400);
            return ['error' => 'fecha_hora debe ser al menos 1 minuto en el futuro'];
        }

        // Solo admin puede múltiples instancias
        if (!$isAdmin && count($instIds) > 1) {
            http_response_code(403);
            return ['error' => 'Solo administradores pueden programar en múltiples instancias'];
        }

        // Usuario normal: verificar que tenga acceso a las instancias
        if (!$isAdmin) {
            try {
                $stmtCheck = $pdo->prepare(
                    'SELECT instancia_id FROM usuarios_instancias WHERE usuario_id = ?'
                );
                $stmtCheck->execute([$userId]);
                $asignadas = $stmtCheck->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($instIds as $iid) {
                    if (!empty($asignadas) && !in_array($iid, $asignadas)) {
                        http_response_code(403);
                        return ['error' => "Sin acceso a instancia {$iid}"];
                    }
                }
            } catch (\Throwable $e) {
                // Tabla no existe → permitir
            }
        }

        // Determinar masivo
        $esMasivo    = count($instIds) > 1 ? 1 : 0;
        $grupoMasivo = $esMasivo ? $this->uuid4() : null;

        $fechaHoraNorm = date('Y-m-d H:i:s', $fechaTs);

        $stmt = $pdo->prepare('
            INSERT INTO estados_programados
                (instancia_id, usuario_id, tipo, contenido, caption, fecha_hora, es_masivo, grupo_masivo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $total = 0;
        foreach ($instIds as $instId) {
            $stmt->execute([
                $instId, $userId, $tipo, $contenido,
                $caption ?: null, $fechaHoraNorm,
                $esMasivo, $grupoMasivo,
            ]);
            $total++;
        }

        return ['ok' => true, 'total' => $total, 'grupo_masivo' => $grupoMasivo];
    }

    /**
     * POST /api/states/send-now
     * Envía un estado ahora (sin programar fecha).
     */
    public function sendNow(): array
    {
        $pdo     = Database::connect();
        $input   = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId  = (int)($_REQUEST['_user_id'] ?? 0);
        $userRol = $_REQUEST['_user_rol'] ?? 'usuario';
        $isAdmin = $userRol === 'admin';

        $instIds   = array_filter(array_map('intval', $input['instancias_ids'] ?? []));
        $tipo      = in_array($input['tipo'] ?? '', ['text','image']) ? $input['tipo'] : 'text';
        $contenido = trim($input['contenido'] ?? '');
        $caption   = trim($input['caption'] ?? '');

        // Validaciones
        if (empty($instIds)) {
            http_response_code(400);
            return ['error' => 'instancias_ids requerido'];
        }
        if (!$contenido) {
            http_response_code(400);
            return ['error' => 'contenido requerido'];
        }

        // Solo admin puede múltiples instancias
        if (!$isAdmin && count($instIds) > 1) {
            http_response_code(403);
            return ['error' => 'Solo administradores pueden enviar en múltiples instancias'];
        }

        // Verificar acceso a instancias
        if (!$isAdmin) {
            try {
                $stmtCheck = $pdo->prepare(
                    'SELECT instancia_id FROM usuarios_instancias WHERE usuario_id = ?'
                );
                $stmtCheck->execute([$userId]);
                $asignadas = $stmtCheck->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($instIds as $iid) {
                    if (!empty($asignadas) && !in_array($iid, $asignadas)) {
                        http_response_code(403);
                        return ['error' => "Sin acceso a instancia {$iid}"];
                    }
                }
            } catch (\Throwable $e) {
                // Tabla no existe → permitir
            }
        }

        // Enviar inmediatamente via Evolution API
        $fechaHora   = date('Y-m-d H:i:s');
        $exitosos = 0;
        $fallidos = 0;
        $detalle  = [];

        $stmtInsert = $pdo->prepare('
            INSERT INTO estados_programados
                (instancia_id, usuario_id, tipo, contenido, caption, fecha_hora, estado, publicado_at, error_msg)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        foreach ($instIds as $instId) {
            $stmtInst = $pdo->prepare('SELECT * FROM instancias WHERE id = ?');
            $stmtInst->execute([$instId]);
            $inst = $stmtInst->fetch();
            if (!$inst) {
                $fallidos++;
                $detalle[] = ['instancia_id' => $instId, 'ok' => false, 'error' => 'Instancia no encontrada'];
                continue;
            }

            // Obtener contactos para statusJidList
            $stmtCont = $pdo->prepare(
                "SELECT numero FROM contactos WHERE instancia_id = ? AND activo = 1 AND numero != '' AND LENGTH(numero) > 8 LIMIT 500"
            );
            $stmtCont->execute([$instId]);
            $nums    = $stmtCont->fetchAll(\PDO::FETCH_COLUMN);
            $jidList = array_values(array_map(fn($n) => $n . '@s.whatsapp.net', $nums));

            $evoOk    = false;
            $evoError = null;

            try {
                $evo    = new EvolutionService($inst['nombre']);
                $result = $evo->publicarEstado($tipo, $contenido, $caption, $jidList);
                $evoOk  = !empty($result['success']);
                if (!$evoOk) {
                    $evoError = 'HTTP ' . ($result['http_code'] ?? '?') . ': ' . json_encode($result['data'] ?? []);
                }
            } catch (\Throwable $e) {
                $evoError = $e->getMessage();
            }

            // Guardar registro
            $estado = $evoOk ? 'publicado' : 'error';
            $stmtInsert->execute([
                $instId, $userId, $tipo, $contenido,
                $caption ?: null, $fechaHora,
                $estado,
                $evoOk ? $fechaHora : null,
                $evoError ? substr($evoError, 0, 500) : null,
            ]);

            if ($evoOk) {
                $exitosos++;
                $detalle[] = ['instancia_id' => $instId, 'instancia' => $inst['nombre'], 'ok' => true, 'contactos' => count($jidList)];
            } else {
                $fallidos++;
                $detalle[] = ['instancia_id' => $instId, 'instancia' => $inst['nombre'], 'ok' => false, 'error' => $evoError];
            }

            if (count($instIds) > 1) sleep(2);
        }

        return ['ok' => $exitosos > 0, 'total' => $exitosos, 'fallidos' => $fallidos, 'detalle' => $detalle];
    }

    /**
     * DELETE /api/states/scheduled/:id
     * Cancela un estado programado.
     */
    public function deleteScheduled(string $id): array
    {
        $pdo     = Database::connect();
        $userId  = (int)($_REQUEST['_user_id'] ?? 0);
        $userRol = $_REQUEST['_user_rol'] ?? 'usuario';
        $isAdmin = $userRol === 'admin';

        $stmt = $pdo->prepare('SELECT * FROM estados_programados WHERE id = ?');
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            return ['error' => 'No encontrado'];
        }

        if (!$isAdmin && (int)$row['usuario_id'] !== $userId) {
            http_response_code(403);
            return ['error' => 'Sin permisos'];
        }

        $pdo->prepare("UPDATE estados_programados SET estado = 'cancelado' WHERE id = ?")
            ->execute([(int)$id]);

        return ['ok' => true];
    }

    /**
     * Método estático para boot: procesar estados pendientes con file lock de 60s.
     */
    public static function processScheduled(\PDO $db): void
    {
        $lockFile = '/tmp/crm_states_lock';

        // File lock: ejecutar máximo 1 vez por minuto
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 60) {
            return;
        }
        file_put_contents($lockFile, date('c'));

        // Buscar estados pendientes cuya fecha_hora ya pasó
        $stmt = $db->prepare('
            SELECT ep.*, i.nombre AS instancia_nombre
            FROM   estados_programados ep
            JOIN   instancias i ON ep.instancia_id = i.id
            WHERE  ep.estado = ? AND ep.fecha_hora <= NOW()
            ORDER  BY ep.fecha_hora ASC
            LIMIT  20
        ');
        $stmt->execute(['pendiente']);
        $pendientes = $stmt->fetchAll();

        if (empty($pendientes)) {
            return;
        }

        $stmtOk  = $db->prepare("UPDATE estados_programados SET estado='publicado', publicado_at=NOW() WHERE id=?");
        $stmtErr = $db->prepare("UPDATE estados_programados SET estado='error', error_msg=? WHERE id=?");

        foreach ($pendientes as $ep) {
            try {
                // Obtener contactos para statusJidList
                $stmtCont = $db->prepare(
                    'SELECT numero FROM contactos WHERE instancia_id = ? AND activo = 1 AND numero != \'\' AND LENGTH(numero) > 8 LIMIT 500'
                );
                $stmtCont->execute([$ep['instancia_id']]);
                $nums    = $stmtCont->fetchAll(\PDO::FETCH_COLUMN);
                $jidList = array_values(array_map(fn($n) => $n . '@s.whatsapp.net', $nums));

                $evo    = new EvolutionService($ep['instancia_nombre']);
                $result = $evo->publicarEstado(
                    $ep['tipo'],
                    $ep['contenido'],
                    $ep['caption'] ?? '',
                    $jidList
                );

                if (!empty($result['success'])) {
                    $stmtOk->execute([$ep['id']]);
                } else {
                    $errMsg = $result['data']['message'] ?? json_encode($result['data'] ?? []);
                    $stmtErr->execute([substr($errMsg, 0, 500), $ep['id']]);
                }
            } catch (\Throwable $e) {
                $stmtErr->execute([substr($e->getMessage(), 0, 500), $ep['id']]);
            }

            sleep(2);
        }
    }

    // ==========================================
    // PUBLISH (existente, completo)
    // ==========================================

    /**
     * POST /api/states/publish
     * Publica un estado (Story) en una o varias instancias via Evolution API.
     *
     * Body: { instancias_ids[], tipo (text|image), contenido, caption? }
     *
     * Endpoint Evolution: POST /message/sendStatus/{instance}
     * Payload requerido: { type, content, statusJidList[], backgroundColor?, font? }
     *   - statusJidList: lista de JIDs a quienes mostrar el estado
     *   - Para enviar a todos: pasar lista de contactos de la instancia en DB
     *
     * NOTA: Evolution API v2 exige statusJidList (no soporta allContacts sin lista).
     */
    public function publish(): array
    {
        $input      = json_decode(file_get_contents('php://input'), true);
        $instIds    = array_filter(array_map('intval', $input['instancias_ids'] ?? []));
        $tipo       = $input['tipo'] ?? 'text';     // text | image
        $contenido  = trim($input['contenido'] ?? '');
        $caption    = trim($input['caption'] ?? '');
        $usuarioId  = (int)($_REQUEST['_user_id'] ?? 0);

        if (empty($instIds) || !$contenido) {
            http_response_code(400);
            return ['error' => 'instancias_ids y contenido son requeridos'];
        }

        $pdo = Database::connect();
        $exitosos = 0;
        $fallidos = 0;
        $detalle  = [];

        foreach ($instIds as $instId) {
            $stmtInst = $pdo->prepare('SELECT * FROM instancias WHERE id = ?');
            $stmtInst->execute([$instId]);
            $inst = $stmtInst->fetch();
            if (!$inst) {
                $fallidos++;
                $detalle[] = ['instancia_id' => $instId, 'ok' => false, 'error' => 'Instancia no encontrada'];
                continue;
            }

            // Obtener lista de contactos para statusJidList
            $stmtCont = $pdo->prepare(
                'SELECT numero FROM contactos WHERE instancia_id = ? AND activo = 1 AND numero != \'\' AND LENGTH(numero) > 8 LIMIT 500'
            );
            $stmtCont->execute([$instId]);
            $contactos = $stmtCont->fetchAll(\PDO::FETCH_COLUMN);
            $statusJidList = array_values(array_map(fn($n) => $n . '@s.whatsapp.net', $contactos));

            // Llamar Evolution API via EvolutionService
            $evoOk    = false;
            $evoError = null;

            try {
                $evo    = new EvolutionService($inst['nombre']);
                $result = $evo->publicarEstado($tipo, $contenido, $caption, $statusJidList);
                $evoOk  = !empty($result['success']);
                if (!$evoOk) {
                    $evoError = 'HTTP ' . ($result['http_code'] ?? '?') . ': ' . json_encode($result['data'] ?? []);
                }
            } catch (\RuntimeException $e) {
                $evoError = $e->getMessage();
            }

            // Guardar en tabla estados
            $dbEstado = $evoOk ? 'publicado' : 'fallido';
            $stmtSave = $pdo->prepare('
                INSERT INTO estados (instancia_id, usuario_id, tipo, contenido, media_url, estado, publicado_at, error_mensaje, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmtSave->execute([
                $instId,
                $usuarioId,
                $tipo === 'image' ? 'imagen' : 'texto',
                $tipo === 'text' ? $contenido : ($caption ?: ''),
                $tipo === 'image' ? (strlen($contenido) < 500 ? $contenido : null) : null,
                $dbEstado,
                $evoOk ? date('Y-m-d H:i:s') : null,
                $evoError,
            ]);

            if ($evoOk) {
                $exitosos++;
                $detalle[] = ['instancia_id' => $instId, 'instancia' => $inst['nombre'], 'ok' => true, 'contactos' => count($statusJidList)];
            } else {
                $fallidos++;
                $detalle[] = ['instancia_id' => $instId, 'instancia' => $inst['nombre'], 'ok' => false, 'error' => $evoError];
            }
        }

        return [
            'ok'       => $exitosos > 0,
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
            'detalle'  => $detalle,
        ];
    }

    /**
     * POST /api/states/test-send
     * Debug: prueba enviar un estado y devuelve toda la info de la llamada a Evolution API.
     */
    public function testSend(): array
    {
        $pdo   = Database::connect();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $instId    = (int)($input['instancia_id'] ?? 0);
        $tipo      = in_array($input['tipo'] ?? '', ['text','image']) ? $input['tipo'] : 'text';
        $contenido = trim($input['contenido'] ?? '');
        $caption   = trim($input['caption'] ?? '');

        if (!$instId || !$contenido) {
            return ['error' => 'instancia_id y contenido requeridos'];
        }

        // Buscar instancia
        $stmtInst = $pdo->prepare('SELECT * FROM instancias WHERE id = ?');
        $stmtInst->execute([$instId]);
        $inst = $stmtInst->fetch();
        if (!$inst) {
            return ['error' => 'Instancia no encontrada'];
        }

        // Buscar contactos
        $stmtCont = $pdo->prepare(
            "SELECT numero FROM contactos WHERE instancia_id = ? AND activo = 1 AND numero != '' AND LENGTH(numero) > 8 LIMIT 10"
        );
        $stmtCont->execute([$instId]);
        $nums    = $stmtCont->fetchAll(\PDO::FETCH_COLUMN);
        $jidList = array_values(array_map(fn($n) => $n . '@s.whatsapp.net', $nums));

        // Armar body como lo arma publicarEstado
        $body = ['type' => $tipo, 'content' => $contenido];
        if ($caption) $body['caption'] = $caption;
        if (!empty($jidList)) $body['statusJidList'] = $jidList;
        if ($tipo === 'text') {
            $body['backgroundColor'] = '#075E54';
            $body['font'] = 1;
        }

        // Llamar Evolution API
        $result = null;
        $error  = null;
        try {
            $evo    = new EvolutionService($inst['nombre']);
            $result = $evo->publicarEstado($tipo, $contenido, $caption, $jidList);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'debug' => true,
            'instancia'       => $inst['nombre'],
            'tipo'            => $tipo,
            'contenido_len'   => strlen($contenido),
            'contenido_start' => substr($contenido, 0, 100),
            'caption'         => $caption,
            'contactos_found' => count($nums),
            'jid_list_sample' => array_slice($jidList, 0, 3),
            'body_sent'       => array_merge($body, ['content' => substr($body['content'], 0, 100) . '...', 'statusJidList' => count($jidList) . ' jids']),
            'evolution_response' => $result,
            'exception'       => $error,
        ];
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function uuid4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}