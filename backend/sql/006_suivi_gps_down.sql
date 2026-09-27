-- Retour arriere de la migration 006. Ne retire que ce qu'elle a ajoute.
-- ATTENTION : l'historique GPS (trajet_positions) et les coordonnees saisies sur
-- les arrets seraient perdus : exporter ces donnees avant.
DELETE rp FROM `role_permissions` rp JOIN `permissions` p ON p.id = rp.permission_id WHERE p.module_key = 'suivi_gps';
DELETE up FROM `user_permissions` up JOIN `permissions` p ON p.id = up.permission_id WHERE p.module_key = 'suivi_gps';
DELETE FROM `permissions` WHERE module_key = 'suivi_gps';
DELETE FROM `parametres` WHERE ecole_id IS NULL AND cle IN ('gps_intervalle_secondes', 'gps_position_ancienne_secondes', 'gps_perdu_secondes', 'gps_rayon_arret_metres', 'gps_precision_faible_metres', 'gps_ecart_circuit_metres');
DROP TABLE `trajet_positions`;
ALTER TABLE `etapes` DROP COLUMN `latitude`, DROP COLUMN `longitude`;
