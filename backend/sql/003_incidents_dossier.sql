-- =====================================================================
-- Migration 003 — Incidents / accidents, dossier chauffeur, contrats
-- SHIPP School V5, lot 3 (T5-12 a T5-15). Strictement additive :
-- aucune table, colonne ou ligne existante n'est supprimee ni reecrite
-- (les incidents existants recoivent seulement source = 'reprise').
-- Prerequis : migrations 001 et 002. Retour arriere : 003_incidents_dossier_down.sql
--
-- Regles metier portees par le schema :
--  * incident et accident sont distingues (categorie) ;
--  * la responsabilite vaut 'non_determinee' par defaut et n'est JAMAIS
--    deduite automatiquement : seule une qualification humaine la change ;
--  * le contrat chauffeur est un contrat d'UTILISATION DE VEHICULE (pas un
--    contrat de travail) ; aucun montant n'est impose par le schema ;
--  * les fichiers sont stockes hors de la racine web (chemin relatif au
--    dossier prive configure) et ne sont jamais supprimes physiquement.
-- =====================================================================

-- ---------- Incidents : colonnes additionnelles ----------
ALTER TABLE `incidents`
  ADD COLUMN `categorie` enum('incident','accident') NOT NULL DEFAULT 'incident',
  ADD COLUMN `trajet_id` int(11) DEFAULT NULL,
  ADD COLUMN `survenu_at` datetime DEFAULT NULL,
  ADD COLUMN `lieu` varchar(255) DEFAULT NULL,
  ADD COLUMN `latitude` decimal(10,7) DEFAULT NULL,
  ADD COLUMN `longitude` decimal(10,7) DEFAULT NULL,
  ADD COLUMN `mesures_immediates` text DEFAULT NULL,
  ADD COLUMN `responsabilite` enum('non_determinee','chauffeur','tiers','partagee','eleve','autre') NOT NULL DEFAULT 'non_determinee',
  ADD COLUMN `responsabilite_commentaire` text DEFAULT NULL,
  ADD COLUMN `qualifiee_par` int(11) DEFAULT NULL,
  ADD COLUMN `qualifiee_at` datetime DEFAULT NULL,
  ADD COLUMN `action_corrective` text DEFAULT NULL,
  ADD COLUMN `cout_estime` decimal(12,2) DEFAULT NULL,
  ADD COLUMN `cout_reel` decimal(12,2) DEFAULT NULL,
  ADD COLUMN `devise` varchar(3) NOT NULL DEFAULT 'XOF',
  ADD COLUMN `date_cloture` datetime DEFAULT NULL,
  ADD COLUMN `cloture_par` int(11) DEFAULT NULL,
  ADD COLUMN `declare_par` int(11) DEFAULT NULL,
  ADD COLUMN `proprietaire_informe_at` datetime DEFAULT NULL,
  ADD COLUMN `source` varchar(20) NOT NULL DEFAULT 'saisie',
  ADD COLUMN `import_lot_id` int(11) DEFAULT NULL,
  ADD COLUMN `updated_at` timestamp NULL DEFAULT NULL;

CREATE INDEX `idx_incidents_date` ON `incidents` (`date_incident`);
CREATE INDEX `idx_incidents_chauffeur` ON `incidents` (`chauffeur_id`);
CREATE INDEX `idx_incidents_trajet` ON `incidents` (`trajet_id`);

-- Les incidents deja presents sont identifies comme repris (aucune autre valeur touchee).
UPDATE `incidents` SET `source` = 'reprise' WHERE `source` = 'saisie' AND `created_at` < NOW();

