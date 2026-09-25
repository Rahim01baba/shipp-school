<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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

require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);

$modulesConfig = [
'eleves' => ['code_dr', 'nom', 'prenom', 'date_naissance', 'classe', 'ecole', 'statut', 'circuit_id'],
'parents_eleves' => ['nom', 'prenom', 'email', 'telephone', 'adresse'],
'transport' => ['eleve_nom', 'circuit', 'point_ramassage', 'statut'],
'cantine' => ['eleve_nom', 'menu', 'date_repas', 'statut'],
'vehicules' => ['immatriculation', 'modele', 'capacite', 'chauffeur', 'statut', 'marque', 'annee', 'type_vehicule', 'proprietaire'],
'circuits' => ['nom', 'description', 'vehicule', 'vehicule_id', 'statut', 'type_circuit', 'activite', 'destination', 'jours_semaine', 'heure_depart', 'heure_retour'],
'finance' => ['libelle', 'montant', 'type', 'date_echeance', 'statut', 'eleve_id', 'mode_paiement', 'date_paiement'],
'notifications' => ['titre', 'message', 'cible', 'statut'],
'ecoles' => ['nom', 'adresse', 'telephone', 'directeur'],
'rapports' => ['titre', 'type', 'periode', 'statut'],
'menus' => ['date_menu', 'periode', 'libelle', 'description'],
'utilisateurs' => ['name', 'email', 'status'],
'abonnements' => ['eleve_id', 'type', 'statut', 'date_debut', 'date_fin', 'zone_tarifaire', 'montant_mensuel', 'periodicite'],
'annees_scolaires' => ['libelle', 'date_debut', 'date_fin', 'statut'],
'etapes' => ['circuit_id', 'nom', 'ordre', 'heure_estimee', 'statut'],
'trajets' => ['circuit_id', 'date_trajet', 'statut'],
'scans' => ['eleve_id', 'type', 'trajet_id', 'etape_id', 'methode'],
'parent_liaisons' => ['user_id', 'eleve_id', 'lien'],
'ecole_modules' => ['module_key', 'actif'],
'journal_activite' => [],
'affectations_chauffeur' => ['user_id', 'circuit_id'],
'couvertures_chauffeur' => ['chauffeur_remplacant_id', 'circuit_id', 'eleve_id', 'date_debut', 'date_fin', 'motif'],
];

$tableMap = [
'eleves' => 'eleves',
'parents_eleves' => 'parents_eleves',
'transport' => 'transport',
'cantine' => 'cantine',
'vehicules' => 'vehicules',
'circuits' => 'circuits',
'finance' => 'finance',
'menus' => 'menus',
'notifications' => 'notifications',
'ecoles' => 'ecoles',
'rapports' => 'rapports',
'utilisateurs' => 'users',
'abonnements' => 'abonnements',
'annees_scolaires' => 'annees_scolaires',
'etapes' => 'etapes',
'trajets' => 'trajets',
'scans' => 'scans',
'parent_liaisons' => 'parent_liaisons',
'ecole_modules' => 'ecole_modules',
'journal_activite' => 'journal_activite',
'affectations_chauffeur' => 'affectations_chauffeur',
'couvertures_chauffeur' => 'couvertures_chauffeur',
];

// Modules dont la table possede une colonne ecole_id / annee_scolaire_id,
// injectee automatiquement cote serveur (jamais fournie par le frontend).
$ecoleScopedModules = ['eleves', 'parents_eleves', 'transport', 'cantine', 'vehicules', 'circuits', 'finance', 'menus', 'notifications', 'rapports', 'utilisateurs', 'abonnements', 'annees_scolaires', 'etapes', 'trajets', 'scans', 'parent_liaisons', 'ecole_modules', 'affectations_chauffeur', 'couvertures_chauffeur'];
$anneeScopedModules = ['eleves', 'transport', 'cantine', 'finance', 'menus', 'abonnements', 'trajets', 'scans'];

