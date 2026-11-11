<?php
/**
 * Inscription autonome des parents et liaison par code enfant (migration 007).
 *
 * Le code parent est aleatoire, remis individuellement a chaque famille.
 * Ce n'est jamais l'identifiant de l'eleve (sequentiel, donc devinable).
 * Alphabet sans caracteres ambigus (pas de 0/O, 1/I/L) : 31^8 combinaisons.
 */

const PARENT_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const PARENT_CODE_PREFIXE = 'SHP';

/** La migration 007 est-elle appliquee ? */
function parents_disponible(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT code_parent FROM eleves LIMIT 0');
        $pdo->query('SELECT id FROM securite_tentatives LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Parametre (table parametres), valeur par defaut si absent. */
function parents_param(PDO $pdo, string $cle, string $defaut): string
{
    try {
        $s = $pdo->prepare('SELECT valeur FROM parametres WHERE cle = ? ORDER BY ecole_id IS NULL, ecole_id LIMIT 1');
        $s->execute([$cle]);
        $v = $s->fetchColumn();
        return $v !== false ? (string) $v : $defaut;
    } catch (Throwable $e) {
        return $defaut;
    }
}

/** Nouveau code : SHP-XXXX-XXXX. */
function parents_nouveau_code(): string
{
    $n = strlen(PARENT_CODE_ALPHABET);
    $c = '';
    for ($i = 0; $i < 8; $i++) {
        $c .= PARENT_CODE_ALPHABET[random_int(0, $n - 1)];
    }
    return PARENT_CODE_PREFIXE . '-' . substr($c, 0, 4) . '-' . substr($c, 4, 4);
}

/** Normalise une saisie (espaces, minuscules, tirets ou prefixe oublies). */
function parents_normaliser_code(string $saisie): ?string
{
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $saisie));
    if (strlen($s) === 8 + strlen(PARENT_CODE_PREFIXE) && strpos($s, PARENT_CODE_PREFIXE) === 0) {
        $s = substr($s, strlen(PARENT_CODE_PREFIXE));
    }
    // 0, O, 1, I et L n'existent pas dans l'alphabet : une saisie qui en contient est invalide.
    if (strlen($s) !== 8 || strspn($s, PARENT_CODE_ALPHABET) !== 8) {
        return null;
    }
    return PARENT_CODE_PREFIXE . '-' . substr($s, 0, 4) . '-' . substr($s, 4, 4);
}

/** Attribue un code unique a l'eleve (reessaie en cas de collision). */
function parents_attribuer_code(PDO $pdo, int $eleveId): string
{
    $u = $pdo->prepare('UPDATE eleves SET code_parent = ? WHERE id = ?');
    for ($essai = 0; $essai < 5; $essai++) {
        $code = parents_nouveau_code();
        try {
            $u->execute([$code, $eleveId]);
            return $code;
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
    }
    throw new RuntimeException('Impossible de generer un code unique');
}

/** Telephone normalise : chiffres et + ; indicatif +225 ajoute a un numero local a 10 chiffres. */
function parents_telephone(?string $v): ?string
{
    $t = preg_replace('/[^0-9+]/', '', (string) $v);
    if ($t === '') {
        return null;
    }
    if (strpos($t, '00') === 0) {
        $t = '+' . substr($t, 2);
    }
    if ($t[0] !== '+' && strlen($t) === 10) {
        $t = '+225' . $t;
    }
    return substr($t, 0, 30);
}

function parents_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Nombre de tentatives (echouees ou toutes) depuis N minutes, par utilisateur ou par IP. */
function parents_tentatives(PDO $pdo, string $type, ?int $userId, ?string $ip, int $minutes, bool $echecsSeulement): int
{
    $where = ['type = ?', 'created_at >= ?'];
    $params = [$type, date('Y-m-d H:i:s', time() - $minutes * 60)];
    if ($userId !== null) {
        $where[] = 'user_id = ?';
        $params[] = $userId;
    }
    if ($ip !== null) {
        $where[] = 'ip = ?';
        $params[] = $ip;
    }
    if ($echecsSeulement) {
        $where[] = 'succes = 0';
    }
    $s = $pdo->prepare('SELECT COUNT(*) FROM securite_tentatives WHERE ' . implode(' AND ', $where));
    $s->execute($params);
    return (int) $s->fetchColumn();
}

function parents_noter_tentative(PDO $pdo, string $type, ?int $userId, bool $succes): void
{
    $pdo->prepare('INSERT INTO securite_tentatives (type, user_id, ip, succes, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$type, $userId, parents_ip(), $succes ? 1 : 0, date('Y-m-d H:i:s')]);
}

/** Identifiant du role parent (table roles). */
function parents_role_id(PDO $pdo): ?int
{
    $s = $pdo->prepare("SELECT id FROM roles WHERE role_key = 'parent' LIMIT 1");
    $s->execute();
    $v = $s->fetchColumn();
    return $v !== false ? (int) $v : null;
}

/** L'utilisateur a-t-il le role parent ? */
function parents_est_parent(PDO $pdo, int $userId): bool
{
    $s = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.role_key = 'parent' LIMIT 1");
    $s->execute([$userId]);
    return (bool) $s->fetchColumn();
}

/**
 * Previent les administrateurs (notification individuelle). Best-effort mais
 * jamais silencieux : un echec est ecrit dans le journal d'erreurs PHP.
 */
function parents_prevenir_admins(PDO $pdo, ?int $ecoleId, string $titre, string $message): void
{
    try {
        $admins = $pdo->query("SELECT id FROM users WHERE is_admin = 1 AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        if (!$admins) {
            return;
        }
        $pdo->prepare('INSERT INTO notifications (ecole_id, titre, message, cible, statut) VALUES (?, ?, ?, ?, ?)')
            ->execute([$ecoleId, mb_substr($titre, 0, 150), mb_substr($message, 0, 500), 'admins', 'envoyee']);
        $nid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO notification_recipients (notification_id, user_id, envoye_at) VALUES (?, ?, NOW())');
        foreach ($admins as $uid) {
            $ins->execute([$nid, (int) $uid]);
        }
    } catch (Throwable $e) {
        error_log('[SHIPP] notification admins (liaison parent) : ' . $e->getMessage());
    }
}
