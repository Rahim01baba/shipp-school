<?php
/**
 * Import du fichier de suivi ENKO (lot 5, T5-17 / T5-18). Reserve au module « imports ».
 *
 * Deroule (rien n'est ecrit dans les donnees metier avant la validation) :
 *  1. POST multipart {fichier}                     -> lot « analyse » : feuilles, lignes, mois lus,
 *                                                     annee scolaire suggeree d'apres les MOIS (jamais le nom de feuille)
 *  2. PUT {lot_id, action:'preparer', feuille, annee_scolaire_id, ecole_id?, options}
 *                                                  -> lignes classees ok / avertissement / erreur / insuffisant,
 *                                                     valeurs a faire correspondre (conducteurs)
 *  3. PUT {action:'correspondances', items:[{type:'conducteur', valeur_source, cible_id}]}
 *  4. PUT {lot_id, action:'valider'}               -> creation en transaction (eleves, abonnements, echeances
 *                                                     mensuelles Enko / Shipp, tarifs, affectations si demande)
 *  5. PUT {lot_id, action:'annuler', motif}         -> retrait de ce que le lot a cree, si rien ne s'y est rattache
 * GET  [?lot_id=N]
 *
 * Garanties : le fichier n'est jamais modifie (copie privee + empreinte SHA-256) ;
 * un meme fichier et une meme feuille ne sont importes qu'une fois ; aucune
 * relation n'est devinee (conducteur -> chauffeur uniquement par correspondance
 * saisie ; aucun vehicule deduit d'un simple type) ; les mois hors de l'annee
 * choisie ne sont pas importes (pas de melange d'annees).
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, POST, PUT, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
require __DIR__ . '/lib/transport.php';
require __DIR__ . '/lib/fichiers.php';
require __DIR__ . '/lib/xlsx.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
$userId = (int) $authUser['sub'];
$method = $_SERVER['REQUEST_METHOD'];
authz_require($ctx, 'imports', $method === 'GET' ? 'can_read' : 'can_create');
if (!in_array(authz_scope($ctx, 'imports'), ['GLOBAL', 'SCHOOL'], true)) {
    shipp_error(403, 'Imports reserves a l\'administration');
}

const IMP_MOIS = ['janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7,
    'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12];

function imp_norm($s): string
{
    $s = (string) $s;
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $t = $t === false ? $s : $t;
    return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($t)));
}

/** Colonnes reconnues d'apres l'en-tete ; les mois sont convertis en date (1er du mois). */
function imp_colonnes(array $entete): array
{
    $map = ['mois' => []];
    foreach ($entete as $col => $label) {
        $n = imp_norm($label);
        if ($n === '') {
            continue;
        }
        if (preg_match('/^([a-z]+) (\d{4})$/', $n, $m) && isset(IMP_MOIS[$m[1]])) {
            $map['mois'][$col] = sprintf('%04d-%02d-01', (int) $m[2], IMP_MOIS[$m[1]]);
            continue;
        }
        $cles = [
            'nom' => '/^nom prenom/', 'statut' => '/^statut/', 'campus' => '/^campus/', 'classe' => '/^classe/',
            'ligne' => '/^ligne$/', 'commune' => '/^commune/', 'localisation' => '/^localisation/', 'point' => '/^appellation/',
            'conducteur' => '/^conducteur/', 'vehicule' => '/^vehicule/', 'montant' => '/^montant/', 'paiement' => '/^type de pay/',
            'extras' => '/^activite extra/', 'cours_jeudi' => '/^cours en ligne/',
        ];
        foreach ($cles as $k => $re) {
            if (!isset($map[$k]) && preg_match($re, $n)) {
                $map[$k] = $col;
            }
        }
    }
    return $map;
}

/** Ligne d'en-tete : la premiere qui contient « Nom & Prenom ». */
function imp_entete(array $valeurs): ?int
{
    foreach ($valeurs as $r => $cells) {
        foreach ($cells as $v) {
            if (is_string($v) && preg_match('/^nom prenom/', imp_norm($v))) {
                return $r;
            }
        }
        if ($r > 20) {
            break;
        }
    }
    return null;
}

/** Separation NOM / Prenoms : mots en majuscules en tete = nom. */
function imp_nom_prenom(string $full): array
{
    $mots = preg_split('/\s+/', trim(preg_replace('/\s+/', ' ', $full)));
    $nom = [];
    $i = 0;
    while ($i < count($mots) && $mots[$i] === mb_strtoupper($mots[$i]) && preg_match('/\p{L}/u', $mots[$i])) {
        $nom[] = $mots[$i];
        $i++;
    }
    if (count($nom) === count($mots)) {
        // Tout en majuscules : premier mot = nom, a verifier.
        return [$mots[0], implode(' ', array_slice($mots, 1)), true];
    }
    if (!$nom) {
        return [$mots[0], implode(' ', array_slice($mots, 1)), true];
    }
    return [implode(' ', $nom), implode(' ', array_slice($mots, $i)), false];
}

