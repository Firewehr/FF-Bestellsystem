-- ==========================================
-- Bestellsystem Setup – vollständiges Schema
-- ==========================================
-- Engine: InnoDB, Charset: utf8mb4 / Collation: utf8mb4_unicode_ci
-- Inhaltlich gleich mit documentation/install_neuinstallation_complete.sql
-- (Standard für phpMyAdmin); diese Datei legt zusätzlich die Datenbank an.
--
-- Stand: 2026-11-24 – erzeugt aus dem Schema einer laufenden Installation
-- (alle Migrationen angewendet, SHOW CREATE TABLE), Sortierung einheitlich
-- utf8mb4_unicode_ci. 46 Tabellen:
--   beilagen, position_subcategories, positionen, tische, type, users, feste,
--   bestellungen, print, sammelrechnungen, print_targets, menu_locks,
--   menu_lock_exceptions, rechnungen, buchungen, kassen_bereiche,
--   kassen_sessions, kassen_bewegungen, kellner_settlements,
--   kellner_bewegungen, mitarbeiter_bereiche, mitarbeiter_verpflegung,
--   settings, printer_jobs, ff_login_failures, ff_csrf_log, allergene,
--   bestandteil_allergene, bestandteile, bestellung_rezeptur_komponenten,
--   bestellung_rezeptur_snapshots, ff_admin_messages, ff_fest_push_rules,
--   ff_fest_push_settings, ff_fest_push_state, ff_station_events,
--   pilot_order_requests, pilot_payment_requests, pilot_position_links,
--   pilot_sumup_requests, position_rezepturen, printer_devices,
--   substation_access_tokens, table_tokens, user_passkeys,
--   webauthn_remote_requests
--
-- Spätere Schema-Änderungen kommen über den Migrationslauf
-- (include/ff_schema_migrate.php) beim ersten Admin-Aufruf automatisch dazu.
-- ==========================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- Optional: DB anlegen (falls du das willst)
CREATE DATABASE IF NOT EXISTS `ffobritzberg`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `ffobritzberg`;

