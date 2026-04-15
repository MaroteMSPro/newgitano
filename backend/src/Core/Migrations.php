<?php
namespace App\Core;

/**
 * Sistema de migraciones automáticas.
 * Corre al bootear — aplica solo las migraciones pendientes en orden.
 *
 * Archivos en database/migrations/:
 *   - vX.YY_nombre.sql  → migraciones incrementales
 *   - vX.00_clean.sql   → schema completo de la versión (solo referencia, no se corre)
 *
 * La tabla schema_migrations registra qué versiones fueron aplicadas.
 */
class Migrations
{
    private \PDO $db;
    private string $migrationsDir;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->migrationsDir = dirname(dirname(__DIR__)) . '/database/migrations';
    }

    /**
     * Correr migraciones pendientes. Silencioso si todo está al día.
     */
    public function run(): void
    {
        // Crear tabla de control si no existe
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `version`    VARCHAR(20)  NOT NULL UNIQUE,
                `nombre`     VARCHAR(255) NOT NULL,
                `applied_at` DATETIME     DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $applied = $this->getApplied();
        $pending = $this->getPending($applied);

        foreach ($pending as $migration) {
            $this->apply($migration);
        }
    }

    /**
     * Versiones ya aplicadas en esta DB.
     */
    private function getApplied(): array
    {
        try {
            $rows = $this->db->query("SELECT version FROM schema_migrations")->fetchAll(\PDO::FETCH_COLUMN);
            return $rows ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Migraciones pendientes, ordenadas por nombre de archivo.
     * Excluye los _clean.sql (son solo para referencia/importación nueva).
     */
    private function getPending(array $applied): array
    {
        if (!is_dir($this->migrationsDir)) return [];

        $files = glob($this->migrationsDir . '/v*.sql');
        if (!$files) return [];

        sort($files); // orden natural por nombre

        $pending = [];
        foreach ($files as $file) {
            $filename = basename($file, '.sql');

            // Saltear schemas completos (vX.00_clean)
            if (str_contains($filename, '_clean')) continue;

            // Extraer versión: "v1.02_envios_masivos" → "v1.02"
            preg_match('/^(v[\d]+\.[\d]+)/', $filename, $m);
            $version = $m[1] ?? $filename;

            if (!in_array($version, $applied)) {
                $pending[] = ['version' => $version, 'file' => $file, 'nombre' => $filename];
            }
        }

        return $pending;
    }

    /**
     * Aplicar una migración y registrarla.
     */
    private function apply(array $migration): void
    {
        $sql = file_get_contents($migration['file']);
        if (!$sql) return;

        try {
            // Ejecutar cada statement separado por ;
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($s) => $s !== '' && !str_starts_with(ltrim($s), '--')
            );

            foreach ($statements as $stmt) {
                if (trim($stmt)) {
                    $this->db->exec($stmt);
                }
            }

            // Registrar como aplicada
            $this->db->prepare("INSERT IGNORE INTO schema_migrations (version, nombre) VALUES (?, ?)")
                     ->execute([$migration['version'], $migration['nombre']]);

            error_log("[Migrations] Applied: {$migration['version']} — {$migration['nombre']}");
        } catch (\Throwable $e) {
            error_log("[Migrations] ERROR applying {$migration['version']}: " . $e->getMessage());
            // No detener el boot — loggear y seguir
        }
    }

    /**
     * Estado para el panel de controlcrm (vía API).
     */
    public function status(): array
    {
        $applied = $this->getApplied();
        $pending = $this->getPending($applied);

        $lastApplied = null;
        if ($applied) {
            rsort($applied);
            $lastApplied = $applied[0];
        }

        return [
            'applied'      => count($applied),
            'pending'      => count($pending),
            'last_version' => $lastApplied,
            'pending_list' => array_column($pending, 'version'),
        ];
    }
}
