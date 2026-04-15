<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Plan;

/**
 * CampaignMultiController — Campañas multi-instancia
 * Usa la tabla `campanas` agrupada por `grupo_campana` (UUID).
 * Cada instancia = 1 fila en campanas con el mismo grupo_campana.
 */
class CampaignMultiController
{
    /**
     * GET /api/campaigns-multi
     * Lista grupos de campañas multi-instancia.
     */
    public function index(): array
    {
        Plan::requerir('campanas_multi');
        $pdo = Database::connect();

        // Agrupar por grupo_campana; traer resumen por grupo
        $stmt = $pdo->query("
            SELECT
                grupo_campana                              AS grupo_id,
                MAX(grupo_nombre)                         AS nombre,
                COUNT(*)                                  AS total_instancias,
                SUM(enviados)                             AS enviados,
                SUM(fallidos)                             AS fallidos,
                SUM(total_contactos)                      AS total_contactos,
                MAX(created_at)                           AS created_at,
                GROUP_CONCAT(DISTINCT estado ORDER BY estado SEPARATOR ',') AS estados,
                GROUP_CONCAT(instancia_id ORDER BY instancia_id SEPARATOR ',') AS instancia_ids
            FROM campanas
            WHERE grupo_campana IS NOT NULL
            GROUP BY grupo_campana
            ORDER BY MAX(created_at) DESC
            LIMIT 100
        ");

        $grupos = $stmt->fetchAll();

        // Estado consolidado del grupo
        foreach ($grupos as &$g) {
            $estadosArr = array_unique(explode(',', $g['estados']));
            if (in_array('procesando', $estadosArr)) {
                $g['estado_grupo'] = 'procesando';
            } elseif (in_array('activa', $estadosArr)) {
                $g['estado_grupo'] = 'activa';
            } elseif (count(array_diff($estadosArr, ['completada'])) === 0) {
                $g['estado_grupo'] = 'completada';
            } elseif (in_array('pausada', $estadosArr)) {
                $g['estado_grupo'] = 'pausada';
            } else {
                $g['estado_grupo'] = 'borrador';
            }
            $g['instancia_ids'] = array_map('intval', explode(',', $g['instancia_ids']));
        }
        unset($g);

        return ['campaigns_multi' => $grupos];
    }

    /**
     * POST /api/campaigns-multi/create
     * Crea una campaña multi-instancia.
     * Body: { nombre, mensaje, instancias_ids[], delay_min, delay_max }
     */
    public function create(): array
    {
        Plan::requerir('campanas_multi');

        $input        = json_decode(file_get_contents('php://input'), true);
        $nombre       = trim($input['nombre'] ?? '');
        $mensaje      = trim($input['mensaje'] ?? '');
        $instIds      = array_filter(array_map('intval', $input['instancias_ids'] ?? []));
        $delayMin     = max(5,  (int)($input['delay_min'] ?? 8));
        $delayMax     = max($delayMin, (int)($input['delay_max'] ?? 20));
        $usuarioId    = (int)($_REQUEST['_user_id'] ?? 0);

        if (!$nombre || !$mensaje || empty($instIds)) {
            http_response_code(400);
            return ['error' => 'nombre, mensaje e instancias_ids son requeridos'];
        }

        $pdo = Database::connect();

        // Generar UUID para el grupo
        $grupoId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $stmtInst = $pdo->prepare('SELECT id FROM instancias WHERE id = ?');
        $stmtIns  = $pdo->prepare('
            INSERT INTO campanas
                (instancia_id, usuario_id, nombre, mensaje, delay_min, delay_max,
                 estado, grupo_campana, grupo_nombre, total_contactos, enviados, fallidos, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, \'borrador\', ?, ?, 0, 0, 0, NOW(), NOW())
        ');
        $stmtContacts = $pdo->prepare('
            SELECT id, numero FROM contactos WHERE instancia_id = ? AND activo = 1 AND numero != \'\' AND LENGTH(numero) > 8
        ');
        $stmtContIns = $pdo->prepare('
            INSERT INTO campanas_contactos (campana_id, contacto_id, numero, estado) VALUES (?, ?, ?, \'pendiente\')
        ');
        $stmtUpdTotal = $pdo->prepare('UPDATE campanas SET total_contactos = ? WHERE id = ?');

        $campanas_ids = [];

        foreach ($instIds as $instId) {
            // Verificar instancia existe
            $stmtInst->execute([$instId]);
            if (!$stmtInst->fetchColumn()) continue;

            $stmtIns->execute([$instId, $usuarioId, $nombre, $mensaje, $delayMin, $delayMax, $grupoId, $nombre]);
            $campanaId = (int)$pdo->lastInsertId();
            $campanas_ids[] = $campanaId;

            // Obtener contactos de la instancia y agregarlos a la campaña
            $stmtContacts->execute([$instId]);
            $contactos = $stmtContacts->fetchAll();
            $total     = 0;

            foreach ($contactos as $c) {
                try {
                    $stmtContIns->execute([$campanaId, $c['id'], $c['numero']]);
                    $total++;
                } catch (\Throwable $e) {
                    // Ignorar duplicados
                }
            }

            $stmtUpdTotal->execute([$total, $campanaId]);
        }

        if (empty($campanas_ids)) {
            http_response_code(422);
            return ['error' => 'No se encontraron instancias válidas'];
        }

        return [
            'ok'           => true,
            'grupo_id'     => $grupoId,
            'campanas_ids' => $campanas_ids,
            'instancias'   => count($campanas_ids),
        ];
    }

    /**
     * GET /api/campaigns-multi/{id}/progress
     * Progreso de una campaña multi por grupo_id.
     */
    public function progress(string $id): array
    {
        Plan::requerir('campanas_multi');
        $pdo = Database::connect();

        $stmt = $pdo->prepare('
            SELECT c.id, c.instancia_id, i.nombre AS instancia_nombre,
                   c.estado, c.total_contactos, c.enviados, c.fallidos, c.pendientes
            FROM campanas c
            LEFT JOIN instancias i ON c.instancia_id = i.id
            WHERE c.grupo_campana = ?
        ');
        $stmt->execute([$id]);
        $filas = $stmt->fetchAll();

        if (empty($filas)) {
            http_response_code(404);
            return ['error' => 'Grupo de campaña no encontrado'];
        }

        $total     = array_sum(array_column($filas, 'total_contactos'));
        $enviados  = array_sum(array_column($filas, 'enviados'));
        $fallidos  = array_sum(array_column($filas, 'fallidos'));
        $pendientes = array_sum(array_column($filas, 'pendientes'));
        $pct = $total > 0 ? round($enviados / $total * 100, 1) : 0;

        return [
            'grupo_id'   => $id,
            'instancias' => $filas,
            'resumen'    => [
                'total'     => $total,
                'enviados'  => $enviados,
                'fallidos'  => $fallidos,
                'pendientes' => $pendientes,
                'porcentaje' => $pct,
            ],
        ];
    }
}
