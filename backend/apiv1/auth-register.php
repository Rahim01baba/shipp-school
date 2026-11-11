<?php
/**
 * Inscription autonome d'un parent (migration 007). Endpoint PUBLIC.
 * POST {nom, telephone, email?, password}
 *
 * Le compte cree a uniquement le role « parent » (perimetre CHILDREN) et AUCUN
 * enfant : il ne voit rien tant qu'il n'a pas saisi le code parent d'un enfant
 * (parent-lier.php). Retourne un jeton de connexion (le parent est connecte).
 *
 * Anti-abus : 5 inscriptions par heure et par adresse IP.
 */
require __DIR__ . '/lib/db.php';
shipp_headers('POST, OPTIONS');
require __DIR__ . '/auth-lib.php';
require __DIR__ . '/lib/parents.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shipp_error(405, 'Methode non autorisee');
}
$pdo = shipp_db();
if (!parents_disponible($pdo)) {
    shipp_error(503, 'Inscription des parents non activee sur ce serveur');
}
if (parents_param($pdo, 'parent_inscription_ouverte', '1') !== '1') {
    shipp_error(403, "Les inscriptions sont fermees : contactez l'etablissement");
}
if (parents_tentatives($pdo, 'inscription', null, parents_ip(), 60, false) >= 5) {
    shipp_error(429, 'Trop de tentatives depuis cette connexion : reessayez dans une heure');
}

$in = shipp_json_input();
$nom = trim(preg_replace('/\s+/', ' ', (string) ($in['nom'] ?? '')));
$telephone = parents_telephone($in['telephone'] ?? '');
$email = trim((string) ($in['email'] ?? ''));
$password = (string) ($in['password'] ?? '');

if (mb_strlen($nom) < 2 || mb_strlen($nom) > 150) {
    shipp_error(400, 'Nom et prenom requis');
}
if (!$telephone || !preg_match('/^\+?\d{8,15}$/', $telephone)) {
    shipp_error(400, 'Numero de telephone invalide');
}
if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
    shipp_error(400, 'Adresse e-mail invalide');
}
if (strlen($password) < 8) {
    shipp_error(400, 'Le mot de passe doit contenir au moins 8 caracteres');
}
$roleId = parents_role_id($pdo);
if (!$roleId) {
    shipp_error(500, 'Role parent introuvable');
}

parents_noter_tentative($pdo, 'inscription', null, false);

// Un compte existant n'est jamais repris ni modifie : le parent doit se connecter.
$d = $pdo->prepare('SELECT id FROM users WHERE telephone = ? OR (? <> \'\' AND email = ?) LIMIT 1');
$d->execute([$telephone, $email, $email]);
if ($d->fetch()) {
    shipp_error(409, 'Un compte existe deja avec ce telephone ou cet e-mail : connectez-vous');
}

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO users (name, email, telephone, password_hash, ecole_id, status, is_admin) VALUES (?, ?, ?, ?, NULL, 'active', 0)")
        ->execute([$nom, $email !== '' ? $email : null, $telephone, password_hash($password, PASSWORD_BCRYPT)]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id, ecole_id) VALUES (?, ?, NULL)')->execute([$userId, $roleId]);
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
        shipp_error(409, 'Un compte existe deja avec ce telephone ou cet e-mail : connectez-vous');
    }
    error_log('[SHIPP] inscription parent : ' . $e->getMessage());
    shipp_error(500, 'Inscription impossible pour le moment');
}

shipp_journal($pdo, null, $userId, 'create', 'inscription_parent', $userId, 'Compte parent cree par inscription');

$token = jwt_encode([
    'sub' => $userId,
    'email' => $email !== '' ? $email : null,
    'name' => $nom,
    'iat' => time(),
    'exp' => time() + 60 * 60 * 24 * 7,
]);
http_response_code(201);
echo json_encode(['token' => $token, 'user' => ['id' => $userId, 'name' => $nom, 'email' => $email !== '' ? $email : null]]);
