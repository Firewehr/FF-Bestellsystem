SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

START TRANSACTION;

-- ---------------------------------------------------------------------
-- 1) Tabelle 'feste' NEU (weil es die 2017 nicht gab)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `feste` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `payment_mode` enum('END','IMMEDIATE') NOT NULL DEFAULT 'END',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fest_name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Optional: Ein Default-Fest anlegen (kannst du ändern)
INSERT IGNORE INTO feste (id, name, is_active, payment_mode) VALUES (1, 'Standard-Fest', 1, 'END');

-- ---------------------------------------------------------------------
-- 2) Tabelle 'users' erweitern: Superadmin-Level + role + active
-- (dein altes Feld admin bleibt bestehen)
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN `admin_level` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `role` varchar(20) NOT NULL DEFAULT 'WAITER',
  ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1;

-- Mapping: wenn admin=1, dann admin_level mindestens 1
UPDATE `users` SET `admin_level` = 1 WHERE `admin` = 1 AND `admin_level` < 1;

-- ---------------------------------------------------------------------
-- 3) Tabelle 'tische' erweitern: fest_id + flags + status/closing
-- ---------------------------------------------------------------------
ALTER TABLE `tische`
  ADD COLUMN `fest_id` int(11) NOT NULL DEFAULT 1,
  ADD COLUMN `is_collective` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `is_honor` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `status` enum('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  ADD COLUMN `closed_at` timestamp NULL DEFAULT NULL,
  ADD COLUMN `closed_by_user` int(11) DEFAULT NULL;

-- ---------------------------------------------------------------------
-- 4) Tabelle 'bestellungen' erweitern: fest_id + status + paid_by
-- (so kannst du Abrechnung/Schließen korrekt machen)
-- ---------------------------------------------------------------------
ALTER TABLE `bestellungen`
  ADD COLUMN `fest_id` int(11) NOT NULL DEFAULT 1,
  ADD COLUMN `status2` varchar(20) NOT NULL DEFAULT 'OPEN',
  ADD COLUMN `paid_by_user` int(11) DEFAULT NULL;

-- status2 ist ein Patch-Feld, weil im alten Schema schon viele Felder existieren
-- OPEN / DONE / CANCELLED etc. kannst du im Code verwenden.

-- ---------------------------------------------------------------------
-- 5) Druckjobs als Queue (NEU)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `printer_jobs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fest_id` int(11) NOT NULL DEFAULT 1,
  `target` varchar(10) NOT NULL DEFAULT 'CASH',
  `job_type` varchar(10) NOT NULL DEFAULT 'BON',
  `payload_json` longtext NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_error` text,
  `reserved_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `done_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_fest` (`fest_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- ---------------------------------------------------------------------
-- 6) Settings (NEU) für "Kellner sieht nur eigene Bestellungen"
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `key_name` varchar(80) NOT NULL,
  `value_text` text,
  PRIMARY KEY (`rowid`),
  UNIQUE KEY `uq_key` (`key_name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

INSERT IGNORE INTO `settings` (`key_name`, `value_text`) VALUES
('waiter_see_own_orders_only', '0');

COMMIT;
