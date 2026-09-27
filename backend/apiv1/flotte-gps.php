<?php
/**
 * Carte flotte et historique GPS (migration 006). Lecture seule.
 *
 * GET                       : vehicules dont une course est en cours + KPI (rafraichi toutes les 5 s)
 * GET ?trajet_id=N          : historique d'un trajet (trace reelle, circuit prevu, evenements, statistiques)
 * GET ?liste=1&date=AAAA-MM-JJ : trajets du jour avec le volume de positions recues
 *
 * Droit : module 'suivi_gps' (admin : global ; fleet manager : son ecole).
 * Les chauffeurs et les parents n'y ont pas acces.
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
require __DIR__ . '/lib/transport.php';
require __DIR__ . '/lib/gps.php';

if (!gps_disponible($pdo)) {
    shipp_error(503, 'Suivi GPS non active sur ce serveur');
}
$ctx = authz_load($pdo, (int) $authUser['sub']);
authz_require($ctx, 'suivi_gps', 'can_read');
$cond = authz_scope_condition($pdo, $ctx, 'suivi_gps', 't.');
if ($cond === null) {
    shipp_error(403, 'Acces refuse');
}
$P = gps_params($pdo);
$nowMs = (int) round(microtime(true) * 1000);

$SELECT_TRAJET = "SELECT t.id, t.ecole_id, t.circuit_id, t.date_trajet, t.sens, t.statut, t.etape_courante_id, t.chauffeur_id, t.vehicle_id,
    t.heure_debut, t.heure_fin, UNIX_TIMESTAMP(t.heure_debut) AS depart_ts, UNIX_TIMESTAMP(t.heure_fin) AS fin_ts,
    ci.nom AS circuit_nom, v.immatriculation, v.modele, TRIM(CONCAT(COALESCE(c.prenom, ''), ' ', COALESCE(c.nom, ''))) AS chauffeur_nom, c.telephone AS chauffeur_telephone
    FROM trajets t JOIN circuits ci ON ci.id = t.circuit_id
    LEFT JOIN vehicules v ON v.id = t.vehicle_id
    LEFT JOIN chauffeurs c ON c.id = t.chauffeur_id";

/** Description commune d'un trajet (circuit prevu, dernier point, etat). */
function decrire_trajet(PDO $pdo, array $t, ?array $pos, ?array $signal, array $P, int $nowMs): array
{
    $etapes = circuit_etapes($pdo, (int) $t['circuit_id'], $t['sens'], true);
    $idx = null;
    foreach ($etapes as $i => $e) {
        if ($e['id'] === (int) $t['etape_courante_id']) {
            $idx = $i;
        }
    }
    $age = $pos ? max(0, intdiv($nowMs - $pos['t'], 1000)) : null;
    $etat = gps_etat($t['statut'], $age, $P, $t['depart_ts'] ? (int) $t['depart_ts'] : null);
    $ecart = $pos ? gps_distance_ligne($pos['lat'], $pos['lng'], $etapes) : null;
    return [
        'trajet_id' => (int) $t['id'],
        'date_trajet' => $t['date_trajet'],
        'circuit_id' => (int) $t['circuit_id'],
        'circuit' => $t['circuit_nom'],
        'sens' => $t['sens'],
        'moment' => $t['sens'] === 'retour' ? 'SOIR' : ($t['sens'] === 'aller' ? 'MATIN' : null),
        'statut' => $t['statut'],
        'depart' => $t['heure_debut'],
        'fin' => $t['heure_fin'],
        'chauffeur_id' => $t['chauffeur_id'] ? (int) $t['chauffeur_id'] : null,
        'chauffeur' => $t['chauffeur_nom'] ?: null,
        'chauffeur_telephone' => $t['chauffeur_telephone'],
        'vehicle_id' => $t['vehicle_id'] ? (int) $t['vehicle_id'] : null,
        'vehicule' => $t['immatriculation'],
        'vehicule_modele' => $t['modele'],
        'arrets' => $etapes,
        'etape_courante' => $idx !== null ? ['id' => $etapes[$idx]['id'], 'nom' => $etapes[$idx]['nom'], 'rang' => $idx + 1] : null,
        'prochain_arret' => $idx !== null && isset($etapes[$idx + 1]) ? ['id' => $etapes[$idx + 1]['id'], 'nom' => $etapes[$idx + 1]['nom'], 'rang' => $idx + 2] : null,
        'position' => $pos,
        'age_secondes' => $age,
        'etat_gps' => $etat,
        'precision_faible' => $pos && $pos['precision_m'] !== null && $pos['precision_m'] > $P['precision_faible'],
        'ecart_circuit_m' => $ecart !== null ? (int) round($ecart) : null,
        'hors_circuit' => $ecart !== null && $ecart > $P['ecart_circuit'],
        'signal' => $signal,
        'oublie' => $t['statut'] === 'en_cours' && $t['date_trajet'] < date('Y-m-d'),
    ];
}

