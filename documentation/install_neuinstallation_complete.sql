-- ============================================================================
-- FeuerwehrBestellsystem – NEUINSTALLATION (einmalig, komplette Datenbank)
-- ============================================================================
-- Dieses Script ist an include/sql.sql angeglichen (Stand siehe Kopf dort).
-- Verwendung: Nur auf einer LEEREN Datenbank (keine Tabellen mit Daten).
-- In phpMyAdmin Ziel-DB auswählen → Script einmal ausführen (ohne CREATE DATABASE/USE).
--
-- NICHT auf einer laufenden Installation erneut ausführen (Fehler #1060 Duplicate column).
-- Upgrade alter DB: documentation/patch_schema_legacy_safe.sql
--
-- Enthält u.a.:
--   beilagen, position_subcategories, positionen (inkl. subcategory/tile_bg, kassa_only),
--   tische, type, users (Startseite, Thermo Abholbon-Ziel), feste, bestellungen
--   (order_nr, bon_id, …), print, sammelrechnungen, print_targets,
--   menu_locks, menu_lock_exceptions, rechnungen (order_nr, is_proforma, lines_json),
--   buchungen, mitarbeiter_*, Finanzmodul (kassen_*, kellner_*), settings, printer_jobs
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ------------------------------------------
-- Tabelle: beilagen
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `beilagen` (
  `rowid` INT(11) NOT NULL AUTO_INCREMENT,
  `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` VARCHAR(255) NOT NULL,
  `position` INT(11) NOT NULL,
  `betrag` DOUBLE NOT NULL DEFAULT 0,
  `fest_id` INT(11) NULL DEFAULT NULL COMMENT 'NULL = global, sonst gehört Beilage zu diesem Fest und wird beim Löschen mit-entfernt',
  PRIMARY KEY (`rowid`),
  KEY `idx_position` (`position`),
  KEY `idx_fest_id` (`fest_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: position_subcategories
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `position_subcategories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `type` TINYINT(4) NOT NULL COMMENT '1=Speise 2=Getränk',
  `name` VARCHAR(128) NOT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `tile_bg` VARCHAR(32) NULL DEFAULT NULL,
  `kassa_only` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=nur Kasse, Kellner sehen Gruppe nicht',
  `fest_id` INT(11) NULL DEFAULT NULL COMMENT 'NULL = global, sonst gehört Subkategorie zu diesem Fest und wird beim Löschen mit-entfernt',
  PRIMARY KEY (`id`),
  KEY `idx_type_sort` (`type`, `sort_order`),
  KEY `idx_kassa_only` (`kassa_only`),
  KEY `idx_fest_id` (`fest_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: positionen
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `positionen` (
  `rowid` INT(11) NOT NULL AUTO_INCREMENT,
  `Positionsname` TEXT NOT NULL,
  `Betrag` DECIMAL(10,2) NOT NULL,
  `type` INT(2) NOT NULL COMMENT '1=Speise, 2=Getränk',
  `Kurzbezeichnung` VARCHAR(30) NOT NULL,
  `maxBestellbar` INT(11) NOT NULL DEFAULT -1 COMMENT '-1=unbegrenzt',
  `reihenfolge` INT(11) NOT NULL,
  `color` VARCHAR(8) NOT NULL DEFAULT '#ffffff',
  `icon` VARCHAR(30) NOT NULL,
  `print_target` INT(11) NOT NULL DEFAULT 11 COMMENT '11=Küche, 12=Schank, 13=Feuerflecken, etc.',
  `selbstkosten` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Einkaufspreis / variable Kosten pro Stück für Gewinnberechnung',
  `subcategory_id` INT(11) NULL DEFAULT NULL,
  `tile_bg` VARCHAR(32) NULL DEFAULT NULL,
  `kassa_only` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=nur Direktverkauf/Kasse, nicht für Kellner',
  `fest_id` INT(11) NULL DEFAULT NULL COMMENT 'NULL = global, sonst gehört Position zu diesem Fest und wird beim Löschen mit-entfernt',
  PRIMARY KEY (`rowid`),
  KEY `idx_type` (`type`),
  KEY `idx_print_target` (`print_target`),
  KEY `idx_kassa_only` (`kassa_only`),
  KEY `idx_fest_id` (`fest_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: tische
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `tische` (
  `tischnummer` INT(11) NOT NULL AUTO_INCREMENT,
  `fest_id` INT(11) NULL DEFAULT NULL,
  `tischname` VARCHAR(50) NOT NULL,
  `zeitstempel` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userID` INT(11) NOT NULL,
  `x` INT(11) NOT NULL,
  `y` INT(11) NOT NULL,
  `color` VARCHAR(10) NOT NULL DEFAULT '#ffffff',
  `is_sammelrechnung` TINYINT(1) NOT NULL DEFAULT 0,
  `is_ehrengast` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`tischnummer`),
  KEY `idx_fest_id` (`fest_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: type
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `type` (
  `rowid` INT(2) NOT NULL,
  `name` VARCHAR(30) NOT NULL,
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: users
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(255) DEFAULT NULL,
  `display_name` VARCHAR(120) NULL DEFAULT NULL COMMENT 'Anzeigename (Vor-/Nachname) für Rechnungen; leer = Fallback auf username',
  `password` VARCHAR(255) DEFAULT NULL,
  `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `admin` TINYINT(1) NOT NULL,
  `can_finance` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Finanzverwaltung (Kassen, Kellnerabrechnung, Gewinn)',
  `start_page` VARCHAR(32) NOT NULL DEFAULT 'menu',
  `start_print_target` INT(11) NULL DEFAULT NULL,
  `dv_abholbon_print_target` INT(11) NULL DEFAULT NULL COMMENT 'NULL=Auto; Thermo Abholbon Direktverkauf',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: feste
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `feste` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `rechnung_prefix` VARCHAR(16) NULL DEFAULT NULL COMMENT 'Rechnungsnr.-Präfix; leer = global rechnung_prefix',
  `fest_datum` DATE NULL,
  `aktiv` TINYINT(1) NOT NULL DEFAULT 1,
  `ist_aktuell` TINYINT(1) NOT NULL DEFAULT 0,
  `payment_mode` ENUM('instant','after') NOT NULL DEFAULT 'after',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_aktiv` (`aktiv`),
  KEY `idx_ist_aktuell` (`ist_aktuell`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: bestellungen
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `bestellungen` (
  `rowid` INT(11) NOT NULL AUTO_INCREMENT,
  `fest_id` INT(11) NULL DEFAULT NULL,
  `sammelrechnung_id` INT(11) NULL,
  `rechnung_id` INT(11) NULL,
  `zeitstempel` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `position` INT(11) NOT NULL,
  `ausgeliefert` INT(11) NOT NULL,
  `tischnummer` INT(11) NOT NULL,
  `kellner` TEXT NOT NULL,
  `bestellung` INT(11) NOT NULL,
  `kueche` INT(11) NOT NULL,
  `delete` INT(11) NOT NULL,
  `zeitKueche` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
  `print` TINYINT(1) NOT NULL,
  `print_target` INT(11) NULL DEFAULT NULL,
  `print_status` TINYINT(1) NOT NULL DEFAULT 0,
  `timestampBestellung` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
  `timestampAuslieferung` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
  `timestampBezahlung` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
  `kellnerZahlung` TEXT NOT NULL,
  `Zusatzinfo` VARCHAR(255) NULL,
  `betrag` DOUBLE NOT NULL,
  `bestellt` TINYINT(1) NOT NULL,
  `is_gratis` TINYINT(1) NOT NULL DEFAULT 0,
  `bon_id` VARCHAR(10) NULL DEFAULT NULL,
  `order_nr` INT(11) NULL DEFAULT NULL COMMENT 'Bestellnummer (beim Abschicken)',
  `settlement_id` INT(11) NULL DEFAULT NULL COMMENT 'Kellner-Abrechnung',
  `settled_at` DATETIME NULL DEFAULT NULL,
  `settled_by` VARCHAR(64) NULL DEFAULT NULL,
  PRIMARY KEY (`rowid`),
  KEY `idx_tischnummer` (`tischnummer`),
  KEY `idx_position` (`position`),
  KEY `idx_fest_id` (`fest_id`),
  KEY `idx_sammelrechnung_id` (`sammelrechnung_id`),
  KEY `idx_rechnung_id` (`rechnung_id`),
  KEY `idx_print_target` (`print_target`),
  KEY `idx_bon_id` (`bon_id`),
  KEY `idx_order_nr` (`order_nr`),
  KEY `idx_kueche` (`kueche`),
  KEY `idx_delete` (`delete`),
  KEY `idx_settlement_id` (`settlement_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: print
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `print` (
  `rowid` INT(11) NOT NULL AUTO_INCREMENT,
  `bestellungID` INT(11) NOT NULL,
  `timestamp` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`rowid`),
  KEY `idx_bestellungID` (`bestellungID`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: sammelrechnungen
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `sammelrechnungen` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Session: wer den Bezahl-Vorgang ausgeführt hat',
  `umsatz_zustaendig` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Kellner/in für Umsatz-Zuordnung',
  `tables_text` TEXT NULL COMMENT 'Kommagetrennte Tischnummern',
  `total_amount` DECIMAL(10,2) NULL DEFAULT 0.00,
  `bezahlt` TINYINT(1) NOT NULL DEFAULT 0,
  `bezahlt_at` TIMESTAMP NULL DEFAULT NULL,
  `fest_id` INT(11) NULL DEFAULT NULL,
  `tischnummer` INT(11) NULL DEFAULT NULL COMMENT 'Legacy: einzelner Tisch',
  `betrag` DECIMAL(10,2) NULL DEFAULT 0.00 COMMENT 'Legacy: parallel zu total_amount',
  PRIMARY KEY (`id`),
  KEY `idx_fest_id` (`fest_id`),
  KEY `idx_tischnummer` (`tischnummer`),
  KEY `idx_bezahlt` (`bezahlt`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Super-Admin User (admin / admin123 – bitte ändern!)
-- Kommentarkopie: documentation/SQL_Dateien_Übersicht.md —
-- keine direkten HTTP-Downloads der SQL-/include-Ordner (.htaccess).
-- ------------------------------------------
INSERT INTO `users` (`id`, `username`, `password`, `timestamp`, `admin`, `start_page`, `start_print_target`, `dv_abholbon_print_target`)
VALUES
(1, 'admin', '$2y$12$Jy4ABWLPBXduUl6MhMfRPOXuV3Nkj7XMdChCTPXZVN1wT98eEg9lq', NOW(), 2, 'menu', NULL, NULL)
ON DUPLICATE KEY UPDATE
`username`=VALUES(`username`),
`password`=VALUES(`password`),
`admin`=VALUES(`admin`);

INSERT INTO `type` (`rowid`, `name`) VALUES
(1, 'Speise'),
(2, 'Getränk')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- ------------------------------------------
-- print_targets (Druckziele)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `print_targets` (
  `print_target` INT(11) NOT NULL,
  `name` VARCHAR(64) NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `finance_bereich_id` INT(11) NULL DEFAULT NULL COMMENT 'Finanzbereich für Verkaufsauswertung',
  PRIMARY KEY (`print_target`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

INSERT INTO `print_targets` (`print_target`, `name`, `active`, `sort_order`) VALUES
(11, 'Küche', 1, 10),
(12, 'Schank', 1, 20),
(13, 'Feuerflecken', 1, 30)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `sort_order`=VALUES(`sort_order`);

-- ------------------------------------------
-- menu_locks (temporäre Speisekarten-Sperren)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `menu_locks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `scope` ENUM('position','type_all') NOT NULL DEFAULT 'position',
  `position_id` INT(11) NULL DEFAULT NULL,
  `menu_type` TINYINT(2) NULL DEFAULT NULL COMMENT '1=Speise, 2=Getränk bei type_all',
  `locked_until` DATETIME NULL DEFAULT NULL COMMENT 'NULL = bis manuell aufgehoben',
  `reason` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_scope_type` (`scope`, `menu_type`),
  KEY `idx_position` (`position_id`),
  KEY `idx_until` (`locked_until`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_lock_exceptions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `lock_id` INT(11) NOT NULL,
  `position_id` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_lock_pos` (`lock_id`, `position_id`),
  KEY `idx_lock` (`lock_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- rechnungen (PDF-Rechnungen)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `rechnungen` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `rechnungsnummer` VARCHAR(50) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(64) NOT NULL,
  `fest_id` INT(11) NULL,
  `tischnummer` INT(11) NULL,
  `sammelrechnung_id` INT(11) NULL,
  `is_firma` TINYINT(1) NOT NULL DEFAULT 0,
  `empfaenger_name` VARCHAR(255) NULL,
  `empfaenger_strasse` VARCHAR(255) NULL,
  `empfaenger_plz` VARCHAR(30) NULL,
  `empfaenger_ort` VARCHAR(80) NULL,
  `empfaenger_uid` VARCHAR(40) NULL,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `gedruckt` TINYINT(1) NOT NULL DEFAULT 0,
  `druck_status` VARCHAR(10) NOT NULL DEFAULT 'pending',
  `druck_attempts` INT(11) NOT NULL DEFAULT 0,
  `druck_last_error` VARCHAR(255) NULL,
  `reserved_at` TIMESTAMP NULL,
  `reserved_by` VARCHAR(64) NULL,
  `order_nr` INT(11) NULL DEFAULT NULL,
  `is_proforma` TINYINT(1) NOT NULL DEFAULT 0,
  `lines_json` MEDIUMTEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rechnungsnummer` (`rechnungsnummer`),
  KEY `idx_fest_id` (`fest_id`),
  KEY `idx_sammelrechnung_id` (`sammelrechnung_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_order_nr` (`order_nr`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- buchungen (Fixe Einnahmen & Ausgaben für Gewinn)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `buchungen` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `typ` ENUM('einnahme','ausgabe') NOT NULL,
  `bezeichnung` VARCHAR(255) NOT NULL,
  `betrag` DECIMAL(10,2) NOT NULL,
  `datum` DATE NULL,
  `kategorie` VARCHAR(100) NULL,
  `notiz` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(64) NULL,
  `bereich_id` INT(11) NULL DEFAULT NULL COMMENT 'Finanzbereich (kassen_bereiche)',
  PRIMARY KEY (`id`),
  KEY `idx_typ` (`typ`),
  KEY `idx_datum` (`datum`),
  KEY `idx_bereich_id` (`bereich_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Finanzmodul (Kassen, Kellnerabrechnung)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `kassen_bereiche` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `kontrolle_only` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Nur Kassenkontrolle, nicht in Gesamtumsatz',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kassen_sessions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `bereich_id` INT(11) NOT NULL,
  `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
  `opening_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `opened_at` DATETIME NOT NULL,
  `opened_by` VARCHAR(64) NOT NULL,
  `closing_amount` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Tageslosung',
  `revenue_amount` DECIMAL(10,2) NULL DEFAULT NULL,
  `closed_at` DATETIME NULL DEFAULT NULL,
  `closed_by` VARCHAR(64) NULL DEFAULT NULL,
  `reopened_at` DATETIME NULL DEFAULT NULL,
  `reopened_by` VARCHAR(64) NULL DEFAULT NULL,
  `reopen_reason` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bereich_status` (`bereich_id`, `status`),
  KEY `idx_opened_at` (`opened_at`)
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
  `settlement_scope` VARCHAR(20) NOT NULL DEFAULT 'kellner' COMMENT 'kellner oder direktverkauf',
  `von_dt` DATETIME NOT NULL,
  `bis_dt` DATETIME NOT NULL,
  `umsatz_soll` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `betrag_abgegeben` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `wechselgeld_zurueck` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `trinkgeld` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notiz` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(64) NULL DEFAULT NULL,
  `voided_at` DATETIME NULL DEFAULT NULL,
  `voided_by` VARCHAR(64) NULL DEFAULT NULL,
  `void_reason` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kellner` (`kellner_login`),
  KEY `idx_von_bis` (`von_dt`, `bis_dt`)
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
  PRIMARY KEY (`id`),
  KEY `idx_kellner` (`kellner_login`),
  KEY `idx_settlement` (`settlement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- mitarbeiter_bereiche & mitarbeiter_verpflegung
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `mitarbeiter_bereiche` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `mitarbeiter_bereiche` (`name`, `sort_order`) VALUES
('Küche', 10),
('Schank', 20),
('Kellner', 30),
('Komando', 40),
('Jugendfeuerwehr', 50),
('Sonstige', 99);

CREATE TABLE IF NOT EXISTS `mitarbeiter_verpflegung` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `datum` DATE NOT NULL,
  `bereich_id` INT(11) NOT NULL,
  `position_id` INT(11) NOT NULL,
  `menge` INT(11) NOT NULL DEFAULT 1,
  `notiz` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_datum` (`datum`),
  KEY `idx_bereich` (`bereich_id`),
  KEY `idx_position` (`position_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- settings (Einstellungen)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`k`, `v`) VALUES
('kellner_nur_eigene', '1'),
('fast_refresh', '0'),
('current_fest_id', '0'),
('current_fest_code', ''),
('seller_name', 'Freiwillige Feuerwehr Obritzberg'),
('seller_address', 'Hauptstraße 1\n3123 Obritzberg'),
('seller_uid', ''),
('rechnung_prefix', 'R'),
('rechnung_festname', 'FF Fest Obritzberg 2026'),
('rechnung_logo', ''),
('thermo_bon_header', ''),
('thermo_bon_footer', ''),
('printer_token', ''),
('offline_backup_token', ''),
('printer_warn_after_sec', '60'),
('printer_job_stuck_reserved_min', '10'),
('order_nr_seq', '0'),
('bon_nr_seq', '0'),
('bon_nr_start', '1'),
('karte_spalten', '3'),
('karte_spalten_mobil', '3'),
('tisch_raster_spalten', '5'),
('tisch_raster_spalten_mobil', '5'),
('station_summary_top', '1'),
('station_summary_right', '1'),
('station_spalten', '0'),
('station_spalten_mobil', '0'),
('station_one_click_abschliessen', '0'),
('station_teillieferung_druck', '0'),
('app_title', ''),
('session_max_idle_sec', '900')
ON DUPLICATE KEY UPDATE `k`=`k`;

-- ------------------------------------------
-- printer_jobs (Druck-Queue Rechnungen)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `printer_jobs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `printer` VARCHAR(32) NOT NULL,
  `type` VARCHAR(32) DEFAULT 'invoice',
  `payload` MEDIUMTEXT NOT NULL,
  `meta` JSON NULL,
  `status` ENUM('pending','reserved','done','error') DEFAULT 'pending',
  `attempts` INT(11) DEFAULT 0,
  `reserved_at` DATETIME NULL,
  `reserved_by` VARCHAR(64) NULL,
  `error` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Ende Neuinstallation. Danach: Login (admin / admin123), Admin konfigurieren, Passwort ändern.
-- Quelle: include/sql.sql (inkl. Finanzmodul, Buchungen, Mitarbeiter-Verpflegung).
-- ============================================================================
