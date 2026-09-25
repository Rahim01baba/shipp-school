<?php
/**
 * Incidents et accidents (lot 3, T5-12 / T5-13).
 *
 * GET  ?id=N   : detail (eleves, actions, details accident, fichiers, historique)
 * GET          : liste filtree — date_debut, date_fin, categorie, statut, gravite,
 *                responsabilite, chauffeur_id, vehicule_id, circuit_id, trajet_id, q,
 *                annee_scolaire_id (N | toutes, perimetres GLOBAL/SCHOOL)
 * POST         : declaration (terrain). La responsabilite est TOUJOURS
 *                'non_determinee' a la creation, quelle que soit la saisie.
 * PUT {id, action} :
 *   modifier        champs descriptifs (can_edit)
 *   statut          {statut: en_cours|resolu|clos|annule|ouvert, motif} (can_edit)
 *   qualifier       {responsabilite, commentaire} — decision humaine (can_validate)
 *   accident        details accident : tiers, constat, police, assurance (can_edit)
 *   suivi           action_corrective, cout_estime, cout_reel, proprietaire_informe_at (can_edit)
 *   eleve_ajouter   {eleve_id, role, blessure} (can_edit)
 *   action_ajouter  {description, type?, echeance?, responsable_user_id?} (can_edit)
 *   action_statut   {action_id, statut} (can_edit)
 * Aucune suppression : un incident errone est passe au statut 'annule'.
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, POST, PUT, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
require __DIR__ . '/lib/transport.php';
require __DIR__ . '/lib/fichiers.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
$userId = (int) $authUser['sub'];
$method = $_SERVER['REQUEST_METHOD'];
$flag = ['GET' => 'can_read', 'POST' => 'can_create', 'PUT' => 'can_edit'][$method] ?? null;
if (!$flag) {
    shipp_error(405, 'Methode non autorisee');
}
authz_require($ctx, 'incidents', $flag);
$cond = authz_scope_condition($pdo, $ctx, 'incidents', 'i.');
if ($cond === null) {
    shipp_error(403, 'Acces refuse pour ce module');
}

const INC_CATEGORIES = ['incident', 'accident'];
const INC_GRAVITES = ['faible', 'moyenne', 'elevee', 'critique'];
const INC_STATUTS = ['ouvert', 'en_cours', 'resolu', 'clos', 'annule'];
const INC_RESPONSABILITES = ['non_determinee', 'chauffeur', 'tiers', 'partagee', 'eleve', 'autre'];
const INC_ROLES_ELEVE = ['implique', 'blesse', 'temoin'];

function inc_datetime($v): ?string
{
    if (!is_string($v) || trim($v) === '') {
        return null;
    }
    $t = strtotime(str_replace('T', ' ', $v));
    return $t === false ? null : date('Y-m-d H:i:s', $t);
}

function inc_date($v): ?string
{
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
}

function inc_num($v): ?float
{
    return ($v === null || $v === '' || !is_numeric($v)) ? null : (float) $v;
}

function inc_historique(PDO $pdo, int $incidentId, ?string $ancien, string $nouveau, string $action, ?string $motif, int $userId): void
{
    $pdo->prepare('INSERT INTO incidents_historique (incident_id, ancien_statut, nouveau_statut, action, motif, user_id) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$incidentId, $ancien, $nouveau, $action, $motif !== null ? mb_substr($motif, 0, 255) : null, $userId]);
}

function inc_charger(PDO $pdo, array $cond, int $id): ?array
{
    $s = $pdo->prepare('SELECT i.* FROM incidents i WHERE i.id = ? AND (' . $cond[0] . ')');
    $s->execute(array_merge([$id], $cond[1]));
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

$selectListe = "SELECT i.*, TRIM(CONCAT(COALESCE(c.prenom, ''), ' ', COALESCE(c.nom, ''))) AS chauffeur_nom,
        v.immatriculation, ci.nom AS circuit_nom,
        (SELECT COUNT(*) FROM incident_eleves ie WHERE ie.incident_id = i.id) AS nb_eleves
    FROM incidents i
    LEFT JOIN chauffeurs c ON c.id = i.chauffeur_id
    LEFT JOIN vehicules v ON v.id = i.vehicule_id
    LEFT JOIN circuits ci ON ci.id = i.circuit_id";

// ------------------------------------------------------------------ GET
if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        $s = $pdo->prepare($selectListe . ' WHERE i.id = ? AND (' . $cond[0] . ')');
        $s->execute(array_merge([$id], $cond[1]));
        $inc = $s->fetch(PDO::FETCH_ASSOC);
        if (!$inc) {
            shipp_error(404, 'Incident introuvable');
        }
        $el = $pdo->prepare('SELECT ie.*, e.nom, e.prenom, e.classe FROM incident_eleves ie JOIN eleves e ON e.id = ie.eleve_id WHERE ie.incident_id = ? ORDER BY ie.id');
        $el->execute([$id]);
        $ac = $pdo->prepare('SELECT a.*, u.name AS responsable_nom FROM incident_actions a LEFT JOIN users u ON u.id = a.responsable_user_id WHERE a.incident_id = ? ORDER BY a.id');
        $ac->execute([$id]);
        $ad = $pdo->prepare('SELECT * FROM accident_details WHERE incident_id = ?');
        $ad->execute([$id]);
        $hi = $pdo->prepare('SELECT h.*, u.name AS user_nom FROM incidents_historique h LEFT JOIN users u ON u.id = h.user_id WHERE h.incident_id = ? ORDER BY h.id');
        $hi->execute([$id]);
        $decl = null;
        if ($inc['declare_par']) {
            $d = $pdo->prepare('SELECT name FROM users WHERE id = ?');
            $d->execute([(int) $inc['declare_par']]);
            $decl = $d->fetchColumn() ?: null;
        }
        $inc['declare_par_nom'] = $decl;
        echo json_encode([
            'data' => $inc,
            'eleves' => $el->fetchAll(PDO::FETCH_ASSOC),
            'actions' => $ac->fetchAll(PDO::FETCH_ASSOC),
            'accident' => $ad->fetch(PDO::FETCH_ASSOC) ?: null,
            'fichiers' => fichier_liste($pdo, 'incident', $id),
            'historique' => $hi->fetchAll(PDO::FETCH_ASSOC),
            'droits' => [
                'modifier' => authz_can($ctx, 'incidents', 'can_edit'),
                'qualifier' => authz_can($ctx, 'incidents', 'can_validate'),
            ],
        ]);
        exit;
    }

    $where = ['(' . $cond[0] . ')'];
    $params = $cond[1];
    $debut = inc_date($_GET['date_debut'] ?? '');
    $fin = inc_date($_GET['date_fin'] ?? '');
    if ($debut) {
        $where[] = 'COALESCE(i.date_incident, DATE(i.created_at)) >= ?';
        $params[] = $debut;
    }
    if ($fin) {
        $where[] = 'COALESCE(i.date_incident, DATE(i.created_at)) <= ?';
        $params[] = $fin;
    }
    $annee = $_GET['annee_scolaire_id'] ?? '';
    $peutHistorique = in_array(authz_scope($ctx, 'incidents'), ['GLOBAL', 'SCHOOL'], true);
    if ($annee === 'toutes' && $peutHistorique) {
        // pas de filtre annee
    } elseif ($annee !== '' && ctype_digit((string) $annee) && $peutHistorique) {
        $where[] = 'i.annee_scolaire_id = ?';
        $params[] = (int) $annee;
    } elseif (!$debut && !$fin) {
        $active = shipp_annee_active($pdo);
        if ($active) {
            $where[] = 'i.annee_scolaire_id = ?';
            $params[] = $active;
        }
    }
    foreach (['categorie' => INC_CATEGORIES, 'statut' => INC_STATUTS, 'gravite' => INC_GRAVITES, 'responsabilite' => INC_RESPONSABILITES] as $k => $allowed) {
        if (!empty($_GET[$k]) && in_array($_GET[$k], $allowed, true)) {
            $where[] = "i.$k = ?";
            $params[] = $_GET[$k];
        }
    }
    foreach (['chauffeur_id', 'vehicule_id', 'circuit_id', 'trajet_id'] as $k) {
        if (!empty($_GET[$k])) {
            $where[] = "i.$k = ?";
            $params[] = (int) $_GET[$k];
        }
    }
    if (!empty($_GET['eleve_id'])) {
        $where[] = '(i.eleve_id = ? OR EXISTS (SELECT 1 FROM incident_eleves ie2 WHERE ie2.incident_id = i.id AND ie2.eleve_id = ?))';
        $params[] = (int) $_GET['eleve_id'];
        $params[] = (int) $_GET['eleve_id'];
    }
    if (!empty($_GET['q'])) {
        $where[] = '(i.titre LIKE ? OR i.description LIKE ? OR i.lieu LIKE ?)';
        $like = '%' . $_GET['q'] . '%';
        array_push($params, $like, $like, $like);
    }
    $s = $pdo->prepare($selectListe . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(i.survenu_at, i.date_incident, i.created_at) DESC, i.id DESC LIMIT 500');
    $s->execute($params);
    echo json_encode(['data' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = shipp_json_input();
$sets = authz_sets($pdo, $ctx);
$scope = authz_scope($ctx, 'incidents');

// ------------------------------------------------------------------ POST : declaration
if ($method === 'POST') {
    $categorie = in_array($input['categorie'] ?? '', INC_CATEGORIES, true) ? $input['categorie'] : 'incident';
    $gravite = in_array($input['gravite'] ?? '', INC_GRAVITES, true) ? $input['gravite'] : ($categorie === 'accident' ? 'elevee' : 'moyenne');
    $titre = trim((string) ($input['titre'] ?? ''));
    if ($titre === '') {
        shipp_error(400, 'Titre requis');
    }
    $survenu = inc_datetime($input['survenu_at'] ?? '') ?? date('Y-m-d H:i:s');
    if (strtotime($survenu) > time() + 600) {
        shipp_error(400, "La date de l'incident ne peut pas etre dans le futur");
    }

    $trajetId = !empty($input['trajet_id']) ? (int) $input['trajet_id'] : null;
    $circuitId = !empty($input['circuit_id']) ? (int) $input['circuit_id'] : null;
    $chauffeurId = !empty($input['chauffeur_id']) ? (int) $input['chauffeur_id'] : null;
    $vehiculeId = !empty($input['vehicule_id']) ? (int) $input['vehicule_id'] : null;
    $ecoleId = null;
    $anneeId = null;

    if ($trajetId) {
        // Le contexte vient du trajet (chauffeur / vehicule figes au demarrage).
        if (!authz_row_allowed($pdo, $ctx, 'trajets', $trajetId)) {
            shipp_error(404, 'Trajet introuvable');
        }
        $t = $pdo->prepare('SELECT * FROM trajets WHERE id = ?');
        $t->execute([$trajetId]);
        $trajet = $t->fetch(PDO::FETCH_ASSOC);
        $circuitId = (int) $trajet['circuit_id'];
        $chauffeurId = $chauffeurId ?: ($trajet['chauffeur_id'] ? (int) $trajet['chauffeur_id'] : circuit_titulaire_chauffeur_id($pdo, $circuitId));
        $vehiculeId = $vehiculeId ?: ($trajet['vehicle_id'] ? (int) $trajet['vehicle_id'] : chauffeur_vehicle_on($pdo, $chauffeurId, substr($survenu, 0, 10)));
        $ecoleId = (int) $trajet['ecole_id'];
        $anneeId = (int) $trajet['annee_scolaire_id'];
    }

    if ($scope === 'ASSIGNED_ROUTE') {
        // Un chauffeur declare pour lui-meme, sur ses circuits.
        $mine = $sets['my_chauffeurs'];
        if (!$mine) {
            shipp_error(403, 'Aucune fiche chauffeur liee a ce compte');
        }
        if ($chauffeurId && !in_array($chauffeurId, $mine, true)) {
            shipp_error(403, 'Vous ne pouvez declarer que vos propres incidents');
        }
        $chauffeurId = $chauffeurId ?: $mine[0];
        if ($circuitId && !in_array($circuitId, $sets['route_circuits'], true)) {
            shipp_error(403, 'Circuit hors de votre perimetre');
        }
        $vehiculeId = $vehiculeId ?: chauffeur_vehicle_on($pdo, $chauffeurId, substr($survenu, 0, 10));
    } else {
        if ($chauffeurId && !authz_row_allowed($pdo, $ctx, 'chauffeurs', $chauffeurId)) {
            shipp_error(404, 'Chauffeur introuvable');
        }
        if ($circuitId && !authz_row_allowed($pdo, $ctx, 'circuits', $circuitId)) {
            shipp_error(404, 'Circuit introuvable');
        }
        if ($vehiculeId && !$trajetId && !authz_row_allowed($pdo, $ctx, 'vehicules', $vehiculeId)) {
            shipp_error(404, 'Vehicule introuvable');
        }
    }

    // Eleves concernes : dans le perimetre de l'auteur.
    $eleves = [];
    foreach ((array) ($input['eleves'] ?? []) as $e) {
        $eid = (int) (is_array($e) ? ($e['eleve_id'] ?? 0) : $e);
        if (!$eid) {
            continue;
        }
        if (!authz_row_allowed($pdo, $ctx, 'eleves', $eid)) {
            shipp_error(403, "Eleve #$eid hors de votre perimetre");
        }
        $role = is_array($e) && in_array($e['role'] ?? '', INC_ROLES_ELEVE, true) ? $e['role'] : 'implique';
        $eleves[$eid] = ['role' => $role, 'blessure' => is_array($e) ? mb_substr(trim((string) ($e['blessure'] ?? '')), 0, 255) : ''];
    }

    if (!$ecoleId) {
        foreach ([['circuits', $circuitId], ['chauffeurs', $chauffeurId], ['vehicules', $vehiculeId]] as [$tbl, $rid]) {
            if ($rid) {
                $q = $pdo->prepare("SELECT ecole_id FROM `$tbl` WHERE id = ?");
                $q->execute([$rid]);
                $v = $q->fetchColumn();
                if ($v) {
                    $ecoleId = (int) $v;
                    break;
                }
            }
        }
        $ecoleId = $ecoleId ?: ($ctx['ecole_ids'][0] ?? null);
    }
    $anneeId = $anneeId ?: shipp_annee_active($pdo);
    if (!$ecoleId || !$anneeId) {
        shipp_error(400, 'Etablissement ou annee scolaire active introuvable');
    }

    $pdo->beginTransaction();
    $pdo->prepare(
        "INSERT INTO incidents (ecole_id, annee_scolaire_id, titre, description, type, gravite, circuit_id, vehicule_id, chauffeur_id, eleve_id,
            statut, date_incident, categorie, trajet_id, survenu_at, lieu, latitude, longitude, mesures_immediates, responsabilite, declare_par, source, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ouvert', ?, ?, ?, ?, ?, ?, ?, ?, 'non_determinee', ?, 'app', NOW())"
    )->execute([
        $ecoleId, $anneeId, mb_substr($titre, 0, 150), trim((string) ($input['description'] ?? '')) ?: null,
        mb_substr(preg_replace('/[^a-z_]/', '', strtolower((string) ($input['type'] ?? 'autre'))) ?: 'autre', 0, 30), $gravite,
        $circuitId, $vehiculeId, $chauffeurId, $eleves ? array_key_first($eleves) : null,
        substr($survenu, 0, 10), $categorie, $trajetId, $survenu,
        trim((string) ($input['lieu'] ?? '')) ?: null, inc_num($input['latitude'] ?? null), inc_num($input['longitude'] ?? null),
        trim((string) ($input['mesures_immediates'] ?? '')) ?: null, $userId,
    ]);
    $id = (int) $pdo->lastInsertId();
    inc_historique($pdo, $id, null, 'ouvert', 'declaration', $categorie === 'accident' ? 'Accident declare' : 'Incident declare', $userId);

    if ($categorie === 'accident' && is_array($input['accident'] ?? null)) {
        $a = $input['accident'];
        $pdo->prepare(
            'INSERT INTO accident_details (incident_id, tiers_implique, tiers_nom, tiers_telephone, tiers_immatriculation, constat_amiable, rapport_police, blesses, nb_blesses, degats_vehicule, vehicule_immobilise)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, !empty($a['tiers_implique']) ? 1 : 0, $a['tiers_nom'] ?? null, $a['tiers_telephone'] ?? null, $a['tiers_immatriculation'] ?? null,
            !empty($a['constat_amiable']) ? 1 : 0, !empty($a['rapport_police']) ? 1 : 0, !empty($a['blesses']) ? 1 : 0,
            isset($a['nb_blesses']) && $a['nb_blesses'] !== '' ? (int) $a['nb_blesses'] : null, $a['degats_vehicule'] ?? null, !empty($a['vehicule_immobilise']) ? 1 : 0]);
    }

    $evtId = transport_event($pdo, 'INCIDENT_DECLARED', [
        'ecole_id' => $ecoleId, 'annee_scolaire_id' => $anneeId, 'trajet_id' => $trajetId, 'circuit_id' => $circuitId,
        'chauffeur_id' => $chauffeurId, 'vehicle_id' => $vehiculeId, 'incident_id' => $id, 'survenu_at' => $survenu,
        'cree_par' => $userId, 'details' => json_encode(['categorie' => $categorie, 'gravite' => $gravite]),
    ]);

    // Parents des eleves concernes : message neutre (aucune responsabilite evoquee).
    $insEl = $pdo->prepare('INSERT INTO incident_eleves (incident_id, eleve_id, role, blessure, parents_notifies_at) VALUES (?, ?, ?, ?, ?)');
    $notifies = 0;
    foreach ($eleves as $eid => $e) {
        $n = 0;
        if ($e['role'] !== 'temoin') {
            $nom = eleve_nom_complet($pdo, $eid);
            $msg = ($categorie === 'accident' ? 'Un accident' : 'Un incident') . " concernant $nom a ete signale le " . date('d/m/Y a H:i', strtotime($survenu))
                . ($e['role'] === 'blesse' ? '. Votre enfant est signale comme blesse.' : '.') . " L'etablissement vous tiendra informe.";
            $n = notify_parents($pdo, $eid, $categorie === 'accident' ? 'Accident signale' : 'Incident signale', $msg, 'incident',
                ['ecole_id' => $ecoleId, 'trajet_id' => $trajetId, 'chauffeur_id' => $chauffeurId, 'vehicle_id' => $vehiculeId, 'evenement_id' => $evtId, 'incident_id' => $id]);
            $notifies += $n;
        }
        $insEl->execute([$id, $eid, $e['role'], $e['blessure'] ?: null, $n > 0 ? date('Y-m-d H:i:s') : null]);
    }
    $pdo->commit();
    shipp_journal($pdo, $ecoleId, $userId, 'create', 'incidents', $id, ucfirst($categorie) . ' : ' . $titre);
    echo json_encode(['success' => true, 'id' => $id, 'parents_notifies' => $notifies]);
    exit;
}

// ------------------------------------------------------------------ PUT
$id = (int) ($input['id'] ?? 0);
$inc = $id ? inc_charger($pdo, $cond, $id) : null;
if (!$inc) {
    shipp_error(404, 'Incident introuvable');
}
$action = (string) ($input['action'] ?? '');
$termine = in_array($inc['statut'], ['clos', 'annule'], true);
if ($termine && !in_array($action, ['statut'], true)) {
    shipp_error(409, 'Incident clos ou annule : rouvrir avant toute modification');
}

switch ($action) {
    case 'modifier':
        $champs = [];
        $vals = [];
        foreach (['titre' => 150, 'description' => null, 'lieu' => 255, 'mesures_immediates' => null, 'type' => 30] as $k => $max) {
            if (array_key_exists($k, $input)) {
                $v = trim((string) $input[$k]);
                if ($k === 'titre' && $v === '') {
                    shipp_error(400, 'Titre requis');
                }
                $champs[] = "`$k` = ?";
                $vals[] = $v === '' ? null : ($max ? mb_substr($v, 0, $max) : $v);
            }
        }
        if (array_key_exists('gravite', $input)) {
            if (!in_array($input['gravite'], INC_GRAVITES, true)) {
                shipp_error(400, 'Gravite invalide');
            }
            $champs[] = '`gravite` = ?';
            $vals[] = $input['gravite'];
        }
        if (array_key_exists('categorie', $input)) {
            if (!in_array($input['categorie'], INC_CATEGORIES, true)) {
                shipp_error(400, 'Categorie invalide');
            }
            $champs[] = '`categorie` = ?';
            $vals[] = $input['categorie'];
        }
        if (array_key_exists('survenu_at', $input)) {
            $d = inc_datetime($input['survenu_at']);
            if (!$d) {
                shipp_error(400, 'Date invalide');
            }
            array_push($champs, '`survenu_at` = ?', '`date_incident` = ?');
            array_push($vals, $d, substr($d, 0, 10));
        }
        if (!$champs) {
            shipp_error(400, 'Aucun champ a modifier');
        }
        $vals[] = $id;
        $pdo->prepare('UPDATE incidents SET ' . implode(', ', $champs) . ', updated_at = NOW() WHERE id = ?')->execute($vals);
        inc_historique($pdo, $id, $inc['statut'], $inc['statut'], 'modification', 'Champs : ' . implode(', ', array_map(function ($c) { return trim(explode('=', $c)[0], '` '); }, $champs)), $userId);
        break;

    case 'statut':
        $nouveau = (string) ($input['statut'] ?? '');
        $motif = trim((string) ($input['motif'] ?? ''));
        if (!in_array($nouveau, INC_STATUTS, true) || $nouveau === $inc['statut']) {
            shipp_error(400, 'Statut invalide');
        }
        if ($termine && $nouveau !== 'ouvert') {
            shipp_error(409, 'Un incident clos ou annule ne peut etre que rouvert');
        }
        if (in_array($nouveau, ['annule', 'ouvert'], true) && $motif === '') {
            shipp_error(400, 'Motif obligatoire pour annuler ou rouvrir');
        }
        if ($nouveau === 'clos' && $inc['categorie'] === 'accident' && !$inc['qualifiee_at']) {
            shipp_error(409, "Un accident doit etre qualifie (responsabilite) avant d'etre clos");
        }
        if ($nouveau === 'clos') {
            $pdo->prepare('UPDATE incidents SET statut = ?, date_cloture = NOW(), cloture_par = ?, updated_at = NOW() WHERE id = ?')->execute([$nouveau, $userId, $id]);
        } elseif ($nouveau === 'ouvert') {
            $pdo->prepare('UPDATE incidents SET statut = ?, date_cloture = NULL, cloture_par = NULL, updated_at = NOW() WHERE id = ?')->execute([$nouveau, $id]);
        } else {
            $pdo->prepare('UPDATE incidents SET statut = ?, updated_at = NOW() WHERE id = ?')->execute([$nouveau, $id]);
        }
        inc_historique($pdo, $id, $inc['statut'], $nouveau, $nouveau === 'ouvert' ? 'reouverture' : 'changement_statut', $motif ?: null, $userId);
        break;

    case 'qualifier':
        if (!authz_can($ctx, 'incidents', 'can_validate')) {
            shipp_error(403, 'Qualification reservee aux responsables habilites');
        }
        $resp = (string) ($input['responsabilite'] ?? '');
        $commentaire = trim((string) ($input['commentaire'] ?? ''));
        if (!in_array($resp, INC_RESPONSABILITES, true)) {
            shipp_error(400, 'Responsabilite invalide');
        }
        if ($commentaire === '') {
            shipp_error(400, 'Un commentaire justifiant la qualification est obligatoire');
        }
        $pdo->prepare('UPDATE incidents SET responsabilite = ?, responsabilite_commentaire = ?, qualifiee_par = ?, qualifiee_at = NOW(), updated_at = NOW() WHERE id = ?')
            ->execute([$resp, $commentaire, $userId, $id]);
        inc_historique($pdo, $id, $inc['statut'], $inc['statut'], 'qualification', "Responsabilite : $resp (precedente : {$inc['responsabilite']}) — " . $commentaire, $userId);
        break;

    case 'accident':
        if ($inc['categorie'] !== 'accident') {
            shipp_error(409, "Cet element n'est pas un accident");
        }
        $a = is_array($input['accident'] ?? null) ? $input['accident'] : [];
        $bool = ['tiers_implique', 'constat_amiable', 'rapport_police', 'blesses', 'vehicule_immobilise'];
        $txt = ['tiers_nom' => 150, 'tiers_telephone' => 30, 'tiers_immatriculation' => 30, 'tiers_assurance' => 150, 'reference_police' => 100, 'degats_vehicule' => null, 'reference_sinistre' => 100];
        $pdo->prepare('INSERT IGNORE INTO accident_details (incident_id) VALUES (?)')->execute([$id]);
        $champs = [];
        $vals = [];
        foreach ($bool as $k) {
            if (array_key_exists($k, $a)) {
                $champs[] = "`$k` = ?";
                $vals[] = !empty($a[$k]) ? 1 : 0;
            }
        }
        foreach ($txt as $k => $max) {
            if (array_key_exists($k, $a)) {
                $v = trim((string) $a[$k]);
                $champs[] = "`$k` = ?";
                $vals[] = $v === '' ? null : ($max ? mb_substr($v, 0, $max) : $v);
            }
        }
        if (array_key_exists('nb_blesses', $a)) {
            $champs[] = '`nb_blesses` = ?';
            $vals[] = $a['nb_blesses'] === '' || $a['nb_blesses'] === null ? null : max(0, (int) $a['nb_blesses']);
        }
        if (array_key_exists('franchise', $a)) {
            $champs[] = '`franchise` = ?';
            $vals[] = inc_num($a['franchise']);
        }
        if (array_key_exists('assurance_declaree_at', $a)) {
            $champs[] = '`assurance_declaree_at` = ?';
            $vals[] = inc_date((string) $a['assurance_declaree_at']);
        }
        if ($champs) {
            $vals[] = $id;
            $pdo->prepare('UPDATE accident_details SET ' . implode(', ', $champs) . ', updated_at = NOW() WHERE incident_id = ?')->execute($vals);
        }
        inc_historique($pdo, $id, $inc['statut'], $inc['statut'], 'details_accident', null, $userId);
        break;

    case 'suivi':
        $champs = [];
        $vals = [];
        if (array_key_exists('action_corrective', $input)) {
            $champs[] = 'action_corrective = ?';
            $vals[] = trim((string) $input['action_corrective']) ?: null;
        }
        foreach (['cout_estime', 'cout_reel'] as $k) {
            if (array_key_exists($k, $input)) {
                $v = inc_num($input[$k]);
                if ($v !== null && $v < 0) {
                    shipp_error(400, 'Montant invalide');
                }
                $champs[] = "$k = ?";
                $vals[] = $v;
            }
        }
        if (array_key_exists('proprietaire_informe_at', $input)) {
            $champs[] = 'proprietaire_informe_at = ?';
            $vals[] = inc_datetime((string) $input['proprietaire_informe_at']);
        }
        if (!$champs) {
            shipp_error(400, 'Aucun champ a modifier');
        }
        $vals[] = $id;
        $pdo->prepare('UPDATE incidents SET ' . implode(', ', $champs) . ', updated_at = NOW() WHERE id = ?')->execute($vals);
        inc_historique($pdo, $id, $inc['statut'], $inc['statut'], 'suivi', null, $userId);
        break;

    case 'eleve_ajouter':
        $eid = (int) ($input['eleve_id'] ?? 0);
        $role = in_array($input['role'] ?? '', INC_ROLES_ELEVE, true) ? $input['role'] : 'implique';
        if (!$eid || !authz_row_allowed($pdo, $ctx, 'eleves', $eid)) {
            shipp_error(404, 'Eleve introuvable');
        }
        try {
            $pdo->prepare('INSERT INTO incident_eleves (incident_id, eleve_id, role, blessure) VALUES (?, ?, ?, ?)')
                ->execute([$id, $eid, $role, trim((string) ($input['blessure'] ?? '')) ?: null]);
        } catch (PDOException $e) {
            shipp_error(409, 'Eleve deja rattache a cet incident');
        }
        inc_historique($pdo, $id, $inc['statut'], $inc['statut'], 'eleve_ajoute', eleve_nom_complet($pdo, $eid) . " ($role)", $userId);
        break;

    case 'action_ajouter':
        $desc = trim((string) ($input['description'] ?? ''));
        if ($desc === '') {
            shipp_error(400, 'Description requise');
        }
        $pdo->prepare('INSERT INTO incident_actions (incident_id, type, description, responsable_user_id, echeance, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, mb_substr(preg_replace('/[^a-z_]/', '', (string) ($input['type'] ?? 'corrective')) ?: 'corrective', 0, 30), $desc,
                !empty($input['responsable_user_id']) ? (int) $input['responsable_user_id'] : null, inc_date((string) ($input['echeance'] ?? '')), $userId]);
        inc_historique($pdo, $id, $inc['statut'], $inc['statut'], 'action_ajoutee', mb_substr($desc, 0, 200), $userId);
        break;

    case 'action_statut':
        $aid = (int) ($input['action_id'] ?? 0);
        $st = (string) ($input['statut'] ?? '');
        if (!in_array($st, ['a_faire', 'en_cours', 'faite', 'abandonnee'], true)) {
            shipp_error(400, 'Statut invalide');
        }
        $ex = $pdo->prepare('SELECT id FROM incident_actions WHERE id = ? AND incident_id = ?');
        $ex->execute([$aid, $id]);
        if (!$ex->fetch()) {
            shipp_error(404, 'Action introuvable');
        }
        $pdo->prepare('UPDATE incident_actions SET statut = ?, fait_at = ' . ($st === 'faite' ? 'NOW()' : 'NULL') . ' WHERE id = ?')->execute([$st, $aid]);
        inc_historique($pdo, $id, $inc['statut'], $inc['statut'], 'action_statut', "Action #$aid : $st", $userId);
        break;

    default:
        shipp_error(400, 'Action inconnue');
}

shipp_journal($pdo, (int) $inc['ecole_id'], $userId, 'update', 'incidents', $id, 'Action : ' . $action);
echo json_encode(['success' => true]);
