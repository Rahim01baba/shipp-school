<?php
/**
 * Connexion PDO partagee et utilitaires communs (nouveaux endpoints).
 * Les endpoints historiques gardent leur propre connexion ; ils peuvent
 * migrer progressivement vers ce fichier.
 */

function shipp_headers(string $methods): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function shipp_db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $config = @include __DIR__ . '/../../db-config.php';
    if (!is_array($config) || empty($config['pass'])) {
        shipp_error(500, 'Configuration base de donnees manquante');
    }
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4",
            $config['user'],
            $config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable $e) {
        shipp_error(500, 'Connexion base de donnees impossible');
    }
    return $pdo;
}

function shipp_error(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['message' => $message]);
    exit;
}

function shipp_json_input(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

/** Annee scolaire active (la plus recente au statut 'active'). */
function shipp_annee_active(PDO $pdo): ?int
{
    static $id = false;
    if ($id === false) {
        $row = $pdo->query("SELECT id FROM annees_scolaires WHERE statut = 'active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $id = $row ? (int) $row['id'] : null;
    }
    return $id;
}

/** Journal d'activite best-effort. */
function shipp_journal(PDO $pdo, ?int $ecoleId, int $userId, string $action, string $module, ?int $recordId, ?string $details = null): void
{
    try {
        $pdo->prepare('INSERT INTO journal_activite (ecole_id, user_id, action, module_key, record_id, details) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$ecoleId, $userId, $action, $module, $recordId, $details !== null ? mb_substr($details, 0, 500) : null]);
    } catch (Throwable $e) {
        // best-effort
    }
}
