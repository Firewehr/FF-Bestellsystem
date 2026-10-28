<?php
/**
 * Zentrale Schema-Helfer: SHOW COLUMNS / CREATE TABLE laufen pro Request nur einmal je Tabelle/Spalte.
 *
 * Plus ein Tages-Cache (Datei in include/.cache/), damit die SHOW-COLUMNS-Aufrufe
 * an mehreren Requests auf demselben Tag entfallen, sobald ein Migrationsdurchlauf erfolgreich war.
 */
declare(strict_types=1);

if (defined('FF_SCHEMA_HELPERS_LOADED')) {
    return;
}
define('FF_SCHEMA_HELPERS_LOADED', true);

if (!function_exists('ff_schema_cache_dir')) {
    function ff_schema_cache_dir(): string
    {
        $dir = __DIR__ . '/.cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }
}

if (!function_exists('ff_schema_today_flag')) {
    function ff_schema_today_flag(string $key): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key) ?: 'x';
        return ff_schema_cache_dir() . '/schema_' . date('Ymd') . '_' . $safe . '.ok';
    }
}

if (!function_exists('ff_schema_flag_is_set')) {
    function ff_schema_flag_is_set(string $key): bool
    {
        return is_file(ff_schema_today_flag($key));
    }
}

if (!function_exists('ff_schema_flag_set')) {
    function ff_schema_flag_set(string $key): void
    {
        @file_put_contents(ff_schema_today_flag($key), (string) time());
    }
}

/**
 * Stellt sicher, dass eine Spalte existiert. Pro Request je Tabelle/Spalte exakt eine Prüfung,
 * danach Datei-Flag pro Tag → an folgenden Requests gar kein SHOW COLUMNS mehr.
 */
if (!function_exists('ff_ensure_column')) {
    function ff_ensure_column(mysqli $conn, string $table, string $column, string $alterSql): void
    {
        static $done = [];
        $cacheKey = $table . '.' . $column;
        if (isset($done[$cacheKey])) {
            return;
        }
        $done[$cacheKey] = true;

        $flag = 'col_' . $cacheKey;
        if (ff_schema_flag_is_set($flag)) {
            return;
        }

        $tableEsc = mysqli_real_escape_string($conn, $table);
        $columnEsc = mysqli_real_escape_string($conn, $column);
        $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
        if (!$chk) {
            return;
        }
        if (mysqli_num_rows($chk) === 0) {
            @mysqli_query($conn, $alterSql);
        } else {
            mysqli_free_result($chk);
        }
        ff_schema_flag_set($flag);
    }
}

/**
 * Stellt sicher, dass eine Tabelle existiert. Identische Caching-Logik.
 */
if (!function_exists('ff_ensure_table')) {
    function ff_ensure_table(mysqli $conn, string $table, string $createSql): void
    {
        static $done = [];
        if (isset($done[$table])) {
            return;
        }
        $done[$table] = true;

        $flag = 'tbl_' . $table;
        if (ff_schema_flag_is_set($flag)) {
            return;
        }

        @mysqli_query($conn, $createSql);
        ff_schema_flag_set($flag);
    }
}

/**
 * Stellt sicher, dass ein Index/Key auf einer Tabelle existiert.
 *
 * $indexDef Beispiel: 'KEY `idx_tischnummer_kueche_del` (`tischnummer`,`kueche`,`delete`)'
 */
if (!function_exists('ff_ensure_index')) {
    function ff_ensure_index(mysqli $conn, string $table, string $indexName, string $indexDef): void
    {
        static $done = [];
        $cacheKey = $table . '.' . $indexName;
        if (isset($done[$cacheKey])) {
            return;
        }
        $done[$cacheKey] = true;

        $flag = 'idx_' . $cacheKey;
        if (ff_schema_flag_is_set($flag)) {
            return;
        }

        $tableEsc = mysqli_real_escape_string($conn, $table);
        $indexEsc = mysqli_real_escape_string($conn, $indexName);
        $chk = @mysqli_query($conn, "SHOW INDEX FROM `{$tableEsc}` WHERE Key_name='{$indexEsc}'");
        if (!$chk) {
            return;
        }
        if (mysqli_num_rows($chk) === 0) {
            @mysqli_query($conn, "ALTER TABLE `{$tableEsc}` ADD {$indexDef}");
        } else {
            mysqli_free_result($chk);
        }
        ff_schema_flag_set($flag);
    }
}

