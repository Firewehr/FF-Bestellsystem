<?php
/**
 * Schema: Finanzverwaltung (Kassen, Kellnerabrechnung, can_finance).
 */
declare(strict_types=1);

require_once __DIR__ . '/user_landing.php';

function ff_finance_table_exists(mysqli $conn, string $table): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') {
        return false;
    }
    $tEsc = mysqli_real_escape_string($conn, $table);
    $res = @mysqli_query($conn, "SHOW TABLES LIKE '{$tEsc}'");
    if (!$res) {
        return false;
    }
    $exists = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $exists;
}

function ff_finance_table_index_exists(mysqli $conn, string $table, string $indexName): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $indexName = preg_replace('/[^a-zA-Z0-9_]/', '', $indexName);
    if ($table === '' || $indexName === '') {
        return false;
    }
    $idxEsc = mysqli_real_escape_string($conn, $indexName);
    $res = mysqli_query($conn, "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$idxEsc}'");
    if (!$res) {
        return false;
    }
    $exists = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $exists;
}

if (function_exists('mysqli_report')) {
    @mysqli_report(MYSQLI_REPORT_OFF);
}

function ff_finance_ensure_undo_columns(mysqli $conn): void
{
    static $undoDone = false;
    if ($undoDone) {
        return;
    }
    $undoDone = true;

    try {
        if (ff_finance_table_exists($conn, 'kellner_settlements')) {
            $settleCols = [
                'voided_at' => 'ADD COLUMN `voided_at` DATETIME NULL DEFAULT NULL',
                'voided_by' => 'ADD COLUMN `voided_by` VARCHAR(64) NULL DEFAULT NULL',
                'void_reason' => 'ADD COLUMN `void_reason` VARCHAR(255) NULL DEFAULT NULL',
            ];
            foreach ($settleCols as $col => $alter) {
                $c = @mysqli_query($conn, "SHOW COLUMNS FROM `kellner_settlements` LIKE '{$col}'");
                if ($c && mysqli_num_rows($c) === 0) {
                    try {
                        @mysqli_query($conn, "ALTER TABLE `kellner_settlements` {$alter}");
                    } catch (\Throwable $e) {}
                }
                if ($c) {
                    @mysqli_free_result($c);
                }
            }
        }

        if (ff_finance_table_exists($conn, 'kassen_sessions')) {
            $kasseCols = [
                'reopened_at' => 'ADD COLUMN `reopened_at` DATETIME NULL DEFAULT NULL',
                'reopened_by' => 'ADD COLUMN `reopened_by` VARCHAR(64) NULL DEFAULT NULL',
                'reopen_reason' => 'ADD COLUMN `reopen_reason` VARCHAR(255) NULL DEFAULT NULL',
            ];
            foreach ($kasseCols as $col => $alter) {
                $c = @mysqli_query($conn, "SHOW COLUMNS FROM `kassen_sessions` LIKE '{$col}'");
                if ($c && mysqli_num_rows($c) === 0) {
                    try {
                        @mysqli_query($conn, "ALTER TABLE `kassen_sessions` {$alter}");
                    } catch (\Throwable $e) {}
                }
                if ($c) {
                    @mysqli_free_result($c);
                }
            }
        }
    } catch (\Throwable $e) {}
}

