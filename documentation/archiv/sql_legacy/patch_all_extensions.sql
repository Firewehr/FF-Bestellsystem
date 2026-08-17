-- ============================================================================
-- FF Obritzberg Bestellsystem - Patch für alle Erweiterungen
-- ============================================================================
-- Dieses Script fügt alle neuen Features zu einer bestehenden Datenbank hinzu.
-- Bei "Duplicate column" Fehlern einfach ignorieren (Spalte existiert bereits).
-- ============================================================================

SET NAMES utf8mb4;

-- ============================================================================
-- 1. DRUCKZIELE (Print Targets)
-- ============================================================================

-- Tabelle für Druckziele
CREATE TABLE IF NOT EXISTS print_targets (
    print_target INT(11) NOT NULL,
    name VARCHAR(64) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (print_target)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard-Druckziele einfügen
INSERT IGNORE INTO print_targets (print_target, name, active, sort_order) VALUES 
    (11, 'Küche', 1, 10),
    (12, 'Schank', 1, 20),
    (13, 'Feuerflecken', 1, 30);

-- Positionen: Druckziel-Spalte
ALTER TABLE positionen ADD COLUMN print_target INT(11) NOT NULL DEFAULT 11;

-- Bestellungen: Druckziel-Spalte
ALTER TABLE bestellungen ADD COLUMN print_target INT(11) NULL DEFAULT NULL;
ALTER TABLE bestellungen ADD COLUMN print_status TINYINT(1) NOT NULL DEFAULT 0;

-- Bestehende Positionen aktualisieren (type 1=Küche, type 2=Schank)
UPDATE positionen SET print_target = 11 WHERE type = 1 AND (print_target IS NULL OR print_target = 0);
UPDATE positionen SET print_target = 12 WHERE type = 2 AND (print_target IS NULL OR print_target = 0);

-- ============================================================================
-- 1b. SPEISEKARTEN-SPERREN (menu_locks)
-- ============================================================================

CREATE TABLE IF NOT EXISTS menu_locks (
    id INT(11) NOT NULL AUTO_INCREMENT,
    scope ENUM('position','type_all') NOT NULL DEFAULT 'position',
    position_id INT(11) NULL DEFAULT NULL,
    menu_type TINYINT(2) NULL DEFAULT NULL COMMENT '1=Speise, 2=Getränk bei type_all',
    locked_until DATETIME NULL DEFAULT NULL COMMENT 'NULL = bis manuell aufgehoben',
    reason VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(64) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_scope_type (scope, menu_type),
    KEY idx_position (position_id),
    KEY idx_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_lock_exceptions (
    id INT(11) NOT NULL AUTO_INCREMENT,
    lock_id INT(11) NOT NULL,
    position_id INT(11) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_lock_pos (lock_id, position_id),
    KEY idx_lock (lock_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. SAMMELRECHNUNG & EHRENGAST
-- ============================================================================

-- Tische: Flags für Sammelrechnung und Ehrengast
ALTER TABLE tische ADD COLUMN is_sammelrechnung TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE tische ADD COLUMN is_ehrengast TINYINT(1) NOT NULL DEFAULT 0;

-- Bestellungen: Sammelrechnung-Verknüpfung und Gratis-Flag
ALTER TABLE bestellungen ADD COLUMN sammelrechnung_id INT(11) NULL DEFAULT NULL;
ALTER TABLE bestellungen ADD COLUMN is_gratis TINYINT(1) NOT NULL DEFAULT 0;

-- Sammelrechnungen-Tabelle
CREATE TABLE IF NOT EXISTS sammelrechnungen (
    id INT(11) NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(64) NULL,
    bezahlt TINYINT(1) NOT NULL DEFAULT 0,
    bezahlt_at TIMESTAMP NULL,
    PRIMARY KEY (id)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. RECHNUNGEN & PDF
-- ============================================================================

-- Bestellungen: Rechnungs-Verknüpfung
ALTER TABLE bestellungen ADD COLUMN rechnung_id INT(11) NULL DEFAULT NULL;

-- Rechnungen-Tabelle
CREATE TABLE IF NOT EXISTS rechnungen (
    id INT(11) NOT NULL AUTO_INCREMENT,
    rechnungsnummer VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(64) NOT NULL,
    fest_id INT(11) NULL,
    tischnummer INT(11) NULL,
    sammelrechnung_id INT(11) NULL,
    is_firma TINYINT(1) NOT NULL DEFAULT 0,
    empfaenger_name VARCHAR(255) NULL,
    empfaenger_strasse VARCHAR(255) NULL,
    empfaenger_plz VARCHAR(30) NULL,
    empfaenger_ort VARCHAR(80) NULL,
    empfaenger_uid VARCHAR(40) NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    gedruckt TINYINT(1) NOT NULL DEFAULT 0,
    druck_status VARCHAR(10) NOT NULL DEFAULT 'pending',
    druck_attempts INT(11) NOT NULL DEFAULT 0,
    druck_last_error VARCHAR(255) NULL,
    reserved_at TIMESTAMP NULL,
    reserved_by VARCHAR(64) NULL,
    PRIMARY KEY (id),
    KEY rechnungsnummer (rechnungsnummer),
    KEY fest_id (fest_id),
    KEY sammelrechnung_id (sammelrechnung_id)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. DIREKTVERKAUF MIT BON-ID
-- ============================================================================

-- Bestellungen: Bon-ID für Direktverkauf
ALTER TABLE bestellungen ADD COLUMN bon_id VARCHAR(10) NULL DEFAULT NULL;

-- Index für schnelle Bon-ID Abfragen
ALTER TABLE bestellungen ADD INDEX idx_bon_id (bon_id);

-- ============================================================================
-- 5. FESTE / VERANSTALTUNGEN (falls nicht vorhanden)
-- ============================================================================

CREATE TABLE IF NOT EXISTS feste (
    id INT(11) NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(16) NOT NULL,
    fest_datum DATE NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    ist_aktuell TINYINT(1) NOT NULL DEFAULT 0,
    payment_mode ENUM('after','instant') NOT NULL DEFAULT 'after',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY aktiv (aktiv),
    KEY ist_aktuell (ist_aktuell)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. EINSTELLUNGEN
-- ============================================================================

CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(64) NOT NULL,
    v TEXT NULL,
    PRIMARY KEY (k)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard-Einstellungen (nur wenn nicht vorhanden)
INSERT IGNORE INTO settings (k, v) VALUES 
    ('kellner_nur_eigene', '1'),
    ('fast_refresh', '0'),
    ('current_fest_id', '0'),
    ('seller_name', 'Freiwillige Feuerwehr'),
    ('seller_address', ''),
    ('seller_uid', ''),
    ('rechnung_prefix', 'R'),
    ('rechnung_festname', ''),
    ('rechnung_logo', ''),
    ('printer_token', '');

-- ============================================================================
-- 7. PERFORMANCE-INDIZES
-- ============================================================================

-- Indizes für häufige Abfragen (Fehler bei bereits existierenden ignorieren)
ALTER TABLE bestellungen ADD INDEX idx_print_target (print_target);
ALTER TABLE bestellungen ADD INDEX idx_sammelrechnung (sammelrechnung_id);
ALTER TABLE bestellungen ADD INDEX idx_rechnung (rechnung_id);
ALTER TABLE bestellungen ADD INDEX idx_tischnummer (tischnummer);
ALTER TABLE bestellungen ADD INDEX idx_kueche (kueche);
ALTER TABLE bestellungen ADD INDEX idx_delete (`delete`);

-- ============================================================================
-- FERTIG!
-- ============================================================================
-- Alle Erweiterungen wurden hinzugefügt.
-- Bitte im Admin-Bereich die Einstellungen (Rechnungsdaten, Logo, etc.) konfigurieren.
-- ============================================================================
