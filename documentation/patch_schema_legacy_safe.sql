-- ============================================================================
-- Upgrade laufende Installation (idempotent, mehrfach ausführbar)
-- ============================================================================
-- Nur wenn Tabellen aus einer älteren Version stammen und Spalten fehlen.
-- Keine Fehler #1060: fehlende Spalten werden ergänzt, vorhandene übersprungen.
--
-- NICHT verwenden für leere Neuinstallation → install_neuinstallation_complete.sql
-- ============================================================================

SET NAMES utf8mb4;

-- Hilfsmakro: Spalte nur anlegen wenn sie fehlt
-- (pro Spalte ein Block — kompatibel mit MariaDB/MySQL in phpMyAdmin)

-- sammelrechnungen.created_at
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sammelrechnungen' AND COLUMN_NAME = 'created_at');
SET @sql = IF(@c = 0, 'ALTER TABLE `sammelrechnungen` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sammelrechnungen' AND COLUMN_NAME = 'created_by');
SET @sql = IF(@c = 0, 'ALTER TABLE `sammelrechnungen` ADD COLUMN `created_by` VARCHAR(255) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sammelrechnungen' AND COLUMN_NAME = 'umsatz_zustaendig');
SET @sql = IF(@c = 0, 'ALTER TABLE `sammelrechnungen` ADD COLUMN `umsatz_zustaendig` VARCHAR(255) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sammelrechnungen' AND COLUMN_NAME = 'tables_text');
SET @sql = IF(@c = 0, 'ALTER TABLE `sammelrechnungen` ADD COLUMN `tables_text` TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sammelrechnungen' AND COLUMN_NAME = 'total_amount');
SET @sql = IF(@c = 0, 'ALTER TABLE `sammelrechnungen` ADD COLUMN `total_amount` DECIMAL(10,2) NULL DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sammelrechnungen' AND COLUMN_NAME = 'bezahlt');
SET @sql = IF(@c = 0, 'ALTER TABLE `sammelrechnungen` ADD COLUMN `bezahlt` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sammelrechnungen' AND COLUMN_NAME = 'bezahlt_at');
SET @sql = IF(@c = 0, 'ALTER TABLE `sammelrechnungen` ADD COLUMN `bezahlt_at` TIMESTAMP NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sammelrechnungen' AND COLUMN_NAME = 'fest_id');
SET @sql = IF(@c = 0, 'ALTER TABLE `sammelrechnungen` ADD COLUMN `fest_id` INT(11) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sammelrechnungen' AND COLUMN_NAME = 'tischnummer');
SET @sql = IF(@c = 0, 'ALTER TABLE `sammelrechnungen` ADD COLUMN `tischnummer` INT(11) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sammelrechnungen' AND COLUMN_NAME = 'betrag');
SET @sql = IF(@c = 0, 'ALTER TABLE `sammelrechnungen` ADD COLUMN `betrag` DECIMAL(10,2) NULL DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sammelrechnungen' AND COLUMN_NAME = 'name');
SET @sql = IF(@c = 0, 'ALTER TABLE `sammelrechnungen` ADD COLUMN `name` VARCHAR(100) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- bestellungen.sammelrechnung_id + Index
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bestellungen' AND COLUMN_NAME = 'sammelrechnung_id');
SET @sql = IF(@c = 0,
  'ALTER TABLE `bestellungen` ADD COLUMN `sammelrechnung_id` INT(11) NULL DEFAULT NULL COMMENT ''Verknüpfung zur Sammelrechnung''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bestellungen' AND INDEX_NAME = 'idx_sammelrechnung_id');
SET @sql = IF(@c = 0,
  'ALTER TABLE `bestellungen` ADD KEY `idx_sammelrechnung_id` (`sammelrechnung_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ende — bei Erfolg keine Meldung nötig; fehlende Spalten sind danach vorhanden.
