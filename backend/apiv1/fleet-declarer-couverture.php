<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, OPTIONS');
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

// --- Droit sur le module couvertures_chauffeur (roles + scope) ---
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
authz_require($ctx, 'couvertures_chauffeur', 'can_edit');
// Ecran d'exploitation : reserve aux perimetres ecole et global.
if (!in_array(authz_scope($ctx, 'couvertures_chauffeur'), ['GLOBAL', 'SCHOOL'], true)) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse pour ce module']);
exit;
}
// --- Fin verification ---

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['POST', 'PUT'], true)) {
http_response_code(405);
echo json_encode(['message' => 'Methode non autorisee']);
exit;
}

$ecoleRow = $pdo->query('SELECT id FROM ecoles ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$ecoleId = $ecoleRow ? (int) $ecoleRow['id'] : null;
if (!$ecoleId) {
http_response_code(500);
echo json_encode(['message' => 'Ecole introuvable']);
exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// --- Cloturer une ou plusieurs couvertures actives ---
if ($method === 'PUT') {
$conds = ['ecole_id = ?'];
$params = [$ecoleId];

if (!empty($input['id'])) {
$conds[] = 'id = ?';
$params[] = (int) $input['id'];
} elseif (!empty($input['circuit_id']) && !empty($input['chauffeur_remplacant_id'])) {
$conds[] = 'circuit_id = ?';
$params[] = (int) $input['circuit_id'];
$conds[] = 'chauffeur_remplacant_id = ?';
$params[] = (int) $input['chauffeur_remplacant_id'];
} else {
http_response_code(400);
echo json_encode(['message' => 'id ou (circuit_id + chauffeur_remplacant_id) requis']);
exit;
}
$conds[] = '(date_fin IS NULL OR date_fin >= CURDATE())';

$sel = $pdo->prepare('SELECT id, circuit_id, eleve_id, chauffeur_remplacant_id FROM couvertures_chauffeur WHERE ' . implode(' AND ', $conds));
$sel->execute($params);
$rows = $sel->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
http_response_code(404);
echo json_encode(['message' => 'Aucune couverture active correspondante']);
exit;
}

$ids = array_column($rows, 'id');
$in = implode(',', array_fill(0, count($ids), '?'));
$upd = $pdo->prepare("UPDATE couvertures_chauffeur SET date_fin = CURDATE() WHERE id IN ($in)");
$upd->execute($ids);

$circuitId = (int) $rows[0]['circuit_id'];
$circNom = $pdo->prepare('SELECT nom FROM circuits WHERE id = ?');
$circNom->execute([$circuitId]);
$circuitNom = ($circNom->fetch(PDO::FETCH_ASSOC) ?: ['nom' => '?'])['nom'];

$remplacantId = (int) $rows[0]['chauffeur_remplacant_id'];
$remplacantStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$remplacantStmt->execute([$remplacantId]);
$remplacantNom = ($remplacantStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => '?'])['name'];

try {
$logStmt = $pdo->prepare(
'INSERT INTO journal_activite (ecole_id, user_id, action, module_key, record_id, details) VALUES (?, ?, ?, ?, ?, ?)'
);
$logStmt->execute([$ecoleId, $authUser['sub'], 'update', 'couvertures_chauffeur', $circuitId, 'Fin de couverture circuit ' . $circuitNom . ' par ' . $remplacantNom . ' (' . count($ids) . ' ligne(s))']);
} catch (Throwable $e) {
// Journal best-effort.
}

try {
$titulaireStmt = $pdo->prepare('SELECT u.name FROM affectations_chauffeur ac JOIN users u ON u.id = ac.user_id WHERE ac.circuit_id = ?');
$titulaireStmt->execute([$circuitId]);
$titulaireRow = $titulaireStmt->fetch(PDO::FETCH_ASSOC);
$titulaireNom = $titulaireRow ? $titulaireRow['name'] : 'le titulaire';

$eleveIds = array_values(array_unique(array_filter(array_column($rows, 'eleve_id'))));
if (!$eleveIds) {
$elStmt = $pdo->prepare('SELECT id FROM eleves WHERE circuit_id = ? AND ecole_id = ?');
$elStmt->execute([$circuitId, $ecoleId]);
$eleveIds = array_column($elStmt->fetchAll(PDO::FETCH_ASSOC), 'id');
}
$notifStmt = $pdo->prepare(
'INSERT INTO notifications (ecole_id, titre, message, cible, statut) VALUES (?, ?, ?, ?, ?)'
);
foreach ($eleveIds as $eid) {
$notifStmt->execute([$ecoleId, 'Fin de couverture', $titulaireNom . ' reprend le circuit ' . $circuitNom . '.', 'eleve:' . $eid, 'envoyee']);
}
} catch (Throwable $e) {
// Notification best-effort.
}

echo json_encode(['message' => 'ok', 'cloturees' => count($ids)]);
exit;
}

