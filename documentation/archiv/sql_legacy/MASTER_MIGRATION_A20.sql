-- MASTER MIGRATION A20 (MySQL) - idempotent style
-- Ziel: alle benoetigten Tabellen/Spalten fuer die A1-A19 Features anlegen.
-- Hinweis: Diese Datei ist fuer MySQL/MariaDB gedacht (bplaced/phpMyAdmin).

SET sql_mode = 'NO_ENGINE_SUBSTITUTION';

-- 1) settings (Key/Value)
CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(128) PRIMARY KEY,
  v MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default settings (only insert if missing)
INSERT IGNORE INTO settings (k,v) VALUES
('FAST_REFRESH','0'),
('PRINTER_TOKEN',''),
('PRINTER_WARN_AFTER_SEC','60'),
('PRINTER_MAX_ATTEMPTS','5'),
('SELLER_NAME',''),
('SELLER_ADDRESS',''),
('SELLER_UID',''),
('INVOICE_PREFIX','FF'),
('CURRENT_FEST_CODE','');

-- 2) printer_jobs (Queue)
CREATE TABLE IF NOT EXISTS printer_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  printer VARCHAR(32) NOT NULL,
  type VARCHAR(32) DEFAULT 'invoice',
  payload MEDIUMTEXT NOT NULL,
  meta JSON NULL,
  status ENUM('pending','reserved','done','error') DEFAULT 'pending',
  attempts INT DEFAULT 0,
  reserved_at DATETIME NULL,
  reserved_by VARCHAR(64) NULL,
  error TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) feste (Events)
CREATE TABLE IF NOT EXISTS feste (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  code VARCHAR(32) NOT NULL,
  datum DATE NULL,
  aktiv TINYINT(1) DEFAULT 1,
  is_current TINYINT(1) DEFAULT 0,
  payment_mode ENUM('after','instant') DEFAULT 'after',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) sammelrechnungen
CREATE TABLE IF NOT EXISTS sammelrechnungen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(128) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5) bestellungen Erweiterungen (best-effort ALTER; ignore if already exists)
ALTER TABLE bestellungen ADD COLUMN is_gratis TINYINT(1) DEFAULT 0;
ALTER TABLE bestellungen ADD COLUMN sammelrechnung_id INT NULL;
ALTER TABLE bestellungen ADD INDEX idx_sammelrechnung_id (sammelrechnung_id);
ALTER TABLE bestellungen ADD COLUMN kellner_abgerechnet TINYINT(1) DEFAULT 0;
ALTER TABLE bestellungen ADD COLUMN timestampKellnerAbrechnung DATETIME NULL;
ALTER TABLE bestellungen ADD COLUMN kellnerAbgerechnetVon VARCHAR(128) NULL;

-- 6) Meta (optional)
CREATE TABLE IF NOT EXISTS bestellung_meta (
  bestellung_id INT NOT NULL,
  fest_id INT NULL,
  sofortzahlung TINYINT(1) DEFAULT 0,
  PRIMARY KEY (bestellung_id),
  KEY idx_fest_id (fest_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7) Tisch-Flags (optional, falls Tabelle existiert; kann fehlschlagen)
ALTER TABLE tische ADD COLUMN is_sammelrechnung TINYINT(1) DEFAULT 0;
ALTER TABLE tische ADD COLUMN is_ehrengast TINYINT(1) DEFAULT 0;

-- DONE
