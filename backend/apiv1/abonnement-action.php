<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
$pdo = new PDO("mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4", $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
http_response_code(500);
echo json_encode(['message' => 'Connexion base de donnees impossible']);
exit;
}
function currentEcoleId(PDO $pdo) {
$row = $pdo->query('SELECT id FROM ecoles ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
return $row ? (int) $row['id'] : null;
}
function currentAnneeScolaireId(PDO $pdo) {
$row = $pdo->query("SELECT id FROM annees_scolaires WHERE statut = 'active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
return $row ? (int) $row['id'] : null;
}
function logHistorique(PDO $pdo, $abonnementId, $ancienStatut, $nouveauStatut, $action, $motif, $ancienneDateFin, $nouvelleDateFin, $userId) {
try {
$stmt = $pdo->prepare('INSERT INTO abonnements_historique (abonnement_id, ancien_statut, nouveau_statut, action, motif, ancienne_date_fin, nouvelle_date_fin, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([$abonnementId, $ancienStatut, $nouveauStatut, $action, $motif, $ancienneDateFin, $nouvelleDateFin, $userId]);
} catch (Throwable $e) {
}
}
$method = $_SERVER['REQUEST_METHOD'];
// Droits : roles + derogations + scope (lib/authz.php)
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
$perm = [
'can_read' => authz_can($ctx, 'abonnements', 'can_read'),
'can_create' => authz_can($ctx, 'abonnements', 'can_create'),
'can_edit' => authz_can($ctx, 'abonnements', 'can_edit'),
];
if ($method === 'GET') {
if (!$perm['can_read']) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse pour ce module']);
exit;
}
$abonnementId = (int) ($_GET['abonnement_id'] ?? 0);
if (!$abonnementId) {
http_response_code(400);
echo json_encode(['message' => 'abonnement_id requis']);
exit;
}
if (!authz_row_allowed($pdo, $ctx, 'abonnements', $abonnementId)) {
http_response_code(404);
echo json_encode(['message' => 'Abonnement introuvable']);
exit;
}
$stmt = $pdo->prepare('SELECT * FROM abonnements_historique WHERE abonnement_id = ? ORDER BY id DESC');
$stmt->execute([$abonnementId]);
echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
exit;
}
if ($method !== 'POST') {
http_response_code(405);
echo json_encode(['message' => 'Methode non autorisee']);
exit;
}
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($input['action'] ?? '');
$validActions = ['souscrire', 'renouveler', 'suspendre', 'resilier', 'reactiver'];
if (!in_array($action, $validActions, true)) {
http_response_code(400);
echo json_encode(['message' => 'Action invalide']);
exit;
}
if ($action === 'souscrire') {
if (!$perm['can_create']) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse pour ce module']);
exit;
}
$eleveId = (int) ($input['eleve_id'] ?? 0);
$type = trim($input['type'] ?? '');
$dateDebut = $input['date_debut'] ?? null;
$dateFin = $input['date_fin'] ?? null;
if (!$eleveId || !in_array($type, ['transport', 'cantine'], true)) {
http_response_code(400);
echo json_encode(['message' => 'eleve_id et type valides requis']);
exit;
}
if (!authz_values_allowed($pdo, $ctx, 'abonnements', ['eleve_id' => $eleveId])) {
http_response_code(403);
echo json_encode(['message' => 'Eleve hors de votre perimetre']);
exit;
}
$ecoleId = currentEcoleId($pdo);
$anneeId = currentAnneeScolaireId($pdo);
$statut = $dateDebut ? 'actif' : 'en_attente';
$stmt = $pdo->prepare('INSERT INTO abonnements (eleve_id, ecole_id, annee_scolaire_id, type, statut, date_debut, date_fin) VALUES (?, ?, ?, ?, ?, ?, ?)');
try {
$stmt->execute([$eleveId, $ecoleId, $anneeId, $type, $statut, $dateDebut, $dateFin]);
} catch (PDOException $e) {
if ($e->getCode() === '23000' || (int) ($e->errorInfo[1] ?? 0) === 1062) {
http_response_code(409);
echo json_encode(['message' => 'Cet eleve a deja un abonnement de ce type pour l annee en cours']);
exit;
}
throw $e;
}
$newId = (int) $pdo->lastInsertId();
logHistorique($pdo, $newId, null, $statut, 'souscription', $input['motif'] ?? null, null, $dateFin, $authUser['sub']);
echo json_encode(['message' => 'ok', 'id' => $newId, 'statut' => $statut]);
exit;
}
if (!$perm['can_edit']) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse pour ce module']);
exit;
}
$abonnementId = (int) ($input['abonnement_id'] ?? 0);
if (!$abonnementId) {
http_response_code(400);
echo json_encode(['message' => 'abonnement_id requis']);
exit;
}
$stmt = $pdo->prepare('SELECT * FROM abonnements WHERE id = ?');
$stmt->execute([$abonnementId]);
$abo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$abo || !authz_row_allowed($pdo, $ctx, 'abonnements', $abonnementId)) {
http_response_code(404);
echo json_encode(['message' => 'Abonnement introuvable']);
exit;
}
$ancienStatut = $abo['statut'];
$motif = trim($input['motif'] ?? '') ?: null;
if ($action === 'renouveler') {
if ($ancienStatut === 'resilie') {
http_response_code(400);
echo json_encode(['message' => 'Un abonnement resilie ne peut pas etre renouvele']);
exit;
}
$nouvelleDateFin = $input['date_fin'] ?? null;
if (!$nouvelleDateFin) {
http_response_code(400);
echo json_encode(['message' => 'date_fin requise pour renouveler']);
exit;
}
$upd = $pdo->prepare("UPDATE abonnements SET statut = 'actif', date_fin = ? WHERE id = ?");
$upd->execute([$nouvelleDateFin, $abonnementId]);
logHistorique($pdo, $abonnementId, $ancienStatut, 'actif', 'renouvellement', $motif, $abo['date_fin'], $nouvelleDateFin, $authUser['sub']);
echo json_encode(['message' => 'ok', 'statut' => 'actif']);
exit;
}
if ($action === 'suspendre') {
if ($ancienStatut !== 'actif') {
http_response_code(400);
echo json_encode(['message' => 'Seul un abonnement actif peut etre suspendu']);
exit;
}
$upd = $pdo->prepare("UPDATE abonnements SET statut = 'suspendu' WHERE id = ?");
$upd->execute([$abonnementId]);
logHistorique($pdo, $abonnementId, $ancienStatut, 'suspendu', 'suspension', $motif, null, null, $authUser['sub']);
echo json_encode(['message' => 'ok', 'statut' => 'suspendu']);
exit;
}
if ($action === 'reactiver') {
if ($ancienStatut !== 'suspendu') {
http_response_code(400);
echo json_encode(['message' => 'Seul un abonnement suspendu peut etre reactive']);
exit;
}
$upd = $pdo->prepare("UPDATE abonnements SET statut = 'actif' WHERE id = ?");
$upd->execute([$abonnementId]);
logHistorique($pdo, $abonnementId, $ancienStatut, 'actif', 'reactivation', $motif, null, null, $authUser['sub']);
echo json_encode(['message' => 'ok', 'statut' => 'actif']);
exit;
}
if ($action === 'resilier') {
if ($ancienStatut === 'resilie') {
http_response_code(400);
echo json_encode(['message' => 'Cet abonnement est deja resilie']);
exit;
}
$upd = $pdo->prepare("UPDATE abonnements SET statut = 'resilie' WHERE id = ?");
$upd->execute([$abonnementId]);
logHistorique($pdo, $abonnementId, $ancienStatut, 'resilie', 'resiliation', $motif, null, null, $authUser['sub']);
echo json_encode(['message' => 'ok', 'statut' => 'resilie']);
exit;
}
http_response_code(400);
echo json_encode(['message' => 'Action inconnue']);
