<?php
/**
 * Documents du chauffeur : permis, piece d'identite, visite medicale, etc. (lot 3, T5-14)
 * GET  ?chauffeur_id=N          : documents (avec etat d'expiration calcule)
 * POST {chauffeur_id, type, numero?, categorie_permis?, date_delivrance?, date_expiration?, autorite?, commentaire?}
 *      Nouveau document au statut 'a_verifier'. Le chauffeur peut deposer les siens.
 * PUT  {id, action: 'modifier', ...champs}  (can_edit)
 *      {id, action: 'valider'|'refuser', commentaire?} (can_validate)
 *      A la validation, le document valide precedent du meme type passe a 'remplace'
 *      (conserve dans l'historique, jamais supprime).
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, POST, PUT, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
require __DIR__ . '/lib/transport.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
$userId = (int) $authUser['sub'];
$method = $_SERVER['REQUEST_METHOD'];
$flag = ['GET' => 'can_read', 'POST' => 'can_create', 'PUT' => 'can_read'][$method] ?? null;
if (!$flag) {
    shipp_error(405, 'Methode non autorisee');
}
authz_require($ctx, 'chauffeur_documents', $flag);
$cond = authz_scope_condition($pdo, $ctx, 'chauffeur_documents', 'd.');
if ($cond === null) {
    shipp_error(403, 'Acces refuse pour ce module');
}

const DOC_TYPES = ['permis', 'cni', 'passeport', 'visite_medicale', 'attestation_residence', 'casier_judiciaire', 'photo', 'autre'];

function doc_date($v): ?string
{
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
}

/** Etat affiche : expire / expire_bientot / statut. */
function doc_etat(array $d, int $jours): string
{
    if ($d['statut'] === 'remplace' || $d['statut'] === 'refuse') {
        return $d['statut'];
    }
    if ($d['date_expiration']) {
        $today = date('Y-m-d');
        if ($d['date_expiration'] < $today) {
            return 'expire';
        }
        if ($d['date_expiration'] <= date('Y-m-d', strtotime("+$jours days"))) {
            return 'expire_bientot';
        }
    }
    return $d['statut'];
}

$jours = (int) shipp_param($pdo, 'alerte_expiration_jours', '30');

if ($method === 'GET') {
    $where = ['(' . $cond[0] . ')'];
    $params = $cond[1];
    if (!empty($_GET['chauffeur_id'])) {
        $where[] = 'd.chauffeur_id = ?';
        $params[] = (int) $_GET['chauffeur_id'];
    }
    $s = $pdo->prepare(
        "SELECT d.*, f.nom_original AS fichier_nom, f.mime AS fichier_mime, u.name AS verifie_par_nom,
                TRIM(CONCAT(COALESCE(c.prenom, ''), ' ', c.nom)) AS chauffeur_nom
         FROM chauffeur_documents d
         JOIN chauffeurs c ON c.id = d.chauffeur_id
         LEFT JOIN fichiers f ON f.id = d.fichier_id AND f.retire_at IS NULL
         LEFT JOIN users u ON u.id = d.verifie_par
         WHERE " . implode(' AND ', $where) . ' ORDER BY d.type, d.id DESC'
    );
    $s->execute($params);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['etat'] = doc_etat($r, $jours);
    }
    echo json_encode(['data' => $rows, 'types' => DOC_TYPES, 'alerte_jours' => $jours]);
    exit;
}

$input = shipp_json_input();
$champsTexte = ['numero' => 100, 'categorie_permis' => 30, 'autorite' => 150, 'commentaire' => 255];

