<?php
/**
 * Le chauffeur signale qu'un eleve attendu est absent a l'arret.
 * POST {trajet_id, eleve_id, motif?}
 * Cree l'evenement STUDENT_ABSENT et notifie les parents de l'eleve.
 */
require __DIR__ . '/lib/db.php';
shipp_headers('POST, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
require __DIR__ . '/lib/transport.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shipp_error(405, 'Methode non autorisee');
}
authz_require($ctx, 'transport_events', 'can_create');

$input = shipp_json_input();
$trajetId = (int) ($input['trajet_id'] ?? 0);
$eleveId = (int) ($input['eleve_id'] ?? 0);
if (!$trajetId || !$eleveId) {
    shipp_error(400, 'trajet_id et eleve_id requis');
}
$t = $pdo->prepare('SELECT * FROM trajets WHERE id = ?');
$t->execute([$trajetId]);
$trajet = $t->fetch(PDO::FETCH_ASSOC);
if (!$trajet || !authz_row_allowed($pdo, $ctx, 'trajets', $trajetId)) {
    shipp_error(404, 'Trajet introuvable');
}
if (!in_array($trajet['statut'], ['planifie', 'en_cours'], true)) {
    shipp_error(400, "Ce trajet n'est plus modifiable (statut : " . $trajet['statut'] . ')');
}
$attendus = array_map(function ($e) { return (int) $e['eleve_id']; }, trajet_expected_students($pdo, $trajet));
if (!in_array($eleveId, $attendus, true)) {
    shipp_error(400, "Cet eleve n'est pas attendu sur ce trajet");
}
$st = trajet_student_statuses($pdo, $trajetId)[$eleveId] ?? null;
if ($st && $st['embarque_at']) {
    shipp_error(409, 'Eleve deja embarque sur ce trajet');
}
if ($st && $st['absent_at']) {
    shipp_error(409, 'Absence deja signalee');
}
$motif = mb_substr(trim((string) ($input['motif'] ?? '')), 0, 200) ?: null;
$liens = [
    'ecole_id' => $trajet['ecole_id'], 'annee_scolaire_id' => $trajet['annee_scolaire_id'], 'eleve_id' => $eleveId,
    'trajet_id' => $trajetId, 'circuit_id' => (int) $trajet['circuit_id'], 'etape_id' => $trajet['etape_courante_id'] ?: null,
    'chauffeur_id' => $trajet['chauffeur_id'] ?: null, 'vehicle_id' => $trajet['vehicle_id'] ?: null,
    'cree_par' => (int) $authUser['sub'], 'details' => $motif ?? 'Absent a l arret',
];
$evt = transport_event($pdo, 'STUDENT_ABSENT', $liens);
$n = notify_parents($pdo, $eleveId, 'Absence transport', eleve_nom_complet($pdo, $eleveId) . " n'etait pas a l'arret a " . date('H:i') . '.', 'STUDENT_ABSENT', $liens + ['evenement_id' => $evt]);
echo json_encode(['message' => 'ok', 'evenement_id' => $evt, 'parents_notifies' => $n]);
