<?php
/**
 * Reporting serveur (lot 4 : T5-16, T5-19, T5-20).
 *
 * GET ?rapport=synthese|par_jour|chauffeurs|vehicules|circuits|eleves|incidents
 *     &date_debut=AAAA-MM-JJ&date_fin=AAAA-MM-JJ      (OBLIGATOIRES)
 *     [&ecole_id][&circuit_id][&chauffeur_id][&vehicule_id][&eleve_id][&classe]
 *     [&categorie][&gravite][&statut]                   (filtres incidents)
 *     [&format=csv]                                     (export, droit can_export)
 * GET ?liste=trajets|retards|embarquements|absences|repas|incidents&date_debut&date_fin&... : detail (drill-down)
 * GET ?catalogue=1 : liste des rapports et de leurs colonnes
 *
 * Toutes les donnees viennent des evenements reels (transport_events, statut
 * 'valide'), des trajets, des passages aux arrets, des scans et des incidents.
 * Perimetre : GLOBAL (tout, filtre ecole optionnel) ou SCHOOL (ses ecoles).
 * Les indicateurs n'attribuent aucune responsabilite : les accidents « imputes
 * au chauffeur » ne comptent que ceux qualifies ainsi par un responsable.
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
require __DIR__ . '/lib/transport.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
$userId = (int) $authUser['sub'];
authz_require($ctx, 'reporting', 'can_read');
$scope = authz_scope($ctx, 'reporting');
if (!in_array($scope, ['GLOBAL', 'SCHOOL'], true)) {
    shipp_error(403, 'Reporting reserve aux responsables (perimetre etablissement)');
}

const REP_COLONNES = [
    'par_jour' => ['jour' => 'Jour', 'trajets' => 'Trajets prevus', 'termines' => 'Termines', 'annules' => 'Annules', 'embarquements' => 'Embarquements',
        'deposes' => 'Deposes', 'absences' => 'Absences', 'retards' => 'Arrets en retard', 'repas' => 'Repas servis', 'incidents' => 'Incidents', 'accidents' => 'Accidents'],
    'chauffeurs' => ['chauffeur' => 'Chauffeur', 'trajets' => 'Trajets prevus', 'termines' => 'Termines', 'annules' => 'Annules', 'passages' => 'Arrets desservis',
        'retards' => 'Arrets en retard', 'retard_moyen' => 'Retard moyen (min)', 'ponctualite' => 'Ponctualite (%)', 'eleves_transportes' => 'Eleves transportes',
        'embarquements' => 'Embarquements', 'absences' => 'Absences signalees', 'incidents' => 'Incidents', 'accidents' => 'Accidents',
        'accidents_imputes' => 'Accidents qualifies « chauffeur »', 'documents_expires' => 'Documents expires (ce jour)'],
    'vehicules' => ['vehicule' => 'Vehicule', 'trajets' => 'Trajets prevus', 'termines' => 'Termines', 'chauffeurs' => 'Chauffeurs distincts',
        'embarquements' => 'Embarquements', 'retards' => 'Arrets en retard', 'incidents' => 'Incidents', 'accidents' => 'Accidents', 'cout_reel' => 'Cout reel incidents (FCFA)'],
    'circuits' => ['circuit' => 'Circuit', 'trajets' => 'Trajets prevus', 'termines' => 'Termines', 'annules' => 'Annules', 'eleves_affectes' => 'Eleves affectes',
        'eleves_transportes' => 'Eleves transportes', 'embarquements' => 'Embarquements', 'absences' => 'Absences', 'passages' => 'Arrets desservis',
        'retards' => 'Arrets en retard', 'ponctualite' => 'Ponctualite (%)', 'incidents' => 'Incidents'],
    'eleves' => ['eleve' => 'Eleve', 'classe' => 'Classe', 'circuit' => 'Circuit', 'jours_transport' => 'Jours transportes', 'embarquements' => 'Embarquements',
        'absences' => 'Absences transport', 'repas' => 'Repas servis', 'incidents' => 'Incidents'],
    'incidents' => ['dimension' => 'Dimension', 'valeur' => 'Valeur', 'nombre' => 'Nombre', 'cout_estime' => 'Cout estime (FCFA)', 'cout_reel' => 'Cout reel (FCFA)'],
    'synthese' => ['indicateur' => 'Indicateur', 'valeur' => 'Valeur'],
    'paiements' => ['mois' => 'Mois', 'eleves' => 'Eleves', 'du' => 'Du (FCFA)', 'enko' => 'Encaisse par Enko (FCFA)', 'shipp' => 'Recu par SHIPP (FCFA)',
        'a_reverser' => 'Encaisse Enko, non recu SHIPP (FCFA)', 'impaye' => 'Ni Enko ni SHIPP (FCFA)', 'arrets' => 'Services arretes'],
];
const REP_LISTES = [
    'trajets' => ['date_trajet' => 'Date', 'circuit' => 'Circuit', 'sens' => 'Sens', 'chauffeur' => 'Chauffeur', 'vehicule' => 'Vehicule', 'statut' => 'Statut', 'heure_debut' => 'Debut', 'heure_fin' => 'Fin', 'motif_annulation' => 'Motif annulation'],
    'retards' => ['date' => 'Date', 'circuit' => 'Circuit', 'arret' => 'Arret', 'heure_prevue' => 'Prevu', 'heure_reelle' => 'Reel', 'ecart_minutes' => 'Ecart (min)', 'chauffeur' => 'Chauffeur'],
    'embarquements' => ['survenu_at' => 'Heure', 'eleve' => 'Eleve', 'classe' => 'Classe', 'circuit' => 'Circuit', 'arret' => 'Arret', 'chauffeur' => 'Chauffeur'],
    'absences' => ['survenu_at' => 'Heure', 'eleve' => 'Eleve', 'classe' => 'Classe', 'circuit' => 'Circuit', 'chauffeur' => 'Chauffeur', 'details' => 'Details'],
    'repas' => ['scanned_at' => 'Heure', 'eleve' => 'Eleve', 'classe' => 'Classe'],
    'a_reverser' => ['mois' => 'Mois', 'eleve' => 'Eleve', 'classe' => 'Classe', 'montant' => 'Montant', 'enko_at' => 'Encaisse Enko le'],
    'impayes' => ['mois' => 'Mois', 'eleve' => 'Eleve', 'classe' => 'Classe', 'montant' => 'Montant'],
    'incidents' => ['date' => 'Date', 'categorie' => 'Nature', 'type' => 'Type', 'gravite' => 'Gravite', 'titre' => 'Titre', 'chauffeur' => 'Chauffeur', 'vehicule' => 'Vehicule',
        'responsabilite' => 'Responsabilite', 'statut' => 'Statut', 'cout_reel' => 'Cout reel'],
];

if (!empty($_GET['catalogue'])) {
    echo json_encode(['rapports' => REP_COLONNES, 'listes' => REP_LISTES, 'export' => authz_can($ctx, 'reporting', 'can_export')]);
    exit;
}

// ---------------------------------------------------------------- Filtres
function rep_date($v): ?string
{
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
}
$debut = rep_date($_GET['date_debut'] ?? '');
$fin = rep_date($_GET['date_fin'] ?? '');
if (!$debut || !$fin) {
    shipp_error(400, 'Date de debut et date de fin obligatoires');
}
if ($fin < $debut) {
    shipp_error(400, 'La date de fin precede la date de debut');
}
$maxJours = (int) shipp_param($pdo, 'reporting_periode_max_jours', '400');
if ((strtotime($fin) - strtotime($debut)) / 86400 > $maxJours) {
    shipp_error(400, "Periode limitee a $maxJours jours");
}
$F = ['date_debut' => $debut, 'date_fin' => $fin];
foreach (['ecole_id', 'circuit_id', 'chauffeur_id', 'vehicule_id', 'eleve_id'] as $k) {
    if (!empty($_GET[$k]) && ctype_digit((string) $_GET[$k])) {
        $F[$k] = (int) $_GET[$k];
    }
}
foreach (['classe', 'categorie', 'gravite', 'statut'] as $k) {
    if (isset($_GET[$k]) && trim((string) $_GET[$k]) !== '') {
        $F[$k] = mb_substr(trim((string) $_GET[$k]), 0, 50);
    }
}

// Restriction etablissement.
$ecoles = null;
if ($scope === 'SCHOOL') {
    $ecoles = $ctx['ecole_ids'];
    if (!$ecoles) {
        shipp_error(403, 'Aucun etablissement rattache a ce compte');
    }
    if (isset($F['ecole_id']) && !in_array($F['ecole_id'], $ecoles, true)) {
        shipp_error(403, 'Etablissement hors de votre perimetre');
    }
}
if (isset($F['ecole_id'])) {
    $ecoles = [$F['ecole_id']];
}

/** Conditions communes. $cols : colonnes de l'alias pour ecole/circuit/chauffeur/vehicule/eleve. */
function rep_where(array $F, ?array $ecoles, string $dateExpr, array $cols, array &$params): string
{
    $w = ["$dateExpr BETWEEN ? AND ?"];
    array_push($params, $F['date_debut'], $F['date_fin']);
    if ($ecoles !== null && !empty($cols['ecole'])) {
        $w[] = authz_in($cols['ecole'], $ecoles, $params);
    }
    foreach (['circuit_id' => 'circuit', 'chauffeur_id' => 'chauffeur', 'vehicule_id' => 'vehicule', 'eleve_id' => 'eleve'] as $f => $c) {
        if (isset($F[$f])) {
            if (empty($cols[$c])) {
                // Filtre non applicable a cette source : elle est exclue plutot que melangee.
                $w[] = '1 = 0';
                continue;
            }
            $w[] = $cols[$c] . ' = ?';
            $params[] = $F[$f];
        }
    }
    if (isset($F['classe']) && !empty($cols['classe'])) {
        $w[] = $cols['classe'] . ' = ?';
        $params[] = $F['classe'];
    }
    return implode(' AND ', $w);
}