// =====================================================================
// Historique d'un trajet
// =====================================================================
if (!empty($_GET['trajet_id'])) {
    $id = (int) $_GET['trajet_id'];
    $s = $pdo->prepare($SELECT_TRAJET . ' WHERE t.id = ? AND (' . $cond[0] . ')');
    $s->execute(array_merge([$id], $cond[1]));
    $t = $s->fetch(PDO::FETCH_ASSOC);
    if (!$t) {
        shipp_error(404, 'Trajet introuvable');
    }
    $ps = $pdo->prepare('SELECT latitude, longitude, vitesse_kmh, cap, precision_m, batterie, recorded_at_ms, recorded_at, received_at FROM trajet_positions WHERE trajet_id = ? ORDER BY recorded_at_ms');
    $ps->execute([$id]);
    $points = array_map('gps_format_position', $ps->fetchAll(PDO::FETCH_ASSOC));
    $last = $points ? $points[count($points) - 1] : null;
    $sig = gps_dernier_signal($pdo, [$id])[$id] ?? null;
    $out = decrire_trajet($pdo, $t, $last, $sig, $P, $nowMs);

    // Statistiques : points trop imprecis ecartes du calcul de distance.
    $distance = 0.0;
    $vmax = null;
    $prev = null;
    $ecarts = [];
    $arrets = [];
    $cluster = null;
    foreach ($points as $p) {
        if ($p['vitesse_kmh'] !== null) {
            $vmax = max($vmax ?? 0.0, $p['vitesse_kmh']);
        }
        $fiable = $p['precision_m'] === null || $p['precision_m'] <= $P['precision_faible'];
        if ($fiable && $prev) {
            $d = gps_distance($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
            $dt = max(1, ($p['t'] - $prev['t']) / 1000);
            if ($d / $dt < 70) { // < 250 km/h : saut GPS ignore sinon
                $distance += $d;
            }
        }
        if ($fiable) {
            $prev = $p;
            $e = gps_distance_ligne($p['lat'], $p['lng'], $out['arrets']);
            if ($e !== null && $e > $P['ecart_circuit']) {
                $ecarts[] = ['t' => $p['t'], 'recorded_at' => $p['recorded_at'], 'distance_m' => (int) round($e), 'lat' => $p['lat'], 'lng' => $p['lng']];
            }
            // Arrets detectes : immobilisation de 2 minutes ou plus dans un rayon de 50 m.
            if ($cluster && gps_distance($cluster['lat'], $cluster['lng'], $p['lat'], $p['lng']) <= 50) {
                $cluster['fin'] = $p['t'];
            } else {
                if ($cluster && $cluster['fin'] - $cluster['debut'] >= 120000) {
                    $arrets[] = $cluster;
                }
                $cluster = ['lat' => $p['lat'], 'lng' => $p['lng'], 'debut' => $p['t'], 'fin' => $p['t']];
            }
        }
    }
    if ($cluster && $cluster['fin'] - $cluster['debut'] >= 120000) {
        $arrets[] = $cluster;
    }
    $arretsDetectes = array_map(function ($c) use ($out) {
        $proche = null;
        $best = null;
        foreach ($out['arrets'] as $e) {
            if ($e['latitude'] === null) {
                continue;
            }
            $d = gps_distance($c['lat'], $c['lng'], $e['latitude'], $e['longitude']);
            if ($best === null || $d < $best) {
                $best = $d;
                $proche = $e['nom'];
            }
        }
        return [
            'lat' => $c['lat'], 'lng' => $c['lng'], 'debut' => date('Y-m-d H:i:s', intdiv($c['debut'], 1000)),
            'duree_secondes' => intdiv($c['fin'] - $c['debut'], 1000),
            'arret_prevu_proche' => $best !== null && $best <= 150 ? $proche : null,
        ];
    }, $arrets);
    $duree = $t['depart_ts'] ? (($t['fin_ts'] ?: time()) - (int) $t['depart_ts']) : null;

    // Evenements du trajet (depart, arrets, embarquements, absences, fin, GPS...).
    $voitEleves = authz_can($ctx, 'eleves', 'can_read');
    $ev = $pdo->prepare("SELECT te.id, te.type, te.survenu_at, UNIX_TIMESTAMP(te.survenu_at) AS ts, te.details, te.eleve_id, et.nom AS arret,
        TRIM(CONCAT(COALESCE(el.prenom, ''), ' ', COALESCE(el.nom, ''))) AS eleve
        FROM transport_events te LEFT JOIN etapes et ON et.id = te.etape_id LEFT JOIN eleves el ON el.id = te.eleve_id
        WHERE te.trajet_id = ? AND te.statut = 'valide' ORDER BY te.survenu_at, te.id");
    $ev->execute([$id]);
    $evenements = [];
    foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $e) {
        // Position au moment de l'evenement : point GPS le plus proche dans le temps (5 min max).
        $pos = null;
        $ms = (int) $e['ts'] * 1000;
        $best = null;
        foreach ($points as $p) {
            $d = abs($p['t'] - $ms);
            if ($best === null || $d < $best) {
                $best = $d;
                $pos = $p;
            }
        }
        $evenements[] = [
            'id' => (int) $e['id'], 'type' => $e['type'], 'survenu_at' => $e['survenu_at'], 'details' => $e['details'], 'arret' => $e['arret'],
            'eleve' => $voitEleves && $e['eleve_id'] ? ($e['eleve'] ?: null) : null,
            'lat' => $pos && $best <= 300000 ? $pos['lat'] : null, 'lng' => $pos && $best <= 300000 ? $pos['lng'] : null,
        ];
    }
    $pa = $pdo->prepare('SELECT tp.etape_id, et.nom, tp.heure_prevue, tp.heure_reelle, tp.ecart_minutes FROM trajet_passages tp JOIN etapes et ON et.id = tp.etape_id WHERE tp.trajet_id = ? ORDER BY tp.heure_reelle');
    $pa->execute([$id]);

    $out['trace'] = $points;
    $out['evenements'] = $evenements;
    $out['passages'] = $pa->fetchAll(PDO::FETCH_ASSOC);
    $out['stats'] = [
        'points' => count($points),
        'distance_km' => round($distance / 1000, 2),
        'duree_secondes' => $duree,
        'vitesse_max_kmh' => $vmax !== null ? round($vmax, 1) : null,
        'vitesse_moyenne_kmh' => $duree && $duree > 0 ? round(($distance / 1000) / ($duree / 3600), 1) : null,
        'arrets_detectes' => $arretsDetectes,
        'ecarts' => array_slice($ecarts, 0, 200),
        'ecart_max_m' => $ecarts ? max(array_column($ecarts, 'distance_m')) : 0,
        'premiere_position' => $points ? $points[0]['recorded_at'] : null,
        'derniere_position' => $last ? $last['recorded_at'] : null,
    ];
    $out['parametres'] = $P;
    $out['server_time_ms'] = $nowMs;
    echo json_encode($out);
    exit;
}

// =====================================================================
// Liste des trajets d'une journee (choix de l'historique)
// =====================================================================
if (!empty($_GET['liste'])) {
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        shipp_error(400, 'Date invalide');
    }
    $s = $pdo->prepare($SELECT_TRAJET . ' WHERE t.date_trajet = ? AND (' . $cond[0] . ') ORDER BY t.heure_debut IS NULL, t.heure_debut, t.id');
    $s->execute(array_merge([$date], $cond[1]));
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $nb = [];
    if ($rows) {
        $ids = array_map('intval', array_column($rows, 'id'));
        $q = $pdo->prepare('SELECT trajet_id, COUNT(*) AS n FROM trajet_positions WHERE trajet_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY trajet_id');
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nb[(int) $r['trajet_id']] = (int) $r['n'];
        }
    }
    echo json_encode(['date' => $date, 'data' => array_map(function ($t) use ($nb) {
        return [
            'trajet_id' => (int) $t['id'], 'circuit' => $t['circuit_nom'], 'sens' => $t['sens'], 'statut' => $t['statut'],
            'depart' => $t['heure_debut'], 'fin' => $t['heure_fin'], 'chauffeur' => $t['chauffeur_nom'] ?: null,
            'vehicule' => $t['immatriculation'], 'positions' => $nb[(int) $t['id']] ?? 0,
        ];
    }, $rows)]);
    exit;
}

