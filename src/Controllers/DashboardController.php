<?php

namespace App\Controllers;

use App\Core\Database;

class DashboardController
{
    public function index(): array
    {
        $pdo = Database::connect();

        // Lead stats
        $leads = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(estado = 'nuevo') as nuevos,
                SUM(estado = 'asignado') as asignados,
                SUM(estado = 'cerrado_positivo') as cerrados_pos,
                SUM(estado = 'cerrado_negativo') as cerrados_neg
            FROM crm_leads
        ")->fetch();

        // Total contacts
        $totalContactos = (int)$pdo->query("SELECT COUNT(*) FROM contactos")->fetchColumn();

        // Messages today
        $msgHoy = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(direccion = 'salida') as enviados,
                SUM(direccion = 'entrada') as recibidos
            FROM crm_mensajes 
            WHERE DATE(created_at) = CURDATE()
        ")->fetch();

        // Active users
        $usersOnline = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE crm_online = 1 AND activo = 1")->fetchColumn();

        // Campaigns active
        $campaignsActive = (int)$pdo->query("SELECT COUNT(*) FROM campanas WHERE estado IN ('activa','enviando')")->fetchColumn();

        // Instances
        $totalInstancias = (int)$pdo->query("SELECT COUNT(*) FROM instancias")->fetchColumn();

        return [
            'leads' => $leads,
            'contacts_total' => $totalContactos,
            'messages_today' => $msgHoy,
            'users_online' => $usersOnline,
            'campaigns_active' => $campaignsActive,
            'instances_total' => $totalInstancias,
        ];
    }
}
