<?php
/**
 * Script para sincronizar estado de instancias desde Evolution API
 * Corre cada 6 horas vía cron
 */

require '/home/u695160153/domains/luxom.com.ar/public_html/elgitano/src/Core/Database.php';
require '/home/u695160153/domains/luxom.com.ar/public_html/elgitano/src/Services/EvolutionService.php';

use Marote\Database;
use App\Services\EvolutionService;

$pdo = Database::connect();

// Obtener todas las instancias
$stmt = $pdo->query('SELECT id, nombre FROM instancias WHERE estado != "eliminado"');
$instancias = $stmt->fetchAll();

foreach ($instancias as $inst) {
    try {
        $evo = new EvolutionService($inst['nombre']);
        
        // Consultar estado de la instancia en Evolution
        $result = $evo->obtenerInstancia();
        
        if (!empty($result['success']) && !empty($result['instance'])) {
            $estado_evo = $result['instance']['connectionStatus'] ?? 'unknown';
            
            // Mapear estado Evolution a nuestro formato
            $estado = match($estado_evo) {
                'open' => 'conectado',
                'connecting' => 'escaneando',
                'close' => 'desconectado',
                default => 'desconectado'
            };
            
            // Actualizar en BD
            $upd = $pdo->prepare('UPDATE instancias SET estado = ? WHERE id = ?');
            $upd->execute([$estado, $inst['id']]);
            
            echo "[" . date('Y-m-d H:i:s') . "] {$inst['nombre']}: $estado_evo → $estado\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] {$inst['nombre']}: No se pudo obtener estado\n";
        }
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] {$inst['nombre']}: ERROR - {$e->getMessage()}\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Sincronización completada\n";
?>
