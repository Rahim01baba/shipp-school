<?php
/**
 * Contrats d'utilisation de vehicule par le chauffeur (lot 3, T5-15).
 * Ce n'est PAS un contrat de travail. Les montants sont propres a chaque
 * contrat ; la valeur d'un modele ne sert qu'a pre-remplir.
 *
 * Donnees sensibles (remuneration, contenu genere, fichier signe) : visibles
 * uniquement pour un perimetre GLOBAL ou SCHOOL sur le module
 * chauffeur_contracts (admin, RH). Le chauffeur voit ses contrats sans ces champs.
 *
 * GET  ?chauffeur_id=N | ?id=N
 * POST {chauffeur_id, vehicle_id?, template_id?, date_debut, date_fin?, duree_mois?,
 *       remuneration_montant?, remuneration_devise?, remuneration_periodicite?,
 *       preavis_jours?, penalites?, conditions_particulieres?, reference?}  -> brouillon
 * PUT  {id, action}
 *   modifier   (brouillon)                       can_edit
 *   activer                                      can_validate (refus si chevauchement)
 *   signer     {signe_le, fichier_id?}           can_edit
 *   suspendre / reprendre {motif}                can_validate
 *   terminer   {date_fin}                        can_edit
 *   resilier   {resilie_le, motif}               can_validate
 * Aucune suppression ; un contrat termine ou resilie est en lecture seule.
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
authz_require($ctx, 'chauffeur_contracts', $flag);
$cond = authz_scope_condition($pdo, $ctx, 'chauffeur_contracts', 'cc.');
if ($cond === null) {
    shipp_error(403, 'Acces refuse pour ce module');
}
$sensible = in_array(authz_scope($ctx, 'chauffeur_contracts'), ['GLOBAL', 'SCHOOL'], true);
const CC_SENSIBLES = ['remuneration_montant', 'remuneration_devise', 'remuneration_periodicite', 'contenu_genere', 'fichier_signe_id'];

function cc_date($v): ?string
{
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
}

function cc_masquer(array $row, bool $sensible): array
{
    if (!$sensible) {
        foreach (CC_SENSIBLES as $k) {
            unset($row[$k]);
        }
        $row['remuneration_masquee'] = true;
    }
    return $row;
}

/** Remplit les {{variables}} du modele avec les donnees du contrat. */
function cc_generer(PDO $pdo, ?array $tpl, array $c): array
{
    if (!$tpl || trim((string) $tpl['contenu']) === '') {
        return [null, []];
    }
    $ch = $pdo->prepare('SELECT * FROM chauffeurs WHERE id = ?');
    $ch->execute([(int) $c['chauffeur_id']]);
    $chauffeur = $ch->fetch(PDO::FETCH_ASSOC) ?: [];
    $veh = [];
    if (!empty($c['vehicle_id'])) {
        $v = $pdo->prepare('SELECT * FROM vehicules WHERE id = ?');
        $v->execute([(int) $c['vehicle_id']]);
        $veh = $v->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    $etab = '';
    if (!empty($c['ecole_id'])) {
        $e = $pdo->prepare('SELECT nom FROM ecoles WHERE id = ?');
        $e->execute([(int) $c['ecole_id']]);
        $etab = (string) ($e->fetchColumn() ?: '');
    }
    $cni = '';
    if (authz_table_exists($pdo, 'chauffeur_documents')) {
        $d = $pdo->prepare("SELECT numero FROM chauffeur_documents WHERE chauffeur_id = ? AND type = 'cni' AND statut IN ('valide', 'a_verifier') ORDER BY statut = 'valide' DESC, id DESC LIMIT 1");
        $d->execute([(int) $c['chauffeur_id']]);
        $cni = (string) ($d->fetchColumn() ?: '');
    }
    $fmtDate = function ($d) {
        return $d ? date('d/m/Y', strtotime($d)) : '';
    };
    $vals = [
        'reference' => $c['reference'] ?? '', 'date_jour' => date('d/m/Y'), 'etablissement' => $etab,
        'chauffeur_nom' => $chauffeur['nom'] ?? '', 'chauffeur_prenom' => $chauffeur['prenom'] ?? '',
        'chauffeur_telephone' => $chauffeur['telephone'] ?? '', 'chauffeur_adresse' => $chauffeur['adresse'] ?? '',
        'chauffeur_date_naissance' => $fmtDate($chauffeur['date_naissance'] ?? null),
        'vehicule_immatriculation' => $veh['immatriculation'] ?? '', 'vehicule_modele' => $veh['modele'] ?? '',
        'vehicule_marque' => $veh['marque'] ?? '', 'vehicule_annee' => $veh['annee'] ?? '', 'chauffeur_cni' => $cni,
        'date_debut' => $fmtDate($c['date_debut'] ?? null), 'date_fin' => $fmtDate($c['date_fin'] ?? null),
        'duree_mois' => $c['duree_mois'] ?? '',
        'remuneration_montant' => ($c['remuneration_montant'] ?? null) !== null ? number_format((float) $c['remuneration_montant'], 0, ',', ' ') : '',
        'remuneration_devise' => ($c['remuneration_devise'] ?? 'XOF') === 'XOF' ? 'FCFA' : ($c['remuneration_devise'] ?? ''),
        'remuneration_periodicite' => $c['remuneration_periodicite'] ?? '',
        'preavis_jours' => $c['preavis_jours'] ?? '', 'penalites' => $c['penalites'] ?? '',
        'conditions_particulieres' => $c['conditions_particulieres'] ?? '',
    ];
    $manquantes = [];
    $texte = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($vals, &$manquantes) {
        if (!array_key_exists($m[1], $vals) || (string) $vals[$m[1]] === '') {
            $manquantes[] = $m[1];
            return $m[0];
        }
        return (string) $vals[$m[1]];
    }, (string) $tpl['contenu']);
    return [$texte, array_values(array_unique($manquantes))];
}

