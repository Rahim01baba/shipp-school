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
echo json_encode(['data' => [], 'kpis' => ['circuits' => 0, 'circuits_couverts' => 0, 'chauffeurs_affectes' => 0, 'vehicules_actifs' => 0, 'alertes' => 0]]);
exit;
}

$circuits = $pdo->prepare('SELECT id, nom, description, vehicule_id, statut FROM circuits WHERE ecole_id = ? ORDER BY nom ASC');
$circuits->execute([$ecoleId]);
$circuits = $circuits->fetchAll(PDO::FETCH_ASSOC);

$titStmt = $pdo->prepare(
'SELECT ac.circuit_id, ac.user_id, u.name AS chauffeur_nom
FROM affectations_chauffeur ac
JOIN users u ON u.id = ac.user_id
WHERE ac.ecole_id = ?'
);
$titStmt->execute([$ecoleId]);
$titulairesParCircuit = [];
foreach ($titStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
$titulairesParCircuit[(int) $row['circuit_id']] = $row;
}

$vehStmt = $pdo->prepare('SELECT id, immatriculation, modele, statut FROM vehicules WHERE ecole_id = ?');
$vehStmt->execute([$ecoleId]);
$vehiculesParId = [];
foreach ($vehStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
$vehiculesParId[(int) $row['id']] = $row;
}

$trajStmt = $pdo->prepare(
"SELECT id, circuit_id, statut, heure_debut, heure_fin FROM trajets WHERE ecole_id = ? AND date_trajet = CURDATE()"
);
$trajStmt->execute([$ecoleId]);
$trajetsParCircuit = [];
foreach ($trajStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
$trajetsParCircuit[(int) $row['circuit_id']] = $row;
}

$covStmt = $pdo->prepare(
"SELECT cc.id, cc.circuit_id, cc.eleve_id, cc.date_debut, cc.date_fin, cc.motif, cc.chauffeur_remplacant_id, u.name AS remplacant_nom
FROM couvertures_chauffeur cc
JOIN users u ON u.id = cc.chauffeur_remplacant_id
WHERE cc.ecole_id = ? AND cc.date_debut <= CURDATE() AND (cc.date_fin IS NULL OR cc.date_fin >= CURDATE())
ORDER BY cc.date_debut ASC"
);
$covStmt->execute([$ecoleId]);
$couverturesParCircuit = [];
foreach ($covStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
$cid = (int) $row['circuit_id'];
if (!isset($couverturesParCircuit[$cid])) {
$couverturesParCircuit[$cid] = [];
}
$couverturesParCircuit[$cid][] = $row;
}

$data = [];
$circuitsCouverts = 0;
$alertes = 0;
foreach ($circuits as $c) {
$cid = (int) $c['id'];
$titulaire = $titulairesParCircuit[$cid] ?? null;
$vehiculeId = $c['vehicule_id'] ? (int) $c['vehicule_id'] : null;
$vehicule = $vehiculeId && isset($vehiculesParId[$vehiculeId]) ? $vehiculesParId[$vehiculeId] : null;
$trajet = $trajetsParCircuit[$cid] ?? null;
$couvertures = $couverturesParCircuit[$cid] ?? [];

$estActif = $c['statut'] === 'actif';
$aChauffeur = $titulaire !== null || count($couvertures) > 0;
$aVehicule = $vehicule !== null;

if ($estActif && $aChauffeur) {
$circuitsCouverts++;
}
if ($estActif && !$aChauffeur) {
$alertes++;
}
if ($estActif && !$aVehicule) {
$alertes++;
}
if ($vehicule && $vehicule['statut'] !== 'actif') {
$alertes++;
}

$data[] = [
'id' => $cid,
'nom' => $c['nom'],
'description' => $c['description'],
'statut' => $c['statut'],
'titulaire' => $titulaire,
'vehicule' => $vehicule ? array_merge(['id' => $vehiculeId], $vehicule) : null,
'trajet_du_jour' => $trajet,
'couvertures_actives' => $couvertures,
];
}

$chauffeursAffectes = count($titulairesParCircuit);
$vehiculesActifs = count(array_filter($vehiculesParId, function ($v) { return $v['statut'] === 'actif'; }));

echo json_encode([
'data' => $data,
'kpis' => [
'circuits' => count($circuits),
'circuits_couverts' => $circuitsCouverts,
'chauffeurs_affectes' => $chauffeursAffectes,
'vehicules_actifs' => $vehiculesActifs,
'alertes' => $alertes,
],
]);
