<?php
/**
 * Tarifs mensuels par zone et par annee scolaire (lot 5).
 * GET  ?annee_scolaire_id=N
 * POST {annee_scolaire_id, zone, montant_mensuel, service?}
 * PUT  {id, montant_mensuel}   (les abonnements gardent leur propre montant : aucun recalcul silencieux)
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
authz_require($ctx, 'tarifs', $flag);
$cond = authz_scope_condition($pdo, $ctx, 'tarifs', 't.');
if ($cond === null) {
    shipp_error(403, 'Acces refuse pour ce module');
}

if ($method === 'GET') {
    $annee = !empty($_GET['annee_scolaire_id']) ? (int) $_GET['annee_scolaire_id'] : shipp_annee_active($pdo);
    $s = $pdo->prepare('SELECT t.*, a.libelle AS annee FROM tarifs t JOIN annees_scolaires a ON a.id = t.annee_scolaire_id WHERE t.annee_scolaire_id = ? AND (' . $cond[0] . ') ORDER BY t.service, t.zone');
    $s->execute(array_merge([$annee], $cond[1]));
    echo json_encode(['data' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}
$input = shipp_json_input();
$montant = $input['montant_mensuel'] ?? null;
if (!is_numeric($montant) || (float) $montant < 0) {
    shipp_error(400, 'Montant mensuel invalide');
}
if ($method === 'POST') {
    $annee = (int) ($input['annee_scolaire_id'] ?? 0);
    $zone = strtoupper(trim((string) ($input['zone'] ?? '')));
    $service = in_array($input['service'] ?? 'transport', ['transport', 'cantine'], true) ? ($input['service'] ?? 'transport') : 'transport';
    if (!$annee || $zone === '') {
        shipp_error(400, 'Annee et zone requises');
    }
    try {
        $pdo->prepare('INSERT INTO tarifs (ecole_id, annee_scolaire_id, service, zone, montant_mensuel) VALUES (?, ?, ?, ?, ?)')
            ->execute([$ctx['ecole_ids'][0] ?? null, $annee, $service, mb_substr($zone, 0, 60), round((float) $montant, 2)]);
    } catch (PDOException $e) {
        shipp_error(409, 'Un tarif existe deja pour cette zone et cette annee');
    }
    $id = (int) $pdo->lastInsertId();
    shipp_journal($pdo, $ctx['ecole_ids'][0] ?? null, $userId, 'create', 'tarifs', $id, "$zone : $montant");
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}
$id = (int) ($input['id'] ?? 0);
$s = $pdo->prepare('SELECT t.* FROM tarifs t WHERE t.id = ? AND (' . $cond[0] . ')');
$s->execute(array_merge([$id], $cond[1]));
$t = $s->fetch(PDO::FETCH_ASSOC);
if (!$t) {
    shipp_error(404, 'Tarif introuvable');
}
$pdo->prepare('UPDATE tarifs SET montant_mensuel = ? WHERE id = ?')->execute([round((float) $montant, 2), $id]);
shipp_journal($pdo, $t['ecole_id'] !== null ? (int) $t['ecole_id'] : null, $userId, 'update', 'tarifs', $id, "{$t['zone']} : {$t['montant_mensuel']} -> $montant");
echo json_encode(['success' => true]);
