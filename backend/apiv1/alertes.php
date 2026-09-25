<?php
/**
 * Alertes calculees a la volee (lot 3) — rien n'est stocke ni modifie.
 * Chaque famille d'alertes n'est produite que si l'utilisateur lit le module
 * correspondant, et uniquement dans son perimetre.
 *
 * GET [?chauffeur_id=N]
 * -> {data: [{type, niveau, message, chauffeur_id, chauffeur_nom, entite, entite_id, date}], parametres}
 * niveau : critique | alerte | info
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
require __DIR__ . '/lib/transport.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    shipp_error(405, 'Methode non autorisee');
}

$jours = (int) shipp_param($pdo, 'alerte_expiration_jours', '30');
$joursQualif = (int) shipp_param($pdo, 'incident_qualification_jours', '7');
$today = date('Y-m-d');
$limite = date('Y-m-d', strtotime("+$jours days"));
$filtreChauffeur = !empty($_GET['chauffeur_id']) ? (int) $_GET['chauffeur_id'] : null;
$alertes = [];

function al_add(array &$alertes, string $type, string $niveau, string $message, ?array $row, ?string $entite = null, $entiteId = null, ?string $date = null): void
{
    $alertes[] = [
        'type' => $type, 'niveau' => $niveau, 'message' => $message,
        'chauffeur_id' => $row['chauffeur_id'] ?? null, 'chauffeur_nom' => isset($row['chauffeur_nom']) ? trim($row['chauffeur_nom']) : null,
        'entite' => $entite, 'entite_id' => $entiteId !== null ? (int) $entiteId : null, 'date' => $date,
    ];
}

/** Chauffeurs actifs du perimetre (module chauffeurs), filtre optionnel. */
function al_chauffeurs(PDO $pdo, array &$ctx, ?int $filtre): array
{
    $cond = authz_can($ctx, 'chauffeurs', 'can_read') ? authz_scope_condition($pdo, $ctx, 'chauffeurs', 'c.') : null;
    if ($cond === null) {
        return [];
    }
    $sql = "SELECT c.id AS chauffeur_id, TRIM(CONCAT(COALESCE(c.prenom, ''), ' ', c.nom)) AS chauffeur_nom FROM chauffeurs c WHERE c.statut = 'actif' AND (" . $cond[0] . ')';
    $params = $cond[1];
    if ($filtre) {
        $sql .= ' AND c.id = ?';
        $params[] = $filtre;
    }
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['chauffeur_id']] = $r;
    }
    return $out;
}

$chauffeurs = al_chauffeurs($pdo, $ctx, $filtreChauffeur);
$ids = array_keys($chauffeurs);
$labels = ['permis' => 'Permis de conduire', 'cni' => "Piece d'identite", 'passeport' => 'Passeport', 'visite_medicale' => 'Visite medicale',
    'attestation_residence' => 'Attestation de residence', 'casier_judiciaire' => 'Casier judiciaire', 'photo' => 'Photo', 'autre' => 'Document'];

// ---------- Documents ----------
if ($ids && authz_can($ctx, 'chauffeur_documents', 'can_read') && ($dc = authz_scope_condition($pdo, $ctx, 'chauffeur_documents', 'd.')) !== null) {
    $p = $dc[1];
    $in = authz_in('d.chauffeur_id', $ids, $p);
    $s = $pdo->prepare("SELECT d.* FROM chauffeur_documents d WHERE d.statut IN ('a_verifier', 'valide') AND $in AND (" . $dc[0] . ') ORDER BY d.id DESC');
    $s->execute(array_merge($p, []));
    $docs = $s->fetchAll(PDO::FETCH_ASSOC);
    $parChauffeurType = [];
    foreach ($docs as $d) {
        $parChauffeurType[(int) $d['chauffeur_id']][$d['type']][] = $d;
    }
    foreach ($chauffeurs as $cid => $ch) {
        $types = $parChauffeurType[$cid] ?? [];
        if (empty($types['permis'])) {
            al_add($alertes, 'permis_manquant', 'critique', 'Aucun permis de conduire enregistre', $ch, 'chauffeur', $cid);
        }
        foreach ($types as $type => $liste) {
            // Le document le plus recent de chaque type fait foi.
            $d = $liste[0];
            $lib = $labels[$type] ?? $type;
            if ($d['date_expiration'] && $d['date_expiration'] < $today) {
                al_add($alertes, 'document_expire', $type === 'permis' ? 'critique' : 'alerte', "$lib expire depuis le " . date('d/m/Y', strtotime($d['date_expiration'])), $ch, 'chauffeur_document', $d['id'], $d['date_expiration']);
            } elseif ($d['date_expiration'] && $d['date_expiration'] <= $limite) {
                al_add($alertes, 'document_expire_bientot', 'alerte', "$lib expire le " . date('d/m/Y', strtotime($d['date_expiration'])), $ch, 'chauffeur_document', $d['id'], $d['date_expiration']);
            }
            if ($d['statut'] === 'a_verifier' && authz_can($ctx, 'chauffeur_documents', 'can_validate')) {
                al_add($alertes, 'document_a_verifier', 'info', "$lib a verifier", $ch, 'chauffeur_document', $d['id']);
            }
        }
    }
}