// =====================================================================
// Flotte en direct
// =====================================================================
$s = $pdo->prepare($SELECT_TRAJET . " WHERE t.statut = 'en_cours' AND (" . $cond[0] . ') ORDER BY v.immatriculation, t.id');
$s->execute($cond[1]);
$actifs = $s->fetchAll(PDO::FETCH_ASSOC);
$ids = array_map('intval', array_column($actifs, 'id'));
$dernieres = gps_dernieres_positions($pdo, $ids);
$signaux = gps_dernier_signal($pdo, $ids);

// Trace recente (10 dernieres minutes, 40 points max) pour dessiner le sillage.
$traces = [];
if ($ids) {
    $q = $pdo->prepare('SELECT trajet_id, latitude, longitude, vitesse_kmh, cap, precision_m, batterie, recorded_at_ms, recorded_at FROM trajet_positions
        WHERE trajet_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND recorded_at_ms >= ? ORDER BY recorded_at_ms');
    $q->execute(array_merge($ids, [$nowMs - 600000]));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $traces[(int) $r['trajet_id']][] = gps_format_position($r);
    }
}

$vehicules = [];
$k = ['en_course' => 0, 'a_l_arret' => 0, 'gps_actifs' => 0, 'positions_anciennes' => 0, 'gps_perdus' => 0];
foreach ($actifs as $t) {
    $tid = (int) $t['id'];
    $pos = $dernieres[$tid] ?? null;
    $d = decrire_trajet($pdo, $t, $pos, $signaux[$tid] ?? null, $P, $nowMs);
    $trace = array_slice($traces[$tid] ?? [], -40);
    // A l'arret : vitesse faible et moins de 30 m parcourus sur la derniere minute.
    $arrete = false;
    if ($pos) {
        $ref = null;
        foreach ($trace as $p) {
            if ($p['t'] >= $pos['t'] - 60000) {
                $ref = $p;
                break;
            }
        }
        $bouge = $ref ? gps_distance($ref['lat'], $ref['lng'], $pos['lat'], $pos['lng']) : null;
        $lent = $pos['vitesse_kmh'] === null || $pos['vitesse_kmh'] < 3;
        $arrete = $lent && ($bouge === null || $bouge < 30) && ($ref && $ref['t'] <= $pos['t'] - 30000);
    }
    $d['statut_vehicule'] = $d['etat_gps'] === 'perdu' || $d['etat_gps'] === 'attente' ? 'inconnu' : ($arrete ? 'arrete' : 'en_course');
    $d['trace_recente'] = $trace;
    if ($d['statut_vehicule'] === 'arrete') {
        $k['a_l_arret']++;
    } elseif ($d['statut_vehicule'] === 'en_course') {
        $k['en_course']++;
    }
    if ($d['etat_gps'] === 'actif') {
        $k['gps_actifs']++;
    } elseif ($d['etat_gps'] === 'ancienne') {
        $k['positions_anciennes']++;
    } elseif ($d['etat_gps'] === 'perdu') {
        $k['gps_perdus']++;
    }
    $vehicules[] = $d;
}
$term = $pdo->prepare("SELECT COUNT(*) FROM trajets t WHERE t.statut = 'termine' AND t.date_trajet = ? AND (" . $cond[0] . ')');
$term->execute(array_merge([date('Y-m-d')], $cond[1]));
$k['trajets_termines'] = (int) $term->fetchColumn();
$k['courses_actives'] = count($vehicules);

echo json_encode(['server_time_ms' => $nowMs, 'parametres' => $P, 'kpi' => $k, 'vehicules' => $vehicules]);