/** Autre contrat actif du chauffeur chevauchant la periode ? */
function cc_chevauchement(PDO $pdo, int $chauffeurId, string $debut, ?string $fin, int $saufId): bool
{
    $s = $pdo->prepare(
        "SELECT id FROM chauffeur_contracts WHERE chauffeur_id = ? AND id <> ? AND statut IN ('actif', 'suspendu')
           AND date_debut <= ? AND (date_fin IS NULL OR date_fin >= ?) LIMIT 1"
    );
    $s->execute([$chauffeurId, $saufId, $fin ?? '9999-12-31', $debut]);
    return (bool) $s->fetch();
}

$selectBase = "SELECT cc.*, v.immatriculation, v.modele, TRIM(CONCAT(COALESCE(c.prenom, ''), ' ', c.nom)) AS chauffeur_nom,
        t.code AS template_code, t.titre AS template_titre
    FROM chauffeur_contracts cc
    JOIN chauffeurs c ON c.id = cc.chauffeur_id
    LEFT JOIN vehicules v ON v.id = cc.vehicle_id
    LEFT JOIN contract_templates t ON t.id = cc.template_id";

if ($method === 'GET') {
    $where = ['(' . $cond[0] . ')'];
    $params = $cond[1];
    foreach (['id', 'chauffeur_id', 'vehicle_id'] as $k) {
        if (!empty($_GET[$k])) {
            $where[] = "cc.$k = ?";
            $params[] = (int) $_GET[$k];
        }
    }
    if (!empty($_GET['statut'])) {
        $where[] = 'cc.statut = ?';
        $params[] = (string) $_GET['statut'];
    }
    $s = $pdo->prepare($selectBase . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY cc.date_debut DESC, cc.id DESC');
    $s->execute($params);
    $rows = array_map(function ($r) use ($sensible) {
        return cc_masquer($r, $sensible);
    }, $s->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['data' => $rows, 'remuneration_visible' => $sensible]);
    exit;
}

$input = shipp_json_input();

/** Champs editables d'un contrat (brouillon), valides. */
function cc_champs(PDO $pdo, array &$ctx, array $input, array $base): array
{
    $c = $base;
    foreach (['date_debut', 'date_fin'] as $k) {
        if (array_key_exists($k, $input)) {
            $c[$k] = cc_date($input[$k]);
        }
    }
    if (array_key_exists('duree_mois', $input)) {
        $c['duree_mois'] = $input['duree_mois'] === '' || $input['duree_mois'] === null ? null : max(1, (int) $input['duree_mois']);
    }
    // D-27 : un brouillon peut rester incomplet ; la date de debut est exigee a l'activation.
    if (!$c['date_fin'] && !empty($c['duree_mois']) && $c['date_debut']) {
        $c['date_fin'] = date('Y-m-d', strtotime($c['date_debut'] . ' +' . (int) $c['duree_mois'] . ' months -1 day'));
    }
    if ($c['date_fin'] && $c['date_debut'] && $c['date_fin'] < $c['date_debut']) {
        shipp_error(400, 'La date de fin precede la date de debut');
    }
    if (array_key_exists('vehicle_id', $input)) {
        $c['vehicle_id'] = !empty($input['vehicle_id']) ? (int) $input['vehicle_id'] : null;
        if ($c['vehicle_id'] && !authz_row_allowed($pdo, $ctx, 'vehicules', $c['vehicle_id'])) {
            shipp_error(404, 'Vehicule introuvable');
        }
    }
    if (array_key_exists('remuneration_montant', $input)) {
        $m = $input['remuneration_montant'];
        if ($m !== null && $m !== '' && (!is_numeric($m) || (float) $m < 0)) {
            shipp_error(400, 'Montant invalide');
        }
        $c['remuneration_montant'] = ($m === null || $m === '') ? null : (float) $m;
    }
    if (array_key_exists('remuneration_devise', $input)) {
        $c['remuneration_devise'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $input['remuneration_devise']), 0, 3)) ?: 'XOF';
    }
    foreach (['remuneration_periodicite' => 20, 'penalites' => null, 'conditions_particulieres' => null] as $k => $max) {
        if (array_key_exists($k, $input)) {
            $v = trim((string) $input[$k]);
            $c[$k] = $v === '' ? null : ($max ? mb_substr($v, 0, $max) : $v);
        }
    }
    if (array_key_exists('preavis_jours', $input)) {
        $c['preavis_jours'] = $input['preavis_jours'] === '' || $input['preavis_jours'] === null ? null : max(0, (int) $input['preavis_jours']);
    }
    return $c;
}

