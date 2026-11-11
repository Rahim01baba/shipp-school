"""Tests de l'inscription autonome des parents et de la liaison par code (migration 007).

Recette locale uniquement (voir README.md) : base recreee + migrations 001 a 007,
serveur `php -S 127.0.0.1:8080 -t backend`. Compte admin : SHIPP_ADMIN / SHIPP_PWD.
"""
import json, os, subprocess, sys, time, uuid
from client import call, login

ok = 0
ko = []


def check(nom, cond, detail=''):
    global ok
    if cond:
        ok += 1
    else:
        ko.append(f'{nom} {detail}')
        print('ECHEC', nom, detail)


ADMIN = os.environ.get('SHIPP_ADMIN', 'admin')


def tel():
    return '07' + str(uuid.uuid4().int)[:8]


def inscrire(nom='Parent Test', telephone=None, email='', pwd='MotDePasse1'):
    return call('POST', '/auth-register.php', {'nom': nom, 'telephone': telephone or tel(), 'email': email, 'password': pwd})


admin = login(ADMIN)

# --- Codes parents (admin) ---
c, js = call('POST', '/codes-parents.php', {'action': 'generer'}, token=admin)
check('generer 200', c == 200, js)
c, js = call('GET', '/codes-parents.php', token=admin)
check('liste 200', c == 200, js)
eleves = js.get('data', [])
check('inactifs exclus', all(e['code_parent'] for e in eleves) and len(eleves) >= 2, len(eleves))
codes = [e['code_parent'] for e in eleves]
check('format SHP-XXXX-XXXX', all(len(x) == 13 and x.startswith('SHP-') for x in codes), codes[:2])
check('codes uniques', len(set(codes)) == len(codes))
e1, e2 = eleves[0], eleves[1]
c, js = call('POST', '/codes-parents.php', {'action': 'generer'}, token=admin)
check('generer idempotent', js.get('generes') == 0, js)

# --- Inscription ---
c, js = inscrire(pwd='court')
check('mot de passe court refuse', c == 400, js)
c, js = inscrire(telephone='abc')
check('telephone invalide refuse', c == 400, js)
t1 = tel()
c, js = inscrire('Awa Kone', telephone=t1)
check('inscription 201', c == 201 and js.get('token'), js)
p1 = js.get('token')
c, js = inscrire('Doublon', telephone=t1)
check('telephone deja utilise 409', c == 409, js)
c, js = call('POST', '/auth-login.php', {'email': '+225' + t1, 'password': 'MotDePasse1'})
check('connexion par telephone normalise', c == 200, js)

# Un parent sans enfant ne voit aucun eleve.
c, js = call('GET', '/crud.php?module=eleves', token=p1)
if c != 404:
    check('parent sans enfant ne voit aucun eleve', c in (200, 403) and not (js.get('data') or []), js)

# --- Liaison par code ---
c, js = call('POST', '/parent-lier.php', {'code': 'SHP-0000-0000'}, token=p1)
check('code mal forme 400', c == 400, js)
c, js = call('POST', '/parent-lier.php', {'code': e1['code_parent'].lower().replace('-', ' ')}, token=p1)
check('liaison saisie souple 201', c == 201 and js['eleve']['id'] == int(e1['id']), js)
c, js = call('POST', '/parent-lier.php', {'code': e1['code_parent']}, token=p1)
check('liaison deja faite 200', c == 200 and js.get('deja_lie'), js)
c, js = call('GET', '/parent-lier.php', token=p1)
check('mes enfants = 1', c == 200 and len(js['data']) == 1, js)

# Admin non parent : refuse.
c, js = call('POST', '/parent-lier.php', {'code': e2['code_parent']}, token=admin)
check('non parent refuse 403', c == 403, js)

# Maximum 2 comptes par enfant.
c, js = inscrire('Deuxieme parent')
p2 = js['token']
c, js = call('POST', '/parent-lier.php', {'code': e1['code_parent']}, token=p2)
check('2e compte accepte', c == 201, js)
c, js = inscrire('Troisieme parent')
p3 = js['token']
c, js = call('POST', '/parent-lier.php', {'code': e1['code_parent']}, token=p3)
check('3e compte refuse 409', c == 409, js)

# Code regenere : l'ancien ne marche plus, les liaisons restent.
ancien = e2['code_parent']
c, js = call('POST', '/codes-parents.php', {'action': 'regenerer', 'eleve_id': int(e2['id'])}, token=admin)
check('regenerer 200', c == 200 and js['code_parent'] != ancien, js)
nouveau = js['code_parent']
c, js = call('POST', '/parent-lier.php', {'code': ancien}, token=p3)
check('ancien code refuse 404', c == 404, js)
c, js = call('POST', '/parent-lier.php', {'code': nouveau}, token=p3)
check('nouveau code accepte', c == 201, js)

# Limitation : 5 codes errones par heure.
c, js = inscrire('Fraudeur')
pf = js['token']
codes_faux = ['SHP-AAAA-AAA' + x for x in 'BCDEFG']
res = [call('POST', '/parent-lier.php', {'code': x}, token=pf)[0] for x in codes_faux]
check('5 echecs puis 429', res[:5] == [404] * 5 and res[5] == 429, res)
c, js = call('POST', '/parent-lier.php', {'code': e1['code_parent']}, token=pf)
check('bloque meme avec un bon code', c == 429, js)

# Les codes ne sont pas visibles par un parent.
c, js = call('GET', '/codes-parents.php', token=p1)
check('codes reserves admin 403', c == 403, js)

# Retrait d'acces.
c, js = call('GET', '/codes-parents.php', token=admin)
lia = [x for e in js['data'] if int(e['id']) == int(e1['id']) for x in e['comptes']]
check('2 comptes lies visibles', len(lia) == 2, lia)
c, js = call('POST', '/codes-parents.php', {'action': 'retirer', 'liaison_id': int(lia[0]['liaison_id'])}, token=admin)
check('retrait 200', c == 200, js)

# Export CSV.
c, txt = call('GET', '/codes-parents.php?format=csv', token=admin, raw=True)
check('export csv', c == 200 and 'Code parent' in txt, txt[:80])

# Admins prevenus.
c, js = call('GET', '/notifications-moi.php', token=admin)
if c == 200:
    check('admins prevenus', any('parent' in (n.get('titre') or '').lower() for n in js.get('data', [])), js)

print(f'{ok} OK, {len(ko)} echec(s)')
sys.exit(1 if ko else 0)
