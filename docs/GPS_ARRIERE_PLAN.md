# Suivi GPS en arrière-plan : ce qui est garanti, ce qui ne l'est pas

## Architecture retenue

SHIPP School est une application web : React sur Vercel, API PHP + MySQL sur cPanel. Il n'y a ni application mobile native ni Supabase. Le suivi GPS est donc construit en **application web installable (PWA légère)** :

- le chauffeur ouvre SHIPP dans Chrome (Android) ou Safari (iPhone), idéalement depuis l'icône « Ajouter à l'écran d'accueil » (manifeste fourni) ;
- pendant une course, la page demande au téléphone de **garder l'écran allumé** (API Wake Lock) ;
- les positions sont **mises en file d'attente sur le téléphone** et envoyées par lots : une coupure réseau ne perd rien, le renvoi ne crée pas de doublon ;
- le suivi continue quand le chauffeur change d'écran dans SHIPP (scanner, incident) ;
- le passage en arrière-plan est détecté et signalé au Back Office.

## Limite technique (à connaître)

Un navigateur **ne garantit pas** la localisation quand l'écran est verrouillé ou quand l'application passe en arrière-plan :

| Situation | Android (Chrome) | iPhone (Safari / écran d'accueil) |
|---|---|---|
| SHIPP ouvert au premier plan, écran allumé | Suivi OK | Suivi OK |
| Autre application au premier plan | Suivi suspendu en quelques secondes ou minutes | Suivi suspendu |
| Écran verrouillé | Suivi suspendu (variable selon le constructeur et l'économie d'énergie) | Suivi suspendu |

Ce qui est fait pour ne rien cacher :

- le téléphone envoie « application en arrière-plan / écran verrouillé » dès qu'il le peut ;
- le Back Office n'affiche jamais « GPS actif » sur une position ancienne : il affiche 🟠 POSITION ANCIENNE (au-delà de 30 s), puis 🔴 GPS PERDU (au-delà de 120 s), avec « position reçue il y a N secondes » ;
- au retour au premier plan, les positions en attente partent et le suivi reprend tout seul.

**Consigne chauffeur tant que l'application mobile n'existe pas** : téléphone sur un support, chargeur branché, SHIPP ouvert à l'écran pendant toute la course. L'écran reste allumé automatiquement.

## Pour un suivi garanti écran verrouillé : l'application mobile (enveloppe)

La solution fiable consiste à mettre l'application SHIPP existante dans une enveloppe native, par exemple **Capacitor** avec une extension de géolocalisation en arrière-plan (service de premier plan Android avec notification « Course en cours », mode localisation en arrière-plan d'iOS). Ce que cela implique :

- **aucune réécriture** : le même code React est embarqué, et l'enveloppe envoie ses positions à la même API `gps-position.php` (même format, mêmes contrôles de sécurité, même anti-doublon) ;
- publication sur le Play Store et l'App Store (comptes développeur, fiche, politique de confidentialité mentionnant la localisation pendant les courses) ;
- sur iPhone, validation Apple de l'usage « localisation en arrière-plan », justifié par le suivi des courses de transport scolaire.

C'est un changement d'architecture (nouveau livrable mobile) : à décider avant de le lancer. Le travail côté serveur et côté Back Office est déjà prêt pour cette étape.

## Protocole de test sur un vrai téléphone (TEST 8)

Ce test ne peut pas être automatisé en recette : il se fait sur le téléphone d'un chauffeur de test, sur un trajet de test.

1. Se connecter avec un compte chauffeur de test, ouvrir « Mon activité », démarrer la course.
2. Sur le Back Office, ouvrir « Suivi flottes » : le véhicule doit être 🟢 GPS ACTIF.
3. Laisser SHIPP ouvert 2 minutes en marchant : le marqueur doit avancer.
4. Verrouiller l'écran 3 minutes. Sur le Back Office, noter le moment où le véhicule passe 🟠 puis 🔴, et si le signal « Application en arrière-plan / écran verrouillé » apparaît.
5. Déverrouiller : le suivi doit reprendre (🟢) en moins de 15 s, sans action du chauffeur.
6. Refaire l'étape 4 en ouvrant une autre application (WhatsApp) au lieu de verrouiller.
7. Couper les données mobiles 1 minute puis les rétablir : l'historique du trajet doit contenir les positions de la coupure, sans doublon.
8. Terminer la course : le véhicule disparaît de la flotte active et l'historique est complet.

Noter pour chaque modèle de téléphone testé (marque, Android ou iOS, version) le résultat des étapes 4 et 6 : ce relevé permettra de décider s'il faut l'application mobile.
