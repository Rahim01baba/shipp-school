<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require __DIR__ . '/auth-lib.php';
$payload = require_auth();

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

$stmt = $pdo->prepare('SELECT id, is_admin FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$payload['sub']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
http_response_code(401);
echo json_encode(['message' => 'Utilisateur introuvable']);
exit;
}

// Droits effectifs : roles + derogations individuelles + scope (lib/authz.php).
// Un module hors du perimetre de l'utilisateur est renvoye sans aucun droit.
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $user['id']);
$perms = authz_effective_permissions($pdo, $ctx);

$plStmt = $pdo->prepare('SELECT eleve_id FROM parent_liaisons WHERE user_id = ?');
$plStmt->execute([$user['id']]);
$parentEleveIds = array_map('intval', array_column($plStmt->fetchAll(PDO::FETCH_ASSOC), 'eleve_id'));

$acStmt = $pdo->prepare('SELECT circuit_id FROM affectations_chauffeur WHERE user_id = ?');
$acStmt->execute([$user['id']]);
$chauffeurCircuitIds = array_map('intval', array_column($acStmt->fetchAll(PDO::FETCH_ASSOC), 'circuit_id'));

echo json_encode([
'is_admin' => (bool) $user['is_admin'],
'roles' => $ctx['roles'],
'scope' => $ctx['base_scope'],
'chauffeur_id' => authz_table_exists($pdo, 'chauffeurs') ? authz_my_chauffeur_id($pdo, $ctx) : null,
'permissions' => $perms,
'parent_eleve_ids' => $parentEleveIds,
'chauffeur_circuit_ids' => $chauffeurCircuitIds,
]);
