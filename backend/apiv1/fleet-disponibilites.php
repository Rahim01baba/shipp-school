<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
http_response_code(405);
echo json_encode(['message' => 'Methode non autorisee']);
exit;
}

// --- Droit sur le module affectations_chauffeur (roles + scope) ---
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
authz_require($ctx, 'affectations_chauffeur', 'can_read');
// Ecran d'exploitation : reserve aux perimetres ecole et global.
if (!in_array(authz_scope($ctx, 'affectations_chauffeur'), ['GLOBAL', 'SCHOOL'], true)) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse pour ce module']);
exit;
}
// --- Fin verification ---

$ecoleRow = $pdo->query('SELECT id FROM ecoles ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$ecoleId = $ecoleRow ? (int) $ecoleRow['id'] : null;

if (!$ecoleId) {
echo json_encode(['chauffeurs' => [], 'vehicules' => []]);
exit;
}

// Chauffeurs = comptes actifs ayant le role 'chauffeur'. Tant qu'aucun role
// chauffeur n'est attribue, on garde l'ancienne regle (lecteurs des affectations).
$tousChauffeurs = [];
if (authz_table_exists($pdo, 'user_roles')) {
$chStmt = $pdo->prepare(
"SELECT DISTINCT u.id, u.name, u.email
FROM users u
JOIN user_roles ur ON ur.user_id = u.id
JOIN roles r ON r.id = ur.role_id AND r.role_key = 'chauffeur'
WHERE u.ecole_id = ? AND u.status = 'active'
ORDER BY u.name ASC"
);
$chStmt->execute([$ecoleId]);
$tousChauffeurs = $chStmt->fetchAll(PDO::FETCH_ASSOC);
}
if (!$tousChauffeurs) {
$chStmt = $pdo->prepare(
"SELECT u.id, u.name, u.email
FROM users u
JOIN user_permissions up ON up.user_id = u.id
JOIN permissions p ON p.id = up.permission_id AND p.module_key = 'affectations_chauffeur'
WHERE u.ecole_id = ? AND up.can_read = 1
ORDER BY u.name ASC"
);
$chStmt->execute([$ecoleId]);
$tousChauffeurs = $chStmt->fetchAll(PDO::FETCH_ASSOC);
}

$titStmt = $pdo->prepare(
'SELECT ac.user_id, ac.circuit_id, c.nom AS circuit_nom
FROM affectations_chauffeur ac
JOIN circuits c ON c.id = ac.circuit_id
WHERE ac.ecole_id = ?'
);
$titStmt->execute([$ecoleId]);
$occupationTitulaire = [];
foreach ($titStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
$occupationTitulaire[(int) $row['user_id']] = $row;
}

$covStmt = $pdo->prepare(
"SELECT cc.chauffeur_remplacant_id, cc.circuit_id, c.nom AS circuit_nom
FROM couvertures_chauffeur cc
JOIN circuits c ON c.id = cc.circuit_id
WHERE cc.ecole_id = ? AND cc.date_debut <= CURDATE() AND (cc.date_fin IS NULL OR cc.date_fin >= CURDATE())"
);
$covStmt->execute([$ecoleId]);
$occupationCouverture = [];
foreach ($covStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
$occupationCouverture[(int) $row['chauffeur_remplacant_id']] = $row;
}

$chauffeurs = [];
foreach ($tousChauffeurs as $c) {
$uid = (int) $c['id'];
if (isset($occupationTitulaire[$uid])) {
$chauffeurs[] = array_merge($c, ['disponible' => false, 'occupation' => 'titulaire', 'circuit' => $occupationTitulaire[$uid]['circuit_nom']]);
} elseif (isset($occupationCouverture[$uid])) {
$chauffeurs[] = array_merge($c, ['disponible' => false, 'occupation' => 'couverture', 'circuit' => $occupationCouverture[$uid]['circuit_nom']]);
} else {
$chauffeurs[] = array_merge($c, ['disponible' => true, 'occupation' => null, 'circuit' => null]);
}
}

$vehStmt = $pdo->prepare('SELECT id, immatriculation, modele, statut FROM vehicules WHERE ecole_id = ? ORDER BY immatriculation ASC');
$vehStmt->execute([$ecoleId]);
$tousVehicules = $vehStmt->fetchAll(PDO::FETCH_ASSOC);

$circVehStmt = $pdo->prepare('SELECT id, nom, vehicule_id FROM circuits WHERE ecole_id = ? AND vehicule_id IS NOT NULL');
$circVehStmt->execute([$ecoleId]);
$occupationVehicule = [];
foreach ($circVehStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
$occupationVehicule[(int) $row['vehicule_id']] = $row['nom'];
}

$vehicules = [];
foreach ($tousVehicules as $v) {
$vid = (int) $v['id'];
$occupeSur = $occupationVehicule[$vid] ?? null;
$vehicules[] = array_merge($v, [
'disponible' => $v['statut'] === 'actif' && $occupeSur === null,
'circuit' => $occupeSur,
]);
}

echo json_encode(['chauffeurs' => $chauffeurs, 'vehicules' => $vehicules]);
