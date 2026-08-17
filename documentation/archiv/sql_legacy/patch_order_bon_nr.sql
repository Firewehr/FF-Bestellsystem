-- Patch: Bestellung-Nr (order_nr) und Bon-Nr (bon_nr) Support
-- Kann mehrfach ausgeführt werden (IF NOT EXISTS / ON DUPLICATE KEY).

-- 1) order_nr Spalte in bestellungen (Bestellnummer, vergeben beim Abschicken)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bestellungen' AND COLUMN_NAME = 'order_nr');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE bestellungen ADD COLUMN order_nr INT(11) NULL DEFAULT NULL COMMENT ''Bestellnummer (beim Abschicken vergeben)'', ADD KEY idx_order_nr (order_nr)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Settings: Zähler für Bestellung-Nr und Bon-Nr
INSERT INTO settings (k, v) VALUES
('order_nr_seq', '0'),
('bon_nr_seq', '0'),
('bon_nr_start', '1')
ON DUPLICATE KEY UPDATE k=k;
