-- =====================================================================
-- Migration 007 — Inscription autonome des parents et liaison par code enfant
-- SHIPP School V5. Strictement additive : aucune table, colonne ou donnee
-- existante n'est supprimee ni modifiee.
-- Prerequis : migrations 001 a 006. Retour arriere : 007_inscription_parents_down.sql
--
-- Principe : le parent cree lui-meme son compte (role parent, aucun enfant),
-- puis saisit le CODE PARENT de chaque enfant, remis individuellement a la
-- famille. Le code est aleatoire (jamais l'identifiant de l'eleve) : il ne se
-- devine pas et peut etre regenere par l'administration.
-- =====================================================================

-- 1. Code parent de chaque eleve (genere par l'administration, ecran « Codes parents »).
ALTER TABLE `eleves`
  ADD COLUMN `code_parent` varchar(20) DEFAULT NULL,
  ADD UNIQUE KEY `uniq_eleves_code_parent` (`code_parent`);

-- 2. Origine de la liaison parent <-> eleve : 'admin' (existant) ou 'code' (saisie du parent).
ALTER TABLE `parent_liaisons`
  ADD COLUMN `source` varchar(20) NOT NULL DEFAULT 'admin';

-- 3. Tentatives d'inscription et de saisie de code (limitation anti-abus).
CREATE TABLE IF NOT EXISTS `securite_tentatives` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `type` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `succes` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_st_user` (`type`, `user_id`, `created_at`),
  KEY `idx_st_ip` (`type`, `ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Parametres (modifiables sans deploiement).
INSERT IGNORE INTO `parametres` (ecole_id, cle, valeur) VALUES
  (NULL, 'parent_inscription_ouverte', '1'),
  (NULL, 'parent_max_comptes_par_eleve', '2');
