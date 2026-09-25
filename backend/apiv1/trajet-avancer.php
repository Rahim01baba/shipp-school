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

// --- Droit d'edition sur le module trajets (roles + scope) ---
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
authz_require($ctx, 'trajets', 'can_edit');
// --- Fin verification ---

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
http_response_code(405);
echo json_encode(['message' => 'Methode non autorisee']);
exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$trajetId = (int) ($input['trajet_id'] ?? 0);
$action = trim($input['action'] ?? '');

if (!$trajetId || !in_array($action, ['demarrer', 'avancer', 'cloturer', 'annuler'], true)) {
http_response_code(400);
echo json_encode(['message' => 'trajet_id et action valides requis']);
exit;
}

$trajetStmt = $pdo->prepare('SELECT * FROM trajets WHERE id = ?');
$trajetStmt->execute([$trajetId]);
$trajet = $trajetStmt->fetch(PDO::FETCH_ASSOC);
if (!$trajet || !authz_row_allowed($pdo, $ctx, 'trajets', $trajetId)) {
http_response_code(404);
echo json_encode(['message' => 'Trajet introuvable']);
exit;
}

// --- Transport connecte (migration 002) : chauffeur, vehicule, passages, evenements ---
$v5 = authz_table_exists($pdo, 'transport_events') && authz_column_exists($pdo, 'trajets', 'chauffeur_id');
if ($v5) {
require_once __DIR__ . '/lib/transport.php';
}
function v5_passage(PDO $pdo, array $trajet, array $etape): ?int
{
$ecart = null;
if (!empty($etape['heure_estimee'])) {
$prevu = strtotime($trajet['date_trajet'] . ' ' . $etape['heure_estimee']);
$ecart = (int) round((time() - $prevu) / 60);
}
try {
$pdo->prepare('INSERT INTO trajet_passages (trajet_id, etape_id, heure_prevue, heure_reelle, ecart_minutes) VALUES (?, ?, ?, NOW(), ?)')
->execute([(int) $trajet['id'], (int) $etape['id'], $etape['heure_estimee'] ?: null, $ecart]);
} catch (Throwable $e) {
// Passage deja enregistre : on conserve le premier.
}
return $ecart;
}
function v5_ctx(array $trajet): array
{
return [
'ecole_id' => $trajet['ecole_id'] ?? null,
'annee_scolaire_id' => $trajet['annee_scolaire_id'] ?? null,
'trajet_id' => (int) $trajet['id'],
'circuit_id' => (int) $trajet['circuit_id'],
'chauffeur_id' => isset($trajet['chauffeur_id']) ? (int) $trajet['chauffeur_id'] ?: null : null,
'vehicle_id' => isset($trajet['vehicle_id']) ? (int) $trajet['vehicle_id'] ?: null : null,
];
}

$etapesStmt = $pdo->prepare('SELECT * FROM etapes WHERE circuit_id = ? ORDER BY ordre ASC');
$etapesStmt->execute([$trajet['circuit_id']]);
$etapes = $etapesStmt->fetchAll(PDO::FETCH_ASSOC);

if ($action === 'annuler') {
if (!in_array($trajet['statut'], ['planifie', 'en_cours'], true)) {
http_response_code(400);
echo json_encode(['message' => 'Seul un trajet planifie ou en cours peut etre annule (statut actuel : ' . $trajet['statut'] . ')']);
exit;
}
$upd = $pdo->prepare("UPDATE trajets SET statut = 'annule' WHERE id = ?");
$upd->execute([$trajetId]);
if ($v5) {
$motif = trim((string) ($input['motif'] ?? '')) ?: null;
$pdo->prepare('UPDATE trajets SET motif_annulation = ? WHERE id = ?')->execute([$motif, $trajetId]);
transport_event($pdo, 'TRIP_CANCELLED', v5_ctx($trajet) + ['cree_par' => (int) $authUser['sub'], 'details' => $motif]);
}
echo json_encode(['message' => 'ok', 'statut' => 'annule']);
exit;
}

