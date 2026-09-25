"""Photographie des reponses GET de l'API par utilisateur (non-regression)."""
import json, sys
from client import call, USERS

MODULES = ['eleves','parents_eleves','transport','cantine','vehicules','circuits','finance','notifications',
 'ecoles','rapports','menus','utilisateurs','abonnements','annees_scolaires','etapes','trajets','scans',
 'parent_liaisons','ecole_modules','journal_activite','affectations_chauffeur','couvertures_chauffeur']
OTHERS = ['/permissions-me.php','/auth-me.php','/fleet-vue-du-jour.php','/fleet-disponibilites.php',
 '/modules-ecole.php','/finance-action.php?repartition=1','/permissions-matrix.php','/users.php']

def snap():
    out = {}
    for who in USERS:
        for m in MODULES:
            code, js = call('GET', f'/crud.php?module={m}', who=who)
            ids = sorted([r.get('id') for r in js.get('data', [])], key=lambda x: int(x)) if code == 200 else None
            out[f'{who} crud {m}'] = [code, ids]
        for p in OTHERS:
            code, js = call('GET', p, who=who)
            out[f'{who} {p}'] = [code, js if p in ('/permissions-me.php','/auth-me.php') else None]
    return out

if __name__ == '__main__':
    json.dump(snap(), open(sys.argv[1], 'w'), indent=1, sort_keys=True)
    print('ok')
