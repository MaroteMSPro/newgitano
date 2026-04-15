<?php

namespace App\Controllers;

use App\Core\Database;

class ShortcutsController
{
    public function index(): array
    {
        $pdo = Database::connect();
        $userId = $_REQUEST['_user_id'] ?? null;
        $rol = $_REQUEST['_user_rol'] ?? '';

        // Admin ve todos, usuario ve solo los suyos
        if ($rol === 'admin') {
            $stmt = $pdo->query('
                SELECT a.*, u.nombre as usuario_nombre, i.nombre as instancia_nombre
                FROM crm_atajos a
                LEFT JOIN usuarios u ON a.usuario_id = u.id
                LEFT JOIN instancias i ON a.instancia_id = i.id
                ORDER BY a.orden, a.nombre
            ');
        } else {
            $stmt = $pdo->prepare('
                SELECT a.*, u.nombre as usuario_nombre, i.nombre as instancia_nombre
                FROM crm_atajos a
                LEFT JOIN usuarios u ON a.usuario_id = u.id
                LEFT JOIN instancias i ON a.instancia_id = i.id
                WHERE a.usuario_id = ? OR a.usuario_id IS NULL
                ORDER BY a.orden, a.nombre
            ');
            $stmt->execute([$userId]);
        }
        return ['shortcuts' => $stmt->fetchAll()];
    }

    public function store(): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);

        $stmt = $pdo->prepare('
            INSERT INTO crm_atajos (instancia_id, usuario_id, nombre, atajo, tipo, contenido, activo, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?)
        ');
        $userId = $body['usuario_id'] ?? $_REQUEST['_user_id'] ?? null;
        $stmt->execute([
            $body['instancia_id'] ?? null, $userId,
            $body['nombre'], $body['atajo'] ?? null,
            $body['tipo'] ?? 'text', $body['contenido'],
            $_REQUEST['_user_id'] ?? null,
        ]);

        return ['ok' => true, 'id' => $pdo->lastInsertId()];
    }

    public function update(string $id): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);

        $pdo->prepare('
            UPDATE crm_atajos SET nombre = ?, atajo = ?, contenido = ?, activo = ?
            WHERE id = ?
        ')->execute([$body['nombre'], $body['atajo'] ?? null, $body['contenido'], $body['activo'] ?? 1, $id]);

        return ['ok' => true];
    }

    public function destroy(string $id): array
    {
        $pdo = Database::connect();
        $pdo->prepare('DELETE FROM crm_atajos WHERE id = ?')->execute([$id]);
        return ['ok' => true];
    }
}
