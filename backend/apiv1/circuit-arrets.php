<?php
/**
 * Editeur cartographique des arrets d'un circuit (migration 006).
 *
 * GET ?circuit_id=N  : arrets du circuit (ordre du matin) avec latitude / longitude
 * PUT {circuit_id, arrets: [{id?, nom, latitude?, longitude?, heure_estimee?}, ...]}
 *     Enregistre la liste complete dans l'ordre donne (ordre du matin, maison -> ecole).
 *     - arret avec id : nom, position, heure et rang mis a jour ;
 *     - arret sans id : cree ;
 *     - arret absent de la liste : supprime seulement s'il n'a jamais servi
 *       (passages, affectations d'eleves, scans, evenements) ; sinon refus.
 * Le trajet du soir parcourt automatiquement ces arrets dans l'ordre inverse.
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, PUT, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
require __DIR__ . '/lib/gps.php';

$ctx = authz_load($pdo, (int) $authUser['sub']);
$method = $_SERVER['REQUEST_METHOD'];
$coords = authz_column_exists($pdo, 'etapes', 'latitude');

if ($method === 'GET') {
    authz_require($ctx, 'etapes', 'can_read');
    $cid = (int) ($_GET['circuit_id'] ?? 0);
    if (!$cid || !authz_row_allowed($pdo, $ctx, 'circuits', $cid)) {
        shipp_error(404, 'Circuit introuvable');
    }
    echo json_encode(['circuit_id' => $cid, 'coordonnees' => $coords, 'data' => circuit_etapes($pdo, $cid, 'aller', false)]);
    exit;
}

if ($method !== 'PUT') {
    shipp_error(405, 'Methode non autorisee');
}
authz_require($ctx, 'etapes', 'can_edit');
if (!$coords) {
    shipp_error(503, 'Positions des arrets non activees sur ce serveur');
}
$in = shipp_json_input();
$cid = (int) ($in['circuit_id'] ?? 0);
if (!$cid || !authz_row_allowed($pdo, $ctx, 'circuits', $cid)) {
    shipp_error(404, 'Circuit introuvable');
}
$liste = is_array($in['arrets'] ?? null) ? array_values($in['arrets']) : null;
if ($liste === null || count($liste) > 100) {
    shipp_error(400, 'Liste des arrets requise (100 maximum)');
}
$c = $pdo->prepare('SELECT ecole_id FROM circuits WHERE id = ?');
$c->execute([$cid]);
$ecoleId = $c->fetchColumn();

$ex = $pdo->prepare('SELECT id FROM etapes WHERE circuit_id = ?');
$ex->execute([$cid]);
$existants = array_map('intval', $ex->fetchAll(PDO::FETCH_COLUMN));

$propres = [];
foreach ($liste as $i => $a) {
    $nom = trim((string) ($a['nom'] ?? ''));
    if ($nom === '' || mb_strlen($nom) > 150) {
        shipp_error(400, 'Arret ' . ($i + 1) . ' : nom requis (150 caracteres maximum)');
    }
    $lat = $a['latitude'] ?? null;
    $lng = $a['longitude'] ?? null;
    if (($lat === null || $lat === '') !== ($lng === null || $lng === '')) {
        shipp_error(400, 'Arret « ' . $nom . ' » : latitude et longitude vont ensemble');
    }
    if ($lat !== null && $lat !== '' && (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180)) {
        shipp_error(400, 'Arret « ' . $nom . ' » : coordonnees invalides');
    }
    $heure = trim((string) ($a['heure_estimee'] ?? ''));
    if ($heure !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/', $heure)) {
        shipp_error(400, 'Arret « ' . $nom . ' » : heure invalide (HH:MM)');
    }
    $id = isset($a['id']) && $a['id'] ? (int) $a['id'] : null;
    if ($id && !in_array($id, $existants, true)) {
        shipp_error(400, 'Arret ' . $id . " : n'appartient pas a ce circuit");
    }
    $propres[] = [
        'id' => $id, 'nom' => $nom,
        'lat' => $lat === null || $lat === '' ? null : round((float) $lat, 7),
        'lng' => $lng === null || $lng === '' ? null : round((float) $lng, 7),
        'heure' => $heure !== '' ? substr($heure, 0, 5) . ':00' : null,
    ];
}
$gardes = array_filter(array_column($propres, 'id'));
if (count($gardes) !== count(array_unique($gardes))) {
    shipp_error(400, 'Un arret apparait deux fois');
}
$aSupprimer = array_values(array_diff($existants, $gardes));
if (array_filter($propres, function ($p) { return !$p['id']; }) && !authz_can($ctx, 'etapes', 'can_create')) {
    shipp_error(403, "Vous n'avez pas le droit de creer des arrets");
}
if ($aSupprimer && !authz_can($ctx, 'etapes', 'can_delete')) {
    shipp_error(403, "Vous n'avez pas le droit de supprimer des arrets");
}
// Un arret qui a deja servi n'est jamais supprime (historique conserve).
if ($aSupprimer) {
    $in2 = implode(',', array_fill(0, count($aSupprimer), '?'));
    $refs = [];
    foreach ([
        "SELECT etape_id FROM trajet_passages WHERE etape_id IN ($in2)",
        "SELECT etape_montee_id FROM eleve_affectations_transport WHERE etape_montee_id IN ($in2)",
        "SELECT etape_depose_id FROM eleve_affectations_transport WHERE etape_depose_id IN ($in2)",
        "SELECT etape_id FROM scans WHERE etape_id IN ($in2)",
        "SELECT etape_id FROM transport_events WHERE etape_id IN ($in2)",
        "SELECT etape_courante_id FROM trajets WHERE etape_courante_id IN ($in2)",
    ] as $q) {
        try {
            $s = $pdo->prepare($q);
            $s->execute($aSupprimer);
            $refs = array_merge($refs, array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) {
            // table absente sur une ancienne base : rien a verifier
        }
    }
    $refs = array_values(array_unique($refs));
    if ($refs) {
        $n = $pdo->prepare('SELECT nom FROM etapes WHERE id IN (' . implode(',', array_fill(0, count($refs), '?')) . ')');
        $n->execute($refs);
        shipp_error(409, 'Arret(s) deja utilise(s), suppression impossible : ' . implode(', ', $n->fetchAll(PDO::FETCH_COLUMN)) . '. Conservez-les ou desactivez-les.');
    }
}

$pdo->beginTransaction();
try {
    // Deux temps pour respecter la cle unique (circuit, ordre) pendant la reorganisation.
    $pdo->prepare('UPDATE etapes SET ordre = ordre + 100000 WHERE circuit_id = ?')->execute([$cid]);
    if ($aSupprimer) {
        $pdo->prepare('DELETE FROM etapes WHERE circuit_id = ? AND id IN (' . implode(',', array_fill(0, count($aSupprimer), '?')) . ')')->execute(array_merge([$cid], $aSupprimer));
    }
    $upd = $pdo->prepare('UPDATE etapes SET nom = ?, latitude = ?, longitude = ?, heure_estimee = ?, ordre = ? WHERE id = ? AND circuit_id = ?');
    $ins = $pdo->prepare("INSERT INTO etapes (ecole_id, circuit_id, nom, ordre, heure_estimee, statut, latitude, longitude) VALUES (?, ?, ?, ?, ?, 'active', ?, ?)");
    foreach ($propres as $i => $p) {
        if ($p['id']) {
            $upd->execute([$p['nom'], $p['lat'], $p['lng'], $p['heure'], $i + 1, $p['id'], $cid]);
        } else {
            $ins->execute([$ecoleId, $cid, $p['nom'], $i + 1, $p['heure'], $p['lat'], $p['lng']]);
        }
    }
    // Arrets inactifs non listes (s'il y en a) : places apres, ordre conserve.
    $reste = $pdo->prepare('SELECT id FROM etapes WHERE circuit_id = ? AND ordre > 100000 ORDER BY ordre');
    $reste->execute([$cid]);
    $rang = count($propres);
    foreach ($reste->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $pdo->prepare('UPDATE etapes SET ordre = ? WHERE id = ?')->execute([++$rang, (int) $rid]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    shipp_error(500, "Enregistrement des arrets impossible");
}
echo json_encode(['message' => 'ok', 'data' => circuit_etapes($pdo, $cid, 'aller', false)]);
