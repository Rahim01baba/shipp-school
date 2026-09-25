-- =====================================================================
-- Migration 005 — Sources ENKO : contacts parents, vehicules et leurs
-- documents, navettes d'activite, retenues chauffeur, suivi mensuel des
-- paiements (Enko / Shipp), tarifs par zone, imports tracés.
-- SHIPP School V5, lot 5 (decisions D-25 a D-30 du 25/09/2026).
-- Strictement additive, sauf une contrainte ELARGIE : la date de debut d'un
-- contrat brouillon devient facultative (D-27 : champs remplis au besoin).
-- Prerequis : migrations 001 a 004. Retour arriere : 005_enko_flotte_paiements_down.sql
-- =====================================================================

-- ---------- Contacts des parents (D : « il faut rajouter le champ ») ----------
CREATE TABLE IF NOT EXISTS `eleve_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `eleve_id` int(11) NOT NULL,
  `nom` varchar(150) DEFAULT NULL,
  `lien` enum('pere','mere','tuteur','autre') NOT NULL DEFAULT 'autre',
  `telephone` varchar(30) DEFAULT NULL,
  `telephone2` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `principal` tinyint(1) NOT NULL DEFAULT 0,
  `user_id` int(11) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'saisie',
  `import_lot_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ec_eleve` (`eleve_id`),
  KEY `idx_ec_tel` (`telephone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Tracabilite des eleves importes ----------
ALTER TABLE `eleves`
  ADD COLUMN `source` varchar(20) DEFAULT NULL,
  ADD COLUMN `import_lot_id` int(11) DEFAULT NULL,
  ADD COLUMN `campus` varchar(60) DEFAULT NULL;

-- ---------- Vehicules : identification complete (immatriculation deja presente) ----------
ALTER TABLE `vehicules`
  ADD COLUMN `marque` varchar(60) DEFAULT NULL,
  ADD COLUMN `annee` smallint(6) DEFAULT NULL,
  ADD COLUMN `type_vehicule` varchar(30) DEFAULT NULL,
  ADD COLUMN `proprietaire` varchar(150) DEFAULT NULL,
  ADD COLUMN `source` varchar(20) DEFAULT NULL;