if ($action === 'demarrer') {
if ($trajet['statut'] !== 'planifie') {
http_response_code(400);
echo json_encode(['message' => 'Ce trajet ne peut pas etre demarre (statut actuel : ' . $trajet['statut'] . ')']);
exit;
}
if (!$etapes) {
http_response_code(400);
echo json_encode(['message' => 'Ce circuit ne possede aucune etape']);
exit;
}
$premiereEtapeId = $etapes[0]['id'];
$upd = $pdo->prepare("UPDATE trajets SET statut = 'en_cours', etape_courante_id = ?, heure_debut = NOW() WHERE id = ?");
$upd->execute([$premiereEtapeId, $trajetId]);
if ($v5) {
// Le trajet fige son chauffeur et son vehicule au demarrage.
$chauffeurId = authz_my_chauffeur_id($pdo, $ctx) ?: ((int) ($trajet['chauffeur_id'] ?? 0) ?: circuit_titulaire_chauffeur_id($pdo, (int) $trajet['circuit_id']));
$vehicleId = (int) ($trajet['vehicle_id'] ?? 0) ?: chauffeur_vehicle_on($pdo, $chauffeurId, $trajet['date_trajet']);
if (!$vehicleId) {
$cv = $pdo->prepare('SELECT vehicule_id FROM circuits WHERE id = ?');
$cv->execute([(int) $trajet['circuit_id']]);
$vehicleId = (int) $cv->fetchColumn() ?: null;
}
$pdo->prepare('UPDATE trajets SET chauffeur_id = ?, vehicle_id = ? WHERE id = ?')->execute([$chauffeurId, $vehicleId, $trajetId]);
$trajet['chauffeur_id'] = $chauffeurId;
$trajet['vehicle_id'] = $vehicleId;
$base = v5_ctx($trajet) + ['cree_par' => (int) $authUser['sub']];
transport_event($pdo, 'TRIP_STARTED', $base);
v5_passage($pdo, $trajet, $etapes[0]);
transport_event($pdo, 'ARRIVED_AT_STOP', $base + ['etape_id' => (int) $etapes[0]['id']]);
foreach (trajet_expected_students($pdo, $trajet) as $el) {
notify_parents($pdo, (int) $el['eleve_id'], 'Trajet demarre', 'Le vehicule de ' . trim($el['prenom'] . ' ' . $el['nom']) . ' a demarre son trajet.', 'TRIP_STARTED', $base);
}
}
echo json_encode(['message' => 'ok', 'statut' => 'en_cours', 'etape_courante_id' => $premiereEtapeId]);
exit;
}

if ($action === 'avancer') {
if ($trajet['statut'] !== 'en_cours') {
http_response_code(400);
echo json_encode(['message' => "Ce trajet n'est pas en cours"]);
exit;
}
$currentIndex = null;
foreach ($etapes as $i => $e) {
if ((int) $e['id'] === (int) $trajet['etape_courante_id']) {
$currentIndex = $i;
break;
}
}
if ($currentIndex === null) {
http_response_code(400);
echo json_encode(['message' => 'Etape courante introuvable dans ce circuit']);
exit;
}
if ($currentIndex + 1 >= count($etapes)) {
http_response_code(400);
echo json_encode(['message' => 'Derniere etape atteinte, utilisez cloturer pour terminer le trajet']);
exit;
}
$nextEtapeId = $etapes[$currentIndex + 1]['id'];
$upd = $pdo->prepare('UPDATE trajets SET etape_courante_id = ? WHERE id = ?');
$upd->execute([$nextEtapeId, $trajetId]);
$ecart = null;
if ($v5) {
$base = v5_ctx($trajet) + ['cree_par' => (int) $authUser['sub'], 'etape_id' => (int) $nextEtapeId];
$ecart = v5_passage($pdo, $trajet, $etapes[$currentIndex + 1]);
transport_event($pdo, 'ARRIVED_AT_STOP', $base);
$tolerance = (int) shipp_param($pdo, 'retard_tolerance_minutes', '10');
if ($ecart !== null && $ecart > $tolerance) {
transport_event($pdo, 'DELAY_DETECTED', $base + ['details' => 'Retard de ' . $ecart . ' min a l arret ' . $etapes[$currentIndex + 1]['nom']]);
}
}
echo json_encode(['message' => 'ok', 'etape_courante_id' => $nextEtapeId, 'ecart_minutes' => $ecart]);
exit;
}

if ($action === 'cloturer') {
if ($trajet['statut'] !== 'en_cours') {
http_response_code(400);
echo json_encode(['message' => "Ce trajet n'est pas en cours"]);
exit;
}
$upd = $pdo->prepare("UPDATE trajets SET statut = 'termine', heure_fin = NOW() WHERE id = ?");
$upd->execute([$trajetId]);
$absents = 0;
if ($v5) {
$base = v5_ctx($trajet) + ['cree_par' => (int) $authUser['sub']];
// Eleves attendus ni embarques ni deja marques absents : absence constatee en fin de trajet.
$statuts = trajet_student_statuses($pdo, $trajetId);
foreach (trajet_expected_students($pdo, $trajet) as $el) {
$eid = (int) $el['eleve_id'];
$st = $statuts[$eid] ?? null;
if ($st && ($st['embarque_at'] || $st['absent_at'])) {
continue;
}
$evt = transport_event($pdo, 'STUDENT_ABSENT', $base + ['eleve_id' => $eid, 'details' => 'Non scanne en fin de trajet']);
notify_parents($pdo, $eid, 'Absence transport', trim($el['prenom'] . ' ' . $el['nom']) . " n'a pas ete pris en charge sur ce trajet.", 'STUDENT_ABSENT', $base + ['evenement_id' => $evt]);
$absents++;
}
transport_event($pdo, 'TRIP_COMPLETED', $base);
}
echo json_encode(['message' => 'ok', 'statut' => 'termine', 'absents' => $absents]);
exit;
}

http_response_code(400);
echo json_encode(['message' => 'Action inconnue']);