/**
 * Bündel-Bootstrap für die wichtigsten heißen Tabellen.
 * Nur Sachen, die für viele Pfade gebraucht werden – sonst weiterhin lokal je Modul.
 */
if (!function_exists('ff_schema_ensure_hot_paths')) {
    function ff_schema_ensure_hot_paths(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        ff_ensure_table(
            $conn,
            'print_targets',
            "CREATE TABLE IF NOT EXISTS print_targets (print_target INT(11) NOT NULL, name VARCHAR(64) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT(11) NOT NULL DEFAULT 0, PRIMARY KEY (print_target)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        ff_ensure_column(
            $conn,
            'positionen',
            'print_target',
            'ALTER TABLE positionen ADD COLUMN print_target INT(11) NOT NULL DEFAULT 11'
        );
        ff_ensure_column(
            $conn,
            'bestellungen',
            'print_target',
            'ALTER TABLE bestellungen ADD COLUMN print_target INT(11) NULL DEFAULT NULL'
        );
        ff_ensure_column(
            $conn,
            'bestellungen',
            'bon_id',
            'ALTER TABLE bestellungen ADD COLUMN bon_id VARCHAR(10) NULL DEFAULT NULL'
        );
        ff_ensure_column(
            $conn,
            'bestellungen',
            'Zusatzinfo',
            'ALTER TABLE bestellungen ADD COLUMN Zusatzinfo VARCHAR(255) NULL DEFAULT NULL'
        );
        ff_ensure_column(
            $conn,
            'bestellungen',
            'is_gratis',
            'ALTER TABLE bestellungen ADD COLUMN is_gratis TINYINT(1) NOT NULL DEFAULT 0'
        );
        ff_ensure_column(
            $conn,
            'bestellungen',
            'print_status',
            'ALTER TABLE bestellungen ADD COLUMN print_status TINYINT(1) NOT NULL DEFAULT 0'
        );
        ff_ensure_column(
            $conn,
            'bestellungen',
            'order_nr',
            'ALTER TABLE bestellungen ADD COLUMN order_nr INT(11) NULL DEFAULT NULL COMMENT \'Bestellnummer (beim Abschicken vergeben)\''
        );
        ff_ensure_column(
            $conn,
            'tische',
            'is_ehrengast',
            'ALTER TABLE tische ADD COLUMN is_ehrengast TINYINT(1) NOT NULL DEFAULT 0'
        );
        ff_ensure_column(
            $conn,
            'tische',
            'is_sammelrechnung',
            'ALTER TABLE tische ADD COLUMN is_sammelrechnung TINYINT(1) NOT NULL DEFAULT 0'
        );

        ff_ensure_index(
            $conn,
            'bestellungen',
            'idx_tischnummer_kueche_del',
            'KEY `idx_tischnummer_kueche_del` (`tischnummer`,`kueche`,`delete`)'
        );
        ff_ensure_index(
            $conn,
            'bestellungen',
            'idx_position_del',
            'KEY `idx_position_del` (`position`,`delete`)'
        );
        ff_ensure_index(
            $conn,
            'bestellungen',
            'idx_print_pipeline',
            'KEY `idx_print_pipeline` (`delete`,`kueche`,`bestellt`,`print`,`print_status`)'
        );
        ff_ensure_index(
            $conn,
            'bestellungen',
            'idx_bon_id',
            'KEY `idx_bon_id` (`bon_id`)'
        );
        ff_ensure_index(
            $conn,
            'bestellungen',
            'idx_timestamp_bezahlung',
            'KEY `idx_timestamp_bezahlung` (`timestampBezahlung`)'
        );
        ff_ensure_index(
            $conn,
            'printer_jobs',
            'idx_printer_status_id',
            'KEY `idx_printer_status_id` (`printer`,`status`,`id`)'
        );
    }
}
