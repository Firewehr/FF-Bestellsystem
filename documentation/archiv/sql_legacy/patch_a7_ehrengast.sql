-- A7: Ehrengast-Tisch "abschließen" ohne Zahlung

-- MySQL/MyISAM: falls die Spalte bereits existiert, kann die Meldung ignoriert werden.
ALTER TABLE `bestellungen`
  ADD COLUMN `is_gratis` TINYINT(1) NOT NULL DEFAULT 0;
