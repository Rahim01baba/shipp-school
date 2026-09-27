<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
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

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
$hasTel = false;
try { $pdo->query('SELECT telephone FROM users LIMIT 0'); $hasTel = true; } catch (Throwable $e) {}
$stmt = $pdo->query('SELECT id, name, email, ' . ($hasTel ? 'telephone, ' : '') . 'status, created_at FROM users ORDER BY name');
echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
exit;
}

if ($method === 'POST') {
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$telephone = preg_replace('/[^0-9+]/', '', (string) ($input['telephone'] ?? ''));
$password = (string) ($input['password'] ?? '');
$roleKey = trim((string) ($input['role_key'] ?? ''));

// Nom obligatoire ; e-mail OU telephone obligatoire (chauffeurs sans e-mail).
if ($name === '' || ($email === '' && $telephone === '') || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
http_response_code(400);
echo json_encode(['message' => 'Nom et e-mail ou telephone valides requis']);
exit;
}
if ($password !== '' && strlen($password) < 8) {
http_response_code(400);
echo json_encode(['message' => 'Le mot de passe doit contenir au moins 8 caracteres']);
exit;
}

if ($email !== '') {
$check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$check->execute([$email]);
if ($check->fetch()) {
http_response_code(409);
echo json_encode(['message' => 'Cet email existe deja']);
exit;
}
}
if ($telephone !== '') {
$check = $pdo->prepare('SELECT id FROM users WHERE telephone = ?');
$check->execute([$telephone]);
if ($check->fetch()) {
http_response_code(409);
echo json_encode(['message' => 'Ce telephone existe deja']);
exit;
}
}

$ecoleRow = $pdo->query('SELECT id FROM ecoles ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$ecoleId = $ecoleRow ? (int) $ecoleRow['id'] : null;
$hash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null;
$pdo->prepare('INSERT INTO users (name, email, telephone, password_hash, ecole_id) VALUES (?, ?, ?, ?, ?)')
->execute([$name, $email !== '' ? $email : null, $telephone !== '' ? $telephone : null, $hash, $ecoleId]);
$id = (int) $pdo->lastInsertId();

$permIds = $pdo->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
$seed = $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id, can_read, can_create, can_edit) VALUES (?, ?, 0, 0, 0)');
foreach ($permIds as $permId) {
$seed->execute([$id, $permId]);
}

if ($roleKey !== '') {
$r = $pdo->prepare('SELECT id FROM roles WHERE role_key = ?');
$r->execute([$roleKey]);
$roleId = $r->fetchColumn();
if ($roleId) {
$pdo->prepare('INSERT INTO user_roles (user_id, role_id, ecole_id) VALUES (?, ?, ?)')->execute([$id, $roleId, $ecoleId]);
}
}

echo json_encode(['data' => ['id' => $id, 'name' => $name, 'email' => $email, 'telephone' => $telephone, 'a_mot_de_passe' => $hash !== null]]);
exit;
}

if ($method === 'PUT') {
// Reinitialisation du mot de passe par un administrateur.
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int) ($input['id'] ?? 0);
$password = (string) ($input['password'] ?? '');
if (!$id || strlen($password) < 8) {
http_response_code(400);
echo json_encode(['message' => 'id et mot de passe (8 caracteres minimum) requis']);
exit;
}
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
echo json_encode(['message' => 'ok']);
exit;
}

http_response_code(405);
echo json_encode(['message' => 'Methode non supportee']);
