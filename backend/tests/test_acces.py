"""Tests d'acces et de non-regression SHIPP School (recette locale).

Prerequis : base de recette recreee (tools/reset_recette.php) puis migration 001.
Usage : python3 test_acces.py
"""
import json, os, subprocess, sys
from client import call, login, reset_tokens

BACKEND = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RESULTS = []


def sql(query):
    """Execute une requete sur la base de recette (preparation des jeux de test)."""
    php = ("$c=require '" + BACKEND + "/db-config.php';"
           "$p=new PDO(\"mysql:host={$c['host']};dbname={$c['name']}\",$c['user'],$c['pass']);"
           "$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);"
           "$s=$p->query($argv[1]);echo json_encode($s->columnCount()?$s->fetchAll(PDO::FETCH_ASSOC):[]);")
    out = subprocess.run(['php', '-r', php, query], capture_output=True, text=True)
    if out.returncode:
        raise RuntimeError(out.stderr)
    return json.loads(out.stdout or '[]')


def check(ident, label, ok, detail=''):
    RESULTS.append((ident, label, bool(ok), detail))
    print(('OK   ' if ok else 'ECHEC') + f' {ident} {label}' + (f' -- {detail}' if detail and not ok else ''))


def ids(resp):
    code, js = resp
    return sorted(int(r['id']) for r in js.get('data', [])) if code == 200 else None


def prepare():
    # Jeu de test : 2 circuits, le chauffeur titulaire du circuit A, eleves repartis.
    admin = 'rahim'
    ca = call('POST', '/crud.php?module=circuits', {'nom': 'CIRCUIT-A', 'statut': 'actif', 'vehicule_id': 1}, who=admin)[1]['id']
    cb = call('POST', '/crud.php?module=circuits', {'nom': 'CIRCUIT-B', 'statut': 'actif', 'vehicule_id': 2}, who=admin)[1]['id']
    for c in (ca, cb):
        for o in (1, 2):
            call('POST', '/crud.php?module=etapes', {'circuit_id': c, 'nom': f'Arret {o}', 'ordre': o, 'statut': 'active'}, who=admin)
    call('PUT', '/crud.php?module=eleves', {'id': 6, 'circuit_id': ca}, who=admin)   # enfant du parent
    call('PUT', '/crud.php?module=eleves', {'id': 3, 'circuit_id': ca}, who=admin)
    call('PUT', '/crud.php?module=eleves', {'id': 4, 'circuit_id': cb}, who=admin)
    code, _ = call('POST', '/fleet-reaffecter.php', {'type': 'chauffeur', 'circuit_id': ca, 'nouveau_id': 4}, who=admin)
    ta = call('POST', '/crud.php?module=trajets', {'circuit_id': ca, 'date_trajet': '2026-09-25', 'statut': 'planifie'}, who=admin)[1]['id']
    tb = call('POST', '/crud.php?module=trajets', {'circuit_id': cb, 'date_trajet': '2026-09-25', 'statut': 'planifie'}, who=admin)[1]['id']
    # Parent sans aucun enfant lie
    sql("INSERT INTO users (id, ecole_id, name, email, password_hash, status) SELECT 7, 2, 'Parent Sans Enfant', 'parent2@test.local', password_hash, 'active' FROM users WHERE id = 3")
    sql("INSERT INTO user_roles (user_id, role_id, ecole_id) SELECT 7, id, 2 FROM roles WHERE role_key = 'parent'")
    # Annee archivee avec un eleve historique
    sql("INSERT INTO annees_scolaires (id, ecole_id, libelle, date_debut, date_fin, statut) VALUES (9, 2, '2025-2026', '2025-09-01', '2026-07-31', 'archivee')")
    sql("INSERT INTO eleves (id, ecole_id, annee_scolaire_id, nom, prenom, statut) VALUES (90, 2, 9, 'Historique', 'Eleve', 'actif')")
    return ca, cb, ta, tb


