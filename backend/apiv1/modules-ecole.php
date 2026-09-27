<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
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

$method = $_SERVER['REQUEST_METHOD'];
$requiredKey = $method === 'GET' ? 'can_read' : ($method === 'PUT' ? 'can_edit' : null);
if ($requiredKey === null) {
http_response_code(405);
echo json_encode(['message' => 'Methode non autorisee']);
exit;
}

// --- Droit sur le module ecole_modules (roles + scope) ---
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
authz_require($ctx, 'ecole_modules', $requiredKey);
if (!in_array(authz_scope($ctx, 'ecole_modules'), ['GLOBAL', 'SCHOOL'], true)) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse pour ce module']);
exit;
}
// --- Fin verification ---

$ecoleRow = $pdo->query('SELECT id FROM ecoles ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$ecoleId = $ecoleRow ? (int) $ecoleRow['id'] : null;

if ($method === 'GET') {
$stmt = $pdo->prepare(
'SELECT p.module_key, p.module_label,
COALESCE(em.actif, 1) AS actif
FROM permissions p
LEFT JOIN ecole_modules em ON em.module_key = p.module_key AND em.ecole_id = ?
ORDER BY p.sort_order ASC'
);
$stmt->execute([$ecoleId]);
echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
exit;
}

if ($method === 'PUT') {
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$moduleKey = trim($input['module_key'] ?? '');
$actif = !empty($input['actif']) ? 1 : 0;

if ($moduleKey === '' || !$ecoleId) {
http_response_code(400);
echo json_encode(['message' => 'module_key requis']);
exit;
}

$stmt = $pdo->prepare(
'INSERT INTO ecole_modules (ecole_id, module_key, actif) VALUES (?, ?, ?)
ON DUPLICATE KEY UPDATE actif = VALUES(actif)'
);
$stmt->execute([$ecoleId, $moduleKey, $actif]);

try {
$logStmt = $pdo->prepare(
'INSERT INTO journal_activite (ecole_id, user_id, action, module_key, record_id, details) VALUES (?, ?, ?, ?, ?, ?)'
);
$logStmt->execute([$ecoleId, $authUser['sub'], 'update', 'ecole_modules', null, $moduleKey . ' -> ' . ($actif ? 'actif' : 'inactif')]);
} catch (Throwable $e) {
// Journal best-effort : on ignore silencieusement l'echec.
}

echo json_encode(['message' => 'ok']);
exit;
}
