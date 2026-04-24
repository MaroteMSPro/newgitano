<?php
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
$inst = $pdo->query("SELECT * FROM instancias WHERE id=22")->fetch();
$evo = new App\Services\EvolutionService($inst["nombre"]);

// Sin participantes primero
$response = $evo->obtenerGrupos(false);
$groups = $response["data"] ?? $response;
echo "Grupos sin participantes: " . count($groups) . "\n";

$gi = 0;
$selG = $pdo->prepare('SELECT id FROM contactos WHERE instancia_id = ? AND numero = ? AND tipo = "grupo"');
$insG = $pdo->prepare('INSERT INTO contactos (instancia_id, tipo, numero, nombre, origen, activo, created_at) VALUES (?, "grupo", ?, ?, "sync", 1, NOW())');

foreach ($groups as $g) {
    $jid = $g["id"] ?? "";
    if (strpos($jid, "@g.us") === false) continue;
    $nombre = $g["subject"] ?? $jid;
    $numero = str_replace("@g.us", "", $jid);
    
    $selG->execute([22, $numero]);
    if (!$selG->fetch()) {
        $insG->execute([22, $numero, $nombre]);
        $gi++;
    }
}
echo "Grupos importados: $gi\n";
$tg = $pdo->query('SELECT COUNT(*) FROM contactos WHERE tipo="grupo" AND instancia_id=22')->fetchColumn();
echo "Total grupos DB: $tg\n";
