<?php
/**
 * Contacts des parents d'un eleve (lot 5). Un contact n'est pas un compte :
 * user_id le relie facultativement a un compte parent existant.
 * GET  ?eleve_id=N
 * POST {eleve_id, nom?, lien?, telephone?, telephone2?, email?, principal?}
 * PUT  {id, ...champs}
 * Aucune suppression : un contact obsolete est corrige ou remplace.
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
authz_require($ctx, 'eleve_contacts', $flag);
$cond = authz_scope_condition($pdo, $ctx, 'eleve_contacts', 'c.');
if ($cond === null) {
    shipp_error(403, 'Acces refuse pour ce module');
}

const EC_LIENS = ['pere', 'mere', 'tuteur', 'autre'];

/** Telephone normalise : chiffres et + seulement ; indicatif +225 ajoute a un numero local a 10 chiffres. */
function ec_tel($v): ?string
{
    $t = preg_replace('/[^0-9+]/', '', (string) $v);
    if ($t === '') {
        return null;
    }
    if (strpos($t, '00') === 0) {
        $t = '+' . substr($t, 2);
    }
    if ($t[0] !== '+' && strlen($t) === 10) {
        $t = '+225' . $t;
    }
    return mb_substr($t, 0, 30);
}

function ec_champs(array $in, array $base): array
{
    $c = $base;
    foreach (['nom' => 150, 'email' => 150] as $k => $max) {
        if (array_key_exists($k, $in)) {
            $v = trim((string) $in[$k]);
            $c[$k] = $v === '' ? null : mb_substr($v, 0, $max);
        }
    }
    if (isset($c['email']) && $c['email'] !== null && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
        shipp_error(400, 'E-mail invalide');
    }
    foreach (['telephone', 'telephone2'] as $k) {
        if (array_key_exists($k, $in)) {
            $c[$k] = ec_tel($in[$k]);
        }
    }
    if (array_key_exists('lien', $in)) {
        $c['lien'] = in_array($in['lien'], EC_LIENS, true) ? $in['lien'] : 'autre';
    }
    if (array_key_exists('principal', $in)) {
        $c['principal'] = !empty($in['principal']) ? 1 : 0;
    }
    if (empty($c['telephone']) && empty($c['email'])) {
        shipp_error(400, 'Un telephone ou un e-mail est requis');
    }
    return $c;
}

if ($method === 'GET') {
    $eleveId = (int) ($_GET['eleve_id'] ?? 0);
    if (!$eleveId) {
        shipp_error(400, 'eleve_id requis');
    }
    $s = $pdo->prepare('SELECT c.*, u.name AS compte_nom FROM eleve_contacts c LEFT JOIN users u ON u.id = c.user_id WHERE c.eleve_id = ? AND (' . $cond[0] . ') ORDER BY c.principal DESC, c.id');
    $s->execute(array_merge([$eleveId], $cond[1]));
    echo json_encode(['data' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = shipp_json_input();
if (!in_array(authz_scope($ctx, 'eleve_contacts'), ['GLOBAL', 'SCHOOL'], true)) {
    shipp_error(403, 'Modification reservee a l\'administration');
}

if ($method === 'POST') {
    $eleveId = (int) ($input['eleve_id'] ?? 0);
    if (!$eleveId || !authz_row_allowed($pdo, $ctx, 'eleves', $eleveId)) {
        shipp_error(404, 'Eleve introuvable');
    }
    $c = ec_champs($input, ['nom' => null, 'lien' => 'autre', 'telephone' => null, 'telephone2' => null, 'email' => null, 'principal' => 0]);
    $e = $pdo->prepare('SELECT ecole_id FROM eleves WHERE id = ?');
    $e->execute([$eleveId]);
    $ecoleId = $e->fetchColumn() ?: null;
    // Rattachement au compte parent existant ayant ce telephone, s'il est deja lie a l'eleve.
    $compte = null;
    if ($c['telephone']) {
        $u = $pdo->prepare('SELECT u.id FROM users u JOIN parent_liaisons pl ON pl.user_id = u.id AND pl.eleve_id = ? WHERE u.telephone = ? LIMIT 1');
        $u->execute([$eleveId, $c['telephone']]);
        $compte = $u->fetchColumn() ?: null;
    }
    $pdo->beginTransaction();
    if ($c['principal']) {
        $pdo->prepare('UPDATE eleve_contacts SET principal = 0 WHERE eleve_id = ?')->execute([$eleveId]);
    }
    $pdo->prepare('INSERT INTO eleve_contacts (ecole_id, eleve_id, nom, lien, telephone, telephone2, email, principal, user_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$ecoleId, $eleveId, $c['nom'], $c['lien'], $c['telephone'], $c['telephone2'], $c['email'], $c['principal'], $compte]);
    $id = (int) $pdo->lastInsertId();
    $pdo->commit();
    shipp_journal($pdo, $ecoleId ? (int) $ecoleId : null, $userId, 'create', 'eleve_contacts', $id, "Contact eleve #$eleveId");
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

// PUT
$id = (int) ($input['id'] ?? 0);
$s = $pdo->prepare('SELECT c.* FROM eleve_contacts c WHERE c.id = ? AND (' . $cond[0] . ')');
$s->execute(array_merge([$id], $cond[1]));
$row = $s->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    shipp_error(404, 'Contact introuvable');
}
$c = ec_champs($input, $row);
$pdo->beginTransaction();
if ($c['principal']) {
    $pdo->prepare('UPDATE eleve_contacts SET principal = 0 WHERE eleve_id = ? AND id <> ?')->execute([(int) $row['eleve_id'], $id]);
}
$pdo->prepare('UPDATE eleve_contacts SET nom = ?, lien = ?, telephone = ?, telephone2 = ?, email = ?, principal = ?, updated_at = NOW() WHERE id = ?')
    ->execute([$c['nom'], $c['lien'], $c['telephone'], $c['telephone2'], $c['email'], $c['principal'], $id]);
$pdo->commit();
shipp_journal($pdo, $row['ecole_id'] !== null ? (int) $row['ecole_id'] : null, $userId, 'update', 'eleve_contacts', $id, 'Contact modifie');
echo json_encode(['success' => true]);