CREATE TABLE IF NOT EXISTS `incident_eleves` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `incident_id` int(11) NOT NULL,
  `eleve_id` int(11) NOT NULL,
  `role` enum('implique','blesse','temoin') NOT NULL DEFAULT 'implique',
  `blessure` varchar(255) DEFAULT NULL,
  `parents_notifies_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_incident_eleve` (`incident_id`, `eleve_id`),
  KEY `idx_ie_eleve` (`eleve_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `incident_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `incident_id` int(11) NOT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'corrective',
  `description` text NOT NULL,
  `responsable_user_id` int(11) DEFAULT NULL,
  `echeance` date DEFAULT NULL,
  `statut` enum('a_faire','en_cours','faite','abandonnee') NOT NULL DEFAULT 'a_faire',
  `fait_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ia_incident` (`incident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `accident_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `incident_id` int(11) NOT NULL,
  `tiers_implique` tinyint(1) NOT NULL DEFAULT 0,
  `tiers_nom` varchar(150) DEFAULT NULL,
  `tiers_telephone` varchar(30) DEFAULT NULL,
  `tiers_immatriculation` varchar(30) DEFAULT NULL,
  `tiers_assurance` varchar(150) DEFAULT NULL,
  `constat_amiable` tinyint(1) NOT NULL DEFAULT 0,
  `rapport_police` tinyint(1) NOT NULL DEFAULT 0,
  `reference_police` varchar(100) DEFAULT NULL,
  `blesses` tinyint(1) NOT NULL DEFAULT 0,
  `nb_blesses` int(11) DEFAULT NULL,
  `degats_vehicule` text DEFAULT NULL,
  `vehicule_immobilise` tinyint(1) NOT NULL DEFAULT 0,
  `assurance_declaree_at` date DEFAULT NULL,
  `reference_sinistre` varchar(100) DEFAULT NULL,
  `franchise` decimal(12,2) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_accident_incident` (`incident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Fichiers prives (hors racine web) ----------
CREATE TABLE IF NOT EXISTS `fichiers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `entite` varchar(40) NOT NULL,
  `entite_id` int(11) NOT NULL,
  `categorie` varchar(40) DEFAULT NULL,
  `nom_original` varchar(255) NOT NULL,
  `chemin` varchar(255) NOT NULL,
  `mime` varchar(100) NOT NULL,
  `taille` int(11) NOT NULL,
  `sha256` char(64) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `retire_at` datetime DEFAULT NULL,
  `retire_par` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fichiers_entite` (`entite`, `entite_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Documents chauffeur (permis, piece d'identite, ...) ----------
CREATE TABLE IF NOT EXISTS `chauffeur_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `chauffeur_id` int(11) NOT NULL,
  `type` varchar(30) NOT NULL,
  `numero` varchar(100) DEFAULT NULL,
  `categorie_permis` varchar(30) DEFAULT NULL,
  `date_delivrance` date DEFAULT NULL,
  `date_expiration` date DEFAULT NULL,
  `autorite` varchar(150) DEFAULT NULL,
  `fichier_id` int(11) DEFAULT NULL,
  `statut` enum('a_verifier','valide','refuse','remplace') NOT NULL DEFAULT 'a_verifier',
  `verifie_par` int(11) DEFAULT NULL,
  `verifie_at` datetime DEFAULT NULL,
  `remplace_par_id` int(11) DEFAULT NULL,
  `commentaire` varchar(255) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'saisie',
  `import_lot_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cd_chauffeur` (`chauffeur_id`, `type`),
  KEY `idx_cd_expiration` (`date_expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Modeles de contrat (versionnes, jamais ecrases) ----------
CREATE TABLE IF NOT EXISTS `contract_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `code` varchar(50) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `titre` varchar(200) NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'utilisation_vehicule',
  `contenu` mediumtext DEFAULT NULL,
  `valeurs_defaut` text DEFAULT NULL,
  `source_document` varchar(255) DEFAULT NULL,
  `statut` enum('brouillon','actif','archive') NOT NULL DEFAULT 'brouillon',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_template_version` (`code`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Contrats d'utilisation de vehicule par chauffeur ----------
CREATE TABLE IF NOT EXISTS `chauffeur_contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ecole_id` int(11) DEFAULT NULL,
  `chauffeur_id` int(11) NOT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'utilisation_vehicule',
  `reference` varchar(60) DEFAULT NULL,
  `template_id` int(11) DEFAULT NULL,
  `template_version` int(11) DEFAULT NULL,
  `contenu_genere` mediumtext DEFAULT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `duree_mois` int(11) DEFAULT NULL,
  `remuneration_montant` decimal(12,2) DEFAULT NULL,
  `remuneration_devise` varchar(3) NOT NULL DEFAULT 'XOF',
  `remuneration_periodicite` varchar(20) DEFAULT NULL,
  `preavis_jours` int(11) DEFAULT NULL,
  `penalites` text DEFAULT NULL,
  `conditions_particulieres` text DEFAULT NULL,
  `statut` enum('brouillon','actif','suspendu','termine','resilie') NOT NULL DEFAULT 'brouillon',
  `signe_le` date DEFAULT NULL,
  `fichier_signe_id` int(11) DEFAULT NULL,
  `resilie_le` date DEFAULT NULL,
  `motif_resiliation` varchar(255) DEFAULT NULL,
  `resilie_par` int(11) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'saisie',
  `import_lot_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_contract_reference` (`reference`),
  KEY `idx_cc_chauffeur` (`chauffeur_id`, `date_debut`),
  KEY `idx_cc_fin` (`date_fin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Parametres ----------
INSERT IGNORE INTO `parametres` (ecole_id, cle, valeur) VALUES (NULL, 'fichier_taille_max_mo', '10'), (NULL, 'incident_qualification_jours', '7');

-- ---------- Droits complementaires ----------
-- Le chauffeur peut deposer ses propres documents (statut 'a_verifier').
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_create = 1 WHERE r.role_key = 'chauffeur' AND p.module_key = 'chauffeur_documents';
-- Le gestionnaire de flotte valide les documents (verification), sans voir les contrats.
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_validate = 1 WHERE r.role_key = 'fleet_manager' AND p.module_key = 'chauffeur_documents';
-- Le gestionnaire de flotte qualifie les incidents (responsabilite), l'admin aussi.
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_validate = 1 WHERE r.role_key = 'fleet_manager' AND p.module_key = 'incidents';
-- La RH valide les documents et les contrats.
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_validate = 1 WHERE r.role_key = 'rh' AND p.module_key IN ('chauffeur_documents', 'chauffeur_contracts');
-- La RH consulte les vehicules (lecture) pour les rattacher aux contrats d'utilisation.
INSERT IGNORE INTO `role_permissions` (role_id, permission_id) SELECT r.id, p.id FROM `roles` r, `permissions` p WHERE r.role_key = 'rh' AND p.module_key = 'vehicules';
UPDATE `role_permissions` rp JOIN `roles` r ON r.id = rp.role_id JOIN `permissions` p ON p.id = rp.permission_id SET rp.can_read = 1, rp.can_create = 0, rp.can_edit = 0, rp.can_delete = 0, rp.can_validate = 0, rp.can_export = 0, rp.scope = 'SCHOOL' WHERE r.role_key = 'rh' AND p.module_key = 'vehicules';
