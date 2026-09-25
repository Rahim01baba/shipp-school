-- Retour arriere de la migration 004 (le journal des exports serait perdu : l'exporter avant).
DELETE FROM `parametres` WHERE ecole_id IS NULL AND cle = 'reporting_periode_max_jours';
DROP INDEX `idx_passages_trajet` ON `trajet_passages`;
DROP TABLE `export_journal`;
