<?php
/**
 * Modeles de contrat d'utilisation de vehicule, versionnes (lot 3, T5-15).
 * Une version publiee n'est jamais ecrasee : toute evolution cree une nouvelle
 * version. Les valeurs par defaut d'un modele (ex. montant indique dans le
 * document source) servent UNIQUEMENT a pre-remplir un nouveau contrat ;
 * chaque contrat conserve sa propre valeur.
 *
 * GET  [?code=X] [?id=N]
 * POST {code, titre, contenu, valeurs_defaut?, source_document?}  -> nouvelle version (brouillon)
 * PUT  {id, action: 'modifier'} (brouillon non utilise uniquement)
 *      {id, action: 'activer'|'archiver'}
 * Variables utilisables dans le contenu : voir CT_VARIABLES ({{nom_variable}}).
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
authz_require($ctx, 'contract_templates', $flag);
$cond = authz_scope_condition($pdo, $ctx, 'contract_templates', 't.');
if ($cond === null) {
    shipp_error(403, 'Acces refuse pour ce module');
}

const CT_VARIABLES = [
    'reference', 'date_jour', 'etablissement',
    'chauffeur_nom', 'chauffeur_prenom', 'chauffeur_telephone', 'chauffeur_adresse', 'chauffeur_date_naissance',
    'chauffeur_cni', 'vehicule_immatriculation', 'vehicule_modele', 'vehicule_marque', 'vehicule_annee',
    'date_debut', 'date_fin', 'duree_mois',
    'remuneration_montant', 'remuneration_devise', 'remuneration_periodicite',
    'preavis_jours', 'penalites', 'conditions_particulieres',
];

function ct_valeurs($v): ?string
{
    if ($v === null || $v === '' || $v === []) {
        return null;
    }
    if (!is_array($v)) {
        shipp_error(400, 'valeurs_defaut doit etre un objet');
    }
    $out = [];
    foreach ($v as $k => $val) {
        if (in_array($k, CT_VARIABLES, true) && (is_scalar($val) || $val === null)) {
            $out[$k] = $val;
        }
    }
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
}

if ($method === 'GET') {
    $where = ['(' . $cond[0] . ')'];
    $params = $cond[1];
    if (!empty($_GET['id'])) {
        $where[] = 't.id = ?';
        $params[] = (int) $_GET['id'];
    }
    if (!empty($_GET['code'])) {
        $where[] = 't.code = ?';
        $params[] = (string) $_GET['code'];
    }
    if (!empty($_GET['statut'])) {
        $where[] = 't.statut = ?';
        $params[] = (string) $_GET['statut'];
    }
    $s = $pdo->prepare(
        'SELECT t.*, u.name AS created_by_nom,
                (SELECT COUNT(*) FROM chauffeur_contracts cc WHERE cc.template_id = t.id) AS nb_contrats
         FROM contract_templates t LEFT JOIN users u ON u.id = t.created_by
         WHERE ' . implode(' AND ', $where) . ' ORDER BY t.code, t.version DESC'
    );
    $s->execute($params);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['valeurs_defaut'] = $r['valeurs_defaut'] ? json_decode($r['valeurs_defaut'], true) : null;
    }
    echo json_encode(['data' => $rows, 'variables' => CT_VARIABLES]);
    exit;
}

$input = shipp_json_input();

if ($method === 'POST') {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($input['code'] ?? '')));
    $titre = trim((string) ($input['titre'] ?? ''));
    if ($code === '' || $titre === '') {
        shipp_error(400, 'Code et titre requis');
    }
    $v = $pdo->prepare('SELECT COALESCE(MAX(version), 0) FROM contract_templates WHERE code = ?');
    $v->execute([$code]);
    $version = (int) $v->fetchColumn() + 1;
    $ecoleId = authz_scope($ctx, 'contract_templates') === 'GLOBAL' ? (isset($input['ecole_id']) && $input['ecole_id'] !== '' ? (int) $input['ecole_id'] : null) : ($ctx['ecole_ids'][0] ?? null);
    $pdo->prepare(
        "INSERT INTO contract_templates (ecole_id, code, version, titre, type, contenu, valeurs_defaut, source_document, statut, created_by)
         VALUES (?, ?, ?, ?, 'utilisation_vehicule', ?, ?, ?, 'brouillon', ?)"
    )->execute([$ecoleId, mb_substr($code, 0, 50), $version, mb_substr($titre, 0, 200), (string) ($input['contenu'] ?? ''),
        ct_valeurs($input['valeurs_defaut'] ?? null), trim((string) ($input['source_document'] ?? '')) ?: null, $userId]);
    $id = (int) $pdo->lastInsertId();
    shipp_journal($pdo, $ecoleId, $userId, 'create', 'contract_templates', $id, "$code v$version");
    echo json_encode(['success' => true, 'id' => $id, 'version' => $version]);
    exit;
}

// PUT
$id = (int) ($input['id'] ?? 0);
$s = $pdo->prepare('SELECT t.* FROM contract_templates t WHERE t.id = ? AND (' . $cond[0] . ')');
$s->execute(array_merge([$id], $cond[1]));
$tpl = $s->fetch(PDO::FETCH_ASSOC);
if (!$tpl) {
    shipp_error(404, 'Modele introuvable');
}
$action = (string) ($input['action'] ?? '');
if ($action === 'modifier') {
    $used = $pdo->prepare('SELECT COUNT(*) FROM chauffeur_contracts WHERE template_id = ?');
    $used->execute([$id]);
    if ($tpl['statut'] !== 'brouillon' || (int) $used->fetchColumn() > 0) {
        shipp_error(409, 'Version publiee ou deja utilisee : creer une nouvelle version');
    }
    $pdo->prepare('UPDATE contract_templates SET titre = ?, contenu = ?, valeurs_defaut = ?, source_document = ? WHERE id = ?')
        ->execute([mb_substr(trim((string) ($input['titre'] ?? $tpl['titre'])), 0, 200) ?: $tpl['titre'], (string) ($input['contenu'] ?? $tpl['contenu']),
            array_key_exists('valeurs_defaut', $input) ? ct_valeurs($input['valeurs_defaut']) : $tpl['valeurs_defaut'],
            array_key_exists('source_document', $input) ? (trim((string) $input['source_document']) ?: null) : $tpl['source_document'], $id]);
} elseif ($action === 'activer') {
    if (trim((string) $tpl['contenu']) === '') {
        shipp_error(409, 'Un modele sans contenu ne peut pas etre active');
    }
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE contract_templates SET statut = 'archive' WHERE code = ? AND statut = 'actif' AND id <> ?")->execute([$tpl['code'], $id]);
    $pdo->prepare("UPDATE contract_templates SET statut = 'actif' WHERE id = ?")->execute([$id]);
    $pdo->commit();
} elseif ($action === 'archiver') {
    $pdo->prepare("UPDATE contract_templates SET statut = 'archive' WHERE id = ?")->execute([$id]);
} else {
    shipp_error(400, 'Action inconnue');
}
shipp_journal($pdo, $tpl['ecole_id'] !== null ? (int) $tpl['ecole_id'] : null, $userId, 'update', 'contract_templates', $id, "Action : $action");
echo json_encode(['success' => true]);
