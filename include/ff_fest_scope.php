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

require_once __DIR__ . '/ff_schema_login.php';

if (!function_exists('ff_fest_scope_ensure_columns')) {
    /**
     * Stellt die Spalte `fest_id` (NULL) auf positionen / position_subcategories / beilagen sicher.
     * Idempotent. Best-effort – Fehler werden geschluckt.
     */
    function ff_fest_scope_ensure_columns(mysqli $conn): void
    {
        if (ff_schema_session_ok()) {
            return;
        }
        static $tables = ['positionen', 'position_subcategories', 'beilagen'];
        foreach ($tables as $tbl) {
            $r = ff_mysqli_query($conn, 'SHOW TABLES LIKE ' . "'" . mysqli_real_escape_string($conn, $tbl) . "'");
            if (!$r || mysqli_num_rows($r) === 0) {
                continue;
            }
            $c = ff_mysqli_query($conn, "SHOW COLUMNS FROM `{$tbl}` LIKE 'fest_id'");
            if (!$c || mysqli_num_rows($c) === 0) {
                ff_mysqli_query($conn, "ALTER TABLE `{$tbl}` ADD COLUMN `fest_id` INT(11) NULL DEFAULT NULL");
                ff_mysqli_query($conn, "ALTER TABLE `{$tbl}` ADD KEY `idx_fest_id` (`fest_id`)");
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

        $c = ff_mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE 'fest_id'");
        if (!$c || mysqli_num_rows($c) === 0) {
            return;
        }
        require_once __DIR__ . '/settings.php';
        $fid = (int) setting_get($conn, 'current_fest_id', '0');
        if ($fid <= 0) {
            return;
        }
        // Tabellen-/Spaltenname aus fester Liste ($pkMap), Werte als Parameter
        ff_db_query($conn, "UPDATE `{$table}` SET `fest_id`=? WHERE `{$pk}`=? LIMIT 1", 'ii', [$fid, (int) $insertId]);
    }
}

if (!function_exists('ff_fest_scope_current_id')) {
    function ff_fest_scope_current_id(mysqli $conn): int
    {
        require_once __DIR__ . '/settings.php';
        $id = (int) setting_get($conn, 'current_fest_id', '0');
        if ($id > 0) {
            return $id;
        }
        $result = ff_mysqli_query($conn, 'SELECT id FROM feste WHERE aktiv = 1 ORDER BY id DESC LIMIT 1');
        $row = $result ? mysqli_fetch_assoc($result) : null;
        return (int) ($row['id'] ?? 0);
    }
}

if (!function_exists('ff_fest_scope_position_sql')) {
    function ff_fest_scope_position_sql(mysqli $conn, string $alias = 'p'): string
    {
        $safeAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'p';
        $festId = ff_fest_scope_current_id($conn);
        if ($festId <= 0) {
            return ' AND 1=0 ';
        }
        // Fest-ID als Sitzungsvariable (Prepared Statement): der Baustein enthält keinen Wert.
        // Bei jedem Aufruf setzen (nicht cachen): die Variable gilt pro Verbindung, nicht pro PHP-Request.
        $lastDb = $GLOBALS['__ff_db_last'] ?? null;
        ff_db_query($conn, 'SET @ff_scope_fest_id = ?', 'i', [$festId]);
        $GLOBALS['__ff_db_last'] = $lastDb;
        return ' AND (' . $safeAlias . '.fest_id IS NULL OR ' . $safeAlias . '.fest_id = @ff_scope_fest_id) ';
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
            $tr = ff_mysqli_query($conn, 'SHOW TABLES LIKE ' . "'" . mysqli_real_escape_string($conn, $tbl) . "'");
            if (!$tr || mysqli_num_rows($tr) === 0) {
                continue;
            }
            $c = ff_mysqli_query($conn, "SHOW COLUMNS FROM `{$tbl}` LIKE 'fest_id'");
            if (!$c || mysqli_num_rows($c) === 0) {
                continue;
            }
            ff_db_query($conn, "DELETE FROM `{$tbl}` WHERE fest_id=?", 'i', [(int) $festId]);
        }
    }
}

if (!function_exists('ff_fest_set_current')) {
    /**
     * Setzt das aktive Fest – einzige Quelle für beide Kennzeichen:
     * `feste.aktiv` (genau ein Fest = 1, von Bestellung/Zahlung/Karte gelesen) und
     * die Einstellungen `current_fest_id` / `current_fest_code` (Admin, Fest-Scope).
     */
    function ff_fest_set_current(mysqli $conn, int $festId): bool
    {
        require_once __DIR__ . '/settings.php';
        if ($festId <= 0) {
            return false;
        }
        $stmt = ff_mysqli_prepare($conn, 'SELECT code FROM feste WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'i', $festId);
        ff_mysqli_stmt_execute($stmt);
        $code = null;
        mysqli_stmt_bind_result($stmt, $code);
        $found = mysqli_stmt_fetch($stmt) === true;
        mysqli_stmt_close($stmt);
        if (!$found) {
            return false;
        }

        // Settings-Tabelle vor der Transaktion sicherstellen (DDL = implizites COMMIT)
        settings_ensure_table($conn);
        // Läuft bereits eine Transaktion (MariaDB: @@in_transaction), wird sie mitbenutzt –
        // ein erneutes BEGIN würde sie sonst stillschweigend committen.
        $outerTx = false;
        $txRes = ff_mysqli_query($conn, 'SELECT @@in_transaction');
        if ($txRes) {
            $outerTx = (int) (mysqli_fetch_row($txRes)[0] ?? 0) === 1;
            mysqli_free_result($txRes);
        }
        if (!$outerTx) {
            mysqli_begin_transaction($conn);
        }
        try {
            $upd = ff_mysqli_prepare($conn, 'UPDATE feste SET aktiv = IF(id = ?, 1, 0)');
            mysqli_stmt_bind_param($upd, 'i', $festId);
            ff_mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
            if (!setting_set($conn, 'current_fest_id', (string) $festId)
                || !setting_set($conn, 'current_fest_code', (string) $code)) {
                throw new RuntimeException('settings');
            }
            if (!$outerTx) {
                mysqli_commit($conn);
            }
            return true;
        } catch (Throwable $e) {
            if (!$outerTx) {
                mysqli_rollback($conn);
            }
            error_log('ff_fest_set_current: ' . $e->getMessage());
            return false;
        }
    }
}
