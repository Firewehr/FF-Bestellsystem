-- Temporäre Sperre von Speisekarten-Positionen (wird bei erstem Aufruf auch per PHP angelegt)
-- Stand: 2026-03

CREATE TABLE IF NOT EXISTS `menu_locks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `scope` ENUM('position','type_all') NOT NULL DEFAULT 'position',
  `position_id` INT(11) NULL DEFAULT NULL,
  `menu_type` TINYINT(2) NULL DEFAULT NULL COMMENT '1=Speise, 2=Getränk bei type_all',
  `locked_until` DATETIME NULL DEFAULT NULL COMMENT 'NULL = bis manuell aufgehoben',
  `reason` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_scope_type` (`scope`, `menu_type`),
  KEY `idx_position` (`position_id`),
  KEY `idx_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_lock_exceptions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `lock_id` INT(11) NOT NULL,
  `position_id` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_lock_pos` (`lock_id`, `position_id`),
  KEY `idx_lock` (`lock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
