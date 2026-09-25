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

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$userId = (int) ($input['user_id'] ?? 0);
$permissions = $input['permissions'] ?? [];

$roleId = isset($input['role_id']) ? (int) $input['role_id'] : null;
if ($roleId) {
$roleUpsert = $pdo->prepare(
'INSERT INTO role_permissions (role_id, permission_id, can_read, can_create, can_edit, can_delete)
VALUES (?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE can_read = VALUES(can_read), can_create = VALUES(can_create), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)'
);
foreach ($permissions as $perm) {
$roleUpsert->execute([
$roleId,
(int) $perm['permission_id'],
!empty($perm['can_read']) ? 1 : 0,
!empty($perm['can_create']) ? 1 : 0,
!empty($perm['can_edit']) ? 1 : 0,
!empty($perm['can_delete']) ? 1 : 0,
]);
}
echo json_encode(['message' => 'ok']);
exit;
}

if (!$userId) {
http_response_code(400);
echo json_encode(['message' => 'user_id manquant']);
exit;
}

$check = $pdo->prepare('SELECT id FROM users WHERE id = ?');
$check->execute([$userId]);
if (!$check->fetch()) {
http_response_code(404);
echo json_encode(['message' => 'Utilisateur introuvable']);
exit;
}

$upsert = $pdo->prepare(
'INSERT INTO user_permissions (user_id, permission_id, can_read, can_create, can_edit, can_delete)
VALUES (?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE can_read = VALUES(can_read), can_create = VALUES(can_create), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)'
);

foreach ($permissions as $perm) {
$upsert->execute([
$userId,
(int) $perm['permission_id'],
!empty($perm['can_read']) ? 1 : 0,
!empty($perm['can_create']) ? 1 : 0,
!empty($perm['can_edit']) ? 1 : 0,
!empty($perm['can_delete']) ? 1 : 0,
]);
}

echo json_encode(['message' => 'ok']);
