-- Finanzmodul: Berechtigung, Kassen, Kellnerabrechnung (bestehende Installation)
-- Einmal ausführen. Neuinstallation: Tabellen bereits in include/sql.sql (nach Update).

ALTER TABLE `users`
  ADD COLUMN `can_finance` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Finanzverwaltung' AFTER `admin`;

CREATE TABLE IF NOT EXISTS `kassen_bereiche` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kassen_sessions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `bereich_id` INT(11) NOT NULL,
  `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
  `opening_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `opened_at` DATETIME NOT NULL,
  `opened_by` VARCHAR(64) NOT NULL,
  `closing_amount` DECIMAL(10,2) NULL DEFAULT NULL,
  `revenue_amount` DECIMAL(10,2) NULL DEFAULT NULL,
  `closed_at` DATETIME NULL DEFAULT NULL,
  `closed_by` VARCHAR(64) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bereich_status` (`bereich_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kassen_bewegungen` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `session_id` INT(11) NOT NULL,
  `typ` ENUM('entnahme','zuzahlung') NOT NULL,
  `betrag` DECIMAL(10,2) NOT NULL,
  `notiz` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(64) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kellner_settlements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `kellner_login` VARCHAR(255) NOT NULL,
  `von_dt` DATETIME NOT NULL,
  `bis_dt` DATETIME NOT NULL,
  `umsatz_soll` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `betrag_abgegeben` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `wechselgeld_zurueck` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `trinkgeld` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notiz` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(64) NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kellner_bewegungen` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `kellner_login` VARCHAR(255) NOT NULL,
  `settlement_id` INT(11) NULL DEFAULT NULL,
  `typ` ENUM('entnahme','zuzahlung') NOT NULL,
  `betrag` DECIMAL(10,2) NOT NULL,
  `notiz` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(64) NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `bestellungen` ADD COLUMN `settlement_id` INT(11) NULL DEFAULT NULL;
ALTER TABLE `bestellungen` ADD COLUMN `settled_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `bestellungen` ADD COLUMN `settled_by` VARCHAR(64) NULL DEFAULT NULL;