-- ------------------------------------------
-- Tabelle: beilagen – Beilagen und Zusatzinfos mit Preisaufschlag (Pflege in manage/)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `beilagen` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `name` varchar(255) NOT NULL,
  `position` int(11) NOT NULL,
  `betrag` double NOT NULL DEFAULT 0,
  `fest_id` int(11) DEFAULT NULL COMMENT 'NULL = global, sonst gehört Beilage zu diesem Fest und wird beim Löschen mit-entfernt',
  PRIMARY KEY (`rowid`),
  KEY `idx_position` (`position`),
  KEY `idx_fest_id` (`fest_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: position_subcategories – Unterkategorien der Speisekarte inkl. Kachelfarbe und kassa_only
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `position_subcategories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` tinyint(4) NOT NULL COMMENT '1=Speise 2=Getränk',
  `name` varchar(128) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `tile_bg` varchar(32) DEFAULT NULL,
  `kassa_only` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=nur Kasse, Kellner sehen Gruppe nicht',
  `fest_id` int(11) DEFAULT NULL COMMENT 'NULL = global, sonst gehört Subkategorie zu diesem Fest und wird beim Löschen mit-entfernt',
  PRIMARY KEY (`id`),
  KEY `idx_type_sort` (`type`,`sort_order`),
  KEY `idx_kassa_only` (`kassa_only`),
  KEY `idx_fest_id` (`fest_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: positionen – Speisekarte (Artikel, Preis, Druckziel, Selbstkosten, fest_id)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `positionen` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `Positionsname` text NOT NULL,
  `Betrag` decimal(10,2) NOT NULL,
  `type` int(2) NOT NULL COMMENT '1=Speise, 2=Getränk',
  `Kurzbezeichnung` varchar(30) NOT NULL,
  `maxBestellbar` int(11) NOT NULL DEFAULT -1 COMMENT '-1=unbegrenzt',
  `reihenfolge` int(11) NOT NULL,
  `color` varchar(8) NOT NULL DEFAULT '#ffffff',
  `icon` varchar(30) DEFAULT NULL,
  `print_target` int(11) NOT NULL DEFAULT 11 COMMENT '11=Küche, 12=Schank, 13=Feuerflecken, etc.',
  `selbstkosten` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Einkaufspreis / variable Kosten pro Stück für Gewinnberechnung',
  `subcategory_id` int(11) DEFAULT NULL,
  `tile_bg` varchar(32) DEFAULT NULL,
  `kassa_only` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=nur Direktverkauf/Kasse, nicht für Kellner',
  `fest_id` int(11) DEFAULT NULL COMMENT 'NULL = global, sonst gehört Position zu diesem Fest und wird beim Löschen mit-entfernt',
  PRIMARY KEY (`rowid`),
  KEY `idx_type` (`type`),
  KEY `idx_print_target` (`print_target`),
  KEY `idx_kassa_only` (`kassa_only`),
  KEY `idx_fest_id` (`fest_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: tische – Tische inkl. Raster-Koordinaten, Sammelrechnung/Ehrengast
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `tische` (
  `tischnummer` int(11) NOT NULL AUTO_INCREMENT,
  `fest_id` int(11) DEFAULT NULL,
  `tischname` varchar(50) NOT NULL,
  `zeitstempel` timestamp NOT NULL DEFAULT current_timestamp(),
  `userID` int(11) DEFAULT 0,
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL,
  `color` varchar(10) NOT NULL DEFAULT '#ffffff',
  `is_sammelrechnung` tinyint(1) NOT NULL DEFAULT 0,
  `is_ehrengast` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`tischnummer`),
  KEY `idx_fest_id` (`fest_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: type – Artikeltypen (1 = Speise, 2 = Getränk)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `type` (
  `rowid` int(2) NOT NULL,
  `name` varchar(30) NOT NULL,
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `type` (`rowid`, `name`) VALUES
(1, 'Speise'),
(2, 'Getränk')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- ------------------------------------------
-- Tabelle: users – Benutzer, Rollen, Rechte, Startseite, Druckziele
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) DEFAULT NULL,
  `display_name` varchar(120) DEFAULT NULL COMMENT 'Anzeigename (Vor-/Nachname) für Rechnungen; leer = Fallback auf username',
  `password` varchar(255) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin` tinyint(1) NOT NULL,
  `can_finance` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Finanzverwaltung (Kassen, Kellnerabrechnung, Gewinn)',
  `can_direktverkauf` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Direktverkauf (Kassa)',
  `menu_permissions` text DEFAULT NULL COMMENT 'JSON: menu + print_targets',
  `start_page` varchar(32) NOT NULL DEFAULT 'menu',
  `start_print_target` int(11) DEFAULT NULL,
  `dv_abholbon_print_target` int(11) DEFAULT NULL COMMENT 'NULL=Auto; Thermo Abholbon Direktverkauf',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Login erlaubt',
  `active_from` datetime DEFAULT NULL COMMENT 'Login erst ab',
  `active_until` datetime DEFAULT NULL COMMENT 'Login nur bis',
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Einmalpasswort, Wechsel erzwingen',
  `auth_rev` int(11) NOT NULL DEFAULT 0 COMMENT 'Session-Version, erhoehen bei Deaktivierung/PW-Reset',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Super-Admin (admin / admin123 – nach dem ersten Login sofort ändern!)
-- Kommentarkopie: documentation/SQL_Dateien_Übersicht.md
INSERT INTO `users` (`id`, `username`, `password`, `timestamp`, `admin`, `start_page`, `start_print_target`, `dv_abholbon_print_target`)
VALUES
(1, 'admin', '$2y$12$Jy4ABWLPBXduUl6MhMfRPOXuV3Nkj7XMdChCTPXZVN1wT98eEg9lq', NOW(), 2, 'menu', NULL, NULL)
ON DUPLICATE KEY UPDATE
`username`=VALUES(`username`),
`password`=VALUES(`password`),
`admin`=VALUES(`admin`);

-- ------------------------------------------
-- Tabelle: feste – Feste/Veranstaltungen mit Zahlungsmodus und Rechnungspräfix
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `feste` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `code` varchar(32) NOT NULL,
  `rechnung_prefix` varchar(16) DEFAULT NULL COMMENT 'Rechnungsnr.-Präfix; leer = global rechnung_prefix',
  `fest_datum` date DEFAULT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `ist_aktuell` tinyint(1) NOT NULL DEFAULT 0,
  `payment_mode` enum('instant','after') NOT NULL DEFAULT 'after',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_aktiv` (`aktiv`),
  KEY `idx_ist_aktuell` (`ist_aktuell`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: bestellungen – Bestellzeilen (Tisch, Direktverkauf, Druck- und Zahlstatus)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `bestellungen` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fest_id` int(11) DEFAULT NULL,
  `sammelrechnung_id` int(11) DEFAULT NULL,
  `rechnung_id` int(11) DEFAULT NULL,
  `zeitstempel` timestamp NOT NULL DEFAULT current_timestamp(),
  `position` int(11) NOT NULL,
  `ausgeliefert` int(11) NOT NULL,
  `tischnummer` int(11) NOT NULL,
  `kellner` text NOT NULL,
  `bestellung` int(11) NOT NULL DEFAULT 0,
  `kueche` int(11) NOT NULL,
  `delete` int(11) NOT NULL DEFAULT 0,
  `zeitKueche` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `print` tinyint(1) NOT NULL DEFAULT 0,
  `print_target` int(11) DEFAULT NULL,
  `print_status` tinyint(1) NOT NULL DEFAULT 0,
  `timestampBestellung` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `timestampAuslieferung` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `timestampBezahlung` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `kellnerZahlung` text NOT NULL DEFAULT '0',
  `Zusatzinfo` varchar(255) DEFAULT NULL,
  `betrag` double NOT NULL,
  `bestellt` tinyint(1) NOT NULL,
  `is_gratis` tinyint(1) NOT NULL DEFAULT 0,
  `bon_id` varchar(10) DEFAULT NULL,
  `order_nr` int(11) DEFAULT NULL COMMENT 'Bestellnummer (beim Abschicken)',
  `settlement_id` int(11) DEFAULT NULL COMMENT 'Kellner-Abrechnung',
  `settled_at` datetime DEFAULT NULL,
  `settled_by` varchar(64) DEFAULT NULL,
  `schreibaus` tinyint(1) NOT NULL DEFAULT 0,
  `sumup_checkout_id` varchar(100) DEFAULT NULL,
  `sumup_checkout_reference` varchar(100) DEFAULT NULL,
  `zahlungsart` varchar(30) DEFAULT NULL,
  `auto_print` tinyint(1) NOT NULL DEFAULT 0,
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
  KEY `idx_settlement_id` (`settlement_id`),
  KEY `idx_tischnummer_kueche_del` (`tischnummer`,`kueche`,`delete`),
  KEY `idx_position_del` (`position`,`delete`),
  KEY `idx_print_pipeline` (`delete`,`kueche`,`bestellt`,`print`,`print_status`),
  KEY `idx_timestamp_bezahlung` (`timestampBezahlung`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: print – Legacy-Druckwarteschlange (alter print.php-Ablauf)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `print` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `bestellungID` int(11) NOT NULL,
  `timestamp` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`rowid`),
  KEY `idx_bestellungID` (`bestellungID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: sammelrechnungen – Sammelrechnungen pro Tisch
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `sammelrechnungen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(255) DEFAULT NULL COMMENT 'Session: wer den Bezahl-Vorgang ausgeführt hat',
  `umsatz_zustaendig` varchar(255) DEFAULT NULL COMMENT 'Kellner/in für Umsatz-Zuordnung',
  `tables_text` text DEFAULT NULL COMMENT 'Kommagetrennte Tischnummern',
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `bezahlt` tinyint(1) NOT NULL DEFAULT 0,
  `bezahlt_at` timestamp NULL DEFAULT NULL,
  `fest_id` int(11) DEFAULT NULL,
  `tischnummer` int(11) DEFAULT NULL COMMENT 'Legacy: einzelner Tisch',
  `betrag` decimal(10,2) DEFAULT 0.00 COMMENT 'Legacy: parallel zu total_amount',
  PRIMARY KEY (`id`),
  KEY `idx_fest_id` (`fest_id`),
  KEY `idx_tischnummer` (`tischnummer`),
  KEY `idx_bezahlt` (`bezahlt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: print_targets – Druckziele (Küche, Schank, …) inkl. Netzwerkdrucker und Auto-Druck-Limit
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `print_targets` (
  `print_target` int(11) NOT NULL,
  `name` varchar(64) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `finance_bereich_id` int(11) DEFAULT NULL COMMENT 'Finanzbereich für Verkaufsauswertung',
  `auto_print_limit` int(11) NOT NULL DEFAULT 0,
  `connection_type` varchar(16) NOT NULL DEFAULT 'client' COMMENT 'client = Python Daemon, network = RAW Socket Port 9100',
  `ip_address` varchar(64) DEFAULT NULL COMMENT 'IPv4 oder Hostname des Netzwerk-Druckers',
  `port` int(11) NOT NULL DEFAULT 9100 COMMENT 'RAW ESC/POS Port (Standard 9100)',
  PRIMARY KEY (`print_target`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `print_targets` (`print_target`, `name`, `active`, `sort_order`) VALUES
(11, 'Küche', 1, 10),
(12, 'Schank', 1, 20),
(13, 'Feuerflecken', 1, 30)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `sort_order`=VALUES(`sort_order`);

-- ------------------------------------------
-- Tabelle: menu_locks – Temporäre Speisekarten-Sperren („ausverkauft“)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `menu_locks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope` enum('position','type_all') NOT NULL DEFAULT 'position',
  `position_id` int(11) DEFAULT NULL,
  `menu_type` tinyint(2) DEFAULT NULL COMMENT '1=Speise, 2=Getränk bei type_all',
  `locked_until` datetime DEFAULT NULL COMMENT 'NULL = bis manuell aufgehoben',
  `reason` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_scope_type` (`scope`,`menu_type`),
  KEY `idx_position` (`position_id`),
  KEY `idx_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: menu_lock_exceptions – Ausnahmen zu Typ-Sperren
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `menu_lock_exceptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lock_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_lock_pos` (`lock_id`,`position_id`),
  KEY `idx_lock` (`lock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: rechnungen – Rechnungen (PDF/Thermo, Rechnungsnummer, Storno)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `rechnungen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rechnungsnummer` varchar(50) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) DEFAULT '',
  `fest_id` int(11) DEFAULT NULL,
  `tischnummer` int(11) DEFAULT NULL,
  `sammelrechnung_id` int(11) DEFAULT NULL,
  `is_firma` tinyint(1) NOT NULL DEFAULT 0,
  `empfaenger_name` varchar(255) DEFAULT NULL,
  `empfaenger_strasse` varchar(255) DEFAULT NULL,
  `empfaenger_plz` varchar(30) DEFAULT NULL,
  `empfaenger_ort` varchar(80) DEFAULT NULL,
  `empfaenger_uid` varchar(40) DEFAULT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gedruckt` tinyint(1) NOT NULL DEFAULT 0,
  `druck_status` varchar(10) NOT NULL DEFAULT 'pending',
  `druck_attempts` int(11) NOT NULL DEFAULT 0,
  `druck_last_error` varchar(255) DEFAULT NULL,
  `reserved_at` timestamp NULL DEFAULT NULL,
  `reserved_by` varchar(64) DEFAULT NULL,
  `order_nr` int(11) DEFAULT NULL,
  `is_proforma` tinyint(1) NOT NULL DEFAULT 0,
  `lines_json` mediumtext DEFAULT NULL,
  `storniert` tinyint(1) NOT NULL DEFAULT 0,
  `storno_at` datetime DEFAULT NULL,
  `storno_by` varchar(64) DEFAULT NULL,
  `storno_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rechnungsnummer` (`rechnungsnummer`),
  KEY `idx_fest_id` (`fest_id`),
  KEY `idx_sammelrechnung_id` (`sammelrechnung_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_order_nr` (`order_nr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: buchungen – Fixe Einnahmen und Ausgaben für die Gewinnermittlung
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `buchungen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `typ` enum('einnahme','ausgabe') NOT NULL,
  `bezeichnung` varchar(255) NOT NULL,
  `betrag` decimal(10,2) NOT NULL,
  `datum` date DEFAULT NULL,
  `kategorie` varchar(100) DEFAULT NULL,
  `notiz` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) DEFAULT NULL,
  `bereich_id` int(11) DEFAULT NULL COMMENT 'Finanzbereich (kassen_bereiche)',
  PRIMARY KEY (`id`),
  KEY `idx_typ` (`typ`),
  KEY `idx_datum` (`datum`),
  KEY `idx_bereich_id` (`bereich_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: kassen_bereiche – Finanzmodul: Kassenbereiche
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `kassen_bereiche` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `kontrolle_only` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Nur Kassenkontrolle, nicht in Gesamtumsatz',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: kassen_sessions – Finanzmodul: Kassensitzungen (Öffnen/Schließen, Soll/Ist)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `kassen_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bereich_id` int(11) NOT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `opening_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `opened_at` datetime NOT NULL,
  `opened_by` varchar(64) NOT NULL,
  `closing_amount` decimal(10,2) DEFAULT NULL COMMENT 'Tageslosung',
  `revenue_amount` decimal(10,2) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `closed_by` varchar(64) DEFAULT NULL,
  `reopened_at` datetime DEFAULT NULL,
  `reopened_by` varchar(64) DEFAULT NULL,
  `reopen_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bereich_status` (`bereich_id`,`status`),
  KEY `idx_opened_at` (`opened_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: kassen_bewegungen – Finanzmodul: Bareinlagen, Entnahmen, Ausgaben je Kassa
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `kassen_bewegungen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `typ` enum('entnahme','zuzahlung') NOT NULL,
  `betrag` decimal(10,2) NOT NULL,
  `notiz` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: kellner_settlements – Finanzmodul: Kellner-Abrechnungen
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `kellner_settlements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kellner_login` varchar(255) NOT NULL,
  `settlement_scope` varchar(20) NOT NULL DEFAULT 'kellner' COMMENT 'kellner oder direktverkauf',
  `von_dt` datetime NOT NULL,
  `bis_dt` datetime NOT NULL,
  `umsatz_soll` decimal(10,2) NOT NULL DEFAULT 0.00,
  `betrag_abgegeben` decimal(10,2) NOT NULL DEFAULT 0.00,
  `wechselgeld_zurueck` decimal(10,2) NOT NULL DEFAULT 0.00,
  `trinkgeld` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notiz` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` varchar(64) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kellner` (`kellner_login`),
  KEY `idx_von_bis` (`von_dt`,`bis_dt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: kellner_bewegungen – Finanzmodul: Bewegungen je Kellner-Abrechnung
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `kellner_bewegungen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kellner_login` varchar(255) NOT NULL,
  `settlement_id` int(11) DEFAULT NULL,
  `typ` enum('entnahme','zuzahlung') NOT NULL,
  `betrag` decimal(10,2) NOT NULL,
  `notiz` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kellner` (`kellner_login`),
  KEY `idx_settlement` (`settlement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: mitarbeiter_bereiche – Helfer-Bereiche für die Mitarbeiterverpflegung
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `mitarbeiter_bereiche` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
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

-- ------------------------------------------
-- Tabelle: mitarbeiter_verpflegung – Helfer-Konsumationen (getrennt vom Festumsatz)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `mitarbeiter_verpflegung` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `datum` date DEFAULT NULL,
  `bereich_id` int(11) DEFAULT 0,
  `position_id` int(11) DEFAULT 0,
  `menge` int(11) NOT NULL DEFAULT 1,
  `notiz` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_datum` (`datum`),
  KEY `idx_bereich` (`bereich_id`),
  KEY `idx_position` (`position_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: settings – Einstellungen (Schlüssel/Wert)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `k` varchar(64) NOT NULL,
  `v` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
-- Tabelle: printer_jobs – Druckaufträge-Warteschlange mit Reservierung und Wiederholung
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `printer_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `printer` varchar(32) NOT NULL,
  `type` varchar(32) DEFAULT 'invoice',
  `payload` mediumtext NOT NULL,
  `meta` longtext DEFAULT NULL CHECK (json_valid(`meta`)),
  `status` enum('pending','reserved','done','error') DEFAULT 'pending',
  `attempts` int(11) DEFAULT 0,
  `reserved_at` datetime DEFAULT NULL,
  `reserved_by` varchar(64) DEFAULT NULL,
  `error` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_printer_status_id` (`printer`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: ff_login_failures – Login-Sperre gegen Passwort-Raten (include/ff_login_throttle.php)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `ff_login_failures` (
  `username` varchar(100) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `fails` int(11) NOT NULL DEFAULT 0,
  `last_fail_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`username`,`ip`),
  KEY `idx_last_fail` (`last_fail_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: ff_csrf_log – Schreibende Anfragen ohne gültiges CSRF-Token (include/ff_csrf.php)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `ff_csrf_log` (
  `tag` date NOT NULL,
  `path` varchar(190) NOT NULL,
  `via` varchar(16) NOT NULL,
  `cnt` int(11) NOT NULL DEFAULT 0,
  `blocked` int(11) NOT NULL DEFAULT 0,
  `last_at` datetime NOT NULL,
  `last_user` varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY (`tag`,`path`,`via`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: allergene – Allergene (Lebensmittelinformation)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `allergene` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`),
  KEY `idx_sort` (`sort_order`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: bestandteil_allergene – Zuordnung Bestandteil ↔ Allergen
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `bestandteil_allergene` (
  `bestandteil_id` int(11) NOT NULL,
  `allergen_id` int(11) NOT NULL,
  PRIMARY KEY (`bestandteil_id`,`allergen_id`),
  KEY `idx_allergen` (`allergen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: bestandteile – Bestandteile/Zutaten für Lager und Rezepturen
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `bestandteile` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `einheit` varchar(30) NOT NULL DEFAULT 'Stk',
  `kapazitaet` int(11) NOT NULL DEFAULT -1,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `fest_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aktiv_sort` (`aktiv`,`sort_order`),
  KEY `idx_fest_id` (`fest_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: bestellung_rezeptur_komponenten – Komponenten der Rezeptur-Snapshots
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `bestellung_rezeptur_komponenten` (
  `bestellung_rowid` int(11) NOT NULL,
  `bestandteil_id` int(11) NOT NULL,
  `name_snapshot` varchar(255) NOT NULL,
  `einheit_snapshot` varchar(30) NOT NULL DEFAULT 'Stk',
  `menge` decimal(10,3) NOT NULL DEFAULT 1.000,
  `optional_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `default_selected` tinyint(1) NOT NULL DEFAULT 1,
  `selected` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`bestellung_rowid`,`bestandteil_id`),
  KEY `idx_bestellung_rezeptur_bestandteil` (`bestandteil_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: bestellung_rezeptur_snapshots – Rezeptur-Stand je Bestellzeile (Verbrauch, Prognose)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `bestellung_rezeptur_snapshots` (
  `bestellung_rowid` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`bestellung_rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: ff_admin_messages – Admin-Meldungen/Aktivitäten (Übersicht)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `ff_admin_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fest_id` int(11) NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `url` varchar(255) NOT NULL DEFAULT './admin.php',
  `tag` varchar(191) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: ff_fest_push_rules – Push-Benachrichtigungen: Regeln je Fest
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `ff_fest_push_rules` (
  `fest_id` int(11) NOT NULL,
  `resource_type` enum('position','ingredient','station') NOT NULL,
  `resource_id` int(11) NOT NULL,
  `threshold_value` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`fest_id`,`resource_type`,`resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: ff_fest_push_settings – Push-Benachrichtigungen: Einstellungen je Fest
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `ff_fest_push_settings` (
  `fest_id` int(11) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fest_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: ff_fest_push_state – Push-Benachrichtigungen: zuletzt gemeldeter Stand
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `ff_fest_push_state` (
  `fest_id` int(11) NOT NULL,
  `resource_type` enum('position','ingredient','station') NOT NULL,
  `resource_id` int(11) NOT NULL,
  `notified` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`fest_id`,`resource_type`,`resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: ff_station_events – Ereignisse für Live-Aktualisierung der Stationen
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `ff_station_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `print_target` int(11) NOT NULL,
  `event_type` varchar(32) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_station_events_target_id` (`print_target`,`id`),
  KEY `idx_station_events_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: pilot_order_requests – Kellner-App: Idempotenz für Bestellanfragen
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `pilot_order_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_key` varchar(80) NOT NULL,
  `username` varchar(100) NOT NULL,
  `tischnummer` int(11) NOT NULL,
  `order_nr` int(11) DEFAULT NULL,
  `response_json` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pilot_order_client` (`client_key`),
  KEY `idx_pilot_order_user_table` (`username`,`tischnummer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: pilot_payment_requests – Kellner-App: Idempotenz für Zahlanfragen
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `pilot_payment_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_key` varchar(80) NOT NULL,
  `action_name` varchar(32) NOT NULL,
  `username` varchar(100) NOT NULL,
  `tischnummer` int(11) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `response_json` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pilot_payment_client` (`username`,`action_name`,`client_key`),
  KEY `idx_pilot_payment_table` (`tischnummer`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: pilot_position_links – Kellner-App: verknüpfte Artikel
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `pilot_position_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_position_id` int(11) NOT NULL,
  `child_position_id` int(11) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pilot_position_link` (`parent_position_id`,`child_position_id`),
  KEY `idx_pilot_position_link_parent` (`parent_position_id`,`sort_order`),
  KEY `idx_pilot_position_link_child` (`child_position_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: pilot_sumup_requests – Kellner-App: SumUp-Kartenzahlungen
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `pilot_sumup_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `intent_token` char(64) NOT NULL,
  `username` varchar(100) NOT NULL,
  `tischnummer` int(11) NOT NULL,
  `rowids_json` mediumtext NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `confirmed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pilot_sumup_token` (`intent_token`),
  KEY `idx_pilot_sumup_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: position_rezepturen – Rezepturen: Verkaufsartikel ↔ Bestandteile
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `position_rezepturen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `position_id` int(11) NOT NULL,
  `bestandteil_id` int(11) DEFAULT NULL,
  `menge` decimal(10,3) NOT NULL DEFAULT 1.000,
  `optional_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `default_selected` tinyint(1) NOT NULL DEFAULT 1,
  `option_group` varchar(64) NOT NULL DEFAULT '',
  `reihenfolge` int(11) NOT NULL DEFAULT 0,
  `fest_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_position` (`position_id`),
  KEY `idx_fest_id` (`fest_id`),
  KEY `idx_option_group` (`option_group`),
  KEY `idx_bestandteil` (`bestandteil_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: printer_devices – Gekoppelte Druck-Clients (Heartbeat, Freigabe)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `printer_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL,
  `pair_code` varchar(8) NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT '',
  `host` varchar(120) NOT NULL DEFAULT '',
  `service` varchar(64) NOT NULL DEFAULT '',
  `print_target` int(11) DEFAULT NULL,
  `client` varchar(64) NOT NULL DEFAULT '',
  `status` enum('pending','approved','blocked') NOT NULL DEFAULT 'pending',
  `created_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_printer_devices_token` (`token_hash`),
  KEY `idx_printer_devices_status` (`status`,`created_at`),
  KEY `idx_printer_devices_ip` (`created_ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: substation_access_tokens – Zugangs-Tokens für Stationsansichten (QR)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `substation_access_tokens` (
  `token_hash` char(64) NOT NULL,
  `station` varchar(40) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_hash`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: table_tokens – Tisch-Tokens für QR-Selbstbestellung der Gäste
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `table_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `table_number` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: user_passkeys – WebAuthn/Passkeys der Benutzer
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `user_passkeys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `credential_id` varchar(1024) NOT NULL,
  `public_key_pem` text NOT NULL,
  `sign_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `label` varchar(120) NOT NULL DEFAULT '',
  `transports` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------
-- Tabelle: webauthn_remote_requests – Passkey-Registrierung per Admin-Freigabe
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS `webauthn_remote_requests` (
  `token` varchar(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `challenge` varchar(64) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `label` varchar(120) NOT NULL DEFAULT '',
  `error` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`token`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
