-- Retour arriere de la migration 005. Ne retire que ce qu'elle a ajoute.
-- ATTENTION : contacts, documents vehicule, retenues, echeances, tarifs et lots
-- d'import saisis apres la migration seraient perdus : exporter ces tables avant.
-- La date de debut des contrats reste facultative (contrainte elargie, sans perte).
DELETE rp FROM `role_permissions` rp JOIN `permissions` p ON p.id = rp.permission_id WHERE p.module_key IN ('vehicle_documents', 'eleve_contacts', 'echeances_transport', 'tarifs');
DELETE up FROM `user_permissions` up JOIN `permissions` p ON p.id = up.permission_id WHERE p.module_key IN ('vehicle_documents', 'eleve_contacts', 'echeances_transport', 'tarifs');
DELETE FROM `permissions` WHERE module_key IN ('vehicle_documents', 'eleve_contacts', 'echeances_transport', 'tarifs');
DELETE FROM `parametres` WHERE ecole_id IS NULL AND cle IN ('annee_mois_debut', 'annee_mois_fin');
DROP TABLE `import_correspondances`;
DROP TABLE `import_lignes`;
DROP TABLE `import_lots`;
DROP TABLE `echeances_historique`;
DROP TABLE `echeances_transport`;
ALTER TABLE `abonnements` DROP COLUMN `zone_tarifaire`, DROP COLUMN `tarif_id`, DROP COLUMN `montant_mensuel`, DROP COLUMN `periodicite`, DROP COLUMN `source`, DROP COLUMN `import_lot_id`;
DROP TABLE `tarifs`;
DROP TABLE `chauffeur_retenues`;
ALTER TABLE `circuits` DROP COLUMN `type_circuit`, DROP COLUMN `activite`, DROP COLUMN `destination`, DROP COLUMN `jours_semaine`, DROP COLUMN `heure_depart`, DROP COLUMN `heure_retour`;
DROP TABLE `vehicle_documents`;
ALTER TABLE `vehicules` DROP COLUMN `marque`, DROP COLUMN `annee`, DROP COLUMN `type_vehicule`, DROP COLUMN `proprietaire`, DROP COLUMN `source`;
ALTER TABLE `eleves` DROP COLUMN `source`, DROP COLUMN `import_lot_id`, DROP COLUMN `campus`;
DROP TABLE `eleve_contacts`;
