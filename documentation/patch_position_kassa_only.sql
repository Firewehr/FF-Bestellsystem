-- ============================================================================
-- Patch: kassa_only (Positionen + Unterkategorien, nur Direktverkauf / Kasse)
-- ============================================================================
-- Für bestehende Installationen, die vor Einführung dieser Spalte liefen.
-- Neuinstallation: bereits in include/sql.sql und
-- documentation/install_neuinstallation_complete.sql enthalten — Patch nicht nötig.
--
-- Verhalten (Anwendung):
--   kassa_only = 1 → Position nur im Direktverkauf (Tisch 999999), nicht für Kellner
--   Pflege: manage/ → „Nur Kasse“ (Spalte in Speisekarten-Verwaltung)
-- ============================================================================

ALTER TABLE `positionen`
  ADD COLUMN `kassa_only` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1=nur Direktverkauf/Kasse, nicht für Kellner'
  AFTER `tile_bg`;

ALTER TABLE `positionen`
  ADD KEY `idx_kassa_only` (`kassa_only`);

ALTER TABLE `position_subcategories`
  ADD COLUMN `kassa_only` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1=nur Kasse, Kellner sehen Gruppe nicht'
  AFTER `tile_bg`;

ALTER TABLE `position_subcategories`
  ADD KEY `idx_kassa_only` (`kassa_only`);
