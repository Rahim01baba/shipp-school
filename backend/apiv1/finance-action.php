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

// Droits : roles + derogations + scope (lib/authz.php)
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);

function checkPerm(PDO $pdo, array $authUser, string $key)
{
global $ctx;
return authz_can($ctx, 'finance', $key);
}

function logHistorique(PDO $pdo, $financeId, $ancienStatut, $nouveauStatut, $action, $motif, $montantPaye, $userId)
{
try {
$stmt = $pdo->prepare(
'INSERT INTO finance_historique (finance_id, ancien_statut, nouveau_statut, action, motif, montant_paye, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$financeId, $ancienStatut, $nouveauStatut, $action, $motif, $montantPaye, $userId]);
} catch (Throwable $e) {
}
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
if (isset($_GET['repartition'])) {
if (!checkPerm($pdo, $authUser, 'can_read')) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse']);
exit;
}
// Totaux limites au perimetre de l'utilisateur et a l'annee scolaire active.
$scopeCond = authz_scope_condition($pdo, $ctx, 'finance');
if ($scopeCond === null) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse']);
exit;
}
$where = '(' . $scopeCond[0] . ')';
$params = $scopeCond[1];
$anneeRow = $pdo->query("SELECT id FROM annees_scolaires WHERE statut = 'active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($anneeRow) {
$where .= ' AND (annee_scolaire_id = ? OR annee_scolaire_id IS NULL)';
$params[] = (int) $anneeRow['id'];
}
$rowsStmt = $pdo->prepare('SELECT type, statut, montant FROM finance WHERE ' . $where);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
$totalRecettes = 0.0;
$totalDepenses = 0.0;
$parStatut = [];
foreach ($rows as $r) {
$m = (float) $r['montant'];
if ($r['type'] === 'depense') {
$totalDepenses += $m;
} else {
$totalRecettes += $m;
}
$s = $r['statut'];
if (!isset($parStatut[$s])) {
$parStatut[$s] = ['count' => 0, 'total' => 0.0];
}
$parStatut[$s]['count']++;
$parStatut[$s]['total'] += $m;
}
echo json_encode([
'total_recettes' => $totalRecettes,
'total_depenses' => $totalDepenses,
'solde' => $totalRecettes - $totalDepenses,
'par_statut' => $parStatut,
]);
exit;
}

$financeId = (int) ($_GET['finance_id'] ?? 0);
if (!$financeId) {
http_response_code(400);
echo json_encode(['message' => 'finance_id requis']);
exit;
}
if (!checkPerm($pdo, $authUser, 'can_read')) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse']);
exit;
}
if (!authz_row_allowed($pdo, $ctx, 'finance', $financeId)) {
http_response_code(404);
echo json_encode(['message' => 'Entree finance introuvable']);
exit;
}
$stmt = $pdo->prepare('SELECT * FROM finance_historique WHERE finance_id = ? ORDER BY created_at DESC');
$stmt->execute([$financeId]);
echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
exit;
}

if ($method === 'POST') {
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

$financeId = (int) ($input['finance_id'] ?? 0);
if (!$financeId) {
http_response_code(400);
echo json_encode(['message' => 'finance_id requis']);
exit;
}

$stmt = $pdo->prepare('SELECT * FROM finance WHERE id = ?');
$stmt->execute([$financeId]);
$finance = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$finance) {
http_response_code(404);
echo json_encode(['message' => 'Entree finance introuvable']);
exit;
}

if (!checkPerm($pdo, $authUser, 'can_edit') || !authz_row_allowed($pdo, $ctx, 'finance', $financeId)) {
http_response_code(403);
echo json_encode(['message' => 'Acces refuse']);
exit;
}

$ancienStatut = $finance['statut'];

if ($action === 'marquer_payee') {
if ($ancienStatut === 'payee') {
http_response_code(400);
echo json_encode(['message' => 'Cette entree est deja payee']);
exit;
}
if ($ancienStatut === 'annulee') {
http_response_code(400);
echo json_encode(['message' => 'Impossible de marquer payee une entree annulee']);
exit;
}
$modePaiement = $input['mode_paiement'] ?? null;
$montantPaye = isset($input['montant_paye']) ? (float) $input['montant_paye'] : (float) $finance['montant'];
$stmt = $pdo->prepare("UPDATE finance SET statut = 'payee', mode_paiement = ?, date_paiement = CURDATE() WHERE id = ?");
$stmt->execute([$modePaiement, $financeId]);
logHistorique($pdo, $financeId, $ancienStatut, 'payee', 'marquer_payee', $input['motif'] ?? null, $montantPaye, $authUser['sub']);
echo json_encode(['message' => 'ok']);
exit;
}

if ($action === 'marquer_en_retard') {
if ($ancienStatut !== 'en_attente') {
http_response_code(400);
echo json_encode(['message' => 'Transition invalide']);
exit;
}
$stmt = $pdo->prepare("UPDATE finance SET statut = 'en_retard' WHERE id = ?");
$stmt->execute([$financeId]);
logHistorique($pdo, $financeId, $ancienStatut, 'en_retard', 'marquer_en_retard', $input['motif'] ?? null, null, $authUser['sub']);
echo json_encode(['message' => 'ok']);
exit;
}

if ($action === 'annuler') {
if ($ancienStatut === 'payee') {
http_response_code(400);
echo json_encode(['message' => 'Impossible d annuler une entree deja payee']);
exit;
}
$stmt = $pdo->prepare("UPDATE finance SET statut = 'annulee' WHERE id = ?");
$stmt->execute([$financeId]);
logHistorique($pdo, $financeId, $ancienStatut, 'annulee', 'annuler', $input['motif'] ?? null, null, $authUser['sub']);
echo json_encode(['message' => 'ok']);
exit;
}

if ($action === 'reactiver') {
if ($ancienStatut !== 'annulee') {
http_response_code(400);
echo json_encode(['message' => 'Transition invalide']);
exit;
}
$stmt = $pdo->prepare("UPDATE finance SET statut = 'en_attente' WHERE id = ?");
$stmt->execute([$financeId]);
logHistorique($pdo, $financeId, $ancienStatut, 'en_attente', 'reactiver', $input['motif'] ?? null, null, $authUser['sub']);
echo json_encode(['message' => 'ok']);
exit;
}

http_response_code(400);
echo json_encode(['message' => 'Action inconnue']);
exit;
}

http_response_code(405);
echo json_encode(['message' => 'Methode non autorisee']);
