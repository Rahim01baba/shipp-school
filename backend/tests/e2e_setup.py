"""Prepare la recette pour le test navigateur du suivi GPS (donnees fictives)."""
import json
from test_gps import prepare
circuits, arrets, chs, trajets = prepare()
print(json.dumps({'circuits': circuits, 'chauffeurs': {k: v[0] for k, v in chs.items()}, 'trajets': {f'{c}|{s}': t for (c, s), t in trajets.items()}}))
