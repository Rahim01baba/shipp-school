<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require __DIR__ . '/auth-lib.php';

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

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = trim($input['email'] ?? '');
$password = (string) ($input['password'] ?? '');

if (!$email || !$password) {
http_response_code(400);
echo json_encode(['message' => 'Identifiant et mot de passe requis']);
exit;
}

// Identifiant = e-mail, ou numero de telephone (chauffeurs sans e-mail).
if (strpos($email, '@') === false) {
$telephone = preg_replace('/[^0-9+]/', '', $email);
$stmt = $pdo->prepare('SELECT id, name, email, status, password_hash FROM users WHERE telephone = ? LIMIT 1');
$stmt->execute([$telephone]);
} else {
$stmt = $pdo->prepare('SELECT id, name, email, status, password_hash FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
}
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
http_response_code(401);
echo json_encode(['message' => 'Identifiants incorrects']);
exit;
}

if ($user['status'] !== 'active') {
http_response_code(403);
echo json_encode(['message' => 'Compte inactif']);
exit;
}

$payload = [
'sub' => (int) $user['id'],
'email' => $user['email'],
'name' => $user['name'],
'iat' => time(),
'exp' => time() + 60 * 60 * 24 * 7,
];

$token = jwt_encode($payload);

try {
$pdo->prepare("INSERT INTO journal_activite (ecole_id, user_id, action, module_key, record_id, details) VALUES (NULL, ?, 'login', 'auth', ?, NULL)")
->execute([(int) $user['id'], (int) $user['id']]);
} catch (Throwable $e) {
// Journal best-effort.
}

echo json_encode([
'token' => $token,
'user' => [
'id' => (int) $user['id'],
'name' => $user['name'],
'email' => $user['email'],
],
]);
