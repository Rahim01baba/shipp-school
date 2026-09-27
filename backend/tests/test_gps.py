"""Tests du suivi GPS temps reel (migration 006).

Prerequis : base de recette recreee avec les migrations 001 a 006.
Donnees 100 % fictives. Aucune communication externe : les notifications de
SHIPP sont internes (table notifications), aucun WhatsApp / SMS n'existe dans le code.
Usage : python3 test_gps.py
"""
import datetime, json, os, sys, time
from client import call, login, _tokens
from test_acces import sql, check, RESULTS

TODAY = datetime.date.today().isoformat()
A = 'rahim'

# Trois circuits fictifs a Abidjan (coordonnees d'illustration).
CIRCUITS = {
    'GPS-COCODY-01': [('Point A', 5.3600, -3.9900, '06:30'), ('Point B', 5.3550, -3.9950, '06:40'), ('Point C', 5.3500, -4.0000, '06:50'), ('Ecole', 5.3450, -4.0100, '07:10')],
    'GPS-RIVIERA-02': [('Riviera 2', 5.3700, -3.9600, '06:35'), ('Riviera 3', 5.3680, -3.9500, '06:45'), ('Ecole', 5.3450, -4.0100, '07:15')],
    'GPS-MARCORY-01': [('Marcory Zone 4', 5.3000, -3.9800, '06:30'), ('Biafra', 5.3100, -3.9900, '06:45'), ('Ecole', 5.3450, -4.0100, '07:20')],
}


def now_ms():
    return int(time.time() * 1000)


def pos(lat, lng, t=None, **kw):
    d = {'lat': lat, 'lng': lng, 't': t or now_ms()}
    d.update(kw)
    return d


def fleet(who='fleet'):
    return call('GET', '/flotte-gps.php', who=who)


def veh(js, tid):
    return next((v for v in js.get('vehicules', []) if v['trajet_id'] == tid), None)


def prepare():
    """Circuits positionnes, 4 chauffeurs (dont un sans vehicule), trajets du matin."""
    circuits, arrets = {}, {}
    for nom, pts in CIRCUITS.items():
        cid = call('POST', '/crud.php?module=circuits', {'nom': nom, 'statut': 'actif'}, who=A)[1]['id']
        code, js = call('PUT', '/circuit-arrets.php', {'circuit_id': cid, 'arrets': [
            {'nom': n, 'latitude': la, 'longitude': lo, 'heure_estimee': h} for n, la, lo, h in pts]}, who=A)
        circuits[nom], arrets[nom] = cid, js.get('data', [])
    cd = call('POST', '/crud.php?module=circuits', {'nom': 'GPS-SANS-VEHICULE', 'statut': 'actif'}, who=A)[1]['id']
    call('PUT', '/circuit-arrets.php', {'circuit_id': cd, 'arrets': [{'nom': 'Depart'}, {'nom': 'Ecole'}]}, who=A)
    circuits['GPS-SANS-VEHICULE'] = cd

    ch1 = int(sql('SELECT id FROM chauffeurs WHERE user_id = 4')[0]['id'])
    chs = {'c1': (ch1, 4)}
    _tokens['c1'] = login('chauffeur')
    for k, (nom, tel) in {'c2': ('Gps Deux', '+225 05 00 00 00 02'), 'c3': ('Gps Trois', '+225 05 00 00 00 03'), 'c4': ('Gps Quatre', '+225 05 00 00 00 04')}.items():
        js = call('POST', '/chauffeurs.php', {'nom': nom, 'prenom': 'Test', 'telephone': tel, 'creer_compte': True, 'password': 'GpsRecette2026!'}, who=A)[1]
        chs[k] = (int(js['id']), int(js['user_id']))
        _tokens[k] = login(k, password='GpsRecette2026!', identifiant=tel)
    for k, v in {'c1': 1, 'c2': 2, 'c3': 3}.items():
        call('POST', '/vehicle-assignments.php', {'chauffeur_id': chs[k][0], 'vehicle_id': v, 'date_debut': TODAY}, who='fleet')
    for k, nom in {'c1': 'GPS-COCODY-01', 'c2': 'GPS-RIVIERA-02', 'c3': 'GPS-MARCORY-01', 'c4': 'GPS-SANS-VEHICULE'}.items():
        call('POST', '/fleet-reaffecter.php', {'type': 'chauffeur', 'circuit_id': circuits[nom], 'nouveau_id': chs[k][1]}, who=A)
    js = call('POST', '/trajets-generer.php', {'date': TODAY, 'sens': 'les_deux'}, who=A)[1]
    trajets = {(t['circuit'], t['sens']): t['id'] for t in js.get('crees', [])}
    return circuits, arrets, chs, trajets


