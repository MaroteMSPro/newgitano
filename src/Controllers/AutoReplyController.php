<?php

namespace App\Controllers;

use App\Core\Database;

class AutoReplyController
{
    public function index(): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('
            SELECT ar.*, u.nombre as usuario_nombre
            FROM crm_auto_respuesta ar
            LEFT JOIN usuarios u ON ar.usuario_id = u.id
            ORDER BY ar.created_at DESC
        ');
        return ['auto_replies' => $stmt->fetchAll()];
    }

    public function store(): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);

        $stmt = $pdo->prepare('
            INSERT INTO crm_auto_respuesta (usuario_id, nombre, activa, mensaje, solo_nuevos)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $body['usuario_id'] ?? $_REQUEST['_user_id'],
            $body['nombre'] ?? 'Mi respuesta',
            $body['activa'] ?? 1,
            $body['mensaje'],
            $body['solo_nuevos'] ?? 1,
        ]);

        return ['ok' => true, 'id' => $pdo->lastInsertId()];
    }

    public function update(string $id): array
    {
        $pdo = Database::connect();
        $body = json_decode(file_get_contents('php://input'), true);

        $pdo->prepare('
            UPDATE crm_auto_respuesta SET nombre = ?, activa = ?, mensaje = ?, solo_nuevos = ?
            WHERE id = ?
        ')->execute([$body['nombre'], $body['activa'] ?? 1, $body['mensaje'], $body['solo_nuevos'] ?? 1, $id]);

        return ['ok' => true];
    }

    public function destroy(string $id): array
    {
        $pdo = Database::connect();
        $pdo->prepare('DELETE FROM crm_auto_respuesta WHERE id = ?')->execute([$id]);
        return ['ok' => true];
    }
}
