"""Tests du lot 3 : incidents / accidents, dossier chauffeur, contrats, fichiers, alertes.

Prerequis : base recreee avec les migrations 001, 002 et 003
(php tools/reset_recette.php sql/001_roles_scopes.sql sql/002_transport_connecte.sql sql/003_incidents_dossier.sql)
et 'private_dir' configure dans config.local.php.
Usage : python3 test_lot3.py
"""
import datetime, json, sys, uuid, urllib.request, urllib.error
from client import call, login, BASE
from test_acces import sql, check, RESULTS

TODAY = datetime.date.today()
D = lambda n: (TODAY + datetime.timedelta(days=n)).isoformat()
PDF = b'%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'


def upload(who, entite, entite_id, content=PDF, name='scan.pdf', categorie=None):
    boundary = uuid.uuid4().hex
    parts = []
    fields = {'entite': entite, 'entite_id': str(entite_id)}
    if categorie:
        fields['categorie'] = categorie
    for k, v in fields.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="fichier"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + content + b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    req = urllib.request.Request(BASE + '/fichier.php', data=b''.join(parts), method='POST')
    req.add_header('Content-Type', f'multipart/form-data; boundary={boundary}')
    req.add_header('Authorization', 'Bearer ' + login(who))
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or '{}')


def download(who, fid):
    req = urllib.request.Request(BASE + f'/fichier.php?id={fid}')
    req.add_header('Authorization', 'Bearer ' + login(who))
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, r.read(), r.headers.get('Content-Type')
    except urllib.error.HTTPError as e:
        return e.code, e.read(), None


