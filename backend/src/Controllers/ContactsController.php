<?php

namespace App\Controllers;

use App\Core\Database;

class ContactsController
{
    public function index(): array
    {
        $pdo = Database::connect();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');
        $tipo = trim($_GET["tipo"] ?? "individual");

        $instanciaId = $_GET['instancia_id'] ?? '';

        $where = ['c.tipo = ?'];
        $params = [$tipo];

        if ($instanciaId !== '') {
            $where[] = 'c.instancia_id = ?';
            $params[] = $instanciaId;
        }

        if ($search !== '') {
            $where[] = '(c.nombre LIKE ? OR c.numero LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $where = 'WHERE ' . implode(' AND ', $where);

        $total = $pdo->prepare("SELECT COUNT(*) FROM contactos c $where");
        $total->execute($params);
        $totalCount = (int)$total->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT c.*, c.numero as telefono,
                (SELECT COUNT(*) FROM mensajes m WHERE m.contacto_id = c.id) as total_mensajes,
                (SELECT MAX(m.created_at) FROM mensajes m WHERE m.contacto_id = c.id) as ultimo_mensaje
            FROM contactos c 
            $where
            ORDER BY c.id DESC 
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);

        return [
            'contacts' => $stmt->fetchAll(),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalCount,
                'pages' => (int)ceil($totalCount / $limit),
            ],
        ];
    }

    public function show(string $id): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT *, numero as telefono FROM contactos WHERE id = ?');
        $stmt->execute([$id]);
        $contact = $stmt->fetch();

        if (!$contact) {
            http_response_code(404);
            return ['error' => 'Contacto no encontrado'];
        }

        // Get CRM messages for this contact
        $msgs = $pdo->prepare('
            SELECT cm.*, cm.direccion as tipo, cm.contenido as mensaje, cm.created_at as fecha
            FROM crm_mensajes cm 
            JOIN crm_leads cl ON cm.lead_id = cl.id
            WHERE cl.numero = ? 
            ORDER BY cm.created_at DESC 
            LIMIT 50
        ');
        $msgs->execute([$contact['numero']]);

        return [
            'contact' => $contact,
            'messages' => $msgs->fetchAll(),
        ];
    }

    public function store(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $nombre = trim($input['nombre'] ?? '');
        $numero = trim($input['numero'] ?? $input['telefono'] ?? '');

        if (empty($numero)) {
            http_response_code(400);
            return ['error' => 'Número requerido'];
        }

        $pdo = Database::connect();

        $exists = $pdo->prepare('SELECT id FROM contactos WHERE numero = ?');
        $exists->execute([$numero]);
        if ($exists->fetch()) {
            http_response_code(409);
            return ['error' => 'El contacto ya existe'];
        }

        $stmt = $pdo->prepare('INSERT INTO contactos (nombre, numero, instancia_id, created_at) VALUES (?, ?, 1, NOW())');
        $stmt->execute([$nombre, $numero]);

        return ['id' => $pdo->lastInsertId(), 'message' => 'Contacto creado'];
    }

    public function destroy(string $id): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('DELETE FROM contactos WHERE id = ?');
        $stmt->execute([$id]);

        return ['message' => 'Contacto eliminado'];
    }
}
