<?php
// Copier en config.local.php (a cote de db-config.php, hors du dossier apiv1).
// Ne jamais versionner le fichier reel.
return [
    // Secret de signature des jetons de connexion (64 caracteres aleatoires minimum).
    // Lors du premier deploiement, reprendre la valeur actuellement ecrite dans
    // apiv1/auth-lib.php en production pour ne deconnecter personne.
    'jwt_secret' => 'a-remplacer',
    // Dossier des fichiers prives (documents chauffeur, contrats signes, photos
    // d'incident). OBLIGATOIREMENT hors de public_html, ex. /home/shippgroup/shipp-private
    'private_dir' => '/home/UTILISATEUR/shipp-private',
];
