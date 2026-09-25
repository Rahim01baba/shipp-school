<?php
/**
 * Retenues chiffrees sur la remuneration du chauffeur (lot 5, D-30).
 * Donnees sensibles : reservees aux perimetres GLOBAL / SCHOOL du module
 * chauffeur_contracts (admin, RH), comme la remuneration.
 *
 * GET  ?chauffeur_id=N            : retenues du chauffeur
 * GET  ?etat=AAAA-MM              : etat mensuel (remuneration du contrat en vigueur,
 *                                   retenues validees du mois, net)
 * POST {chauffeur_id, periode: 'AAAA-MM', montant, motif, date_fait?, incident_id?, contract_id?}
 *      -> retenue 'proposee' (can_edit). Liee a un incident, celui-ci doit concerner
 *         le chauffeur et avoir ete QUALIFIE « chauffeur » ou « partagee » par un
 *         responsable : la retenue ne deduit jamais la responsabilite.
 * PUT  {id, action: 'valider'}             (can_validate)
 *      {id, action: 'annuler', motif}      (can_validate)
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
authz_require($ctx, 'chauffeur_contracts', 'can_read');
if (!in_array(authz_scope($ctx, 'chauffeur_contracts'), ['GLOBAL', 'SCHOOL'], true)) {
    shipp_error(403, 'Retenues reservees aux gestionnaires des contrats');
}
$cond = authz_scope_condition($pdo, $ctx, 'chauffeurs', 'c.');
if ($cond === null) {
    shipp_error(403, 'Acces refuse');
}

function rt_mois($v): ?string
{
    return is_string($v) && preg_match('/^(\d{4})-(\d{2})$/', $v, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12 ? "$m[1]-$m[2]-01" : null;
}

if ($method === 'GET' && !empty($_GET['etat'])) {
    $mois = rt_mois((string) $_GET['etat']);
    if (!$mois) {
        shipp_error(400, 'Mois invalide (AAAA-MM)');
    }
    $fin = date('Y-m-t', strtotime($mois));
    $s = $pdo->prepare(
        "SELECT c.id AS chauffeur_id, TRIM(CONCAT(COALESCE(c.prenom, ''), ' ', c.nom)) AS chauffeur, k.id AS contract_id, k.reference,
                k.remuneration_montant, k.remuneration_periodicite, k.remuneration_devise,
                (SELECT COALESCE(SUM(r.montant), 0) FROM chauffeur_retenues r WHERE r.chauffeur_id = c.id AND r.periode = ? AND r.statut = 'validee') AS retenues,
                (SELECT COUNT(*) FROM chauffeur_retenues r WHERE r.chauffeur_id = c.id AND r.periode = ? AND r.statut = 'proposee') AS en_attente
         FROM chauffeurs c
         JOIN chauffeur_contracts k ON k.chauffeur_id = c.id AND k.statut IN ('actif', 'suspendu', 'termine', 'resilie')
              AND k.date_debut <= ? AND (COALESCE(k.resilie_le, k.date_fin) IS NULL OR COALESCE(k.resilie_le, k.date_fin) >= ?)
         WHERE (" . $cond[0] . ') ORDER BY chauffeur'
    );
    $s->execute(array_merge([$mois, $mois, $fin, $mois], $cond[1]));
    $rows = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $brut = $r['remuneration_montant'] !== null ? (float) $r['remuneration_montant'] : null;
        $r['retenues'] = (float) $r['retenues'];
        $r['net'] = $brut !== null ? max(0, $brut - $r['retenues']) : null;
        $r['depassement'] = $brut !== null && $r['retenues'] > $brut;
        $rows[] = $r;
    }
    echo json_encode(['mois' => substr($mois, 0, 7), 'data' => $rows]);
    exit;
}

if ($method === 'GET') {
    $chauffeurId = (int) ($_GET['chauffeur_id'] ?? 0);
    if (!$chauffeurId || !authz_row_allowed($pdo, $ctx, 'chauffeurs', $chauffeurId)) {
        shipp_error(404, 'Chauffeur introuvable');
    }
    $s = $pdo->prepare(
        'SELECT r.*, i.titre AS incident_titre, k.reference AS contrat_reference, u.name AS validee_par_nom
         FROM chauffeur_retenues r LEFT JOIN incidents i ON i.id = r.incident_id
         LEFT JOIN chauffeur_contracts k ON k.id = r.contract_id LEFT JOIN users u ON u.id = r.validee_par
         WHERE r.chauffeur_id = ? ORDER BY r.periode DESC, r.id DESC'
    );
    $s->execute([$chauffeurId]);
    echo json_encode(['data' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = shipp_json_input();

if ($method === 'POST') {
    authz_require($ctx, 'chauffeur_contracts', 'can_edit');
    $chauffeurId = (int) ($input['chauffeur_id'] ?? 0);
    $periode = rt_mois((string) ($input['periode'] ?? ''));
    $montant = $input['montant'] ?? null;
    $motif = trim((string) ($input['motif'] ?? ''));
    if (!$chauffeurId || !authz_row_allowed($pdo, $ctx, 'chauffeurs', $chauffeurId)) {
        shipp_error(404, 'Chauffeur introuvable');
    }
    if (!$periode || !is_numeric($montant) || (float) $montant <= 0 || $motif === '') {
        shipp_error(400, 'Periode (AAAA-MM), montant positif et motif requis');
    }
    $contractId = !empty($input['contract_id']) ? (int) $input['contract_id'] : null;
    if ($contractId) {
        $k = $pdo->prepare('SELECT id FROM chauffeur_contracts WHERE id = ? AND chauffeur_id = ?');
        $k->execute([$contractId, $chauffeurId]);
        if (!$k->fetch()) {
            shipp_error(400, 'Contrat d\'un autre chauffeur');
        }
    } else {
        $k = $pdo->prepare("SELECT id FROM chauffeur_contracts WHERE chauffeur_id = ? AND statut IN ('actif', 'suspendu') ORDER BY date_debut DESC LIMIT 1");
        $k->execute([$chauffeurId]);
        $contractId = $k->fetchColumn() ?: null;
    }
    $incidentId = !empty($input['incident_id']) ? (int) $input['incident_id'] : null;
    if ($incidentId) {
        $i = $pdo->prepare('SELECT chauffeur_id, responsabilite, qualifiee_at FROM incidents WHERE id = ?');
        $i->execute([$incidentId]);
        $inc = $i->fetch(PDO::FETCH_ASSOC);
        if (!$inc || (int) $inc['chauffeur_id'] !== $chauffeurId) {
            shipp_error(400, 'Incident introuvable pour ce chauffeur');
        }
        if (!$inc['qualifiee_at'] || !in_array($inc['responsabilite'], ['chauffeur', 'partagee'], true)) {
            shipp_error(409, "Incident non qualifie « chauffeur » ou « partagee » : la retenue ne peut pas s'y rattacher");
        }
    }
    $e = $pdo->prepare('SELECT ecole_id FROM chauffeurs WHERE id = ?');
    $e->execute([$chauffeurId]);
    $ecoleId = $e->fetchColumn() ?: null;
    $dateFait = is_string($input['date_fait'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date_fait']) ? $input['date_fait'] : null;
    $pdo->prepare('INSERT INTO chauffeur_retenues (ecole_id, chauffeur_id, contract_id, incident_id, periode, date_fait, motif, montant, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ecoleId, $chauffeurId, $contractId, $incidentId, $periode, $dateFait, mb_substr($motif, 0, 255), round((float) $montant, 2), $userId]);
    $id = (int) $pdo->lastInsertId();
    shipp_journal($pdo, $ecoleId ? (int) $ecoleId : null, $userId, 'create', 'chauffeur_retenues', $id, 'Retenue proposee ' . substr($periode, 0, 7));
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($method === 'PUT') {
    authz_require($ctx, 'chauffeur_contracts', 'can_validate');
    $id = (int) ($input['id'] ?? 0);
    $s = $pdo->prepare('SELECT * FROM chauffeur_retenues WHERE id = ?');
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r || !authz_row_allowed($pdo, $ctx, 'chauffeurs', (int) $r['chauffeur_id'])) {
        shipp_error(404, 'Retenue introuvable');
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'valider') {
        if ($r['statut'] !== 'proposee') {
            shipp_error(409, 'Seule une retenue proposee peut etre validee');
        }
        $pdo->prepare("UPDATE chauffeur_retenues SET statut = 'validee', validee_par = ?, validee_at = NOW() WHERE id = ?")->execute([$userId, $id]);
    } elseif ($action === 'annuler') {
        $motif = trim((string) ($input['motif'] ?? ''));
        if ($motif === '' || $r['statut'] === 'annulee') {
            shipp_error(400, 'Motif d\'annulation obligatoire');
        }
        $pdo->prepare("UPDATE chauffeur_retenues SET statut = 'annulee', motif_annulation = ? WHERE id = ?")->execute([mb_substr($motif, 0, 255), $id]);
    } else {
        shipp_error(400, 'Action inconnue');
    }
    shipp_journal($pdo, $r['ecole_id'] !== null ? (int) $r['ecole_id'] : null, $userId, 'update', 'chauffeur_retenues', $id, "Retenue : $action");
    echo json_encode(['success' => true]);
    exit;
}

shipp_error(405, 'Methode non autorisee');
