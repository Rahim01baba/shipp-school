<?php
/**
 * Affectations chauffeur <-> vehicule, datees et historisees.
 * GET  ?chauffeur_id=N | ?vehicle_id=N : historique
 * POST {chauffeur_id, vehicle_id, date_debut, date_fin?, contract_id?, motif?}
 *      La precedente affectation active du chauffeur est cloturee la veille.
 *      Refus si le vehicule est deja affecte a un autre chauffeur sur la periode.
 * PUT  {id, action: 'terminer'|'annuler', date_fin?}
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, POST, PUT, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
$method = $_SERVER['REQUEST_METHOD'];
$flag = ['GET' => 'can_read', 'POST' => 'can_create', 'PUT' => 'can_edit'][$method] ?? null;
if (!$flag) {
    shipp_error(405, 'Methode non autorisee');
}
authz_require($ctx, 'vehicle_assignments', $flag);
$cond = authz_scope_condition($pdo, $ctx, 'vehicle_assignments', 'va.');
if ($cond === null) {
    shipp_error(403, 'Acces refuse pour ce module');
}

function va_valid_date($d): bool
{
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

if ($method === 'GET') {
    $where = ['(' . $cond[0] . ')'];
    $params = $cond[1];
    foreach (['chauffeur_id', 'vehicle_id'] as $k) {
        if (!empty($_GET[$k])) {
            $where[] = "va.$k = ?";
            $params[] = (int) $_GET[$k];
        }
    }
    $s = $pdo->prepare(
        'SELECT va.*, v.immatriculation, v.modele, TRIM(CONCAT(COALESCE(c.prenom, \'\'), \' \', c.nom)) AS chauffeur_nom
         FROM vehicle_assignments va
         JOIN vehicules v ON v.id = va.vehicle_id
         JOIN chauffeurs c ON c.id = va.chauffeur_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY va.date_debut DESC, va.id DESC'
    );
    $s->execute($params);
    echo json_encode(['data' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = shipp_json_input();

if ($method === 'POST') {
    $chauffeurId = (int) ($input['chauffeur_id'] ?? 0);
    $vehicleId = (int) ($input['vehicle_id'] ?? 0);
    $debut = $input['date_debut'] ?? date('Y-m-d');
    $fin = ($input['date_fin'] ?? '') ?: null;
    if (!$chauffeurId || !$vehicleId || !va_valid_date($debut) || ($fin !== null && (!va_valid_date($fin) || $fin < $debut))) {
        shipp_error(400, 'chauffeur_id, vehicle_id et dates valides requis');
    }
    if (!authz_row_allowed($pdo, $ctx, 'chauffeurs', $chauffeurId) || !authz_row_allowed($pdo, $ctx, 'vehicules', $vehicleId)) {
        shipp_error(404, 'Chauffeur ou vehicule introuvable');
    }
    // Le vehicule ne peut pas etre affecte a deux chauffeurs sur la meme periode.
    $finCmp = $fin ?? '9999-12-31';
    $conf = $pdo->prepare(
        "SELECT va.id FROM vehicle_assignments va
         WHERE va.vehicle_id = ? AND va.chauffeur_id <> ? AND va.statut IN ('active', 'planifiee')
           AND va.date_debut <= ? AND (va.date_fin IS NULL OR va.date_fin >= ?) LIMIT 1"
    );
    $conf->execute([$vehicleId, $chauffeurId, $finCmp, $debut]);
    if ($conf->fetch()) {
        shipp_error(409, 'Ce vehicule est deja affecte a un autre chauffeur sur cette periode');
    }
    $pdo->beginTransaction();
    // Cloture de l'affectation en cours du chauffeur (historique conserve).
    $veille = date('Y-m-d', strtotime($debut . ' -1 day'));
    $pdo->prepare(
        "UPDATE vehicle_assignments SET date_fin = ?, statut = 'terminee'
         WHERE chauffeur_id = ? AND statut = 'active' AND date_debut < ? AND (date_fin IS NULL OR date_fin >= ?)"
    )->execute([$veille, $chauffeurId, $debut, $debut]);
    $statut = $debut > date('Y-m-d') ? 'planifiee' : 'active';
    $ecole = $pdo->prepare('SELECT ecole_id FROM chauffeurs WHERE id = ?');
    $ecole->execute([$chauffeurId]);
    $ecoleId = $ecole->fetchColumn() ?: null;
    $pdo->prepare(
        'INSERT INTO vehicle_assignments (ecole_id, chauffeur_id, vehicle_id, contract_id, date_debut, date_fin, statut, motif, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$ecoleId, $chauffeurId, $vehicleId, ($input['contract_id'] ?? null) ?: null, $debut, $fin, $statut, ($input['motif'] ?? '') ?: null, (int) $authUser['sub']]);
    $id = (int) $pdo->lastInsertId();
    $pdo->commit();
    shipp_journal($pdo, $ecoleId ? (int) $ecoleId : null, (int) $authUser['sub'], 'create', 'vehicle_assignments', $id, "Chauffeur #$chauffeurId -> vehicule #$vehicleId a partir du $debut");
    echo json_encode(['message' => 'ok', 'id' => $id, 'statut' => $statut]);
    exit;
}

// PUT : terminer ou annuler
$id = (int) ($input['id'] ?? 0);
$action = $input['action'] ?? '';
$r = $pdo->prepare('SELECT va.* FROM vehicle_assignments va WHERE va.id = ? AND (' . $cond[0] . ')');
$r->execute(array_merge([$id], $cond[1]));
$row = $r->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    shipp_error(404, 'Affectation introuvable');
}
if ($action === 'terminer') {
    $fin = $input['date_fin'] ?? date('Y-m-d');
    if (!va_valid_date($fin) || $fin < $row['date_debut']) {
        shipp_error(400, 'Date de fin invalide');
    }
    $pdo->prepare("UPDATE vehicle_assignments SET date_fin = ?, statut = 'terminee' WHERE id = ?")->execute([$fin, $id]);
} elseif ($action === 'annuler') {
    $pdo->prepare("UPDATE vehicle_assignments SET statut = 'annulee' WHERE id = ?")->execute([$id]);
} else {
    shipp_error(400, 'Action inconnue');
}
shipp_journal($pdo, $row['ecole_id'] !== null ? (int) $row['ecole_id'] : null, (int) $authUser['sub'], 'update', 'vehicle_assignments', $id, 'Affectation ' . $action);
echo json_encode(['message' => 'ok']);
