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

// --- Droit sur le module affectations_chauffeur (roles + scope) ---
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
authz_require($ctx, 'affectations_chauffeur', 'can_edit');
// Ecran d'exploitation : reserve aux perimetres ecole et global.
if (!in_array(authz_scope($ctx, 'affectations_chauffeur'), ['GLOBAL', 'SCHOOL'], true)) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse pour ce module']);
exit;
}
// --- Fin verification ---

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
http_response_code(405);
echo json_encode(['message' => 'Methode non autorisee']);
exit;
}

$ecoleRow = $pdo->query('SELECT id FROM ecoles ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$ecoleId = $ecoleRow ? (int) $ecoleRow['id'] : null;

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$type = $input['type'] ?? '';
$circuitId = isset($input['circuit_id']) ? (int) $input['circuit_id'] : 0;
$nouveauId = isset($input['nouveau_id']) ? (int) $input['nouveau_id'] : 0;

if (!in_array($type, ['chauffeur', 'vehicule'], true) || !$circuitId || !$nouveauId || !$ecoleId) {
http_response_code(400);
echo json_encode(['message' => 'Parametres invalides']);
exit;
}

$circStmt = $pdo->prepare('SELECT id, nom, vehicule_id FROM circuits WHERE id = ? AND ecole_id = ?');
$circStmt->execute([$circuitId, $ecoleId]);
$circuit = $circStmt->fetch(PDO::FETCH_ASSOC);
if (!$circuit) {
http_response_code(404);
echo json_encode(['message' => 'Circuit introuvable']);
exit;
}

if ($type === 'chauffeur') {
$ancienStmt = $pdo->prepare(
'SELECT ac.user_id, u.name FROM affectations_chauffeur ac JOIN users u ON u.id = ac.user_id WHERE ac.circuit_id = ?'
);
$ancienStmt->execute([$circuitId]);
$ancien = $ancienStmt->fetch(PDO::FETCH_ASSOC);

$nouveauStmt = $pdo->prepare('SELECT name FROM users WHERE id = ? AND ecole_id = ?');
$nouveauStmt->execute([$nouveauId, $ecoleId]);
$nouveau = $nouveauStmt->fetch(PDO::FETCH_ASSOC);
if (!$nouveau) {
http_response_code(404);
echo json_encode(['message' => 'Chauffeur introuvable']);
exit;
}

$upsert = $pdo->prepare(
'INSERT INTO affectations_chauffeur (ecole_id, user_id, circuit_id) VALUES (?, ?, ?)
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
);
$upsert->execute([$ecoleId, $nouveauId, $circuitId]);

$ancienLabel = $ancien ? $ancien['name'] : 'aucun';
$detail = 'Circuit ' . $circuit['nom'] . ' : chauffeur ' . $ancienLabel . ' -> ' . $nouveau['name'];
$moduleKey = 'affectations_chauffeur';
} else {
$ancienVehiculeId = $circuit['vehicule_id'] ? (int) $circuit['vehicule_id'] : null;
$ancienLabel = 'aucun';
if ($ancienVehiculeId) {
$av = $pdo->prepare('SELECT immatriculation FROM vehicules WHERE id = ?');
$av->execute([$ancienVehiculeId]);
$avRow = $av->fetch(PDO::FETCH_ASSOC);
if ($avRow) {
$ancienLabel = $avRow['immatriculation'];
}
}

$nvStmt = $pdo->prepare('SELECT immatriculation FROM vehicules WHERE id = ? AND ecole_id = ?');
$nvStmt->execute([$nouveauId, $ecoleId]);
$nouveauVehicule = $nvStmt->fetch(PDO::FETCH_ASSOC);
if (!$nouveauVehicule) {
http_response_code(404);
echo json_encode(['message' => 'Vehicule introuvable']);
exit;
}

$upd = $pdo->prepare('UPDATE circuits SET vehicule_id = ? WHERE id = ? AND ecole_id = ?');
$upd->execute([$nouveauId, $circuitId, $ecoleId]);

$detail = 'Circuit ' . $circuit['nom'] . ' : vehicule ' . $ancienLabel . ' -> ' . $nouveauVehicule['immatriculation'];
$moduleKey = 'circuits';
}

try {
$logStmt = $pdo->prepare(
'INSERT INTO journal_activite (ecole_id, user_id, action, module_key, record_id, details) VALUES (?, ?, ?, ?, ?, ?)'
);
$logStmt->execute([$ecoleId, $authUser['sub'], 'update', $moduleKey, $circuitId, $detail]);
} catch (Throwable $e) {
// Journal best-effort : on ignore silencieusement l'echec.
}

echo json_encode(['message' => 'ok']);
