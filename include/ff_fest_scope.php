<?php
/**
 * Hybrid-Fest-Scoping für Speisekarte (positionen, position_subcategories, beilagen).
 *
 * Idee:
 * - Neue Datensätze bekommen automatisch fest_id = current_fest_id (sofern aktives Fest).
 * - Bestehende Datensätze ohne fest_id (NULL) bleiben „global“ und werden überall sichtbar – sie werden
 *   beim Fest-Löschen NICHT angefasst.
 * - Beim Löschen eines Fests werden alle Datensätze mit dieser fest_id mitgelöscht (positionen, Subkategorien, Beilagen).
 *
 * Reads bleiben bewusst unverändert (Hybrid-Mode), damit das System weiterhin „eine Speisekarte pro
 * laufendem Fest“ zeigt – im Normalfall mit nur einem aktiven Fest entspricht das genau der Erwartung.
 */

if (!function_exists('ff_fest_scope_ensure_columns')) {
    /**
     * Stellt die Spalte `fest_id` (NULL) auf positionen / position_subcategories / beilagen sicher.
     * Idempotent. Best-effort – Fehler werden geschluckt.
     */
    function ff_fest_scope_ensure_columns(mysqli $conn): void
    {
        static $tables = ['positionen', 'position_subcategories', 'beilagen'];
        foreach ($tables as $tbl) {
            $r = @mysqli_query($conn, 'SHOW TABLES LIKE ' . "'" . mysqli_real_escape_string($conn, $tbl) . "'");
            if (!$r || mysqli_num_rows($r) === 0) {
                continue;
            }
            $c = @mysqli_query($conn, "SHOW COLUMNS FROM `{$tbl}` LIKE 'fest_id'");
            if (!$c || mysqli_num_rows($c) === 0) {
                @mysqli_query($conn, "ALTER TABLE `{$tbl}` ADD COLUMN `fest_id` INT(11) NULL DEFAULT NULL");
                @mysqli_query($conn, "ALTER TABLE `{$tbl}` ADD KEY `idx_fest_id` (`fest_id`)");
            }
        }
    }
}

if (!function_exists('ff_fest_scope_attach_last')) {
    /**
     * Setzt fest_id = current_fest_id auf den frisch eingefügten Datensatz, wenn:
     * - die Tabelle einen fest_id-Spalte hat,
     * - ein aktives Fest gewählt ist (current_fest_id > 0).
     *
     * @param string $table Eine von: positionen, position_subcategories, beilagen
     * @param int $insertId AUTO_INCREMENT Wert (z. B. mysqli_insert_id())
     */
    function ff_fest_scope_attach_last(mysqli $conn, string $table, int $insertId): void
    {
        if ($insertId <= 0) {
            return;
        }
        // Primary-Key je Tabelle (laut Schema)
        $pkMap = [
            'positionen' => 'rowid',
            'position_subcategories' => 'id',
            'beilagen' => 'rowid',
        ];
        if (!isset($pkMap[$table])) {
            return;
        }
        $pk = $pkMap[$table];

        $c = @mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE 'fest_id'");
        if (!$c || mysqli_num_rows($c) === 0) {
            return;
        }
        require_once __DIR__ . '/settings.php';
        $fid = (int) setting_get($conn, 'current_fest_id', '0');
        if ($fid <= 0) {
            return;
        }
        @mysqli_query($conn, "UPDATE `{$table}` SET `fest_id`={$fid} WHERE `{$pk}`=" . (int) $insertId . ' LIMIT 1');
    }
}

if (!function_exists('ff_fest_scope_delete_for_fest')) {
    /**
     * Löscht alle Speisekarten-Datensätze, die diesem Fest exklusiv zugeordnet sind.
     * Globale Datensätze (fest_id IS NULL) bleiben erhalten.
     */
    function ff_fest_scope_delete_for_fest(mysqli $conn, int $festId): void
    {
        if ($festId <= 0) {
            return;
        }
        static $tables = ['beilagen', 'positionen', 'position_subcategories'];
        foreach ($tables as $tbl) {
            $tr = @mysqli_query($conn, 'SHOW TABLES LIKE ' . "'" . mysqli_real_escape_string($conn, $tbl) . "'");
            if (!$tr || mysqli_num_rows($tr) === 0) {
                continue;
            }
            $c = @mysqli_query($conn, "SHOW COLUMNS FROM `{$tbl}` LIKE 'fest_id'");
            if (!$c || mysqli_num_rows($c) === 0) {
                continue;
            }
            @mysqli_query($conn, "DELETE FROM `{$tbl}` WHERE fest_id=" . (int) $festId);
        }
    }
}
