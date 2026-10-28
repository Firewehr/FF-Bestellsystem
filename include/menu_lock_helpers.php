<?php
/**
 * Temporäre Sperre von Speisekarten-Positionen (Einzeln oder ganze Kategorie mit Ausnahmen).
 */

declare(strict_types=1);

function menu_lock_ensure_tables(mysqli $conn): void
{
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `menu_locks` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `menu_lock_exceptions` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `lock_id` INT(11) NOT NULL,
      `position_id` INT(11) NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `ux_lock_pos` (`lock_id`, `position_id`),
      KEY `idx_lock` (`lock_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function menu_lock_cleanup_expired(mysqli $conn): void
{
    menu_lock_ensure_tables($conn);
    @mysqli_query($conn, "DELETE e FROM `menu_lock_exceptions` e
        INNER JOIN `menu_locks` l ON l.id = e.lock_id
        WHERE l.locked_until IS NOT NULL AND l.locked_until <= NOW()");
    @mysqli_query($conn, "DELETE FROM `menu_locks` WHERE locked_until IS NOT NULL AND locked_until <= NOW()");
}

/**
 * @return array|null null = nicht gesperrt, sonst ['reason','until_raw','until_label']
 */
function menu_lock_get_status(mysqli $conn, int $positionId, int $positionType): ?array
{
    menu_lock_cleanup_expired($conn);

    $pid = (int)$positionId;
    $ptype = (int)$positionType;

    $sql = "SELECT id, reason, locked_until FROM menu_locks
            WHERE scope='position' AND position_id={$pid}
              AND (locked_until IS NULL OR locked_until > NOW()) LIMIT 1";
    $res = mysqli_query($conn, $sql);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        return menu_lock_format_row($row);
    }

    $sql2 = "SELECT l.id, l.reason, l.locked_until FROM menu_locks l
             WHERE l.scope='type_all' AND l.menu_type={$ptype}
               AND (l.locked_until IS NULL OR l.locked_until > NOW()) LIMIT 1";
    $res2 = mysqli_query($conn, $sql2);
    if ($res2 && ($lock = mysqli_fetch_assoc($res2))) {
        $lid = (int)$lock['id'];
        $ex = mysqli_query($conn, "SELECT 1 FROM menu_lock_exceptions WHERE lock_id={$lid} AND position_id={$pid} LIMIT 1");
        if ($ex && mysqli_fetch_row($ex)) {
            return null;
        }
        return menu_lock_format_row($lock);
    }

    return null;
}

function menu_lock_format_row(array $row): array
{
    $untilRaw = $row['locked_until'] ?? null;
    $label = 'bis auf Weiteres';
    if ($untilRaw && $untilRaw !== '0000-00-00 00:00:00') {
        $ts = strtotime((string)$untilRaw);
        if ($ts) {
            $label = 'bis ' . date('d.m.Y H:i', $ts) . ' Uhr';
        }
    }
    return [
        'reason' => (string)($row['reason'] ?? ''),
        'until_raw' => $untilRaw,
        'until_label' => $label,
    ];
}

function menu_lock_is_blocked(mysqli $conn, int $positionId, int $positionType): bool
{
    return menu_lock_get_status($conn, $positionId, $positionType) !== null;
}
