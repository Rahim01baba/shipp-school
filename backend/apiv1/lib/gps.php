<?php
/**
 * Suivi GPS de la flotte (migration 006) : outils communs.
 *
 * Deux sortes de coordonnees, a ne pas confondre :
 *  - GPS des arrets (etapes.latitude / longitude) : fixes, construisent le circuit ;
 *  - GPS du telephone du chauffeur (trajet_positions) : dynamique, position reelle.
 */

/** La migration 006 est-elle appliquee ? (sinon le suivi GPS reste invisible) */
function gps_disponible(PDO $pdo): bool
{
    return authz_table_exists($pdo, 'trajet_positions') && authz_column_exists($pdo, 'etapes', 'latitude');
}

/** Parametres du suivi, avec valeurs par defaut si la ligne n'existe pas. */
function gps_params(PDO $pdo): array
{
    require_once __DIR__ . '/transport.php';
    return [
        'intervalle' => max(5, min(60, (int) shipp_param($pdo, 'gps_intervalle_secondes', '10'))),
        'ancienne' => max(10, (int) shipp_param($pdo, 'gps_position_ancienne_secondes', '30')),
        'perdu' => max(20, (int) shipp_param($pdo, 'gps_perdu_secondes', '120')),
        'rayon_arret' => max(20, (int) shipp_param($pdo, 'gps_rayon_arret_metres', '80')),
        'precision_faible' => max(10, (int) shipp_param($pdo, 'gps_precision_faible_metres', '100')),
        'ecart_circuit' => max(50, (int) shipp_param($pdo, 'gps_ecart_circuit_metres', '400')),
    ];
}

/**
 * Arrets d'un circuit dans l'ordre de parcours du trajet.
 * Matin (aller) : ordre croissant, maison -> ecole. Soir (retour) : ordre
 * inverse, ecole -> maison. Le circuit n'est saisi qu'une fois.
 */
function circuit_etapes(PDO $pdo, int $circuitId, ?string $sens, bool $activesSeulement = true): array
{
    $coords = authz_column_exists($pdo, 'etapes', 'latitude') ? ', latitude, longitude' : '';
    $sql = 'SELECT id, nom, ordre, heure_estimee, statut' . $coords . ' FROM etapes WHERE circuit_id = ?'
        . ($activesSeulement ? " AND statut = 'active'" : '')
        . ' ORDER BY ordre ' . ($sens === 'retour' ? 'DESC' : 'ASC');
    $s = $pdo->prepare($sql);
    $s->execute([$circuitId]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $e['id'] = (int) $e['id'];
        $e['ordre'] = (int) $e['ordre'];
        $e['heure_estimee'] = $e['heure_estimee'] ? substr((string) $e['heure_estimee'], 0, 5) : null;
        $e['latitude'] = isset($e['latitude']) && $e['latitude'] !== null ? (float) $e['latitude'] : null;
        $e['longitude'] = isset($e['longitude']) && $e['longitude'] !== null ? (float) $e['longitude'] : null;
        $out[] = $e;
    }
    return $out;
}

/** Distance en metres entre deux points (formule de haversine). */
function gps_distance(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/** Distance en metres d'un point a une ligne brisee (arrets positionnes). */
function gps_distance_ligne(float $lat, float $lng, array $points): ?float
{
    $pts = array_values(array_filter($points, function ($p) { return $p['latitude'] !== null && $p['longitude'] !== null; }));
    if (!$pts) {
        return null;
    }
    if (count($pts) === 1) {
        return gps_distance($lat, $lng, $pts[0]['latitude'], $pts[0]['longitude']);
    }
    // Projection locale equirectangulaire : suffisante a l'echelle d'une ville.
    $k = cos(deg2rad($lat)) * 111320.0;
    $best = null;
    for ($i = 0; $i < count($pts) - 1; $i++) {
        $ax = ($pts[$i]['longitude'] - $lng) * $k;
        $ay = ($pts[$i]['latitude'] - $lat) * 110540.0;
        $bx = ($pts[$i + 1]['longitude'] - $lng) * $k;
        $by = ($pts[$i + 1]['latitude'] - $lat) * 110540.0;
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $l2 = $dx * $dx + $dy * $dy;
        $t = $l2 > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $l2)) : 0;
        $px = $ax + $t * $dx;
        $py = $ay + $t * $dy;
        $d = sqrt($px * $px + $py * $py);
        if ($best === null || $d < $best) {
            $best = $d;
        }
    }
    return $best;
}

