<?php
/**
 * « Mon activite aujourd'hui » pour le chauffeur connecte (mobile).
 * GET ?date=AAAA-MM-JJ (defaut : aujourd'hui)
 * Admin / Fleet (perimetre ecole ou global) : ?chauffeur_id=N pour assistance.
 * Retourne : chauffeur, vehicule du jour, trajets avec arrets, eleves attendus
 * par arret et statut de chaque eleve (attendu, embarque, depose, absent).
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
require __DIR__ . '/lib/transport.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
authz_require($ctx, 'trajets', 'can_read');

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    shipp_error(400, 'Date invalide');
}

$chauffeurId = authz_my_chauffeur_id($pdo, $ctx);
if (!empty($_GET['chauffeur_id']) && in_array(authz_scope($ctx, 'trajets'), ['GLOBAL', 'SCHOOL'], true)) {
    $chauffeurId = (int) $_GET['chauffeur_id'];
}
if (!$chauffeurId) {
    shipp_error(404, 'Aucune fiche chauffeur liee a ce compte');
}
$c = $pdo->prepare('SELECT id, user_id, nom, prenom, telephone, statut FROM chauffeurs WHERE id = ?');
$c->execute([$chauffeurId]);
$chauffeur = $c->fetch(PDO::FETCH_ASSOC);
if (!$chauffeur) {
    shipp_error(404, 'Chauffeur introuvable');
}

$vehicule = null;
$vid = chauffeur_vehicle_on($pdo, $chauffeurId, $date);
if ($vid) {
    $v = $pdo->prepare('SELECT id, immatriculation, modele, capacite, statut FROM vehicules WHERE id = ?');
    $v->execute([$vid]);
    $vehicule = $v->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Trajets du jour : attribues au chauffeur, ou sur un circuit dont il est titulaire / remplacant.
$circuitIds = [];
if (!empty($chauffeur['user_id'])) {
    $s = $pdo->prepare("SELECT circuit_id FROM affectations_chauffeur WHERE user_id = ?
        UNION SELECT circuit_id FROM couvertures_chauffeur WHERE chauffeur_remplacant_id = ? AND eleve_id IS NULL AND date_debut <= ? AND (date_fin IS NULL OR date_fin >= ?)");
    $s->execute([(int) $chauffeur['user_id'], (int) $chauffeur['user_id'], $date, $date]);
    $circuitIds = array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'circuit_id'));
}
$params = [$date, $chauffeurId];
$sql = "SELECT t.*, ci.nom AS circuit_nom FROM trajets t JOIN circuits ci ON ci.id = t.circuit_id
        WHERE t.date_trajet = ? AND (t.chauffeur_id = ?";
if ($circuitIds) {
    $sql .= ' OR (t.chauffeur_id IS NULL AND t.circuit_id IN (' . implode(',', array_fill(0, count($circuitIds), '?')) . '))';
    $params = array_merge($params, $circuitIds);
}
$sql .= ") AND t.statut <> 'annule' ORDER BY FIELD(t.sens, 'aller', 'retour'), t.id";
$t = $pdo->prepare($sql);
$t->execute($params);

$trajets = [];
foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $trajet) {
    $et = $pdo->prepare('SELECT id, nom, ordre, heure_estimee FROM etapes WHERE circuit_id = ? AND statut = \'active\' ORDER BY ordre');
    $et->execute([(int) $trajet['circuit_id']]);
    $arrets = $et->fetchAll(PDO::FETCH_ASSOC);
    $pa = $pdo->prepare('SELECT etape_id, heure_reelle, ecart_minutes FROM trajet_passages WHERE trajet_id = ?');
    $pa->execute([(int) $trajet['id']]);
    $passages = [];
    foreach ($pa->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $passages[(int) $p['etape_id']] = $p;
    }
    $statuts = trajet_student_statuses($pdo, (int) $trajet['id']);
    $eleves = [];
    $cpt = ['attendus' => 0, 'embarques' => 0, 'deposes' => 0, 'absents' => 0];
    foreach (trajet_expected_students($pdo, $trajet) as $e) {
        $st = $statuts[(int) $e['eleve_id']] ?? ['embarque_at' => null, 'depose_at' => null, 'absent_at' => null];
        $etat = $st['depose_at'] ? 'depose' : ($st['embarque_at'] ? 'embarque' : ($st['absent_at'] ? 'absent' : 'attendu'));
        $cpt['attendus']++;
        if ($st['embarque_at']) { $cpt['embarques']++; }
        if ($st['depose_at']) { $cpt['deposes']++; }
        if ($etat === 'absent') { $cpt['absents']++; }
        $eleves[] = [
            'eleve_id' => (int) $e['eleve_id'], 'nom' => $e['nom'], 'prenom' => $e['prenom'], 'classe' => $e['classe'],
            'code_dr' => $e['code_dr'], 'etape_montee_id' => $e['etape_montee_id'] ? (int) $e['etape_montee_id'] : null,
            'etape_depose_id' => $e['etape_depose_id'] ? (int) $e['etape_depose_id'] : null,
            'abonnement_statut' => $e['abonnement_statut'], 'statut' => $etat,
            'embarque_at' => $st['embarque_at'], 'depose_at' => $st['depose_at'], 'absent_at' => $st['absent_at'],
        ];
    }
    foreach ($arrets as &$a) {
        $a['id'] = (int) $a['id'];
        $a['heure_estimee'] = $a['heure_estimee'] ? substr($a['heure_estimee'], 0, 5) : null;
        $a['passage'] = $passages[$a['id']] ?? null;
        $a['courant'] = (int) $trajet['etape_courante_id'] === $a['id'];
    }
    unset($a);
    $veh = null;
    if (!empty($trajet['vehicle_id'])) {
        $v = $pdo->prepare('SELECT id, immatriculation, modele FROM vehicules WHERE id = ?');
        $v->execute([(int) $trajet['vehicle_id']]);
        $veh = $v->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $trajets[] = [
        'id' => (int) $trajet['id'], 'circuit_id' => (int) $trajet['circuit_id'], 'circuit_nom' => $trajet['circuit_nom'],
        'sens' => $trajet['sens'], 'statut' => $trajet['statut'], 'etape_courante_id' => $trajet['etape_courante_id'] ? (int) $trajet['etape_courante_id'] : null,
        'heure_debut' => $trajet['heure_debut'], 'heure_fin' => $trajet['heure_fin'], 'vehicule' => $veh ?: $vehicule,
        'arrets' => $arrets, 'eleves' => $eleves, 'compteurs' => $cpt,
    ];
}

echo json_encode(['chauffeur' => $chauffeur, 'vehicule' => $vehicule, 'date' => $date, 'trajets' => $trajets]);
