-- Finanzbereiche: Buchungen + Druckziel-Zuordnung (bestehende Installation)
-- Bereiche selbst: Tabelle kassen_bereiche (Tab Finanzen → Kassen)

ALTER TABLE `buchungen`
  ADD COLUMN `bereich_id` INT(11) NULL DEFAULT NULL COMMENT 'Finanzbereich (kassen_bereiche)' AFTER `notiz`;

ALTER TABLE `buchungen`
  ADD KEY `idx_bereich_id` (`bereich_id`);

ALTER TABLE `print_targets`
  ADD COLUMN `finance_bereich_id` INT(11) NULL DEFAULT NULL COMMENT 'Finanzbereich für Verkaufsauswertung' AFTER `sort_order`;
