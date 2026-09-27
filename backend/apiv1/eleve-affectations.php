<?php
/**
 * Affectation transport des eleves : circuit + arret de montee + arret de depose.
 * GET  ?circuit_id=N | ?eleve_id=N
 * POST {eleve_id, circuit_id, etape_montee_id?, etape_depose_id?, sens?}
 *      Remplace l'affectation active precedente (conservee en historique) et
 *      met a jour eleves.circuit_id (compatibilite avec l'existant).
 * PUT  {id, etape_montee_id?, etape_depose_id?, sens?} | {id, action: 'terminer'}
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, POST, PUT, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
$method = $_SERVER['REQUEST_METHOD'];
$flag = ['GET' => 'can_read', 'POST' => 'can_create', 'PUT' => 'can_edit'][$method] ?? null;
if (!$flag) {
    shipp_error(405, 'Methode non autorisee');
}
authz_require($ctx, 'eleve_affectations', $flag);
$cond = authz_scope_condition($pdo, $ctx, 'eleve_affectations', 'a.');
if ($cond === null) {
    shipp_error(403, 'Acces refuse pour ce module');
}

function ea_check_etape(PDO $pdo, $etapeId, int $circuitId): ?int
{
    if ($etapeId === null || $etapeId === '') {
        return null;
    }
    $s = $pdo->prepare('SELECT id FROM etapes WHERE id = ? AND circuit_id = ?');
    $s->execute([(int) $etapeId, $circuitId]);
    if (!$s->fetch()) {
        shipp_error(400, "L'arret choisi n'appartient pas a ce circuit");
    }
    return (int) $etapeId;
}

if ($method === 'GET') {
    $where = ['(' . $cond[0] . ')'];
    $params = $cond[1];
    foreach (['circuit_id', 'eleve_id'] as $k) {
        if (!empty($_GET[$k])) {
            $where[] = "a.$k = ?";
            $params[] = (int) $_GET[$k];
        }
    }
    if (empty($_GET['historique'])) {
        $where[] = "a.statut = 'active'";
    }
    $s = $pdo->prepare(
        'SELECT a.*, e.nom, e.prenom, e.classe, c.nom AS circuit_nom, em.nom AS arret_montee, ed.nom AS arret_depose,
                em.heure_estimee AS heure_montee, ed.heure_estimee AS heure_depose
         FROM eleve_affectations_transport a
         JOIN eleves e ON e.id = a.eleve_id
         JOIN circuits c ON c.id = a.circuit_id
         LEFT JOIN etapes em ON em.id = a.etape_montee_id
         LEFT JOIN etapes ed ON ed.id = a.etape_depose_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY e.nom, e.prenom'
    );
    $s->execute($params);
    echo json_encode(['data' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = shipp_json_input();
$sensOk = ['aller', 'retour', 'aller_retour'];

if ($method === 'POST') {
    $eleveId = (int) ($input['eleve_id'] ?? 0);
    $circuitId = (int) ($input['circuit_id'] ?? 0);
    $sens = $input['sens'] ?? 'aller_retour';
    if (!$eleveId || !$circuitId || !in_array($sens, $sensOk, true)) {
        shipp_error(400, 'eleve_id, circuit_id et sens valides requis');
    }
    if (!authz_row_allowed($pdo, $ctx, 'eleves', $eleveId) || !authz_row_allowed($pdo, $ctx, 'circuits', $circuitId)) {
        shipp_error(404, 'Eleve ou circuit introuvable');
    }
    $montee = ea_check_etape($pdo, $input['etape_montee_id'] ?? null, $circuitId);
    $depose = ea_check_etape($pdo, $input['etape_depose_id'] ?? null, $circuitId);
    $e = $pdo->prepare('SELECT ecole_id, annee_scolaire_id FROM eleves WHERE id = ?');
    $e->execute([$eleveId]);
    $el = $e->fetch(PDO::FETCH_ASSOC);
    // Navette d'activite (D-28) : s'ajoute au circuit domicile sans le remplacer.
    $activite = false;
    if (authz_column_exists($pdo, 'circuits', 'type_circuit')) {
        $tc = $pdo->prepare('SELECT type_circuit FROM circuits WHERE id = ?');
        $tc->execute([$circuitId]);
        $activite = $tc->fetchColumn() === 'activite';
    }
    $pdo->beginTransaction();
    if ($activite) {
        $pdo->prepare("UPDATE eleve_affectations_transport SET statut = 'terminee', date_fin = CURDATE() WHERE eleve_id = ? AND circuit_id = ? AND statut = 'active'")->execute([$eleveId, $circuitId]);
    } elseif (authz_column_exists($pdo, 'circuits', 'type_circuit')) {
        $pdo->prepare("UPDATE eleve_affectations_transport a JOIN circuits c ON c.id = a.circuit_id SET a.statut = 'terminee', a.date_fin = CURDATE() WHERE a.eleve_id = ? AND a.statut = 'active' AND c.type_circuit <> 'activite'")->execute([$eleveId]);
    } else {
        $pdo->prepare("UPDATE eleve_affectations_transport SET statut = 'terminee', date_fin = CURDATE() WHERE eleve_id = ? AND statut = 'active'")->execute([$eleveId]);
    }
    $pdo->prepare(
        'INSERT INTO eleve_affectations_transport (ecole_id, annee_scolaire_id, eleve_id, circuit_id, etape_montee_id, etape_depose_id, sens, date_debut, statut)
         VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), \'active\')'
    )->execute([$el['ecole_id'], $el['annee_scolaire_id'], $eleveId, $circuitId, $montee, $depose, $sens]);
    $id = (int) $pdo->lastInsertId();
    if (!$activite) {
        $pdo->prepare('UPDATE eleves SET circuit_id = ? WHERE id = ?')->execute([$circuitId, $eleveId]);
    }
    $pdo->commit();
    shipp_journal($pdo, $el['ecole_id'] !== null ? (int) $el['ecole_id'] : null, (int) $authUser['sub'], 'create', 'eleve_affectations', $id, "Eleve #$eleveId -> circuit #$circuitId");
    echo json_encode(['message' => 'ok', 'id' => $id]);
    exit;
}

// PUT
$id = (int) ($input['id'] ?? 0);
$r = $pdo->prepare('SELECT a.* FROM eleve_affectations_transport a WHERE a.id = ? AND (' . $cond[0] . ')');
$r->execute(array_merge([$id], $cond[1]));
$row = $r->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    shipp_error(404, 'Affectation introuvable');
}
if (($input['action'] ?? '') === 'terminer') {
    $pdo->prepare("UPDATE eleve_affectations_transport SET statut = 'terminee', date_fin = CURDATE() WHERE id = ?")->execute([$id]);
    $pdo->prepare('UPDATE eleves SET circuit_id = NULL WHERE id = ? AND circuit_id = ?')->execute([(int) $row['eleve_id'], (int) $row['circuit_id']]);
} else {
    $sets = [];
    $vals = [];
    if (array_key_exists('etape_montee_id', $input)) {
        $sets[] = 'etape_montee_id = ?';
        $vals[] = ea_check_etape($pdo, $input['etape_montee_id'], (int) $row['circuit_id']);
    }
    if (array_key_exists('etape_depose_id', $input)) {
        $sets[] = 'etape_depose_id = ?';
        $vals[] = ea_check_etape($pdo, $input['etape_depose_id'], (int) $row['circuit_id']);
    }
    if (isset($input['sens'])) {
        if (!in_array($input['sens'], $sensOk, true)) {
            shipp_error(400, 'Sens invalide');
        }
        $sets[] = 'sens = ?';
        $vals[] = $input['sens'];
    }
    if (!$sets) {
        shipp_error(400, 'Aucune donnee fournie');
    }
    $vals[] = $id;
    $pdo->prepare('UPDATE eleve_affectations_transport SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
}
shipp_journal($pdo, $row['ecole_id'] !== null ? (int) $row['ecole_id'] : null, (int) $authUser['sub'], 'update', 'eleve_affectations', $id, null);
echo json_encode(['message' => 'ok']);
