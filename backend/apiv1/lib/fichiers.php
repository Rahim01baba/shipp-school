<?php
/**
 * Stockage prive des fichiers (lot 3) : pieces jointes d'incident, documents
 * chauffeur, contrats signes, modeles. Les fichiers sont ecrits dans un
 * dossier HORS de la racine web, configure dans config.local.php
 * ('private_dir'). Sans configuration valide, tout depot est refuse.
 * Aucun fichier n'est supprime physiquement : un retrait est logique.
 * Requiert la migration 003.
 */

const FICHIER_MIMES = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

/**
 * Entites acceptees -> module de droits, table controlee, et indication de
 * contenu sensible (reserve aux perimetres GLOBAL / SCHOOL).
 */
function fichier_entites(): array
{
    return [
        'incident' => ['module' => 'incidents', 'sensible' => false],
        'chauffeur_document' => ['module' => 'chauffeur_documents', 'sensible' => false],
        'chauffeur_contract' => ['module' => 'chauffeur_contracts', 'sensible' => true],
        'contract_template' => ['module' => 'contract_templates', 'sensible' => false],
        'vehicle_document' => ['module' => 'vehicle_documents', 'sensible' => false],
        'import' => ['module' => 'imports', 'sensible' => true],
    ];
}

/** Dossier prive valide (existant, inscriptible, hors racine web), sinon erreur 500. */
function fichier_private_dir(): string
{
    $conf = @include __DIR__ . '/../../config.local.php';
    $dir = is_array($conf) ? ($conf['private_dir'] ?? '') : '';
    if (!$dir) {
        shipp_error(500, 'Stockage prive non configure');
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $real = realpath($dir);
    if (!$real || !is_writable($real)) {
        shipp_error(500, 'Stockage prive inaccessible');
    }
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: null;
    $apiDir = realpath(__DIR__ . '/..');
    foreach ([$docRoot, $apiDir] as $interdit) {
        if ($interdit && ($real === $interdit || strpos($real . '/', rtrim($interdit, '/') . '/') === 0)) {
            shipp_error(500, 'Stockage prive mal configure (dossier accessible depuis le web)');
        }
    }
    return $real;
}

/** L'entite existe-t-elle et est-elle dans le perimetre, pour l'action demandee ? */
function fichier_entite_autorisee(PDO $pdo, array &$ctx, string $entite, int $entiteId, string $flag): bool
{
    $def = fichier_entites()[$entite] ?? null;
    if (!$def || $entiteId <= 0) {
        return false;
    }
    $module = $def['module'];
    if ($flag === 'ecrire') {
        if (!authz_can($ctx, $module, 'can_create') && !authz_can($ctx, $module, 'can_edit')) {
            return false;
        }
    } elseif (!authz_can($ctx, $module, 'can_read')) {
        return false;
    }
    if ($def['sensible'] && !in_array(authz_scope($ctx, $module), ['GLOBAL', 'SCHOOL'], true)) {
        return false;
    }
    return authz_row_allowed($pdo, $ctx, $module, $entiteId);
}

/**
 * Enregistre le fichier televerse ($_FILES[$champ]) pour l'entite.
 * Retourne l'identifiant de la ligne `fichiers`.
 */
function fichier_enregistrer(PDO $pdo, array $upload, string $entite, int $entiteId, ?string $categorie, int $userId, ?int $ecoleId): int
{
    if (!isset($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        shipp_error(400, 'Fichier manquant ou transfert incomplet');
    }
    $maxMo = (int) shipp_param($pdo, 'fichier_taille_max_mo', '10');
    if ($upload['size'] <= 0 || $upload['size'] > $maxMo * 1024 * 1024) {
        shipp_error(400, "Fichier vide ou superieur a {$maxMo} Mo");
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($upload['tmp_name']) ?: '';
    if (!isset(FICHIER_MIMES[$mime])) {
        shipp_error(400, 'Type de fichier non accepte (PDF, JPEG, PNG ou WEBP)');
    }
    $base = fichier_private_dir();
    $sousDossier = $entite . '/' . date('Y/m');
    if (!is_dir($base . '/' . $sousDossier) && !@mkdir($base . '/' . $sousDossier, 0750, true)) {
        shipp_error(500, 'Stockage prive inaccessible');
    }
    $nom = bin2hex(random_bytes(16)) . '.' . FICHIER_MIMES[$mime];
    $chemin = $sousDossier . '/' . $nom;
    if (!move_uploaded_file($upload['tmp_name'], $base . '/' . $chemin)) {
        shipp_error(500, 'Enregistrement du fichier impossible');
    }
    @chmod($base . '/' . $chemin, 0640);
    $original = mb_substr(preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', basename((string) ($upload['name'] ?? 'fichier'))), 0, 200);
    $pdo->prepare(
        'INSERT INTO fichiers (ecole_id, entite, entite_id, categorie, nom_original, chemin, mime, taille, sha256, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$ecoleId, $entite, $entiteId, $categorie ? mb_substr($categorie, 0, 40) : null, $original ?: 'fichier',
        $chemin, $mime, (int) $upload['size'], hash_file('sha256', $base . '/' . $chemin), $userId]);
    return (int) $pdo->lastInsertId();
}

/** Liste des fichiers actifs d'une entite (sans chemin disque). */
function fichier_liste(PDO $pdo, string $entite, int $entiteId): array
{
    $s = $pdo->prepare(
        'SELECT id, categorie, nom_original, mime, taille, uploaded_by, created_at
         FROM fichiers WHERE entite = ? AND entite_id = ? AND retire_at IS NULL ORDER BY id'
    );
    $s->execute([$entite, $entiteId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
