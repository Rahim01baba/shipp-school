<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require __DIR__ . '/auth-lib.php';
$authUser = require_auth();

$config = @include __DIR__ . '/../db-config.php';
if (!is_array($config) || empty($config['pass'])) {
http_response_code(500);
echo json_encode(['message' => 'Configuration base de donnees manquante']);
exit;
}
try {
$pdo = new PDO(
"mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4",
$config['user'],
$config['pass'],
[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
} catch (Throwable $e) {
http_response_code(500);
echo json_encode(['message' => 'Connexion base de donnees impossible']);
exit;
}

$adminCheck = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
$adminCheck->execute([$authUser['sub']]);
$adminUser = $adminCheck->fetch(PDO::FETCH_ASSOC);
if (!$adminUser || !$adminUser['is_admin']) {
http_response_code(403);
echo json_encode(['message' => 'Acces reserve aux administrateurs']);
exit;
}

$stmt = $pdo->query('SELECT id, module_key, module_label, sort_order FROM permissions ORDER BY sort_order');
echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
