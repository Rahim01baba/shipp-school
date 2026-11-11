<?php
/**
 * Liaison d'un parent a son enfant par le CODE PARENT (migration 007).
 * Reserve aux comptes ayant le role « parent ».
 *
 * GET              : mes enfants lies (prenom, nom, classe, date de liaison)
 * POST {code, lien?} : lie l'enfant dont c'est le code
 *
 * Garde-fous (serveur) :
 * - 5 codes errones par heure et par compte, 20 par heure et par adresse IP ;
 * - au plus N comptes par enfant (parametre parent_max_comptes_par_eleve, 2 par defaut) ;
 *   au-dela, refus : l'administration lie elle-meme apres verification ;
 * - eleve inactif : refus ; chaque liaison previent les administrateurs et est journalisee.
 * Les reponses ne revelent jamais l'identite d'un enfant pour un code errone.
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, POST, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/parents.php';
$userId = (int) $authUser['sub'];
$st = $pdo->prepare('SELECT status FROM users WHERE id = ?');
$st->execute([$userId]);
if ($st->fetchColumn() !== 'active') {
    shipp_error(401, 'Compte introuvable ou inactif');
}

if (!parents_disponible($pdo)) {
    shipp_error(503, 'Liaison par code non activee sur ce serveur');
}
if (!parents_est_parent($pdo, $userId)) {
    shipp_error(403, 'Reserve aux comptes parents');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = $pdo->prepare(
        'SELECT e.id AS eleve_id, e.prenom, e.nom, e.classe, pl.lien, pl.created_at
         FROM parent_liaisons pl JOIN eleves e ON e.id = pl.eleve_id
         WHERE pl.user_id = ? ORDER BY e.prenom'
    );
    $s->execute([$userId]);
    echo json_encode(['data' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shipp_error(405, 'Methode non autorisee');
}

if (parents_tentatives($pdo, 'code', $userId, null, 60, true) >= 5
    || parents_tentatives($pdo, 'code', null, parents_ip(), 60, true) >= 20) {
    shipp_error(429, 'Trop de codes errones : reessayez dans une heure ou contactez SHIPP');
}

$in = shipp_json_input();
$code = parents_normaliser_code((string) ($in['code'] ?? ''));
$lien = in_array($in['lien'] ?? '', ['pere', 'mere', 'tuteur', 'autre'], true) ? $in['lien'] : 'autre';
if (!$code) {
    parents_noter_tentative($pdo, 'code', $userId, false);
    shipp_error(400, 'Code invalide : il a la forme SHP-XXXX-XXXX');
}

$s = $pdo->prepare('SELECT id, ecole_id, prenom, nom, statut FROM eleves WHERE code_parent = ? LIMIT 1');
$s->execute([$code]);
$eleve = $s->fetch(PDO::FETCH_ASSOC);
if (!$eleve || $eleve['statut'] !== 'actif') {
    parents_noter_tentative($pdo, 'code', $userId, false);
    shipp_error(404, 'Code inconnu : verifiez la saisie ou contactez SHIPP');
}
$eleveId = (int) $eleve['id'];
$nomEnfant = trim($eleve['prenom'] . ' ' . $eleve['nom']);

$deja = $pdo->prepare('SELECT 1 FROM parent_liaisons WHERE user_id = ? AND eleve_id = ?');
$deja->execute([$userId, $eleveId]);
if ($deja->fetch()) {
    parents_noter_tentative($pdo, 'code', $userId, true);
    echo json_encode(['message' => 'Enfant deja suivi', 'eleve' => ['id' => $eleveId, 'nom' => $nomEnfant], 'deja_lie' => true]);
    exit;
}

$max = max(1, (int) parents_param($pdo, 'parent_max_comptes_par_eleve', '2'));
$pdo->beginTransaction();
try {
    // Verrou sur l'eleve : deux parents qui saisissent le code en meme temps ne depassent pas le maximum.
    $pdo->prepare('SELECT id FROM eleves WHERE id = ? FOR UPDATE')->execute([$eleveId]);
    $n = $pdo->prepare('SELECT COUNT(*) FROM parent_liaisons WHERE eleve_id = ?');
    $n->execute([$eleveId]);
    if ((int) $n->fetchColumn() >= $max) {
        $pdo->rollBack();
        parents_noter_tentative($pdo, 'code', $userId, false);
        parents_prevenir_admins($pdo, $eleve['ecole_id'] !== null ? (int) $eleve['ecole_id'] : null, 'Liaison parent refusee',
            "Un compte supplementaire a saisi le code de $nomEnfant (maximum $max comptes atteint). A verifier.");
        shipp_error(409, "Nombre maximum de comptes atteint pour cet enfant : contactez SHIPP pour l'ajout");
    }
    $pdo->prepare("INSERT INTO parent_liaisons (ecole_id, user_id, eleve_id, lien, source) VALUES (?, ?, ?, ?, 'code')")
        ->execute([(int) ($eleve['ecole_id'] ?? 0), $userId, $eleveId, $lien]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
        echo json_encode(['message' => 'Enfant deja suivi', 'eleve' => ['id' => $eleveId, 'nom' => $nomEnfant], 'deja_lie' => true]);
        exit;
    }
    error_log('[SHIPP] liaison parent : ' . $e->getMessage());
    shipp_error(500, 'Liaison impossible pour le moment');
}

parents_noter_tentative($pdo, 'code', $userId, true);
$u = $pdo->prepare('SELECT name, telephone FROM users WHERE id = ?');
$u->execute([$userId]);
$compte = $u->fetch(PDO::FETCH_ASSOC) ?: ['name' => '?', 'telephone' => ''];
$ecoleId = $eleve['ecole_id'] !== null ? (int) $eleve['ecole_id'] : null;
shipp_journal($pdo, $ecoleId, $userId, 'create', 'parent_liaisons', $eleveId, "Liaison par code : {$compte['name']} -> $nomEnfant");
parents_prevenir_admins($pdo, $ecoleId, 'Nouveau parent lie', "{$compte['name']} ({$compte['telephone']}) suit desormais $nomEnfant (code parent).");

http_response_code(201);
echo json_encode(['message' => 'ok', 'eleve' => ['id' => $eleveId, 'nom' => $nomEnfant]]);
