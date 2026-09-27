<?php
/**
 * Suivi mensuel des paiements transport (lot 5, D-25).
 * Principe metier (ENKO) : le montant est mensuel. Les parents reglent souvent
 * l'annee entiere a l'etablissement ; l'etablissement reverse a SHIPP mois par
 * mois. Pour chaque eleve et chaque mois :
 *   - encaisse_enko = l'etablissement a recu l'argent du mois (« Enko ») ;
 *   - recu_shipp    = SHIPP a recu le mois (« Shipp ») ;
 *   - ni l'un ni l'autre = « Non » ; arret_service = « Arret S/c ».
 * Les mois affiches sont ceux de l'annee scolaire (date de debut -> date de fin,
 * reglables dans Annees scolaires : septembre -> juin par defaut, modifiable).
 *
 * GET  [?annee_scolaire_id=N] [&eleve_id=N] [&q=nom]
 * POST {action: 'generer', annee_scolaire_id?}   cree les mois manquants des abonnements transport actifs
 * PUT  {eleve_id, mois: 'AAAA-MM', encaisse_enko?, recu_shipp?, arret_service?, montant?, commentaire?}
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, POST, PUT, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
$userId = (int) $authUser['sub'];
$method = $_SERVER['REQUEST_METHOD'];
$flag = ['GET' => 'can_read', 'POST' => 'can_create', 'PUT' => 'can_edit'][$method] ?? null;
if (!$flag) {
    shipp_error(405, 'Methode non autorisee');
}
authz_require($ctx, 'echeances_transport', $flag);
$scope = authz_scope($ctx, 'echeances_transport');
$condEleves = authz_scope_condition($pdo, $ctx, 'echeances_transport', 'e.');
// La condition du module porte sur eleve_id / ecole_id : on l'applique aux eleves.
$condEleves = $condEleves === null ? null : [str_replace(['e.eleve_id', 'e.ecole_id'], ['e.id', 'e.ecole_id'], $condEleves[0]), $condEleves[1]];
if ($condEleves === null) {
    shipp_error(403, 'Acces refuse pour ce module');
}

/** Mois (1er du mois) couverts par l'annee scolaire. */
function et_mois_annee(array $annee): array
{
    $out = [];
    $d = strtotime(date('Y-m-01', strtotime($annee['date_debut'])));
    $fin = strtotime(date('Y-m-01', strtotime($annee['date_fin'])));
    while ($d <= $fin && count($out) < 24) {
        $out[] = date('Y-m-01', $d);
        $d = strtotime('+1 month', $d);
    }
    return $out;
}

function et_annee(PDO $pdo, $id): array
{
    $id = $id ? (int) $id : shipp_annee_active($pdo);
    $s = $pdo->prepare('SELECT id, libelle, date_debut, date_fin, statut FROM annees_scolaires WHERE id = ?');
    $s->execute([$id]);
    $a = $s->fetch(PDO::FETCH_ASSOC);
    if (!$a) {
        shipp_error(400, 'Annee scolaire introuvable');
    }
    return $a;
}

