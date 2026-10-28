<?php
declare(strict_types=1);

if (function_exists('mysqli_report')) {
    @mysqli_report(MYSQLI_REPORT_OFF);
}

if (!function_exists('ff_rezepturen_table_exists')) {
    function ff_rezepturen_table_exists(mysqli $conn, string $tableName): bool
    {
        static $cache = [];
        $key = strtolower($tableName);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $safeTable = str_replace("'", "''", $tableName);
        $result = @mysqli_query($conn, "SHOW TABLES LIKE '" . $safeTable . "'");
        if (!$result) {
            $cache[$key] = false;
            return false;
        }

        $exists = mysqli_num_rows($result) > 0;
        @mysqli_free_result($result);
        $cache[$key] = $exists;
        return $exists;
    }
}

if (!function_exists('ff_mitarbeiter_verpflegung_ensure_schema')) {
    /**
     * Stellt die Tabellen mitarbeiter_bereiche und mitarbeiter_verpflegung idempotent sicher.
     */
    function ff_mitarbeiter_verpflegung_ensure_schema(mysqli $conn): void
    {
        static $mvChecked = [];
        $cid = spl_object_id($conn);
        if (isset($mvChecked[$cid])) {
            return;
        }
        $mvChecked[$cid] = true;

        try {
            @mysqli_query(
                $conn,
                "CREATE TABLE IF NOT EXISTS `mitarbeiter_bereiche` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `name` varchar(64) NOT NULL,
                    `sort_order` int(11) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            // Standardbereiche initialisieren, falls Tabelle neu/leer ist
            $cntMb = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM `mitarbeiter_bereiche`");
            $mbCount = $cntMb ? (int)(mysqli_fetch_assoc($cntMb)['c'] ?? 0) : 0;
            if ($cntMb) {
                @mysqli_free_result($cntMb);
            }
            if ($mbCount === 0) {
                @mysqli_query(
                    $conn,
                    "INSERT IGNORE INTO `mitarbeiter_bereiche` (`name`, `sort_order`) VALUES
                    ('Küche', 10),
                    ('Schank', 20),
                    ('Kellner', 30),
                    ('Kommando', 40),
                    ('Jugendfeuerwehr', 50),
                    ('Sonstige', 99)"
                );
            }

            @mysqli_query(
                $conn,
                "CREATE TABLE IF NOT EXISTS `mitarbeiter_verpflegung` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `datum` date NOT NULL,
                    `bereich_id` int(11) NOT NULL,
                    `position_id` int(11) NOT NULL,
                    `menge` int(11) NOT NULL DEFAULT 1,
                    `notiz` varchar(255) DEFAULT NULL,
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `created_by` varchar(64) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_datum` (`datum`),
                    KEY `idx_bereich` (`bereich_id`),
                    KEY `idx_position` (`position_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            // Spalten in mitarbeiter_verpflegung prüfen und ggf. ergänzen / umbenennen
            $cPosId = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'position_id'");
            $hasPosId = ($cPosId && mysqli_num_rows($cPosId) > 0);
            if ($cPosId) {
                @mysqli_free_result($cPosId);
            }

            if (!$hasPosId) {
                $cPos = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'position'");
                if ($cPos && mysqli_num_rows($cPos) > 0) {
                    @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` CHANGE COLUMN `position` `position_id` int(11) NOT NULL");
                } else {
                    @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` ADD COLUMN `position_id` int(11) NOT NULL DEFAULT 0 AFTER `bereich_id`");
                }
                if ($cPos) {
                    @mysqli_free_result($cPos);
                }
            }

            $cMenge = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'menge'");
            $hasMenge = ($cMenge && mysqli_num_rows($cMenge) > 0);
            if ($cMenge) {
                @mysqli_free_result($cMenge);
            }

            if (!$hasMenge) {
                $cAnzahl = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'anzahl'");
                if ($cAnzahl && mysqli_num_rows($cAnzahl) > 0) {
                    @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` CHANGE COLUMN `anzahl` `menge` int(11) NOT NULL DEFAULT 1");
                } else {
                    @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` ADD COLUMN `menge` int(11) NOT NULL DEFAULT 1 AFTER `position_id`");
                }
                if ($cAnzahl) {
                    @mysqli_free_result($cAnzahl);
                }
            }

            $cBereich = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'bereich_id'");
            if (!$cBereich || mysqli_num_rows($cBereich) === 0) {
                @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` ADD COLUMN `bereich_id` int(11) NOT NULL DEFAULT 0 AFTER `datum`");
            }
            if ($cBereich) {
                @mysqli_free_result($cBereich);
            }

            $cDatum = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'datum'");
            if (!$cDatum || mysqli_num_rows($cDatum) === 0) {
                @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` ADD COLUMN `datum` date NOT NULL DEFAULT '1970-01-01' AFTER `id`");
            }
            if ($cDatum) {
                @mysqli_free_result($cDatum);
            }

            $cNotiz = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'notiz'");
            if (!$cNotiz || mysqli_num_rows($cNotiz) === 0) {
                @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` ADD COLUMN `notiz` varchar(255) DEFAULT NULL AFTER `menge`");
            }
            if ($cNotiz) {
                @mysqli_free_result($cNotiz);
            }

            $cCreated = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'created_at'");
            if (!$cCreated || mysqli_num_rows($cCreated) === 0) {
                @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP");
            }
            if ($cCreated) {
                @mysqli_free_result($cCreated);
            }

            $cBy = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'created_by'");
            if (!$cBy || mysqli_num_rows($cBy) === 0) {
                @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` ADD COLUMN `created_by` varchar(64) DEFAULT NULL");
            }
            if ($cBy) {
                @mysqli_free_result($cBy);
            }
        } catch (\Throwable $e) {}
    }
}

if (!function_exists('ff_rezepturen_ensure_schema')) {
    /**
     * Stellt die Tabelle position_rezepturen sicher (idempotent).
     */
    function ff_rezepturen_ensure_schema(mysqli $conn): void
    {
        static $schemaChecked = [];
        $connectionId = spl_object_id($conn);
        if (isset($schemaChecked[$connectionId])) {
            return;
        }
        $schemaChecked[$connectionId] = true;

        ff_mitarbeiter_verpflegung_ensure_schema($conn);

        @mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS `bestandteile` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `einheit` varchar(30) NOT NULL DEFAULT 'Stk',
                `kapazitaet` int(11) NOT NULL DEFAULT -1,
                `aktiv` tinyint(1) NOT NULL DEFAULT 1,
                `sort_order` int(11) NOT NULL DEFAULT 0,
                `fest_id` int(11) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_aktiv_sort` (`aktiv`,`sort_order`),
                KEY `idx_fest_id` (`fest_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            $bKap = @mysqli_query($conn, "SHOW COLUMNS FROM `bestandteile` LIKE 'kapazitaet'");
            if (!$bKap || mysqli_num_rows($bKap) === 0) {
                @mysqli_query($conn, "ALTER TABLE `bestandteile` ADD COLUMN `kapazitaet` int(11) NOT NULL DEFAULT -1 AFTER `einheit`");
            }
            if ($bKap) @mysqli_free_result($bKap);

            $bAktiv = @mysqli_query($conn, "SHOW COLUMNS FROM `bestandteile` LIKE 'aktiv'");
            if (!$bAktiv || mysqli_num_rows($bAktiv) === 0) {
                @mysqli_query($conn, "ALTER TABLE `bestandteile` ADD COLUMN `aktiv` tinyint(1) NOT NULL DEFAULT 1 AFTER `kapazitaet`");
            }
            if ($bAktiv) @mysqli_free_result($bAktiv);

            $bSort = @mysqli_query($conn, "SHOW COLUMNS FROM `bestandteile` LIKE 'sort_order'");
            if (!$bSort || mysqli_num_rows($bSort) === 0) {
                @mysqli_query($conn, "ALTER TABLE `bestandteile` ADD COLUMN `sort_order` int(11) NOT NULL DEFAULT 0 AFTER `aktiv`");
            }
            if ($bSort) @mysqli_free_result($bSort);

            $bFest = @mysqli_query($conn, "SHOW COLUMNS FROM `bestandteile` LIKE 'fest_id'");
            if (!$bFest || mysqli_num_rows($bFest) === 0) {
                @mysqli_query($conn, "ALTER TABLE `bestandteile` ADD COLUMN `fest_id` int(11) DEFAULT NULL");
            }
            if ($bFest) @mysqli_free_result($bFest);
        } catch (\Throwable $e) {}

        try {
            @mysqli_query(
                $conn,
                "CREATE TABLE IF NOT EXISTS `position_rezepturen` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `position_id` int(11) NOT NULL,
                    `bestandteil_id` int(11) NOT NULL,
                    `menge` decimal(10,3) NOT NULL DEFAULT 1.000,
                    `optional_enabled` tinyint(1) NOT NULL DEFAULT 0,
                    `default_selected` tinyint(1) NOT NULL DEFAULT 1,
                    `option_group` varchar(64) NOT NULL DEFAULT '',
                    `reihenfolge` int(11) NOT NULL DEFAULT 0,
                    `fest_id` int(11) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_position` (`position_id`),
                    KEY `idx_bestandteil` (`bestandteil_id`),
                    KEY `idx_option_group` (`option_group`),
                    KEY `idx_fest_id` (`fest_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {}

        // Sicherstellen, dass position_rezepturen eine Spalte 'id' als Auto-Increment Primärschlüssel besitzt
        try {
            $colId = @mysqli_query($conn, "SHOW COLUMNS FROM `position_rezepturen` LIKE 'id'");
            $hasId = ($colId && mysqli_num_rows($colId) > 0);
            if ($colId) @mysqli_free_result($colId);

            if (!$hasId && ff_rezepturen_table_exists($conn, 'position_rezepturen')) {
                $cntRes = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM `position_rezepturen`");
                $rCount = $cntRes ? (int)(mysqli_fetch_assoc($cntRes)['c'] ?? 0) : 0;
                if ($cntRes) @mysqli_free_result($cntRes);

                if ($rCount === 0) {
                    @mysqli_query($conn, "DROP TABLE `position_rezepturen`");
                    @mysqli_query(
                        $conn,
                        "CREATE TABLE IF NOT EXISTS `position_rezepturen` (
                            `id` int(11) NOT NULL AUTO_INCREMENT,
                            `position_id` int(11) NOT NULL,
                            `bestandteil_id` int(11) NOT NULL,
                            `menge` decimal(10,3) NOT NULL DEFAULT 1.000,
                            `optional_enabled` tinyint(1) NOT NULL DEFAULT 0,
                            `default_selected` tinyint(1) NOT NULL DEFAULT 1,
                            `option_group` varchar(64) NOT NULL DEFAULT '',
                            `reihenfolge` int(11) NOT NULL DEFAULT 0,
                            `fest_id` int(11) DEFAULT NULL,
                            PRIMARY KEY (`id`),
                            KEY `idx_position` (`position_id`),
                            KEY `idx_bestandteil` (`bestandteil_id`),
                            KEY `idx_option_group` (`option_group`),
                            KEY `idx_fest_id` (`fest_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                    );
                } else {
                    $colRowid = @mysqli_query($conn, "SHOW COLUMNS FROM `position_rezepturen` LIKE 'rowid'");
                    if ($colRowid && mysqli_num_rows($colRowid) > 0) {
                        @mysqli_query($conn, "ALTER TABLE `position_rezepturen` CHANGE COLUMN `rowid` `id` int(11) NOT NULL AUTO_INCREMENT");
                    } else {
                        $pkRes = @mysqli_query($conn, "SHOW INDEX FROM `position_rezepturen` WHERE Key_name = 'PRIMARY'");
                        $hasPk = ($pkRes && mysqli_num_rows($pkRes) > 0);
                        if ($pkRes) @mysqli_free_result($pkRes);

                        if ($hasPk) {
                            @mysqli_query($conn, "ALTER TABLE `position_rezepturen` DROP PRIMARY KEY, ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                        } else {
                            @mysqli_query($conn, "ALTER TABLE `position_rezepturen` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                        }
                    }
                    if ($colRowid) @mysqli_free_result($colRowid);
                }
            }
        } catch (\Throwable $e) {}

        try {
            @mysqli_query(
                $conn,
                "CREATE TABLE IF NOT EXISTS `allergene` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `name` varchar(100) NOT NULL,
                    `sort_order` int(11) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_name` (`name`),
                    KEY `idx_sort` (`sort_order`,`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            @mysqli_query(
                $conn,
                "CREATE TABLE IF NOT EXISTS `bestandteil_allergene` (
                    `bestandteil_id` int(11) NOT NULL,
                    `allergen_id` int(11) NOT NULL,
                    PRIMARY KEY (`bestandteil_id`,`allergen_id`),
                    KEY `idx_allergen` (`allergen_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            @mysqli_query(
                $conn,
                "CREATE TABLE IF NOT EXISTS `bestellung_rezeptur_snapshots` (
                    `bestellung_rowid` int(11) NOT NULL,
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`bestellung_rowid`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            @mysqli_query(
                $conn,
                "CREATE TABLE IF NOT EXISTS `bestellung_rezeptur_komponenten` (
                    `bestellung_rowid` int(11) NOT NULL,
                    `bestandteil_id` int(11) NOT NULL,
                    `name_snapshot` varchar(255) NOT NULL,
                    `einheit_snapshot` varchar(30) NOT NULL DEFAULT 'Stk',
                    `menge` decimal(10,3) NOT NULL DEFAULT 1.000,
                    `optional_enabled` tinyint(1) NOT NULL DEFAULT 0,
                    `default_selected` tinyint(1) NOT NULL DEFAULT 1,
                    `selected` tinyint(1) NOT NULL DEFAULT 1,
                    PRIMARY KEY (`bestellung_rowid`, `bestandteil_id`),
                    KEY `idx_bestellung_rezeptur_bestandteil` (`bestandteil_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            foreach ([
                'optional_enabled' => "ADD COLUMN `optional_enabled` tinyint(1) NOT NULL DEFAULT 0",
                'default_selected' => "ADD COLUMN `default_selected` tinyint(1) NOT NULL DEFAULT 1",
                'selected' => "ADD COLUMN `selected` tinyint(1) NOT NULL DEFAULT 1",
            ] as $column => $alter) {
                $columnCheck = @mysqli_query($conn, "SHOW COLUMNS FROM `bestellung_rezeptur_komponenten` LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
                if (!$columnCheck || mysqli_num_rows($columnCheck) === 0) {
                    @mysqli_query($conn, "ALTER TABLE `bestellung_rezeptur_komponenten` " . $alter);
                }
                if ($columnCheck) {
                    @mysqli_free_result($columnCheck);
                }
            }
        } catch (\Throwable $e) {}

        try {
            $colOpt = @mysqli_query($conn, "SHOW COLUMNS FROM `position_rezepturen` LIKE 'optional_enabled'");
            if (!$colOpt || mysqli_num_rows($colOpt) === 0) {
                @mysqli_query($conn, "ALTER TABLE `position_rezepturen` ADD COLUMN `optional_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `menge`");
            }
            if ($colOpt) @mysqli_free_result($colOpt);

            $colDef = @mysqli_query($conn, "SHOW COLUMNS FROM `position_rezepturen` LIKE 'default_selected'");
            if (!$colDef || mysqli_num_rows($colDef) === 0) {
                @mysqli_query($conn, "ALTER TABLE `position_rezepturen` ADD COLUMN `default_selected` tinyint(1) NOT NULL DEFAULT 1 AFTER `optional_enabled`");
            }
            if ($colDef) @mysqli_free_result($colDef);

            $colGrp = @mysqli_query($conn, "SHOW COLUMNS FROM `position_rezepturen` LIKE 'option_group'");
            if (!$colGrp || mysqli_num_rows($colGrp) === 0) {
                @mysqli_query($conn, "ALTER TABLE `position_rezepturen` ADD COLUMN `option_group` varchar(64) NOT NULL DEFAULT '' AFTER `default_selected`");
                @mysqli_query($conn, "ALTER TABLE `position_rezepturen` ADD KEY `idx_option_group` (`option_group`)");
            }
            if ($colGrp) @mysqli_free_result($colGrp);

            $colReihenfolge = @mysqli_query($conn, "SHOW COLUMNS FROM `position_rezepturen` LIKE 'reihenfolge'");
            if (!$colReihenfolge || mysqli_num_rows($colReihenfolge) === 0) {
                @mysqli_query($conn, "ALTER TABLE `position_rezepturen` ADD COLUMN `reihenfolge` int(11) NOT NULL DEFAULT 0 AFTER `option_group`");
            }
            if ($colReihenfolge) @mysqli_free_result($colReihenfolge);

            $colFest = @mysqli_query($conn, "SHOW COLUMNS FROM `position_rezepturen` LIKE 'fest_id'");
            if (!$colFest || mysqli_num_rows($colFest) === 0) {
                @mysqli_query($conn, "ALTER TABLE `position_rezepturen` ADD COLUMN `fest_id` int(11) DEFAULT NULL");
                @mysqli_query($conn, "ALTER TABLE `position_rezepturen` ADD KEY `idx_fest_id` (`fest_id`)");
            }
            if ($colFest) @mysqli_free_result($colFest);

            $hasOldCol = false;
            $colOld = @mysqli_query($conn, "SHOW COLUMNS FROM `position_rezepturen` LIKE 'bestandteil_position_id'");
            if ($colOld && mysqli_num_rows($colOld) > 0) {
                $hasOldCol = true;
            }
            if ($colOld) @mysqli_free_result($colOld);

            $hasNewCol = false;
            $colNew = @mysqli_query($conn, "SHOW COLUMNS FROM `position_rezepturen` LIKE 'bestandteil_id'");
            if ($colNew && mysqli_num_rows($colNew) > 0) {
                $hasNewCol = true;
            }
            if ($colNew) @mysqli_free_result($colNew);

            if (!$hasNewCol) {
                @mysqli_query($conn, "ALTER TABLE `position_rezepturen` ADD COLUMN `bestandteil_id` int(11) DEFAULT NULL AFTER `position_id`");
                @mysqli_query($conn, "ALTER TABLE `position_rezepturen` ADD KEY `idx_bestandteil` (`bestandteil_id`)");
            }

            if ($hasOldCol) {
                @mysqli_query(
                    $conn,
                    "UPDATE `position_rezepturen`
                     SET `bestandteil_id` = `bestandteil_position_id`
                     WHERE (`bestandteil_id` IS NULL OR `bestandteil_id` = 0)
                       AND `bestandteil_position_id` > 0"
                );

                @mysqli_query($conn, "ALTER TABLE `position_rezepturen` DROP COLUMN `bestandteil_position_id`");
            }

            @mysqli_query($conn, "UPDATE `position_rezepturen` SET `bestandteil_id` = 0 WHERE `bestandteil_id` IS NULL");
            @mysqli_query($conn, "DELETE FROM `position_rezepturen` WHERE `bestandteil_id` <= 0");
        } catch (\Throwable $e) {}
    }
}

if (!function_exists('ff_rezeptur_allergene_by_position')) {
    /**
     * Liefert die Allergene einer Position aus allen zugehörigen Rezeptur-Bestandteilen.
     * Die Rückgabe ist nach Allergen-Stammdaten sortiert und enthält keine Duplikate.
     */
    function ff_rezeptur_allergene_by_position(mysqli $conn, array $positionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $positionIds), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === []
            || !ff_rezepturen_table_exists($conn, 'position_rezepturen')
            || !ff_rezepturen_table_exists($conn, 'bestandteil_allergene')
            || !ff_rezepturen_table_exists($conn, 'allergene')
        ) {
            return [];
        }

        $result = mysqli_query(
            $conn,
            'SELECT pr.position_id, a.name
             FROM position_rezepturen pr
             INNER JOIN bestandteil_allergene ba ON ba.bestandteil_id = pr.bestandteil_id
             INNER JOIN allergene a ON a.id = ba.allergen_id
             WHERE pr.position_id IN (' . implode(',', $ids) . ')
             GROUP BY pr.position_id, a.id, a.name, a.sort_order
             ORDER BY pr.position_id, a.sort_order, a.name'
        );
        if (!$result) {
            return [];
        }

        $allergeneByPosition = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $positionId = (int) ($row['position_id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($positionId > 0 && $name !== '') {
                $allergeneByPosition[$positionId][] = $name;
            }
        }
        mysqli_free_result($result);

        return $allergeneByPosition;
    }
}
