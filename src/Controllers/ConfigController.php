<?php

namespace App\Controllers;

use App\Core\Database;

class ConfigController
{
    public function crmConfig(): array
    {
        $pdo = Database::connect();
        $config = $pdo->query('SELECT * FROM crm_config LIMIT 1')->fetch();

        // Lead stats
        $statsRaw = $pdo->query("SELECT estado, COUNT(*) as c FROM crm_leads GROUP BY estado")->fetchAll();
        $stats = ['total' => 0, 'sin_asignar' => 0, 'asignados' => 0, 'cerrado_positivo' => 0, 'cerrado_negativo' => 0];
        foreach ($statsRaw as $s) {
            $stats['total'] += $s['c'];
            if ($s['estado'] === 'nuevo') $stats['sin_asignar'] += $s['c'];
            elseif ($s['estado'] === 'asignado') $stats['asignados'] += $s['c'];
            elseif ($s['estado'] === 'cerrado_positivo') $stats['cerrado_positivo'] += $s['c'];
            elseif ($s['estado'] === 'cerrado_negativo') $stats['cerrado_negativo'] += $s['c'];
        }

        return ['config' => $config, 'stats' => $stats];
    }

    public function crmUsersStats(): array
    {
        $pdo = Database::connect();
        $users = $pdo->query("
            SELECT u.id, u.nombre, u.crm_online,
                (SELECT COUNT(*) FROM crm_leads l WHERE l.usuario_asignado_id = u.id AND l.estado IN ('nuevo','asignado')) as activos,
                (SELECT COUNT(*) FROM crm_leads l WHERE l.usuario_asignado_id = u.id AND l.estado = 'cerrado_positivo') as cerrado_pos,
                (SELECT COUNT(*) FROM crm_leads l WHERE l.usuario_asignado_id = u.id AND l.estado = 'cerrado_negativo') as cerrado_neg
            FROM usuarios u ORDER BY u.nombre
        ")->fetchAll();
        return ['users' => $users];
    }

    public function updateCrmConfig(): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);

        $pdo->prepare('
            UPDATE crm_config SET modo_distribucion = ?, auto_asignar = ?, max_chats_por_usuario = ?,
            notificar_nuevo_lead = ?, auto_reasignar = ?, timeout_respuesta_min = ?, notificar_timeout = ?
            WHERE id = 1
        ')->execute([
            $body['modo_distribucion'] ?? 'round_robin',
            $body['auto_asignar'] ?? 1,
            $body['max_chats_por_usuario'] ?? 300,
            $body['notificar_nuevo_lead'] ?? 1,
            $body['auto_reasignar'] ?? 0,
            $body['timeout_respuesta_min'] ?? 60,
            $body['notificar_timeout'] ?? 1,
        ]);

        return ['ok' => true];
    }

    public function campaignsConfig(): array
    {
        $pdo = Database::connect();
        $config = $pdo->query('SELECT * FROM config_campanas LIMIT 1')->fetch();

        // Campaign stats
        $statsRaw = $pdo->query("SELECT estado, COUNT(*) as c FROM campanas GROUP BY estado")->fetchAll();
        $stats = ['en_proceso' => 0, 'programadas' => 0, 'completadas' => 0, 'total' => 0];
        foreach ($statsRaw as $s) {
            $stats['total'] += $s['c'];
            if (in_array($s['estado'], ['enviando', 'en_proceso'])) $stats['en_proceso'] += $s['c'];
            elseif ($s['estado'] === 'programada') $stats['programadas'] += $s['c'];
            elseif ($s['estado'] === 'completada') $stats['completadas'] += $s['c'];
        }

        // Cron log
        $cronLog = [];
        try {
            $logStmt = $pdo->query("SELECT mensaje, created_at FROM cron_log ORDER BY id DESC LIMIT 30");
            foreach ($logStmt->fetchAll() as $row) {
                $cronLog[] = "[{$row['created_at']}] {$row['mensaje']}";
            }
        } catch (\Exception $e) { /* table may not exist */ }

        return ['config' => $config, 'stats' => $stats, 'cron_log' => $cronLog];
    }

    public function updateCampaignsConfig(): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);

        $pdo->prepare('
            UPDATE config_campanas SET hora_inicio = ?, hora_fin = ?, dias_semana = ?,
            max_por_hora = ?, max_por_dia = ?, pausa_cada_n = ?, pausa_minutos = ?,
            max_errores_consecutivos = ?, delay_progresivo = ?, activo = ?
            WHERE id = 1
        ')->execute([
            $body['hora_inicio'] ?? 8, $body['hora_fin'] ?? 21,
            $body['dias_semana'] ?? '1,2,3,4,5,6',
            $body['max_por_hora'] ?? 200, $body['max_por_dia'] ?? 2000,
            $body['pausa_cada_n'] ?? 30, $body['pausa_minutos'] ?? 5,
            $body['max_errores_consecutivos'] ?? 5,
            $body['delay_progresivo'] ?? 1, $body['activo'] ?? 1,
        ]);

        return ['ok' => true];
    }
}