/**
 * Etat GPS affiche. Jamais « temps reel » si la derniere position est ancienne.
 *   actif     : position recue depuis moins de 'ancienne' secondes
 *   ancienne  : entre 'ancienne' et 'perdu' secondes
 *   perdu     : plus de 'perdu' secondes, ou aucune position depuis le depart
 *   termine   : course terminee
 */
function gps_etat(string $statutTrajet, ?int $ageSecondes, array $p, ?int $departTs = null): string
{
    if ($statutTrajet !== 'en_cours') {
        return 'termine';
    }
    if ($ageSecondes === null) {
        // Aucune position : laisser au telephone le temps d'envoyer la premiere.
        if ($departTs && (time() - $departTs) < $p['perdu']) {
            return 'attente';
        }
        return 'perdu';
    }
    if ($ageSecondes <= $p['ancienne']) {
        return 'actif';
    }
    return $ageSecondes <= $p['perdu'] ? 'ancienne' : 'perdu';
}

/** Derniere position connue de chaque trajet demande (une requete). */
function gps_dernieres_positions(PDO $pdo, array $trajetIds): array
{
    $trajetIds = array_values(array_unique(array_map('intval', $trajetIds)));
    if (!$trajetIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($trajetIds), '?'));
    $s = $pdo->prepare("SELECT p.trajet_id, p.latitude, p.longitude, p.vitesse_kmh, p.cap, p.precision_m, p.batterie, p.recorded_at_ms, p.recorded_at, p.received_at
        FROM trajet_positions p
        JOIN (SELECT trajet_id, MAX(recorded_at_ms) AS m FROM trajet_positions WHERE trajet_id IN ($in) GROUP BY trajet_id) d
          ON d.trajet_id = p.trajet_id AND d.m = p.recorded_at_ms");
    $s->execute($trajetIds);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['trajet_id']] = gps_format_position($r);
    }
    return $out;
}

function gps_format_position(array $r): array
{
    return [
        'lat' => (float) $r['latitude'],
        'lng' => (float) $r['longitude'],
        'vitesse_kmh' => $r['vitesse_kmh'] !== null ? (float) $r['vitesse_kmh'] : null,
        'cap' => $r['cap'] !== null ? (int) $r['cap'] : null,
        'precision_m' => $r['precision_m'] !== null ? (float) $r['precision_m'] : null,
        'batterie' => isset($r['batterie']) && $r['batterie'] !== null ? (int) $r['batterie'] : null,
        't' => (int) $r['recorded_at_ms'],
        'recorded_at' => $r['recorded_at'],
    ];
}

/** Dernier signalement d'etat GPS envoye par le telephone (GPS refuse, indisponible...). */
function gps_dernier_signal(PDO $pdo, array $trajetIds): array
{
    $trajetIds = array_values(array_unique(array_map('intval', $trajetIds)));
    if (!$trajetIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($trajetIds), '?'));
    $s = $pdo->prepare("SELECT trajet_id, type, details, survenu_at FROM transport_events
        WHERE trajet_id IN ($in) AND type LIKE 'GPS\\_%' ORDER BY id");
    $s->execute($trajetIds);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['trajet_id']] = ['type' => $r['type'], 'details' => $r['details'], 'at' => $r['survenu_at']];
    }
    return $out;
}