def main():
    circuits, arrets, chs, trajets = prepare()
    t1, t2, t3 = trajets[('GPS-COCODY-01', 'aller')], trajets[('GPS-RIVIERA-02', 'aller')], trajets[('GPS-MARCORY-01', 'aller')]
    t1r, t4 = trajets[('GPS-COCODY-01', 'retour')], trajets[('GPS-SANS-VEHICULE', 'aller')]

    # ---------- Structure et circuit sur carte ----------
    cols = sql("SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'etapes' AND column_name IN ('latitude', 'longitude')")
    tab = sql("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'trajet_positions'")
    check('G-01', 'Migration 006 : colonnes latitude/longitude sur etapes + table trajet_positions', int(cols[0]['n']) == 2 and int(tab[0]['n']) == 1)
    a = arrets['GPS-COCODY-01']
    check('G-02', "Editeur de circuit : 4 points crees avec nom, ordre et coordonnees",
          [x['nom'] for x in a] == ['Point A', 'Point B', 'Point C', 'Ecole'] and a[0]['latitude'] == 5.36 and a[3]['ordre'] == 4, str(a))
    reord = [dict(id=x['id'], nom=x['nom'], latitude=x['latitude'], longitude=x['longitude'], heure_estimee=x['heure_estimee']) for x in a]
    reord[1], reord[2] = reord[2], reord[1]
    reord[0]['latitude'] = 5.3610
    code, js = call('PUT', '/circuit-arrets.php', {'circuit_id': circuits['GPS-COCODY-01'], 'arrets': reord}, who=A)
    ok = code == 200 and [x['nom'] for x in js['data']] == ['Point A', 'Point C', 'Point B', 'Ecole'] and js['data'][0]['latitude'] == 5.361
    reord[1], reord[2] = reord[2], reord[1]
    call('PUT', '/circuit-arrets.php', {'circuit_id': circuits['GPS-COCODY-01'], 'arrets': reord}, who=A)
    check('G-03', 'Editeur : deplacer un point et reorganiser (ordre unique respecte)', ok, str((code, js))[:300])
    code, _ = call('PUT', '/circuit-arrets.php', {'circuit_id': circuits['GPS-COCODY-01'], 'arrets': reord}, who='c1')
    check('G-04', 'Refus : un chauffeur ne peut pas modifier un circuit', code == 403, str(code))

    # ---------- Ecran chauffeur : matin / soir ----------
    code, js = call('GET', '/chauffeur-jour.php', who='c1')
    tr = {t['id']: t for t in js.get('trajets', [])}
    aller = [x['nom'] for x in tr.get(t1, {}).get('arrets', [])]
    retour = [x['nom'] for x in tr.get(t1r, {}).get('arrets', [])]
    check('G-05', 'Matin : Point A -> Ecole ; soir : Ecole -> Point A (sens retour automatique)',
          aller == ['Point A', 'Point B', 'Point C', 'Ecole'] and retour == ['Ecole', 'Point C', 'Point B', 'Point A'], str((aller, retour)))
    check('G-06', 'Ecran chauffeur : depart prevu, vehicule et parametres GPS fournis',
          tr.get(t1, {}).get('depart_prevu') == '06:30' and js.get('vehicule', {}).get('id') == 1 and js.get('gps', {}).get('intervalle_secondes') == 10, str(js)[:300])

    # ---------- Verifications au demarrage ----------
    code, js = call('POST', '/trajet-avancer.php', {'trajet_id': t4, 'action': 'demarrer', 'gps': True}, who='c4')
    st = sql(f'SELECT statut FROM trajets WHERE id = {t4}')[0]['statut']
    check('G-07', 'Refus de demarrer la course sans vehicule affecte (trajet reste planifie)', code == 400 and 'vehicule' in js.get('message', '').lower() and st == 'planifie', str((code, js, st)))
    code, js = call('POST', '/trajet-avancer.php', {'trajet_id': t2, 'action': 'demarrer', 'gps': True}, who='c1')
    check('G-08', "Refus : un chauffeur ne demarre pas la course d'un autre", code in (403, 404), str((code, js)))

    # ---------- TEST 1 : demarrage + premiere position ----------
    code, js = call('POST', '/trajet-avancer.php', {'trajet_id': t1, 'action': 'demarrer', 'gps': True}, who='c1')
    d = sql(f'SELECT statut, heure_debut, vehicle_id FROM trajets WHERE id = {t1}')[0]
    c2, r2 = call('POST', '/gps-position.php', {'trajet_id': t1, 'positions': [pos(5.3610, -3.9900, vitesse_kmh=0, cap=200, precision=8, batterie=81)]}, who='c1')
    code_f, f = fleet()
    v = veh(f, t1)
    check('TEST-1', 'Demarrage : EN_COURS, heure reelle de depart, GPS active, position recue',
          code == 200 and js.get('gps') and d['statut'] == 'en_cours' and d['heure_debut'] and int(d['vehicle_id']) == 1
          and c2 == 200 and r2['acceptes'] == 1 and v and v['etat_gps'] == 'actif', str((code, js, d, r2, v and v['etat_gps']))[:400])

    # ---------- TEST 2 : position suivante -> carte mise a jour ----------
    time.sleep(1.1)
    c2, r2 = call('POST', '/gps-position.php', {'trajet_id': t1, 'positions': [pos(5.3575, -3.9925, vitesse_kmh=24, cap=225, precision=6)]}, who='c1')
    v = veh(fleet()[1], t1)
    check('TEST-2', 'Position suivante : la flotte renvoie la nouvelle position, la vitesse et le cap',
          r2['acceptes'] == 1 and v['position']['lat'] == 5.3575 and v['position']['vitesse_kmh'] == 24 and v['position']['cap'] == 225 and len(v['trace_recente']) == 2, str(v and v['position']))
    check('G-09', 'Aide au chauffeur : prochain arret et distance calcules', r2['aide']['prochain_arret']['nom'] == 'Point B' and r2['aide']['prochain_arret']['distance_m'] > 0, str(r2))
    check('G-10', 'Superposition : la flotte fournit le circuit prevu (arrets positionnes) + position reelle',
          [x['nom'] for x in v['arrets']] == ['Point A', 'Point B', 'Point C', 'Ecole'] and v['arrets'][0]['latitude'] is not None and v['moment'] == 'MATIN' and not v['hors_circuit'], str(v)[:300])

    # ---------- TEST 3 et 4 : plusieurs vehicules sur la meme carte ----------
    call('POST', '/trajet-avancer.php', {'trajet_id': t2, 'action': 'demarrer', 'gps': True}, who='c2')
    call('POST', '/gps-position.php', {'trajet_id': t2, 'positions': [pos(5.3700, -3.9600, vitesse_kmh=18)]}, who='c2')
    f = fleet()[1]
    check('TEST-3', 'Deux chauffeurs demarrent : deux vehicules sur la meme carte',
          f['kpi']['courses_actives'] == 2 and {x['vehicule'] for x in f['vehicules']} == {'RC-1234-AB', 'RC-5678-CD'}, str(f['kpi']))
    call('POST', '/trajet-avancer.php', {'trajet_id': t3, 'action': 'demarrer', 'gps': True}, who='c3')
    call('POST', '/gps-position.php', {'trajet_id': t3, 'positions': [pos(5.3000, -3.9800, vitesse_kmh=30)]}, who='c3')
    f = fleet()[1]
    check('TEST-4', 'Trois chauffeurs : trois vehicules, chacun avec chauffeur, circuit et statut',
          f['kpi']['courses_actives'] == 3 and all(x['chauffeur'] and x['circuit'] and x['etat_gps'] == 'actif' for x in f['vehicules']), str(f['kpi']))

    # ---------- TEST 9 : securite ----------
    c_a, _ = call('POST', '/gps-position.php', {'trajet_id': t3, 'positions': [pos(5.31, -3.98)]}, who='c2')
    c_b, _ = call('POST', '/gps-position.php', {'trajet_id': t2, 'vehicle_id': 3, 'positions': [pos(5.37, -3.96)]}, who='c2')
    c_c, _ = call('POST', '/gps-position.php', {'trajet_id': t1, 'positions': [pos(5.36, -3.99)]}, who=A)
    c_d, _ = fleet('c1')
    c_e, _ = fleet('parent')
    c_f, _ = call('GET', f'/flotte-gps.php?trajet_id={t1}', who='c2')
    faux = int(sql(f'SELECT COUNT(*) AS n FROM trajet_positions WHERE trajet_id = {t3} AND chauffeur_id = {chs["c2"][0]}')[0]['n'])
    check('TEST-9', "Refus : position pour le trajet d'un autre, pour un autre vehicule, par un non-chauffeur ; carte interdite aux chauffeurs et parents",
          (c_a, c_b, c_c, c_d, c_e, c_f) == (403, 403, 403, 403, 403, 403) and faux == 0, str((c_a, c_b, c_c, c_d, c_e, c_f, faux)))
    c_g, js_g = call('POST', '/gps-position.php', {'trajet_id': t2, 'positions': [pos(91, -3.96), pos(0, 0), {'lat': 'x'}]}, who='c2')
    check('G-11', 'Positions invalides rejetees (latitude hors limite, 0/0, non numerique)', c_g == 200 and js_g['rejetes'] == 3 and js_g['acceptes'] == 0, str(js_g))

    # ---------- TEST 6 : perte reseau puis reconnexion ----------
    base = now_ms() - 50000
    file = [pos(5.3560 - i * 0.0005, -3.9940 - i * 0.0005, t=base + i * 10000, vitesse_kmh=20) for i in range(5)]
    c6a, r6a = call('POST', '/gps-position.php', {'trajet_id': t1, 'positions': file}, who='c1')
    c6b, r6b = call('POST', '/gps-position.php', {'trajet_id': t1, 'positions': file}, who='c1')
    n = int(sql(f'SELECT COUNT(*) AS n FROM trajet_positions WHERE trajet_id = {t1}')[0]['n'])
    check('TEST-6', "Reconnexion : la file d'attente est envoyee en un lot, un renvoi ne cree aucun doublon",
          r6a['acceptes'] == 5 and r6b['doublons'] == 5 and r6b['acceptes'] == 0 and n == 7, str((r6a, r6b, n)))
    futur = now_ms() + 3600000
    c6c, r6c = call('POST', '/gps-position.php', {'trajet_id': t1, 'positions': [pos(5.357, -3.993, t=futur)]}, who='c1')
    tmax = int(sql(f'SELECT MAX(recorded_at_ms) AS m FROM trajet_positions WHERE trajet_id = {t1}')[0]['m'])
    check('G-12', "Horloge du telephone en avance : ramenee a l'heure du serveur", r6c['acceptes'] == 1 and tmax <= now_ms(), str((r6c, tmax)))

    # ---------- TEST 7 : GPS desactive ----------
    call('POST', '/gps-position.php', {'trajet_id': t3, 'signal': {'type': 'GPS_REFUSE', 'details': 'Autorisation de localisation refusee'}}, who='c3')
    call('POST', '/gps-position.php', {'trajet_id': t3, 'signal': {'type': 'GPS_REFUSE'}}, who='c3')
    v3 = veh(fleet()[1], t3)
    nsig = int(sql(f"SELECT COUNT(*) AS n FROM transport_events WHERE trajet_id = {t3} AND type = 'GPS_REFUSE'")[0]['n'])
    check('TEST-7', 'GPS desactive : signale et affiche sur la flotte (un seul evenement, sans spam)',
          v3['signal'] and v3['signal']['type'] == 'GPS_REFUSE' and nsig == 1, str((v3 and v3['signal'], nsig)))
    parent_ev = int(sql(f"SELECT COUNT(*) AS n FROM notifications WHERE type LIKE 'GPS%'")[0]['n'])
    check('G-13', 'Aucune notification parent creee par les evenements GPS', parent_ev == 0, str(parent_ev))

    # ---------- TEST 10 : position ancienne / GPS perdu ----------
    sql(f'UPDATE trajet_positions SET recorded_at_ms = recorded_at_ms - 45000 WHERE trajet_id = {t2}')
    v2 = veh(fleet()[1], t2)
    ok_anc = v2['etat_gps'] == 'ancienne' and 44 <= v2['age_secondes'] <= 60
    sql(f'UPDATE trajet_positions SET recorded_at_ms = recorded_at_ms - 160000 WHERE trajet_id = {t2}')
    f = fleet()[1]
    v2 = veh(f, t2)
    check('TEST-10', "Position vieille de 45 s : « position ancienne » (jamais « temps reel ») ; au-dela de 120 s : GPS perdu",
          ok_anc and v2['etat_gps'] == 'perdu' and f['kpi']['gps_perdus'] >= 1, str((ok_anc, v2['etat_gps'], v2['age_secondes'], f['kpi'])))
    sql(f'UPDATE trajets SET heure_debut = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = {t4}')

    # ---------- Ecart par rapport au circuit ----------
    call('POST', '/gps-position.php', {'trajet_id': t1, 'positions': [pos(5.3800, -4.0300, vitesse_kmh=40)]}, who='c1')
    v = veh(fleet()[1], t1)
    check('G-14', 'Ecart detecte : vehicule a plus de 400 m du circuit prevu', v['hors_circuit'] and v['ecart_circuit_m'] > 400, str(v and v['ecart_circuit_m']))
    call('POST', '/gps-position.php', {'trajet_id': t1, 'positions': [pos(5.3452, -4.0098, vitesse_kmh=5)]}, who='c1')

    # ---------- Integration avec les presences : arrets du trajet ----------
    for _ in range(3):
        call('POST', '/trajet-avancer.php', {'trajet_id': t1, 'action': 'avancer'}, who='c1')

    # ---------- TEST 5 : fin de course ----------
    code, js = call('POST', '/trajet-avancer.php', {'trajet_id': t1, 'action': 'cloturer'}, who='c1')
    f = fleet()[1]
    code_h, h = call('GET', f'/flotte-gps.php?trajet_id={t1}', who='fleet')
    check('TEST-5', "Fin de course : le vehicule quitte la flotte active mais reste dans l'historique",
          code == 200 and veh(f, t1) is None and f['kpi']['courses_actives'] == 2 and f['kpi']['trajets_termines'] == 1
          and code_h == 200 and h['stats']['points'] == 10 and h['etat_gps'] == 'termine', str((code, f['kpi'], code_h, h.get('stats', {}).get('points'))))
    types = [e['type'] for e in h['evenements']]
    check('G-15', 'Historique : trace, depart, arrivee, duree, distance, evenements (depart, arrets, fin) et ecarts',
          h['depart'] and h['fin'] and h['stats']['distance_km'] > 0.2 and h['stats']['duree_secondes'] is not None
          and 'TRIP_STARTED' in types and types.count('ARRIVED_AT_STOP') == 4 and 'TRIP_COMPLETED' in types
          and h['stats']['ecart_max_m'] > 400 and any(e['lat'] for e in h['evenements']), str((h['stats'], types))[:500])
    c5, r5 = call('POST', '/gps-position.php', {'trajet_id': t1, 'positions': [pos(5.35, -4.0, t=now_ms() - 5000)]}, who='c1')
    c5b, r5b = call('POST', '/gps-position.php', {'trajet_id': t1, 'positions': [pos(5.35, -4.0, t=now_ms() + 120000)]}, who='c1')
    check('G-16', 'Apres la fin : vidage tardif accepte seulement pour des positions mesurees pendant la course',
          c5 == 200 and r5['acceptes'] == 1 and c5b == 200 and r5b['acceptes'] == 1, str((r5, r5b)))
    sql(f"UPDATE trajets SET heure_fin = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = {t1}")
    c5c, r5c = call('POST', '/gps-position.php', {'trajet_id': t1, 'positions': [pos(5.35, -4.0, t=now_ms() - 1000)]}, who='c1')
    check('G-17', 'Apres la fin : une position mesuree apres la fin de course est rejetee', c5c == 200 and r5c['rejetes'] == 1, str(r5c))

    # ---------- Soir : sens inverse et pas de faux retard ----------
    code, _ = call('POST', '/trajet-avancer.php', {'trajet_id': t1r, 'action': 'demarrer', 'gps': True}, who='c1')
    cur = sql(f'SELECT e.nom FROM trajets t JOIN etapes e ON e.id = t.etape_courante_id WHERE t.id = {t1r}')[0]['nom']
    call('POST', '/trajet-avancer.php', {'trajet_id': t1r, 'action': 'avancer'}, who='c1')
    cur2 = sql(f'SELECT e.nom FROM trajets t JOIN etapes e ON e.id = t.etape_courante_id WHERE t.id = {t1r}')[0]['nom']
    retards = int(sql(f"SELECT COUNT(*) AS n FROM transport_events WHERE trajet_id = {t1r} AND type = 'DELAY_DETECTED'")[0]['n'])
    v = veh(fleet()[1], t1r)
    check('G-18', 'Course du soir : depart de l Ecole puis Point C, aucun faux retard, affichee SOIR',
          code == 200 and cur == 'Ecole' and cur2 == 'Point C' and retards == 0 and v and v['moment'] == 'SOIR', str((code, cur, cur2, retards)))

    # ---------- Suppression d'arret deja utilise ----------
    a = call('GET', f'/circuit-arrets.php?circuit_id={circuits["GPS-COCODY-01"]}', who=A)[1]['data']
    code, js = call('PUT', '/circuit-arrets.php', {'circuit_id': circuits['GPS-COCODY-01'], 'arrets': [dict(id=x['id'], nom=x['nom'], latitude=x['latitude'], longitude=x['longitude']) for x in a[:-1]]}, who=A)
    check('G-19', "Refus de supprimer un arret deja utilise (historique conserve)", code == 409 and 'Ecole' in js.get('message', ''), str((code, js)))

    # ---------- Non-regression : demarrage historique sans GPS ----------
    code, js = call('POST', '/trajet-avancer.php', {'trajet_id': t4, 'action': 'demarrer'}, who=A)
    check('G-20', "Non-regression : le demarrage classique (sans GPS, par l'admin) fonctionne toujours", code == 200 and js.get('statut') == 'en_cours', str((code, js)))
    code, js = call('GET', '/flotte-gps.php?liste=1&date=' + TODAY, who=A)
    n1 = next((x['positions'] for x in js.get('data', []) if x['trajet_id'] == t1), None)
    check('G-21', 'Admin : liste des trajets du jour avec volume de positions (choix de l historique)', code == 200 and n1 == 12, str((code, n1)))

    ok = sum(1 for r in RESULTS if r[2])
    print(f'\n{ok}/{len(RESULTS)} tests reussis')
    json.dump(RESULTS, open(os.environ.get('SHIPP_TEST_REPORT', '/tmp/shipp_tests_gps.json'), 'w'), indent=1)
    sys.exit(0 if ok == len(RESULTS) else 1)


if __name__ == '__main__':
    main()