function rep_rows(PDO $pdo, string $sql, array $params): array
{
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** Agregat indexe par la colonne 'k'. */
function rep_map(PDO $pdo, string $sql, array $params): array
{
    $out = [];
    foreach (rep_rows($pdo, $sql, $params) as $r) {
        $out[(string) $r['k']] = $r;
    }
    return $out;
}

$tol = (int) shipp_param($pdo, 'retard_tolerance_minutes', '10');
// Sources (alias et colonnes filtrables).
$SRC = [
    'trajets' => ['from' => 'trajets t', 'date' => 't.date_trajet', 'cols' => ['ecole' => 't.ecole_id', 'circuit' => 't.circuit_id', 'chauffeur' => 't.chauffeur_id', 'vehicule' => 't.vehicle_id']],
    'passages' => ['from' => 'trajet_passages p JOIN trajets t ON t.id = p.trajet_id', 'date' => 't.date_trajet', 'cols' => ['ecole' => 't.ecole_id', 'circuit' => 't.circuit_id', 'chauffeur' => 't.chauffeur_id', 'vehicule' => 't.vehicle_id']],
    'events' => ['from' => 'transport_events e LEFT JOIN eleves el ON el.id = e.eleve_id', 'date' => 'DATE(e.survenu_at)', 'cols' => ['ecole' => 'e.ecole_id', 'circuit' => 'e.circuit_id', 'chauffeur' => 'e.chauffeur_id', 'vehicule' => 'e.vehicle_id', 'eleve' => 'e.eleve_id', 'classe' => 'el.classe']],
    'repas' => ['from' => "scans s JOIN eleves el ON el.id = s.eleve_id", 'date' => 'DATE(s.scanned_at)', 'cols' => ['ecole' => 's.ecole_id', 'eleve' => 's.eleve_id', 'classe' => 'el.classe']],
    'echeances' => ['from' => 'echeances_transport x JOIN eleves el ON el.id = x.eleve_id', 'date' => 'x.mois', 'cols' => ['ecole' => 'x.ecole_id', 'eleve' => 'x.eleve_id', 'classe' => 'el.classe']],
    'incidents' => ['from' => 'incidents i', 'date' => 'COALESCE(i.date_incident, DATE(i.created_at))', 'cols' => ['ecole' => 'i.ecole_id', 'circuit' => 'i.circuit_id', 'chauffeur' => 'i.chauffeur_id', 'vehicule' => 'i.vehicule_id', 'eleve' => 'i.eleve_id']],
];

/** FROM + WHERE d'une source, avec conditions supplementaires. */
function rep_src(array $SRC, string $src, array $F, ?array $ecoles, array &$params, array $extra = []): string
{
    $d = $SRC[$src];
    $w = rep_where($F, $ecoles, $d['date'], $d['cols'], $params);
    if ($src === 'events') {
        $w .= " AND e.statut = 'valide'";
    }
    if ($src === 'repas') {
        $w .= " AND s.type = 'cantine'";
    }
    if ($src === 'incidents') {
        foreach (['categorie', 'gravite', 'statut'] as $k) {
            if (isset($F[$k])) {
                $w .= " AND i.$k = ?";
                $params[] = $F[$k];
            }
        }
    }
    foreach ($extra as $x) {
        $w .= ' AND ' . $x;
    }
    return ' FROM ' . $d['from'] . ' WHERE ' . $w;
}

function rep_pct($num, $den): ?float
{
    return $den > 0 ? round(100 * $num / $den, 1) : null;
}

// ---------------------------------------------------------------- Drill-down
if (!empty($_GET['liste'])) {
    $liste = (string) $_GET['liste'];
    if (!isset(REP_LISTES[$liste])) {
        shipp_error(400, 'Liste inconnue');
    }
    $p = [];
    $nomCh = "TRIM(CONCAT(COALESCE(ch.prenom, ''), ' ', COALESCE(ch.nom, '')))";
    $nomEl = "TRIM(CONCAT(COALESCE(el.prenom, ''), ' ', COALESCE(el.nom, '')))";
    switch ($liste) {
        case 'trajets':
            $extra = [];
            if (isset($F['statut']) && in_array($F['statut'], ['planifie', 'en_cours', 'termine', 'annule'], true)) {
                $extra[] = 't.statut = ' . $pdo->quote($F['statut']);
            }
            $sql = "SELECT t.id, t.date_trajet, ci.nom AS circuit, t.sens, $nomCh AS chauffeur, v.immatriculation AS vehicule, t.statut, t.heure_debut, t.heure_fin, t.motif_annulation"
                . rep_src($SRC, 'trajets', $F, $ecoles, $p, $extra);
            $sql = str_replace(' FROM trajets t ', ' FROM trajets t LEFT JOIN circuits ci ON ci.id = t.circuit_id LEFT JOIN chauffeurs ch ON ch.id = t.chauffeur_id LEFT JOIN vehicules v ON v.id = t.vehicle_id ', $sql) . ' ORDER BY t.date_trajet, t.id';
            break;
        case 'retards':
            $sql = "SELECT p.id, t.date_trajet AS date, ci.nom AS circuit, et.nom AS arret, p.heure_prevue, p.heure_reelle, p.ecart_minutes, $nomCh AS chauffeur, t.id AS trajet_id"
                . rep_src($SRC, 'passages', $F, $ecoles, $p, ['p.ecart_minutes > ' . $tol]);
            $sql = str_replace(' JOIN trajets t ON t.id = p.trajet_id ', ' JOIN trajets t ON t.id = p.trajet_id LEFT JOIN circuits ci ON ci.id = t.circuit_id LEFT JOIN etapes et ON et.id = p.etape_id LEFT JOIN chauffeurs ch ON ch.id = t.chauffeur_id ', $sql) . ' ORDER BY p.heure_reelle';
            break;
        case 'embarquements':
        case 'absences':
            $type = $liste === 'embarquements' ? 'STUDENT_BOARDED' : 'STUDENT_ABSENT';
            $sql = "SELECT e.id, e.survenu_at, e.eleve_id, $nomEl AS eleve, el.classe, ci.nom AS circuit, et.nom AS arret, $nomCh AS chauffeur, e.details, e.trajet_id"
                . rep_src($SRC, 'events', $F, $ecoles, $p, ["e.type = '$type'"]);
            $sql = str_replace(' LEFT JOIN eleves el ON el.id = e.eleve_id ', ' LEFT JOIN eleves el ON el.id = e.eleve_id LEFT JOIN circuits ci ON ci.id = e.circuit_id LEFT JOIN etapes et ON et.id = e.etape_id LEFT JOIN chauffeurs ch ON ch.id = e.chauffeur_id ', $sql) . ' ORDER BY e.survenu_at';
            break;
        case 'repas':
            $sql = "SELECT s.id, s.scanned_at, s.eleve_id, $nomEl AS eleve, el.classe" . rep_src($SRC, 'repas', $F, $ecoles, $p) . ' ORDER BY s.scanned_at';
            break;
        case 'a_reverser':
        case 'impayes':
            if (!authz_can($ctx, 'echeances_transport', 'can_read')) {
                shipp_error(403, 'Suivi des paiements non autorise');
            }
            $extra = $liste === 'a_reverser' ? ['x.encaisse_enko = 1', 'x.recu_shipp = 0', 'x.arret_service = 0'] : ['x.encaisse_enko = 0', 'x.recu_shipp = 0', 'x.arret_service = 0', 'x.mois <= ' . $pdo->quote(date('Y-m-01'))];
            $sql = "SELECT x.id, DATE_FORMAT(x.mois, '%Y-%m') AS mois, x.eleve_id, $nomEl AS eleve, el.classe, x.montant, x.enko_at"
                . rep_src($SRC, 'echeances', $F, $ecoles, $p, $extra) . ' ORDER BY x.mois, eleve';
            break;
        case 'incidents':
            $sql = "SELECT i.id, COALESCE(i.survenu_at, i.date_incident) AS date, i.categorie, i.type, i.gravite, i.titre, $nomCh AS chauffeur, v.immatriculation AS vehicule, i.responsabilite, i.statut, i.cout_reel"
                . rep_src($SRC, 'incidents', $F, $ecoles, $p);
            $sql = str_replace(' FROM incidents i ', ' FROM incidents i LEFT JOIN chauffeurs ch ON ch.id = i.chauffeur_id LEFT JOIN vehicules v ON v.id = i.vehicule_id ', $sql) . ' ORDER BY date';
            break;
    }
    $rows = rep_rows($pdo, $sql . ' LIMIT 5000', $p);
    rep_sortie($pdo, $ctx, $userId, 'liste_' . $liste, REP_LISTES[$liste], $rows, $F, ['tronque' => count($rows) >= 5000]);
}

// ---------------------------------------------------------------- Rapports
$rapport = (string) ($_GET['rapport'] ?? 'synthese');
if (!isset(REP_COLONNES[$rapport])) {
    shipp_error(400, 'Rapport inconnu');
}
$rows = [];
$extraOut = [];

// Blocs d'agregats reutilises.
$aggTrajets = function (string $key) use ($pdo, $SRC, $F, $ecoles) {
    $p = [];
    return rep_map($pdo, "SELECT $key AS k, COUNT(*) AS trajets, SUM(CASE WHEN t.statut = 'termine' THEN 1 ELSE 0 END) AS termines,
        SUM(CASE WHEN t.statut = 'annule' THEN 1 ELSE 0 END) AS annules, COUNT(DISTINCT t.chauffeur_id) AS chauffeurs"
        . rep_src($SRC, 'trajets', $F, $ecoles, $p) . ($key === "'x'" ? '' : " GROUP BY $key"), $p);
};
$aggPassages = function (string $key) use ($pdo, $SRC, $F, $ecoles, $tol) {
    $p = [];
    return rep_map($pdo, "SELECT $key AS k, COUNT(*) AS passages, SUM(CASE WHEN p.ecart_minutes IS NOT NULL THEN 1 ELSE 0 END) AS mesures,
        SUM(CASE WHEN p.ecart_minutes > $tol THEN 1 ELSE 0 END) AS retards,
        AVG(CASE WHEN p.ecart_minutes > $tol THEN p.ecart_minutes END) AS retard_moyen"
        . rep_src($SRC, 'passages', $F, $ecoles, $p) . ($key === "'x'" ? '' : " GROUP BY $key"), $p);
};
$aggEvents = function (string $key) use ($pdo, $SRC, $F, $ecoles) {
    $p = [];
    return rep_map($pdo, "SELECT $key AS k, SUM(CASE WHEN e.type = 'STUDENT_BOARDED' THEN 1 ELSE 0 END) AS embarquements,
        SUM(CASE WHEN e.type = 'STUDENT_DROPPED' THEN 1 ELSE 0 END) AS deposes,
        SUM(CASE WHEN e.type = 'STUDENT_ABSENT' THEN 1 ELSE 0 END) AS absences,
        COUNT(DISTINCT CASE WHEN e.type = 'STUDENT_BOARDED' THEN e.eleve_id END) AS eleves_transportes,
        COUNT(DISTINCT CASE WHEN e.type = 'STUDENT_BOARDED' THEN DATE(e.survenu_at) END) AS jours_transport"
        . rep_src($SRC, 'events', $F, $ecoles, $p) . ($key === "'x'" ? '' : " GROUP BY $key"), $p);
};
$aggIncidents = function (string $key) use ($pdo, $SRC, $F, $ecoles) {
    $p = [];
    return rep_map($pdo, "SELECT $key AS k, SUM(CASE WHEN i.categorie = 'incident' THEN 1 ELSE 0 END) AS incidents,
        SUM(CASE WHEN i.categorie = 'accident' THEN 1 ELSE 0 END) AS accidents,
        SUM(CASE WHEN i.categorie = 'accident' AND i.responsabilite = 'chauffeur' AND i.qualifiee_at IS NOT NULL THEN 1 ELSE 0 END) AS accidents_imputes,
        SUM(COALESCE(i.cout_reel, 0)) AS cout_reel"
        . rep_src($SRC, 'incidents', $F, $ecoles, $p, ["i.statut <> 'annule'"]) . ($key === "'x'" ? '' : " GROUP BY $key"), $p);
};
$v = function (array $m, $k, string $c, $def = 0) {
    return isset($m[(string) $k][$c]) && $m[(string) $k][$c] !== null ? $m[(string) $k][$c] + 0 : $def;
};

switch ($rapport) {
    case 'synthese':
        $t = $aggTrajets("'x'")['x'] ?? [];
        $pa = $aggPassages("'x'")['x'] ?? [];
        $ev = $aggEvents("'x'")['x'] ?? [];
        $in = $aggIncidents("'x'")['x'] ?? [];
        $p = [];
        $repas = (int) (rep_rows($pdo, 'SELECT COUNT(*) AS n' . rep_src($SRC, 'repas', $F, $ecoles, $p), $p)[0]['n'] ?? 0);
        $mes = (int) ($pa['mesures'] ?? 0);
        $ret = (int) ($pa['retards'] ?? 0);
        $ind = [
            'trajets_prevus' => (int) ($t['trajets'] ?? 0), 'trajets_termines' => (int) ($t['termines'] ?? 0), 'trajets_annules' => (int) ($t['annules'] ?? 0),
            'taux_realisation' => rep_pct((int) ($t['termines'] ?? 0), (int) ($t['trajets'] ?? 0) - (int) ($t['annules'] ?? 0)),
            'arrets_desservis' => (int) ($pa['passages'] ?? 0), 'arrets_en_retard' => $ret, 'ponctualite' => rep_pct($mes - $ret, $mes),
            'retard_moyen' => isset($pa['retard_moyen']) ? round((float) $pa['retard_moyen'], 1) : null,
            'eleves_transportes' => (int) ($ev['eleves_transportes'] ?? 0), 'embarquements' => (int) ($ev['embarquements'] ?? 0),
            'absences' => (int) ($ev['absences'] ?? 0), 'repas_servis' => $repas,
            'incidents' => (int) ($in['incidents'] ?? 0), 'accidents' => (int) ($in['accidents'] ?? 0), 'cout_reel_incidents' => (float) ($in['cout_reel'] ?? 0),
        ];
        $libelles = ['trajets_prevus' => 'Trajets prevus', 'trajets_termines' => 'Trajets termines', 'trajets_annules' => 'Trajets annules', 'taux_realisation' => 'Taux de realisation (%)',
            'arrets_desservis' => 'Arrets desservis', 'arrets_en_retard' => "Arrets en retard (> $tol min)", 'ponctualite' => 'Ponctualite (%)', 'retard_moyen' => 'Retard moyen (min)',
            'eleves_transportes' => 'Eleves transportes', 'embarquements' => 'Embarquements', 'absences' => 'Absences signalees', 'repas_servis' => 'Repas servis',
            'incidents' => 'Incidents', 'accidents' => 'Accidents', 'cout_reel_incidents' => 'Cout reel incidents (FCFA)'];
        foreach ($ind as $k => $val) {
            $rows[] = ['cle' => $k, 'indicateur' => $libelles[$k], 'valeur' => $val];
        }
        $extraOut['indicateurs'] = $ind;
        break;

    case 'par_jour':
        $t = $aggTrajets('t.date_trajet');
        $pa = $aggPassages('t.date_trajet');
        $ev = $aggEvents('DATE(e.survenu_at)');
        $in = $aggIncidents('COALESCE(i.date_incident, DATE(i.created_at))');
        $p = [];
        $re = rep_map($pdo, 'SELECT DATE(s.scanned_at) AS k, COUNT(*) AS repas' . rep_src($SRC, 'repas', $F, $ecoles, $p) . ' GROUP BY DATE(s.scanned_at)', $p);
        $jours = array_unique(array_merge(array_keys($t), array_keys($pa), array_keys($ev), array_keys($in), array_keys($re)));
        sort($jours);
        foreach ($jours as $j) {
            $rows[] = ['jour' => substr($j, 0, 10), 'trajets' => $v($t, $j, 'trajets'), 'termines' => $v($t, $j, 'termines'), 'annules' => $v($t, $j, 'annules'),
                'embarquements' => $v($ev, $j, 'embarquements'), 'deposes' => $v($ev, $j, 'deposes'), 'absences' => $v($ev, $j, 'absences'),
                'retards' => $v($pa, $j, 'retards'), 'repas' => $v($re, $j, 'repas'), 'incidents' => $v($in, $j, 'incidents'), 'accidents' => $v($in, $j, 'accidents')];
        }
        break;

    case 'chauffeurs':
        $t = $aggTrajets('t.chauffeur_id');
        $pa = $aggPassages('t.chauffeur_id');
        $ev = $aggEvents('e.chauffeur_id');
        $in = $aggIncidents('i.chauffeur_id');
        $p = [];
        $w = ["c.statut = 'actif'"];
        if ($ecoles !== null) {
            $w[] = '(' . authz_in('c.ecole_id', $ecoles, $p) . ' OR c.ecole_id IS NULL)';
        }
        $actifs = array_column(rep_rows($pdo, 'SELECT c.id FROM chauffeurs c WHERE ' . implode(' AND ', $w), $p), 'id');
        $ids = array_filter(array_unique(array_merge(array_keys($t), array_keys($pa), array_keys($ev), array_keys($in), array_map('strval', $actifs))), function ($x) {
            return $x !== '' && ctype_digit((string) $x);
        });
        if (isset($F['chauffeur_id'])) {
            $ids = [(string) $F['chauffeur_id']];
        }
        $noms = [];
        $docs = [];
        if ($ids) {
            $p = [];
            foreach (rep_rows($pdo, "SELECT id, TRIM(CONCAT(COALESCE(prenom, ''), ' ', nom)) AS n FROM chauffeurs WHERE " . authz_in('id', array_map('intval', $ids), $p), $p) as $r) {
                $noms[(string) $r['id']] = $r['n'];
            }
            if (authz_can($ctx, 'chauffeur_documents', 'can_read')) {
                $p = [date('Y-m-d')];
                $docs = rep_map($pdo, "SELECT d.chauffeur_id AS k, COUNT(*) AS n FROM chauffeur_documents d WHERE d.statut IN ('valide', 'a_verifier') AND d.date_expiration < ? AND "
                    . authz_in('d.chauffeur_id', array_map('intval', $ids), $p)
                    . " AND NOT EXISTS (SELECT 1 FROM chauffeur_documents d2 WHERE d2.chauffeur_id = d.chauffeur_id AND d2.type = d.type AND d2.id > d.id AND d2.statut IN ('valide', 'a_verifier')) GROUP BY d.chauffeur_id", $p);
            }
        }
        foreach ($ids as $k) {
            $mes = $v($pa, $k, 'mesures');
            $ret = $v($pa, $k, 'retards');
            $rows[] = ['chauffeur_id' => (int) $k, 'chauffeur' => $noms[$k] ?? "Chauffeur #$k", 'trajets' => $v($t, $k, 'trajets'), 'termines' => $v($t, $k, 'termines'),
                'annules' => $v($t, $k, 'annules'), 'passages' => $v($pa, $k, 'passages'), 'retards' => $ret,
                'retard_moyen' => $v($pa, $k, 'retard_moyen', null) !== null ? round($v($pa, $k, 'retard_moyen'), 1) : null,
                'ponctualite' => rep_pct($mes - $ret, $mes), 'eleves_transportes' => $v($ev, $k, 'eleves_transportes'), 'embarquements' => $v($ev, $k, 'embarquements'),
                'absences' => $v($ev, $k, 'absences'), 'incidents' => $v($in, $k, 'incidents'), 'accidents' => $v($in, $k, 'accidents'),
                'accidents_imputes' => $v($in, $k, 'accidents_imputes'), 'documents_expires' => authz_can($ctx, 'chauffeur_documents', 'can_read') ? $v($docs, $k, 'n') : null];
        }
        // Trajets sans chauffeur attribue (non demarres).
        if (isset($t['']) && !isset($F['chauffeur_id'])) {
            $rows[] = ['chauffeur_id' => null, 'chauffeur' => 'Non attribue', 'trajets' => $v($t, '', 'trajets'), 'termines' => $v($t, '', 'termines'), 'annules' => $v($t, '', 'annules'),
                'passages' => $v($pa, '', 'passages'), 'retards' => $v($pa, '', 'retards'), 'retard_moyen' => null, 'ponctualite' => null, 'eleves_transportes' => $v($ev, '', 'eleves_transportes'),
                'embarquements' => $v($ev, '', 'embarquements'), 'absences' => $v($ev, '', 'absences'), 'incidents' => $v($in, '', 'incidents'), 'accidents' => $v($in, '', 'accidents'),
                'accidents_imputes' => 0, 'documents_expires' => null];
        }
        usort($rows, function ($a, $b) {
            return strcmp((string) $a['chauffeur'], (string) $b['chauffeur']);
        });
        $extraOut['note'] = "Aucun score n'est calcule. « Accidents qualifies chauffeur » ne compte que les accidents dont la responsabilite a ete qualifiee ainsi par un responsable.";
        break;

    case 'vehicules':
        $t = $aggTrajets('t.vehicle_id');
        $pa = $aggPassages('t.vehicle_id');
        $ev = $aggEvents('e.vehicle_id');
        $in = $aggIncidents('i.vehicule_id');
        $ids = array_filter(array_unique(array_merge(array_keys($t), array_keys($ev), array_keys($in))), 'strlen');
        $noms = [];
        if ($ids) {
            $p = [];
            foreach (rep_rows($pdo, 'SELECT id, immatriculation, modele FROM vehicules WHERE ' . authz_in('id', array_map('intval', $ids), $p), $p) as $r) {
                $noms[(string) $r['id']] = trim($r['immatriculation'] . ' ' . ($r['modele'] ?? ''));
            }
        }
        foreach ($ids as $k) {
            $rows[] = ['vehicule_id' => (int) $k, 'vehicule' => $noms[$k] ?? "Vehicule #$k", 'trajets' => $v($t, $k, 'trajets'), 'termines' => $v($t, $k, 'termines'),
                'chauffeurs' => $v($t, $k, 'chauffeurs'), 'embarquements' => $v($ev, $k, 'embarquements'), 'retards' => $v($pa, $k, 'retards'),
                'incidents' => $v($in, $k, 'incidents'), 'accidents' => $v($in, $k, 'accidents'), 'cout_reel' => $v($in, $k, 'cout_reel')];
        }
        usort($rows, function ($a, $b) {
            return strcmp($a['vehicule'], $b['vehicule']);
        });
        break;

    case 'circuits':
        $t = $aggTrajets('t.circuit_id');
        $pa = $aggPassages('t.circuit_id');
        $ev = $aggEvents('e.circuit_id');
        $in = $aggIncidents('i.circuit_id');
        $p = [];
        $aff = rep_map($pdo, "SELECT a.circuit_id AS k, COUNT(DISTINCT a.eleve_id) AS n FROM eleve_affectations_transport a WHERE a.statut = 'active'"
            . ($ecoles !== null ? ' AND (' . authz_in('a.ecole_id', $ecoles, $p) . ' OR a.ecole_id IS NULL)' : '') . ' GROUP BY a.circuit_id', $p);
        $ids = array_filter(array_unique(array_merge(array_keys($t), array_keys($ev), array_keys($in))), 'strlen');
        if (isset($F['circuit_id'])) {
            $ids = [(string) $F['circuit_id']];
        }
        $noms = [];
        if ($ids) {
            $p = [];
            foreach (rep_rows($pdo, 'SELECT id, nom FROM circuits WHERE ' . authz_in('id', array_map('intval', $ids), $p), $p) as $r) {
                $noms[(string) $r['id']] = $r['nom'];
            }
        }
        foreach ($ids as $k) {
            $mes = $v($pa, $k, 'mesures');
            $ret = $v($pa, $k, 'retards');
            $rows[] = ['circuit_id' => (int) $k, 'circuit' => $noms[$k] ?? "Circuit #$k", 'trajets' => $v($t, $k, 'trajets'), 'termines' => $v($t, $k, 'termines'),
                'annules' => $v($t, $k, 'annules'), 'eleves_affectes' => $v($aff, $k, 'n'), 'eleves_transportes' => $v($ev, $k, 'eleves_transportes'),
                'embarquements' => $v($ev, $k, 'embarquements'), 'absences' => $v($ev, $k, 'absences'), 'passages' => $v($pa, $k, 'passages'), 'retards' => $ret,
                'ponctualite' => rep_pct($mes - $ret, $mes), 'incidents' => $v($in, $k, 'incidents') + $v($in, $k, 'accidents')];
        }
        usort($rows, function ($a, $b) {
            return strcmp($a['circuit'], $b['circuit']);
        });
        break;

    case 'eleves':
        $ev = $aggEvents('e.eleve_id');
        $p = [];
        $re = rep_map($pdo, 'SELECT s.eleve_id AS k, COUNT(*) AS repas' . rep_src($SRC, 'repas', $F, $ecoles, $p) . ' GROUP BY s.eleve_id', $p);
        $p = [];
        $ie = rep_map($pdo, 'SELECT ie.eleve_id AS k, COUNT(DISTINCT ie.incident_id) AS n FROM incident_eleves ie JOIN incidents i ON i.id = ie.incident_id WHERE '
            . rep_where($F, $ecoles, $SRC['incidents']['date'], ['ecole' => 'i.ecole_id', 'eleve' => 'ie.eleve_id'], $p) . " AND i.statut <> 'annule' GROUP BY ie.eleve_id", $p);
        $ids = array_filter(array_unique(array_merge(array_keys($ev), array_keys($re), array_keys($ie))), 'strlen');
        if ($ids) {
            $p = [];
            $info = rep_map($pdo, "SELECT e.id AS k, TRIM(CONCAT(e.prenom, ' ', e.nom)) AS nom, e.classe, c.nom AS circuit FROM eleves e LEFT JOIN circuits c ON c.id = e.circuit_id WHERE "
                . authz_in('e.id', array_map('intval', $ids), $p), $p);
            foreach ($ids as $k) {
                if (isset($F['classe']) && ($info[$k]['classe'] ?? null) !== $F['classe']) {
                    continue;
                }
                $rows[] = ['eleve_id' => (int) $k, 'eleve' => $info[$k]['nom'] ?? "Eleve #$k", 'classe' => $info[$k]['classe'] ?? '', 'circuit' => $info[$k]['circuit'] ?? '',
                    'jours_transport' => $v($ev, $k, 'jours_transport'), 'embarquements' => $v($ev, $k, 'embarquements'), 'absences' => $v($ev, $k, 'absences'),
                    'repas' => $v($re, $k, 'repas'), 'incidents' => $v($ie, $k, 'n')];
            }
        }
        usort($rows, function ($a, $b) {
            return strcmp($a['classe'] . $a['eleve'], $b['classe'] . $b['eleve']);
        });
        break;

    case 'paiements':
        if (!authz_can($ctx, 'echeances_transport', 'can_read')) {
            shipp_error(403, 'Suivi des paiements non autorise');
        }
        $p = [];
        // Periode : mois dont le 1er tombe entre les deux dates (mois de la date de debut inclus).
        $F2 = $F;
        $F2['date_debut'] = date('Y-m-01', strtotime($F['date_debut']));
        foreach (rep_rows($pdo, "SELECT DATE_FORMAT(x.mois, '%Y-%m') AS mois, COUNT(DISTINCT x.eleve_id) AS eleves,
                SUM(CASE WHEN x.arret_service = 0 THEN COALESCE(x.montant, 0) ELSE 0 END) AS du,
                SUM(CASE WHEN x.arret_service = 0 AND x.encaisse_enko = 1 THEN COALESCE(x.montant, 0) ELSE 0 END) AS enko,
                SUM(CASE WHEN x.arret_service = 0 AND x.recu_shipp = 1 THEN COALESCE(x.montant, 0) ELSE 0 END) AS shipp,
                SUM(CASE WHEN x.arret_service = 0 AND x.encaisse_enko = 1 AND x.recu_shipp = 0 THEN COALESCE(x.montant, 0) ELSE 0 END) AS a_reverser,
                SUM(CASE WHEN x.arret_service = 0 AND x.encaisse_enko = 0 AND x.recu_shipp = 0 THEN COALESCE(x.montant, 0) ELSE 0 END) AS impaye,
                SUM(x.arret_service) AS arrets"
            . rep_src($SRC, 'echeances', $F2, $ecoles, $p) . " GROUP BY DATE_FORMAT(x.mois, '%Y-%m') ORDER BY mois", $p) as $r) {
            foreach (['eleves', 'arrets'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            foreach (['du', 'enko', 'shipp', 'a_reverser', 'impaye'] as $k) {
                $r[$k] = (float) $r[$k];
            }
            $rows[] = $r;
        }
        $extraOut['note'] = "« Enko » : l'etablissement a encaisse le mois ; « SHIPP » : SHIPP l'a recu. « Ni Enko ni SHIPP » inclut les mois a venir.";
        break;

    case 'incidents':
        foreach (['categorie' => 'Nature', 'type' => 'Type', 'gravite' => 'Gravite', 'responsabilite' => 'Responsabilite', 'statut' => 'Statut'] as $col => $lib) {
            $p = [];
            foreach (rep_rows($pdo, "SELECT i.$col AS valeur, COUNT(*) AS nombre, SUM(COALESCE(i.cout_estime, 0)) AS cout_estime, SUM(COALESCE(i.cout_reel, 0)) AS cout_reel"
                . rep_src($SRC, 'incidents', $F, $ecoles, $p) . " GROUP BY i.$col ORDER BY nombre DESC", $p) as $r) {
                $rows[] = ['dimension' => $lib, 'valeur' => $r['valeur'], 'nombre' => (int) $r['nombre'], 'cout_estime' => (float) $r['cout_estime'], 'cout_reel' => (float) $r['cout_reel']];
            }
        }
        $extraOut['note'] = 'Les incidents annules restent comptes dans la dimension Statut.';
        break;
}

rep_sortie($pdo, $ctx, $userId, $rapport, REP_COLONNES[$rapport], $rows, $F, $extraOut);

// ---------------------------------------------------------------- Sortie JSON / CSV
function rep_sortie(PDO $pdo, array $ctx, int $userId, string $nom, array $colonnes, array $rows, array $F, array $extra): void
{
    $format = (string) ($_GET['format'] ?? 'json');
    if ($format === 'csv') {
        if (!authz_can($ctx, 'reporting', 'can_export')) {
            shipp_error(403, 'Export non autorise');
        }
        try {
            $pdo->prepare('INSERT INTO export_journal (user_id, ecole_id, rapport, format, filtres, nb_lignes) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $F['ecole_id'] ?? ($ctx['ecole_ids'][0] ?? null), $nom, 'csv', json_encode($F), count($rows)]);
        } catch (Throwable $e) {
            // la table peut manquer si la migration 004 n'est pas appliquee : export refuse pour garder la trace
            shipp_error(500, 'Journal des exports indisponible');
        }
        shipp_journal($pdo, $ctx['ecole_ids'][0] ?? null, $userId, 'create', 'export', null, "Export $nom {$F['date_debut']} -> {$F['date_fin']} (" . count($rows) . ' lignes)');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="shipp_' . $nom . '_' . $F['date_debut'] . '_' . $F['date_fin'] . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fwrite($out, 'Periode du ' . $F['date_debut'] . ' au ' . $F['date_fin'] . "\r\n");
        fputcsv($out, array_values($colonnes), ';');
        foreach ($rows as $r) {
            $ligne = [];
            foreach (array_keys($colonnes) as $k) {
                $val = $r[$k] ?? '';
                if (is_float($val)) {
                    $val = str_replace('.', ',', (string) $val);
                }
                // Neutralise les formules dans les tableurs.
                if (is_string($val) && $val !== '' && in_array($val[0], ['=', '+', '-', '@'], true) && !is_numeric($val)) {
                    $val = "'" . $val;
                }
                $ligne[] = $val;
            }
            fputcsv($out, $ligne, ';');
        }
        fclose($out);
        exit;
    }
    echo json_encode(array_merge(['rapport' => $nom, 'filtres' => $F, 'colonnes' => $colonnes, 'data' => $rows,
        'export' => authz_can($ctx, 'reporting', 'can_export')], $extra));
    exit;
}
