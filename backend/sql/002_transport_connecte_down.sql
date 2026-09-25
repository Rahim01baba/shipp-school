-- Retour arriere de la migration 002. Ne retire que ce qu'elle a ajoute.
DELETE rp FROM `role_permissions` rp JOIN `permissions` p ON p.id = rp.permission_id
 WHERE p.module_key IN ('chauffeurs','vehicle_assignments','eleve_affectations','transport_events','incidents','chauffeur_documents','chauffeur_contracts','contract_templates','reporting','imports');
DELETE up FROM `user_permissions` up JOIN `permissions` p ON p.id = up.permission_id
 WHERE p.module_key IN ('chauffeurs','vehicle_assignments','eleve_affectations','transport_events','incidents','chauffeur_documents','chauffeur_contracts','contract_templates','reporting','imports');
DELETE FROM `permissions` WHERE module_key IN ('chauffeurs','vehicle_assignments','eleve_affectations','transport_events','incidents','chauffeur_documents','chauffeur_contracts','contract_templates','reporting','imports');
DELETE ur FROM `user_roles` ur JOIN `roles` r ON r.id = ur.role_id WHERE r.role_key = 'rh';
DELETE rp FROM `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id WHERE r.role_key = 'rh';
DELETE FROM `roles` WHERE role_key = 'rh';
DROP TABLE `parametres`;
DROP TABLE `notification_recipients`;
ALTER TABLE `notifications` DROP COLUMN `type`, DROP COLUMN `eleve_id`, DROP COLUMN `trajet_id`, DROP COLUMN `etape_id`, DROP COLUMN `chauffeur_id`, DROP COLUMN `vehicle_id`, DROP COLUMN `evenement_id`, DROP COLUMN `incident_id`;
DROP TABLE `transport_events`;
DROP TABLE `trajet_passages`;
DROP INDEX `idx_trajets_chauffeur_date` ON `trajets`;
DROP INDEX `idx_trajets_date` ON `trajets`;
ALTER TABLE `trajets` DROP COLUMN `chauffeur_id`, DROP COLUMN `vehicle_id`, DROP COLUMN `sens`, DROP COLUMN `motif_annulation`, DROP COLUMN `source`;
DROP TABLE `eleve_affectations_transport`;
DROP TABLE `vehicle_assignments`;
DROP TABLE `chauffeurs`;