// ---------- Contrats ----------
if ($ids && authz_can($ctx, 'chauffeur_contracts', 'can_read') && ($cc = authz_scope_condition($pdo, $ctx, 'chauffeur_contracts', 'k.')) !== null) {
    $p = $cc[1];
    $in = authz_in('k.chauffeur_id', $ids, $p);
    $s = $pdo->prepare("SELECT k.id, k.chauffeur_id, k.reference, k.statut, k.date_debut, k.date_fin, k.signe_le FROM chauffeur_contracts k WHERE k.statut IN ('actif', 'suspendu') AND $in AND (" . $cc[0] . ')');
    $s->execute($p);
    $avecContrat = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $k) {
        $ch = $chauffeurs[(int) $k['chauffeur_id']];
        if ($k['date_debut'] <= $today && (!$k['date_fin'] || $k['date_fin'] >= $today)) {
            $avecContrat[(int) $k['chauffeur_id']] = true;
        }
        if ($k['date_fin'] && $k['date_fin'] < $today) {
            al_add($alertes, 'contrat_echu', 'alerte', "Contrat {$k['reference']} echu le " . date('d/m/Y', strtotime($k['date_fin'])) . ' : a terminer ou renouveler', $ch, 'chauffeur_contract', $k['id'], $k['date_fin']);
        } elseif ($k['date_fin'] && $k['date_fin'] <= $limite) {
            al_add($alertes, 'contrat_fin_proche', 'alerte', "Contrat {$k['reference']} se termine le " . date('d/m/Y', strtotime($k['date_fin'])), $ch, 'chauffeur_contract', $k['id'], $k['date_fin']);
        }
        if (!$k['signe_le']) {
            al_add($alertes, 'contrat_non_signe', 'alerte', "Contrat {$k['reference']} actif sans date de signature", $ch, 'chauffeur_contract', $k['id']);
        }
        if ($k['statut'] === 'suspendu') {
            al_add($alertes, 'contrat_suspendu', 'info', "Contrat {$k['reference']} suspendu", $ch, 'chauffeur_contract', $k['id']);
        }
    }
    if (in_array(authz_scope($ctx, 'chauffeur_contracts'), ['GLOBAL', 'SCHOOL'], true)) {
        foreach ($chauffeurs as $cid => $ch) {
            if (empty($avecContrat[$cid])) {
                al_add($alertes, 'contrat_manquant', 'alerte', "Aucun contrat d'utilisation de vehicule en vigueur", $ch, 'chauffeur', $cid);
            }
        }
    }
}

// ---------- Vehicule ----------
if ($ids && authz_can($ctx, 'vehicle_assignments', 'can_read') && in_array(authz_scope($ctx, 'vehicle_assignments'), ['GLOBAL', 'SCHOOL'], true)) {
    foreach ($chauffeurs as $cid => $ch) {
        if (!chauffeur_vehicle_on($pdo, $cid, $today)) {
            al_add($alertes, 'sans_vehicule', 'info', "Aucun vehicule affecte aujourd'hui", $ch, 'chauffeur', $cid);
        }
    }
}

// ---------- Documents des vehicules (D-29) ----------
if (authz_can($ctx, 'vehicle_documents', 'can_read') && ($vc = authz_scope_condition($pdo, $ctx, 'vehicules', 'v.')) !== null && !$filtreChauffeur) {
    $vs = $pdo->prepare("SELECT v.id, v.immatriculation FROM vehicules v WHERE v.statut = 'actif' AND (" . $vc[0] . ')');
    $vs->execute($vc[1]);
    $vehicules = $vs->fetchAll(PDO::FETCH_ASSOC);
    $libV = ['assurance' => 'Assurance', 'carte_grise' => 'Carte grise', 'visite_technique' => 'Visite technique', 'vignette' => 'Vignette', 'autre' => 'Document'];
    foreach ($vehicules as $v) {
        $d = $pdo->prepare("SELECT * FROM vehicle_documents WHERE vehicle_id = ? AND statut IN ('a_verifier', 'valide') ORDER BY id DESC");
        $d->execute([(int) $v['id']]);
        $parType = [];
        foreach ($d->fetchAll(PDO::FETCH_ASSOC) as $doc) {
            $parType[$doc['type']] = $parType[$doc['type']] ?? $doc;
        }
        $row = ['chauffeur_id' => null, 'chauffeur_nom' => null];
        foreach (['assurance', 'visite_technique', 'carte_grise'] as $oblig) {
            if (empty($parType[$oblig])) {
                al_add($alertes, 'document_vehicule_manquant', $oblig === 'carte_grise' ? 'alerte' : 'critique', "{$v['immatriculation']} : {$libV[$oblig]} non enregistree", $row, 'vehicule', $v['id']);
            }
        }
        foreach ($parType as $type => $doc) {
            if ($doc['date_expiration'] && $doc['date_expiration'] < $today) {
                al_add($alertes, 'document_vehicule_expire', 'critique', "{$v['immatriculation']} : " . ($libV[$type] ?? $type) . ' expiree depuis le ' . date('d/m/Y', strtotime($doc['date_expiration'])), $row, 'vehicule', $v['id'], $doc['date_expiration']);
            } elseif ($doc['date_expiration'] && $doc['date_expiration'] <= $limite) {
                al_add($alertes, 'document_vehicule_expire_bientot', 'alerte', "{$v['immatriculation']} : " . ($libV[$type] ?? $type) . ' expire le ' . date('d/m/Y', strtotime($doc['date_expiration'])), $row, 'vehicule', $v['id'], $doc['date_expiration']);
            }
        }
    }
}

