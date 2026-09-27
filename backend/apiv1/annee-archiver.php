<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require __DIR__ . '/auth-lib.php';
$authUser = require_auth();

$config = @include __DIR__ . '/../db-config.php';
if (!is_array($config) || empty($config['pass'])) {
http_response_code(500);
echo json_encode(['message' => 'Configuration base de donnees manquante']);
exit;
}
try {
$pdo = new PDO(
"mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4",
$config['user'],
$config['pass'],
[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
} catch (Throwable $e) {
http_response_code(500);
echo json_encode(['message' => 'Connexion base de donnees impossible']);
exit;
}

$adminCheck = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
$adminCheck->execute([$authUser['sub']]);
$adminUser = $adminCheck->fetch(PDO::FETCH_ASSOC);
if (!$adminUser || !$adminUser['is_admin']) {
http_response_code(403);
echo json_encode(['message' => 'Acces reserve aux administrateurs']);
exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
http_response_code(405);
echo json_encode(['message' => 'Methode non autorisee']);
exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$libelle = trim($input['libelle'] ?? '');
$dateDebut = trim($input['date_debut'] ?? '');
$dateFin = trim($input['date_fin'] ?? '');

if ($libelle === '' || $dateDebut === '' || $dateFin === '') {
http_response_code(400);
echo json_encode(['message' => 'libelle, date_debut et date_fin sont requis']);
exit;
}

$ecoleRow = $pdo->query('SELECT id FROM ecoles ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$ecoleRow) {
http_response_code(500);
echo json_encode(['message' => 'Aucun etablissement configure']);
exit;
}
$ecoleId = (int) $ecoleRow['id'];

$pdo->beginTransaction();
try {
$pdo->prepare("UPDATE annees_scolaires SET statut = 'archivee' WHERE statut = 'active' AND ecole_id = ?")
->execute([$ecoleId]);

$insert = $pdo->prepare(
'INSERT INTO annees_scolaires (ecole_id, libelle, date_debut, date_fin, statut) VALUES (?, ?, ?, ?, ?)'
);
$insert->execute([$ecoleId, $libelle, $dateDebut, $dateFin, 'active']);
$newId = (int) $pdo->lastInsertId();

$pdo->commit();
} catch (Throwable $e) {
$pdo->rollBack();
http_response_code(500);
echo json_encode(['message' => "Erreur lors de l'archivage", 'error' => $e->getMessage()]);
exit;
}

echo json_encode(['message' => 'ok', 'nouvelle_annee_id' => $newId]);
