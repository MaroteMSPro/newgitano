<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$_SERVER['HTTP_HOST'] = 'elgitano.luxom.com.ar';

spl_autoload_register(function (string $class) {
    $prefix = "App\\";
    $baseDir = __DIR__ . "/src/";
    if (!str_starts_with($class, $prefix)) return;
    $file = $baseDir . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
    if (file_exists($file)) require $file;
});
App\Core\Env::load(__DIR__ . "/.env");
App\Core\License::boot();
$pdo = App\Core\Database::connect();
$inst = $pdo->query("SELECT * FROM instancias WHERE id=22")->fetch(\PDO::FETCH_ASSOC);
$evo = new App\Services\EvolutionService($inst["nombre"]);
$response = $evo->obtenerGrupos(false);
$groups = $response["data"];
echo "Grupos: " . count($groups) . "\n";

$gi = 0;
$selG = $pdo->prepare('SELECT id FROM contactos WHERE instancia_id = 22 AND numero = ? AND tipo = "grupo"');
$insG = $pdo->prepare('INSERT INTO contactos (instancia_id, tipo, numero, nombre, origen, activo, created_at) VALUES (22, "grupo", ?, ?, "sync", 1, NOW())');

foreach ($groups as $g) {
    $jid = $g["id"] ?? "";
    if (strpos($jid, "@g.us") === false) continue;
    $nombre = $g["subject"] ?? $jid;
    $numero = str_replace("@g.us", "", $jid);
    $selG->execute([$numero]);
    if (!$selG->fetch()) {
        $insG->execute([$numero, $nombre]);
        $gi++;
    }
    $selG->closeCursor();
}
echo "Importados: $gi\n";
$tg = $pdo->query('SELECT COUNT(*) FROM contactos WHERE tipo="grupo" AND instancia_id=22')->fetchColumn();
echo "Total grupos DB: $tg\n";
