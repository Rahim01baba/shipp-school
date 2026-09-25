-- Retour arriere de la migration 003. Ne retire que ce qu'elle a ajoute.
-- ATTENTION : les incidents qualifies, documents, contrats et fichiers saisis
-- apres la migration seraient perdus. Exporter ces tables avant execution.
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_create = 0 WHERE r.role_key = 'chauffeur' AND p.module_key = 'chauffeur_documents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_validate = 0 WHERE r.role_key = 'fleet_manager' AND p.module_key IN ('chauffeur_documents', 'incidents');
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_validate = 0 WHERE r.role_key = 'rh' AND p.module_key IN ('chauffeur_documents', 'chauffeur_contracts');
DELETE rp FROM `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id WHERE r.role_key = 'rh' AND p.module_key = 'vehicules';
DELETE FROM `parametres` WHERE ecole_id IS NULL AND cle IN ('fichier_taille_max_mo', 'incident_qualification_jours');
DROP TABLE `chauffeur_contracts`;
DROP TABLE `contract_templates`;
DROP TABLE `chauffeur_documents`;
DROP TABLE `fichiers`;
DROP TABLE `accident_details`;
DROP TABLE `incident_actions`;
DROP TABLE `incident_eleves`;
DROP INDEX `idx_incidents_trajet` ON `incidents`;
DROP INDEX `idx_incidents_chauffeur` ON `incidents`;
DROP INDEX `idx_incidents_date` ON `incidents`;
ALTER TABLE `incidents` DROP COLUMN `categorie`, DROP COLUMN `trajet_id`, DROP COLUMN `survenu_at`, DROP COLUMN `lieu`, DROP COLUMN `latitude`, DROP COLUMN `longitude`, DROP COLUMN `mesures_immediates`, DROP COLUMN `responsabilite`, DROP COLUMN `responsabilite_commentaire`, DROP COLUMN `qualifiee_par`, DROP COLUMN `qualifiee_at`, DROP COLUMN `action_corrective`, DROP COLUMN `cout_estime`, DROP COLUMN `cout_reel`, DROP COLUMN `devise`, DROP COLUMN `date_cloture`, DROP COLUMN `cloture_par`, DROP COLUMN `declare_par`, DROP COLUMN `proprietaire_informe_at`, DROP COLUMN `source`, DROP COLUMN `import_lot_id`, DROP COLUMN `updated_at`;