/** Spalte kontrolle_only (Kassenabschluss ohne Doppelzählung im Gesamtumsatz). */
/** settlement_scope auf kellner_settlements (kellner | direktverkauf). */
function ff_finance_ensure_settlement_scope_column(mysqli $conn): void
{
    static $colDone = false;
    if ($colDone) {
        return;
    }
    $colDone = true;
    if (!ff_finance_table_exists($conn, 'kellner_settlements')) {
        return;
    }
    try {
        // Prüfen, ob kellner_login existiert oder die Tabelle repariert werden muss
        $cLogin = @mysqli_query($conn, "SHOW COLUMNS FROM `kellner_settlements` LIKE 'kellner_login'");
        $hasLogin = ($cLogin && mysqli_num_rows($cLogin) > 0);
        if ($cLogin) @mysqli_free_result($cLogin);

        if (!$hasLogin) {
            $cntRes = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM `kellner_settlements`");
            $rCount = $cntRes ? (int)(mysqli_fetch_assoc($cntRes)['c'] ?? 0) : 0;
            if ($cntRes) @mysqli_free_result($cntRes);

            if ($rCount === 0) {
                @mysqli_query($conn, "DROP TABLE `kellner_settlements`");
                @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `kellner_settlements` (
                  `id` INT(11) NOT NULL AUTO_INCREMENT,
                  `kellner_login` VARCHAR(255) NOT NULL,
                  `settlement_scope` VARCHAR(20) NOT NULL DEFAULT 'kellner' COMMENT 'kellner oder direktverkauf',
                  `von_dt` DATETIME NOT NULL,
                  `bis_dt` DATETIME NOT NULL,
                  `umsatz_soll` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                  `betrag_abgegeben` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                  `wechselgeld_zurueck` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                  `trinkgeld` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                  `notiz` VARCHAR(255) NULL DEFAULT NULL,
                  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `created_by` VARCHAR(64) NULL DEFAULT NULL,
                  `voided_at` DATETIME NULL DEFAULT NULL,
                  `voided_by` VARCHAR(64) NULL DEFAULT NULL,
                  `void_reason` VARCHAR(255) NULL DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `idx_kellner` (`kellner_login`),
                  KEY `idx_von_bis` (`von_dt`, `bis_dt`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                return;
            } else {
                $cK = @mysqli_query($conn, "SHOW COLUMNS FROM `kellner_settlements` LIKE 'kellner'");
                if ($cK && mysqli_num_rows($cK) > 0) {
                    @mysqli_query($conn, "ALTER TABLE `kellner_settlements` CHANGE COLUMN `kellner` `kellner_login` VARCHAR(255) NOT NULL");
                    $hasLogin = true;
                } else {
                    @mysqli_query($conn, "ALTER TABLE `kellner_settlements` ADD COLUMN `kellner_login` VARCHAR(255) NOT NULL DEFAULT '' AFTER `id`");
                    $hasLogin = true;
                }
                if ($cK) @mysqli_free_result($cK);
            }
        }

        $c = @mysqli_query($conn, "SHOW COLUMNS FROM `kellner_settlements` LIKE 'settlement_scope'");
        if ($c && mysqli_num_rows($c) === 0) {
            $afterClause = $hasLogin ? " AFTER `kellner_login`" : "";
            @mysqli_query(
                $conn,
                "ALTER TABLE `kellner_settlements` ADD COLUMN `settlement_scope` VARCHAR(20) NOT NULL DEFAULT 'kellner'"
                . " COMMENT 'kellner oder direktverkauf'{$afterClause}"
            );
        }
        if ($c) {
            @mysqli_free_result($c);
        }
    } catch (\Throwable $e) {
        // Ignorieren falls nicht möglich
    }
}

function ff_finance_ensure_kontrolle_only_column(mysqli $conn): void
{
    static $colDone = false;
    if ($colDone) {
        return;
    }
    $colDone = true;
    if (!ff_finance_table_exists($conn, 'kassen_bereiche')) {
        return;
    }
    try {
        $cAct = @mysqli_query($conn, "SHOW COLUMNS FROM `kassen_bereiche` LIKE 'is_active'");
        if ($cAct && mysqli_num_rows($cAct) === 0) {
            @mysqli_query($conn, "ALTER TABLE `kassen_bereiche` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `name`");
        }
        if ($cAct) {
            @mysqli_free_result($cAct);
        }

        $c = @mysqli_query($conn, "SHOW COLUMNS FROM `kassen_bereiche` LIKE 'kontrolle_only'");
        if ($c && mysqli_num_rows($c) === 0) {
            @mysqli_query(
                $conn,
                "ALTER TABLE `kassen_bereiche` ADD COLUMN `kontrolle_only` TINYINT(1) NOT NULL DEFAULT 0"
                . " COMMENT 'Nur Kassenkontrolle, nicht in Gesamtumsatz'"
            );
        }
        if ($c) {
            @mysqli_free_result($c);
        }
    } catch (\Throwable $e) {
        // Ignorieren falls nicht möglich
    }
}

function ff_finance_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (function_exists('mysqli_report')) {
        @mysqli_report(MYSQLI_REPORT_OFF);
    }

    try {
        ff_users_ensure_landing_columns($conn);
    } catch (\Throwable $e) {}

    if (function_exists('ff_schema_flag_is_set') && ff_schema_flag_is_set('finance_schema')) {
        ff_finance_ensure_undo_columns($conn);
        ff_finance_ensure_settlement_scope_column($conn);
        ff_finance_ensure_kontrolle_only_column($conn);
        return;
    }

    try {
        $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'can_finance'");
        if ($chk && mysqli_num_rows($chk) === 0) {
            @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN `can_finance` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Finanzverwaltung'");
        }
        if ($chk) {
            @mysqli_free_result($chk);
        }
    } catch (\Throwable $e) {}

    try {
        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `kassen_bereiche` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `name` VARCHAR(128) NOT NULL,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `kontrolle_only` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Nur Kassenkontrolle, nicht in Gesamtumsatz',
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `kassen_sessions` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `bereich_id` INT(11) NOT NULL,
          `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
          `opening_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `opened_at` DATETIME NOT NULL,
          `opened_by` VARCHAR(64) NOT NULL,
          `closing_amount` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Tageslosung',
          `revenue_amount` DECIMAL(10,2) NULL DEFAULT NULL,
          `closed_at` DATETIME NULL DEFAULT NULL,
          `closed_by` VARCHAR(64) NULL DEFAULT NULL,
          `reopened_at` DATETIME NULL DEFAULT NULL,
          `reopened_by` VARCHAR(64) NULL DEFAULT NULL,
          `reopen_reason` VARCHAR(255) NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_bereich_status` (`bereich_id`, `status`),
          KEY `idx_opened_at` (`opened_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `kassen_bewegungen` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `session_id` INT(11) NOT NULL,
          `typ` ENUM('entnahme','zuzahlung') NOT NULL,
          `betrag` DECIMAL(10,2) NOT NULL,
          `notiz` VARCHAR(255) NULL DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `created_by` VARCHAR(64) NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_session` (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `kellner_settlements` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `kellner_login` VARCHAR(255) NOT NULL,
          `settlement_scope` VARCHAR(20) NOT NULL DEFAULT 'kellner' COMMENT 'kellner oder direktverkauf',
          `von_dt` DATETIME NOT NULL,
          `bis_dt` DATETIME NOT NULL,
          `umsatz_soll` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `betrag_abgegeben` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `wechselgeld_zurueck` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `trinkgeld` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `notiz` VARCHAR(255) NULL DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `created_by` VARCHAR(64) NULL DEFAULT NULL,
          `voided_at` DATETIME NULL DEFAULT NULL,
          `voided_by` VARCHAR(64) NULL DEFAULT NULL,
          `void_reason` VARCHAR(255) NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_kellner` (`kellner_login`),
          KEY `idx_von_bis` (`von_dt`, `bis_dt`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `kellner_bewegungen` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `kellner_login` VARCHAR(255) NOT NULL,
          `settlement_id` INT(11) NULL DEFAULT NULL,
          `typ` ENUM('entnahme','zuzahlung') NOT NULL,
          `betrag` DECIMAL(10,2) NOT NULL,
          `notiz` VARCHAR(255) NULL DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `created_by` VARCHAR(64) NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_kellner` (`kellner_login`),
          KEY `idx_settlement` (`settlement_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $e) {}

    try {
        $cols = [
            'settlement_id' => "ADD COLUMN `settlement_id` INT(11) NULL DEFAULT NULL",
            'settled_at' => "ADD COLUMN `settled_at` DATETIME NULL DEFAULT NULL",
            'settled_by' => "ADD COLUMN `settled_by` VARCHAR(64) NULL DEFAULT NULL",
        ];
        foreach ($cols as $col => $alter) {
            $c = @mysqli_query($conn, "SHOW COLUMNS FROM `bestellungen` LIKE '{$col}'");
            if ($c && mysqli_num_rows($c) === 0) {
                try {
                    @mysqli_query($conn, "ALTER TABLE `bestellungen` {$alter}");
                } catch (\Throwable $e) {}
            }
            if ($c) {
                @mysqli_free_result($c);
            }
        }
        if (!ff_finance_table_index_exists($conn, 'bestellungen', 'idx_settlement_id')) {
            try {
                @mysqli_query($conn, 'ALTER TABLE `bestellungen` ADD KEY `idx_settlement_id` (`settlement_id`)');
            } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {}

    // Sicherstellen, dass die Basisfelder in buchungen vorhanden sind
    try {
        $chkNotiz = @mysqli_query($conn, "SHOW COLUMNS FROM `buchungen` LIKE 'notiz'");
        if ($chkNotiz && mysqli_num_rows($chkNotiz) === 0) {
            try {
                @mysqli_query($conn, "ALTER TABLE `buchungen` ADD COLUMN `notiz` TEXT NULL");
            } catch (\Throwable $e) {}
        }
        if ($chkNotiz) {
            @mysqli_free_result($chkNotiz);
        }

        $chkTyp = @mysqli_query($conn, "SHOW COLUMNS FROM `buchungen` LIKE 'typ'");
        if ($chkTyp && mysqli_num_rows($chkTyp) === 0) {
            try {
                @mysqli_query($conn, "ALTER TABLE `buchungen` ADD COLUMN `typ` ENUM('einnahme','ausgabe') NOT NULL DEFAULT 'ausgabe' AFTER `id`");
            } catch (\Throwable $e) {}
        }
        if ($chkTyp) {
            @mysqli_free_result($chkTyp);
        }

        $chkBez = @mysqli_query($conn, "SHOW COLUMNS FROM `buchungen` LIKE 'bezeichnung'");
        if ($chkBez && mysqli_num_rows($chkBez) === 0) {
            try {
                @mysqli_query($conn, "ALTER TABLE `buchungen` ADD COLUMN `bezeichnung` VARCHAR(255) NOT NULL DEFAULT '' AFTER `typ`");
            } catch (\Throwable $e) {}
        }
        if ($chkBez) {
            @mysqli_free_result($chkBez);
        }

        $buchCols = [
            'bereich_id' => 'ADD COLUMN `bereich_id` INT(11) NULL DEFAULT NULL COMMENT \'Finanzbereich (kassen_bereiche)\'',
        ];
        foreach ($buchCols as $col => $alter) {
            $c = @mysqli_query($conn, "SHOW COLUMNS FROM `buchungen` LIKE '{$col}'");
            if ($c && mysqli_num_rows($c) === 0) {
                try {
                    @mysqli_query($conn, "ALTER TABLE `buchungen` {$alter}");
                } catch (\Throwable $e) {}
            }
            if ($c) {
                @mysqli_free_result($c);
            }
        }
        if (!ff_finance_table_index_exists($conn, 'buchungen', 'idx_bereich_id')) {
            try {
                @mysqli_query($conn, 'ALTER TABLE `buchungen` ADD KEY `idx_bereich_id` (`bereich_id`)');
            } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {}

    try {
        $ptCol = @mysqli_query($conn, "SHOW COLUMNS FROM `print_targets` LIKE 'finance_bereich_id'");
        if ($ptCol && mysqli_num_rows($ptCol) === 0) {
            try {
                @mysqli_query($conn, 'ALTER TABLE `print_targets` ADD COLUMN `finance_bereich_id` INT(11) NULL DEFAULT NULL COMMENT \'Finanzbereich für Verkaufsauswertung\' AFTER `sort_order`');
            } catch (\Throwable $e) {}
        }
        if ($ptCol) {
            @mysqli_free_result($ptCol);
        }
    } catch (\Throwable $e) {}

    if (function_exists('ff_schema_flag_set')) {
        try {
            ff_schema_flag_set('finance_schema');
        } catch (\Throwable $e) {}
    }

    ff_finance_ensure_undo_columns($conn);
    ff_finance_ensure_settlement_scope_column($conn);
    ff_finance_ensure_kontrolle_only_column($conn);
}
