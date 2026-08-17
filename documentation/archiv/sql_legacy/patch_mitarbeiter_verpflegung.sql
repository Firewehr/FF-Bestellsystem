-- ============================================================================
-- Patch: Mitarbeiter-Verpflegung (Dokumentation Essen/Getränke für Helfer)
-- ============================================================================
-- Für bestehende Datenbank. Bei "Duplicate column" / "already exists" ignorieren.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mitarbeiter_bereiche (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(64) NOT NULL,
  sort_order INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mitarbeiter_bereiche (name, sort_order) VALUES
('Küche', 10),
('Schank', 20),
('Kellner', 30),
('Komando', 40),
('Jugendfeuerwehr', 50),
('Sonstige', 99);

CREATE TABLE IF NOT EXISTS mitarbeiter_verpflegung (
  id INT(11) NOT NULL AUTO_INCREMENT,
  datum DATE NOT NULL,
  bereich_id INT(11) NOT NULL,
  position_id INT(11) NOT NULL,
  menge INT(11) NOT NULL DEFAULT 1,
  notiz VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(64) NULL,
  PRIMARY KEY (id),
  KEY idx_datum (datum),
  KEY idx_bereich (bereich_id),
  KEY idx_position (position_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Ende Patch
-- ============================================================================
