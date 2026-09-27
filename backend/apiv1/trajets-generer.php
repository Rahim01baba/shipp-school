<?php
/**
 * Generation des trajets d'une journee pour les circuits actifs.
 * POST {date: 'AAAA-MM-JJ', sens: 'aller'|'retour'|'les_deux', circuit_ids?: [..]}
 * Un trajet deja existant (circuit + date + sens) n'est jamais recree.
 * Le chauffeur prevu est le titulaire du circuit ; le vehicule, celui de son
 * affectation en cours (sinon celui du circuit).
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
authz_require($ctx, 'trajets', 'can_create');
if (!in_array(authz_scope($ctx, 'trajets'), ['GLOBAL', 'SCHOOL'], true)) {
    shipp_error(403, 'Acces refuse pour ce module');
}

$input = shipp_json_input();
$date = $input['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    shipp_error(400, 'Date invalide');
}
$sensDemande = $input['sens'] ?? 'les_deux';
$sensListe = $sensDemande === 'les_deux' ? ['aller', 'retour'] : [$sensDemande];
foreach ($sensListe as $s) {
    if (!in_array($s, ['aller', 'retour'], true)) {
        shipp_error(400, 'Sens invalide');
    }
}
$annee = shipp_annee_active($pdo);
if (!$annee) {
    shipp_error(400, 'Aucune annee scolaire active');
}

$cond = authz_scope_condition($pdo, $ctx, 'circuits', 'c.');
$where = ["c.statut = 'actif'", '(' . $cond[0] . ')', 'EXISTS (SELECT 1 FROM etapes et WHERE et.circuit_id = c.id)'];
$params = $cond[1];
if (!empty($input['circuit_ids']) && is_array($input['circuit_ids'])) {
    $ids = array_map('intval', $input['circuit_ids']);
    $where[] = 'c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $params = array_merge($params, $ids);
}
// Navettes d'activite (D-28) : generees seulement les jours de la semaine prevus.
if (authz_column_exists($pdo, 'circuits', 'type_circuit')) {
    $where[] = "(c.type_circuit <> 'activite' OR FIND_IN_SET(?, REPLACE(COALESCE(c.jours_semaine, ''), ' ', '')) > 0)";
    $params[] = (string) date('N', strtotime($date));
}
$circuits = $pdo->prepare('SELECT c.id, c.ecole_id, c.vehicule_id, c.nom FROM circuits c WHERE ' . implode(' AND ', $where));
$circuits->execute($params);

$exists = $pdo->prepare('SELECT id FROM trajets WHERE circuit_id = ? AND date_trajet = ? AND (sens = ? OR sens IS NULL) LIMIT 1');
$insert = $pdo->prepare(
    "INSERT INTO trajets (ecole_id, annee_scolaire_id, circuit_id, date_trajet, statut, chauffeur_id, vehicle_id, sens, source)
     VALUES (?, ?, ?, ?, 'planifie', ?, ?, ?, 'generation')"
);
$crees = [];
$ignores = 0;
foreach ($circuits->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $chauffeurId = circuit_titulaire_chauffeur_id($pdo, (int) $c['id']);
    $vehicleId = chauffeur_vehicle_on($pdo, $chauffeurId, $date) ?: ($c['vehicule_id'] ? (int) $c['vehicule_id'] : null);
    foreach ($sensListe as $sens) {
        $exists->execute([(int) $c['id'], $date, $sens]);
        if ($exists->fetch()) {
            $ignores++;
            continue;
        }
        $insert->execute([$c['ecole_id'], $annee, (int) $c['id'], $date, $chauffeurId, $vehicleId, $sens]);
        $crees[] = ['id' => (int) $pdo->lastInsertId(), 'circuit' => $c['nom'], 'sens' => $sens, 'chauffeur_id' => $chauffeurId, 'vehicle_id' => $vehicleId];
    }
}
shipp_journal($pdo, null, (int) $authUser['sub'], 'create', 'trajets', null, count($crees) . " trajet(s) genere(s) pour le $date");
echo json_encode(['message' => 'ok', 'crees' => $crees, 'deja_existants' => $ignores]);