if ($method === 'POST') {
    $chauffeurId = (int) ($input['chauffeur_id'] ?? 0);
    if (!$chauffeurId || !authz_values_allowed($pdo, $ctx, 'chauffeur_contracts', ['chauffeur_id' => $chauffeurId])
        || !authz_row_allowed($pdo, $ctx, 'chauffeurs', $chauffeurId)) {
        shipp_error(404, 'Chauffeur introuvable');
    }
    $tpl = null;
    if (!empty($input['template_id'])) {
        $t = $pdo->prepare("SELECT * FROM contract_templates WHERE id = ? AND statut = 'actif'");
        $t->execute([(int) $input['template_id']]);
        $tpl = $t->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) {
            shipp_error(400, 'Modele introuvable ou non actif');
        }
    }
    $e = $pdo->prepare('SELECT ecole_id FROM chauffeurs WHERE id = ?');
    $e->execute([$chauffeurId]);
    $ecoleId = $e->fetchColumn() ?: null;
    $c = cc_champs($pdo, $ctx, $input + ['vehicle_id' => null], [
        'chauffeur_id' => $chauffeurId, 'ecole_id' => $ecoleId, 'date_debut' => null, 'date_fin' => null, 'duree_mois' => null,
        'vehicle_id' => null, 'remuneration_montant' => null, 'remuneration_devise' => 'XOF', 'remuneration_periodicite' => null,
        'preavis_jours' => null, 'penalites' => null, 'conditions_particulieres' => null,
    ]);
    $ref = strtoupper(preg_replace('/[^A-Za-z0-9_\/-]/', '', (string) ($input['reference'] ?? '')));
    if ($ref === '') {
        $n = $pdo->prepare('SELECT COUNT(*) FROM chauffeur_contracts WHERE reference LIKE ?');
        $n->execute(['CUV-' . date('Y') . '-%']);
        $ref = sprintf('CUV-%s-%04d', date('Y'), (int) $n->fetchColumn() + 1);
    }
    $c['reference'] = mb_substr($ref, 0, 60);
    [$contenu, $manquantes] = cc_generer($pdo, $tpl, $c);
    try {
        $pdo->prepare(
            "INSERT INTO chauffeur_contracts (ecole_id, chauffeur_id, vehicle_id, type, reference, template_id, template_version, contenu_genere,
                date_debut, date_fin, duree_mois, remuneration_montant, remuneration_devise, remuneration_periodicite, preavis_jours, penalites,
                conditions_particulieres, statut, created_by, updated_at)
             VALUES (?, ?, ?, 'utilisation_vehicule', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'brouillon', ?, NOW())"
        )->execute([$ecoleId, $chauffeurId, $c['vehicle_id'], $c['reference'], $tpl['id'] ?? null, $tpl['version'] ?? null, $contenu,
            $c['date_debut'], $c['date_fin'], $c['duree_mois'], $c['remuneration_montant'], $c['remuneration_devise'], $c['remuneration_periodicite'],
            $c['preavis_jours'], $c['penalites'], $c['conditions_particulieres'], $userId]);
    } catch (PDOException $ex) {
        shipp_error(409, 'Reference de contrat deja utilisee');
    }
    $id = (int) $pdo->lastInsertId();
    shipp_journal($pdo, $ecoleId ? (int) $ecoleId : null, $userId, 'create', 'chauffeur_contracts', $id, 'Contrat ' . $c['reference']);
    echo json_encode(['success' => true, 'id' => $id, 'reference' => $c['reference'], 'variables_manquantes' => $manquantes]);
    exit;
}

