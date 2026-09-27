-- =====================================================================
-- Migration 002 — Chauffeur, transport connecte, evenements, notifications
-- SHIPP School V5, lot 2 (T5-05, T5-07, T5-08, T5-10). Strictement additive.
-- Prerequis : migration 001. Retour arriere : 002_transport_connecte_down.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS `chauffeurs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `ecole_id` int(11) DEFAULT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) DEFAULT NULL,
  `telephone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `date_naissance` date DEFAULT NULL,
  `urgence_nom` varchar(150) DEFAULT NULL,
  `urgence_telephone` varchar(30) DEFAULT NULL,
  `photo_fichier_id` int(11) DEFAULT NULL,
  `statut` enum('actif','suspendu','sorti') NOT NULL DEFAULT 'actif',
  `date_entree` date DEFAULT NULL,
  `date_sortie` date DEFAULT NULL,
  `perimetre` varchar(150) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'saisie',
  `import_lot_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_chauffeurs_user` (`user_id`),
  KEY `idx_chauffeurs_ecole` (`ecole_id`),
  KEY `idx_chauffeurs_telephone` (`telephone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `vehicle_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `chauffeur_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `contract_id` int(11) DEFAULT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `statut` enum('planifiee','active','terminee','annulee') NOT NULL DEFAULT 'active',
  `motif` varchar(255) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'saisie',
  `import_lot_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_va_chauffeur` (`chauffeur_id`, `date_debut`),
  KEY `idx_va_vehicle` (`vehicle_id`, `date_debut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `eleve_affectations_transport` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `annee_scolaire_id` int(11) DEFAULT NULL,
  `eleve_id` int(11) NOT NULL,
  `circuit_id` int(11) NOT NULL,
  `etape_montee_id` int(11) DEFAULT NULL,
  `etape_depose_id` int(11) DEFAULT NULL,
  `sens` enum('aller','retour','aller_retour') NOT NULL DEFAULT 'aller_retour',
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `statut` enum('active','terminee') NOT NULL DEFAULT 'active',
  `source` varchar(20) NOT NULL DEFAULT 'saisie',
  `import_lot_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_eat_eleve` (`eleve_id`),
  KEY `idx_eat_circuit` (`circuit_id`, `statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `trajets`
  ADD COLUMN `chauffeur_id` int(11) DEFAULT NULL,
  ADD COLUMN `vehicle_id` int(11) DEFAULT NULL,
  ADD COLUMN `sens` varchar(10) DEFAULT NULL,
  ADD COLUMN `motif_annulation` varchar(255) DEFAULT NULL,
  ADD COLUMN `source` varchar(20) DEFAULT NULL;
CREATE INDEX `idx_trajets_chauffeur_date` ON `trajets` (`chauffeur_id`, `date_trajet`);
CREATE INDEX `idx_trajets_date` ON `trajets` (`date_trajet`);

CREATE TABLE IF NOT EXISTS `trajet_passages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trajet_id` int(11) NOT NULL,
  `etape_id` int(11) NOT NULL,
  `heure_prevue` time DEFAULT NULL,
  `heure_reelle` datetime NOT NULL,
  `ecart_minutes` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_passage` (`trajet_id`, `etape_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `transport_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(30) NOT NULL,
  `ecole_id` int(11) DEFAULT NULL,
  `annee_scolaire_id` int(11) DEFAULT NULL,
  `eleve_id` int(11) DEFAULT NULL,
  `trajet_id` int(11) DEFAULT NULL,
  `circuit_id` int(11) DEFAULT NULL,
  `etape_id` int(11) DEFAULT NULL,
  `chauffeur_id` int(11) DEFAULT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `scan_id` int(11) DEFAULT NULL,
  `incident_id` int(11) DEFAULT NULL,
  `survenu_at` datetime NOT NULL,
  `heure_connue` tinyint(1) NOT NULL DEFAULT 1,
  `source` varchar(20) NOT NULL DEFAULT 'app',
  `cree_par` int(11) DEFAULT NULL,
  `statut` varchar(10) NOT NULL DEFAULT 'valide',
  `details` varchar(255) DEFAULT NULL,
  `import_lot_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_te_eleve` (`eleve_id`, `survenu_at`),
  KEY `idx_te_trajet` (`trajet_id`),
  KEY `idx_te_chauffeur` (`chauffeur_id`, `survenu_at`),
  KEY `idx_te_type` (`type`, `survenu_at`),
  KEY `idx_te_annee` (`annee_scolaire_id`, `ecole_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `notifications`
  ADD COLUMN `type` varchar(40) DEFAULT NULL,
  ADD COLUMN `eleve_id` int(11) DEFAULT NULL,
  ADD COLUMN `trajet_id` int(11) DEFAULT NULL,
  ADD COLUMN `etape_id` int(11) DEFAULT NULL,
  ADD COLUMN `chauffeur_id` int(11) DEFAULT NULL,
  ADD COLUMN `vehicle_id` int(11) DEFAULT NULL,
  ADD COLUMN `evenement_id` int(11) DEFAULT NULL,
  ADD COLUMN `incident_id` int(11) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `notification_recipients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `notification_id` int(11) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `envoye_at` datetime DEFAULT NULL,
  `lu_at` datetime DEFAULT NULL,
  `canal` varchar(20) NOT NULL DEFAULT 'app',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_notif_user` (`notification_id`, `user_id`),
  KEY `idx_nr_user` (`user_id`, `lu_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `parametres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `cle` varchar(60) NOT NULL,
  `valeur` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_param` (`ecole_id`, `cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT IGNORE INTO `parametres` (ecole_id, cle, valeur) VALUES (NULL, 'retard_tolerance_minutes', '10'), (NULL, 'alerte_expiration_jours', '30');

-- Nouveaux modules de droits
INSERT IGNORE INTO `permissions` (module_key, module_label, sort_order) VALUES
('chauffeurs', 'Chauffeurs', 200),
('vehicle_assignments', 'Affectations vehicules', 205),
('eleve_affectations', 'Affectations eleves (arrets)', 210),
('transport_events', 'Evenements transport', 215),
('incidents', 'Incidents et accidents', 220),
('chauffeur_documents', 'Documents chauffeur', 225),
('chauffeur_contracts', 'Contrats chauffeur (sensible)', 230),
('contract_templates', 'Modeles de contrat', 235),
('reporting', 'Reporting', 240),
('imports', 'Imports', 245);

-- Role RH (gestion des chauffeurs, seuls avec l'admin a voir les remunerations)
INSERT IGNORE INTO `roles` (role_key, role_label, sort_order) VALUES ('rh', 'RH / Gestion chauffeurs', 7);

-- Droits des roles sur les nouveaux modules
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'chauffeurs';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'chauffeurs';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'vehicle_assignments';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'vehicle_assignments';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'eleve_affectations';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'eleve_affectations';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'transport_events';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'transport_events';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'incidents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'incidents';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'chauffeur_documents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'chauffeur_documents';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'chauffeur_contracts';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'chauffeur_contracts';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'contract_templates';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'contract_templates';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'reporting';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'reporting';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'imports';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'imports';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'chauffeurs';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'chauffeurs';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'vehicle_assignments';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'vehicle_assignments';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'eleve_affectations';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'eleve_affectations';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'transport_events';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'transport_events';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'incidents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 1, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'incidents';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'chauffeur_documents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'chauffeur_documents';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'reporting';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 1, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'reporting';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'rh' AND p.module_key = 'chauffeurs';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 1, rp.scope = 'SCHOOL' WHERE r.role_key = 'rh' AND p.module_key = 'chauffeurs';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'rh' AND p.module_key = 'chauffeur_documents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 1, rp.scope = 'SCHOOL' WHERE r.role_key = 'rh' AND p.module_key = 'chauffeur_documents';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'rh' AND p.module_key = 'chauffeur_contracts';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 1, rp.scope = 'SCHOOL' WHERE r.role_key = 'rh' AND p.module_key = 'chauffeur_contracts';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'rh' AND p.module_key = 'contract_templates';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'rh' AND p.module_key = 'contract_templates';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'rh' AND p.module_key = 'vehicle_assignments';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'rh' AND p.module_key = 'vehicle_assignments';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'rh' AND p.module_key = 'incidents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'rh' AND p.module_key = 'incidents';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'rh' AND p.module_key = 'reporting';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 1, rp.scope = 'SCHOOL' WHERE r.role_key = 'rh' AND p.module_key = 'reporting';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'chauffeurs';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'chauffeurs';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'chauffeur_documents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'chauffeur_documents';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'chauffeur_contracts';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'chauffeur_contracts';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'vehicle_assignments';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'vehicle_assignments';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'eleve_affectations';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'eleve_affectations';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'transport_events';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'transport_events';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'incidents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'incidents';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key = 'transport_events';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key = 'transport_events';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key = 'eleve_affectations';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key = 'eleve_affectations';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'restaurant' AND p.module_key = 'incidents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'restaurant' AND p.module_key = 'incidents';

-- Reprise : une fiche chauffeur pour chaque compte ayant le role chauffeur
-- ou une affectation de circuit (aucune donnee existante modifiee).
INSERT INTO `chauffeurs` (user_id, ecole_id, nom, telephone, email, statut, source)
SELECT u.id, u.ecole_id, u.name, u.telephone, u.email, 'actif', 'reprise'
FROM `users` u
WHERE (EXISTS (SELECT 1 FROM `user_roles` ur JOIN `roles` r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.role_key = 'chauffeur')
    OR EXISTS (SELECT 1 FROM `affectations_chauffeur` ac WHERE ac.user_id = u.id))
  AND NOT EXISTS (SELECT 1 FROM `chauffeurs` c WHERE c.user_id = u.id);

-- Reprise : affectation transport de l'annee active pour les eleves ayant deja un circuit
INSERT INTO `eleve_affectations_transport` (ecole_id, annee_scolaire_id, eleve_id, circuit_id, statut, source)
SELECT e.ecole_id, e.annee_scolaire_id, e.id, e.circuit_id, 'active', 'reprise'
FROM `eleves` e
WHERE e.circuit_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `eleve_affectations_transport` a WHERE a.eleve_id = e.id AND a.statut = 'active');

-- Reprise : destinataires des notifications existantes (parents lies a l'eleve cible)
INSERT IGNORE INTO `notification_recipients` (notification_id, user_id, envoye_at, lu_at)
SELECT n.id, pl.user_id, n.created_at, CASE WHEN n.statut = 'lue' THEN n.created_at ELSE NULL END
FROM `notifications` n JOIN `parent_liaisons` pl ON n.cible = CONCAT('eleve:', pl.eleve_id);

