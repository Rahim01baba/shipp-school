# Recette locale

1. Serveur MySQL compatible sur 127.0.0.1:3307 (utilise ici : Dolt), base `shipp`.
2. `backend/db-config.php` et `backend/config.local.php` locaux (non versionnes).
3. `php -S 127.0.0.1:8080 -t backend`
4. `php backend/tools/reset_recette.php sql/001_roles_scopes.sql sql/002_transport_connecte.sql sql/003_incidents_dossier.sql sql/004_reporting.sql sql/005_enko_flotte_paiements.sql`
   (schema de production + donnees de demo + migrations). `config.local.php` doit definir `private_dir` (lot 3).
5. Chaque suite sur une base fraichement recreee :
   `python3 test_acces.py` (34), `python3 test_lot2.py` (32), `python3 test_lot3.py` (49), `python3 test_lot4.py` (28), `python3 test_lot5.py` (42)

`snapshot.py` photographie les reponses de l'API par compte : a comparer avant / apres
une modification pour verifier la non-regression.