def main():
    ca, cb, ta, tb = prepare()

    # --- Parent : uniquement ses enfants ---
    check('AT-01', 'Parent voit son enfant (liste eleves)', ids(call('GET', '/crud.php?module=eleves', who='parent')) == [6])
    check('AT-02', "Parent : modification de l'eleve d'une autre famille refusee",
          call('PUT', '/crud.php?module=eleves', {'id': 3, 'nom': 'X'}, who='parent')[0] in (403, 404))
    check('AT-02b', "Parent : historique d'abonnement d'un autre enfant refuse",
          call('GET', '/abonnement-action.php?abonnement_id=1', who='parent')[0] in (403, 404))
    check('AT-03', 'Parent : notifications de son enfant uniquement',
          all(n['cible'] == 'eleve:6' for n in call('GET', '/crud.php?module=notifications', who='parent')[1].get('data', [])))
    check('AT-03b', 'Parent : trajets du circuit de son enfant uniquement', ids(call('GET', '/crud.php?module=trajets', who='parent')) == [ta])
    check('AT-03c', 'Parent : plus acces au centre Fleet', call('GET', '/fleet-vue-du-jour.php', who='parent')[0] == 403)
    check('AT-03d', 'Parent : plus acces aux anciennes tables transport/cantine',
          call('GET', '/crud.php?module=transport', who='parent')[0] == 403 and call('GET', '/crud.php?module=cantine', who='parent')[0] == 403)
    p2 = [ids(call('GET', f'/crud.php?module={m}', token=login('p2', identifiant='parent2@test.local'))) for m in ('eleves', 'notifications', 'trajets', 'abonnements', 'scans')]
    check('AT-04', 'Parent sans enfant lie : ne voit rien (et non tout)', all(x == [] for x in p2), str(p2))

    # --- Chauffeur : uniquement ses circuits ---
    check('AT-05a', 'Chauffeur voit les eleves de son circuit', ids(call('GET', '/crud.php?module=eleves', who='chauffeur')) == [3, 6])
    check('AT-05b', 'Chauffeur voit les trajets de son circuit', ids(call('GET', '/crud.php?module=trajets', who='chauffeur')) == [ta])
    check('AT-05c', "Chauffeur : demarrer le trajet d'un autre circuit refuse",
          call('POST', '/trajet-avancer.php', {'trajet_id': tb, 'action': 'demarrer'}, who='chauffeur')[0] == 404)
    check('AT-05d', 'Chauffeur : demarre son propre trajet',
          call('POST', '/trajet-avancer.php', {'trajet_id': ta, 'action': 'demarrer'}, who='chauffeur')[0] == 200)
    check('AT-05e', "Chauffeur : scan d'un eleve de son circuit accepte",
          call('POST', '/scan-enregistrer.php', {'eleve_id': 6, 'type': 'transport_embarquement', 'methode': 'code', 'trajet_id': ta}, who='chauffeur')[0] == 200)
    code, js = call('POST', '/scan-enregistrer.php', {'eleve_id': 4, 'type': 'transport_embarquement', 'methode': 'code'}, who='chauffeur')
    v5 = bool(sql("SHOW TABLES LIKE 'transport_events'"))
    # Avant la migration 002 : refus. Apres : accepte avec alerte pendant son propre trajet en cours (decision D-07).
    check('AT-05f', "Chauffeur : eleve hors de son circuit refuse (ou accepte avec alerte pendant son trajet)",
          (code == 200 and 'eleve_non_affecte' in js.get('alertes', [])) if v5 else code == 403, f'{code} {js}')
    check('AT-05g', 'Chauffeur : plus acces au centre Fleet ni au journal',
          call('GET', '/fleet-vue-du-jour.php', who='chauffeur')[0] == 403 and call('GET', '/crud.php?module=journal_activite', who='chauffeur')[0] == 403)
    check('AT-06', "Chauffeur : modification par id d'un trajet hors perimetre refusee",
          call('PUT', '/crud.php?module=trajets', {'id': tb, 'statut': 'annule'}, who='chauffeur')[0] in (403, 404))

    # --- Journal inalterable ---
    check('AT-09a', 'Fleet Manager : modification du journal refusee',
          call('PUT', '/crud.php?module=journal_activite', {'id': 1}, who='fleet')[0] == 403)
    check('AT-09b', 'Admin : suppression du journal refusee',
          call('DELETE', '/crud.php?module=journal_activite', {'id': 1}, who='rahim')[0] == 403)

    # --- Admin et roles ---
    check('AT-R1', 'Admin Test accede enfin au centre Fleet', call('GET', '/fleet-vue-du-jour.php', who='admin')[0] == 200)
    check('AT-R2', 'Restaurant accede aux eleves, abonnements et scans (Service Cantine)',
          all(call('GET', f'/crud.php?module={m}', who='restaurant')[0] == 200 for m in ('eleves', 'abonnements', 'scans')))
    check('AT-R3', 'Restaurant : pointage cantine possible',
          call('POST', '/scan-enregistrer.php', {'eleve_id': 5, 'type': 'cantine', 'methode': 'recherche'}, who='restaurant')[0] == 200)
    pm = call('GET', '/permissions-me.php', who='parent')[1]
    check('AT-R4', "permissions-me : le parent n'a plus de droit Fleet et a son scope",
          pm['permissions']['affectations_chauffeur']['can_read'] is False and pm['scope'] == 'CHILDREN')

    # --- Annee scolaire ---
    lst = ids(call('GET', '/crud.php?module=eleves', who='rahim'))
    check('AT-A1', "Eleve d'une annee archivee absent des ecrans operationnels", 90 not in lst, str(lst))
    check('AT-A2', "Admin : consultation de l'historique possible (annee_scolaire_id=toutes)",
          90 in ids(call('GET', '/crud.php?module=eleves&annee_scolaire_id=toutes', who='rahim')))
    check('AT-A3', "Parent : ne peut pas elargir son perimetre avec le parametre d'annee",
          ids(call('GET', '/crud.php?module=eleves&annee_scolaire_id=toutes', who='parent')) == [6])

    # --- Corrections de workflow ---
    code, js = call('POST', '/abonnement-action.php', {'action': 'souscrire', 'eleve_id': 3, 'type': 'transport'}, who='rahim')
    check('AT-W1', 'Souscription en double : erreur claire (409) au lieu de 500', code == 409, f'{code} {js}')
    call('POST', '/trajet-avancer.php', {'trajet_id': ta, 'action': 'cloturer'}, who='chauffeur')
    check('AT-W2', 'Annulation d\'un trajet termine refusee',
          call('POST', '/trajet-avancer.php', {'trajet_id': ta, 'action': 'annuler'}, who='rahim')[0] == 400)
    fid = call('POST', '/crud.php?module=finance', {'libelle': 'Test', 'montant': 1000, 'type': 'recette', 'statut': 'en_attente'}, who='rahim')[1]['id']
    call('POST', '/finance-action.php', {'finance_id': fid, 'action': 'marquer_payee'}, who='rahim')
    check('AT-W3', 'Paiement en double refuse',
          call('POST', '/finance-action.php', {'finance_id': fid, 'action': 'marquer_payee'}, who='rahim')[0] == 400)

    # --- Securite ---
    h = call('GET', '/health.php')[1]
    check('AT-S1', "health.php n'expose plus la version de PHP", 'php_version' not in h and h.get('db') is True)
    tok = login('p2', identifiant='parent2@test.local')
    sql("UPDATE users SET status = 'inactive' WHERE id = 7")
    check('AT-S2', "Compte desactive : jeton refuse immediatement",
          call('GET', '/crud.php?module=eleves', token=tok)[0] == 401)

    # --- Comptes : mot de passe, telephone, role ---
    code, js = call('POST', '/users.php', {'name': 'Chauffeur Sans Email', 'telephone': '+225 07 00 00 00 01', 'password': 'Chauffeur2026!', 'role_key': 'chauffeur'}, who='rahim')
    check('AT-C1', 'Admin cree un chauffeur sans e-mail, avec mot de passe et role', code == 200 and js['data']['a_mot_de_passe'], f'{code} {js}')
    try:
        tokc = login('c2', password='Chauffeur2026!', identifiant='+2250700000001')
        okc = call('GET', '/permissions-me.php', token=tokc)[1].get('scope') == 'ASSIGNED_ROUTE'
    except Exception as e:
        okc = False
    check('AT-C2', 'Connexion par numero de telephone', okc)
    check('AT-C3', 'Gestion des roles reservee aux administrateurs', call('GET', '/roles.php', who='fleet')[0] == 403 and call('GET', '/roles.php', who='rahim')[0] == 200)
    code, _ = call('POST', '/roles.php', {'user_id': 7, 'role_key': 'restaurant'}, who='rahim')
    check('AT-C4', "Admin attribue un role a un compte", code == 200)

    ok = sum(1 for r in RESULTS if r[2])
    print(f'\n{ok}/{len(RESULTS)} tests reussis')
    json.dump(RESULTS, open(os.environ.get('SHIPP_TEST_REPORT', '/tmp/shipp_tests.json'), 'w'), indent=1)
    sys.exit(0 if ok == len(RESULTS) else 1)


if __name__ == '__main__':
    main()
