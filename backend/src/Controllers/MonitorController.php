<?php

namespace App\Controllers;

use App\Core\Database;

class MonitorController
{
    public function dashboard(): array
    {
        $pdo = Database::connect();

        // Per-user stats: pending, avg response time, active leads
        $users = $pdo->query("
            SELECT u.id, u.nombre, u.crm_online,
                (SELECT COUNT(*) FROM crm_leads l WHERE l.usuario_asignado_id = u.id AND l.estado = 'asignado' AND l.mensajes_sin_leer > 0) as pendientes,
                (SELECT COUNT(*) FROM crm_leads l WHERE l.usuario_asignado_id = u.id AND l.estado = 'asignado') as activos,
                (SELECT AVG(l.tiempo_respuesta_seg) FROM crm_leads l WHERE l.usuario_asignado_id = u.id AND l.tiempo_respuesta_seg > 0) as avg_respuesta_seg,
                (SELECT COUNT(*) FROM crm_leads l WHERE l.usuario_asignado_id = u.id AND l.estado = 'cerrado_positivo') as cerrado_pos,
                (SELECT COUNT(*) FROM crm_leads l WHERE l.usuario_asignado_id = u.id AND l.estado = 'cerrado_negativo') as cerrado_neg
            FROM usuarios u
            WHERE u.rol != 'admin'
            ORDER BY pendientes DESC, u.nombre
        ")->fetchAll();

        // Leads waiting longest (sin responder)
        $waiting = $pdo->query("
            SELECT l.id, l.nombre, l.numero, l.ultimo_mensaje_at, l.mensajes_sin_leer,
                u.nombre as asignado_nombre,
                TIMESTAMPDIFF(MINUTE, l.ultimo_mensaje_at, NOW()) as minutos_espera
            FROM crm_leads l
            LEFT JOIN usuarios u ON l.usuario_asignado_id = u.id
            WHERE l.estado IN ('nuevo','asignado') AND l.mensajes_sin_leer > 0
            ORDER BY l.ultimo_mensaje_at ASC
            LIMIT 50
        ")->fetchAll();

        // Recent incoming messages
        $recent = $pdo->query("
            SELECT m.id, m.lead_id, m.contenido, m.created_at, m.tipo,
                l.nombre as lead_nombre, l.numero as lead_numero,
                u.nombre as asignado_nombre,
                TIMESTAMPDIFF(MINUTE, m.created_at, NOW()) as minutos_ago
            FROM crm_mensajes m
            JOIN crm_leads l ON m.lead_id = l.id
            LEFT JOIN usuarios u ON l.usuario_asignado_id = u.id
            WHERE m.direccion = 'entrante'
            ORDER BY m.created_at DESC
            LIMIT 30
        ")->fetchAll();

        // Global stats
        $globalStats = $pdo->query("
            SELECT
                (SELECT COUNT(*) FROM crm_leads WHERE estado IN ('nuevo','asignado') AND mensajes_sin_leer > 0) as total_pendientes,
                (SELECT COUNT(*) FROM crm_leads WHERE estado = 'nuevo') as sin_asignar,
                (SELECT AVG(tiempo_respuesta_seg) FROM crm_leads WHERE tiempo_respuesta_seg > 0) as avg_global_seg
        ")->fetch();

        return [
            'users' => $users,
            'waiting' => $waiting,
            'recent' => $recent,
            'stats' => $globalStats,
        ];
    }
}