// PUT
$id = (int) ($input['id'] ?? 0);
$s = $pdo->prepare('SELECT cc.* FROM chauffeur_contracts cc WHERE cc.id = ? AND (' . $cond[0] . ')');
$s->execute(array_merge([$id], $cond[1]));
$ct = $s->fetch(PDO::FETCH_ASSOC);
if (!$ct) {
    shipp_error(404, 'Contrat introuvable');
}
if (!$sensible) {
    shipp_error(403, 'Modification reservee aux gestionnaires des contrats');
}
$action = (string) ($input['action'] ?? '');
if (in_array($ct['statut'], ['termine', 'resilie'], true)) {
    shipp_error(409, 'Contrat termine ou resilie : lecture seule');
}
$need = ['modifier' => 'can_edit', 'signer' => 'can_edit', 'terminer' => 'can_edit', 'activer' => 'can_validate',
    'suspendre' => 'can_validate', 'reprendre' => 'can_validate', 'resilier' => 'can_validate'][$action] ?? null;
if (!$need) {
    shipp_error(400, 'Action inconnue');
}
authz_require($ctx, 'chauffeur_contracts', $need);
$details = "Action : $action";
$manquantes = [];

switch ($action) {
    case 'modifier':
        if ($ct['statut'] !== 'brouillon') {
            shipp_error(409, 'Seul un brouillon peut etre modifie');
        }
        $c = cc_champs($pdo, $ctx, $input, $ct);
        $tpl = null;
        if ($ct['template_id']) {
            $t = $pdo->prepare('SELECT * FROM contract_templates WHERE id = ?');
            $t->execute([(int) $ct['template_id']]);
            $tpl = $t->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        [$contenu, $manquantes] = cc_generer($pdo, $tpl, $c);
        $pdo->prepare(
            'UPDATE chauffeur_contracts SET vehicle_id = ?, date_debut = ?, date_fin = ?, duree_mois = ?, remuneration_montant = ?, remuneration_devise = ?,
                remuneration_periodicite = ?, preavis_jours = ?, penalites = ?, conditions_particulieres = ?, contenu_genere = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$c['vehicle_id'], $c['date_debut'], $c['date_fin'], $c['duree_mois'], $c['remuneration_montant'], $c['remuneration_devise'],
            $c['remuneration_periodicite'], $c['preavis_jours'], $c['penalites'], $c['conditions_particulieres'], $contenu, $id]);
        break;

    case 'activer':
        if ($ct['statut'] !== 'brouillon') {
            shipp_error(409, 'Seul un brouillon peut etre active');
        }
        if (!$ct['date_debut']) {
            shipp_error(409, "Renseigner la date de debut avant d'activer le contrat");
        }
        if (cc_chevauchement($pdo, (int) $ct['chauffeur_id'], $ct['date_debut'], $ct['date_fin'], $id)) {
            shipp_error(409, 'Le chauffeur a deja un contrat actif sur cette periode');
        }
        $pdo->prepare("UPDATE chauffeur_contracts SET statut = 'actif', updated_at = NOW() WHERE id = ?")->execute([$id]);
        if ($ct['vehicle_id']) {
            // Rattache le contrat a l'affectation vehicule correspondante, si elle n'en a pas.
            $pdo->prepare("UPDATE vehicle_assignments SET contract_id = ? WHERE chauffeur_id = ? AND vehicle_id = ? AND statut IN ('active', 'planifiee') AND contract_id IS NULL")
                ->execute([$id, (int) $ct['chauffeur_id'], (int) $ct['vehicle_id']]);
        }
        break;

    case 'signer':
        $signe = cc_date($input['signe_le'] ?? '') ?? date('Y-m-d');
        $fid = !empty($input['fichier_id']) ? (int) $input['fichier_id'] : null;
        if ($fid) {
            $f = $pdo->prepare("SELECT id FROM fichiers WHERE id = ? AND entite = 'chauffeur_contract' AND entite_id = ? AND retire_at IS NULL");
            $f->execute([$fid, $id]);
            if (!$f->fetch()) {
                shipp_error(400, 'Fichier non rattache a ce contrat');
            }
        }
        $pdo->prepare('UPDATE chauffeur_contracts SET signe_le = ?, fichier_signe_id = COALESCE(?, fichier_signe_id), updated_at = NOW() WHERE id = ?')->execute([$signe, $fid, $id]);
        break;

    case 'suspendre':
    case 'reprendre':
        $motif = trim((string) ($input['motif'] ?? ''));
        if ($motif === '') {
            shipp_error(400, 'Motif obligatoire');
        }
        if (($action === 'suspendre' && $ct['statut'] !== 'actif') || ($action === 'reprendre' && $ct['statut'] !== 'suspendu')) {
            shipp_error(409, 'Transition impossible depuis le statut ' . $ct['statut']);
        }
        $pdo->prepare('UPDATE chauffeur_contracts SET statut = ?, updated_at = NOW() WHERE id = ?')->execute([$action === 'suspendre' ? 'suspendu' : 'actif', $id]);
        $details .= ' — ' . $motif;
        break;

    case 'terminer':
        $fin = cc_date($input['date_fin'] ?? '') ?? date('Y-m-d');
        if ($ct['statut'] === 'brouillon') {
            shipp_error(409, 'Un brouillon ne peut pas etre termine');
        }
        if ($fin < $ct['date_debut']) {
            shipp_error(400, 'La date de fin precede la date de debut');
        }
        $pdo->prepare("UPDATE chauffeur_contracts SET statut = 'termine', date_fin = ?, updated_at = NOW() WHERE id = ?")->execute([$fin, $id]);
        break;

    case 'resilier':
        $motif = trim((string) ($input['motif'] ?? ''));
        $le = cc_date($input['resilie_le'] ?? '') ?? date('Y-m-d');
        if ($motif === '') {
            shipp_error(400, 'Motif de resiliation obligatoire');
        }
        $pdo->prepare("UPDATE chauffeur_contracts SET statut = 'resilie', resilie_le = ?, motif_resiliation = ?, resilie_par = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$le, mb_substr($motif, 0, 255), $userId, $id]);
        $details .= ' — ' . $motif;
        break;
}

shipp_journal($pdo, $ct['ecole_id'] !== null ? (int) $ct['ecole_id'] : null, $userId, 'update', 'chauffeur_contracts', $id, $details);
echo json_encode(['success' => true, 'variables_manquantes' => $manquantes]);
