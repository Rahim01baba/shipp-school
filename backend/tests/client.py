"""Client de test de l'API SHIPP School (recette locale uniquement)."""
import json, os, urllib.request, urllib.error

BASE = os.environ.get('SHIPP_API', 'http://127.0.0.1:8080/apiv1')
PWD = os.environ.get('SHIPP_PWD', 'Recette2026!')
USERS = {
    'rahim': 'test.rahim@example.com', 'admin': 'admin@shipp-group.com',
    'parent': 'parent@shipp-group.com', 'chauffeur': 'chauffeur@shipp-group.com',
    'restaurant': 'restaurant@shipp-group.com', 'fleet': 'fleet@shipp-group.com',
}
_tokens = {}

def call(method, path, body=None, who=None, token=None, raw=False):
    url = BASE + path
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header('Content-Type', 'application/json')
    tok = token or (login(who) if who else None)
    if tok:
        req.add_header('Authorization', 'Bearer ' + tok)
    try:
        with urllib.request.urlopen(req) as r:
            txt = r.read().decode(); code = r.status
    except urllib.error.HTTPError as e:
        txt = e.read().decode(); code = e.code
    if raw:
        return code, txt
    try:
        return code, json.loads(txt)
    except Exception:
        return code, {'_raw': txt[:500]}

def login(who, password=None, identifiant=None):
    if who in _tokens and password is None:
        return _tokens[who]
    body = {'email': identifiant or USERS.get(who, who), 'password': password or PWD}
    code, js = call('POST', '/auth-login.php', body)
    if code != 200:
        raise RuntimeError(f'login {who} -> {code} {js}')
    if password is None:
        _tokens[who] = js['token']
    return js['token']

def reset_tokens():
    _tokens.clear()