if ($method === 'GET') {
    $annee = et_annee($pdo, $_GET['annee_scolaire_id'] ?? null);
    $mois = et_mois_annee($annee);
    $where = ['(' . $condEleves[0] . ')'];
    $params = $condEleves[1];
    $where[] = "(EXISTS (SELECT 1 FROM echeances_transport x WHERE x.eleve_id = e.id AND x.annee_scolaire_id = ?)
                 OR EXISTS (SELECT 1 FROM abonnements ab WHERE ab.eleve_id = e.id AND ab.type = 'transport' AND ab.annee_scolaire_id = ?))";
    array_push($params, (int) $annee['id'], (int) $annee['id']);
    if (!empty($_GET['eleve_id'])) {
        $where[] = 'e.id = ?';
        $params[] = (int) $_GET['eleve_id'];
    }
    if (!empty($_GET['q'])) {
        $where[] = "CONCAT(e.nom, ' ', e.prenom) LIKE ?";
        $params[] = '%' . $_GET['q'] . '%';
    }
    $s = $pdo->prepare(
        "SELECT e.id, e.nom, e.prenom, e.classe, e.campus, ab.id AS abonnement_id, ab.statut AS abonnement_statut, ab.zone_tarifaire, ab.montant_mensuel, ab.periodicite
         FROM eleves e LEFT JOIN abonnements ab ON ab.eleve_id = e.id AND ab.type = 'transport' AND ab.annee_scolaire_id = ?
         WHERE " . implode(' AND ', $where) . ' ORDER BY e.nom, e.prenom LIMIT 2000'
    );
    $s->execute(array_merge([(int) $annee['id']], $params));
    $eleves = $s->fetchAll(PDO::FETCH_ASSOC);
    $cells = [];
    if ($eleves) {
        $p = [(int) $annee['id']];
        $in = authz_in('eleve_id', array_map(function ($e) { return (int) $e['id']; }, $eleves), $p);
        $c = $pdo->prepare("SELECT id, eleve_id, mois, montant, encaisse_enko, enko_at, recu_shipp, shipp_at, arret_service, commentaire, source
                            FROM echeances_transport WHERE annee_scolaire_id = ? AND $in");
        $c->execute($p);
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cells[(int) $r['eleve_id']][substr($r['mois'], 0, 7)] = $r;
        }
    }
    $totaux = [];
    $moisCourant = date('Y-m');
    foreach ($mois as $m) {
        $totaux[substr($m, 0, 7)] = ['du' => 0.0, 'enko' => 0.0, 'shipp' => 0.0, 'a_reverser' => 0.0, 'impaye' => 0.0];
    }
    foreach ($eleves as &$e) {
        $e['mois'] = $cells[(int) $e['id']] ?? new stdClass();
        foreach ($cells[(int) $e['id']] ?? [] as $k => $r) {
            if (!isset($totaux[$k]) || $r['arret_service']) {
                continue;
            }
            $mt = (float) ($r['montant'] ?? 0);
            $totaux[$k]['du'] += $mt;
            $totaux[$k]['enko'] += $r['encaisse_enko'] ? $mt : 0;
            $totaux[$k]['shipp'] += $r['recu_shipp'] ? $mt : 0;
            $totaux[$k]['a_reverser'] += ($r['encaisse_enko'] && !$r['recu_shipp']) ? $mt : 0;
            $totaux[$k]['impaye'] += (!$r['encaisse_enko'] && !$r['recu_shipp'] && $k <= $moisCourant) ? $mt : 0;
        }
    }
    unset($e);
    echo json_encode([
        'annee' => $annee, 'mois' => array_map(function ($m) { return substr($m, 0, 7); }, $mois),
        'data' => $eleves, 'totaux' => $totaux,
        'droits' => ['modifier' => authz_can($ctx, 'echeances_transport', 'can_edit') && in_array($scope, ['GLOBAL', 'SCHOOL'], true),
            'generer' => authz_can($ctx, 'echeances_transport', 'can_create') && in_array($scope, ['GLOBAL', 'SCHOOL'], true)],
    ]);
    exit;
}

if (!in_array($scope, ['GLOBAL', 'SCHOOL'], true)) {
    shipp_error(403, 'Modification reservee a l\'administration');
}
$input = shipp_json_input();

if ($method === 'POST') {
    if (($input['action'] ?? '') !== 'generer') {
        shipp_error(400, 'Action inconnue');
    }
    $annee = et_annee($pdo, $input['annee_scolaire_id'] ?? null);
    $mois = et_mois_annee($annee);
    $p = [(int) $annee['id']];
    $s = $pdo->prepare(
        "SELECT ab.id, ab.eleve_id, ab.ecole_id, ab.date_debut, ab.date_fin, ab.montant_mensuel, ab.zone_tarifaire,
                (SELECT t.montant_mensuel FROM tarifs t WHERE t.annee_scolaire_id = ab.annee_scolaire_id AND t.service = 'transport' AND t.zone = ab.zone_tarifaire
                  AND (t.ecole_id = ab.ecole_id OR t.ecole_id IS NULL) ORDER BY t.ecole_id IS NULL LIMIT 1) AS tarif
         FROM abonnements ab JOIN eleves e ON e.id = ab.eleve_id
         WHERE ab.type = 'transport' AND ab.statut = 'actif' AND ab.annee_scolaire_id = ? AND (" . $condEleves[0] . ')'
    );
    $s->execute(array_merge($p, $condEleves[1]));
    $ins = $pdo->prepare('INSERT IGNORE INTO echeances_transport (ecole_id, annee_scolaire_id, eleve_id, abonnement_id, mois, montant, source, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, \'generation\', ?, NOW())');
    $crees = 0;
    $sansMontant = 0;
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $ab) {
        $montant = $ab['montant_mensuel'] !== null ? (float) $ab['montant_mensuel'] : ($ab['tarif'] !== null ? (float) $ab['tarif'] : null);
        if ($montant === null) {
            $sansMontant++;
        }
        foreach ($mois as $m) {
            if (($ab['date_debut'] && $m < date('Y-m-01', strtotime($ab['date_debut']))) || ($ab['date_fin'] && $m > $ab['date_fin'])) {
                continue;
            }
            $ins->execute([$ab['ecole_id'], (int) $annee['id'], (int) $ab['eleve_id'], (int) $ab['id'], $m, $montant, $userId]);
            $crees += $ins->rowCount();
        }
    }
    shipp_journal($pdo, null, $userId, 'create', 'echeances_transport', null, "$crees mois generes ({$annee['libelle']})");
    echo json_encode(['success' => true, 'crees' => $crees, 'abonnements_sans_montant' => $sansMontant]);
    exit;
}

