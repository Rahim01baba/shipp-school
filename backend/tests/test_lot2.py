"""Tests bout en bout du lot 2 : chauffeur -> trajet -> scan -> evenement -> parent.

Prerequis : base recreee avec les migrations 001 et 002.
Usage : python3 test_lot2.py
"""
import datetime, json, os, sys
from client import call, login
from test_acces import sql, check, RESULTS

TODAY = datetime.date.today().isoformat()


def main():
    A = 'rahim'
    # --- Mise en place par l'admin / fleet ---
    ca = call('POST', '/crud.php?module=circuits', {'nom': 'LIGNE-COCODY', 'statut': 'actif'}, who=A)[1]['id']
    cb = call('POST', '/crud.php?module=circuits', {'nom': 'LIGNE-YOPOUGON', 'statut': 'actif'}, who=A)[1]['id']
    etapes = {}
    for c in (ca, cb):
        for o, (nom, h) in enumerate([('Depart', '06:30:00'), ('Arret 1', '06:45:00'), ('Ecole', '07:15:00')], start=1):
            etapes[(c, o)] = call('POST', '/crud.php?module=etapes', {'circuit_id': c, 'nom': nom, 'ordre': o, 'heure_estimee': h, 'statut': 'active'}, who=A)[1]['id']

    # Fiche chauffeur existante (reprise) du compte chauffeur de test
    code, js = call('GET', '/chauffeurs.php', who=A)
    ch_test = [c for c in js.get('data', []) if c.get('user_id') and int(c['user_id']) == 4]
    check('L2-01', 'Reprise : une fiche chauffeur existe pour le compte chauffeur', code == 200 and len(ch_test) == 1, str(js)[:200])
    ch1 = int(ch_test[0]['id']) if ch_test else None

    # Nouveau chauffeur sans e-mail, avec compte par telephone
    code, js = call('POST', '/chauffeurs.php', {'nom': 'Kone', 'prenom': 'Kassim', 'telephone': '+225 05 11 22 33 44', 'creer_compte': True, 'password': 'Kassim2026!'}, who=A)
    check('L2-02', 'Creation fiche chauffeur sans e-mail + compte telephone', code == 200 and js.get('user_id'), str(js))
    ch2 = js.get('id')

    # Affectations vehicules datees
    code, _ = call('POST', '/vehicle-assignments.php', {'chauffeur_id': ch1, 'vehicle_id': 1, 'date_debut': '2026-09-01'}, who='fleet')
    check('L2-03', 'Fleet affecte le vehicule 1 au chauffeur de test', code == 200)
    code, js = call('POST', '/vehicle-assignments.php', {'chauffeur_id': ch2, 'vehicle_id': 1, 'date_debut': '2026-09-10'}, who='fleet')
    check('L2-04', 'Refus : vehicule deja affecte a un autre chauffeur sur la periode', code == 409, str(js))
    call('POST', '/vehicle-assignments.php', {'chauffeur_id': ch2, 'vehicle_id': 2, 'date_debut': '2026-09-01'}, who='fleet')
    code, _ = call('POST', '/vehicle-assignments.php', {'chauffeur_id': ch1, 'vehicle_id': 3, 'date_debut': TODAY}, who='fleet')
    hist = call('GET', f'/vehicle-assignments.php?chauffeur_id={ch1}', who='fleet')[1]['data']
    closed = [h for h in hist if int(h['vehicle_id']) == 1]
    check('L2-05', "Changement de vehicule : l'ancienne affectation est cloturee (historique conserve)",
          len(hist) == 2 and closed and closed[0]['statut'] == 'terminee' and closed[0]['date_fin'] is not None, str(hist))

    # Titulaires des circuits
    call('POST', '/fleet-reaffecter.php', {'type': 'chauffeur', 'circuit_id': ca, 'nouveau_id': 4}, who=A)
    u2 = sql(f"SELECT user_id FROM chauffeurs WHERE id = {ch2}")[0]['user_id']
    call('POST', '/fleet-reaffecter.php', {'type': 'chauffeur', 'circuit_id': cb, 'nouveau_id': int(u2)}, who=A)

    # Affectation des eleves aux arrets
    code, _ = call('POST', '/eleve-affectations.php', {'eleve_id': 6, 'circuit_id': ca, 'etape_montee_id': etapes[(ca, 2)], 'etape_depose_id': etapes[(ca, 3)]}, who='fleet')
    check('L2-06', "Affectation de l'enfant du parent a l'arret 1 du circuit Cocody", code == 200)
    call('POST', '/eleve-affectations.php', {'eleve_id': 3, 'circuit_id': ca, 'etape_montee_id': etapes[(ca, 1)]}, who='fleet')
    call('POST', '/eleve-affectations.php', {'eleve_id': 4, 'circuit_id': cb, 'etape_montee_id': etapes[(cb, 2)]}, who='fleet')
    code, js = call('POST', '/eleve-affectations.php', {'eleve_id': 5, 'circuit_id': ca, 'etape_montee_id': etapes[(cb, 2)]}, who='fleet')
    check('L2-07', "Refus : arret n'appartenant pas au circuit", code == 400, str(js))

    # Generation des trajets du jour
    code, js = call('POST', '/trajets-generer.php', {'date': TODAY, 'sens': 'aller'}, who=A)
    check('L2-08', 'Generation des trajets du jour (2 circuits)', code == 200 and len(js.get('crees', [])) == 2, str(js))
    code, js2 = call('POST', '/trajets-generer.php', {'date': TODAY, 'sens': 'aller'}, who=A)
    check('L2-09', 'Generation relancee : aucun doublon', code == 200 and len(js2.get('crees', [])) == 0 and js2.get('deja_existants') == 2)
    ta = [t for t in js['crees'] if t['circuit'] == 'LIGNE-COCODY'][0]
    tb = [t for t in js['crees'] if t['circuit'] == 'LIGNE-YOPOUGON'][0]
    check('L2-10', 'Trajet genere avec chauffeur titulaire et vehicule du jour', ta['chauffeur_id'] == ch1 and ta['vehicle_id'] == 3, str(ta))

    # --- Parcours chauffeur ---
    code, jour = call('GET', '/chauffeur-jour.php', who='chauffeur')
    tr = jour.get('trajets', [{}])[0] if code == 200 else {}
    check('L2-11', "Mon activite : le chauffeur voit son trajet, son vehicule et ses 2 eleves attendus",
          code == 200 and len(jour['trajets']) == 1 and tr['compteurs']['attendus'] == 2 and jour['vehicule']['id'] in (3, '3'), str(jour)[:300])
    check('L2-12', "Mon activite : le trajet de l'autre chauffeur n'apparait pas", all(t['id'] != tb['id'] for t in jour.get('trajets', [])))
    code, _ = call('POST', '/trajet-avancer.php', {'trajet_id': ta['id'], 'action': 'demarrer'}, who='chauffeur')
    check('L2-13', 'Demarrage du trajet par le chauffeur', code == 200)
    call('POST', '/trajet-avancer.php', {'trajet_id': ta['id'], 'action': 'avancer'}, who='chauffeur')
    code, js = call('POST', '/scan-enregistrer.php', {'eleve_id': 6, 'type': 'transport_embarquement', 'methode': 'camera'}, who='chauffeur')
    check('L2-14', 'Scan sans trajet_id : rattache automatiquement au trajet en cours et a l arret courant',
          code == 200 and js.get('trajet_id') == ta['id'] and js.get('etape_id') == etapes[(ca, 2)], str(js))
    code, js = call('POST', '/scan-enregistrer.php', {'eleve_id': 6, 'type': 'transport_embarquement', 'methode': 'camera'}, who='chauffeur')
    check('L2-15', 'Second scan identique refuse (anti-doublon)', code == 409, str(js))
    code, js = call('POST', '/scan-enregistrer.php', {'eleve_id': 5, 'type': 'transport_embarquement', 'methode': 'code'}, who='chauffeur')
    check('L2-16', "Eleve de l'ecole non affecte : accepte avec alerte pendant le trajet", code == 200 and 'eleve_non_affecte' in js.get('alertes', []), str(js))
    code, js = call('POST', '/scan-enregistrer.php', {'eleve_id': 4, 'type': 'transport_embarquement', 'methode': 'code', 'trajet_id': tb['id']}, who='chauffeur')
    check('L2-17', "Scan sur le trajet d'un autre chauffeur refuse", code == 404, str(js))

    # --- Parent : suivi et notifications individuelles ---
    code, pe = call('GET', '/parent-enfants.php', who='parent')
    enf = pe.get('data', [{}])[0] if code == 200 else {}
    tj = (enf.get('trajets_du_jour') or [{}])[0]
    check('L2-18', "Parent : son enfant est 'embarque' avec l'heure, le chauffeur et le vehicule reels",
          code == 200 and len(pe['data']) == 1 and tj.get('statut_eleve') == 'embarque' and tj.get('embarque_at') and tj.get('immatriculation') == 'RC-9012-EF' and tj.get('chauffeur_nom'),
          json.dumps(pe)[:400])
    check('L2-19', "Parent : l'affectation (circuit, arret, horaire) est affichee",
          enf.get('affectation', {}).get('arret_montee') == 'Arret 1' and enf['affectation']['heure_montee'] == '06:45')
    types = [c['type'] for c in enf.get('chronologie', [])]
    check('L2-20', 'Parent : chronologie issue des evenements reels', 'STUDENT_BOARDED' in types, str(types))
    code, nm = call('GET', '/notifications-moi.php', who='parent')
    n_board = [n for n in nm.get('data', []) if n['type'] == 'STUDENT_BOARDED']
    check('L2-21', 'Parent : notification de montee recue', code == 200 and len(n_board) == 1 and n_board[0]['lu_at'] is None, str(nm)[:300])
    # Deuxieme parent lie au meme enfant : lecture independante
    sql("INSERT INTO users (id, ecole_id, name, email, password_hash, status) SELECT 8, 2, 'Mere Test', 'mere@test.local', password_hash, 'active' FROM users WHERE id = 3")
    sql("INSERT INTO user_roles (user_id, role_id, ecole_id) SELECT 8, id, 2 FROM roles WHERE role_key = 'parent'")
    sql("INSERT INTO parent_liaisons (ecole_id, user_id, eleve_id, lien) VALUES (2, 8, 6, 'mere')")
    call('POST', '/trajet-avancer.php', {'trajet_id': ta['id'], 'action': 'avancer'}, who='chauffeur')
    call('POST', '/scan-enregistrer.php', {'eleve_id': 6, 'type': 'transport_debarquement', 'methode': 'camera'}, who='chauffeur')
    tm = login('mere', identifiant='mere@test.local')
    nid = [n for n in call('GET', '/notifications-moi.php', who='parent')[1]['data'] if n['type'] == 'STUDENT_DROPPED'][0]['id']
    call('PUT', '/notifications-moi.php', {'id': nid}, who='parent')
    lu_p = [n for n in call('GET', '/notifications-moi.php', who='parent')[1]['data'] if n['id'] == nid][0]['lu_at']
    lu_m = [n for n in call('GET', '/notifications-moi.php', token=tm)[1]['data'] if n['id'] == nid][0]['lu_at']
    check('L2-22', "Lecture individuelle : lue pour le pere, toujours non lue pour la mere", lu_p is not None and lu_m is None)
    code, _ = call('PUT', '/notifications-moi.php', {'id': nid + 999}, who='parent')
    check('L2-23', "Impossible de marquer la notification d'un autre", code == 404)

    # Isolation parent
    code, js = call('GET', '/parent-enfants.php?eleve_id=4', who='parent')
    check('L2-24', "Parent : l'enfant d'une autre famille est invisible", code == 200 and js['data'] == [])
    code, js = call('GET', '/crud.php?module=transport_events', who='parent')
    check('L2-25', 'Parent : ne lit pas les evenements via le CRUD generique (module non expose)', code in (403, 404))

    # --- Absence et fin de trajet ---
    code, js = call('POST', '/eleve-absent.php', {'trajet_id': ta['id'], 'eleve_id': 6}, who='chauffeur')
    check('L2-26', 'Absence refusee pour un eleve deja embarque', code == 409, str(js))
    code, js = call('POST', '/trajet-avancer.php', {'trajet_id': ta['id'], 'action': 'cloturer'}, who='chauffeur')
    check('L2-27', "Fin de trajet : l'eleve attendu non scanne est marque absent", code == 200 and js.get('absents') == 1, str(js))
    ev = sql(f"SELECT type, COUNT(*) n FROM transport_events WHERE trajet_id = {ta['id']} GROUP BY type")
    evd = {r['type']: int(r['n']) for r in ev}
    check('L2-28', 'Evenements complets du trajet (demarrage, arrets, montees, descente, absence, fin)',
          evd.get('TRIP_STARTED') == 1 and evd.get('ARRIVED_AT_STOP') == 3 and evd.get('STUDENT_BOARDED') == 2 and evd.get('STUDENT_DROPPED') == 1 and evd.get('STUDENT_ABSENT') == 1 and evd.get('TRIP_COMPLETED') == 1, str(evd))
    pas = sql(f"SELECT COUNT(*) n FROM trajet_passages WHERE trajet_id = {ta['id']}")[0]['n']
    check('L2-29', 'Passages horodates aux 3 arrets', int(pas) == 3)
    t = sql(f"SELECT chauffeur_id, vehicle_id FROM trajets WHERE id = {ta['id']}")[0]
    check('L2-30', 'Le trajet a fige son chauffeur et son vehicule', int(t['chauffeur_id']) == ch1 and int(t['vehicle_id']) == 3, str(t))
    code, js = call('GET', f'/chauffeurs.php?id={ch2}', who='chauffeur')
    check('L2-31', "Chauffeur : ne voit pas la fiche d'un autre chauffeur", code == 404)
    code, js = call('GET', '/chauffeurs.php', who='chauffeur')
    check('L2-32', 'Chauffeur : ne voit que sa propre fiche', code == 200 and len(js['data']) == 1 and int(js['data'][0]['id']) == ch1)

    ok = sum(1 for r in RESULTS if r[2])
    print(f'\n{ok}/{len(RESULTS)} tests reussis')
    sys.exit(0 if ok == len(RESULTS) else 1)


if __name__ == '__main__':
    main()
