-- Print Targets: Druckziele verwalten, Bestellungen pro Ziel anzeigen
-- Nach dem Patch: Menüpunkte in index.php pro Druckziel, list_druckziel.php filtert danach.
-- Bei "Duplicate column" Fehlern: Spalte existiert bereits, ignorieren.

-- Tabelle Druckziele (falls nicht vorhanden)
CREATE TABLE IF NOT EXISTS print_targets (
  print_target INT(11) NOT NULL,
  name VARCHAR(64) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (print_target)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO print_targets (print_target, name, active, sort_order) VALUES (11, 'Küche', 1, 10);
INSERT IGNORE INTO print_targets (print_target, name, active, sort_order) VALUES (12, 'Schank', 1, 20);

-- positionen: welches Druckziel für diese Position (11=Küche, 12=Schank)
ALTER TABLE positionen ADD COLUMN print_target INT(11) NOT NULL DEFAULT 11;

-- Bestellungen: print_target bei jeder Bestellung mitspeichern
ALTER TABLE bestellungen ADD COLUMN print_target INT(11) NULL DEFAULT NULL;

-- Bestehende positionen: type 1 -> Küche(11), type 2 -> Schank(12)
UPDATE positionen SET print_target = 12 WHERE type = 2;