// ---------- Paiements : mois encaisses par l'etablissement, non reverses a SHIPP (D-25) ----------
if (authz_can($ctx, 'echeances_transport', 'can_read') && in_array(authz_scope($ctx, 'echeances_transport'), ['GLOBAL', 'SCHOOL'], true) && !$filtreChauffeur
    && ($ec = authz_scope_condition($pdo, $ctx, 'echeances_transport', 'x.')) !== null) {
    $q = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(x.montant), 0) AS total FROM echeances_transport x
                        WHERE x.encaisse_enko = 1 AND x.recu_shipp = 0 AND x.arret_service = 0 AND x.mois < ? AND (" . $ec[0] . ')');
    $q->execute(array_merge([date('Y-m-01')], $ec[1]));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if ((int) $r['n'] > 0) {
        al_add($alertes, 'paiements_a_reverser', 'alerte', "{$r['n']} mois encaisses par l'etablissement et non encore recus par SHIPP (" . number_format((float) $r['total'], 0, ',', ' ') . ' FCFA)', null, 'echeances', null);
    }
}

// ---------- Incidents ----------
if (authz_can($ctx, 'incidents', 'can_read') && (authz_can($ctx, 'incidents', 'can_edit') || authz_can($ctx, 'incidents', 'can_validate'))
    && ($ic = authz_scope_condition($pdo, $ctx, 'incidents', 'i.')) !== null) {
    $p = $ic[1];
    $sql = "SELECT i.id, i.titre, i.categorie, i.gravite, i.chauffeur_id, i.qualifiee_at, COALESCE(i.survenu_at, i.date_incident, i.created_at) AS quand,
                   TRIM(CONCAT(COALESCE(c.prenom, ''), ' ', COALESCE(c.nom, ''))) AS chauffeur_nom
            FROM incidents i LEFT JOIN chauffeurs c ON c.id = i.chauffeur_id
            WHERE i.statut IN ('ouvert', 'en_cours') AND (" . $ic[0] . ')';
    if ($filtreChauffeur) {
        $sql .= ' AND i.chauffeur_id = ?';
        $p[] = $filtreChauffeur;
    }
    $s = $pdo->prepare($sql);
    $s->execute($p);
    $seuil = date('Y-m-d H:i:s', strtotime("-$joursQualif days"));
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $quand = substr((string) $i['quand'], 0, 19);
        if ($i['categorie'] === 'accident' && !$i['qualifiee_at'] && $quand < $seuil) {
            al_add($alertes, 'accident_non_qualifie', 'alerte', "Accident non qualifie depuis plus de $joursQualif jours : {$i['titre']}", $i, 'incident', $i['id'], substr($quand, 0, 10));
        }
        if (in_array($i['gravite'], ['elevee', 'critique'], true)) {
            al_add($alertes, 'incident_grave_ouvert', $i['gravite'] === 'critique' ? 'critique' : 'alerte', ucfirst($i['categorie']) . " {$i['gravite']} en cours : {$i['titre']}", $i, 'incident', $i['id'], substr($quand, 0, 10));
        }
    }
}

$rang = ['critique' => 0, 'alerte' => 1, 'info' => 2];
usort($alertes, function ($a, $b) use ($rang) {
    return [$rang[$a['niveau']], $a['chauffeur_nom'] ?? ''] <=> [$rang[$b['niveau']], $b['chauffeur_nom'] ?? ''];
});
echo json_encode(['data' => $alertes, 'parametres' => ['alerte_expiration_jours' => $jours, 'incident_qualification_jours' => $joursQualif]]);
