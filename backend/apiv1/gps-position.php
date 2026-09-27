<?php
/**
 * Reception des positions GPS du telephone du chauffeur (migration 006).
 *
 * POST {
 *   trajet_id: N,
 *   vehicle_id?: N,                 // facultatif ; s'il est fourni il doit etre celui du trajet
 *   positions?: [{lat, lng, t (epoch ms), vitesse_kmh?, cap?, precision?, batterie?}, ...],
 *   signal?: {type: 'GPS_REFUSE'|'GPS_INDISPONIBLE'|'GPS_RETABLI'|'GPS_PRECISION_FAIBLE'|'GPS_ARRIERE_PLAN'|'GPS_PREMIER_PLAN', details?}
 * }
 *
 * Securite : seul le chauffeur du trajet, connecte avec son propre compte,
 * peut envoyer des positions, uniquement pour ce trajet en cours et pour le
 * vehicule du trajet (fixe au demarrage, jamais choisi par le telephone).
 * Doublons : la cle (trajet_id, recorded_at_ms) ignore les positions deja recues
 * (renvoi de la file d'attente apres une coupure reseau).
 */
require __DIR__ . '/lib/db.php';
shipp_headers('POST, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
require __DIR__ . '/lib/transport.php';
require __DIR__ . '/lib/gps.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    shipp_error(405, 'Methode non autorisee');
}
if (!gps_disponible($pdo)) {
    shipp_error(503, 'Suivi GPS non active sur ce serveur');
}
$ctx = authz_load($pdo, (int) $authUser['sub']);
authz_require($ctx, 'trajets', 'can_edit');

