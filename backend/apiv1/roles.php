<?php
/**
 * Roles des utilisateurs (reserve aux administrateurs).
 * GET    : roles disponibles + attributions
 * POST   : {user_id, role_key, ecole_id?}  attribue un role
 * DELETE : {user_id, role_key}             retire un role
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, POST, DELETE, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
if (!$ctx['is_admin']) {
    shipp_error(403, 'Acces reserve aux administrateurs');
}
if (!authz_table_exists($pdo, 'user_roles')) {
    shipp_error(503, 'Migration 001 non appliquee');
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $roles = $pdo->query('SELECT id, role_key, role_label, sort_order FROM roles ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
    $assign = $pdo->query('SELECT ur.user_id, ur.role_id, r.role_key, ur.ecole_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['roles' => $roles, 'user_roles' => $assign]);
    exit;
}

$input = shipp_json_input();
$userId = (int) ($input['user_id'] ?? 0);
$roleKey = trim((string) ($input['role_key'] ?? ''));
$r = $pdo->prepare('SELECT id FROM roles WHERE role_key = ?');
$r->execute([$roleKey]);
$roleId = (int) ($r->fetchColumn() ?: 0);
$u = $pdo->prepare('SELECT id, ecole_id FROM users WHERE id = ?');
$u->execute([$userId]);
$user = $u->fetch(PDO::FETCH_ASSOC);
if (!$user || !$roleId) {
    shipp_error(400, 'Utilisateur ou role invalide');
}

if ($method === 'POST') {
    $ecoleId = isset($input['ecole_id']) && $input['ecole_id'] !== '' ? (int) $input['ecole_id'] : ($user['ecole_id'] !== null ? (int) $user['ecole_id'] : null);
    $exists = $pdo->prepare('SELECT id FROM user_roles WHERE user_id = ? AND role_id = ?');
    $exists->execute([$userId, $roleId]);
    if (!$exists->fetch()) {
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id, ecole_id) VALUES (?, ?, ?)')->execute([$userId, $roleId, $ecoleId]);
    }
    shipp_journal($pdo, $ecoleId, (int) $authUser['sub'], 'update', 'roles', $userId, 'Role attribue : ' . $roleKey);
    echo json_encode(['message' => 'ok']);
    exit;
}

if ($method === 'DELETE') {
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?')->execute([$userId, $roleId]);
    shipp_journal($pdo, $user['ecole_id'] !== null ? (int) $user['ecole_id'] : null, (int) $authUser['sub'], 'update', 'roles', $userId, 'Role retire : ' . $roleKey);
    echo json_encode(['message' => 'ok']);
    exit;
}

shipp_error(405, 'Methode non autorisee');
