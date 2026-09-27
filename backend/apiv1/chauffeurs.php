<?php
/**
 * Fiches chauffeurs (distinctes des comptes utilisateurs, user_id facultatif).
 * GET  ?id=N           : une fiche (avec vehicule et circuits du moment)
 * GET                  : liste dans le perimetre
 * POST                 : creation (option : creer_compte + password)
 * PUT  {id, ...}       : modification
 * Un chauffeur ne voit que sa propre fiche.
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, POST, PUT, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
require __DIR__ . '/lib/transport.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
$method = $_SERVER['REQUEST_METHOD'];
$flag = ['GET' => 'can_read', 'POST' => 'can_create', 'PUT' => 'can_edit'][$method] ?? null;
if (!$flag) {
    shipp_error(405, 'Methode non autorisee');
}
authz_require($ctx, 'chauffeurs', $flag);

const CHAUFFEUR_FIELDS = ['nom', 'prenom', 'telephone', 'email', 'adresse', 'date_naissance', 'urgence_nom', 'urgence_telephone', 'statut', 'date_entree', 'date_sortie', 'perimetre'];

function chauffeur_enrichir(PDO $pdo, array $c): array
{
    $today = date('Y-m-d');
    $c['vehicule_actuel'] = null;
    $vid = chauffeur_vehicle_on($pdo, (int) $c['id'], $today);
    if ($vid) {
        $v = $pdo->prepare('SELECT id, immatriculation, modele, statut FROM vehicules WHERE id = ?');
        $v->execute([$vid]);
        $c['vehicule_actuel'] = $v->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $c['circuits'] = [];
    if (!empty($c['user_id'])) {
        $s = $pdo->prepare("SELECT c.id, c.nom, 'titulaire' AS role FROM affectations_chauffeur ac JOIN circuits c ON c.id = ac.circuit_id WHERE ac.user_id = ?
            UNION SELECT c.id, c.nom, 'remplacant' AS role FROM couvertures_chauffeur cc JOIN circuits c ON c.id = cc.circuit_id
            WHERE cc.chauffeur_remplacant_id = ? AND cc.date_debut <= CURDATE() AND (cc.date_fin IS NULL OR cc.date_fin >= CURDATE())");
        $s->execute([(int) $c['user_id'], (int) $c['user_id']]);
        $c['circuits'] = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    $c['a_un_compte'] = !empty($c['user_id']);
    return $c;
}

if ($method === 'GET') {
    $cond = authz_scope_condition($pdo, $ctx, 'chauffeurs');
    if ($cond === null) {
        shipp_error(403, 'Acces refuse pour ce module');
    }
    if (!empty($_GET['id'])) {
        $s = $pdo->prepare('SELECT * FROM chauffeurs WHERE id = ? AND (' . $cond[0] . ')');
        $s->execute(array_merge([(int) $_GET['id']], $cond[1]));
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            shipp_error(404, 'Chauffeur introuvable');
        }
        echo json_encode(['data' => chauffeur_enrichir($pdo, $c)]);
        exit;
    }
    $where = ['(' . $cond[0] . ')'];
    $params = $cond[1];
    if (!empty($_GET['statut'])) {
        $where[] = 'statut = ?';
        $params[] = $_GET['statut'];
    }
    if (!empty($_GET['q'])) {
        $where[] = '(nom LIKE ? OR prenom LIKE ? OR telephone LIKE ?)';
        $q = '%' . $_GET['q'] . '%';
        array_push($params, $q, $q, $q);
    }
    $s = $pdo->prepare('SELECT * FROM chauffeurs WHERE ' . implode(' AND ', $where) . ' ORDER BY nom, prenom');
    $s->execute($params);
    $rows = array_map(function ($c) use ($pdo) { return chauffeur_enrichir($pdo, $c); }, $s->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['data' => $rows]);
    exit;
}

$input = shipp_json_input();
if (isset($input['telephone'])) {
    $input['telephone'] = preg_replace('/[^0-9+]/', '', (string) $input['telephone']) ?: null;
}
if (isset($input['email']) && $input['email'] !== '' && $input['email'] !== null && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
    shipp_error(400, 'E-mail invalide');
}
if (isset($input['statut']) && !in_array($input['statut'], ['actif', 'suspendu', 'sorti'], true)) {
    shipp_error(400, 'Statut invalide');
}

if ($method === 'POST') {
    if (trim((string) ($input['nom'] ?? '')) === '' || empty($input['telephone'])) {
        shipp_error(400, 'Nom et telephone requis');
    }
    $dup = $pdo->prepare('SELECT id FROM chauffeurs WHERE telephone = ?');
    $dup->execute([$input['telephone']]);
    if ($dup->fetch()) {
        shipp_error(409, 'Un chauffeur avec ce telephone existe deja');
    }
    $ecoleRow = $pdo->query('SELECT id FROM ecoles ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $ecoleId = $ecoleRow ? (int) $ecoleRow['id'] : null;
    $pdo->beginTransaction();
    try {
        $userId = null;
        // Option : creer le compte de connexion (telephone + mot de passe), role chauffeur.
        if (!empty($input['creer_compte'])) {
            $pwd = (string) ($input['password'] ?? '');
            if (strlen($pwd) < 8) {
                throw new InvalidArgumentException('Mot de passe du compte : 8 caracteres minimum');
            }
            $u = $pdo->prepare('SELECT id FROM users WHERE telephone = ? OR (email IS NOT NULL AND email = ?)');
            $u->execute([$input['telephone'], $input['email'] ?? '']);
            if ($u->fetch()) {
                throw new InvalidArgumentException('Un compte existe deja avec ce telephone ou cet e-mail');
            }
            $pdo->prepare('INSERT INTO users (name, email, telephone, password_hash, ecole_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([trim(($input['prenom'] ?? '') . ' ' . $input['nom']), ($input['email'] ?? '') ?: null, $input['telephone'], password_hash($pwd, PASSWORD_BCRYPT), $ecoleId]);
            $userId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO user_roles (user_id, role_id, ecole_id) SELECT ?, id, ? FROM roles WHERE role_key = 'chauffeur'")->execute([$userId, $ecoleId]);
        } elseif (!empty($input['user_id'])) {
            $userId = (int) $input['user_id'];
        }
        $cols = ['ecole_id', 'user_id'];
        $vals = [$ecoleId, $userId];
        foreach (CHAUFFEUR_FIELDS as $f) {
            if (array_key_exists($f, $input)) {
                $cols[] = $f;
                $vals[] = $input[$f] === '' ? null : $input[$f];
            }
        }
        $pdo->prepare('INSERT INTO chauffeurs (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute($vals);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        shipp_error(400, $e->getMessage());
    }
    shipp_journal($pdo, $ecoleId, (int) $authUser['sub'], 'create', 'chauffeurs', $id, 'Fiche chauffeur ' . $input['nom'] . ($userId ? ' (avec compte)' : ''));
    echo json_encode(['message' => 'ok', 'id' => $id, 'user_id' => $userId]);
    exit;
}

// PUT
$id = (int) ($input['id'] ?? 0);
if (!$id || !authz_row_allowed($pdo, $ctx, 'chauffeurs', $id)) {
    shipp_error(404, 'Chauffeur introuvable');
}
$sets = [];
$vals = [];
foreach (CHAUFFEUR_FIELDS as $f) {
    if (array_key_exists($f, $input)) {
        $sets[] = "`$f` = ?";
        $vals[] = $input[$f] === '' ? null : $input[$f];
    }
}
if (!$sets) {
    shipp_error(400, 'Aucune donnee fournie');
}
$vals[] = $id;
$pdo->prepare('UPDATE chauffeurs SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
shipp_journal($pdo, null, (int) $authUser['sub'], 'update', 'chauffeurs', $id, 'Champs : ' . implode(', ', array_keys(array_intersect_key($input, array_flip(CHAUFFEUR_FIELDS)))));
echo json_encode(['message' => 'ok']);
