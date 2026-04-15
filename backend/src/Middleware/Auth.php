<?php

namespace App\Middleware;

use App\Core\JWT;
use App\Core\Router;

class Auth
{
    public function handle(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // Soporte para token por query string (descarga directa CSV)
        if (empty($header) && !empty($_GET['_token'])) {
            $header = 'Bearer ' . $_GET['_token'];
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            http_response_code(401);
            Router::json(['error' => 'Token requerido']);
            exit;
        }

        $payload = JWT::decode($m[1]);

        if (!$payload) {
            http_response_code(401);
            Router::json(['error' => 'Token inválido o expirado']);
            exit;
        }

        // Store user in global and request for controllers
        $GLOBALS['auth_user'] = $payload;
        $_REQUEST['_user_id'] = $payload['id'] ?? null;
        $_REQUEST['_user_rol'] = $payload['rol'] ?? '';
        $_REQUEST['_user_nombre'] = $payload['nombre'] ?? '';
    }

    public function requireAdmin(): void
    {
        $this->handle();

        if (($GLOBALS['auth_user']['rol'] ?? '') !== 'admin') {
            http_response_code(403);
            Router::json(['error' => 'Acceso denegado']);
            exit;
        }
    }
}
