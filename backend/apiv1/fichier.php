<?php
/**
 * Fichiers prives (hors racine web).
 * GET  ?id=N                        : telechargement (controle des droits sur l'entite)
 * GET  ?entite=incident&entite_id=N : liste des fichiers de l'entite
 * POST multipart {fichier, entite, entite_id, categorie?} : depot
 * PUT  {id, action: 'retirer'}      : retrait logique (le fichier reste sur disque)
 */
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/transport.php';
require __DIR__ . '/lib/fichiers.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    shipp_headers('GET, POST, PUT, OPTIONS');
}
require __DIR__ . '/auth-lib.php';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
$userId = (int) $authUser['sub'];

if ($method === 'GET' && !empty($_GET['id'])) {
    $s = $pdo->prepare('SELECT * FROM fichiers WHERE id = ? AND retire_at IS NULL');
    $s->execute([(int) $_GET['id']]);
    $f = $s->fetch(PDO::FETCH_ASSOC);
    if (!$f || !fichier_entite_autorisee($pdo, $ctx, $f['entite'], (int) $f['entite_id'], 'lire')) {
        header('Content-Type: application/json; charset=utf-8');
        shipp_error(404, 'Fichier introuvable');
    }
    $base = fichier_private_dir();
    $path = realpath($base . '/' . $f['chemin']);
    if (!$path || strpos($path, $base . '/') !== 0 || !is_file($path)) {
        header('Content-Type: application/json; charset=utf-8');
        shipp_error(404, 'Fichier introuvable');
    }
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    $disposition = !empty($_GET['telecharger']) ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $f['nom_original']) . '"');
    readfile($path);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($method === 'GET') {
    $entite = (string) ($_GET['entite'] ?? '');
    $entiteId = (int) ($_GET['entite_id'] ?? 0);
    if (!fichier_entite_autorisee($pdo, $ctx, $entite, $entiteId, 'lire')) {
        shipp_error(404, 'Element introuvable');
    }
    echo json_encode(['data' => fichier_liste($pdo, $entite, $entiteId)]);
    exit;
}

if ($method === 'POST') {
    $entite = (string) ($_POST['entite'] ?? '');
    $entiteId = (int) ($_POST['entite_id'] ?? 0);
    if (!fichier_entite_autorisee($pdo, $ctx, $entite, $entiteId, 'ecrire')) {
        shipp_error(403, 'Depot non autorise pour cet element');
    }
    if ($entite === 'chauffeur_document' || $entite === 'vehicle_document') {
        $st = $pdo->prepare('SELECT statut FROM ' . ($entite === 'chauffeur_document' ? 'chauffeur_documents' : 'vehicle_documents') . ' WHERE id = ?');
        $st->execute([$entiteId]);
        if ($st->fetchColumn() === 'remplace') {
            shipp_error(409, 'Document remplace : historique en lecture seule');
        }
    }
    $module = fichier_entites()[$entite]['module'];
    $table = authz_module_columns()[$module]['table'];
    $e = $pdo->prepare('SELECT ecole_id FROM `' . $table . '` WHERE id = ?');
    $e->execute([$entiteId]);
    $ecoleId = $e->fetchColumn();
    $id = fichier_enregistrer($pdo, $_FILES['fichier'] ?? [], $entite, $entiteId, $_POST['categorie'] ?? null, $userId, $ecoleId !== false && $ecoleId !== null ? (int) $ecoleId : null);
    if ($entite === 'chauffeur_document' || $entite === 'vehicle_document') {
        // Le dernier scan depose devient la piece du document, qui repasse en verification.
        $pdo->prepare('UPDATE ' . ($entite === 'chauffeur_document' ? 'chauffeur_documents' : 'vehicle_documents') . " SET fichier_id = ?, statut = 'a_verifier', updated_at = NOW() WHERE id = ?")
            ->execute([$id, $entiteId]);
    }
    shipp_journal($pdo, $ecoleId ? (int) $ecoleId : null, $userId, 'create', 'fichiers', $id, "Depot sur $entite #$entiteId");
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($method === 'PUT') {
    $input = shipp_json_input();
    $id = (int) ($input['id'] ?? 0);
    if (($input['action'] ?? '') !== 'retirer') {
        shipp_error(400, 'Action inconnue');
    }
    $s = $pdo->prepare('SELECT * FROM fichiers WHERE id = ? AND retire_at IS NULL');
    $s->execute([$id]);
    $f = $s->fetch(PDO::FETCH_ASSOC);
    if (!$f || !fichier_entite_autorisee($pdo, $ctx, $f['entite'], (int) $f['entite_id'], 'ecrire')
        || !authz_can($ctx, fichier_entites()[$f['entite']]['module'], 'can_edit')) {
        shipp_error(404, 'Fichier introuvable');
    }
    $pdo->prepare('UPDATE fichiers SET retire_at = NOW(), retire_par = ? WHERE id = ?')->execute([$userId, $id]);
    shipp_journal($pdo, $f['ecole_id'] !== null ? (int) $f['ecole_id'] : null, $userId, 'update', 'fichiers', $id, 'Retrait logique');
    echo json_encode(['success' => true]);
    exit;
}

shipp_error(405, 'Methode non autorisee');
