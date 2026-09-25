-- Retour arriere de la migration 001 (a executer dans cet ordre).
-- Ne supprime que ce que la migration 001 a ajoute.
DROP INDEX `idx_scans_eleve` ON `scans`;
DROP INDEX `idx_scans_trajet` ON `scans`;
DROP INDEX `idx_scans_date` ON `scans`;
DROP INDEX `idx_trajets_circuit_date` ON `trajets`;
DROP INDEX `idx_eleves_circuit` ON `eleves`;
DROP INDEX `idx_parent_liaisons_eleve` ON `parent_liaisons`;
DROP INDEX `idx_notifications_cible` ON `notifications`;
DROP INDEX `idx_finance_eleve` ON `finance`;
DROP INDEX `idx_affectations_user` ON `affectations_chauffeur`;
-- users.email : ne revenir a NOT NULL que si aucun compte sans e-mail n'a ete cree.
ALTER TABLE `users` DROP INDEX `uniq_users_telephone`, DROP COLUMN `telephone`;
ALTER TABLE `users` MODIFY `email` varchar(150) NOT NULL;
DROP TABLE `user_roles`;
-- Restauration des valeurs d'origine de role_permissions
DELETE FROM `role_permissions`;
ALTER TABLE `role_permissions` DROP COLUMN `scope`, DROP COLUMN `can_export`, DROP COLUMN `can_validate`;
INSERT INTO `role_permissions` SELECT * FROM `role_permissions_backup_20260925`;
DROP TABLE `role_permissions_backup_20260925`;
