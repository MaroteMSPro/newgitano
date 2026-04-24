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
$response = $evo->obtenerGrupos(true);
$groups = $response["data"] ?? $response;
echo "Grupos: " . count($groups) . "\n";

$gi = 0; $pi = 0;
$selG = $pdo->prepare('SELECT id FROM contactos WHERE instancia_id = ? AND numero = ? AND tipo = "grupo"');
$insG = $pdo->prepare('INSERT INTO contactos (instancia_id, tipo, numero, nombre, origen, activo, created_at) VALUES (?, "grupo", ?, ?, "sync", 1, NOW())');
$updG = $pdo->prepare('UPDATE contactos SET nombre = ?, updated_at = NOW() WHERE id = ?');
$insP = $pdo->prepare('INSERT INTO grupo_participantes (contacto_id, numero, nombre, es_admin, created_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), es_admin = VALUES(es_admin)');

foreach ($groups as $g) {
    $jid = $g["id"] ?? "";
    if (strpos($jid, "@g.us") === false) continue;
    $nombre = $g["subject"] ?? $jid;
    $numero = str_replace("@g.us", "", $jid);
    
    $selG->execute([22, $numero]);
    $ex = $selG->fetch();
    if ($ex) { $gid = $ex["id"]; $updG->execute([$nombre, $gid]); }
    else { $insG->execute([22, $numero, $nombre]); $gid = $pdo->lastInsertId(); $gi++; }
    
    foreach ($g["participants"] ?? [] as $p) {
        $pJid = $p["id"] ?? "";
        $pNum = preg_replace("/@.*/", "", $pJid);
        $pNum = preg_replace("/[^0-9]/", "", $pNum);
        if (strlen($pNum) < 10 || strlen($pNum) > 15) continue;
        $esAdm = (($p["admin"] ?? "") === "admin" || ($p["admin"] ?? "") === "superadmin") ? 1 : 0;
        $insP->execute([$gid, $pNum, $pNum, $esAdm]);
        $pi++;
    }
}
echo "Grupos importados: $gi\nParticipantes: $pi\n";
$tg = $pdo->query('SELECT COUNT(*) FROM contactos WHERE tipo="grupo" AND instancia_id=22')->fetchColumn();
$tp = $pdo->query('SELECT COUNT(*) FROM grupo_participantes')->fetchColumn();
echo "Total grupos DB: $tg\nTotal participantes DB: $tp\n";
