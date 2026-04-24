<?php

namespace App\Controllers;

use App\Core\Database;

class UsersController
{
    public function index(): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('
            SELECT u.id, u.usuario, u.nombre, u.responsable, u.rol, u.activo, u.crm_online, u.crm_activo, u.ultimo_login, u.created_at,
                GROUP_CONCAT(i.nombre) as instancias
            FROM usuarios u
            LEFT JOIN usuarios_instancias ui ON ui.usuario_id = u.id
            LEFT JOIN instancias i ON ui.instancia_id = i.id
            GROUP BY u.id
            ORDER BY u.nombre
        ');
        return ['users' => $stmt->fetchAll()];
    }

    public function store(): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);

        $hash = password_hash($body['password'] ?? 'password', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('
            INSERT INTO usuarios (usuario, password, nombre, responsable, rol, activo, crm_activo)
            VALUES (?, ?, ?, ?, ?, 1, 1)
        ');
        $stmt->execute([
            $body['usuario'], $hash, $body['nombre'],
            $body['responsable'] ?? null, $body['rol'] ?? 'usuario',
        ]);
        $userId = $pdo->lastInsertId();

        // Assign instances
        if (!empty($body['instancias'])) {
            $ins = $pdo->prepare('INSERT INTO usuarios_instancias (usuario_id, instancia_id) VALUES (?, ?)');
            foreach ($body['instancias'] as $instId) {
                $ins->execute([$userId, $instId]);
            }
        }

        return ['ok' => true, 'id' => $userId];
    }

    public function update(string $id): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);

        $pdo->prepare('
            UPDATE usuarios SET nombre = ?, usuario = ?, responsable = ?, rol = ?, activo = ?, crm_activo = ?
            WHERE id = ?
        ')->execute([
            $body['nombre'], $body['usuario'], $body['responsable'] ?? null,
            $body['rol'] ?? 'usuario', $body['activo'] ?? 1, $body['crm_activo'] ?? 1, $id,
        ]);

        if (isset($body['password']) && $body['password']) {
            $hash = password_hash($body['password'], PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE usuarios SET password = ? WHERE id = ?')->execute([$hash, $id]);
        }

        // Update instances
        if (isset($body['instancias'])) {
            $pdo->prepare('DELETE FROM usuarios_instancias WHERE usuario_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO usuarios_instancias (usuario_id, instancia_id) VALUES (?, ?)');
            foreach ($body['instancias'] as $instId) {
                $ins->execute([$id, $instId]);
            }
        }

        return ['ok' => true];
    }

    public function destroy(string $id): array
    {
        $pdo = Database::connect();
        $pdo->prepare('DELETE FROM usuarios_instancias WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE usuarios SET activo = 0 WHERE id = ?')->execute([$id]);
        return ['ok' => true];
    }
}