// PUT : mise a jour d'une case (eleve x mois), avec historique champ par champ.
$eleveId = (int) ($input['eleve_id'] ?? 0);
$m = is_string($input['mois'] ?? null) && preg_match('/^\d{4}-\d{2}$/', $input['mois']) ? $input['mois'] . '-01' : null;
if (!$eleveId || !$m || !authz_row_allowed($pdo, $ctx, 'eleves', $eleveId)) {
    shipp_error(400, 'Eleve et mois (AAAA-MM) requis');
}
$a = $pdo->prepare('SELECT id FROM annees_scolaires WHERE ? BETWEEN DATE_FORMAT(date_debut, \'%Y-%m-01\') AND date_fin ORDER BY statut = \'active\' DESC, id DESC LIMIT 1');
$a->execute([$m]);
$anneeId = $a->fetchColumn() ?: null;
if (!$anneeId) {
    shipp_error(400, 'Ce mois n\'appartient a aucune annee scolaire');
}
$s = $pdo->prepare('SELECT * FROM echeances_transport WHERE eleve_id = ? AND mois = ?');
$s->execute([$eleveId, $m]);
$row = $s->fetch(PDO::FETCH_ASSOC);
$pdo->beginTransaction();
if (!$row) {
    $e = $pdo->prepare('SELECT ecole_id FROM eleves WHERE id = ?');
    $e->execute([$eleveId]);
    $ab = $pdo->prepare("SELECT id, montant_mensuel FROM abonnements WHERE eleve_id = ? AND type = 'transport' AND annee_scolaire_id = ? LIMIT 1");
    $ab->execute([$eleveId, $anneeId]);
    $abo = $ab->fetch(PDO::FETCH_ASSOC) ?: ['id' => null, 'montant_mensuel' => null];
    $pdo->prepare('INSERT INTO echeances_transport (ecole_id, annee_scolaire_id, eleve_id, abonnement_id, mois, montant, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$e->fetchColumn() ?: null, $anneeId, $eleveId, $abo['id'], $m, $abo['montant_mensuel'], $userId]);
    $s->execute([$eleveId, $m]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
}
$sets = [];
$vals = [];
$hist = [];
$today = date('Y-m-d');
foreach (['encaisse_enko' => 'enko_at', 'recu_shipp' => 'shipp_at', 'arret_service' => null] as $k => $dateCol) {
    if (array_key_exists($k, $input)) {
        $v = !empty($input[$k]) ? 1 : 0;
        if ((int) $row[$k] !== $v) {
            $sets[] = "$k = ?";
            $vals[] = $v;
            if ($dateCol) {
                $sets[] = "$dateCol = ?";
                $vals[] = $v ? (is_string($input[$dateCol] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input[$dateCol]) ? $input[$dateCol] : $today) : null;
            }
            $hist[] = [$k, (string) $row[$k], (string) $v];
        }
    }
}
if (array_key_exists('montant', $input)) {
    $mt = $input['montant'] === '' || $input['montant'] === null ? null : (is_numeric($input['montant']) && (float) $input['montant'] >= 0 ? round((float) $input['montant'], 2) : false);
    if ($mt === false) {
        $pdo->rollBack();
        shipp_error(400, 'Montant invalide');
    }
    if ((string) $row['montant'] !== (string) $mt && !((float) $row['montant'] === (float) $mt && $mt !== null && $row['montant'] !== null)) {
        $sets[] = 'montant = ?';
        $vals[] = $mt;
        $hist[] = ['montant', (string) $row['montant'], (string) $mt];
    }
}
if (array_key_exists('commentaire', $input)) {
    $sets[] = 'commentaire = ?';
    $vals[] = trim((string) $input['commentaire']) !== '' ? mb_substr(trim((string) $input['commentaire']), 0, 255) : null;
}
if ($sets) {
    $vals[] = $userId;
    $vals[] = (int) $row['id'];
    $pdo->prepare('UPDATE echeances_transport SET ' . implode(', ', $sets) . ', updated_by = ?, updated_at = NOW() WHERE id = ?')->execute($vals);
    $h = $pdo->prepare('INSERT INTO echeances_historique (echeance_id, champ, ancienne_valeur, nouvelle_valeur, user_id) VALUES (?, ?, ?, ?, ?)');
    foreach ($hist as [$champ, $old, $new]) {
        $h->execute([(int) $row['id'], $champ, $old, $new, $userId]);
    }
}
$pdo->commit();
shipp_journal($pdo, $row['ecole_id'] !== null ? (int) $row['ecole_id'] : null, $userId, 'update', 'echeances_transport', (int) $row['id'], 'Mois ' . substr($m, 0, 7) . ' : ' . implode(', ', array_column($hist, 0)));
echo json_encode(['success' => true, 'id' => (int) $row['id']]);
