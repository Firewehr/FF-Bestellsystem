<?php
/**
 * Direktverkauf Abholbon → Thermodrucker (print_target / printer_jobs).
 */

require_once __DIR__ . '/user_landing.php';

/**
 * Alle aktiven Druckziele (unabhängig von positionen — für Benutzereinstellung).
 *
 * @return list<array{print_target:int,name:string}>
 */
function ff_dv_abholbon_list_active_targets(mysqli $conn): array
{
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS print_targets (print_target INT(11) NOT NULL, name VARCHAR(64) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT(11) NOT NULL DEFAULT 0, PRIMARY KEY (print_target)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $out = [];
    $q = @mysqli_query($conn, 'SELECT print_target, name FROM print_targets WHERE active = 1 ORDER BY sort_order, name');
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $out[] = [
                'print_target' => (int)$row['print_target'],
                'name' => (string)$row['name'],
            ];
        }
    }
    if ($out === []) {
        return [
            ['print_target' => 11, 'name' => 'Küche'],
            ['print_target' => 12, 'name' => 'Schank'],
        ];
    }
    return $out;
}

function ff_dv_abholbon_target_is_valid(mysqli $conn, int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS print_targets (print_target INT(11) NOT NULL, name VARCHAR(64) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT(11) NOT NULL DEFAULT 0, PRIMARY KEY (print_target)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = mysqli_prepare($conn, 'SELECT 1 FROM print_targets WHERE print_target = ? AND active = 1 LIMIT 1');
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $ok = $res && mysqli_fetch_row($res);
    mysqli_stmt_close($st);
    return (bool)$ok;
}

/**
 * Fallback, falls weder 11 noch 12 in print_targets existieren.
 */
function ff_dv_abholbon_fallback_target_id(mysqli $conn): int
{
    $list = ff_dv_abholbon_list_active_targets($conn);
    if ($list === []) {
        return 11;
    }
    foreach ($list as $row) {
        if ((int)$row['print_target'] === 12) {
            return 12;
        }
    }
    foreach ($list as $row) {
        if ((int)$row['print_target'] === 11) {
            return 11;
        }
    }
    return (int)$list[0]['print_target'];
}

function ff_dv_abholbon_default_from_start(mysqli $conn, string $startPage, ?int $startPrintTarget): int
{
    $page = ff_user_normalize_start_page($startPage);
    switch ($page) {
        case 'kueche':
            return ff_dv_abholbon_target_is_valid($conn, 11) ? 11 : ff_dv_abholbon_fallback_target_id($conn);
        case 'schank':
            return ff_dv_abholbon_target_is_valid($conn, 12) ? 12 : ff_dv_abholbon_fallback_target_id($conn);
        case 'print_target':
            $pt = (int)$startPrintTarget;
            return ff_dv_abholbon_target_is_valid($conn, $pt) ? $pt : ff_dv_abholbon_fallback_target_id($conn);
        default:
            return ff_dv_abholbon_target_is_valid($conn, 12) ? 12 : ff_dv_abholbon_fallback_target_id($conn);
    }
}

/**
 * @param int|null $dvSaved users.dv_abholbon_print_target (NULL = automatisch)
 */
function ff_user_resolve_dv_abholbon_print_target(mysqli $conn, ?int $dvSaved, string $startPage, ?int $startPrintTarget): int
{
    if ($dvSaved !== null && $dvSaved > 0 && ff_dv_abholbon_target_is_valid($conn, $dvSaved)) {
        return $dvSaved;
    }
    return ff_dv_abholbon_default_from_start($conn, $startPage, $startPrintTarget);
}

/**
 * Label für die automatische Zuordnung (Anzeige).
 */
function ff_dv_abholbon_auto_label(mysqli $conn, string $startPage, ?int $startPrintTarget): string
{
    $id = ff_dv_abholbon_default_from_start($conn, $startPage, $startPrintTarget);
    $name = '';
    $st = mysqli_prepare($conn, 'SELECT name FROM print_targets WHERE print_target = ? LIMIT 1');
    if ($st) {
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        if ($res && ($r = mysqli_fetch_assoc($res))) {
            $name = (string)$r['name'];
        }
        mysqli_stmt_close($st);
    }
    if ($name === '') {
        require_once __DIR__ . '/ff_print_target_labels.php';
        return ff_print_target_display_name($conn, $id);
    }
    return $name;
}
