-- ============================================================================
-- Patch: Finanzen / Gewinnübersicht (Einnahmen, Ausgaben, Selbstkosten)
-- ============================================================================
-- Für bestehende Datenbank ausführen. Bei "Duplicate column" ignorieren.
-- ============================================================================

SET NAMES utf8mb4;

-- ------------------------------------------
-- positionen: Selbstkosten (variable Kosten pro Stück)
-- ------------------------------------------
ALTER TABLE positionen ADD COLUMN selbstkosten DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Einkaufspreis / variable Kosten pro Stück';

-- ------------------------------------------
-- Tabelle: buchungen (Fixe Einnahmen & Ausgaben)
-- ------------------------------------------
CREATE TABLE IF NOT EXISTS buchungen (
  id INT(11) NOT NULL AUTO_INCREMENT,
  typ ENUM('einnahme','ausgabe') NOT NULL COMMENT 'einnahme=z.B. Sponsoring, ausgabe=z.B. Miete, Musik',
  bezeichnung VARCHAR(255) NOT NULL,
  betrag DECIMAL(10,2) NOT NULL,
  datum DATE NULL COMMENT 'Optional: für Tagesgewinn',
  kategorie VARCHAR(100) NULL,
  notiz TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(64) NULL,
  PRIMARY KEY (id),
  KEY idx_typ (typ),
  KEY idx_datum (datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Ende Patch
-- ============================================================================
