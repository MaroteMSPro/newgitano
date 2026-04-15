<?php

namespace App\Controllers;

use App\Core\Database;

class BibliotecaController
{
    public function categories(): array
    {
        $pdo = Database::connect();
        $cats = $pdo->query('
            SELECT c.*, COUNT(a.id) as archivos_count
            FROM crm_biblioteca_categorias c
            LEFT JOIN crm_biblioteca_archivos a ON a.categoria_id = c.id AND a.activo = 1
            WHERE c.activa = 1
            GROUP BY c.id
            ORDER BY c.orden, c.nombre
        ')->fetchAll();
        return ['categories' => $cats];
    }

    public function files(): array
    {
        $categoriaId = $_GET['categoria_id'] ?? null;
        $pdo = Database::connect();

        $where = 'a.activo = 1';
        $params = [];
        if ($categoriaId) {
            $where .= ' AND a.categoria_id = ?';
            $params[] = $categoriaId;
        }

        $stmt = $pdo->prepare("
            SELECT a.*, c.nombre as categoria_nombre
            FROM crm_biblioteca_archivos a
            LEFT JOIN crm_biblioteca_categorias c ON a.categoria_id = c.id
            WHERE $where
            ORDER BY a.orden, a.nombre
        ");
        $stmt->execute($params);
        return ['files' => $stmt->fetchAll()];
    }

    public function createCategory(): array
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $nombre = trim($body['nombre'] ?? '');
        if (!$nombre) { http_response_code(400); return ['error' => 'Nombre requerido']; }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('INSERT INTO crm_biblioteca_categorias (instancia_id, nombre, icono, orden, activa) VALUES (?, ?, ?, 99, 1)');
        $stmt->execute([
            $body['instancia_id'] ?? 1,
            $nombre,
            $body['icono'] ?? '📁',
        ]);
        return ['id' => $pdo->lastInsertId(), 'ok' => true];
    }

    public function updateCategory(string $id): array
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $pdo = Database::connect();
        $pdo->prepare('UPDATE crm_biblioteca_categorias SET nombre = ?, icono = ?, instancia_id = ? WHERE id = ?')
            ->execute([$body['nombre'] ?? '', $body['icono'] ?? '📁', $body['instancia_id'] ?? 1, $id]);
        return ['ok' => true];
    }

    public function deleteCategory(string $id): array
    {
        $pdo = Database::connect();
        $pdo->prepare('UPDATE crm_biblioteca_categorias SET activa = 0 WHERE id = ?')->execute([$id]);
        $pdo->prepare('UPDATE crm_biblioteca_archivos SET activo = 0 WHERE categoria_id = ?')->execute([$id]);
        return ['ok' => true];
    }

    public function uploadFile(): array
    {
        if (empty($_FILES['archivo'])) {
            http_response_code(400);
            return ['error' => 'Archivo requerido'];
        }

        $file = $_FILES['archivo'];
        $nombre = trim($_POST['nombre'] ?? $file['name']);
        $tipo = $_POST['tipo'] ?? 'document';
        $descripcion = $_POST['descripcion'] ?? '';
        $categoriaId = (int)($_POST['categoria_id'] ?? 0);
        $instanciaId = (int)($_POST['instancia_id'] ?? 1);
        $userId = $_REQUEST['_user_id'] ?? null;

        if (!$categoriaId) { http_response_code(400); return ['error' => 'categoria_id requerido']; }

        // Upload directory
        $uploadDir = 'assets/uploads/biblioteca/';
        $basePath = '/home/u695160153/domains/luxom.com.ar/public_html/crm/';
        $fullDir = $basePath . $uploadDir;

        if (!is_dir($fullDir)) mkdir($fullDir, 0755, true);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'bib_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $destPath = $fullDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            http_response_code(500);
            return ['error' => 'Error al guardar archivo'];
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('
            INSERT INTO crm_biblioteca_archivos (categoria_id, instancia_id, nombre, descripcion, tipo, archivo_path, archivo_nombre, archivo_mime, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $categoriaId, $instanciaId, $nombre, $descripcion, $tipo,
            $uploadDir . $filename, $file['name'], $file['type'], $userId,
        ]);

        return ['id' => $pdo->lastInsertId(), 'ok' => true];
    }

    public function deleteFile(string $id): array
    {
        $pdo = Database::connect();
        $pdo->prepare('UPDATE crm_biblioteca_archivos SET activo = 0 WHERE id = ?')->execute([$id]);
        return ['ok' => true];
    }
}
