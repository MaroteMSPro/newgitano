<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\JWT;
use App\Core\Router;

class AuthController
{
    public function login(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $usuario = trim($input['usuario'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($usuario) || empty($password)) {
            http_response_code(400);
            return ['error' => 'Usuario y contraseña requeridos'];
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = ? AND activo = 1 LIMIT 1');
        $stmt->execute([$usuario]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            http_response_code(401);
            return ['error' => 'Credenciales incorrectas'];
        }

        // Update last login
        $pdo->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?')
            ->execute([$user['id']]);

        $token = JWT::encode([
            'id' => $user['id'],
            'usuario' => $user['usuario'],
            'nombre' => $user['nombre'],
            'rol' => $user['rol'],
        ]);

        return [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'nombre' => $user['nombre'],
                'usuario' => $user['usuario'],
                'rol' => $user['rol'],
            ],
        ];
    }

    public function me(): array
    {
        $user = $GLOBALS['auth_user'];

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id, nombre, usuario, rol, crm_activo, crm_online FROM usuarios WHERE id = ?');
        $stmt->execute([$user['id']]);
        $data = $stmt->fetch();

        if (!$data) {
            http_response_code(404);
            return ['error' => 'Usuario no encontrado'];
        }

        return ['user' => $data];
    }
}
