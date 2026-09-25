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

// --- Droit de creation sur le module scans (roles + scope) ---
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
authz_require($ctx, 'scans', 'can_create');
// --- Fin verification ---

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
http_response_code(405);
echo json_encode(['message' => 'Methode non autorisee']);
exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$eleveId = (int) ($input['eleve_id'] ?? 0);
$type = trim($input['type'] ?? '');
$methode = trim($input['methode'] ?? 'recherche');
$trajetId = isset($input['trajet_id']) && $input['trajet_id'] !== '' ? (int) $input['trajet_id'] : null;
$etapeId = isset($input['etape_id']) && $input['etape_id'] !== '' ? (int) $input['etape_id'] : null;

$typesValides = ['transport_embarquement', 'transport_debarquement', 'cantine'];
$methodesValides = ['camera', 'recherche', 'code'];

if (!$eleveId || !in_array($type, $typesValides, true)) {
http_response_code(400);
echo json_encode(['message' => 'eleve_id et type valides requis']);
exit;
}
if (!in_array($methode, $methodesValides, true)) {
$methode = 'recherche';
}

$eleveStmt = $pdo->prepare('SELECT * FROM eleves WHERE id = ?');
$eleveStmt->execute([$eleveId]);
$eleve = $eleveStmt->fetch(PDO::FETCH_ASSOC);
if (!$eleve) {
http_response_code(404);
echo json_encode(['message' => 'Eleve introuvable']);
exit;
}
// --- Transport connecte (migration 002) : contexte du trajet ---
$v5 = authz_table_exists($pdo, 'transport_events') && authz_column_exists($pdo, 'trajets', 'chauffeur_id');
$trajet = null;
$scanWarnings = [];
if ($v5 && $type !== 'cantine') {
require_once __DIR__ . '/lib/transport.php';
if ($trajetId) {
$t = $pdo->prepare('SELECT * FROM trajets WHERE id = ?');
$t->execute([$trajetId]);
$trajet = $t->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$trajet || !authz_row_allowed($pdo, $ctx, 'trajets', $trajetId)) {
http_response_code(404);
echo json_encode(['message' => 'Trajet introuvable']);
exit;
}
} else {
// Trajet en cours du chauffeur : de preference celui du circuit de l'eleve.
$sets = authz_sets($pdo, $ctx);
if ($sets['route_trajets']) {
$in = implode(',', array_fill(0, count($sets['route_trajets']), '?'));
$t = $pdo->prepare("SELECT * FROM trajets WHERE id IN ($in) AND statut = 'en_cours' AND date_trajet = CURDATE() ORDER BY (circuit_id = ?) DESC, id DESC LIMIT 1");
$t->execute(array_merge($sets['route_trajets'], [(int) ($eleve['circuit_id'] ?? 0)]));
$trajet = $t->fetch(PDO::FETCH_ASSOC) ?: null;
}
}
if ($trajet) {
$trajetId = (int) $trajet['id'];
if ($trajet['statut'] !== 'en_cours') {
http_response_code(400);
echo json_encode(['message' => "Ce trajet n'est pas en cours"]);
exit;
}
if (!$etapeId && !empty($trajet['etape_courante_id'])) {
$etapeId = (int) $trajet['etape_courante_id'];
}
// Anti-doublon : une seule montee / descente par eleve et par trajet.
$dup = $pdo->prepare('SELECT id FROM scans WHERE eleve_id = ? AND type = ? AND trajet_id = ? LIMIT 1');
$dup->execute([$eleveId, $type, $trajetId]);
if ($dup->fetch()) {
http_response_code(409);
echo json_encode(['message' => 'Scan deja enregistre pour cet eleve sur ce trajet']);
exit;
}
}
}
if ($v5 && $type === 'cantine') {
require_once __DIR__ . '/lib/transport.php';
$dup = $pdo->prepare("SELECT id FROM scans WHERE eleve_id = ? AND type = 'cantine' AND DATE(scanned_at) = CURDATE() LIMIT 1");
$dup->execute([$eleveId]);
if ($dup->fetch()) {
http_response_code(409);
echo json_encode(['message' => 'Eleve deja pointe a la cantine aujourd hui']);
exit;
}
}

// Un chauffeur ne peut scanner que les eleves de ses circuits (ou couverts).
// Exception : pendant son propre trajet en cours, un eleve de l'ecole non affecte
// au circuit est accepte avec une alerte (changement ponctuel).
if (!authz_values_allowed($pdo, $ctx, 'scans', ['eleve_id' => $eleveId])) {
if ($trajet && authz_row_allowed($pdo, $ctx, 'trajets', (int) $trajet['id']) && (int) ($eleve['ecole_id'] ?? 0) === (int) ($trajet['ecole_id'] ?? -1)) {
$scanWarnings[] = 'eleve_non_affecte';
} else {
http_response_code(403);
echo json_encode(['message' => 'Eleve hors de votre perimetre']);
exit;
}
}

