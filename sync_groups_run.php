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
$stmt = $pdo->prepare("SELECT * FROM instancias WHERE id = ?");
$stmt->execute([22]);
$inst = $stmt->fetch();

$evo = new App\Services\EvolutionService($inst["nombre"]);
$response = $evo->obtenerGrupos(true);
$rawGroups = $response["data"] ?? $response;
echo "Total grupos Evolution: " . count($rawGroups) . "\n";

$gi = 0; $pi = 0; $err = 0;
$selG = $pdo->prepare("SELECT id FROM contactos WHERE instancia_id = ? AND numero = ? AND tipo = \"grupo\"");
$insG = $pdo->prepare("INSERT INTO contactos (instancia_id, tipo, numero, nombre, origen, activo, created_at) VALUES (?, \"grupo\", ?, ?, \"sync\", 1, NOW())");
$updG = $pdo->prepare("UPDATE contactos SET nombre = ?, updated_at = NOW() WHERE id = ?");
$insP = $pdo->prepare("INSERT INTO grupo_participantes (contacto_id, numero, nombre, es_admin, created_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), es_admin = VALUES(es_admin)");

foreach ($rawGroups as $g) {
    try {
        $jid = $g["id"] ?? $g["jid"] ?? "";
        if (strpos($jid, "@g.us") === false) continue;
        $nombre = $g["subject"] ?? $g["name"] ?? $jid;
        $numero = str_replace("@g.us", "", $jid);
        
        $selG->execute([22, $numero]);
        $ex = $selG->fetch();
        if ($ex) { $grupoId = $ex["id"]; $updG->execute([$nombre, $grupoId]); }
        else { $insG->execute([22, $numero, $nombre]); $grupoId = $pdo->lastInsertId(); $gi++; }
        
        $parts = $g["participants"] ?? [];
        foreach ($parts as $p) {
            $pJid = $p["id"] ?? "";
            $pNum = str_replace("@s.whatsapp.net", "", $pJid);
            $pNum = preg_replace("/[^0-9]/", "", $pNum);
            if (strlen($pNum) < 10 || strlen($pNum) > 15) continue;
            $pNom = $p["name"] ?? $p["pushName"] ?? $pNum;
            $esAdm = ($p["admin"] === "admin" || $p["admin"] === "superadmin") ? 1 : 0;
            $insP->execute([$grupoId, $pNum, $pNom, $esAdm]);
            $pi++;
        }
    } catch (\Throwable $e) { $err++; echo "Error: ".$e->getMessage()."\n"; }
}
echo "Grupos importados: $gi\nParticipantes importados: $pi\nErrores: $err\n";
$totalG = $pdo->query("SELECT COUNT(*) FROM contactos WHERE tipo=\"grupo\" AND instancia_id=22")->fetchColumn();
$totalP = $pdo->query("SELECT COUNT(*) FROM grupo_participantes")->fetchColumn();
echo "Total grupos en DB: $totalG\nTotal participantes en DB: $totalP\n";