$in = shipp_json_input();
$trajetId = (int) ($in['trajet_id'] ?? 0);
if (!$trajetId) {
    shipp_error(400, 'trajet_id requis');
}
$moi = authz_my_chauffeur_id($pdo, $ctx);
if (!$moi) {
    shipp_error(403, 'Seul le chauffeur du trajet peut transmettre sa position');
}
$s = $pdo->prepare('SELECT id, ecole_id, annee_scolaire_id, circuit_id, chauffeur_id, vehicle_id, sens, statut, etape_courante_id,
    UNIX_TIMESTAMP(heure_debut) AS depart_ts, UNIX_TIMESTAMP(heure_fin) AS fin_ts FROM trajets WHERE id = ?');
$s->execute([$trajetId]);
$trajet = $s->fetch(PDO::FETCH_ASSOC);
if (!$trajet || (int) $trajet['chauffeur_id'] !== $moi) {
    // Trajet d'un autre chauffeur : refus, sans confirmer son existence.
    shipp_error(403, "Ce trajet n'est pas le votre");
}
if (isset($in['vehicle_id']) && $in['vehicle_id'] !== null && (int) $in['vehicle_id'] !== (int) $trajet['vehicle_id']) {
    shipp_error(403, "Ce vehicule n'est pas celui de votre trajet");
}
$enCours = $trajet['statut'] === 'en_cours';
// Apres la fin : on accepte seulement le vidage tardif de la file d'attente (positions mesurees pendant la course).
if (!$enCours && !($trajet['statut'] === 'termine' && $trajet['fin_ts'])) {
    shipp_error(409, "Ce trajet n'est pas en cours");
}
$departMs = ((int) $trajet['depart_ts'] - 120) * 1000;
$finMs = $enCours ? null : ((int) $trajet['fin_ts'] + 60) * 1000;
$nowMs = (int) round(microtime(true) * 1000);
$base = [
    'ecole_id' => $trajet['ecole_id'], 'annee_scolaire_id' => $trajet['annee_scolaire_id'], 'trajet_id' => $trajetId,
    'circuit_id' => (int) $trajet['circuit_id'], 'chauffeur_id' => $moi, 'vehicle_id' => $trajet['vehicle_id'] ? (int) $trajet['vehicle_id'] : null,
    'cree_par' => (int) $authUser['sub'], 'source' => 'gps',
];

// ---------- Signal d'etat du GPS (enregistre seulement s'il change) ----------
$signalTypes = ['GPS_REFUSE', 'GPS_INDISPONIBLE', 'GPS_RETABLI', 'GPS_PRECISION_FAIBLE', 'GPS_ARRIERE_PLAN', 'GPS_PREMIER_PLAN'];
if (!empty($in['signal']['type']) && $enCours) {
    $type = (string) $in['signal']['type'];
    if (!in_array($type, $signalTypes, true)) {
        shipp_error(400, 'Signal GPS inconnu');
    }
    $dernier = gps_dernier_signal($pdo, [$trajetId])[$trajetId]['type'] ?? null;
    if ($dernier !== $type) {
        transport_event($pdo, $type, $base + ['details' => mb_substr(trim((string) ($in['signal']['details'] ?? '')), 0, 200) ?: null]);
    }
}

// ---------- Positions ----------
$positions = is_array($in['positions'] ?? null) ? $in['positions'] : [];
if (count($positions) > 300) {
    shipp_error(413, 'Trop de positions dans un seul envoi (300 maximum)');
}
$acceptes = 0;
$doublons = 0;
$rejetes = 0;
$ins = $pdo->prepare('INSERT IGNORE INTO trajet_positions (ecole_id, trajet_id, vehicle_id, chauffeur_id, latitude, longitude, vitesse_kmh, cap, precision_m, batterie, recorded_at_ms, recorded_at, received_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$recu = date('Y-m-d H:i:s');
foreach ($positions as $p) {
    $lat = isset($p['lat']) && is_numeric($p['lat']) ? (float) $p['lat'] : null;
    $lng = isset($p['lng']) && is_numeric($p['lng']) ? (float) $p['lng'] : null;
    $t = isset($p['t']) && is_numeric($p['t']) ? (int) $p['t'] : null;
    if ($lat === null || $lng === null || $t === null || abs($lat) > 90 || abs($lng) > 180 || (abs($lat) < 0.0001 && abs($lng) < 0.0001)) {
        $rejetes++;
        continue;
    }
    // Horloge du telephone en avance : on ramene a l'heure du serveur.
    if ($t > $nowMs) {
        $t = $nowMs;
    }
    if ($t < $departMs || ($finMs !== null && $t > $finMs)) {
        $rejetes++;
        continue;
    }
    $num = function ($v, $min, $max) { return isset($v) && is_numeric($v) && $v >= $min && $v <= $max ? $v : null; };
    $vitesse = $num($p['vitesse_kmh'] ?? null, 0, 250);
    $cap = $num($p['cap'] ?? null, 0, 360);
    $precision = $num($p['precision'] ?? null, 0, 99999);
    $batterie = $num($p['batterie'] ?? null, 0, 100);
    $ins->execute([
        $trajet['ecole_id'], $trajetId, $trajet['vehicle_id'] ?: null, $moi, round($lat, 7), round($lng, 7),
        $vitesse !== null ? round((float) $vitesse, 1) : null, $cap !== null ? (int) round($cap) % 360 : null,
        $precision !== null ? round((float) $precision, 1) : null, $batterie !== null ? (int) $batterie : null,
        $t, date('Y-m-d H:i:s', intdiv($t, 1000)), $recu,
    ]);
    if ($ins->rowCount() > 0) {
        $acceptes++;
    } else {
        $doublons++;
    }
}

// ---------- Proximite du prochain arret (aide au chauffeur, jamais automatique) ----------
$params = gps_params($pdo);
$aide = null;
$derniere = gps_dernieres_positions($pdo, [$trajetId])[$trajetId] ?? null;
if ($enCours && $derniere) {
    $etapes = circuit_etapes($pdo, (int) $trajet['circuit_id'], $trajet['sens'], false);
    $idx = null;
    foreach ($etapes as $i => $e) {
        if ($e['id'] === (int) $trajet['etape_courante_id']) {
            $idx = $i;
        }
    }
    $prochain = $idx !== null ? ($etapes[$idx + 1] ?? null) : null;
    $dernierArret = $etapes ? $etapes[count($etapes) - 1] : null;
    $dist = function ($e) use ($derniere) {
        return $e && $e['latitude'] !== null ? (int) round(gps_distance($derniere['lat'], $derniere['lng'], $e['latitude'], $e['longitude'])) : null;
    };
    $dp = $dist($prochain);
    $df = $dist($dernierArret);
    $aide = [
        'prochain_arret' => $prochain ? ['id' => $prochain['id'], 'nom' => $prochain['nom'], 'distance_m' => $dp] : null,
        'proche_prochain_arret' => $dp !== null && $dp <= $params['rayon_arret'],
        'proche_fin' => $prochain === null || ($df !== null && $df <= $params['rayon_arret'] && $idx !== null && $idx + 1 >= count($etapes) - 1),
    ];
}

echo json_encode([
    'acceptes' => $acceptes, 'doublons' => $doublons, 'rejetes' => $rejetes,
    'statut_trajet' => $trajet['statut'], 'intervalle_secondes' => $params['intervalle'],
    'precision_faible_metres' => $params['precision_faible'], 'aide' => $aide,
]);
