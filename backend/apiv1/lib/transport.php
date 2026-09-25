<?php
/**
 * SHIPP School — logique transport partagee (lot 2) :
 * evenements transport, notifications a destinataires, eleves attendus,
 * vehicule du chauffeur. Requiert la migration 002.
 */

function shipp_param(PDO $pdo, string $cle, string $defaut): string
{
    try {
        $s = $pdo->prepare('SELECT valeur FROM parametres WHERE cle = ? ORDER BY ecole_id IS NULL, ecole_id LIMIT 1');
        $s->execute([$cle]);
        $v = $s->fetchColumn();
        return $v !== false ? (string) $v : $defaut;
    } catch (Throwable $e) {
        return $defaut;
    }
}

/** Enregistre un evenement transport (source de verite du suivi). */
function transport_event(PDO $pdo, string $type, array $d): int
{
    $cols = ['type', 'ecole_id', 'annee_scolaire_id', 'eleve_id', 'trajet_id', 'circuit_id', 'etape_id', 'chauffeur_id', 'vehicle_id', 'scan_id', 'incident_id', 'survenu_at', 'heure_connue', 'source', 'cree_par', 'details'];
    $d['type'] = $type;
    $d['survenu_at'] = $d['survenu_at'] ?? date('Y-m-d H:i:s');
    $d['heure_connue'] = $d['heure_connue'] ?? 1;
    $d['source'] = $d['source'] ?? 'app';
    $vals = [];
    foreach ($cols as $c) {
        $vals[] = $d[$c] ?? null;
    }
    $pdo->prepare('INSERT INTO transport_events (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute($vals);
    return (int) $pdo->lastInsertId();
}

/**
 * Cree une notification pour chaque parent lie a l'eleve (lecture individuelle).
 * Renseigne aussi 'cible' pour la compatibilite avec les ecrans existants.
 * Retourne le nombre de destinataires.
 */
function notify_parents(PDO $pdo, int $eleveId, string $titre, string $message, string $type, array $liens = []): int
{
    $parents = $pdo->prepare('SELECT DISTINCT pl.user_id FROM parent_liaisons pl JOIN users u ON u.id = pl.user_id WHERE pl.eleve_id = ? AND u.status = \'active\'');
    $parents->execute([$eleveId]);
    $userIds = array_map('intval', array_column($parents->fetchAll(PDO::FETCH_ASSOC), 'user_id'));
    $pdo->prepare(
        'INSERT INTO notifications (ecole_id, titre, message, cible, statut, type, eleve_id, trajet_id, etape_id, chauffeur_id, vehicle_id, evenement_id, incident_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $liens['ecole_id'] ?? null, mb_substr($titre, 0, 150), mb_substr($message, 0, 500), 'eleve:' . $eleveId, 'envoyee', $type, $eleveId,
        $liens['trajet_id'] ?? null, $liens['etape_id'] ?? null, $liens['chauffeur_id'] ?? null, $liens['vehicle_id'] ?? null,
        $liens['evenement_id'] ?? null, $liens['incident_id'] ?? null,
    ]);
    $notifId = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO notification_recipients (notification_id, user_id, envoye_at) VALUES (?, ?, NOW())');
    foreach ($userIds as $uid) {
        $ins->execute([$notifId, $uid]);
    }
    return count($userIds);
}

/** Vehicule affecte au chauffeur a une date (affectation active), sinon null. */
function chauffeur_vehicle_on(PDO $pdo, ?int $chauffeurId, string $date): ?int
{
    if (!$chauffeurId) {
        return null;
    }
    $s = $pdo->prepare(
        "SELECT vehicle_id FROM vehicle_assignments
         WHERE chauffeur_id = ? AND statut IN ('active', 'planifiee') AND date_debut <= ? AND (date_fin IS NULL OR date_fin >= ?)
         ORDER BY date_debut DESC LIMIT 1"
    );
    $s->execute([$chauffeurId, $date, $date]);
    $v = $s->fetchColumn();
    return $v !== false ? (int) $v : null;
}

/** Fiche chauffeur du titulaire d'un circuit (via affectations_chauffeur). */
function circuit_titulaire_chauffeur_id(PDO $pdo, int $circuitId): ?int
{
    $s = $pdo->prepare('SELECT c.id FROM affectations_chauffeur ac JOIN chauffeurs c ON c.user_id = ac.user_id WHERE ac.circuit_id = ? LIMIT 1');
    $s->execute([$circuitId]);
    $v = $s->fetchColumn();
    return $v !== false ? (int) $v : null;
}

