<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

// --- Droit d'edition sur le module eleves (roles + scope) ---
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
authz_require($ctx, 'eleves', 'can_edit');
// --- Fin verification ---

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
http_response_code(405);
echo json_encode(['message' => 'Methode non autorisee']);
exit;
}

$eleveId = (int) ($_POST['eleve_id'] ?? 0);
if (!$eleveId) {
http_response_code(400);
echo json_encode(['message' => 'eleve_id requis']);
exit;
}

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
http_response_code(400);
echo json_encode(['message' => 'Fichier photo manquant ou invalide']);
exit;
}

$file = $_FILES['photo'];
$maxSize = 3 * 1024 * 1024;
if ($file['size'] > $maxSize) {
http_response_code(400);
echo json_encode(['message' => 'Fichier trop volumineux (3 Mo max)']);
exit;
}

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!isset($allowed[$mime])) {
http_response_code(400);
echo json_encode(['message' => 'Format non supporte (jpg, png, webp uniquement)']);
exit;
}
$ext = $allowed[$mime];

$eleveCheck = $pdo->prepare('SELECT id FROM eleves WHERE id = ?');
$eleveCheck->execute([$eleveId]);
if (!$eleveCheck->fetch() || !authz_row_allowed($pdo, $ctx, 'eleves', $eleveId)) {
http_response_code(404);
echo json_encode(['message' => 'Eleve introuvable']);
exit;
}

$uploadDir = __DIR__ . '/uploads/eleves';
if (!is_dir($uploadDir)) {
mkdir($uploadDir, 0755, true);
}

$filename = 'eleve-' . $eleveId . '-' . time() . '.' . $ext;
$destPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
http_response_code(500);
echo json_encode(['message' => "Echec de l'enregistrement du fichier"]);
exit;
}

$relativePath = 'uploads/eleves/' . $filename;
$update = $pdo->prepare('UPDATE eleves SET photo = ? WHERE id = ?');
$update->execute([$relativePath, $eleveId]);

echo json_encode(['message' => 'ok', 'photo' => $relativePath]);
