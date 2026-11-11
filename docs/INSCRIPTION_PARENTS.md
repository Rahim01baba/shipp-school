# Inscription autonome des parents (migration 007)

## Parcours

1. SHIPP partage **un seul lien** : `https://<application>/inscription`.
2. Le parent crée son compte (nom, téléphone, e-mail facultatif, mot de passe).
   Le compte reçoit uniquement le rôle **parent** et ne voit **aucun enfant**.
3. Le parent saisit le **code parent** de chaque enfant (`SHP-XXXX-XXXX`) dans
   « Ajouter mon enfant ». L'enfant apparaît aussitôt dans « Suivi de mes enfants ».
4. L'administration génère, exporte, régénère les codes et retire un accès depuis
   **Élèves → Codes parents**.

## Pourquoi un code et pas l'identifiant de l'élève

L'identifiant est séquentiel (12, 13, 14…) : n'importe qui pourrait suivre l'enfant
d'un autre. Le code est aléatoire (31⁸ ≈ 850 milliards de combinaisons, sans 0/O/1/I/L).

## Garde-fous (côté serveur)

| Règle | Valeur |
|---|---|
| Inscriptions par adresse IP | 5 par heure |
| Codes erronés par compte | 5 par heure (puis blocage, même avec un bon code) |
| Codes erronés par adresse IP | 20 par heure |
| Comptes par enfant | 2 (paramètre `parent_max_comptes_par_eleve`) ; au-delà, refus et alerte admin |
| Élève inactif | code refusé |
| Chaque liaison | notification aux administrateurs + journal d'activité |
| Inscriptions | désactivables : paramètre `parent_inscription_ouverte` = `0` |

**Distribution des codes** : individuellement à chaque famille (message privé, carnet,
papier remis à l'enfant). **Jamais dans un groupe WhatsApp de ligne.**

## Fichiers

- `backend/sql/007_inscription_parents.sql` / `_down.sql` (additive, réversible)
- `backend/apiv1/lib/parents.php`
- `backend/apiv1/auth-register.php` (public), `parent-lier.php` (parent), `codes-parents.php` (admin)
- `src/pages/Inscription.jsx`, `AjouterEnfant.jsx`, `CodesParents.jsx` ; routes, menu, lien sur l'écran de connexion
- `backend/tests/test_inscription_parents.py` (28 vérifications)

## Déploiement (ordre obligatoire)

1. Sauvegarde de la base.
2. Appliquer `007_inscription_parents.sql` (phpMyAdmin → SQL).
3. Copier les 4 fichiers PHP dans `apiv1/` et `apiv1/lib/` du serveur.
4. Seulement ensuite : fusionner le frontend (déploiement Vercel).
5. Admin → Codes parents → « Générer les codes manquants », puis exporter.

Retour arrière : retirer les fichiers PHP, revenir au frontend précédent, puis
`007_inscription_parents_down.sql` (les comptes parents et leurs liaisons sont conservés).
