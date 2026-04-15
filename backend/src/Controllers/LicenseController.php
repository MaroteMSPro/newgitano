<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\JWT;

class LicenseController
{
    public function info(): array
    {
        // Auth interna: no depende del middleware para evitar que el componente Vue rompa
        // Si el token es inválido, devuelve estructura válida vacía en vez de explotar
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            $payload = JWT::decode($m[1]);
        } else {
            $payload = null;
        }

        if (!$payload) {
            // Token inválido/expirado: devolver estructura válida para que Vue no rompa
            // El frontend mostrará estado vacío y redirigirá al login por su propio router guard
            http_response_code(401);
            return [
                'tenant'     => '',
                'plan'       => '',
                'expires_at' => null,
                'days_left'  => null,
                'expired'    => true,
                'status'     => 'expired',
                'users'      => ['used' => 0, 'max' => 0],
                'instances'  => ['used' => 0, 'max' => 0],
                '_auth_error' => 'Token inválido o expirado',
            ];
        }

        // Token válido — devolver datos reales
        $totalUsers     = 0;
        $totalInstances = 0;

        try {
            $pdo            = Database::connect();
            $totalUsers     = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1")->fetchColumn();
            $totalInstances = (int)$pdo->query("SELECT COUNT(*) FROM instancias")->fetchColumn();
        } catch (\Throwable $e) {
            // DB falla — seguimos con 0s
        }

        $expiresAt = $_ENV['EXPIRES_AT'] ?? '';
        $daysLeft  = null;
        $expired   = false;

        if ($expiresAt) {
            try {
                $diff     = (new \DateTime($expiresAt))->diff(new \DateTime());
                $daysLeft = $diff->invert ? $diff->days : -$diff->days;
                $expired  = !$diff->invert;
            } catch (\Throwable $e) {
                // fecha inválida
            }
        }

        return [
            'tenant'     => $_ENV['TENANT_NAME'] ?? '',
            'plan'       => $_ENV['PLAN']        ?? 'básico',
            'expires_at' => $expiresAt ?: null,
            'days_left'  => $daysLeft,
            'expired'    => $expired,
            'status'     => $expired ? 'expired' : 'active',
            'users' => [
                'used' => $totalUsers,
                'max'  => (int)($_ENV['MAX_USERS'] ?? 5),
            ],
            'instances' => [
                'used' => $totalInstances,
                'max'  => (int)($_ENV['MAX_INSTANCES'] ?? 1),
            ],
        ];
    }

    public function migrations(): array
    {
        $db     = \App\Core\Database::getInstance();
        $runner = new \App\Core\Migrations($db);
        return $runner->status();
    }
}
