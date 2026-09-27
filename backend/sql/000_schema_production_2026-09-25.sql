-- Schema de la base de production shippgroup_shippgroup, releve le 25/09/2026
-- (SHOW CREATE TABLE, lecture seule). Reference pour la recette ; ne pas
-- executer en production.

CREATE TABLE `abonnements` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`eleve_id` int(11) NOT NULL,
`ecole_id` int(11) NOT NULL,
`annee_scolaire_id` int(11) NOT NULL,
`type` enum('transport','cantine') NOT NULL,
`statut` enum('actif','suspendu','en_attente','resilie') NOT NULL DEFAULT 'en_attente',
`date_debut` date DEFAULT NULL,
`date_fin` date DEFAULT NULL,
`created_at` timestamp NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`),
UNIQUE KEY `uniq_eleve_type_annee` (`eleve_id`,`type`,`annee_scolaire_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `abonnements_historique` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`abonnement_id` int(11) NOT NULL,
`ancien_statut` varchar(20) DEFAULT NULL,
`nouveau_statut` varchar(20) NOT NULL,
`action` varchar(20) NOT NULL,
`motif` varchar(255) DEFAULT NULL,
`ancienne_date_fin` date DEFAULT NULL,
`nouvelle_date_fin` date DEFAULT NULL,
`user_id` int(11) DEFAULT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`),
KEY `abonnement_id` (`abonnement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `affectations_chauffeur` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) NOT NULL,
`user_id` int(11) NOT NULL,
`circuit_id` int(11) NOT NULL,
`created_at` timestamp NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`),
UNIQUE KEY `uniq_circuit` (`circuit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `annees_scolaires` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) NOT NULL,
`libelle` varchar(20) NOT NULL,
`date_debut` date NOT NULL,
`date_fin` date NOT NULL,
`statut` enum('active','archivee') NOT NULL DEFAULT 'active',
`created_at` timestamp NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `cantine` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) DEFAULT NULL,
`annee_scolaire_id` int(11) DEFAULT NULL,
`eleve_nom` varchar(150) NOT NULL,
`menu` varchar(150) DEFAULT NULL,
`date_repas` date DEFAULT NULL,
`statut` varchar(30) NOT NULL DEFAULT 'reserve',
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `circuits` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) DEFAULT NULL,
`nom` varchar(100) NOT NULL,
`description` varchar(200) DEFAULT NULL,
`vehicule` varchar(100) DEFAULT NULL,
`vehicule_id` int(11) DEFAULT NULL,
`statut` varchar(30) NOT NULL DEFAULT 'actif',
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `couvertures_chauffeur` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) NOT NULL,
`chauffeur_remplacant_id` int(11) NOT NULL,
`circuit_id` int(11) NOT NULL,
`eleve_id` int(11) DEFAULT NULL,
`date_debut` date NOT NULL,
`date_fin` date DEFAULT NULL,
`motif` varchar(255) DEFAULT NULL,
`created_by` int(11) NOT NULL,
`created_at` timestamp NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`),
KEY `idx_circuit` (`circuit_id`),
KEY `idx_eleve` (`eleve_id`),
KEY `idx_chauffeur` (`chauffeur_remplacant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `ecoles` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`nom` varchar(150) NOT NULL,
`adresse` varchar(200) DEFAULT NULL,
`telephone` varchar(30) DEFAULT NULL,
`directeur` varchar(100) DEFAULT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `ecole_modules` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) NOT NULL,
`module_key` varchar(50) NOT NULL,
`actif` tinyint(1) NOT NULL DEFAULT 1,
PRIMARY KEY (`id`),
UNIQUE KEY `uniq_ecole_module` (`ecole_id`,`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `eleves` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) DEFAULT NULL,
`annee_scolaire_id` int(11) DEFAULT NULL,
`circuit_id` int(11) DEFAULT NULL,
`code_dr` varchar(20) DEFAULT NULL,
`qr_code` varchar(40) DEFAULT NULL,
`photo` varchar(255) DEFAULT NULL,
`nom` varchar(100) NOT NULL,
`prenom` varchar(100) NOT NULL,
`date_naissance` date DEFAULT NULL,
`classe` varchar(50) DEFAULT NULL,
`ecole` varchar(100) DEFAULT NULL,
`statut` varchar(30) NOT NULL DEFAULT 'actif',
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`),
UNIQUE KEY `code_dr` (`code_dr`),
UNIQUE KEY `qr_code` (`qr_code`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `etapes` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) NOT NULL,
`circuit_id` int(11) NOT NULL,
`nom` varchar(150) NOT NULL,
`ordre` int(11) NOT NULL,
`heure_estimee` time DEFAULT NULL,
`statut` enum('active','inactive') NOT NULL DEFAULT 'active',
`created_at` timestamp NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`),
UNIQUE KEY `uniq_circuit_ordre` (`circuit_id`,`ordre`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `finance` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) DEFAULT NULL,
`annee_scolaire_id` int(11) DEFAULT NULL,
`eleve_id` int(11) DEFAULT NULL,
`libelle` varchar(150) NOT NULL,
`montant` decimal(10,2) NOT NULL DEFAULT 0.00,
`type` varchar(20) NOT NULL DEFAULT 'recette',
`date_echeance` date DEFAULT NULL,
`statut` varchar(30) NOT NULL DEFAULT 'en_attente',
`mode_paiement` varchar(30) DEFAULT NULL,
`date_paiement` date DEFAULT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `finance_historique` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`finance_id` int(11) NOT NULL,
`ancien_statut` varchar(30) DEFAULT NULL,
`nouveau_statut` varchar(30) NOT NULL,
`action` varchar(30) NOT NULL,
`motif` varchar(255) DEFAULT NULL,
`montant_paye` decimal(10,2) DEFAULT NULL,
`user_id` int(11) DEFAULT NULL,
`created_at` timestamp NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`),
KEY `finance_id` (`finance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `incidents` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) NOT NULL,
`annee_scolaire_id` int(11) NOT NULL,
`titre` varchar(150) NOT NULL,
`description` text DEFAULT NULL,
`type` varchar(30) NOT NULL DEFAULT 'autre',
`gravite` varchar(20) NOT NULL DEFAULT 'moyenne',
`circuit_id` int(11) DEFAULT NULL,
`vehicule_id` int(11) DEFAULT NULL,
`chauffeur_id` int(11) DEFAULT NULL,
`eleve_id` int(11) DEFAULT NULL,
`statut` varchar(20) NOT NULL DEFAULT 'ouvert',
`date_incident` date DEFAULT NULL,
`created_at` timestamp NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `incidents_historique` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`incident_id` int(11) NOT NULL,
`ancien_statut` varchar(20) DEFAULT NULL,
`nouveau_statut` varchar(20) NOT NULL,
`action` varchar(30) NOT NULL,
`motif` varchar(255) DEFAULT NULL,
`user_id` int(11) DEFAULT NULL,
`created_at` timestamp NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`),
KEY `incident_id` (`incident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `journal_activite` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) DEFAULT NULL,
`user_id` int(11) DEFAULT NULL,
`action` enum('create','update','delete','login') NOT NULL,
`module_key` varchar(50) NOT NULL,
`record_id` int(11) DEFAULT NULL,
`details` varchar(500) DEFAULT NULL,
`created_at` timestamp NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `menus` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) DEFAULT NULL,
`annee_scolaire_id` int(11) DEFAULT NULL,
`date_menu` date DEFAULT NULL,
`periode` enum('petit_dejeuner','dejeuner','gouter') NOT NULL DEFAULT 'dejeuner',
`libelle` varchar(150) NOT NULL,
`description` varchar(200) DEFAULT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `notifications` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) DEFAULT NULL,
`titre` varchar(150) NOT NULL,
`message` varchar(500) DEFAULT NULL,
`cible` varchar(50) DEFAULT NULL,
`statut` varchar(30) NOT NULL DEFAULT 'brouillon',
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `parents_eleves` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) DEFAULT NULL,
`nom` varchar(100) NOT NULL,
`prenom` varchar(100) NOT NULL,
`email` varchar(150) DEFAULT NULL,
`telephone` varchar(30) DEFAULT NULL,
`adresse` varchar(200) DEFAULT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `parent_liaisons` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) NOT NULL,
`user_id` int(11) NOT NULL,
`eleve_id` int(11) NOT NULL,
`lien` enum('pere','mere','tuteur','autre') NOT NULL DEFAULT 'autre',
`created_at` timestamp NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`),
UNIQUE KEY `uniq_user_eleve` (`user_id`,`eleve_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `permissions` (
`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
`module_key` varchar(50) NOT NULL,
`module_label` varchar(100) NOT NULL,
`sort_order` int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY (`id`),
UNIQUE KEY `module_key` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `rapports` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) DEFAULT NULL,
`titre` varchar(150) NOT NULL,
`type` varchar(50) DEFAULT NULL,
`periode` varchar(50) DEFAULT NULL,
`statut` varchar(30) NOT NULL DEFAULT 'brouillon',
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `roles` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`role_key` varchar(50) NOT NULL,
`role_label` varchar(100) NOT NULL,
`sort_order` int(11) NOT NULL DEFAULT 0,
PRIMARY KEY (`id`),
UNIQUE KEY `role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `role_permissions` (
`role_id` int(11) NOT NULL,
`permission_id` int(11) NOT NULL,
`can_read` tinyint(1) NOT NULL DEFAULT 0,
`can_create` tinyint(1) NOT NULL DEFAULT 0,
`can_edit` tinyint(1) NOT NULL DEFAULT 0,
`can_delete` tinyint(1) NOT NULL DEFAULT 0,
PRIMARY KEY (`role_id`,`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `scans` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) NOT NULL,
`annee_scolaire_id` int(11) NOT NULL,
`eleve_id` int(11) NOT NULL,
`type` enum('transport_embarquement','transport_debarquement','cantine') NOT NULL,
`trajet_id` int(11) DEFAULT NULL,
`etape_id` int(11) DEFAULT NULL,
`methode` enum('camera','recherche','code') NOT NULL DEFAULT 'recherche',
`scanned_by` int(11) DEFAULT NULL,
`scanned_at` timestamp NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `trajets` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) NOT NULL,
`annee_scolaire_id` int(11) NOT NULL,
`circuit_id` int(11) NOT NULL,
`date_trajet` date NOT NULL,
`statut` enum('planifie','en_cours','termine','annule') NOT NULL DEFAULT 'planifie',
`etape_courante_id` int(11) DEFAULT NULL,
`heure_debut` datetime DEFAULT NULL,
`heure_fin` datetime DEFAULT NULL,
`created_at` timestamp NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `transport` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) DEFAULT NULL,
`annee_scolaire_id` int(11) DEFAULT NULL,
`eleve_nom` varchar(150) NOT NULL,
`circuit` varchar(100) DEFAULT NULL,
`point_ramassage` varchar(150) DEFAULT NULL,
`statut` varchar(30) NOT NULL DEFAULT 'actif',
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `users` (
`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) DEFAULT NULL,
`name` varchar(150) NOT NULL,
`email` varchar(150) NOT NULL,
`password_hash` varchar(255) DEFAULT NULL,
`status` enum('active','inactive') NOT NULL DEFAULT 'active',
`created_at` timestamp NULL DEFAULT current_timestamp(),
`updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
`is_admin` tinyint(1) NOT NULL DEFAULT 0,
PRIMARY KEY (`id`),
UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `user_permissions` (
`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
`user_id` int(10) unsigned NOT NULL,
`permission_id` int(10) unsigned NOT NULL,
`can_read` tinyint(1) NOT NULL DEFAULT 0,
`can_create` tinyint(1) NOT NULL DEFAULT 0,
`can_edit` tinyint(1) NOT NULL DEFAULT 0,
`can_delete` tinyint(1) NOT NULL DEFAULT 0,
`can_validate` tinyint(1) NOT NULL DEFAULT 0,
`can_export` tinyint(1) NOT NULL DEFAULT 0,
PRIMARY KEY (`id`),
UNIQUE KEY `user_permission_unique` (`user_id`,`permission_id`),
KEY `fk_up_permission` (`permission_id`),
CONSTRAINT `fk_up_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `vehicules` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`ecole_id` int(11) DEFAULT NULL,
`immatriculation` varchar(30) NOT NULL,
`modele` varchar(100) DEFAULT NULL,
`capacite` int(11) DEFAULT NULL,
`chauffeur` varchar(100) DEFAULT NULL,
`statut` varchar(30) NOT NULL DEFAULT 'actif',
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
