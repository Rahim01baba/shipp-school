<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$config = @include __DIR__ . '/../db-config.php';
$dbOk = false;
$dbError = null;
if (is_array($config) && !empty($config['pass'])) {
try {
$pdo = new PDO(
"mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4",
$config['user'],
$config['pass']
);
$pdo->query('SELECT 1');
$dbOk = true;
} catch (Throwable $e) {
$dbError = 'connexion impossible';
}
} else {
$dbError = 'db-config.php manquant ou mot de passe non renseigne';
}

echo json_encode([
'status' => 'ok',
'db' => $dbOk,
'db_error' => $dbError,
'time' => date('c'),
]);