/**
 * Eleves attendus sur un trajet : affectations transport actives du circuit
 * (sens compatible), sinon eleves ayant ce circuit. Indique l'arret de montee
 * et de depose, et si l'abonnement transport est valide a la date du trajet.
 */
function trajet_expected_students(PDO $pdo, array $trajet): array
{
    $sens = $trajet['sens'] ?? null;
    $date = $trajet['date_trajet'];
    $rows = $pdo->prepare(
        "SELECT a.eleve_id, a.etape_montee_id, a.etape_depose_id, a.sens, e.nom, e.prenom, e.classe, e.code_dr, e.qr_code
         FROM eleve_affectations_transport a JOIN eleves e ON e.id = a.eleve_id
         WHERE a.circuit_id = ? AND a.statut = 'active'
           AND (a.date_debut IS NULL OR a.date_debut <= ?) AND (a.date_fin IS NULL OR a.date_fin >= ?)"
    );
    $rows->execute([$trajet['circuit_id'], $date, $date]);
    $list = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($sens && $r['sens'] !== 'aller_retour' && $r['sens'] !== $sens) {
            continue;
        }
        $list[(int) $r['eleve_id']] = $r;
    }
    // Repli : eleves rattaches au circuit sans affectation detaillee.
    $fb = $pdo->prepare("SELECT id AS eleve_id, NULL AS etape_montee_id, NULL AS etape_depose_id, 'aller_retour' AS sens, nom, prenom, classe, code_dr, qr_code FROM eleves WHERE circuit_id = ? AND statut = 'actif'");
    $fb->execute([$trajet['circuit_id']]);
    foreach ($fb->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($list[(int) $r['eleve_id']])) {
            $list[(int) $r['eleve_id']] = $r;
        }
    }
    if (!$list) {
        return [];
    }
    $ids = array_keys($list);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $ab = $pdo->prepare("SELECT eleve_id, statut, date_debut, date_fin FROM abonnements WHERE type = 'transport' AND eleve_id IN ($in) ORDER BY id DESC");
    $ab->execute($ids);
    $abos = [];
    foreach ($ab->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($abos[(int) $r['eleve_id']])) {
            $abos[(int) $r['eleve_id']] = $r;
        }
    }
    foreach ($list as $id => &$r) {
        $r['eleve_id'] = $id;
        $r['abonnement_statut'] = abonnement_etat($abos[$id] ?? null, $date);
    }
    unset($r);
    return array_values($list);
}

/** Etat d'un abonnement a une date : actif, suspendu, resilie, en_attente, expire, absent. */
function abonnement_etat(?array $abo, string $date): string
{
    if (!$abo) {
        return 'absent';
    }
    if ($abo['statut'] !== 'actif') {
        return $abo['statut'];
    }
    if (!empty($abo['date_fin']) && $abo['date_fin'] < $date) {
        return 'expire';
    }
    if (!empty($abo['date_debut']) && $abo['date_debut'] > $date) {
        return 'en_attente';
    }
    return 'actif';
}

/** Statut transport de chaque eleve sur un trajet, d'apres les evenements. */
function trajet_student_statuses(PDO $pdo, int $trajetId): array
{
    $s = $pdo->prepare("SELECT eleve_id, type, survenu_at FROM transport_events WHERE trajet_id = ? AND eleve_id IS NOT NULL AND statut = 'valide' ORDER BY survenu_at ASC, id ASC");
    $s->execute([$trajetId]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int) $r['eleve_id'];
        $out[$id] = $out[$id] ?? ['embarque_at' => null, 'depose_at' => null, 'absent_at' => null];
        if ($r['type'] === 'STUDENT_BOARDED') {
            $out[$id]['embarque_at'] = $r['survenu_at'];
        } elseif ($r['type'] === 'STUDENT_DROPPED') {
            $out[$id]['depose_at'] = $r['survenu_at'];
        } elseif ($r['type'] === 'STUDENT_ABSENT') {
            $out[$id]['absent_at'] = $r['survenu_at'];
        }
    }
    return $out;
}

function eleve_nom_complet(PDO $pdo, int $eleveId): string
{
    $s = $pdo->prepare('SELECT nom, prenom FROM eleves WHERE id = ?');
    $s->execute([$eleveId]);
    $e = $s->fetch(PDO::FETCH_ASSOC) ?: ['nom' => '', 'prenom' => ''];
    return trim($e['prenom'] . ' ' . $e['nom']);
}