function imp_classe($v): ?string
{
    $c = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $v)));
    if ($c === '') {
        return null;
    }
    if (preg_match('/^(PYP|MYP|DP|PRE-?K)[ _]?(\d)$/', $c, $m)) {
        return str_replace('PREK', 'PRE-K', $m[1]) . $m[2];
    }
    return $c;
}

function imp_campus($v): ?string
{
    $n = imp_norm($v);
    if ($n === '') {
        return null;
    }
    $canon = ['enko angre' => 'Enko Angré', 'enko riviera' => 'Enko Riviera', 'enko annexe' => 'Enko Annexe'];
    return $canon[$n] ?? trim((string) $v);
}

/** Statut Excel -> statut d'abonnement ; null = valeur multiple ou inconnue. */
function imp_statut($v): ?string
{
    $n = imp_norm($v);
    $parts = array_values(array_filter(array_map('trim', explode(',', (string) $v))));
    if (count($parts) > 1 && !preg_match('/loca transmise/', $n)) {
        return null;
    }
    if (preg_match('/^valide/', $n)) {
        return 'actif';
    }
    if (preg_match('/arrete/', $n)) {
        return 'resilie';
    }
    if (preg_match('/interr?e?ss|inscript ok|reco program/', $n)) {
        return 'en_attente';
    }
    return null;
}

/** Etiquettes d'un mois : Enko / Shipp / Non / Arret S/c. */
function imp_mois_cellule($v): ?array
{
    if ($v === null || $v === '') {
        return null;
    }
    $r = ['enko' => 0, 'shipp' => 0, 'arret' => 0, 'inconnu' => []];
    foreach (explode(',', (string) $v) as $tok) {
        $t = imp_norm($tok);
        if ($t === 'enko') {
            $r['enko'] = 1;
        } elseif ($t === 'shipp') {
            $r['shipp'] = 1;
        } elseif ($t === 'non' || $t === '') {
            // rien recu
        } elseif (strpos($t, 'arret') === 0) {
            $r['arret'] = 1;
        } else {
            $r['inconnu'][] = trim($tok);
        }
    }
    return $r;
}

function imp_lot(PDO $pdo, int $id): array
{
    $s = $pdo->prepare('SELECT * FROM import_lots WHERE id = ?');
    $s->execute([$id]);
    $lot = $s->fetch(PDO::FETCH_ASSOC);
    if (!$lot) {
        shipp_error(404, 'Lot introuvable');
    }
    return $lot;
}

function imp_book(array $lot): array
{
    $path = fichier_private_dir() . '/' . $lot['chemin'];
    if (!is_file($path) || hash_file('sha256', $path) !== $lot['sha256']) {
        shipp_error(500, 'Fichier du lot introuvable ou altere');
    }
    try {
        return xlsx_open($path);
    } catch (Throwable $e) {
        shipp_error(400, $e->getMessage());
    }
}

/** Annees scolaires qui contiennent chacun des mois lus. */
function imp_suggestion(PDO $pdo, array $mois): array
{
    $out = [];
    foreach ($pdo->query('SELECT id, libelle, date_debut, date_fin, statut FROM annees_scolaires ORDER BY date_debut')->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $n = 0;
        foreach ($mois as $m) {
            if ($m >= date('Y-m-01', strtotime($a['date_debut'])) && $m <= $a['date_fin']) {
                $n++;
            }
        }
        $a['mois_couverts'] = $n;
        $out[] = $a;
    }
    usort($out, function ($x, $y) {
        return $y['mois_couverts'] <=> $x['mois_couverts'];
    });
    return $out;
}