-- ---------- Documents du vehicule (D-29) ----------
CREATE TABLE IF NOT EXISTS `vehicle_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `vehicle_id` int(11) NOT NULL,
  `type` varchar(30) NOT NULL,
  `numero` varchar(100) DEFAULT NULL,
  `organisme` varchar(150) DEFAULT NULL,
  `date_debut` date DEFAULT NULL,
  `date_expiration` date DEFAULT NULL,
  `fichier_id` int(11) DEFAULT NULL,
  `statut` enum('a_verifier','valide','refuse','remplace') NOT NULL DEFAULT 'a_verifier',
  `verifie_par` int(11) DEFAULT NULL,
  `verifie_at` datetime DEFAULT NULL,
  `remplace_par_id` int(11) DEFAULT NULL,
  `commentaire` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vd_vehicle` (`vehicle_id`, `type`),
  KEY `idx_vd_expiration` (`date_expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Navettes d'activite (D-28) ----------
ALTER TABLE `circuits`
  ADD COLUMN `type_circuit` varchar(20) NOT NULL DEFAULT 'domicile',
  ADD COLUMN `activite` varchar(60) DEFAULT NULL,
  ADD COLUMN `destination` varchar(150) DEFAULT NULL,
  ADD COLUMN `jours_semaine` varchar(20) DEFAULT NULL,
  ADD COLUMN `heure_depart` time DEFAULT NULL,
  ADD COLUMN `heure_retour` time DEFAULT NULL;

-- ---------- Contrats : champs remplis au besoin (D-27) ----------
ALTER TABLE `chauffeur_contracts` MODIFY `date_debut` date DEFAULT NULL;

-- ---------- Retenues chiffrees sur la remuneration (D-30) ----------
CREATE TABLE IF NOT EXISTS `chauffeur_retenues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `chauffeur_id` int(11) NOT NULL,
  `contract_id` int(11) DEFAULT NULL,
  `incident_id` int(11) DEFAULT NULL,
  `periode` date NOT NULL,
  `date_fait` date DEFAULT NULL,
  `motif` varchar(255) NOT NULL,
  `montant` decimal(12,2) NOT NULL,
  `devise` varchar(3) NOT NULL DEFAULT 'XOF',
  `statut` enum('proposee','validee','annulee') NOT NULL DEFAULT 'proposee',
  `created_by` int(11) DEFAULT NULL,
  `validee_par` int(11) DEFAULT NULL,
  `validee_at` datetime DEFAULT NULL,
  `motif_annulation` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cr_chauffeur` (`chauffeur_id`, `periode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Tarifs transport par zone et par annee ----------
CREATE TABLE IF NOT EXISTS `tarifs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `annee_scolaire_id` int(11) NOT NULL,
  `service` varchar(20) NOT NULL DEFAULT 'transport',
  `zone` varchar(60) NOT NULL,
  `montant_mensuel` decimal(12,2) NOT NULL,
  `devise` varchar(3) NOT NULL DEFAULT 'XOF',
  `source` varchar(20) NOT NULL DEFAULT 'saisie',
  `import_lot_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tarif` (`ecole_id`, `annee_scolaire_id`, `service`, `zone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `abonnements`
  ADD COLUMN `zone_tarifaire` varchar(60) DEFAULT NULL,
  ADD COLUMN `tarif_id` int(11) DEFAULT NULL,
  ADD COLUMN `montant_mensuel` decimal(12,2) DEFAULT NULL,
  ADD COLUMN `periodicite` varchar(20) DEFAULT NULL,
  ADD COLUMN `source` varchar(20) DEFAULT NULL,
  ADD COLUMN `import_lot_id` int(11) DEFAULT NULL;

-- ---------- Suivi mensuel des paiements transport (D-25) ----------
-- Un mois par eleve : montant mensuel ; « Enko » = l'etablissement a encaisse
-- (les parents reglent souvent l'annee entiere a Enko) ; « Shipp » = Shipp a
-- recu le mois ; ni l'un ni l'autre = « Non ». « Arret S/c » = service arrete.
CREATE TABLE IF NOT EXISTS `echeances_transport` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `annee_scolaire_id` int(11) DEFAULT NULL,
  `eleve_id` int(11) NOT NULL,
  `abonnement_id` int(11) DEFAULT NULL,
  `mois` date NOT NULL,
  `montant` decimal(12,2) DEFAULT NULL,
  `encaisse_enko` tinyint(1) NOT NULL DEFAULT 0,
  `enko_at` date DEFAULT NULL,
  `recu_shipp` tinyint(1) NOT NULL DEFAULT 0,
  `shipp_at` date DEFAULT NULL,
  `arret_service` tinyint(1) NOT NULL DEFAULT 0,
  `commentaire` varchar(255) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'saisie',
  `import_lot_id` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_echeance` (`eleve_id`, `mois`),
  KEY `idx_et_annee` (`annee_scolaire_id`, `mois`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `echeances_historique` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `echeance_id` int(11) NOT NULL,
  `champ` varchar(30) NOT NULL,
  `ancienne_valeur` varchar(60) DEFAULT NULL,
  `nouvelle_valeur` varchar(60) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_eh_echeance` (`echeance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Imports tracés (lots, lignes, correspondances) ----------
CREATE TABLE IF NOT EXISTS `import_lots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'enko_suivi',
  `nom_fichier` varchar(255) NOT NULL,
  `chemin` varchar(255) DEFAULT NULL,
  `sha256` char(64) NOT NULL,
  `feuille` varchar(100) DEFAULT NULL,
  `annee_scolaire_id` int(11) DEFAULT NULL,
  `options` text DEFAULT NULL,
  `stats` text DEFAULT NULL,
  `statut` enum('analyse','valide','annule') NOT NULL DEFAULT 'analyse',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `valide_par` int(11) DEFAULT NULL,
  `valide_at` datetime DEFAULT NULL,
  `annule_par` int(11) DEFAULT NULL,
  `annule_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_il_sha` (`sha256`, `feuille`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `import_lignes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lot_id` int(11) NOT NULL,
  `numero_ligne` int(11) NOT NULL,
  `donnees` text NOT NULL,
  `statut` enum('ok','avertissement','erreur','insuffisant') NOT NULL DEFAULT 'ok',
  `messages` text DEFAULT NULL,
  `eleve_id` int(11) DEFAULT NULL,
  `crees` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ili_lot` (`lot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `import_correspondances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `type` varchar(20) NOT NULL,
  `valeur_source` varchar(150) NOT NULL,
  `cible_id` int(11) DEFAULT NULL,
  `cible_valeur` varchar(150) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_corresp` (`ecole_id`, `type`, `valeur_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Nouveaux modules de droits ----------
INSERT IGNORE INTO `permissions` (module_key, module_label, sort_order) VALUES
('vehicle_documents', 'Documents vehicule', 250),
('eleve_contacts', 'Contacts parents', 255),
('echeances_transport', 'Suivi mensuel des paiements transport', 260),
('tarifs', 'Tarifs', 265);

INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'admin' AND p.module_key IN ('vehicle_documents', 'eleve_contacts', 'echeances_transport', 'tarifs');
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 1, rp.can_validate = 1, rp.can_export = 1, rp.scope = 'GLOBAL' WHERE r.role_key = 'admin' AND p.module_key IN ('vehicle_documents', 'eleve_contacts', 'echeances_transport', 'tarifs');
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'fleet_manager' AND p.module_key IN ('vehicle_documents', 'eleve_contacts');
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 1, rp.can_edit = 1, rp.can_delete = 0, rp.can_validate = 1, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'vehicle_documents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'fleet_manager' AND p.module_key = 'eleve_contacts';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'rh' AND p.module_key = 'vehicle_documents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'rh' AND p.module_key = 'vehicle_documents';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'chauffeur' AND p.module_key = 'vehicle_documents';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'ASSIGNED_ROUTE' WHERE r.role_key = 'chauffeur' AND p.module_key = 'vehicle_documents';
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'parent' AND p.module_key IN ('eleve_contacts', 'echeances_transport');
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'CHILDREN' WHERE r.role_key = 'parent' AND p.module_key IN ('eleve_contacts', 'echeances_transport');

INSERT IGNORE INTO `parametres` (ecole_id, cle, valeur) VALUES (NULL, 'annee_mois_debut', '9'), (NULL, 'annee_mois_fin', '6');
