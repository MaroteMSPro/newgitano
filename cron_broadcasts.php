<?php
/**
 * cron_broadcasts.php
 *
 * Script para ejecutar por CRON de Hostinger (o cualquier scheduler).
 * Procesa difusiones pendientes (ver BroadcastsController::processPending).
 *
 * === CONFIGURACIÓN EN HOSTINGER ===
 *
 * Panel de Hostinger → Cron Jobs → Agregar:
 *
 *   Comando:  /usr/bin/php /home/USUARIO/domains/TU_DOMINIO/public_html/cron_broadcasts.php >> /home/USUARIO/logs/cron_broadcasts.log 2>&1
 *   Cada:     1 minuto (o cada 5 minutos si el plan no permite 1 min)
 *
 * Reemplazar USUARIO y TU_DOMINIO por los reales. En Hostinger el path real
 * suele ser /home/u695160153/domains/elgitano.luxom.com.ar/public_html/
 *
 * === SEGURIDAD ===
 *
 * Este script está diseñado para ejecutarse SOLO desde CLI (no web).
 * Si se intenta acceder por HTTP, corta inmediatamente.
 *
 * === OPCIONES ===
 *
 * Variables de entorno opcionales:
 *   MAX_ENVIOS   = cantidad máxima de envíos por pasada (default 3)
 *   MAX_SEGUNDOS = tiempo máx. de una pasada (default 300 = 5 min)
 */

// Seguridad: solo CLI
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

// Resolver path base (este archivo vive en la raíz del backend, al lado de index.php)
$baseDir = __DIR__;

// Cargar autoload / composer si existe, si no usar loader manual del proyecto
if (file_exists($baseDir . '/vendor/autoload.php')) {
    require $baseDir . '/vendor/autoload.php';
} else {
    // Loader manual mínimo (mismo patrón que index.php)
    spl_autoload_register(function ($class) use ($baseDir) {
        $prefix = 'App\\';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
        $rel  = substr($class, strlen($prefix));
        $file = $baseDir . '/src/' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($file)) require $file;
    });
}

// Cargar .env si Env está disponible
if (class_exists('\App\Core\Env')) {
    \App\Core\Env::load($baseDir . '/.env');
}

use App\Core\Database;
use App\Controllers\BroadcastsController;

$inicio = microtime(true);
echo '[' . date('Y-m-d H:i:s') . "] cron_broadcasts: start\n";

try {
    $pdo = Database::connect();

    $maxEnvios  = (int)(getenv('MAX_ENVIOS')   ?: 3);
    $maxSegundos = (int)(getenv('MAX_SEGUNDOS') ?: 300);

    $res = BroadcastsController::processPending($pdo, $maxEnvios, $maxSegundos);

    $elapsed = round(microtime(true) - $inicio, 2);
    echo '[' . date('Y-m-d H:i:s') . '] cron_broadcasts: done. ' . json_encode($res, JSON_UNESCAPED_UNICODE) . " elapsed={$elapsed}s\n";
    exit(0);
} catch (\Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] cron_broadcasts: ERROR: ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
