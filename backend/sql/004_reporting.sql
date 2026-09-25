-- =====================================================================
-- Migration 004 — Reporting (SHIPP School V5, lot 4 : T5-16, T5-19, T5-20)
-- Strictement additive. Prerequis : migrations 001 a 003.
-- Retour arriere : 004_reporting_down.sql
-- =====================================================================

-- Journal des exports (qui a exporte quoi, avec quels filtres).
CREATE TABLE IF NOT EXISTS `export_journal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `ecole_id` int(11) DEFAULT NULL,
  `rapport` varchar(50) NOT NULL,
  `format` varchar(10) NOT NULL,
  `filtres` text DEFAULT NULL,
  `nb_lignes` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_export_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Index utile aux agregations par periode (idx_scans_date existe deja, migration 001).
CREATE INDEX `idx_passages_trajet` ON `trajet_passages` (`trajet_id`);

INSERT IGNORE INTO `parametres` (ecole_id, cle, valeur) VALUES (NULL, 'reporting_periode_max_jours', '400');