// ------------------------------------------------------------------ GET
if ($method === 'GET') {
    if (!empty($_GET['lot_id'])) {
        $lot = imp_lot($pdo, (int) $_GET['lot_id']);
        $l = $pdo->prepare('SELECT id, numero_ligne, donnees, statut, messages, eleve_id FROM import_lignes WHERE lot_id = ? ORDER BY numero_ligne');
        $l->execute([(int) $lot['id']]);
        $lignes = array_map(function ($r) {
            $r['donnees'] = json_decode($r['donnees'], true);
            $r['messages'] = $r['messages'] ? json_decode($r['messages'], true) : [];
            return $r;
        }, $l->fetchAll(PDO::FETCH_ASSOC));
        $lot['options'] = $lot['options'] ? json_decode($lot['options'], true) : null;
        $lot['stats'] = $lot['stats'] ? json_decode($lot['stats'], true) : null;
        $c = $pdo->prepare('SELECT type, valeur_source, cible_id, cible_valeur FROM import_correspondances WHERE ecole_id <=> ?');
        $c->execute([$lot['ecole_id']]);
        echo json_encode(['lot' => $lot, 'lignes' => $lignes, 'correspondances' => $c->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    $s = $pdo->query("SELECT l.id, l.nom_fichier, l.feuille, l.statut, l.stats, l.created_at, l.valide_at, a.libelle AS annee, u.name AS auteur
                      FROM import_lots l LEFT JOIN annees_scolaires a ON a.id = l.annee_scolaire_id LEFT JOIN users u ON u.id = l.created_by
                      ORDER BY l.id DESC LIMIT 200");
    $rows = array_map(function ($r) {
        $r['stats'] = $r['stats'] ? json_decode($r['stats'], true) : null;
        return $r;
    }, $s->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['data' => $rows]);
    exit;
}

// ------------------------------------------------------------------ POST : depot du fichier
if ($method === 'POST') {
    $up = $_FILES['fichier'] ?? null;
    if (!$up || ($up['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) {
        shipp_error(400, 'Fichier manquant');
    }
    if ($up['size'] > 20 * 1024 * 1024 || !preg_match('/\.xlsx$/i', (string) $up['name'])) {
        shipp_error(400, 'Fichier .xlsx de 20 Mo maximum attendu');
    }
    $sha = hash_file('sha256', $up['tmp_name']);
    try {
        $book = xlsx_open($up['tmp_name']);
    } catch (Throwable $e) {
        shipp_error(400, $e->getMessage());
    }
    $base = fichier_private_dir();
    $dir = 'import/' . date('Y/m');
    if (!is_dir("$base/$dir") && !@mkdir("$base/$dir", 0750, true)) {
        shipp_error(500, 'Stockage prive inaccessible');
    }
    $chemin = $dir . '/' . bin2hex(random_bytes(16)) . '.xlsx';
    if (!move_uploaded_file($up['tmp_name'], "$base/$chemin")) {
        shipp_error(500, 'Enregistrement du fichier impossible');
    }
    @chmod("$base/$chemin", 0640);
    $book = xlsx_open("$base/$chemin");
    $feuilles = [];
    foreach ($book['feuilles'] as $f) {
        $rows = xlsx_rows($book, $f['nom']);
        $h = imp_entete($rows['valeurs']);
        $info = ['nom' => $f['nom'], 'etat' => $f['etat'], 'importable' => false, 'lignes' => 0, 'mois' => [], 'deja_importee' => false];
        if ($h !== null) {
            $cols = imp_colonnes($rows['valeurs'][$h]);
            $n = 0;
            foreach ($rows['valeurs'] as $r => $cells) {
                if ($r > $h && isset($cols['nom']) && trim((string) ($cells[$cols['nom']] ?? '')) !== '') {
                    $n++;
                }
            }
            $info['importable'] = isset($cols['nom'], $cols['statut']);
            $info['lignes'] = $n;
            $info['mois'] = array_values(array_map(function ($m) { return substr($m, 0, 7); }, $cols['mois']));
            $info['suggestion_annees'] = imp_suggestion($pdo, array_values($cols['mois']));
            $d = $pdo->prepare("SELECT id FROM import_lots WHERE sha256 = ? AND feuille = ? AND statut = 'valide' LIMIT 1");
            $d->execute([$sha, $f['nom']]);
            $info['deja_importee'] = (bool) $d->fetch();
        }
        $feuilles[] = $info;
    }
    $pdo->prepare('INSERT INTO import_lots (ecole_id, type, nom_fichier, chemin, sha256, stats, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ctx['ecole_ids'][0] ?? null, 'enko_suivi', mb_substr(basename((string) $up['name']), 0, 255), $chemin, $sha, json_encode(['feuilles' => $feuilles]), $userId]);
    $id = (int) $pdo->lastInsertId();
    shipp_journal($pdo, $ctx['ecole_ids'][0] ?? null, $userId, 'create', 'imports', $id, 'Fichier depose : ' . $up['name']);
    echo json_encode(['success' => true, 'lot_id' => $id, 'feuilles' => $feuilles]);
    exit;
}

// ------------------------------------------------------------------ PUT
$input = shipp_json_input();
$action = (string) ($input['action'] ?? '');

if ($action === 'correspondances') {
    $ecoleId = isset($input['ecole_id']) && $input['ecole_id'] !== '' ? (int) $input['ecole_id'] : ($ctx['ecole_ids'][0] ?? null);
    $n = 0;
    foreach ((array) ($input['items'] ?? []) as $it) {
        $type = (string) ($it['type'] ?? '');
        $src = trim((string) ($it['valeur_source'] ?? ''));
        if (!in_array($type, ['conducteur'], true) || $src === '') {
            continue;
        }
        $cible = !empty($it['cible_id']) ? (int) $it['cible_id'] : null;
        if ($cible && !authz_row_allowed($pdo, $ctx, 'chauffeurs', $cible)) {
            shipp_error(404, "Chauffeur introuvable pour « $src »");
        }
        $pdo->prepare('DELETE FROM import_correspondances WHERE ecole_id <=> ? AND type = ? AND valeur_source = ?')->execute([$ecoleId, $type, imp_norm($src)]);
        if ($cible) {
            $pdo->prepare('INSERT INTO import_correspondances (ecole_id, type, valeur_source, cible_id, cible_valeur, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$ecoleId, $type, imp_norm($src), $cible, mb_substr($src, 0, 150), $userId]);
            $n++;
        }
    }
    echo json_encode(['success' => true, 'enregistrees' => $n]);
    exit;
}

$lot = imp_lot($pdo, (int) ($input['lot_id'] ?? 0));

if ($action === 'preparer') {
    if ($lot['statut'] !== 'analyse') {
        shipp_error(409, 'Lot deja valide ou annule');
    }
    $feuille = (string) ($input['feuille'] ?? '');
    $anneeId = (int) ($input['annee_scolaire_id'] ?? 0);
    $a = $pdo->prepare('SELECT * FROM annees_scolaires WHERE id = ?');
    $a->execute([$anneeId]);
    $annee = $a->fetch(PDO::FETCH_ASSOC);
    if (!$annee) {
        shipp_error(400, "Choisir l'annee scolaire du lot (elle n'est jamais deduite du nom de la feuille)");
    }
    $d = $pdo->prepare("SELECT id FROM import_lots WHERE sha256 = ? AND feuille = ? AND statut = 'valide' LIMIT 1");
    $d->execute([$lot['sha256'], $feuille]);
    if ($d->fetch()) {
        shipp_error(409, 'Cette feuille de ce fichier a deja ete importee');
    }
    $ecoleId = isset($input['ecole_id']) && $input['ecole_id'] !== '' ? (int) $input['ecole_id'] : ((int) $annee['ecole_id'] ?: ($ctx['ecole_ids'][0] ?? null));
    if (!$ecoleId) {
        shipp_error(400, "Choisir l'etablissement du lot");
    }
    $book = imp_book($lot);
    try {
        $rows = xlsx_rows($book, $feuille);
    } catch (Throwable $e) {
        shipp_error(400, $e->getMessage());
    }
    $h = imp_entete($rows['valeurs']);
    if ($h === null) {
        shipp_error(400, 'Feuille sans colonne « Nom & Prenom » : non importable');
    }
    $cols = imp_colonnes($rows['valeurs'][$h]);
    if (!isset($cols['nom'], $cols['statut'])) {
        shipp_error(400, 'Colonnes « Nom & Prenom » et « Statut » requises');
    }
    $debutAnnee = date('Y-m-01', strtotime($annee['date_debut']));
    $finAnnee = $annee['date_fin'];
    $options = [
        'circuits_par_conducteur' => !empty($input['options']['circuits_par_conducteur']),
        'creer_tarifs' => !isset($input['options']['creer_tarifs']) || !empty($input['options']['creer_tarifs']),
    ];
    $corr = [];
    $c = $pdo->prepare("SELECT valeur_source, cible_id FROM import_correspondances WHERE ecole_id <=> ? AND type = 'conducteur'");
    $c->execute([$ecoleId]);
    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $corr[$r['valeur_source']] = (int) $r['cible_id'];
    }
    // Eleves deja presents (meme nom normalise, meme ecole) : doublons possibles, jamais fusionnes.
    $existants = [];
    $e = $pdo->prepare('SELECT id, nom, prenom, annee_scolaire_id FROM eleves WHERE ecole_id <=> ?');
    $e->execute([$ecoleId]);
    foreach ($e->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existants[imp_norm($r['nom'] . ' ' . $r['prenom'])][] = (int) $r['annee_scolaire_id'];
    }

    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM import_lignes WHERE lot_id = ?')->execute([(int) $lot['id']]);
    $ins = $pdo->prepare('INSERT INTO import_lignes (lot_id, numero_ligne, donnees, statut, messages) VALUES (?, ?, ?, ?, ?)');
    $stats = ['ok' => 0, 'avertissement' => 0, 'erreur' => 0, 'insuffisant' => 0, 'lignes' => 0, 'mois_hors_annee' => 0, 'conducteurs' => [], 'zones' => [], 'vehicules_types' => []];
    $vus = [];
    foreach ($rows['valeurs'] as $r => $cells) {
        if ($r <= $h) {
            continue;
        }
        $full = trim((string) ($cells[$cols['nom']] ?? ''));
        if ($full === '') {
            continue;
        }
        $stats['lignes']++;
        $msgs = [];
        $statut = 'ok';
        $warn = function ($m) use (&$msgs, &$statut) {
            $msgs[] = $m;
            if ($statut === 'ok') {
                $statut = 'avertissement';
            }
        };
        $insuff = function ($m) use (&$msgs, &$statut) {
            $msgs[] = $m;
            if ($statut !== 'erreur') {
                $statut = 'insuffisant';
            }
        };
        [$nom, $prenom, $douteux] = imp_nom_prenom($full);
        if ($douteux) {
            $warn('Separation nom / prenom a verifier');
        }
        $cle = imp_norm($full);
        if (isset($vus[$cle])) {
            $statut = 'erreur';
            $msgs[] = 'Doublon exact dans la feuille (ligne ' . $vus[$cle] . ')';
        }
        $vus[$cle] = $r;
        if (isset($existants[$cle])) {
            $warn(in_array($anneeId, $existants[$cle], true) ? 'Un eleve de meme nom existe deja pour cette annee (doublon possible)' : 'Eleve de meme nom sur une autre annee : fiche distincte creee (doublon possible a verifier)');
        }
        $stAbo = imp_statut($cells[$cols['statut']] ?? '');
        if ($stAbo === null) {
            $insuff('Statut « ' . ($cells[$cols['statut']] ?? '') . ' » ambigu : abonnement cree « en attente »');
        }
        $conducteur = isset($cols['conducteur']) ? trim((string) ($cells[$cols['conducteur']] ?? '')) : '';
        $chauffeurId = null;
        if ($conducteur !== '') {
            $stats['conducteurs'][imp_norm($conducteur)] = $conducteur;
            $chauffeurId = $corr[imp_norm($conducteur)] ?? null;
            if (!$chauffeurId) {
                $insuff("Conducteur « $conducteur » : donnee insuffisante pour relation automatique (correspondance a saisir)");
            }
        }
        $vehType = isset($cols['vehicule']) ? trim((string) ($cells[$cols['vehicule']] ?? '')) : '';
        if ($vehType !== '') {
            $stats['vehicules_types'][$vehType] = ($stats['vehicules_types'][$vehType] ?? 0) + 1;
            if ($chauffeurId) {
                $vid = chauffeur_vehicle_on($pdo, $chauffeurId, date('Y-m-d'));
                if (!$vid) {
                    $warn("Vehicule « $vehType » : aucun vehicule (immatriculation) affecte a ce chauffeur");
                }
            }
        }
        $montant = isset($cols['montant']) ? ($cells[$cols['montant']] ?? null) : null;
        $montant = is_numeric($montant) ? round((float) $montant, 2) : null;
        $zone = isset($cols['ligne']) ? strtoupper(trim((string) ($cells[$cols['ligne']] ?? ''))) : '';
        if ($zone !== '' && $montant !== null) {
            $stats['zones'][$zone][(string) $montant] = ($stats['zones'][$zone][(string) $montant] ?? 0) + 1;
        }
        if ($montant === null && $stAbo === 'actif') {
            $warn('Montant mensuel absent');
        }
        $mois = [];
        foreach ($cols['mois'] as $col => $date) {
            $cell = imp_mois_cellule($cells[$col] ?? null);
            if ($cell === null) {
                continue;
            }
            if ($date < $debutAnnee || $date > $finAnnee) {
                $stats['mois_hors_annee']++;
                if ($cell['enko'] || $cell['shipp']) {
                    $warn('Mois ' . substr($date, 0, 7) . " hors de l'annee choisie : non importe");
                }
                continue;
            }
            if ($cell['inconnu']) {
                $warn('Mois ' . substr($date, 0, 7) . ' : valeur inconnue « ' . implode(', ', $cell['inconnu']) . ' »');
            }
            $mois[substr($date, 0, 7)] = ['enko' => $cell['enko'], 'shipp' => $cell['shipp'], 'arret' => $cell['arret']];
        }
        $loc = trim(implode(' | ', array_filter([
            isset($cols['commune']) ? trim((string) ($cells[$cols['commune']] ?? '')) : '',
            isset($cols['point']) ? trim((string) ($cells[$cols['point']] ?? '')) : '',
            isset($cols['localisation']) ? trim((string) ($cells[$cols['localisation']] ?? '')) : '',
        ])));
        $donnees = [
            'nom' => $nom, 'prenom' => $prenom, 'source_nom' => $full, 'statut_source' => $cells[$cols['statut']] ?? null, 'abonnement' => $stAbo ?? 'en_attente',
            'campus' => isset($cols['campus']) ? imp_campus($cells[$cols['campus']] ?? '') : null,
            'classe' => isset($cols['classe']) ? imp_classe($cells[$cols['classe']] ?? '') : null,
            'zone' => $zone ?: null, 'montant' => $montant,
            'periodicite' => isset($cols['paiement']) ? (imp_norm($cells[$cols['paiement']] ?? '') === 'trimestre' ? 'trimestrielle' : (imp_norm($cells[$cols['paiement']] ?? '') === 'annee' ? 'annuelle' : null)) : null,
            'conducteur' => $conducteur ?: null, 'chauffeur_id' => $chauffeurId, 'vehicule_type' => $vehType ?: null, 'localisation' => $loc ?: null,
            'mois' => $mois,
        ];
        $stats[$statut]++;
        $ins->execute([(int) $lot['id'], $r, json_encode($donnees, JSON_UNESCAPED_UNICODE), $statut, $msgs ? json_encode($msgs, JSON_UNESCAPED_UNICODE) : null]);
    }
    // Zones : un seul montant observe = tarif propose ; plusieurs = a trancher.
    $tarifs = [];
    foreach ($stats['zones'] as $z => $montants) {
        $tarifs[] = ['zone' => $z, 'montants' => $montants, 'propose' => count($montants) === 1 ? (float) array_key_first($montants) : null];
    }
    $stats['tarifs'] = $tarifs;
    $stats['conducteurs'] = array_values(array_map(function ($lib) use ($corr) {
        return ['valeur' => $lib, 'chauffeur_id' => $corr[imp_norm($lib)] ?? null];
    }, $stats['conducteurs']));
    unset($stats['zones']);
    $pdo->prepare('UPDATE import_lots SET feuille = ?, annee_scolaire_id = ?, ecole_id = ?, options = ?, stats = ? WHERE id = ?')
        ->execute([$feuille, $anneeId, $ecoleId, json_encode($options), json_encode($stats, JSON_UNESCAPED_UNICODE), (int) $lot['id']]);
    $pdo->commit();
    echo json_encode(['success' => true, 'stats' => $stats, 'options' => $options]);
    exit;
}

if ($action === 'valider') {
    if ($lot['statut'] !== 'analyse' || !$lot['feuille'] || !$lot['annee_scolaire_id']) {
        shipp_error(409, 'Lot non prepare, deja valide ou annule');
    }
    $d = $pdo->prepare("SELECT id FROM import_lots WHERE sha256 = ? AND feuille = ? AND statut = 'valide' LIMIT 1");
    $d->execute([$lot['sha256'], $lot['feuille']]);
    if ($d->fetch()) {
        shipp_error(409, 'Cette feuille de ce fichier a deja ete importee');
    }
    $options = json_decode((string) $lot['options'], true) ?: [];
    $stats = json_decode((string) $lot['stats'], true) ?: [];
    $lotId = (int) $lot['id'];
    $anneeId = (int) $lot['annee_scolaire_id'];
    $ecoleId = $lot['ecole_id'] !== null ? (int) $lot['ecole_id'] : null;
    $l = $pdo->prepare("SELECT id, numero_ligne, donnees, statut FROM import_lignes WHERE lot_id = ? AND statut <> 'erreur' ORDER BY numero_ligne");
    $l->execute([$lotId]);
    $lignes = $l->fetchAll(PDO::FETCH_ASSOC);
    // Les correspondances saisies apres la preparation sont prises en compte.
    $corr = [];
    $c = $pdo->prepare("SELECT valeur_source, cible_id FROM import_correspondances WHERE ecole_id <=> ? AND type = 'conducteur'");
    $c->execute([$ecoleId]);
    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $corr[$r['valeur_source']] = (int) $r['cible_id'];
    }
    $cree = ['eleves' => 0, 'abonnements' => 0, 'echeances' => 0, 'tarifs' => 0, 'circuits' => 0, 'affectations' => 0];
    $circuitsCrees = [];
    $pdo->beginTransaction();
    try {
        if (!empty($options['creer_tarifs'])) {
            foreach ($stats['tarifs'] ?? [] as $t) {
                if ($t['propose'] === null) {
                    continue;
                }
                $pdo->prepare("INSERT IGNORE INTO tarifs (ecole_id, annee_scolaire_id, service, zone, montant_mensuel, source, import_lot_id) VALUES (?, ?, 'transport', ?, ?, 'import', ?)")
                    ->execute([$ecoleId, $anneeId, $t['zone'], $t['propose'], $lotId]);
            }
            $n = $pdo->prepare('SELECT COUNT(*) FROM tarifs WHERE import_lot_id = ?');
            $n->execute([$lotId]);
            $cree['tarifs'] = (int) $n->fetchColumn();
        }
        $insEleve = $pdo->prepare("INSERT INTO eleves (ecole_id, annee_scolaire_id, nom, prenom, classe, ecole, campus, statut, qr_code, source, import_lot_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'import', ?)");
        $insAbo = $pdo->prepare("INSERT INTO abonnements (eleve_id, ecole_id, annee_scolaire_id, type, statut, zone_tarifaire, tarif_id, montant_mensuel, periodicite, source, import_lot_id)
                                 VALUES (?, ?, ?, 'transport', ?, ?, (SELECT t.id FROM tarifs t WHERE t.annee_scolaire_id = ? AND t.service = 'transport' AND t.zone = ? LIMIT 1), ?, ?, 'import', ?)");
        $insEch = $pdo->prepare("INSERT INTO echeances_transport (ecole_id, annee_scolaire_id, eleve_id, abonnement_id, mois, montant, encaisse_enko, recu_shipp, arret_service, source, import_lot_id, updated_by, updated_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'import', ?, ?, NOW())");
        $majLigne = $pdo->prepare('UPDATE import_lignes SET eleve_id = ?, crees = ? WHERE id = ?');
        foreach ($lignes as $ln) {
            $dn = json_decode($ln['donnees'], true);
            $insEleve->execute([$ecoleId, $anneeId, mb_substr($dn['nom'], 0, 100), mb_substr($dn['prenom'] ?: '-', 0, 100), $dn['classe'], $dn['campus'], $dn['campus'],
                $dn['abonnement'] === 'resilie' ? 'inactif' : 'actif', 'SHIPP-' . strtoupper(bin2hex(random_bytes(6))), $lotId]);
            $eleveId = (int) $pdo->lastInsertId();
            $cree['eleves']++;
            $insAbo->execute([$eleveId, $ecoleId, $anneeId, $dn['abonnement'], $dn['zone'], $anneeId, $dn['zone'], $dn['montant'], $dn['periodicite'], $lotId]);
            $aboId = (int) $pdo->lastInsertId();
            $cree['abonnements']++;
            foreach ($dn['mois'] as $m => $v) {
                $insEch->execute([$ecoleId, $anneeId, $eleveId, $aboId, $m . '-01', $dn['montant'], $v['enko'], $v['shipp'], $v['arret'], $lotId, $userId]);
                $cree['echeances']++;
            }
            $chauffeurId = $dn['conducteur'] ? ($corr[imp_norm($dn['conducteur'])] ?? null) : null;
            $affId = null;
            if (!empty($options['circuits_par_conducteur']) && $chauffeurId && $dn['abonnement'] === 'actif') {
                if (!isset($circuitsCrees[$chauffeurId])) {
                    $ch = $pdo->prepare("SELECT user_id, TRIM(CONCAT(COALESCE(prenom, ''), ' ', nom)) AS n FROM chauffeurs WHERE id = ?");
                    $ch->execute([$chauffeurId]);
                    $chf = $ch->fetch(PDO::FETCH_ASSOC);
                    $nomCircuit = mb_substr('ENKO - ' . $chf['n'], 0, 100);
                    $ex = $pdo->prepare('SELECT id FROM circuits WHERE nom = ? AND ecole_id <=> ? LIMIT 1');
                    $ex->execute([$nomCircuit, $ecoleId]);
                    $cid = $ex->fetchColumn();
                    if (!$cid) {
                        $pdo->prepare("INSERT INTO circuits (ecole_id, nom, description, statut, type_circuit) VALUES (?, ?, ?, 'actif', 'domicile')")
                            ->execute([$ecoleId, $nomCircuit, "Cree par l'import n° $lotId (a completer : arrets et horaires)"]);
                        $cid = (int) $pdo->lastInsertId();
                        $cree['circuits']++;
                        if ($chf['user_id']) {
                            $pdo->prepare('INSERT INTO affectations_chauffeur (ecole_id, user_id, circuit_id) VALUES (?, ?, ?)')->execute([$ecoleId, (int) $chf['user_id'], $cid]);
                        }
                    }
                    $circuitsCrees[$chauffeurId] = (int) $cid;
                }
                $pdo->prepare("INSERT INTO eleve_affectations_transport (ecole_id, annee_scolaire_id, eleve_id, circuit_id, sens, date_debut, statut, source, import_lot_id)
                               VALUES (?, ?, ?, ?, 'aller_retour', CURDATE(), 'active', 'import', ?)")->execute([$ecoleId, $anneeId, $eleveId, $circuitsCrees[$chauffeurId], $lotId]);
                $affId = (int) $pdo->lastInsertId();
                $pdo->prepare('UPDATE eleves SET circuit_id = ? WHERE id = ?')->execute([$circuitsCrees[$chauffeurId], $eleveId]);
                $cree['affectations']++;
            }
            $majLigne->execute([$eleveId, json_encode(['abonnement_id' => $aboId, 'affectation_id' => $affId]), (int) $ln['id']]);
        }
        $stats['crees'] = $cree;
        $stats['circuits_crees'] = array_values($circuitsCrees);
        $pdo->prepare("UPDATE import_lots SET statut = 'valide', valide_par = ?, valide_at = NOW(), stats = ? WHERE id = ?")
            ->execute([$userId, json_encode($stats, JSON_UNESCAPED_UNICODE), $lotId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        shipp_error(500, 'Import interrompu, rien n\'a ete enregistre : ' . mb_substr($e->getMessage(), 0, 200));
    }
    shipp_journal($pdo, $ecoleId, $userId, 'create', 'imports', $lotId, 'Lot valide : ' . json_encode($cree));
    echo json_encode(['success' => true, 'crees' => $cree]);
    exit;
}

if ($action === 'annuler') {
    $motif = trim((string) ($input['motif'] ?? ''));
    if ($motif === '') {
        shipp_error(400, "Motif d'annulation obligatoire");
    }
    if ($lot['statut'] === 'annule') {
        shipp_error(409, 'Lot deja annule');
    }
    $lotId = (int) $lot['id'];
    if ($lot['statut'] === 'valide') {
        // Dependances : toute activite posterieure sur les eleves du lot bloque l'annulation.
        $deps = [];
        $checks = [
            'scans' => 'SELECT COUNT(*) FROM scans s JOIN eleves e ON e.id = s.eleve_id WHERE e.import_lot_id = ?',
            'evenements transport' => 'SELECT COUNT(*) FROM transport_events t JOIN eleves e ON e.id = t.eleve_id WHERE e.import_lot_id = ?',
            'liaisons parents' => 'SELECT COUNT(*) FROM parent_liaisons p JOIN eleves e ON e.id = p.eleve_id WHERE e.import_lot_id = ?',
            'contacts' => 'SELECT COUNT(*) FROM eleve_contacts c JOIN eleves e ON e.id = c.eleve_id WHERE e.import_lot_id = ?',
            'mois modifies apres import' => 'SELECT COUNT(*) FROM echeances_historique h JOIN echeances_transport x ON x.id = h.echeance_id WHERE x.import_lot_id = ?',
            'incidents' => 'SELECT COUNT(*) FROM incident_eleves i JOIN eleves e ON e.id = i.eleve_id WHERE e.import_lot_id = ?',
            'mois saisis apres import' => 'SELECT COUNT(*) FROM echeances_transport x JOIN eleves e ON e.id = x.eleve_id WHERE e.import_lot_id = ? AND (x.import_lot_id IS NULL OR x.import_lot_id <> e.import_lot_id)',
            'abonnements ajoutes' => 'SELECT COUNT(*) FROM abonnements a JOIN eleves e ON e.id = a.eleve_id WHERE e.import_lot_id = ? AND (a.import_lot_id IS NULL OR a.import_lot_id <> e.import_lot_id)',
            'affectations ajoutees' => 'SELECT COUNT(*) FROM eleve_affectations_transport a JOIN eleves e ON e.id = a.eleve_id WHERE e.import_lot_id = ? AND (a.import_lot_id IS NULL OR a.import_lot_id <> e.import_lot_id)',
        ];
        foreach ($checks as $lib => $sql) {
            $q = $pdo->prepare($sql);
            $q->execute([$lotId]);
            $n = (int) $q->fetchColumn();
            if ($n > 0) {
                $deps[] = "$n $lib";
            }
        }
        if ($deps) {
            shipp_error(409, 'Annulation impossible, des donnees se sont rattachees au lot : ' . implode(', ', $deps));
        }
        $stats = json_decode((string) $lot['stats'], true) ?: [];
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM echeances_transport WHERE import_lot_id = ?')->execute([$lotId]);
        $pdo->prepare('DELETE FROM eleve_affectations_transport WHERE import_lot_id = ?')->execute([$lotId]);
        $pdo->prepare('DELETE FROM abonnements WHERE import_lot_id = ?')->execute([$lotId]);
        $pdo->prepare('DELETE FROM eleves WHERE import_lot_id = ?')->execute([$lotId]);
        $pdo->prepare('DELETE FROM tarifs WHERE import_lot_id = ? AND id NOT IN (SELECT tarif_id FROM (SELECT tarif_id FROM abonnements WHERE tarif_id IS NOT NULL) x)')->execute([$lotId]);
        foreach ($stats['circuits_crees'] ?? [] as $cid) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM eleve_affectations_transport WHERE circuit_id = ?");
            $q->execute([(int) $cid]);
            $t = $pdo->prepare('SELECT COUNT(*) FROM trajets WHERE circuit_id = ?');
            $t->execute([(int) $cid]);
            $d2 = $pdo->prepare("SELECT COUNT(*) FROM circuits WHERE id = ? AND description LIKE ?");
            $d2->execute([(int) $cid, "%import n° $lotId%"]);
            $et = $pdo->prepare('SELECT COUNT(*) FROM etapes WHERE circuit_id = ?');
            $et->execute([(int) $cid]);
            // Circuit retire seulement s'il est reste tel que l'import l'a cree (sans arret, trajet ni eleve).
            if ((int) $q->fetchColumn() === 0 && (int) $t->fetchColumn() === 0 && (int) $d2->fetchColumn() === 1 && (int) $et->fetchColumn() === 0) {
                $pdo->prepare('DELETE FROM affectations_chauffeur WHERE circuit_id = ?')->execute([(int) $cid]);
                $pdo->prepare('DELETE FROM circuits WHERE id = ?')->execute([(int) $cid]);
            }
        }
        $pdo->prepare("UPDATE import_lots SET statut = 'annule', annule_par = ?, annule_at = NOW() WHERE id = ?")->execute([$userId, $lotId]);
        $pdo->commit();
    } else {
        $pdo->prepare("UPDATE import_lots SET statut = 'annule', annule_par = ?, annule_at = NOW() WHERE id = ?")->execute([$userId, $lotId]);
    }
    shipp_journal($pdo, $lot['ecole_id'] !== null ? (int) $lot['ecole_id'] : null, $userId, 'update', 'imports', $lotId, 'Lot annule : ' . $motif);
    echo json_encode(['success' => true]);
    exit;
}

shipp_error(400, 'Action inconnue');