// --- Verification de l'abonnement (cahier des charges 15.1) ---
// Un abonnement suspendu ne bloque pas le scan : on retourne un
// avertissement au frontend, mais l'evenement reste enregistre.
$abonnementWarning = null;
try {
$abonnementTypeMap = [
'cantine' => 'cantine',
'transport_embarquement' => 'transport',
'transport_debarquement' => 'transport',
];
$aboType = $abonnementTypeMap[$type] ?? null;
if ($aboType) {
$aboStmt = $pdo->prepare(
'SELECT statut, date_debut, date_fin FROM abonnements WHERE eleve_id = ? AND type = ? ORDER BY id DESC LIMIT 1'
);
$aboStmt->execute([$eleveId, $aboType]);
$abo = $aboStmt->fetch(PDO::FETCH_ASSOC);
if ($abo && $abo['statut'] === 'suspendu') {
$abonnementWarning = 'abonnement_suspendu';
} elseif ($v5) {
$etat = abonnement_etat($abo ?: null, date('Y-m-d'));
if ($etat !== 'actif') {
$abonnementWarning = 'abonnement_' . $etat;
}
}
}
} catch (Throwable $e) {
// Verification best-effort : ne doit jamais bloquer l'enregistrement du scan.
}
// --- Fin verification abonnement ---

$ecoleRow = $pdo->query('SELECT id FROM ecoles ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$ecoleId = $ecoleRow ? (int) $ecoleRow['id'] : null;
$anneeRow = $pdo->query("SELECT id FROM annees_scolaires WHERE statut = 'active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$anneeId = $anneeRow ? (int) $anneeRow['id'] : null;

$insert = $pdo->prepare(
'INSERT INTO scans (ecole_id, annee_scolaire_id, eleve_id, type, trajet_id, etape_id, methode, scanned_by)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$insert->execute([$ecoleId, $anneeId, $eleveId, $type, $trajetId, $etapeId, $methode, $authUser['sub']]);
$scanId = (int) $pdo->lastInsertId();

// --- Dispatcher d'evenements interne (synchrone) : notification best-effort ---
// Un echec ici ne doit jamais faire echouer l'enregistrement du scan lui-meme.
if ($v5) {
try {
$evtType = ['transport_embarquement' => 'STUDENT_BOARDED', 'transport_debarquement' => 'STUDENT_DROPPED', 'cantine' => 'CANTEEN_SERVED'][$type];
$liens = [
'ecole_id' => $ecoleId, 'annee_scolaire_id' => $anneeId, 'eleve_id' => $eleveId, 'scan_id' => $scanId,
'trajet_id' => $trajetId, 'etape_id' => $etapeId, 'cree_par' => (int) $authUser['sub'],
'circuit_id' => $trajet ? (int) $trajet['circuit_id'] : null,
'chauffeur_id' => $trajet && !empty($trajet['chauffeur_id']) ? (int) $trajet['chauffeur_id'] : null,
'vehicle_id' => $trajet && !empty($trajet['vehicle_id']) ? (int) $trajet['vehicle_id'] : null,
'details' => $scanWarnings ? implode(',', $scanWarnings) : null,
];
$evt = transport_event($pdo, $evtType, $liens);
$nomComplet = trim(($eleve['prenom'] ?? '') . ' ' . ($eleve['nom'] ?? ''));
$heure = date('H:i');
$messages = [
'transport_embarquement' => ['Montee dans le vehicule', $nomComplet . ' est monte(e) dans le vehicule a ' . $heure . '.'],
'transport_debarquement' => ['Descente du vehicule', $nomComplet . ' est descendu(e) du vehicule a ' . $heure . '.'],
'cantine' => ['Cantine', $nomComplet . ' a ete servi(e) a la cantine a ' . $heure . '.'],
];
notify_parents($pdo, $eleveId, $messages[$type][0], $messages[$type][1], $evtType, $liens + ['evenement_id' => $evt]);
} catch (Throwable $e) {
// best-effort
}
} else
try {
$typeLabels = [
'transport_embarquement' => 'monte dans le vehicule',
'transport_debarquement' => 'est descendu du vehicule',
'cantine' => 'a ete pointe a la cantine',
];
$nomComplet = trim(($eleve['nom'] ?? '') . ' ' . ($eleve['prenom'] ?? ''));
$libelle = $typeLabels[$type] ?? $type;
$titre = 'Scan ' . ($type === 'cantine' ? 'cantine' : 'transport');
$message = $nomComplet . ' ' . $libelle . '.';

$notifStmt = $pdo->prepare(
'INSERT INTO notifications (ecole_id, titre, message, cible, statut) VALUES (?, ?, ?, ?, ?)'
);
$notifStmt->execute([$ecoleId, $titre, $message, 'eleve:' . $eleveId, 'envoyee']);
} catch (Throwable $e) {
// Notification best-effort : on ignore silencieusement l'echec.
}
// --- Fin dispatcher ---

echo json_encode(['message' => 'ok', 'scan_id' => $scanId, 'trajet_id' => $trajetId, 'etape_id' => $etapeId, 'abonnement_warning' => $abonnementWarning, 'alertes' => $scanWarnings]);