function currentEcoleId(PDO $pdo)
{
static $id = null;
if ($id === null) {
$row = $pdo->query('SELECT id FROM ecoles ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$id = $row ? (int) $row['id'] : null;
}
return $id;
}

function currentAnneeScolaireId(PDO $pdo)
{
static $id = null;
if ($id === null) {
$row = $pdo->query("SELECT id FROM annees_scolaires WHERE statut = 'active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$id = $row ? (int) $row['id'] : null;
}
return $id;
}

$module = $_GET['module'] ?? '';
if (!isset($modulesConfig[$module])) {
http_response_code(404);
echo json_encode(['message' => 'Module inconnu']);
exit;
}

$table = $tableMap[$module];
$fields = $modulesConfig[$module];
$method = $_SERVER['REQUEST_METHOD'];

// --- Verification des droits : roles + derogations + scope (lib/authz.php) ---
$requiredMap = ['GET' => 'can_read', 'POST' => 'can_create', 'PUT' => 'can_edit', 'DELETE' => 'can_delete'];
$requiredKey = $requiredMap[$method] ?? null;
if ($requiredKey === null || !authz_can($ctx, $module, $requiredKey)) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse pour ce module']);
exit;
}
// Le journal d'activite est une piste d'audit : jamais modifiable via l'API.
if ($module === 'journal_activite' && $method !== 'GET') {
http_response_code(403);
echo json_encode(['message' => "Le journal d'activite est en lecture seule"]);
exit;
}
// Perimetre des donnees (parent : ses enfants ; chauffeur : ses circuits ; ecole...).
$scopeCond = authz_scope_condition($pdo, $ctx, $module);
if ($scopeCond === null) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse pour ce module']);
exit;
}
// --- Fin verification des droits ---

if ($method === 'GET') {
$where = ['(' . $scopeCond[0] . ')'];
$params = $scopeCond[1];
// Les ecrans operationnels lisent l'annee scolaire active. Un utilisateur au
// perimetre ecole ou global peut consulter une autre annee (?annee_scolaire_id=N)
// ou toutes les annees (?annee_scolaire_id=toutes).
if (in_array($module, $anneeScopedModules, true)) {
$anneeDemandee = $_GET['annee_scolaire_id'] ?? '';
$peutHistorique = in_array(authz_scope($ctx, $module), ['GLOBAL', 'SCHOOL'], true);
if ($anneeDemandee === 'toutes' && $peutHistorique) {
// aucune restriction d'annee
} elseif ($anneeDemandee !== '' && ctype_digit((string) $anneeDemandee) && $peutHistorique) {
$where[] = 'annee_scolaire_id = ?';
$params[] = (int) $anneeDemandee;
} else {
$anneeActive = currentAnneeScolaireId($pdo);
if ($anneeActive) {
$where[] = '(annee_scolaire_id = ? OR annee_scolaire_id IS NULL)';
$params[] = $anneeActive;
}
}
}
$columns = $module === 'utilisateurs'
// Ne jamais exposer password_hash, meme aux administrateurs.
? 'id, ecole_id, name, email, status, is_admin, created_at, updated_at'
: '*';
$orderBy = [
'etapes' => 'circuit_id ASC, ordre ASC',
'journal_activite' => 'created_at DESC',
'scans' => 'scanned_at DESC',
][$module] ?? 'id DESC';
$stmt = $pdo->prepare("SELECT $columns FROM `$table` WHERE " . implode(' AND ', $where) . " ORDER BY $orderBy");
$stmt->execute($params);
echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST') {
if (!authz_values_allowed($pdo, $ctx, $module, $input)) {
http_response_code(403);
echo json_encode(['message' => 'Creation hors de votre perimetre']);
exit;
}
$cols = [];
$placeholders = [];
$values = [];
foreach ($fields as $f) {
if (array_key_exists($f, $input)) {
$cols[] = "`$f`";
$placeholders[] = '?';
$values[] = $input[$f];
}
}

if (in_array($module, $ecoleScopedModules, true)) {
$ecoleId = currentEcoleId($pdo);
if ($ecoleId) {
$cols[] = '`ecole_id`';
$placeholders[] = '?';
$values[] = $ecoleId;
}
}
if (in_array($module, $anneeScopedModules, true)) {
$anneeId = currentAnneeScolaireId($pdo);
if ($anneeId) {
$cols[] = '`annee_scolaire_id`';
$placeholders[] = '?';
$values[] = $anneeId;
}
}
if ($module === 'eleves') {
$cols[] = '`qr_code`';
$placeholders[] = '?';
$values[] = 'SHIPP-' . strtoupper(bin2hex(random_bytes(6)));
}

if (!$cols) {
http_response_code(400);
echo json_encode(['message' => 'Aucune donnee fournie']);
exit;
}
$sql = "INSERT INTO `$table` (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
$stmt = $pdo->prepare($sql);
$stmt->execute($values);
$newId = (int) $pdo->lastInsertId();
try {
$logStmt = $pdo->prepare('INSERT INTO journal_activite (ecole_id, user_id, action, module_key, record_id, details) VALUES (?, ?, ?, ?, ?, ?)');
$logStmt->execute([$ecoleId ?? null, $authUser['sub'], 'create', $module, $newId, null]);
} catch (Throwable $e) {
// Journal best-effort : on ignore silencieusement l'echec.
}
echo json_encode(['message' => 'ok', 'id' => $newId]);
exit;
}

