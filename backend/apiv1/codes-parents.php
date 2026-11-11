<?php
/**
 * Codes parents des eleves (migration 007). Reserve aux administrateurs.
 *
 * GET [?circuit_id=N] [&format=csv]     : eleves actifs, code, comptes parents lies
 * POST {action: 'generer'}              : attribue un code aux eleves actifs qui n'en ont pas
 * POST {action: 'regenerer', eleve_id}  : nouveau code (l'ancien ne fonctionne plus ;
 *                                         les comptes deja lies restent lies)
 * POST {action: 'retirer', liaison_id}  : retire l'acces d'un compte a un enfant
 *
 * Les codes sont des secrets : a remettre individuellement a chaque famille,
 * jamais dans un groupe de discussion collectif.
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, POST, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/parents.php';
$userId = (int) $authUser['sub'];

$a = $pdo->prepare("SELECT is_admin FROM users WHERE id = ? AND status = 'active'");
$a->execute([$userId]);
if (!(int) $a->fetchColumn()) {
    shipp_error(403, 'Acces reserve aux administrateurs');
}
if (!parents_disponible($pdo)) {
    shipp_error(503, 'Migration 007 non appliquee');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $campus = true;
    try {
        $pdo->query('SELECT campus FROM eleves LIMIT 0');
    } catch (Throwable $e) {
        $campus = false;
    }
    $where = ["e.statut = 'actif'"];
    $params = [];
    if (!empty($_GET['circuit_id'])) {
        $where[] = 'e.circuit_id = ?';
        $params[] = (int) $_GET['circuit_id'];
    }
    $s = $pdo->prepare(
        'SELECT e.id, e.nom, e.prenom, e.classe, ' . ($campus ? 'e.campus' : 'NULL AS campus') . ', e.code_parent, c.id AS circuit_id, c.nom AS circuit
         FROM eleves e LEFT JOIN circuits c ON c.id = e.circuit_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY c.nom IS NULL, c.nom, e.nom, e.prenom'
    );
    $s->execute($params);
    $eleves = $s->fetchAll(PDO::FETCH_ASSOC);
    $comptes = [];
    if ($eleves) {
        $ids = array_map(function ($e) { return (int) $e['id']; }, $eleves);
        $q = $pdo->prepare('SELECT pl.id AS liaison_id, pl.eleve_id, pl.source, pl.created_at, u.name, u.telephone
            FROM parent_liaisons pl JOIN users u ON u.id = pl.user_id
            WHERE pl.eleve_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY pl.created_at');
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $comptes[(int) $r['eleve_id']][] = $r;
        }
    }
    foreach ($eleves as &$e) {
        $e['comptes'] = $comptes[(int) $e['id']] ?? [];
    }
    unset($e);

    if (($_GET['format'] ?? '') === 'csv') {
        shipp_journal($pdo, null, $userId, 'create', 'export', null, 'Export des codes parents (' . count($eleves) . ' eleves)');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="codes_parents.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Circuit', 'Nom', 'Prenom', 'Classe', 'Campus', 'Code parent', 'Comptes lies'], ';');
        foreach ($eleves as $e) {
            fputcsv($out, [$e['circuit'] ?? '', $e['nom'], $e['prenom'], $e['classe'] ?? '', $e['campus'] ?? '', $e['code_parent'] ?? '', count($e['comptes'])], ';');
        }
        fclose($out);
        exit;
    }
    echo json_encode(['data' => $eleves, 'max_comptes' => (int) parents_param($pdo, 'parent_max_comptes_par_eleve', '2'),
        'inscription_ouverte' => parents_param($pdo, 'parent_inscription_ouverte', '1') === '1']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shipp_error(405, 'Methode non autorisee');
}
$in = shipp_json_input();
$action = (string) ($in['action'] ?? '');

if ($action === 'generer') {
    $ids = $pdo->query("SELECT id FROM eleves WHERE statut = 'actif' AND code_parent IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        parents_attribuer_code($pdo, (int) $id);
    }
    shipp_journal($pdo, null, $userId, 'update', 'codes_parents', null, count($ids) . ' code(s) parent genere(s)');
    echo json_encode(['message' => 'ok', 'generes' => count($ids)]);
    exit;
}

if ($action === 'regenerer') {
    $eleveId = (int) ($in['eleve_id'] ?? 0);
    $e = $pdo->prepare('SELECT ecole_id FROM eleves WHERE id = ?');
    $e->execute([$eleveId]);
    $ecole = $e->fetchColumn();
    if ($ecole === false) {
        shipp_error(404, 'Eleve introuvable');
    }
    $code = parents_attribuer_code($pdo, $eleveId);
    shipp_journal($pdo, $ecole !== null ? (int) $ecole : null, $userId, 'update', 'codes_parents', $eleveId, 'Code parent regenere');
    echo json_encode(['message' => 'ok', 'code_parent' => $code]);
    exit;
}

if ($action === 'retirer') {
    $liaisonId = (int) ($in['liaison_id'] ?? 0);
    $l = $pdo->prepare('SELECT pl.ecole_id, pl.eleve_id, u.name FROM parent_liaisons pl JOIN users u ON u.id = pl.user_id WHERE pl.id = ?');
    $l->execute([$liaisonId]);
    $row = $l->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        shipp_error(404, 'Liaison introuvable');
    }
    $pdo->prepare('DELETE FROM parent_liaisons WHERE id = ?')->execute([$liaisonId]);
    shipp_journal($pdo, (int) $row['ecole_id'] ?: null, $userId, 'delete', 'parent_liaisons', (int) $row['eleve_id'], "Acces retire a {$row['name']}");
    echo json_encode(['message' => 'ok']);
    exit;
}

shipp_error(400, 'Action inconnue');