if ($method === 'POST') {
    $chauffeurId = (int) ($input['chauffeur_id'] ?? 0);
    $type = (string) ($input['type'] ?? '');
    if (!in_array($type, DOC_TYPES, true)) {
        shipp_error(400, 'Type de document invalide');
    }
    if (!$chauffeurId || !authz_values_allowed($pdo, $ctx, 'chauffeur_documents', ['chauffeur_id' => $chauffeurId])
        || !authz_row_allowed($pdo, $ctx, 'chauffeurs', $chauffeurId)) {
        shipp_error(404, 'Chauffeur introuvable');
    }
    $deliv = doc_date($input['date_delivrance'] ?? null);
    $exp = doc_date($input['date_expiration'] ?? null);
    if ($deliv && $exp && $exp < $deliv) {
        shipp_error(400, "La date d'expiration precede la date de delivrance");
    }
    $e = $pdo->prepare('SELECT ecole_id FROM chauffeurs WHERE id = ?');
    $e->execute([$chauffeurId]);
    $ecoleId = $e->fetchColumn() ?: null;
    $vals = [];
    foreach ($champsTexte as $k => $max) {
        $v = trim((string) ($input[$k] ?? ''));
        $vals[] = $v === '' ? null : mb_substr($v, 0, $max);
    }
    $pdo->prepare(
        'INSERT INTO chauffeur_documents (ecole_id, chauffeur_id, type, numero, categorie_permis, autorite, commentaire, date_delivrance, date_expiration, statut, created_by, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'a_verifier\', ?, NOW())'
    )->execute(array_merge([$ecoleId, $chauffeurId, $type], $vals, [$deliv, $exp, $userId]));
    $id = (int) $pdo->lastInsertId();
    shipp_journal($pdo, $ecoleId ? (int) $ecoleId : null, $userId, 'create', 'chauffeur_documents', $id, "Document $type");
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

// PUT
$id = (int) ($input['id'] ?? 0);
$s = $pdo->prepare('SELECT d.* FROM chauffeur_documents d WHERE d.id = ? AND (' . $cond[0] . ')');
$s->execute(array_merge([$id], $cond[1]));
$doc = $s->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    shipp_error(404, 'Document introuvable');
}
if ($doc['statut'] === 'remplace') {
    shipp_error(409, 'Document remplace : historique en lecture seule');
}
$action = (string) ($input['action'] ?? '');

if ($action === 'modifier') {
    authz_require($ctx, 'chauffeur_documents', 'can_edit');
    $sets = [];
    $vals = [];
    foreach ($champsTexte as $k => $max) {
        if (array_key_exists($k, $input)) {
            $v = trim((string) $input[$k]);
            $sets[] = "`$k` = ?";
            $vals[] = $v === '' ? null : mb_substr($v, 0, $max);
        }
    }
    foreach (['date_delivrance', 'date_expiration'] as $k) {
        if (array_key_exists($k, $input)) {
            $sets[] = "`$k` = ?";
            $vals[] = doc_date($input[$k]);
        }
    }
    if (!empty($input['fichier_id'])) {
        $f = $pdo->prepare("SELECT id FROM fichiers WHERE id = ? AND entite = 'chauffeur_document' AND entite_id = ? AND retire_at IS NULL");
        $f->execute([(int) $input['fichier_id'], $id]);
        if (!$f->fetch()) {
            shipp_error(400, 'Fichier non rattache a ce document');
        }
        $sets[] = '`fichier_id` = ?';
        $vals[] = (int) $input['fichier_id'];
    }
    if (!$sets) {
        shipp_error(400, 'Aucun champ a modifier');
    }
    // Toute modification d'un document valide le renvoie en verification.
    $sets[] = "`statut` = 'a_verifier'";
    $vals[] = $id;
    $pdo->prepare('UPDATE chauffeur_documents SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?')->execute($vals);
} elseif ($action === 'valider' || $action === 'refuser') {
    authz_require($ctx, 'chauffeur_documents', 'can_validate');
    $pdo->beginTransaction();
    if ($action === 'valider') {
        $prev = $pdo->prepare("SELECT id FROM chauffeur_documents WHERE chauffeur_id = ? AND type = ? AND statut = 'valide' AND id <> ?");
        $prev->execute([(int) $doc['chauffeur_id'], $doc['type'], $id]);
        foreach ($prev->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $pdo->prepare("UPDATE chauffeur_documents SET statut = 'remplace', remplace_par_id = ?, updated_at = NOW() WHERE id = ?")->execute([$id, (int) $pid]);
        }
    }
    $pdo->prepare('UPDATE chauffeur_documents SET statut = ?, verifie_par = ?, verifie_at = NOW(), commentaire = COALESCE(?, commentaire), updated_at = NOW() WHERE id = ?')
        ->execute([$action === 'valider' ? 'valide' : 'refuse', $userId, trim((string) ($input['commentaire'] ?? '')) ?: null, $id]);
    $pdo->commit();
} else {
    shipp_error(400, 'Action inconnue');
}
shipp_journal($pdo, $doc['ecole_id'] !== null ? (int) $doc['ecole_id'] : null, $userId, 'update', 'chauffeur_documents', $id, 'Action : ' . $action);
echo json_encode(['success' => true]);