if ($method === 'PUT') {
$id = (int) ($input['id'] ?? 0);
if (!$id) {
http_response_code(400);
echo json_encode(['message' => 'id requis']);
exit;
}
if (!authz_row_allowed($pdo, $ctx, $module, $id)) {
http_response_code(404);
echo json_encode(['message' => 'Element introuvable ou hors de votre perimetre']);
exit;
}
$sets = [];
$values = [];
foreach ($fields as $f) {
if (array_key_exists($f, $input)) {
$sets[] = "`$f` = ?";
$values[] = $input[$f];
}
}
if (!$sets) {
http_response_code(400);
echo json_encode(['message' => 'Aucune donnee fournie']);
exit;
}
$values[] = $id;
$sql = "UPDATE `$table` SET " . implode(', ', $sets) . ' WHERE id = ?';
$stmt = $pdo->prepare($sql);
$stmt->execute($values);
try {
$logStmt = $pdo->prepare('INSERT INTO journal_activite (ecole_id, user_id, action, module_key, record_id, details) VALUES (?, ?, ?, ?, ?, ?)');
$logStmt->execute([currentEcoleId($pdo), $authUser['sub'], 'update', $module, $id, 'Champs : ' . implode(', ', array_map(function ($x) { return trim($x, '` =?'); }, $sets))]);
} catch (Throwable $e) {
// Journal best-effort : on ignore silencieusement l'echec.
}
echo json_encode(['message' => 'ok']);
exit;
}

if ($method === 'DELETE') {
$id = (int) ($input['id'] ?? ($_GET['id'] ?? 0));
if (!$id) {
http_response_code(400);
echo json_encode(['message' => 'id requis']);
exit;
}
if (!authz_row_allowed($pdo, $ctx, $module, $id)) {
http_response_code(404);
echo json_encode(['message' => 'Element introuvable ou hors de votre perimetre']);
exit;
}
$stmt = $pdo->prepare("DELETE FROM `$table` WHERE id = ?");
$stmt->execute([$id]);
try {
$logStmt = $pdo->prepare('INSERT INTO journal_activite (ecole_id, user_id, action, module_key, record_id, details) VALUES (?, ?, ?, ?, ?, ?)');
$logStmt->execute([currentEcoleId($pdo), $authUser['sub'], 'delete', $module, $id, null]);
} catch (Throwable $e) {
// Journal best-effort : on ignore silencieusement l'echec.
}
echo json_encode(['message' => 'ok']);
exit;
}

http_response_code(405);
echo json_encode(['message' => 'Methode non autorisee']);
