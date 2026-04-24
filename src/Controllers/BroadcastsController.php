<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\EvolutionService;

/**
 * Difusiones v2 — soporta:
 *  - Destinatarios mixtos: contactos individuales + grupos (se expanden a participantes) + números manuales.
 *  - Validación de números contra WhatsApp (Evolution /chat/whatsappNumbers).
 *  - Detección de duplicados y formato inválido antes de guardar.
 *  - Programación a futuro (ejecutado por cron real, ver cron_broadcasts.php).
 *  - Ejecución manual "Ejecutar ahora" vía endpoint dedicado.
 *  - Tracking fino por número (difusion_envio_detalles).
 *  - Delay real entre mensajes (anti-ban).
 */
class BroadcastsController
{
    // ========================================================================
    //  LISTAS
    // ========================================================================

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

        // Destinatarios (incluye los 3 tipos)
        $destinos = $pdo->prepare("
            SELECT
              dlc.id,
              dlc.tipo_destino,
              dlc.contacto_id,
              dlc.numero_manual,
              dlc.validado_wa,
              dlc.validado_at,
              COALESCE(c.nombre, CONCAT('Manual: ', dlc.numero_manual)) AS nombre,
              COALESCE(c.numero, dlc.numero_manual) AS numero,
              c.tipo AS contacto_tipo
            FROM difusion_lista_contactos dlc
            LEFT JOIN contactos c ON c.id = dlc.contacto_id
            WHERE dlc.lista_id = ?
            ORDER BY nombre
        ");
        $destinos->execute([$id]);

        $envios = $pdo->prepare('
            SELECT * FROM difusion_envios WHERE lista_id = ? ORDER BY created_at DESC
        ');
        $envios->execute([$id]);

        return [
            'list'     => $listData,
            'destinos' => $destinos->fetchAll(),
            'envios'   => $envios->fetchAll(),
        ];
    }

    public function createList(): array
    {
        $pdo  = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $nombre      = trim($body['nombre'] ?? '');
        $instanciaId = (int)($body['instancia_id'] ?? 0);
        $descripcion = trim($body['descripcion'] ?? '');

        // Los 3 buckets de destinatarios
        $contactIds = $body['contacto_ids'] ?? [];   // individuales (tabla contactos tipo=individual)
        $grupoIds   = $body['grupo_ids']    ?? [];   // grupos (tabla contactos tipo=grupo)
        $numerosRaw = $body['numeros_raw']  ?? [];   // strings sueltos pegados por el usuario

        if (!$nombre || !$instanciaId) {
            http_response_code(400);
            return ['error' => 'Nombre e instancia requeridos'];
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                INSERT INTO difusion_listas (instancia_id, usuario_id, nombre, descripcion, total_contactos)
                VALUES (?, ?, ?, ?, 0)
            ');
            $stmt->execute([
                $instanciaId,
                $_REQUEST['_user_id'] ?? null,
                $nombre,
                $descripcion,
            ]);
            $listId = (int)$pdo->lastInsertId();

            // Sumar destinatarios usando el método unificado
            $this->attachDestinos($pdo, $listId, $instanciaId, $contactIds, $grupoIds, $numerosRaw);

            // Actualizar total
            $this->refreshTotal($pdo, $listId);

            $pdo->commit();
            return ['ok' => true, 'id' => $listId];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            return ['error' => 'Error al crear lista: ' . $e->getMessage()];
        }
    }

    /**
     * POST /api/broadcasts/lists/:id/destinos
     * Agrega destinatarios mixtos a una lista existente.
     */
    public function addDestinos(string $id): array
    {
        $pdo  = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Resolver la instancia de la lista
        $sel = $pdo->prepare('SELECT instancia_id FROM difusion_listas WHERE id = ?');
        $sel->execute([$id]);
        $instanciaId = (int)$sel->fetchColumn();
        if (!$instanciaId) {
            http_response_code(404);
            return ['error' => 'Lista no encontrada'];
        }

        $contactIds = $body['contacto_ids'] ?? [];
        $grupoIds   = $body['grupo_ids']    ?? [];
        $numerosRaw = $body['numeros_raw']  ?? [];

        $pdo->beginTransaction();
        try {
            $stats = $this->attachDestinos($pdo, (int)$id, $instanciaId, $contactIds, $grupoIds, $numerosRaw);
            $total = $this->refreshTotal($pdo, (int)$id);
            $pdo->commit();
            return ['ok' => true, 'stats' => $stats, 'total' => $total];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Inserta destinatarios de los 3 tipos. Devuelve estadísticas.
     */
    private function attachDestinos(\PDO $pdo, int $listId, int $instanciaId, array $contactIds, array $grupoIds, array $numerosRaw): array
    {
        $insContacto = $pdo->prepare("
            INSERT IGNORE INTO difusion_lista_contactos (lista_id, tipo_destino, contacto_id, numero_manual)
            VALUES (?, 'contacto', ?, NULL)
        ");
        $insGrupo = $pdo->prepare("
            INSERT IGNORE INTO difusion_lista_contactos (lista_id, tipo_destino, contacto_id, numero_manual)
            VALUES (?, 'grupo', ?, NULL)
        ");
        $insManual = $pdo->prepare("
            INSERT IGNORE INTO difusion_lista_contactos (lista_id, tipo_destino, contacto_id, numero_manual)
            VALUES (?, 'manual', NULL, ?)
        ");

        $statsContactos = 0;
        foreach ($contactIds as $cId) {
            $insContacto->execute([$listId, (int)$cId]);
            if ($insContacto->rowCount() > 0) $statsContactos++;
        }

        $statsGrupos = 0;
        foreach ($grupoIds as $gId) {
            $insGrupo->execute([$listId, (int)$gId]);
            if ($insGrupo->rowCount() > 0) $statsGrupos++;
        }

        // Manuales: normalizar + deduplicar contra la misma lista
        $statsManuales = 0;
        $statsInvalidos = 0;
        $seen = [];
        foreach ($numerosRaw as $raw) {
            $num = $this->normalizarNumero($raw);
            if (!$num) { $statsInvalidos++; continue; }
            if (isset($seen[$num])) continue;  // dedupe dentro del mismo batch
            $seen[$num] = true;
            $insManual->execute([$listId, $num]);
            if ($insManual->rowCount() > 0) $statsManuales++;
        }

        return [
            'contactos_agregados' => $statsContactos,
            'grupos_agregados'    => $statsGrupos,
            'manuales_agregados'  => $statsManuales,
            'manuales_invalidos'  => $statsInvalidos,
        ];
    }

    /**
     * Normaliza un número: solo dígitos, 10-15 chars, devuelve '' si no es válido.
     */
    private function normalizarNumero(string $raw): string
    {
        $num = preg_replace('/[^0-9]/', '', $raw);
        if (strlen($num) < 10 || strlen($num) > 15) return '';
        return $num;
    }

    private function refreshTotal(\PDO $pdo, int $listId): int
    {
        $count = $pdo->prepare("SELECT COUNT(*) FROM difusion_lista_contactos WHERE lista_id = ?");
        $count->execute([$listId]);
        $total = (int)$count->fetchColumn();
        $pdo->prepare("UPDATE difusion_listas SET total_contactos = ? WHERE id = ?")->execute([$total, $listId]);
        return $total;
    }

    // ========================================================================
    //  BÚSQUEDA DE DESTINATARIOS (para armar listas)
    // ========================================================================

    /**
     * GET /api/broadcasts/contacts?instancia_id=X&tipo=individual|grupo&search=...
     * Lista contactos (individuales o grupos) de una instancia para armar listas.
     */
    public function contactsForInstance(): array
    {
        $pdo = Database::connect();
        $instanciaId = (int)($_GET['instancia_id'] ?? 0);
        $search      = trim($_GET['search']  ?? '');
        $tipo        = $_GET['tipo'] ?? 'individual';  // 'individual' | 'grupo'

        if (!in_array($tipo, ['individual', 'grupo'], true)) {
            $tipo = 'individual';
        }

        $where = 'c.instancia_id = ? AND c.activo = 1 AND c.tipo = ?';
        $params = [$instanciaId, $tipo];

        if ($search !== '') {
            $where .= ' AND (c.nombre LIKE ? OR c.numero LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        // Para grupos: traer cantidad de participantes (útil para saber a cuántos les llega)
        // Orden: los que tienen nombre van primero (más usable); los sin nombre al final.
        if ($tipo === 'grupo') {
            $stmt = $pdo->prepare("
                SELECT
                  c.id, c.nombre, c.numero,
                  (SELECT COUNT(*) FROM grupo_participantes gp WHERE gp.contacto_id = c.id) AS participantes_count
                FROM contactos c
                WHERE $where
                ORDER BY
                  (c.nombre IS NULL OR c.nombre = '') ASC,
                  c.nombre ASC,
                  c.id ASC
                LIMIT 500
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT c.id, c.nombre, c.numero
                FROM contactos c
                WHERE $where
                ORDER BY c.nombre
                LIMIT 500
            ");
        }
        $stmt->execute($params);
        return ['contacts' => $stmt->fetchAll()];
    }

    /**
     * POST /api/broadcasts/validate-numbers
     * Body: { instancia_id, numeros: [...] }
     * Devuelve cada número con flag: existe_wa, duplicado_local, formato_invalido.
     * NO modifica la base, es solo preview.
     */
    public function validateNumbers(): array
    {
        $pdo  = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $instanciaId = (int)($body['instancia_id'] ?? 0);
        $numerosIn   = $body['numeros'] ?? [];

        if (!$instanciaId || !is_array($numerosIn)) {
            http_response_code(400);
            return ['error' => 'instancia_id y numeros[] requeridos'];
        }

        // 1) Normalizar y detectar duplicados dentro del propio batch + formato inválido
        $resultados = [];
        $paraValidar = [];   // solo los válidos de formato, únicos
        $vistos = [];

        foreach ($numerosIn as $raw) {
            $item = [
                'input'            => $raw,
                'numero'           => null,
                'formato_invalido' => false,
                'duplicado_local'  => false,
                'existe_wa'        => null,  // null = no chequeado
            ];
            $num = $this->normalizarNumero((string)$raw);
            if (!$num) {
                $item['formato_invalido'] = true;
                $resultados[] = $item;
                continue;
            }
            $item['numero'] = $num;
            if (isset($vistos[$num])) {
                $item['duplicado_local'] = true;
                $resultados[] = $item;
                continue;
            }
            $vistos[$num] = count($resultados); // índice para poder rellenar después
            $paraValidar[$num] = true;
            $resultados[] = $item;
        }

        // 2) Validar en WhatsApp (batch) — si la instancia está conectada
        if (!empty($paraValidar)) {
            try {
                $inst = $pdo->prepare('SELECT nombre, estado FROM instancias WHERE id = ?');
                $inst->execute([$instanciaId]);
                $instRow = $inst->fetch();

                if ($instRow && $instRow['estado'] === 'conectado') {
                    $evo = new EvolutionService($instRow['nombre']);
                    // El endpoint acepta un array de números en un POST, lo hacemos en batches de 50
                    $lista = array_keys($paraValidar);
                    $chunks = array_chunk($lista, 50);
                    $waMap = [];
                    foreach ($chunks as $chunk) {
                        $resp = $this->llamarEvolutionCheckNumbers($evo, $chunk);
                        foreach ($resp as $r) {
                            // Evolution devuelve algo como: [{"exists": true, "jid": "...", "number": "549..."}]
                            $n = preg_replace('/[^0-9]/', '', $r['number'] ?? '');
                            if ($n) $waMap[$n] = !empty($r['exists']);
                        }
                    }
                    foreach ($resultados as &$r) {
                        if ($r['numero'] && array_key_exists($r['numero'], $waMap)) {
                            $r['existe_wa'] = $waMap[$r['numero']];
                        }
                    }
                    unset($r);
                }
            } catch (\Throwable $e) {
                error_log('[validateNumbers] ' . $e->getMessage());
                // Si falla la validación WA, dejamos existe_wa=null en todos
            }
        }

        // 3) Resumen
        $validos = 0;
        $invalidos = 0;
        $duplicados = 0;
        $noWa = 0;
        foreach ($resultados as $r) {
            if ($r['formato_invalido']) $invalidos++;
            elseif ($r['duplicado_local']) $duplicados++;
            else $validos++;
            if ($r['existe_wa'] === false) $noWa++;
        }

        return [
            'ok' => true,
            'resultados' => $resultados,
            'resumen' => [
                'total_input'      => count($numerosIn),
                'validos'          => $validos,
                'formato_invalido' => $invalidos,
                'duplicados'       => $duplicados,
                'no_existen_en_wa' => $noWa,
            ],
        ];
    }

    /**
     * Helper: llama al endpoint de Evolution para verificar varios números a la vez.
     */
    private function llamarEvolutionCheckNumbers(EvolutionService $evo, array $numeros): array
    {
        // El servicio tiene verificarNumero() para 1 solo. Lo hacemos inline con su request() vía reflexión
        // para mandar todos en 1 POST. Si no se puede, caemos a verificarNumero() por cada uno.
        try {
            $ref = new \ReflectionClass($evo);
            $req = $ref->getMethod('request');
            $req->setAccessible(true);
            $instance = $ref->getProperty('instance');
            $instance->setAccessible(true);
            $inst = $instance->getValue($evo);
            // Formatear todos los números
            $fm = $ref->getMethod('formatearNumero');
            $fm->setAccessible(true);
            $formateados = array_map(fn($n) => $fm->invoke($evo, $n), $numeros);
            $resp = $req->invoke($evo, 'POST', "/chat/whatsappNumbers/{$inst}", ['numbers' => $formateados]);
            // Respuesta esperada: array asociativo indexado por número
            if (is_array($resp)) return $resp;
        } catch (\Throwable $e) {
            error_log('[validateNumbers:batch] fallback: ' . $e->getMessage());
        }
        // Fallback: uno por uno
        $out = [];
        foreach ($numeros as $n) {
            try {
                $r = $evo->verificarNumero($n);
                if (isset($r[0])) $out[] = $r[0];
                elseif (isset($r['exists']) || isset($r['number'])) $out[] = $r;
            } catch (\Throwable $e) { /* ignore */ }
        }
        return $out;
    }

    // ========================================================================
    //  ENVÍOS
    // ========================================================================

    /**
     * POST /api/broadcasts/lists/:id/send
     * Body: { contenido, tipo?, media_url?, programado_para?, delay_min?, delay_max? }
     * Si programado_para está presente → estado='pendiente'; si no → se ejecuta YA (via cron o run-now).
     */
    public function sendToList(string $id): array
    {
        $pdo  = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $tipo      = $body['tipo'] ?? 'mensaje';  // mensaje | imagen | video | documento | audio
        $contenido = trim($body['contenido'] ?? '');
        $mediaUrl  = $body['media_url'] ?? null;   // URL relativa (si ya se subió vía uploadAttachment)
        $mediaMime = $body['media_mime'] ?? null;
        $mediaName = $body['media_name'] ?? null;

        // Validación: mensaje requiere contenido, los otros tipos requieren media_url (el texto es opcional como caption)
        if ($tipo === 'mensaje' && !$contenido) {
            http_response_code(400);
            return ['error' => 'Contenido requerido'];
        }
        if ($tipo !== 'mensaje' && !$mediaUrl) {
            http_response_code(400);
            return ['error' => 'Se requiere adjunto para este tipo de envío'];
        }

        // Modo de envío a grupos (condiciona el cálculo del total)
        $modoEnvioGrupo = ($body['modo_envio_grupo'] ?? 'al_grupo') === 'uno_a_uno' ? 'uno_a_uno' : 'al_grupo';

        // Calcular total real según el modo de envío
        $total = $this->contarDestinatariosReales($pdo, (int)$id, $modoEnvioGrupo);
        if ($total === 0) {
            http_response_code(400);
            return ['error' => 'La lista no tiene destinatarios'];
        }

        // Delay entre mensajes (segundos). Default 5-15s. Se respeta TAL CUAL en el cron.
        $delayMin = max(1, (int)($body['delay_min'] ?? 5));
        $delayMax = max($delayMin, (int)($body['delay_max'] ?? 15));

        $programado = $body['programado_para'] ?? null;
        // 'pendiente' siempre: el cron (o "Ejecutar ahora") lo toma. Si programado_para es NULL,
        // el cron lo dispara en la próxima pasada (dentro de ≤5 min).
        $estado = 'pendiente';

        $stmt = $pdo->prepare('
            INSERT INTO difusion_envios
                (lista_id, usuario_id, tipo, contenido, media_url, media_mime, media_name,
                 delay_min, delay_max, estado, programado_para, total, modo_envio_grupo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            (int)$id,
            $_REQUEST['_user_id'] ?? null,
            $tipo,
            $contenido,
            $mediaUrl,
            $mediaMime,
            $mediaName,
            $delayMin,
            $delayMax,
            $estado,
            $programado,
            $total,
            $modoEnvioGrupo,
        ]);

        $envioId = (int)$pdo->lastInsertId();
        $spawned = false;

        // Si NO está programado a futuro → arrancar ahora en background.
        // Si está programado, lo toma el cron cuando llegue la hora.
        if (!$programado || strtotime($programado) <= time() + 5) {
            $spawned = self::spawnProcessor();
        }

        return [
            'ok'              => true,
            'id'              => $envioId,
            'estado'          => $estado,
            'total'           => $total,
            'programado_para' => $programado,
            'spawned'         => $spawned,
            'msg' => $programado && strtotime($programado) > time() + 60
                ? "Programado para $programado. Dispara automáticamente cuando llegue la hora."
                : ($spawned
                    ? 'Ejecutando ahora en background. La barra de progreso se actualiza sola.'
                    : 'Pendiente. Arranca en la próxima pasada del cron (hasta 60s).'),
        ];
    }

    /**
     * Cuenta destinatarios reales según el modo de envío a grupos.
     *
     * @param string $modoEnvioGrupo 'al_grupo' = el grupo cuenta como 1, 'uno_a_uno' = se expande a participantes
     */
    private function contarDestinatariosReales(\PDO $pdo, int $listId, string $modoEnvioGrupo = 'al_grupo'): int
    {
        if ($modoEnvioGrupo === 'uno_a_uno') {
            // Grupos se expanden a sus participantes
            $stmt = $pdo->prepare("
                SELECT
                  SUM(CASE WHEN dlc.tipo_destino = 'contacto' THEN 1
                           WHEN dlc.tipo_destino = 'manual'   THEN 1
                           WHEN dlc.tipo_destino = 'grupo'    THEN (
                             SELECT COUNT(*) FROM grupo_participantes gp WHERE gp.contacto_id = dlc.contacto_id
                           )
                           ELSE 0
                  END) AS total
                FROM difusion_lista_contactos dlc
                WHERE dlc.lista_id = ?
            ");
        } else {
            // 'al_grupo': cada grupo cuenta como 1 mensaje (al chat grupal)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS total
                FROM difusion_lista_contactos dlc
                WHERE dlc.lista_id = ?
            ");
        }
        $stmt->execute([$listId]);
        return (int)$stmt->fetchColumn();
    }

    public function deleteList(string $id): array
    {
        $pdo = Database::connect();
        $pdo->beginTransaction();
        try {
            // Borrar detalles de los envíos primero
            $pdo->prepare("
                DELETE ded FROM difusion_envio_detalles ded
                INNER JOIN difusion_envios e ON e.id = ded.envio_id
                WHERE e.lista_id = ?
            ")->execute([$id]);
            $pdo->prepare('DELETE FROM difusion_lista_contactos WHERE lista_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM difusion_envios WHERE lista_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM difusion_listas WHERE id = ?')->execute([$id]);
            $pdo->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            return ['error' => 'Error al eliminar: ' . $e->getMessage()];
        }
    }

    // ========================================================================
    //  PROGRAMADOS
    // ========================================================================

    public function scheduled(): array
    {
        $pdo    = Database::connect();
        $userId = (int)($_REQUEST['_user_id'] ?? 0);
        $rol    = $_REQUEST['_user_rol'] ?? '';

        $where  = ['1=1'];
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
            ORDER BY
              CASE e.estado
                WHEN 'enviando'   THEN 1
                WHEN 'pendiente'  THEN 2
                WHEN 'completado' THEN 3
                WHEN 'cancelado'  THEN 4
                ELSE 5
              END,
              COALESCE(e.programado_para, e.created_at) DESC
        ");
        $stmt->execute($params);

        return ['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    public function cancelScheduled(string $id): array
    {
        $pdo    = Database::connect();
        $userId = (int)($_REQUEST['_user_id'] ?? 0);
        $rol    = $_REQUEST['_user_rol'] ?? '';

        $stmt = $pdo->prepare('SELECT id, usuario_id, estado FROM difusion_envios WHERE id = ?');
        $stmt->execute([$id]);
        $envio = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$envio) { http_response_code(404); return ['error' => 'No encontrado']; }
        if ($rol !== 'admin' && (int)$envio['usuario_id'] !== $userId) {
            http_response_code(403); return ['error' => 'Sin permiso'];
        }
        if (!in_array($envio['estado'], ['pendiente'], true)) {
            http_response_code(409);
            return ['error' => 'Solo se pueden cancelar envíos pendientes'];
        }

        $pdo->prepare("UPDATE difusion_envios SET estado = 'cancelado' WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    }

    /**
     * POST /api/broadcasts/scheduled/:id/run-now
     * Marca el envío para ejecución inmediata Y lanza el procesador en background.
     * Responde al instante; el worker PHP fork-eado hace el trabajo real.
     */
    public function runNow(string $id): array
    {
        $pdo    = Database::connect();
        $userId = (int)($_REQUEST['_user_id'] ?? 0);
        $rol    = $_REQUEST['_user_rol'] ?? '';

        $stmt = $pdo->prepare('SELECT id, usuario_id, estado FROM difusion_envios WHERE id = ?');
        $stmt->execute([$id]);
        $envio = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$envio) { http_response_code(404); return ['error' => 'No encontrado']; }
        if ($rol !== 'admin' && (int)$envio['usuario_id'] !== $userId) {
            http_response_code(403); return ['error' => 'Sin permiso'];
        }
        if ($envio['estado'] !== 'pendiente') {
            http_response_code(409);
            return ['error' => "Solo se pueden ejecutar envíos pendientes (estado actual: {$envio['estado']})"];
        }

        // Marcar para ejecutar YA (el procesador filtra por programado_para <= NOW())
        $pdo->prepare("UPDATE difusion_envios SET programado_para = NOW() WHERE id = ?")->execute([$id]);

        // Disparar un worker en background — responde al usuario en ~50ms.
        $spawned = self::spawnProcessor();

        return [
            'ok' => true,
            'spawned' => $spawned,
            'msg' => $spawned
                ? 'Ejecutando en background. Podés seguir usando la app, el progreso se actualiza solo.'
                : 'Marcado para ejecutar. Dispara en la próxima pasada del cron (hasta 60s).',
        ];
    }

    /**
     * Lanza el procesador PHP en background (fork & forget).
     * Devuelve true si pudo lanzar, false si la plataforma no lo permite
     * (p. ej. hosting sin shell_exec o similar) — en ese caso el cron recoge el trabajo.
     *
     * Si ya hay un lockfile fresco, no lanza nada (otro worker ya está corriendo).
     */
    private static function spawnProcessor(): bool
    {
        // 1) Si hay otro procesador activo, no lanzar uno nuevo (se procesaría igual)
        $lockFile = sys_get_temp_dir() . '/crm_broadcast_lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
            return true;   // "spawneado" lógicamente — el que corre lo va a tomar
        }

        // 2) Necesitamos shell_exec habilitado
        if (!function_exists('shell_exec')) return false;

        // 3) Resolver path absoluto del cron script
        $script = self::resolverBaseDir() . '/cron_broadcasts.php';
        if (!file_exists($script)) return false;

        // 4) Intentar encontrar el binario php (en Hostinger suele estar en /usr/bin/php)
        $phpBin = PHP_BINARY ?: '/usr/bin/php';
        if (!is_executable($phpBin)) {
            // Fallback — confiamos en que `php` esté en el PATH del shell
            $phpBin = 'php';
        }

        // 5) Lanzar en background (el & final hace fork, nohup sobrevive al cierre del request)
        //    Redirigimos todo a /dev/null para que el shell no se quede esperando stdout.
        $cmd = "nohup $phpBin $script > /dev/null 2>&1 &";
        @shell_exec($cmd);

        return true;
    }

    /**
     * GET /api/broadcasts/envios/:id/detalles
     * Ver el detalle fino: qué número se envió, cuál falló, estado de c/u.
     */
    public function envioDetalles(string $id): array
    {
        $pdo = Database::connect();
        $envio = $pdo->prepare('
            SELECT e.*, l.nombre AS lista_nombre
            FROM difusion_envios e
            LEFT JOIN difusion_listas l ON l.id = e.lista_id
            WHERE e.id = ?
        ');
        $envio->execute([$id]);
        $envioRow = $envio->fetch();
        if (!$envioRow) {
            http_response_code(404);
            return ['error' => 'Envío no encontrado'];
        }

        $det = $pdo->prepare('
            SELECT id, numero, estado, error_msg, enviado_at
            FROM difusion_envio_detalles
            WHERE envio_id = ?
            ORDER BY id ASC
        ');
        $det->execute([$id]);

        return [
            'envio' => $envioRow,
            'detalles' => $det->fetchAll(),
        ];
    }

    // ========================================================================
    //  PROCESADOR (llamado desde el CRON)
    // ========================================================================

    /**
     * Procesa difusiones pendientes cuyo programado_para <= NOW().
     * Diseñado para correr en un CRON dedicado (no en el boot del index).
     * Respeta el delay real entre mensajes (anti-ban).
     *
     * @param \PDO $pdo
     * @param int  $maxEnviosPorPasada   Cuántos envíos por corrida (default 3).
     * @param int  $tiempoMaxPorPasada   Máximo segundos en una corrida (default 300 = 5 min).
     */
    public static function processPending(\PDO $pdo, int $maxEnviosPorPasada = 3, int $tiempoMaxPorPasada = 300): array
    {
        $lockFile = sys_get_temp_dir() . '/crm_broadcast_lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
            return ['ok' => false, 'reason' => 'locked'];
        }
        touch($lockFile);
        $inicioPasada = time();
        $enviosProcesados = 0;
        $log = [];

        try {
            $stmt = $pdo->prepare("
                SELECT e.*, l.instancia_id,
                       (SELECT i.nombre FROM instancias i WHERE i.id = l.instancia_id) AS instancia_nombre
                FROM difusion_envios e
                JOIN difusion_listas l ON l.id = e.lista_id
                WHERE e.estado = 'pendiente'
                  AND e.programado_para <= NOW()
                ORDER BY e.programado_para ASC
                LIMIT ?
            ");
            $stmt->bindValue(1, $maxEnviosPorPasada, \PDO::PARAM_INT);
            $stmt->execute();
            $envios = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($envios as $envio) {
                if ((time() - $inicioPasada) >= $tiempoMaxPorPasada) {
                    $log[] = "Pasada agotada, corto en envio {$envio['id']} para la próxima corrida.";
                    break;
                }

                try {
                    $pdo->prepare("
                        UPDATE difusion_envios SET estado = 'enviando', iniciado_at = NOW() WHERE id = ?
                    ")->execute([$envio['id']]);

                    // Expandir destinatarios según el modo configurado para este envío
                    $modoEnvioGrupo = $envio['modo_envio_grupo'] ?? 'al_grupo';
                    $numeros = self::resolverDestinatarios($pdo, (int)$envio['lista_id'], $modoEnvioGrupo);

                    if (empty($numeros)) {
                        $pdo->prepare("
                            UPDATE difusion_envios SET estado = 'completado', finalizado_at = NOW(),
                                   ultimo_error = 'Lista sin destinatarios resolvibles' WHERE id = ?
                        ")->execute([$envio['id']]);
                        $log[] = "Envio {$envio['id']}: sin destinatarios";
                        continue;
                    }

                    // Pre-poblar detalles para tracking fino
                    $insDet = $pdo->prepare("
                        INSERT INTO difusion_envio_detalles (envio_id, numero, estado) VALUES (?, ?, 'pendiente')
                    ");
                    foreach ($numeros as $num) {
                        $insDet->execute([$envio['id'], $num]);
                    }

                    $evo = new EvolutionService($envio['instancia_nombre']);
                    $enviados = 0;
                    $fallidos = 0;

                    $updOk = $pdo->prepare("
                        UPDATE difusion_envio_detalles
                        SET estado = 'enviado', enviado_at = NOW()
                        WHERE envio_id = ? AND numero = ?
                    ");
                    $updFail = $pdo->prepare("
                        UPDATE difusion_envio_detalles
                        SET estado = 'fallido', error_msg = ?, enviado_at = NOW()
                        WHERE envio_id = ? AND numero = ?
                    ");

                    $delayMin = (int)($envio['delay_min'] ?? 5);
                    $delayMax = (int)($envio['delay_max'] ?? 15);
                    if ($delayMax < $delayMin) $delayMax = $delayMin;

                    foreach ($numeros as $i => $numero) {
                        // Corte por tiempo: si este envío ya gastó el budget, dejamos el resto pendiente
                        if ((time() - $inicioPasada) >= $tiempoMaxPorPasada && $i < count($numeros) - 1) {
                            // Marcar el envío como pendiente de nuevo: la próxima pasada retoma solo los 'pendiente' de difusion_envio_detalles
                            $pdo->prepare("
                                UPDATE difusion_envios
                                SET estado = 'pendiente',
                                    enviados = ?, fallidos = ?
                                WHERE id = ?
                            ")->execute([$enviados, $fallidos, $envio['id']]);
                            $log[] = "Envio {$envio['id']}: time budget exhausted at $i/".count($numeros).", resuming next cron.";
                            break 2;
                        }

                        try {
                            $envioTipo = $envio['tipo'] ?? 'mensaje';
                            $mediaUrl  = $envio['media_url']  ?? null;
                            $mediaMime = $envio['media_mime'] ?? '';
                            $mediaName = $envio['media_name'] ?? 'archivo';
                            $caption   = $envio['contenido']  ?? '';

                            if ($envioTipo === 'mensaje' || !$mediaUrl) {
                                // Solo texto
                                $evo->enviarTexto($numero, $caption);
                            } else {
                                // Convertir URL relativa a ruta absoluta en disco para leer el archivo
                                $filePath = self::resolverPathAdjunto($mediaUrl);
                                if (!$filePath || !file_exists($filePath)) {
                                    throw new \RuntimeException("Adjunto no encontrado: $mediaUrl");
                                }
                                $fileB64 = base64_encode(file_get_contents($filePath));

                                if ($envioTipo === 'imagen') {
                                    $evo->enviarImagenBase64($numero, $fileB64, $caption, $mediaMime ?: 'image/jpeg');
                                } elseif ($envioTipo === 'audio') {
                                    $evo->enviarAudio($numero, $fileB64);
                                } elseif ($envioTipo === 'video') {
                                    // Evolution acepta video via sendMedia con mediatype=video
                                    self::enviarMediaBase64Evolution($evo, $numero, $fileB64, 'video', $mediaMime ?: 'video/mp4', $mediaName, $caption);
                                } else {
                                    // documento (PDF, Word, Excel, etc.)
                                    $evo->enviarDocumento($numero, $fileB64, $mediaName, $caption);
                                }
                            }
                            $enviados++;
                            $updOk->execute([$envio['id'], $numero]);
                        } catch (\Throwable $e) {
                            $fallidos++;
                            $updFail->execute([mb_substr($e->getMessage(), 0, 500), $envio['id'], $numero]);
                        }

                        // Progreso en vivo: actualizamos después de CADA mensaje (el frontend hace polling cada 3s).
                        // Es barato: 1 UPDATE por mensaje y cada mensaje ya toma varios segundos de delay.
                        $pdo->prepare("UPDATE difusion_envios SET enviados = ?, fallidos = ? WHERE id = ?")
                            ->execute([$enviados, $fallidos, $envio['id']]);

                        // Delay REAL entre mensajes (anti-ban), solo si no es el último
                        if ($i < count($numeros) - 1) {
                            $delay = rand($delayMin, $delayMax);
                            sleep($delay);
                        }
                    }

                    $pdo->prepare("
                        UPDATE difusion_envios
                        SET estado = 'completado', enviados = ?, fallidos = ?, finalizado_at = NOW()
                        WHERE id = ?
                    ")->execute([$enviados, $fallidos, $envio['id']]);

                    $log[] = "Envio {$envio['id']}: $enviados ok, $fallidos fail de ".count($numeros);
                    $enviosProcesados++;

                } catch (\Throwable $e) {
                    // Problema gordo (ej. instancia desconectada). Volver a pendiente para reintento.
                    $pdo->prepare("
                        UPDATE difusion_envios SET estado = 'pendiente', ultimo_error = ? WHERE id = ?
                    ")->execute([mb_substr($e->getMessage(), 0, 500), $envio['id']]);
                    $log[] = "Envio {$envio['id']} ERROR: " . $e->getMessage();
                    error_log('[Broadcasts] Error procesando envio ' . $envio['id'] . ': ' . $e->getMessage());
                }
            }
        } finally {
            @unlink($lockFile);
        }

        return [
            'ok' => true,
            'envios_procesados' => $enviosProcesados,
            'tiempo_seg' => time() - $inicioPasada,
            'log' => $log,
        ];
    }

    /**
     * Resuelve los destinatarios reales de una lista según el modo de envío.
     *
     * @param \PDO   $pdo
     * @param int    $listId
     * @param string $modoEnvioGrupo 'al_grupo' = manda 1 msg al JID del grupo
     *                                'uno_a_uno' = expande a participantes y les manda privado
     * @return array Lista de números (sin duplicados). Los JIDs de grupo van con formato
     *               "120363...@g.us"? NO — Evolution detecta el JID por el largo del número,
     *               basta con pasar el número del grupo tal cual (ej: "120363426007404125").
     */
    private static function resolverDestinatarios(\PDO $pdo, int $listId, string $modoEnvioGrupo = 'al_grupo'): array
    {
        // Contactos individuales + manuales (siempre directos)
        $base = $pdo->prepare("
            SELECT
              CASE
                WHEN dlc.tipo_destino = 'manual'   THEN dlc.numero_manual
                WHEN dlc.tipo_destino = 'contacto' THEN c.numero
              END AS numero
            FROM difusion_lista_contactos dlc
            LEFT JOIN contactos c ON c.id = dlc.contacto_id
            WHERE dlc.lista_id = ?
              AND dlc.tipo_destino IN ('contacto','manual')
        ");
        $base->execute([$listId]);
        $nums = array_filter($base->fetchAll(\PDO::FETCH_COLUMN));

        if ($modoEnvioGrupo === 'uno_a_uno') {
            // Expandir grupos a sus participantes y mandar privado a cada uno
            $grupos = $pdo->prepare("
                SELECT gp.numero
                FROM difusion_lista_contactos dlc
                INNER JOIN grupo_participantes gp ON gp.contacto_id = dlc.contacto_id
                WHERE dlc.lista_id = ?
                  AND dlc.tipo_destino = 'grupo'
            ");
            $grupos->execute([$listId]);
            $nums = array_merge($nums, array_filter($grupos->fetchAll(\PDO::FETCH_COLUMN)));
        } else {
            // Modo 'al_grupo': mandar al JID del grupo (= número del contacto grupo)
            // Evolution sendText detecta por largo/formato que es un grupo.
            $grupos = $pdo->prepare("
                SELECT c.numero
                FROM difusion_lista_contactos dlc
                INNER JOIN contactos c ON c.id = dlc.contacto_id
                WHERE dlc.lista_id = ?
                  AND dlc.tipo_destino = 'grupo'
                  AND c.tipo = 'grupo'
            ");
            $grupos->execute([$listId]);
            $nums = array_merge($nums, array_filter($grupos->fetchAll(\PDO::FETCH_COLUMN)));
        }

        // Deduplicar
        $nums = array_values(array_unique(array_filter($nums, fn($n) => !empty($n))));
        return $nums;
    }

    // ========================================================================
    //  ADJUNTOS
    // ========================================================================

    /**
     * POST /api/broadcasts/upload-attachment
     * Recibe un archivo (multipart), lo guarda en assets/uploads/broadcasts/
     * y devuelve la URL pública para usar en sendToList.
     */
    public function uploadAttachment(): array
    {
        if (empty($_FILES['archivo'])) {
            http_response_code(400);
            return ['error' => 'Archivo requerido (campo "archivo")'];
        }

        $file = $_FILES['archivo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            return ['error' => 'Error al recibir el archivo (código ' . $file['error'] . ')'];
        }

        // Limites WhatsApp
        $limits = [
            'image'    => 16 * 1024 * 1024,
            'video'    => 16 * 1024 * 1024,
            'audio'    => 16 * 1024 * 1024,
            'document' => 100 * 1024 * 1024,
        ];
        $mime = $file['type'] ?? 'application/octet-stream';
        if (strpos($mime, 'image/')  === 0) $cap = $limits['image'];
        elseif (strpos($mime, 'video/') === 0) $cap = $limits['video'];
        elseif (strpos($mime, 'audio/') === 0) $cap = $limits['audio'];
        else $cap = $limits['document'];

        if ($file['size'] > $cap) {
            $mb = round($cap / 1024 / 1024);
            http_response_code(400);
            return ['error' => "El archivo supera el límite de WhatsApp ({$mb}MB)"];
        }

        // Carpeta destino (se resuelve relativa al propio index.php en runtime)
        $uploadRel = 'assets/uploads/broadcasts/';
        $baseDir   = self::resolverBaseDir();
        $fullDir   = rtrim($baseDir, '/') . '/' . $uploadRel;
        if (!is_dir($fullDir) && !@mkdir($fullDir, 0755, true)) {
            http_response_code(500);
            return ['error' => "No se pudo crear directorio: $fullDir"];
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin';
        $ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        $userId = (int)($_REQUEST['_user_id'] ?? 0);
        $basename = 'bc_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

        $destPath = $fullDir . $basename;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            http_response_code(500);
            return ['error' => 'No se pudo mover el archivo'];
        }

        // URL pública relativa al dominio (servida estáticamente por Apache/.htaccess)
        $publicUrl = '/' . $uploadRel . $basename;

        return [
            'ok' => true,
            'media_url'  => $publicUrl,
            'media_mime' => $mime,
            'media_name' => $file['name'],
            'size_bytes' => (int)$file['size'],
        ];
    }

    /**
     * GET /api/broadcasts/envios/:id/progress
     * Endpoint liviano para el frontend hacer polling cada 2-3s.
     */
    public function envioProgress(string $id): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('
            SELECT id, estado, total, enviados, fallidos, iniciado_at, finalizado_at, ultimo_error
            FROM difusion_envios WHERE id = ?
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            return ['error' => 'No encontrado'];
        }
        $total     = max(1, (int)$row['total']);
        $enviados  = (int)$row['enviados'];
        $fallidos  = (int)$row['fallidos'];
        $procesados = $enviados + $fallidos;
        $pct = min(100, (int)round($procesados * 100 / $total));

        return [
            'id'            => (int)$row['id'],
            'estado'        => $row['estado'],
            'total'         => (int)$row['total'],
            'enviados'      => $enviados,
            'fallidos'      => $fallidos,
            'procesados'    => $procesados,
            'porcentaje'    => $pct,
            'iniciado_at'   => $row['iniciado_at'],
            'finalizado_at' => $row['finalizado_at'],
            'ultimo_error'  => $row['ultimo_error'],
        ];
    }

    // ========================================================================
    //  HELPERS DE ADJUNTOS (usados por processPending)
    // ========================================================================

    /**
     * Resuelve la ruta absoluta en disco a partir de una URL relativa tipo "/assets/uploads/broadcasts/xxx.jpg".
     */
    private static function resolverPathAdjunto(string $mediaUrl): ?string
    {
        $base = self::resolverBaseDir();
        $clean = ltrim($mediaUrl, '/');
        $full  = rtrim($base, '/') . '/' . $clean;
        return file_exists($full) ? $full : null;
    }

    /**
     * Devuelve el path absoluto del directorio raíz del backend (donde vive index.php).
     */
    private static function resolverBaseDir(): string
    {
        // Raíz = directorio que contiene src/Controllers/...
        return realpath(__DIR__ . '/../..') ?: __DIR__;
    }

    /**
     * Envía media (video u otros) usando /message/sendMedia de Evolution API via reflexión.
     * Similar al enviarMediaEvolution() del CRMController.
     */
    private static function enviarMediaBase64Evolution(
        EvolutionService $evo,
        string $numero,
        string $base64,
        string $mediatype,
        string $mimetype,
        string $filename,
        string $caption = ''
    ): array {
        $ref = new \ReflectionClass($evo);
        $req = $ref->getMethod('request');
        $req->setAccessible(true);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $inst = $instance->getValue($evo);
        $fm = $ref->getMethod('formatearNumero');
        $fm->setAccessible(true);
        $num = $fm->invoke($evo, $numero);

        return $req->invoke($evo, 'POST', "/message/sendMedia/{$inst}", [
            'number'    => $num,
            'mediatype' => $mediatype,
            'mimetype'  => $mimetype,
            'media'     => $base64,
            'fileName'  => $filename,
            'caption'   => $caption,
        ]);
    }

    /**
     * POST /api/broadcasts/refresh-group-names
     * Body: { instancia_id }
     * Refresca los nombres de los grupos desde Evolution API.
     *
     * El endpoint /api/sync/groups original usa pushName/name que para grupos
     * suele venir vacío. Esto usa /group/fetchAllGroups que devuelve 'subject'
     * (el nombre real del grupo).
     */
    public function refreshGroupNames(): array
    {
        $pdo  = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $instanciaId = (int)($body['instancia_id'] ?? 0);
        if (!$instanciaId) {
            http_response_code(400);
            return ['error' => 'instancia_id requerido'];
        }

        $stmt = $pdo->prepare('SELECT id, nombre, estado FROM instancias WHERE id = ?');
        $stmt->execute([$instanciaId]);
        $inst = $stmt->fetch();
        if (!$inst) {
            http_response_code(404);
            return ['error' => 'Instancia no encontrada'];
        }
        if ($inst['estado'] !== 'conectado') {
            http_response_code(503);
            return ['error' => 'Instancia no está conectada'];
        }

        try {
            $evo = new EvolutionService($inst['nombre']);
            $response = $evo->obtenerGrupos(false);
        } catch (\Throwable $e) {
            http_response_code(503);
            return ['error' => 'Evolution API: ' . $e->getMessage()];
        }

        $grupos = is_array($response) ? ($response['data'] ?? $response) : [];
        if (!is_array($grupos)) $grupos = [];

        $actualizados = 0;
        $sinNombre = 0;
        $noEncontrados = 0;

        $stmtUpd = $pdo->prepare("
            UPDATE contactos
               SET nombre = ?, updated_at = NOW()
             WHERE instancia_id = ? AND tipo = 'grupo' AND numero = ?
        ");

        foreach ($grupos as $g) {
            $jid = $g['id'] ?? $g['remoteJid'] ?? '';
            if (!$jid || !str_contains($jid, '@g.us')) continue;

            $subject = trim($g['subject'] ?? $g['name'] ?? '');
            if ($subject === '') { $sinNombre++; continue; }

            $numero = str_replace('@g.us', '', $jid);
            $stmtUpd->execute([$subject, $instanciaId, $numero]);
            if ($stmtUpd->rowCount() > 0) {
                $actualizados++;
            } else {
                $noEncontrados++;
            }
        }

        return [
            'ok' => true,
            'total_grupos_evolution' => count($grupos),
            'actualizados'           => $actualizados,
            'sin_nombre_en_wa'       => $sinNombre,
            'no_encontrados_en_db'   => $noEncontrados,
        ];
    }

    /**
     * GET /api/broadcasts/self-test
     * Chequeo de salud del sistema de difusiones. Útil para validar
     * que el hosting soporta ejecución en background antes de confiar
     * en "Enviar ahora".
     */
    public function selfTest(): array
    {
        $pdo = Database::connect();
        $checks = [];

        // 1) ¿Tablas de difusiones presentes?
        try {
            $pdo->query("SELECT 1 FROM difusion_envios LIMIT 1");
            $checks['tabla_difusion_envios'] = 'ok';
        } catch (\Throwable $e) {
            $checks['tabla_difusion_envios'] = 'FALTA: ' . $e->getMessage();
        }

        try {
            $pdo->query("SELECT 1 FROM difusion_envio_detalles LIMIT 1");
            $checks['tabla_difusion_envio_detalles'] = 'ok';
        } catch (\Throwable $e) {
            $checks['tabla_difusion_envio_detalles'] = 'FALTA: correr migration.sql';
        }

        // 2) ¿Columnas v2.1 presentes?
        $cols = $pdo->query("SHOW COLUMNS FROM difusion_envios")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['modo_envio_grupo','media_mime','media_name','enviados','fallidos'] as $col) {
            $checks["columna_$col"] = in_array($col, $cols) ? 'ok' : 'FALTA (correr migration_v2_1.sql)';
        }

        // 3) ¿shell_exec habilitado? (crítico para "Enviar ahora")
        $disabled = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
        if (!function_exists('shell_exec')) {
            $checks['shell_exec'] = 'NO disponible — "Enviar ahora" caerá al cron (delay ≤60s)';
        } elseif (in_array('shell_exec', $disabled, true)) {
            $checks['shell_exec'] = 'Deshabilitado en php.ini — "Enviar ahora" caerá al cron';
        } else {
            $checks['shell_exec'] = 'ok';
        }

        // 4) ¿Existe el script de cron?
        $script = self::resolverBaseDir() . '/cron_broadcasts.php';
        $checks['cron_script'] = file_exists($script)
            ? "ok ($script)"
            : "FALTA en $script";

        // 5) ¿Existe la carpeta de uploads y es escribible?
        $upDir = self::resolverBaseDir() . '/assets/uploads/broadcasts';
        if (!is_dir($upDir)) {
            $checks['uploads_dir'] = "no existe, se creará al primer upload ($upDir)";
        } else {
            $checks['uploads_dir'] = is_writable($upDir) ? 'ok' : "NO escribible ($upDir)";
        }

        // 6) ¿Hay lockfile viejo trabado? (>5 min = stale)
        $lockFile = sys_get_temp_dir() . '/crm_broadcast_lock';
        if (file_exists($lockFile)) {
            $age = time() - filemtime($lockFile);
            $checks['lockfile'] = $age < 300
                ? "activo (hace {$age}s, otro worker corriendo)"
                : "stale desde hace {$age}s — sugerencia: borrar $lockFile";
        } else {
            $checks['lockfile'] = 'limpio';
        }

        // 7) ¿Cuándo fue la última corrida del procesador?
        try {
            $ult = $pdo->query("
                SELECT MAX(iniciado_at) AS ult_inicio,
                       COUNT(CASE WHEN estado = 'pendiente' AND programado_para <= NOW() THEN 1 END) AS pendientes_vencidos
                FROM difusion_envios
            ")->fetch();
            $checks['ultima_ejecucion']    = $ult['ult_inicio'] ?? 'nunca';
            $checks['pendientes_vencidos'] = (int)$ult['pendientes_vencidos'];
        } catch (\Throwable $e) {
            $checks['ultima_ejecucion'] = 'error: ' . $e->getMessage();
        }

        return [
            'ok' => true,
            'checks' => $checks,
            'recomendaciones' => [
                'cron'       => 'Configurar cron cada 1 min en Hostinger para redundancia.',
                'backgroud'  => $checks['shell_exec'] === 'ok'
                    ? 'Background OK: "Enviar ahora" arranca al instante.'
                    : 'Background no disponible: los envíos dependen del cron.',
            ],
        ];
    }
}
