<?php
// Recree la base de recette locale : schema de production + donnees de demo.
// Usage : php tools/reset_recette.php [migrations...]
$config = require __DIR__ . '/../db-config.php';
$pdo = new PDO("mysql:host={$config['host']};charset=utf8mb4", $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `{$config['name']}`");
$pdo->exec("CREATE DATABASE `{$config['name']}`");
$files = array_merge([__DIR__ . '/../sql/000_schema_production_2026-09-25.sql', __DIR__ . '/../sql/900_seed_recette_demo.sql'], array_slice($argv, 1));
foreach ($files as $f) {
    passthru('php ' . escapeshellarg(__DIR__ . '/run_sql.php') . ' ' . escapeshellarg($f), $rc);
    if ($rc) { exit($rc); }
}
