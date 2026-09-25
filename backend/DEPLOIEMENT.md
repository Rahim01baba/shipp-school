# Déploiement du lot P0 (T5-00 à T5-04)

Rien de ce lot n'a été déployé en production. Ce document décrit la procédure à suivre le jour où vous validez le passage en production.

## Contenu du lot

| Ticket | Contenu |
| --- | --- |
| T5-00 | Backend `apiv1/` versionné dans Git (copie fidèle de la production), schéma de production dans `sql/000_…`, recette locale |
| T5-01 | Socle commun `apiv1/lib/` (`authz.php`, `db.php`) ; secret JWT sorti du code (`config.local.php`) |
| T5-02 | Migration `sql/001_roles_scopes.sql` (+ retour arrière `001_roles_scopes_down.sql`) |
| T5-03 | Cloisonnement côté serveur : rôles + périmètres (parent = ses enfants, chauffeur = ses circuits), `is_admin` respecté, journal non modifiable, comptes avec mot de passe et téléphone, connexion par téléphone, gestion des rôles (`roles.php`) |
| T5-04 | Lectures limitées à l'année scolaire active (historique consultable par les administrateurs) |

## Ordre impératif

La migration **avant** le code : le nouveau code attribue les périmètres à partir de la table `user_roles`. Sans elle, les comptes non administrateurs n'ont plus accès à rien.

1. **Sauvegarde** : phpMyAdmin → base `shippgroup_shippgroup` → Exporter (SQL, structure + données). Conserver aussi une copie zip du dossier `apiv1/`.
2. **Secret** : créer `/home/shippgroup/public_html/app.shipp-group.com/config.local.php` (à côté de `db-config.php`, hors de `apiv1/`) sur le modèle de `config.local.example.php`. Y reporter **la valeur actuelle** de `JWT_SECRET` lue dans `apiv1/auth-lib.php` pour ne déconnecter personne.
3. **Migration** : phpMyAdmin → onglet SQL → coller `sql/001_roles_scopes.sql` → Exécuter. Contrôle : `SELECT COUNT(*) FROM user_roles;` doit renvoyer 6 (comptes actuels).
4. **Code** : téléverser le contenu de `apiv1/` (fichiers modifiés + dossier `lib/` avec son `.htaccess` + `roles.php`). Ne pas écraser `uploads/`.
5. **Nettoyage de sécurité** : supprimer `apiv1/scan-enregistrer.php.bak-20260812`, aujourd'hui téléchargeable publiquement.
6. **Frontend** : fusionner la branche puis laisser Vercel redéployer (pages `Login.jsx` et `Rights.jsx` modifiées).
7. **Contrôles** : se connecter avec chaque compte de test et vérifier les points de `tests/test_acces.py` (parent : uniquement son enfant ; chauffeur : uniquement son circuit ; admin : centre Fleet accessible).

## Retour arrière

1. Remettre la copie zip de `apiv1/`.
2. Exécuter `sql/001_roles_scopes_down.sql` dans phpMyAdmin. Ce script ne retire que ce que la migration a ajouté et restaure `role_permissions` depuis sa sauvegarde.
3. En dernier recours : réimporter l'export de l'étape 1.

## Points d'attention

- **Nouveaux comptes** : un compte non administrateur sans rôle n'a accès à rien. Il faut lui attribuer un rôle dans « Gestion des droits ».
- **Chauffeurs** : un chauffeur ne voit que les élèves, trajets et scans des circuits dont il est titulaire (Fleet → « Changer le titulaire ») ou remplaçant. Tant qu'aucun circuit ne lui est affecté, il ne voit rien.
- **Grille des rôles** : `role_permissions` est désormais appliquée. Ses anciennes valeurs, jamais utilisées, sont conservées dans `role_permissions_backup_20260925`.
- **Recette** : les tests ont été joués sur une base compatible MySQL (Dolt) chargée avec le schéma exact de production et une copie des données de démonstration. Un passage final sur une copie MariaDB de la production reste recommandé avant la mise en ligne.

---

# Lot 2 — Chauffeur et transport connecté (T5-05 à T5-11)

Prérequis : lot P0 déployé (migration 001 appliquée).

1. Sauvegarde (export phpMyAdmin + zip de `apiv1/`).
2. Migration : `sql/002_transport_connecte.sql` dans l'onglet SQL. Contrôles : `SELECT COUNT(*) FROM chauffeurs;` (une fiche par compte chauffeur existant), `SELECT COUNT(*) FROM eleve_affectations_transport;` (une par élève ayant déjà un circuit).
3. Code : `lib/transport.php`, `chauffeurs.php`, `vehicle-assignments.php`, `eleve-affectations.php`, `trajets-generer.php`, `chauffeur-jour.php`, `eleve-absent.php`, `parent-enfants.php`, `notifications-moi.php` et les fichiers modifiés (`scan-enregistrer.php`, `trajet-avancer.php`, `permissions-me.php`, `users.php`, `fleet-*`).
4. Frontend : pages Mon activité, Suivi de mes enfants, Chauffeurs, fiche chauffeur, Notifications, Trajets, Scanner.
5. Retour arrière : `sql/002_transport_connecte_down.sql` puis remise du zip.

# Lot 3 — Incidents / accidents, dossier chauffeur, contrats (T5-12 à T5-15)

Prérequis : lots P0 et 2 déployés.

