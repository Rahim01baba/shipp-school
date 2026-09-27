-- =====================================================================
-- Migration 006 — Suivi GPS temps reel de la flotte scolaire
-- SHIPP School V5. Strictement additive : aucune table, colonne ou donnee
-- existante n'est supprimee ni modifiee.
-- Prerequis : migrations 001 a 005. Retour arriere : 006_suivi_gps_down.sql
-- =====================================================================

-- 1. Coordonnees fixes des points d'arret (GPS des arrets, construit le circuit).
--    Colonnes facultatives : les arrets existants restent valides sans position.
ALTER TABLE `etapes`
  ADD COLUMN `latitude` decimal(10,7) DEFAULT NULL,
  ADD COLUMN `longitude` decimal(10,7) DEFAULT NULL;

-- 2. Historique des positions du telephone du chauffeur (GPS du vehicule).
--    Une ligne par position recue pendant un trajet en cours.
--    recorded_at_ms = heure de la mesure sur le telephone (epoch, millisecondes) :
--    la cle unique (trajet_id, recorded_at_ms) empeche les doublons quand le
--    telephone renvoie sa file d'attente apres une coupure reseau.
CREATE TABLE IF NOT EXISTS `trajet_positions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `trajet_id` int(11) NOT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `chauffeur_id` int(11) NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `vitesse_kmh` decimal(5,1) DEFAULT NULL,
  `cap` smallint(6) DEFAULT NULL,
  `precision_m` decimal(7,1) DEFAULT NULL,
  `batterie` tinyint(4) DEFAULT NULL,
  `recorded_at_ms` bigint(20) NOT NULL,
  `recorded_at` datetime NOT NULL,
  `received_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tp_trajet_mesure` (`trajet_id`, `recorded_at_ms`),
  KEY `idx_tp_vehicle` (`vehicle_id`, `recorded_at`),
  KEY `idx_tp_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Parametres du suivi (modifiables sans deploiement).
INSERT IGNORE INTO `parametres` (ecole_id, cle, valeur) VALUES
(NULL, 'gps_intervalle_secondes', '10'),
(NULL, 'gps_position_ancienne_secondes', '30'),
(NULL, 'gps_perdu_secondes', '120'),
(NULL, 'gps_rayon_arret_metres', '80'),
(NULL, 'gps_precision_faible_metres', '100'),
(NULL, 'gps_ecart_circuit_metres', '400');

-- 4. Droit de consulter la carte flotte et l'historique GPS.
--    Envoi des positions : controle par le code (chauffeur du trajet en cours
--    uniquement), pas par ce droit. Les parents n'y ont pas acces.
INSERT IGNORE INTO `permissions` (module_key, module_label, sort_order) VALUES
('suivi_gps', 'Suivi GPS de la flotte', 250);

INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'suivi_gps';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'suivi_gps';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'suivi_gps';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 1, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'suivi_gps';
