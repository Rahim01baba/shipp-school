<?php
// Execute un fichier SQL instruction par instruction (recette locale).
// Usage : php tools/run_sql.php fichier.sql
$config = require __DIR__ . '/../db-config.php';
$pdo = new PDO("mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4", $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql = file_get_contents($argv[1]);
$sql = preg_replace('/^--.*$/m', '', $sql);
$n = 0;
foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;
    try { $pdo->exec($stmt); $n++; }
    catch (Throwable $e) { fwrite(STDERR, "ERREUR: " . $e->getMessage() . "\n  >> " . substr($stmt, 0, 200) . "\n"); exit(1); }
}
echo "$n instructions executees\n";
