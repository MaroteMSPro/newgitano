<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function connect(): PDO
    {
        if (self::$instance === null) {
            try {
                self::$instance = new PDO(
                    sprintf(
                        'mysql:host=%s;dbname=%s;charset=utf8mb4',
                        $_ENV['DB_HOST'] ?? 'localhost',
                        $_ENV['DB_NAME'] ?? 'crm_luxom'
                    ),
                    $_ENV['DB_USER'] ?? '',
                    $_ENV['DB_PASS'] ?? '',
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
                // Forzar timezone Argentina en MySQL
                self::$instance->exec("SET time_zone = '-03:00'");
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Database connection failed']);
                exit;
            }
        }

        return self::$instance;
    }
}