// --- Declarer une couverture (POST) ---
$circuitId = isset($input['circuit_id']) ? (int) $input['circuit_id'] : 0;
$remplacantId = isset($input['chauffeur_remplacant_id']) ? (int) $input['chauffeur_remplacant_id'] : 0;
$portee = $input['portee'] ?? '';
$eleveIds = is_array($input['eleve_ids'] ?? null) ? array_map('intval', $input['eleve_ids']) : [];
$dateDebut = trim($input['date_debut'] ?? '');
$dateFin = trim($input['date_fin'] ?? '') ?: null;
$motif = trim($input['motif'] ?? '') ?: null;

if (!$circuitId || !$remplacantId || !in_array($portee, ['circuit', 'eleves'], true) || $dateDebut === '') {
http_response_code(400);
echo json_encode(['message' => 'Parametres invalides']);
exit;
}

$circStmt = $pdo->prepare('SELECT id, nom FROM circuits WHERE id = ? AND ecole_id = ?');
$circStmt->execute([$circuitId, $ecoleId]);
$circuit = $circStmt->fetch(PDO::FETCH_ASSOC);
if (!$circuit) {
http_response_code(404);
echo json_encode(['message' => 'Circuit introuvable']);
exit;
}

$remplacantStmt = $pdo->prepare('SELECT name FROM users WHERE id = ? AND ecole_id = ?');
$remplacantStmt->execute([$remplacantId, $ecoleId]);
$remplacant = $remplacantStmt->fetch(PDO::FETCH_ASSOC);
if (!$remplacant) {
http_response_code(404);
echo json_encode(['message' => 'Chauffeur remplacant introuvable']);
exit;
}

$elevesACouvrir = [];
if ($portee === 'eleves') {
if (!$eleveIds) {
http_response_code(400);
echo json_encode(['message' => 'Liste d elèves requise pour une couverture partielle']);
exit;
}
$in = implode(',', array_fill(0, count($eleveIds), '?'));
$elStmt = $pdo->prepare("SELECT id, nom, prenom FROM eleves WHERE circuit_id = ? AND ecole_id = ? AND id IN ($in)");
$elStmt->execute(array_merge([$circuitId, $ecoleId], $eleveIds));
$elevesACouvrir = $elStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$elevesACouvrir) {
http_response_code(400);
echo json_encode(['message' => 'Aucun eleve valide dans ce circuit pour cette liste']);
exit;
}
}

try {
$pdo->beginTransaction();
$insStmt = $pdo->prepare(
'INSERT INTO couvertures_chauffeur (ecole_id, chauffeur_remplacant_id, circuit_id, eleve_id, date_debut, date_fin, motif, created_by)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

$nbCrees = 0;
if ($portee === 'circuit') {
$insStmt->execute([$ecoleId, $remplacantId, $circuitId, null, $dateDebut, $dateFin, $motif, $authUser['sub']]);
$nbCrees = 1;
} else {
foreach ($elevesACouvrir as $el) {
$insStmt->execute([$ecoleId, $remplacantId, $circuitId, (int) $el['id'], $dateDebut, $dateFin, $motif, $authUser['sub']]);
$nbCrees++;
}
}
$pdo->commit();
} catch (Throwable $e) {
$pdo->rollBack();
http_response_code(500);
echo json_encode(['message' => 'Echec de l enregistrement de la couverture']);
exit;
}

$perimetre = $portee === 'circuit' ? 'tout le circuit' : (count($elevesACouvrir) . ' eleve(s)');
$detail = 'Couverture circuit ' . $circuit['nom'] . ' : ' . $remplacant['name'] . ', ' . $perimetre
. ', du ' . $dateDebut . ' au ' . ($dateFin ?: 'indetermine')
. ($motif ? ' (' . $motif . ')' : '');

try {
$logStmt = $pdo->prepare(
'INSERT INTO journal_activite (ecole_id, user_id, action, module_key, record_id, details) VALUES (?, ?, ?, ?, ?, ?)'
);
$logStmt->execute([$ecoleId, $authUser['sub'], 'create', 'couvertures_chauffeur', $circuitId, $detail]);
} catch (Throwable $e) {
// Journal best-effort.
}

try {
$ciblesEleveIds = $portee === 'circuit'
? array_column((function () use ($pdo, $circuitId, $ecoleId) {
$s = $pdo->prepare('SELECT id FROM eleves WHERE circuit_id = ? AND ecole_id = ?');
$s->execute([$circuitId, $ecoleId]);
return $s->fetchAll(PDO::FETCH_ASSOC);
})(), 'id')
: array_column($elevesACouvrir, 'id');

$periode = 'du ' . $dateDebut . ' au ' . ($dateFin ?: 'nouvel ordre');
$notifStmt = $pdo->prepare(
'INSERT INTO notifications (ecole_id, titre, message, cible, statut) VALUES (?, ?, ?, ?, ?)'
);
foreach ($ciblesEleveIds as $eid) {
$notifStmt->execute([$ecoleId, 'Changement de chauffeur', 'Votre enfant sera transporte par ' . $remplacant['name'] . ' ' . $periode . '.', 'eleve:' . $eid, 'envoyee']);
}
} catch (Throwable $e) {
// Notification best-effort.
}

echo json_encode(['message' => 'ok', 'created' => $nbCrees]);