1. Sauvegarde (export phpMyAdmin + zip de `apiv1/`).
2. **Dossier privé** : créer `/home/shippgroup/shipp-private` (HORS de `public_html`, droits 750) et ajouter dans `config.local.php` la ligne `'private_dir' => '/home/shippgroup/shipp-private',`. Sans elle, tout dépôt de fichier est refusé (le serveur refuse aussi un dossier situé sous la racine web).
3. Migration : `sql/003_incidents_dossier.sql`. Strictement additive ; les incidents déjà présents reçoivent seulement `source = 'reprise'`.
4. Code : `lib/fichiers.php`, `fichier.php`, `incidents.php`, `chauffeur-documents.php`, `chauffeur-contracts.php`, `contract-templates.php`, `alertes.php`.
5. Frontend : pages Incidents (liste, déclaration terrain, fiche), onglets du dossier chauffeur (documents, contrat, incidents, alertes), Modèles de contrat, Alertes.
6. **Modèle de contrat** : aucun texte n'est préchargé. La RH saisit le texte du contrat Word d'origine dans « Modèles de contrat » (variables `{{chauffeur_nom}}`, `{{remuneration_montant}}`…), puis l'active. Le montant du modèle ne sert qu'à pré-remplir : chaque contrat garde le sien.
7. Retour arrière : exporter d'abord les tables `incidents`, `incident_*`, `accident_details`, `fichiers`, `chauffeur_documents`, `chauffeur_contracts`, `contract_templates` (sinon les saisies sont perdues), puis `sql/003_incidents_dossier_down.sql` et remise du zip.

Règles appliquées côté serveur (vérifiées par `tests/test_lot3.py`, 49 tests) :

- la responsabilité d'un incident vaut « non déterminée » à la déclaration, quelle que soit la saisie ; seule une qualification justifiée (flotte, admin) la change ; un accident ne peut pas être clos sans qualification ;
- rien n'est supprimé : incident erroné → « annulé » avec motif ; document renouvelé → l'ancien passe « remplacé » ; fichier → retrait logique ; modèle → nouvelle version ;
- rémunération, texte généré et contrat signé : visibles uniquement par l'admin et la RH ; le chauffeur voit ses contrats sans ces éléments ; le gestionnaire de flotte ne voit pas les contrats ;
- parents : prévenus par un message neutre quand leur enfant est concerné, sans accès au registre des incidents.

# Lot 4 — Reporting (T5-16, T5-19, T5-20)

Prérequis : lots P0, 2 et 3 déployés.

1. Sauvegarde.
2. Migration : `sql/004_reporting.sql` (table `export_journal`, un index, un paramètre).
3. Code : `reporting.php`.
4. Frontend : page Reporting (lien « Reporting » du tableau de bord pour l'admin, la flotte et la RH).
5. Retour arrière : exporter `export_journal`, puis `sql/004_reporting_down.sql`.

Principes (vérifiés par `tests/test_lot4.py`, 28 tests) :

- période **date début → date fin obligatoire** (limitée par le paramètre `reporting_periode_max_jours`, 400 par défaut) ;
- chiffres calculés côté serveur à partir des événements réels, des trajets, des passages aux arrêts, des scans cantine et des incidents ; chaque total s'ouvre sur la liste détaillée qui le compose (même nombre) ;
- perimètre : l'admin voit tout (filtre établissement possible), la flotte et la RH uniquement leur établissement ; parents, chauffeurs et cantine n'y ont pas accès ;
- aucun score de chauffeur n'est calculé ; « accidents qualifiés chauffeur » ne compte que les qualifications faites par un responsable ;
- export CSV (s'ouvre dans Excel, séparateur `;`, formules neutralisées) réservé au droit « exporter », chaque export étant journalisé (`export_journal` + journal d'activité) ; PDF par l'impression du navigateur.

# Lot 5 — Sources ENKO : contacts, véhicules, navettes, retenues, paiements, import

Décisions appliquées (25/09/2026) : contact parent ajouté ; année scolaire lue d'après ses dates (septembre → juin par défaut, modifiable) ; immatriculation et rattachement chauffeur ↔ véhicule ; contrat aux champs remplis au besoin (D-27) ; navettes d'activité (D-28) ; documents du véhicule (D-29) ; retenues chiffrées (D-30) ; suivi mensuel Enko / SHIPP (D-25).

Prérequis : lots P0 à 4 déployés.

1. Sauvegarde (export phpMyAdmin + zip de `apiv1/`).
2. Migration : `sql/005_enko_flotte_paiements.sql`. Additive ; seule contrainte modifiée : la date de début d'un contrat devient facultative (élargissement, sans perte).
3. Code : `lib/xlsx.php`, `eleve-contacts.php`, `vehicle-documents.php`, `chauffeur-retenues.php`, `echeances-transport.php`, `tarifs.php`, `imports.php` et les fichiers modifiés (`crud.php`, `eleve-affectations.php`, `trajets-generer.php`, `parent-enfants.php`, `chauffeur-contracts.php`, `contract-templates.php`, `alertes.php`, `reporting.php`, `fichier.php`, `lib/authz.php`, `lib/fichiers.php`). L'extension PHP `zip` doit être active (lecture des fichiers Excel).
4. Frontend : fiche véhicule, contacts sur la fiche élève, navettes dans la fiche circuit, Suivi des paiements transport, Rémunérations chauffeurs, Import du suivi ENKO, onglet Paiements du Reporting.
5. **Avant tout import ENKO** : créer dans « Années scolaires » les années 2024-2025 et 2025-2026 (dates réelles de début et de fin). L'import n'accepte que les mois compris dans l'année choisie.
6. Retour arrière : exporter `eleve_contacts`, `vehicle_documents`, `chauffeur_retenues`, `tarifs`, `echeances_transport`, `echeances_historique`, `import_*`, puis `sql/005_enko_flotte_paiements_down.sql`.

Vérifié par `tests/test_lot5.py` (42 tests), avec un fichier Excel fictif reproduisant la structure du suivi ENKO. Le fichier réel a été analysé en recette sans être importé ni versionné.
