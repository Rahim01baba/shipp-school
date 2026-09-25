<?php
/**
 * Espace parent : etat du transport de chacun de ses enfants.
 * GET  (parent) : tous ses enfants ; ?eleve_id=N pour un seul.
 * Toutes les informations proviennent des donnees reelles (affectations,
 * trajets du jour, evenements transport). Aucune donnee d'un autre enfant.
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
require __DIR__ . '/lib/transport.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
authz_require($ctx, 'eleves', 'can_read');
authz_require($ctx, 'transport_events', 'can_read');

$cond = authz_scope_condition($pdo, $ctx, 'eleves', 'e.');
$where = ['(' . $cond[0] . ')'];
$params = $cond[1];
if (!empty($_GET['eleve_id'])) {
    $where[] = 'e.id = ?';
    $params[] = (int) $_GET['eleve_id'];
}
if (authz_scope($ctx, 'eleves') !== 'CHILDREN' && empty($_GET['eleve_id'])) {
    shipp_error(400, 'eleve_id requis');
}
$s = $pdo->prepare('SELECT e.id, e.nom, e.prenom, e.classe, e.ecole, e.photo FROM eleves e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.prenom');
$s->execute($params);
$today = date('Y-m-d');
$labels = [
    'TRIP_STARTED' => 'Trajet demarre', 'ARRIVED_AT_STOP' => 'Arret atteint', 'STUDENT_BOARDED' => 'Monte dans le vehicule',
    'STUDENT_DROPPED' => 'Descendu du vehicule', 'STUDENT_ABSENT' => 'Absent', 'TRIP_COMPLETED' => 'Trajet termine',
    'DELAY_DETECTED' => 'Retard', 'INCIDENT_REPORTED' => 'Incident signale', 'ACCIDENT_REPORTED' => 'Accident signale',
    'CANTEEN_SERVED' => 'Servi a la cantine', 'TRIP_CANCELLED' => 'Trajet annule',
];

$enfants = [];
foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $eid = (int) $e['id'];
    $ab = $pdo->prepare('SELECT type, statut, date_debut, date_fin FROM abonnements WHERE eleve_id = ? ORDER BY id DESC');
    $ab->execute([$eid]);
    $abonnements = [];
    foreach ($ab->fetchAll(PDO::FETCH_ASSOC) as $a) {
        if (!isset($abonnements[$a['type']])) {
            $a['etat'] = abonnement_etat($a, $today);
            $abonnements[$a['type']] = $a;
        }
    }
    $typeCol = authz_column_exists($pdo, 'circuits', 'type_circuit');
    $af = $pdo->prepare(
        "SELECT a.circuit_id, a.sens, c.nom AS circuit_nom, em.nom AS arret_montee, em.heure_estimee AS heure_montee,
                ed.nom AS arret_depose, ed.heure_estimee AS heure_depose"
        . ($typeCol ? ", c.type_circuit, c.activite, c.destination, c.jours_semaine, c.heure_depart, c.heure_retour" : '') . "
         FROM eleve_affectations_transport a JOIN circuits c ON c.id = a.circuit_id
         LEFT JOIN etapes em ON em.id = a.etape_montee_id LEFT JOIN etapes ed ON ed.id = a.etape_depose_id
         WHERE a.eleve_id = ? AND a.statut = 'active' ORDER BY a.id DESC"
    );
    $af->execute([$eid]);
    $affectation = null;
    $activites = [];
    foreach ($af->fetchAll(PDO::FETCH_ASSOC) as $a) {
        foreach (['heure_montee', 'heure_depose', 'heure_depart', 'heure_retour'] as $h) {
            if (isset($a[$h])) {
                $a[$h] = substr($a[$h], 0, 5);
            }
        }
        if (($a['type_circuit'] ?? 'domicile') === 'activite') {
            $activites[] = $a;
        } elseif (!$affectation) {
            $affectation = $a;
        }
    }
    $circuitsSuivis = array_merge($affectation ? [(int) $affectation['circuit_id']] : [], array_map(function ($a) { return (int) $a['circuit_id']; }, $activites));

    $trajetsJour = [];
    if ($circuitsSuivis) {
        $tj = $pdo->prepare(
            "SELECT t.id, t.sens, t.statut, t.heure_debut, t.heure_fin, t.date_trajet, t.circuit_id, t.etape_courante_id, ci.nom AS circuit_nom,
                    TRIM(CONCAT(COALESCE(ch.prenom, ''), ' ', COALESCE(ch.nom, ''))) AS chauffeur_nom,
                    v.immatriculation, v.modele, et.nom AS arret_courant
             FROM trajets t
             JOIN circuits ci ON ci.id = t.circuit_id
             LEFT JOIN chauffeurs ch ON ch.id = t.chauffeur_id
             LEFT JOIN vehicules v ON v.id = t.vehicle_id
             LEFT JOIN etapes et ON et.id = t.etape_courante_id
             WHERE t.circuit_id IN (" . implode(',', array_fill(0, count($circuitsSuivis), '?')) . ") AND t.date_trajet = ? ORDER BY t.circuit_id = ? DESC, FIELD(t.sens, 'aller', 'retour'), t.id"
        );
        $tj->execute(array_merge($circuitsSuivis, [$today, $circuitsSuivis[0]]));
        foreach ($tj->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $st = trajet_student_statuses($pdo, (int) $t['id'])[$eid] ?? ['embarque_at' => null, 'depose_at' => null, 'absent_at' => null];
            $retard = $pdo->prepare("SELECT details FROM transport_events WHERE trajet_id = ? AND type = 'DELAY_DETECTED' ORDER BY id DESC LIMIT 1");
            $retard->execute([(int) $t['id']]);
            $t['statut_eleve'] = $st['depose_at'] ? 'depose' : ($st['embarque_at'] ? 'embarque' : ($st['absent_at'] ? 'absent' : 'attendu'));
            $t['embarque_at'] = $st['embarque_at'];
            $t['depose_at'] = $st['depose_at'];
            $t['absent_at'] = $st['absent_at'];
            $t['retard'] = $retard->fetchColumn() ?: null;
            $t['chauffeur_nom'] = trim((string) $t['chauffeur_nom']) ?: null;
            $t['activite'] = $affectation === null || (int) $t['circuit_id'] !== (int) $affectation['circuit_id'];
            unset($t['etape_courante_id']);
            $trajetsJour[] = $t;
        }
    }

    // Suivi mensuel des paiements transport (lecture parent, D-25).
    $paiements = [];
    if (authz_table_exists($pdo, 'echeances_transport') && authz_can($ctx, 'echeances_transport', 'can_read')) {
        $pm = $pdo->prepare('SELECT mois, montant, encaisse_enko, recu_shipp, arret_service FROM echeances_transport WHERE eleve_id = ? AND annee_scolaire_id <=> ? ORDER BY mois');
        $pm->execute([$eid, shipp_annee_active($pdo)]);
        $paiements = $pm->fetchAll(PDO::FETCH_ASSOC);
    }

    $ch = $pdo->prepare(
        "SELECT te.id, te.type, te.survenu_at, te.heure_connue, te.details, et.nom AS arret
         FROM transport_events te LEFT JOIN etapes et ON et.id = te.etape_id
         WHERE te.eleve_id = ? AND te.statut = 'valide' ORDER BY te.survenu_at DESC, te.id DESC LIMIT 20"
    );
    $ch->execute([$eid]);
    $chronologie = array_map(function ($r) use ($labels) {
        $r['libelle'] = $labels[$r['type']] ?? $r['type'];
        return $r;
    }, $ch->fetchAll(PDO::FETCH_ASSOC));

    $enfants[] = [
        'eleve' => $e, 'abonnements' => array_values($abonnements), 'affectation' => $affectation, 'activites' => $activites, 'paiements' => $paiements,
        'trajets_du_jour' => $trajetsJour, 'chronologie' => $chronologie,
    ];
}
echo json_encode(['data' => $enfants, 'date' => $today]);
