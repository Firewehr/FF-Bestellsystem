<?php
/**
 * Positionen/Unterkategorien nur im Direktverkauf (Kasse, Tisch 999999).
 */
declare(strict_types=1);

require_once __DIR__ . '/ff_schema_login.php';

function ff_position_ensure_kassa_only_column(mysqli $conn): void
{
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `positionen` LIKE 'kassa_only'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        @mysqli_query(
            $conn,
            "ALTER TABLE `positionen` ADD COLUMN `kassa_only` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=nur Direktverkauf/Kasse'"
        );
    }
}

function ff_subcategory_ensure_kassa_only_column(mysqli $conn): void
{
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `position_subcategories` LIKE 'kassa_only'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        @mysqli_query(
            $conn,
            "ALTER TABLE `position_subcategories` ADD COLUMN `kassa_only` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=nur Kasse, Kellner sehen Gruppe nicht'"
        );
    }
}

function ff_position_kassa_ensure_schema(mysqli $conn): void
{
    if (ff_schema_session_ok()) {
        return;
    }
    ff_position_ensure_kassa_only_column($conn);
    ff_subcategory_ensure_kassa_only_column($conn);
}

/**
 * SQL-Fragment: Kellner/Tisch-Menüs — Position und optional Unterkategorie (Alias s).
 */
function ff_position_sql_kellner_visible(string $aliasPos = 'p', ?string $aliasSub = null): string
{
    $ap = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasPos) ?: 'p';
    $sql = ' AND COALESCE(' . $ap . '.kassa_only, 0) = 0 ';
    if ($aliasSub !== null && $aliasSub !== '') {
        $as = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasSub) ?: 's';
        $sql .= ' AND COALESCE(' . $as . '.kassa_only, 0) = 0 ';
    }
    return $sql;
}

/** Nicht in Küche/Schank/Druckziel-Stationen. */
function ff_position_sql_station_visible(string $aliasPos = 'p', ?string $aliasSub = null): string
{
    return ff_position_sql_kellner_visible($aliasPos, $aliasSub);
}

/**
 * Druckziel-Dropdown für Manage (ohne „Kein Druck“ — das übernimmt „Nur Kasse“).
 */
function ff_manage_print_target_select_options(mysqli $conn, int $selected): string
{
    $html = '';
    $res = @mysqli_query($conn, 'SELECT print_target, name FROM print_targets WHERE active = 1 ORDER BY sort_order, name');
    if ($res && mysqli_num_rows($res) > 0) {
        while ($pt = mysqli_fetch_assoc($res)) {
            $pid = (int) ($pt['print_target'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $html .= '<option value="' . $pid . '"' . ($selected === $pid ? ' selected' : '') . '>'
                . htmlspecialchars((string) ($pt['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</option>';
        }
    } else {
        $html .= '<option value="11"' . ($selected === 11 ? ' selected' : '') . '>Küche</option>';
        $html .= '<option value="12"' . ($selected === 12 ? ' selected' : '') . '>Schank</option>';
    }

    return $html;
}

function ff_position_is_kassa_only(array $row): bool
{
    return (int) ($row['kassa_only'] ?? 0) === 1
        || (int) ($row['sub_kassa_only'] ?? 0) === 1;
}

function ff_subcategory_is_kassa_only(array $row): bool
{
    return (int) ($row['kassa_only'] ?? 0) === 1;
}

/** Wenn keine JOIN auf position_subcategories (Alias z. B. nur positionen). */
function ff_position_sql_kellner_visible_no_subjoin(string $aliasPos = 'positionen'): string
{
    $ap = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasPos) ?: 'positionen';
    return ff_position_sql_kellner_visible($ap)
        . ' AND NOT EXISTS (SELECT 1 FROM position_subcategories sc WHERE sc.id = ' . $ap . '.subcategory_id AND COALESCE(sc.kassa_only, 0) = 1) ';
}
