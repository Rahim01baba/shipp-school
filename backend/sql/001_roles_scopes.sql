-- =====================================================================
-- Migration 001 — Roles + scopes, telephone de connexion, index
-- SHIPP School V5, ticket T5-02. Strictement additive :
--   * aucune table ni colonne supprimee ;
--   * role_permissions (jamais appliquee jusqu'ici) est sauvegardee dans
--     role_permissions_backup_20260925 avant mise a jour ;
--   * users.email devient facultatif (elargissement, sans perte).
-- Retour arriere : 001_roles_scopes_down.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS `role_permissions_backup_20260925` AS SELECT * FROM `role_permissions`;

ALTER TABLE `role_permissions`
  ADD COLUMN `can_validate` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `can_export` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `scope` varchar(20) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `role_id` int(11) NOT NULL,
  `ecole_id` int(11) DEFAULT NULL,
  `scope` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_role_ecole` (`user_id`,`role_id`,`ecole_id`),
  KEY `idx_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `users`
  ADD COLUMN `telephone` varchar(30) DEFAULT NULL,
  ADD UNIQUE KEY `uniq_users_telephone` (`telephone`),
  MODIFY `email` varchar(150) DEFAULT NULL;

-- Index utiles aux filtres de perimetre et d'annee
CREATE INDEX `idx_scans_eleve` ON `scans` (`eleve_id`);
CREATE INDEX `idx_scans_trajet` ON `scans` (`trajet_id`);
CREATE INDEX `idx_scans_date` ON `scans` (`scanned_at`);
CREATE INDEX `idx_trajets_circuit_date` ON `trajets` (`circuit_id`, `date_trajet`);
CREATE INDEX `idx_eleves_circuit` ON `eleves` (`circuit_id`);
CREATE INDEX `idx_parent_liaisons_eleve` ON `parent_liaisons` (`eleve_id`);
CREATE INDEX `idx_notifications_cible` ON `notifications` (`cible`);
CREATE INDEX `idx_finance_eleve` ON `finance` (`eleve_id`);
CREATE INDEX `idx_affectations_user` ON `affectations_chauffeur` (`user_id`);

-- Droits cibles par role (portee = scope du role)
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id SET rp.can_read = 0, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'eleves';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'eleves';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'annees_scolaires';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'annees_scolaires';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'parents_eleves';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'parents_eleves';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'transport';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'transport';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'cantine';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'cantine';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'vehicules';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'vehicules';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'circuits';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'circuits';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'finance';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'finance';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'utilisateurs';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'utilisateurs';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'menus';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'menus';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'notifications';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'notifications';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'ecoles';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'ecoles';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'rapports';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'rapports';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'abonnements';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'abonnements';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'etapes';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'etapes';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'trajets';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'trajets';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'scans';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'scans';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'affectations_chauffeur';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'affectations_chauffeur';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'couvertures_chauffeur';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'couvertures_chauffeur';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'parent_liaisons';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'parent_liaisons';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'ecole_modules';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'ecole_modules';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key = 'journal_activite';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key = 'journal_activite';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id SET rp.can_read = 0, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key = 'eleves';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key = 'eleves';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key = 'abonnements';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key = 'abonnements';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key = 'scans';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key = 'scans';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key = 'finance';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key = 'finance';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key = 'notifications';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key = 'notifications';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key = 'trajets';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key = 'trajets';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key = 'etapes';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key = 'etapes';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key = 'circuits';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key = 'circuits';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key = 'menus';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key = 'menus';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key = 'parent_liaisons';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key = 'parent_liaisons';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id SET rp.can_read = 0, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'eleves';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'eleves';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'abonnements';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'abonnements';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'circuits';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'circuits';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'etapes';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'etapes';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'trajets';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'trajets';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'scans';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'scans';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'vehicules';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'vehicules';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'affectations_chauffeur';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'affectations_chauffeur';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'couvertures_chauffeur';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'couvertures_chauffeur';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'menus';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'menus';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id SET rp.can_read = 0, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'restaurant';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'restaurant' AND p.module_key = 'eleves';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'restaurant' AND p.module_key = 'eleves';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'restaurant' AND p.module_key = 'abonnements';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'restaurant' AND p.module_key = 'abonnements';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'restaurant' AND p.module_key = 'menus';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'restaurant' AND p.module_key = 'menus';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'restaurant' AND p.module_key = 'cantine';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'restaurant' AND p.module_key = 'cantine';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'restaurant' AND p.module_key = 'scans';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'restaurant' AND p.module_key = 'scans';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id SET rp.can_read = 0, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'eleves';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'eleves';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'vehicules';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'vehicules';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'circuits';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'circuits';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'etapes';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'etapes';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'trajets';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'trajets';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'scans';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'scans';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'affectations_chauffeur';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'affectations_chauffeur';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'couvertures_chauffeur';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'couvertures_chauffeur';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'transport';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'transport';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key = 'journal_activite';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'journal_activite';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id SET rp.can_read = 0, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'NONE' WHERE r.role_key = 'eleves';

-- Attribution des roles aux comptes existants (comptes de test actuels)
INSERT IGNORE INTO `user_roles` (user_id, role_id, ecole_id)
SELECT u.id, r.id, u.ecole_id FROM `users` u JOIN `roles` r ON r.role_key = 'admin' WHERE u.is_admin = 1;
INSERT IGNORE INTO `user_roles` (user_id, role_id, ecole_id)
SELECT u.id, r.id, u.ecole_id FROM `users` u JOIN `roles` r ON r.role_key = 'parent' WHERE u.email = 'parent@shipp-group.com';
INSERT IGNORE INTO `user_roles` (user_id, role_id, ecole_id)
SELECT u.id, r.id, u.ecole_id FROM `users` u JOIN `roles` r ON r.role_key = 'chauffeur' WHERE u.email = 'chauffeur@shipp-group.com';
INSERT IGNORE INTO `user_roles` (user_id, role_id, ecole_id)
SELECT u.id, r.id, u.ecole_id FROM `users` u JOIN `roles` r ON r.role_key = 'restaurant' WHERE u.email = 'restaurant@shipp-group.com';
INSERT IGNORE INTO `user_roles` (user_id, role_id, ecole_id)
SELECT u.id, r.id, u.ecole_id FROM `users` u JOIN `roles` r ON r.role_key = 'fleet_manager' WHERE u.email = 'fleet@shipp-group.com';
-- Tout autre compte parent existant (lie a un enfant, sans role) recoit le role parent.
INSERT IGNORE INTO `user_roles` (user_id, role_id, ecole_id)
SELECT DISTINCT pl.user_id, r.id, pl.ecole_id FROM `parent_liaisons` pl JOIN `roles` r ON r.role_key = 'parent'
WHERE NOT EXISTS (SELECT 1 FROM `user_roles` ur WHERE ur.user_id = pl.user_id);