def main():
    A = 'rahim'
    # ---------- Mise en place ----------
    circ = call('POST', '/crud.php?module=circuits', {'nom': 'LIGNE-L3', 'statut': 'actif'}, who=A)[1]['id']
    for o, nom in enumerate(['Depart', 'Arret', 'Ecole'], start=1):
        call('POST', '/crud.php?module=etapes', {'circuit_id': circ, 'nom': nom, 'ordre': o, 'heure_estimee': f'07:{o}0:00', 'statut': 'active'}, who=A)
    call('POST', '/fleet-reaffecter.php', {'type': 'chauffeur', 'circuit_id': circ, 'nouveau_id': 4}, who=A)
    ch1 = int(sql('SELECT id FROM chauffeurs WHERE user_id = 4')[0]['id'])
    call('POST', '/vehicle-assignments.php', {'chauffeur_id': ch1, 'vehicle_id': 1, 'date_debut': D(-30)}, who='fleet')
    call('POST', '/eleve-affectations.php', {'eleve_id': 6, 'circuit_id': circ}, who='fleet')
    call('POST', '/trajets-generer.php', {'date': TODAY.isoformat(), 'sens': 'aller'}, who=A)
    traj = [t for t in call('GET', '/crud.php?module=trajets', who=A)[1]['data'] if int(t['circuit_id']) == circ][0]
    call('POST', '/trajet-avancer.php', {'trajet_id': traj['id'], 'action': 'demarrer'}, who='chauffeur')
    ch_autre = call('POST', '/chauffeurs.php', {'nom': 'Autre', 'telephone': '+225 07 00 00 00 99'}, who=A)[1]['id']

    # Compte RH (etablissement des chauffeurs de recette)
    code, js = call('POST', '/users.php', {'name': 'RH Test', 'email': 'rh@example.com', 'password': 'RhRecette2026!', 'role_key': 'rh'}, who=A)
    rh_id = js['data']['id']
    sql(f'UPDATE users SET ecole_id = 2 WHERE id = {rh_id}')
    sql(f'UPDATE user_roles SET ecole_id = 2 WHERE user_id = {rh_id}')
    from client import _tokens
    _tokens['rh'] = login('rh', password='RhRecette2026!', identifiant='rh@example.com')

    # ---------- Incidents ----------
    code, js = call('POST', '/incidents.php', {
        'trajet_id': traj['id'], 'categorie': 'incident', 'gravite': 'moyenne', 'titre': 'Pneu creve',
        'lieu': 'Boulevard VGE', 'responsabilite': 'chauffeur',
        'eleves': [{'eleve_id': 6, 'role': 'implique'}],
    }, who='chauffeur')
    check('L3-01', 'Le chauffeur declare un incident depuis son trajet', code == 200 and js.get('id'), str(js))
    inc1 = js.get('id')
    row = sql(f'SELECT * FROM incidents WHERE id = {inc1}')[0]
    check('L3-02', 'Responsabilite jamais deduite : non_determinee malgre la saisie', row['responsabilite'] == 'non_determinee', row['responsabilite'])
    check('L3-03', 'Contexte repris du trajet (circuit, chauffeur, vehicule figes)',
          int(row['circuit_id']) == circ and int(row['chauffeur_id']) == ch1 and int(row['vehicule_id']) == 1, str(row))
    ev = sql(f"SELECT COUNT(*) AS n FROM transport_events WHERE type = 'INCIDENT_DECLARED' AND incident_id = {inc1}")[0]['n']
    check('L3-04', 'Evenement transport INCIDENT_DECLARED cree', int(ev) == 1)
    nm = call('GET', '/notifications-moi.php', who='parent')[1]
    notif = [n for n in nm.get('data', []) if n.get('type') == 'incident']
    check('L3-05', 'Le parent de l\'eleve concerne est notifie (message neutre)',
          js.get('parents_notifies') == 1 and notif and 'responsab' not in notif[0]['message'].lower(), str(notif)[:200])

    code, js = call('POST', '/incidents.php', {'chauffeur_id': ch_autre, 'titre': 'Usurpation'}, who='chauffeur')
    check('L3-06', 'Refus : un chauffeur ne declare pas pour un autre chauffeur', code == 403, str(js))
    code, _ = call('GET', '/incidents.php', who='parent')
    check('L3-07', 'Le parent n\'a pas acces au registre des incidents', code == 403)

    code, js = call('POST', '/incidents.php', {
        'categorie': 'accident', 'titre': 'Accrochage carrefour', 'chauffeur_id': ch1, 'vehicule_id': 1, 'circuit_id': circ,
        'survenu_at': (datetime.datetime.now() - datetime.timedelta(days=10)).strftime('%Y-%m-%d %H:%M:%S'),
        'accident': {'tiers_implique': True, 'tiers_immatriculation': 'AB-123-CI', 'constat_amiable': True},
    }, who='fleet')
    acc = js.get('id')
    check('L3-08', 'Fleet declare un accident avec details (tiers, constat)', code == 200 and acc, str(js))
    det = call('GET', f'/incidents.php?id={acc}', who='fleet')[1]
    check('L3-09', 'Detail accident : gravite elevee par defaut, details tiers enregistres',
          det['data']['gravite'] == 'elevee' and det['accident'] and det['accident']['tiers_immatriculation'] == 'AB-123-CI', str(det)[:300])

    code, js = call('PUT', '/incidents.php', {'id': acc, 'action': 'statut', 'statut': 'clos'}, who='fleet')
    check('L3-10', 'Refus de clore un accident non qualifie', code == 409, str(js))
    code, js = call('PUT', '/incidents.php', {'id': acc, 'action': 'qualifier', 'responsabilite': 'tiers'}, who='fleet')
    check('L3-11', 'Qualification sans commentaire refusee', code == 400, str(js))
    code, _ = call('PUT', '/incidents.php', {'id': acc, 'action': 'qualifier', 'responsabilite': 'tiers', 'commentaire': 'Constat signe par le tiers'}, who='rh')
    check('L3-12', 'La RH (lecture seule incidents) ne peut pas qualifier', code == 403)

    al = call('GET', '/alertes.php', who='fleet')[1]['data']
    check('L3-13', 'Alerte : accident non qualifie depuis plus de 7 jours', any(a['type'] == 'accident_non_qualifie' and a['entite_id'] == acc for a in al), str(al)[:300])

    code, _ = call('PUT', '/incidents.php', {'id': acc, 'action': 'qualifier', 'responsabilite': 'tiers', 'commentaire': 'Constat signe par le tiers'}, who='fleet')
    code2, _ = call('PUT', '/incidents.php', {'id': acc, 'action': 'suivi', 'cout_estime': 85000, 'action_corrective': 'Rappel des regles aux carrefours'}, who='fleet')
    code3, _ = call('PUT', '/incidents.php', {'id': acc, 'action': 'action_ajouter', 'description': 'Reparer le pare-choc', 'echeance': D(7)}, who='fleet')
    code4, js = call('PUT', '/incidents.php', {'id': acc, 'action': 'statut', 'statut': 'clos'}, who='fleet')
    det = call('GET', f'/incidents.php?id={acc}', who=A)[1]
    check('L3-14', 'Qualification humaine, suivi, action corrective puis cloture',
          (code, code2, code3, code4) == (200, 200, 200, 200) and det['data']['statut'] == 'clos' and det['data']['responsabilite'] == 'tiers'
          and len(det['actions']) == 1, str((code, code2, code3, code4, js)))
    hist = [h['action'] for h in det['historique']]
    check('L3-15', 'Historique complet (declaration, qualification, suivi, cloture)',
          hist[0] == 'declaration' and 'qualification' in hist and 'changement_statut' in hist, str(hist))
    code, _ = call('PUT', '/incidents.php', {'id': acc, 'action': 'modifier', 'titre': 'x'}, who='fleet')
    code2, _ = call('PUT', '/incidents.php', {'id': acc, 'action': 'statut', 'statut': 'ouvert'}, who='fleet')
    check('L3-16', 'Incident clos en lecture seule ; reouverture exige un motif', code == 409 and code2 == 400)

    code, js = call('GET', f'/incidents.php?date_debut={D(-15)}&date_fin={D(-5)}', who='fleet')
    ids = [int(i['id']) for i in js.get('data', [])]
    check('L3-17', 'Filtre date debut / date fin', acc in ids and inc1 not in ids, str(ids))
    code, js = call('GET', '/incidents.php', who='chauffeur')
    ids = [int(i['id']) for i in js.get('data', [])]
    check('L3-18', 'Le chauffeur voit ses incidents', inc1 in ids and acc in ids, str(ids))

    # ---------- Fichiers prives ----------
    code, js = upload('chauffeur', 'incident', inc1, name='photo.pdf')
    check('L3-19', 'Le chauffeur joint un fichier a son incident', code == 200 and js.get('id'), str(js))
    f1 = js.get('id')
    chemin = sql(f'SELECT chemin FROM fichiers WHERE id = {f1}')[0]['chemin']
    code, body, ctype = download('fleet', f1)
    check('L3-20', 'Telechargement autorise, contenu intact, stockage hors racine web',
          code == 200 and body == PDF and ctype == 'application/pdf' and not chemin.startswith('/') and '..' not in chemin, f'{code} {chemin}')
    code, _, _ = download('parent', f1)
    check('L3-21', 'Refus de telechargement pour le parent', code in (403, 404))
    code, js = upload('chauffeur', 'incident', inc1, content=b'<?php echo 1; ?>', name='shell.php')
    check('L3-22', 'Refus des types non autorises (script)', code == 400, str(js))
    check('L3-23', 'Aucun fichier sous apiv1/uploads pour le lot 3', not sql(f"SELECT id FROM fichiers WHERE chemin LIKE '%uploads%'"))

    # ---------- Documents chauffeur ----------
    code, js = call('POST', '/chauffeur-documents.php', {'chauffeur_id': ch1, 'type': 'permis', 'numero': 'P-001', 'categorie_permis': 'D', 'date_expiration': D(20)}, who='chauffeur')
    check('L3-24', 'Le chauffeur depose son permis (a verifier)', code == 200, str(js))
    doc1 = js.get('id')
    code, js = call('POST', '/chauffeur-documents.php', {'chauffeur_id': ch_autre, 'type': 'permis'}, who='chauffeur')
    check('L3-25', 'Refus : document pour un autre chauffeur', code in (403, 404), str(js))
    code, js = upload('chauffeur', 'chauffeur_document', doc1)
    linked = sql(f'SELECT fichier_id FROM chauffeur_documents WHERE id = {doc1}')[0]['fichier_id']
    check('L3-26', 'Le scan depose est rattache au document', code == 200 and int(linked) == js.get('id'))
    code, _ = call('PUT', '/chauffeur-documents.php', {'id': doc1, 'action': 'valider'}, who='chauffeur')
    check('L3-27', 'Le chauffeur ne valide pas ses documents', code == 403)
    code, _ = call('PUT', '/chauffeur-documents.php', {'id': doc1, 'action': 'valider'}, who='fleet')
    check('L3-28', 'Fleet valide le permis', code == 200)
    docs = call('GET', f'/chauffeur-documents.php?chauffeur_id={ch1}', who='fleet')[1]['data']
    check('L3-29', 'Etat calcule : expire bientot (seuil 30 jours)', docs and docs[0]['etat'] == 'expire_bientot', str(docs)[:200])
    code, js = call('POST', '/chauffeur-documents.php', {'chauffeur_id': ch1, 'type': 'permis', 'numero': 'P-002', 'date_expiration': D(1500)}, who='rh')
    doc2 = js.get('id')
    call('PUT', '/chauffeur-documents.php', {'id': doc2, 'action': 'valider'}, who='rh')
    st = {int(r['id']): r['statut'] for r in sql(f'SELECT id, statut FROM chauffeur_documents WHERE chauffeur_id = {ch1}')}
    check('L3-30', 'Renouvellement : l\'ancien permis passe a remplace (conserve)', st.get(doc1) == 'remplace' and st.get(doc2) == 'valide', str(st))
    code, _ = call('PUT', '/chauffeur-documents.php', {'id': doc1, 'action': 'modifier', 'numero': 'X'}, who='rh')
    check('L3-31', 'Document remplace en lecture seule', code == 409)

    # ---------- Modeles et contrats ----------
    contenu = "Contrat {{reference}} entre {{etablissement}} et {{chauffeur_prenom}} {{chauffeur_nom}} pour le vehicule {{vehicule_immatriculation}}, du {{date_debut}} au {{date_fin}}. Montant : {{remuneration_montant}} {{remuneration_devise}} par {{remuneration_periodicite}}. Preavis {{preavis_jours}} jours."
    code, js = call('POST', '/contract-templates.php', {'code': 'CUV', 'titre': "Contrat d'utilisation de vehicule", 'contenu': contenu,
                                                        'valeurs_defaut': {'remuneration_montant': 150000, 'remuneration_periodicite': 'mois'}}, who='rh')
    tpl1 = js.get('id')
    check('L3-32', 'La RH cree un modele (version 1, brouillon)', code == 200 and js.get('version') == 1, str(js))
    call('PUT', '/contract-templates.php', {'id': tpl1, 'action': 'activer'}, who='rh')
    code, js = call('POST', '/contract-templates.php', {'code': 'CUV', 'titre': 'V2', 'contenu': contenu + ' (v2)'}, who='rh')
    check('L3-33', 'Nouvelle version sans ecraser la precedente', js.get('version') == 2 and len(sql("SELECT id FROM contract_templates WHERE code = 'CUV'")) == 2)
    code, _ = call('GET', '/contract-templates.php', who='fleet')
    check('L3-34', 'Fleet n\'a pas acces aux modeles de contrat', code == 403)

    code, js = call('POST', '/chauffeur-contracts.php', {'chauffeur_id': ch1, 'vehicle_id': 1, 'template_id': tpl1, 'date_debut': D(-30), 'duree_mois': 12,
                                                         'remuneration_montant': 175000, 'remuneration_periodicite': 'mois', 'preavis_jours': 30}, who='rh')
    ct1 = js.get('id')
    check('L3-35', 'Contrat cree depuis le modele (brouillon, reference auto)', code == 200 and js.get('reference', '').startswith('CUV-'), str(js))
    row = sql(f'SELECT * FROM chauffeur_contracts WHERE id = {ct1}')[0]
    check('L3-36', 'Montant propre au contrat (175 000), pas la valeur du modele (150 000)',
          float(row['remuneration_montant']) == 175000 and '175 000 FCFA' in row['contenu_genere'] and row['type'] == 'utilisation_vehicule', row['contenu_genere'][:200])
    check('L3-37', 'Date de fin calculee depuis la duree', row['date_fin'] is not None and D(300) < row['date_fin'] < D(340), row['date_fin'])
    code, js = call('PUT', '/chauffeur-contracts.php', {'id': ct1, 'action': 'activer'}, who='rh')
    check('L3-38', 'La RH active le contrat', code == 200, str(js))
    va = sql(f"SELECT contract_id FROM vehicle_assignments WHERE chauffeur_id = {ch1} AND vehicle_id = 1 AND statut = 'active'")
    check('L3-39', 'Le contrat est rattache a l\'affectation vehicule en cours', va and va[0]['contract_id'] is not None and int(va[0]['contract_id']) == ct1, str(va))
    code, js = call('POST', '/chauffeur-contracts.php', {'chauffeur_id': ch1, 'date_debut': D(10)}, who='rh')
    code2, js2 = call('PUT', '/chauffeur-contracts.php', {'id': js.get('id'), 'action': 'activer'}, who='rh')
    check('L3-40', 'Refus d\'un second contrat actif chevauchant', code2 == 409, str(js2))

    code, js = call('GET', f'/chauffeur-contracts.php?chauffeur_id={ch1}', who='chauffeur')
    mine = [c for c in js.get('data', []) if int(c['id']) == ct1]
    check('L3-41', 'Le chauffeur voit son contrat SANS remuneration ni contenu',
          code == 200 and mine and 'remuneration_montant' not in mine[0] and 'contenu_genere' not in mine[0] and js.get('remuneration_visible') is False, str(js)[:300])
    code, js = call('GET', f'/chauffeur-contracts.php?chauffeur_id={ch1}', who='rh')
    check('L3-42', 'La RH voit la remuneration', any(c.get('remuneration_montant') is not None for c in js.get('data', [])))
    code, _ = call('GET', '/chauffeur-contracts.php', who='fleet')
    check('L3-43', 'Fleet n\'accede pas aux contrats (donnee sensible)', code == 403)
    code, js = upload('rh', 'chauffeur_contract', ct1, name='contrat_signe.pdf', categorie='contrat_signe')
    fct = js.get('id')
    code2, _ = call('PUT', '/chauffeur-contracts.php', {'id': ct1, 'action': 'signer', 'signe_le': D(-30), 'fichier_id': fct}, who='rh')
    code3, _, _ = download('chauffeur', fct)
    check('L3-44', 'Contrat signe joint ; le chauffeur ne telecharge pas le fichier (remuneration)', code == 200 and code2 == 200 and code3 in (403, 404), str((code, code2, code3)))
    code, _ = call('PUT', '/chauffeur-contracts.php', {'id': ct1, 'action': 'resilier'}, who='rh')
    code2, _ = call('PUT', '/chauffeur-contracts.php', {'id': ct1, 'action': 'resilier', 'motif': 'Fin de collaboration', 'resilie_le': D(0)}, who='rh')
    code3, _ = call('PUT', '/chauffeur-contracts.php', {'id': ct1, 'action': 'modifier', 'preavis_jours': 1}, who='rh')
    check('L3-45', 'Resiliation : motif obligatoire, puis lecture seule', (code, code2, code3) == (400, 200, 409), str((code, code2, code3)))

    # ---------- Alertes ----------
    al = call('GET', f'/alertes.php?chauffeur_id={ch_autre}', who='rh')[1]['data']
    types = {a['type'] for a in al}
    check('L3-46', 'Alertes RH : permis et contrat manquants pour un chauffeur sans dossier', {'permis_manquant', 'contrat_manquant'} <= types, str(types))
    al = call('GET', '/alertes.php', who='chauffeur')[1]['data']
    check('L3-47', 'Alertes chauffeur limitees a sa fiche', all(a['chauffeur_id'] in (None, ch1) for a in al), str(al)[:300])
    code, js = call('GET', '/alertes.php', who='parent')
    check('L3-48', 'Parent : aucune alerte dossier', code == 200 and js['data'] == [], str(js)[:200])

    # ---------- Journal ----------
    j = sql("SELECT module_key, COUNT(*) AS n FROM journal_activite WHERE module_key IN ('incidents','chauffeur_documents','chauffeur_contracts','contract_templates','fichiers') GROUP BY module_key")
    check('L3-49', 'Toutes les actions du lot 3 sont journalisees', len(j) == 5, str(j))

    ok = sum(1 for r in RESULTS if r[2])
    print(f'\n{ok}/{len(RESULTS)} tests reussis')
    sys.exit(0 if ok == len(RESULTS) else 1)


if __name__ == '__main__':
    main()
