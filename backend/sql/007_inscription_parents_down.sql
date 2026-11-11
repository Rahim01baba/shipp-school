-- Retour arriere de la migration 007. Ne retire que ce qu'elle a ajoute.
-- Les comptes parents crees par inscription et leurs liaisons sont CONSERVES
-- (ce sont des donnees) ; seule l'origine 'code' de la liaison est perdue.
DELETE FROM `parametres` WHERE ecole_id IS NULL AND cle IN ('parent_inscription_ouverte', 'parent_max_comptes_par_eleve');
DROP TABLE IF EXISTS `securite_tentatives`;
ALTER TABLE `parent_liaisons` DROP COLUMN `source`;
ALTER TABLE `eleves` DROP INDEX `uniq_eleves_code_parent`, DROP COLUMN `code_parent`;
