"""Tests du lot 4 : reporting serveur (filtres date debut / date fin, drill-down, export).

Prerequis : base recreee avec les migrations 001 a 004.
Usage : python3 test_lot4.py
"""
import datetime, sys, urllib.request, urllib.error
from client import call, login, BASE
from test_acces import sql, check, RESULTS

TODAY = datetime.date.today()
D = lambda n: (TODAY + datetime.timedelta(days=n)).isoformat()


def raw_get(who, path):
    req = urllib.request.Request(BASE + path)
    req.add_header('Authorization', 'Bearer ' + login(who))
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, r.read(), r.headers.get('Content-Type')
    except urllib.error.HTTPError as e:
        return e.code, e.read(), None


def main():
    A = 'rahim'
    # ---------- Jeu de donnees : un trajet realise avec embarquements, absence, retard ----------
    circ = call('POST', '/crud.php?module=circuits', {'nom': 'LIGNE-REPORT', 'statut': 'actif'}, who=A)[1]['id']
    et = []
    for o, nom in enumerate(['Depart', 'Arret', 'Ecole'], start=1):
        et.append(call('POST', '/crud.php?module=etapes', {'circuit_id': circ, 'nom': nom, 'ordre': o, 'heure_estimee': '05:00:00', 'statut': 'active'}, who=A)[1]['id'])
    call('POST', '/fleet-reaffecter.php', {'type': 'chauffeur', 'circuit_id': circ, 'nouveau_id': 4}, who=A)
    ch1 = int(sql('SELECT id FROM chauffeurs WHERE user_id = 4')[0]['id'])
    call('POST', '/vehicle-assignments.php', {'chauffeur_id': ch1, 'vehicle_id': 1, 'date_debut': D(-30)}, who='fleet')
    for eid in (3, 6):
        call('POST', '/eleve-affectations.php', {'eleve_id': eid, 'circuit_id': circ, 'etape_montee_id': et[1]}, who='fleet')
    call('POST', '/trajets-generer.php', {'date': TODAY.isoformat(), 'sens': 'aller'}, who=A)
    traj = [t for t in call('GET', '/crud.php?module=trajets', who=A)[1]['data'] if int(t['circuit_id']) == circ][0]['id']
    call('POST', '/trajet-avancer.php', {'trajet_id': traj, 'action': 'demarrer'}, who='chauffeur')
    call('POST', '/trajet-avancer.php', {'trajet_id': traj, 'action': 'avancer'}, who='chauffeur')  # arret prevu 05:00 -> retard
    call('POST', '/scan-enregistrer.php', {'eleve_id': 6, 'type': 'transport_embarquement', 'methode': 'recherche', 'trajet_id': traj}, who='chauffeur')
    call('POST', '/eleve-absent.php', {'trajet_id': traj, 'eleve_id': 3}, who='chauffeur')
    call('POST', '/trajet-avancer.php', {'trajet_id': traj, 'action': 'cloturer'}, who='chauffeur')
    call('POST', '/incidents.php', {'trajet_id': traj, 'titre': 'Retard circulation', 'categorie': 'incident'}, who='chauffeur')
    call('POST', '/scan-enregistrer.php', {'eleve_id': 6, 'type': 'cantine', 'methode': 'recherche'}, who='restaurant')

    per = f'date_debut={D(-7)}&date_fin={D(0)}'
    from client import _tokens
    rh_id = call('POST', '/users.php', {'name': 'RH Test', 'email': 'rh@example.com', 'password': 'RhRecette2026!', 'role_key': 'rh'}, who=A)[1]['data']['id']
    sql(f'UPDATE users SET ecole_id = 2 WHERE id = {rh_id}')
    sql(f'UPDATE user_roles SET ecole_id = 2 WHERE user_id = {rh_id}')
    _tokens['rh'] = login('rh', password='RhRecette2026!', identifiant='rh@example.com')

    # ---------- Regles d'acces et de periode ----------
    code, js = call('GET', '/reporting.php?rapport=synthese', who=A)
    check('L4-01', 'Date debut et date fin obligatoires', code == 400, str(js))
    code, js = call('GET', f'/reporting.php?rapport=synthese&date_debut={D(0)}&date_fin={D(-1)}', who=A)
    check('L4-02', 'Refus : fin avant debut', code == 400)
    code, js = call('GET', f'/reporting.php?rapport=synthese&date_debut={D(-800)}&date_fin={D(0)}', who=A)
    check('L4-03', 'Periode maximale bornee (parametre)', code == 400)
    for who in ('parent', 'chauffeur', 'restaurant'):
        code, _ = call('GET', f'/reporting.php?rapport=synthese&{per}', who=who)
        check(f'L4-04-{who}', f'Reporting refuse au compte {who}', code == 403)

    # ---------- Synthese coherente avec les donnees reelles ----------
    code, js = call('GET', f'/reporting.php?rapport=synthese&{per}', who='fleet')
    ind = js.get('indicateurs', {})
    ev = sql(f"SELECT SUM(type='STUDENT_BOARDED') AS b, SUM(type='STUDENT_ABSENT') AS a FROM transport_events WHERE statut='valide' AND DATE(survenu_at) BETWEEN '{D(-7)}' AND '{D(0)}'")[0]
    check('L4-05', 'Synthese : embarquements et absences = evenements reels',
          code == 200 and ind.get('embarquements') == int(ev['b'] or 0) and ind.get('absences') == int(ev['a'] or 0) and ind.get('embarquements') >= 1, str(ind))
    check('L4-06', 'Synthese : trajet termine, retard mesure, repas et incident comptes',
          ind.get('trajets_termines', 0) >= 1 and ind.get('arrets_en_retard', 0) >= 1 and ind.get('repas_servis', 0) >= 1 and ind.get('incidents', 0) >= 1, str(ind))
    code, js = call('GET', f'/reporting.php?rapport=synthese&date_debut={D(-30)}&date_fin={D(-20)}', who='fleet')
    check('L4-07', 'Periode sans activite : indicateurs a zero', js.get('indicateurs', {}).get('embarquements') == 0 and js['indicateurs']['trajets_prevus'] == 0, str(js.get('indicateurs')))

    # ---------- Chauffeurs ----------
    code, js = call('GET', f'/reporting.php?rapport=chauffeurs&{per}', who='rh')
    row = [r for r in js.get('data', []) if r.get('chauffeur_id') == ch1]
    check('L4-08', 'Performance chauffeur : trajets, retards, eleves transportes, incidents',
          code == 200 and row and row[0]['termines'] >= 1 and row[0]['retards'] >= 1 and row[0]['eleves_transportes'] >= 1 and row[0]['incidents'] >= 1, str(row))
    check('L4-09', 'Aucun score deduit ; accidents imputes = qualifies uniquement', row and row[0]['accidents_imputes'] == 0 and 'note' in js and 'score' not in str(js['colonnes']).lower())
    code, js = call('GET', f'/reporting.php?rapport=chauffeurs&{per}&chauffeur_id={ch1}', who='rh')
    check('L4-10', 'Filtre chauffeur', code == 200 and len(js['data']) == 1 and js['data'][0]['chauffeur_id'] == ch1, str(js)[:200])

    # ---------- Autres rapports ----------
    for rap in ('par_jour', 'vehicules', 'circuits', 'eleves', 'incidents'):
        code, js = call('GET', f'/reporting.php?rapport={rap}&{per}', who=A)
        check(f'L4-11-{rap}', f'Rapport {rap} disponible avec colonnes', code == 200 and js.get('colonnes') and len(js.get('data', [])) >= 1, str(js)[:200])
    code, js = call('GET', f'/reporting.php?rapport=circuits&{per}&circuit_id={circ}', who=A)
    r = js['data'][0] if js.get('data') else {}
    check('L4-12', 'Circuit : eleves affectes, transportes, absences', r.get('eleves_affectes') == 2 and r.get('eleves_transportes') == 1 and r.get('absences') == 1, str(r))
    code, js = call('GET', f'/reporting.php?rapport=eleves&{per}&eleve_id=6', who=A)
    r = js['data'][0] if js.get('data') else {}
    check('L4-13', 'Eleve : jours transportes et repas', r.get('jours_transport') == 1 and r.get('repas') == 1, str(r))

    # ---------- Drill-down coherent ----------
    code, js = call('GET', f'/reporting.php?liste=embarquements&{per}', who='fleet')
    tot = call('GET', f'/reporting.php?rapport=synthese&{per}', who='fleet')[1]['indicateurs']
    check('L4-14', 'Drill-down embarquements = total de la synthese', code == 200 and len(js['data']) == tot['embarquements'], f"{len(js.get('data', []))} vs {tot['embarquements']}")
    code, js = call('GET', f'/reporting.php?liste=retards&{per}', who='fleet')
    check('L4-15', 'Drill-down retards = arrets en retard', len(js.get('data', [])) == tot['arrets_en_retard'])
    code, js = call('GET', f'/reporting.php?liste=absences&{per}&chauffeur_id={ch1}', who='fleet')
    check('L4-16', 'Drill-down absences filtre par chauffeur', code == 200 and len(js['data']) == 1 and js['data'][0]['eleve_id'] == 3, str(js)[:200])

    # ---------- Perimetre etablissement ----------
    code, js = call('GET', f'/reporting.php?rapport=synthese&{per}&ecole_id=1', who='fleet')
    check('L4-17', 'Fleet (perimetre etablissement) : autre ecole refusee', code == 403, str(js))
    code, js = call('GET', f'/reporting.php?rapport=synthese&{per}&ecole_id=1', who=A)
    check('L4-18', 'Admin : filtre ecole sans activite -> zero', code == 200 and js['indicateurs']['embarquements'] == 0, str(js.get('indicateurs')))

    # ---------- Export ----------
    code, body, ctype = raw_get('rh', f'/reporting.php?rapport=chauffeurs&{per}&format=csv')
    txt = body.decode('utf-8', 'replace')
    check('L4-19', 'Export CSV (BOM, separateur ;, periode en tete)', code == 200 and body.startswith(b'\xef\xbb\xbf') and 'Periode du' in txt and 'Chauffeur;' in txt and 'text/csv' in (ctype or ''), txt[:200])
    j = sql("SELECT rapport, format, nb_lignes FROM export_journal ORDER BY id DESC LIMIT 1")
    check('L4-20', "L'export est journalise (rapport, filtres, nombre de lignes)", j and j[0]['rapport'] == 'chauffeurs' and j[0]['format'] == 'csv', str(j))
    # Compte RH dont le role perd temporairement le droit d'export
    uid = call('POST', '/users.php', {'name': 'Lecteur', 'email': 'lecteur@example.com', 'password': 'Lecteur2026!'}, who=A)[1]['data']['id']
    sql(f"INSERT INTO user_roles (user_id, role_id, ecole_id) SELECT {uid}, id, 2 FROM roles WHERE role_key = 'rh'")
    sql(f"UPDATE users SET ecole_id = 2 WHERE id = {uid}")
    sql("UPDATE role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id SET rp.can_export = 0 WHERE r.role_key = 'rh' AND p.module_key = 'reporting'")
    _tokens['lecteur'] = login('lecteur', password='Lecteur2026!', identifiant='lecteur@example.com')
    code, body, _ = raw_get('lecteur', f'/reporting.php?rapport=synthese&{per}&format=csv')
    code2, js = call('GET', f'/reporting.php?rapport=synthese&{per}', who='lecteur')
    check('L4-21', "Sans droit d'export : lecture oui, export CSV refuse", code == 403 and code2 == 200 and js.get('export') is False, str((code, code2)))
    sql("UPDATE role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id SET rp.can_export = 1 WHERE r.role_key = 'rh' AND p.module_key = 'reporting'")

    # Neutralisation des formules dans le CSV
    call('POST', '/incidents.php', {'titre': '=HYPERLINK("http://x")', 'categorie': 'incident', 'circuit_id': circ}, who='fleet')
    code, body, _ = raw_get(A, f'/reporting.php?liste=incidents&{per}&format=csv')
    txt = body.decode('utf-8', 'replace')
    check('L4-22', 'CSV : formules neutralisees', "'=HYPERLINK" in txt and ';=HYPERLINK' not in txt, txt[-200:])

    ok = sum(1 for r in RESULTS if r[2])
    print(f'\n{ok}/{len(RESULTS)} tests reussis')
    sys.exit(0 if ok == len(RESULTS) else 1)


if __name__ == '__main__':
    main()
