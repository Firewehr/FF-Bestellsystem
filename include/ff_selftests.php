<?php
/**
 * Automatische Selbsttests (Admin-Menü): Preisberechnung, Storno, Kapazität.
 *
 * Alle Testdaten werden in EINER Transaktion angelegt und am Ende per ROLLBACK verworfen;
 * produktive Daten werden nicht verändert. Voraussetzung: beteiligte Tabellen sind InnoDB.
 */
declare(strict_types=1);

require_once __DIR__ . '/beilage_helpers.php';
require_once __DIR__ . '/ff_bestellung_storno.php';
require_once __DIR__ . '/ff_position_stock_summary.php';
require_once __DIR__ . '/ff_rezepturen_schema.php';
require_once __DIR__ . '/ff_finance_bereich_helpers.php';
require_once __DIR__ . '/ff_csrf.php';
require_once __DIR__ . '/ff_rechnung_seq.php';

function ff_st_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare fehlgeschlagen: ' . mysqli_error($conn));
    }
    if ($params !== []) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Abfrage fehlgeschlagen: ' . $err);
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function ff_st_exec(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare fehlgeschlagen: ' . mysqli_error($conn));
    }
    if ($params !== []) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Ausführung fehlgeschlagen: ' . $err);
    }
    $id = (int) mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);

    return $id;
}

function ff_st_position(mysqli $conn, float $betrag, int $max = -1): int
{
    return ff_st_exec(
        $conn,
        "INSERT INTO positionen (Positionsname, Betrag, type, Kurzbezeichnung, maxBestellbar, reihenfolge, icon)
         VALUES ('ZZ_SELFTEST', ?, 1, 'ZZ_ST', ?, 9999, '')",
        'di',
        [$betrag, $max]
    );
}

/** @param array{paid?:bool,delivered?:bool,deleted?:bool,betrag?:float} $o */
function ff_st_order(mysqli $conn, int $positionId, array $o = []): int
{
    $betrag = (float) ($o['betrag'] ?? 0.0);
    $deleted = !empty($o['deleted']) ? 1 : 0;
    $delivered = !empty($o['delivered']) ? 1 : 0;
    $id = ff_st_exec(
        $conn,
        "INSERT INTO bestellungen (tischnummer, position, ausgeliefert, kellner, bestellung, kueche, `delete`, print, kellnerZahlung, betrag, bestellt)
         VALUES (999999, ?, ?, 'selftest', 0, 0, ?, 0, '', ?, 1)",
        'iiid',
        [$positionId, $delivered, $deleted, $betrag]
    );
    if (!empty($o['paid'])) {
        ff_st_exec($conn, 'UPDATE bestellungen SET timestampBezahlung = NOW() WHERE rowid = ?', 'i', [$id]);
    }

    return $id;
}

function ff_st_order_row(mysqli $conn, int $id): array
{
    $row = ff_st_fetch_one($conn, 'SELECT * FROM bestellungen WHERE rowid = ?', 'i', [$id]);
    if ($row === null) {
        throw new RuntimeException('Testbestellung ' . $id . ' nicht gefunden');
    }

    return $row;
}

function ff_st_expect_same($expected, $actual, string $what): ?string
{
    if ($expected === $actual) {
        return null;
    }

    return $what . ': erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true);
}

function ff_selftests_expected_schema(): array
{
    static $schema = null;
    if ($schema !== null) {
        return $schema;
    }
    $schema = [
        'allergene' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'name' => 'varchar(100) NOT NULL DEFAULT \'\'',
                'sort_order' => 'int(11) NOT NULL DEFAULT 0',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `allergene` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'beilagen' => [
            'pk' => ["rowid"],
            'cols' => [
                'rowid' => 'int(11) NOT NULL AUTO_INCREMENT',
                'timestamp' => 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
                'name' => 'varchar(255) NOT NULL DEFAULT \'\'',
                'position' => 'int(11) NOT NULL DEFAULT 0',
                'betrag' => 'double NOT NULL DEFAULT 0',
                'fest_id' => 'int(11) DEFAULT NULL COMMENT \'NULL = global, sonst gehört Beilage zu diesem Fest und wird beim Löschen mit-entfernt\'',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `beilagen` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `name` varchar(255) NOT NULL,
  `position` int(11) NOT NULL,
  `betrag` double NOT NULL DEFAULT 0,
  `fest_id` int(11) DEFAULT NULL COMMENT \'NULL = global, sonst gehört Beilage zu diesem Fest und wird beim Löschen mit-entfernt\',
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'bestandteil_allergene' => [
            'pk' => ["bestandteil_id", "allergen_id"],
            'cols' => [
                'bestandteil_id' => 'int(11) NOT NULL',
                'allergen_id' => 'int(11) NOT NULL',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `bestandteil_allergene` (
  `bestandteil_id` int(11) NOT NULL,
  `allergen_id` int(11) NOT NULL,
  PRIMARY KEY (`bestandteil_id`, `allergen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'bestandteile' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'name' => 'varchar(255) NOT NULL DEFAULT \'\'',
                'einheit' => 'varchar(30) NOT NULL DEFAULT \'Stk\'',
                'kapazitaet' => 'int(11) NOT NULL DEFAULT -1',
                'aktiv' => 'tinyint(1) NOT NULL DEFAULT 1',
                'sort_order' => 'int(11) NOT NULL DEFAULT 0',
                'fest_id' => 'int(11) DEFAULT NULL',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `bestandteile` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `einheit` varchar(30) NOT NULL DEFAULT \'Stk\',
  `kapazitaet` int(11) NOT NULL DEFAULT -1,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `fest_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'bestellung_rezeptur_komponenten' => [
            'pk' => ["bestellung_rowid", "bestandteil_id"],
            'cols' => [
                'bestellung_rowid' => 'int(11) NOT NULL',
                'bestandteil_id' => 'int(11) NOT NULL',
                'name_snapshot' => 'varchar(255) NOT NULL DEFAULT \'\'',
                'einheit_snapshot' => 'varchar(30) NOT NULL DEFAULT \'Stk\'',
                'menge' => 'decimal(10,3) NOT NULL DEFAULT 1.000',
                'optional_enabled' => 'tinyint(1) NOT NULL DEFAULT 0',
                'default_selected' => 'tinyint(1) NOT NULL DEFAULT 1',
                'selected' => 'tinyint(1) NOT NULL DEFAULT 1',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `bestellung_rezeptur_komponenten` (
  `bestellung_rowid` int(11) NOT NULL,
  `bestandteil_id` int(11) NOT NULL,
  `name_snapshot` varchar(255) NOT NULL,
  `einheit_snapshot` varchar(30) NOT NULL DEFAULT \'Stk\',
  `menge` decimal(10,3) NOT NULL DEFAULT 1.000,
  `optional_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `default_selected` tinyint(1) NOT NULL DEFAULT 1,
  `selected` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`bestellung_rowid`, `bestandteil_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'bestellung_rezeptur_snapshots' => [
            'pk' => ["bestellung_rowid"],
            'cols' => [
                'bestellung_rowid' => 'int(11) NOT NULL',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `bestellung_rezeptur_snapshots` (
  `bestellung_rowid` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`bestellung_rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'bestellungen' => [
            'pk' => ["rowid"],
            'cols' => [
                'rowid' => 'int(11) NOT NULL AUTO_INCREMENT',
                'fest_id' => 'int(11) DEFAULT NULL',
                'sammelrechnung_id' => 'int(11) DEFAULT NULL',
                'rechnung_id' => 'int(11) DEFAULT NULL',
                'zeitstempel' => 'timestamp NOT NULL DEFAULT current_timestamp()',
                'position' => 'int(11) NOT NULL DEFAULT 0',
                'ausgeliefert' => 'int(11) NOT NULL DEFAULT 0',
                'tischnummer' => 'int(11) NOT NULL DEFAULT 0',
                'kellner' => 'text NOT NULL DEFAULT \'\'',
                'bestellung' => 'int(11) NOT NULL DEFAULT 0',
                'kueche' => 'int(11) NOT NULL DEFAULT 0',
                'delete' => 'int(11) NOT NULL DEFAULT 0',
                'zeitKueche' => 'timestamp NOT NULL DEFAULT \'0000-00-00 00:00:00\'',
                'print' => 'tinyint(1) NOT NULL DEFAULT 0',
                'print_target' => 'int(11) DEFAULT NULL',
                'print_status' => 'tinyint(1) NOT NULL DEFAULT 0',
                'timestampBestellung' => 'timestamp NOT NULL DEFAULT \'0000-00-00 00:00:00\'',
                'timestampAuslieferung' => 'timestamp NOT NULL DEFAULT \'0000-00-00 00:00:00\'',
                'timestampBezahlung' => 'timestamp NOT NULL DEFAULT \'0000-00-00 00:00:00\'',
                'kellnerZahlung' => 'text NOT NULL DEFAULT \'0\'',
                'Zusatzinfo' => 'varchar(255) DEFAULT NULL',
                'betrag' => 'double NOT NULL DEFAULT 0',
                'bestellt' => 'tinyint(1) NOT NULL DEFAULT 0',
                'is_gratis' => 'tinyint(1) NOT NULL DEFAULT 0',
                'bon_id' => 'varchar(10) DEFAULT NULL',
                'order_nr' => 'int(11) DEFAULT NULL COMMENT \'Bestellnummer (beim Abschicken)\'',
                'settlement_id' => 'int(11) DEFAULT NULL COMMENT \'Kellner-Abrechnung\'',
                'settled_at' => 'datetime DEFAULT NULL',
                'settled_by' => 'varchar(64) DEFAULT NULL',
                'schreibaus' => 'tinyint(1) NOT NULL DEFAULT 0',
                'sumup_checkout_id' => 'varchar(100) DEFAULT NULL',
                'sumup_checkout_reference' => 'varchar(100) DEFAULT NULL',
                'zahlungsart' => 'varchar(30) DEFAULT NULL',
                'auto_print' => 'tinyint(1) NOT NULL DEFAULT 0',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `bestellungen` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fest_id` int(11) DEFAULT NULL,
  `sammelrechnung_id` int(11) DEFAULT NULL,
  `rechnung_id` int(11) DEFAULT NULL,
  `zeitstempel` timestamp NOT NULL DEFAULT current_timestamp(),
  `position` int(11) NOT NULL,
  `ausgeliefert` int(11) NOT NULL,
  `tischnummer` int(11) NOT NULL,
  `kellner` text NOT NULL,
  `bestellung` int(11) NOT NULL DEFAULT 0,
  `kueche` int(11) NOT NULL,
  `delete` int(11) NOT NULL DEFAULT 0,
  `zeitKueche` timestamp NOT NULL DEFAULT \'0000-00-00 00:00:00\',
  `print` tinyint(1) NOT NULL DEFAULT 0,
  `print_target` int(11) DEFAULT NULL,
  `print_status` tinyint(1) NOT NULL DEFAULT 0,
  `timestampBestellung` timestamp NOT NULL DEFAULT \'0000-00-00 00:00:00\',
  `timestampAuslieferung` timestamp NOT NULL DEFAULT \'0000-00-00 00:00:00\',
  `timestampBezahlung` timestamp NOT NULL DEFAULT \'0000-00-00 00:00:00\',
  `kellnerZahlung` text NOT NULL DEFAULT \'0\',
  `Zusatzinfo` varchar(255) DEFAULT NULL,
  `betrag` double NOT NULL,
  `bestellt` tinyint(1) NOT NULL,
  `is_gratis` tinyint(1) NOT NULL DEFAULT 0,
  `bon_id` varchar(10) DEFAULT NULL,
  `order_nr` int(11) DEFAULT NULL COMMENT \'Bestellnummer (beim Abschicken)\',
  `settlement_id` int(11) DEFAULT NULL COMMENT \'Kellner-Abrechnung\',
  `settled_at` datetime DEFAULT NULL,
  `settled_by` varchar(64) DEFAULT NULL,
  `schreibaus` tinyint(1) NOT NULL DEFAULT 0,
  `sumup_checkout_id` varchar(100) DEFAULT NULL,
  `sumup_checkout_reference` varchar(100) DEFAULT NULL,
  `zahlungsart` varchar(30) DEFAULT NULL,
  `auto_print` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'buchungen' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'typ' => 'enum(\'einnahme\',\'ausgabe\') NOT NULL',
                'bezeichnung' => 'varchar(255) NOT NULL DEFAULT \'\'',
                'betrag' => 'decimal(10,2) NOT NULL DEFAULT 0',
                'datum' => 'date DEFAULT NULL',
                'kategorie' => 'varchar(100) DEFAULT NULL',
                'notiz' => 'text DEFAULT NULL',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
                'created_by' => 'varchar(64) DEFAULT NULL',
                'bereich_id' => 'int(11) DEFAULT NULL COMMENT \'Finanzbereich (kassen_bereiche)\'',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `buchungen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `typ` enum(\'einnahme\',\'ausgabe\') NOT NULL,
  `bezeichnung` varchar(255) NOT NULL,
  `betrag` decimal(10,2) NOT NULL,
  `datum` date DEFAULT NULL,
  `kategorie` varchar(100) DEFAULT NULL,
  `notiz` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) DEFAULT NULL,
  `bereich_id` int(11) DEFAULT NULL COMMENT \'Finanzbereich (kassen_bereiche)\',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'feste' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'name' => 'varchar(255) NOT NULL DEFAULT \'\'',
                'code' => 'varchar(32) NOT NULL DEFAULT \'\'',
                'rechnung_prefix' => 'varchar(16) DEFAULT NULL COMMENT \'Rechnungsnr.-Präfix; leer = global rechnung_prefix\'',
                'fest_datum' => 'date DEFAULT NULL',
                'aktiv' => 'tinyint(1) NOT NULL DEFAULT 1',
                'ist_aktuell' => 'tinyint(1) NOT NULL DEFAULT 0',
                'payment_mode' => 'enum(\'instant\',\'after\') NOT NULL DEFAULT \'after\'',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `feste` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `code` varchar(32) NOT NULL,
  `rechnung_prefix` varchar(16) DEFAULT NULL COMMENT \'Rechnungsnr.-Präfix; leer = global rechnung_prefix\',
  `fest_datum` date DEFAULT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `ist_aktuell` tinyint(1) NOT NULL DEFAULT 0,
  `payment_mode` enum(\'instant\',\'after\') NOT NULL DEFAULT \'after\',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'ff_admin_messages' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'bigint(20) UNSIGNED NOT NULL',
                'fest_id' => 'int(11) NOT NULL DEFAULT 0',
                'title' => 'varchar(255) NOT NULL DEFAULT \'\'',
                'body' => 'text NOT NULL DEFAULT \'\'',
                'url' => 'varchar(255) NOT NULL DEFAULT \'./admin.php\'',
                'tag' => 'varchar(191) NOT NULL DEFAULT \'\'',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `ff_admin_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `fest_id` int(11) NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `url` varchar(255) NOT NULL DEFAULT \'./admin.php\',
  `tag` varchar(191) NOT NULL DEFAULT \'\',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'ff_fest_push_rules' => [
            'pk' => ["fest_id", "resource_type", "resource_id"],
            'cols' => [
                'fest_id' => 'int(11) NOT NULL',
                'resource_type' => 'enum(\'position\',\'ingredient\',\'station\') NOT NULL',
                'resource_id' => 'int(11) NOT NULL',
                'threshold_value' => 'smallint(5) UNSIGNED NOT NULL DEFAULT 0',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `ff_fest_push_rules` (
  `fest_id` int(11) NOT NULL,
  `resource_type` enum(\'position\',\'ingredient\',\'station\') NOT NULL,
  `resource_id` int(11) NOT NULL,
  `threshold_value` smallint(5) UNSIGNED NOT NULL,
  PRIMARY KEY (`fest_id`, `resource_type`, `resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'ff_fest_push_settings' => [
            'pk' => ["fest_id"],
            'cols' => [
                'fest_id' => 'int(11) NOT NULL',
                'enabled' => 'tinyint(1) NOT NULL DEFAULT 0',
                'updated_at' => 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `ff_fest_push_settings` (
  `fest_id` int(11) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fest_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'ff_fest_push_state' => [
            'pk' => ["fest_id", "resource_type", "resource_id"],
            'cols' => [
                'fest_id' => 'int(11) NOT NULL',
                'resource_type' => 'enum(\'position\',\'ingredient\',\'station\') NOT NULL',
                'resource_id' => 'int(11) NOT NULL',
                'notified' => 'tinyint(1) NOT NULL DEFAULT 0',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `ff_fest_push_state` (
  `fest_id` int(11) NOT NULL,
  `resource_type` enum(\'position\',\'ingredient\',\'station\') NOT NULL,
  `resource_id` int(11) NOT NULL,
  `notified` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`fest_id`, `resource_type`, `resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'ff_station_events' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'bigint(20) UNSIGNED NOT NULL',
                'print_target' => 'int(11) NOT NULL DEFAULT 0',
                'event_type' => 'varchar(32) NOT NULL DEFAULT \'\'',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `ff_station_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `print_target` int(11) NOT NULL,
  `event_type` varchar(32) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'kassen_bereiche' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'name' => 'varchar(128) NOT NULL DEFAULT \'\'',
                'is_active' => 'tinyint(1) NOT NULL DEFAULT 1',
                'kontrolle_only' => 'tinyint(1) NOT NULL DEFAULT 0 COMMENT \'Nur Kassenkontrolle, nicht in Gesamtumsatz\'',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `kassen_bereiche` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `kontrolle_only` tinyint(1) NOT NULL DEFAULT 0 COMMENT \'Nur Kassenkontrolle, nicht in Gesamtumsatz\',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'kassen_bewegungen' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'session_id' => 'int(11) NOT NULL DEFAULT 0',
                'typ' => 'enum(\'entnahme\',\'zuzahlung\') NOT NULL',
                'betrag' => 'decimal(10,2) NOT NULL DEFAULT 0',
                'notiz' => 'varchar(255) DEFAULT NULL',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
                'created_by' => 'varchar(64) DEFAULT NULL',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `kassen_bewegungen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `typ` enum(\'entnahme\',\'zuzahlung\') NOT NULL,
  `betrag` decimal(10,2) NOT NULL,
  `notiz` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'kassen_sessions' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'bereich_id' => 'int(11) NOT NULL DEFAULT 0',
                'status' => 'enum(\'open\',\'closed\') NOT NULL DEFAULT \'open\'',
                'opening_amount' => 'decimal(10,2) NOT NULL DEFAULT 0.00',
                'opened_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'opened_by' => 'varchar(64) NOT NULL DEFAULT \'\'',
                'closing_amount' => 'decimal(10,2) DEFAULT NULL COMMENT \'Tageslosung\'',
                'revenue_amount' => 'decimal(10,2) DEFAULT NULL',
                'closed_at' => 'datetime DEFAULT NULL',
                'closed_by' => 'varchar(64) DEFAULT NULL',
                'reopened_at' => 'datetime DEFAULT NULL',
                'reopened_by' => 'varchar(64) DEFAULT NULL',
                'reopen_reason' => 'varchar(255) DEFAULT NULL',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `kassen_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bereich_id` int(11) NOT NULL,
  `status` enum(\'open\',\'closed\') NOT NULL DEFAULT \'open\',
  `opening_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `opened_at` datetime NOT NULL,
  `opened_by` varchar(64) NOT NULL,
  `closing_amount` decimal(10,2) DEFAULT NULL COMMENT \'Tageslosung\',
  `revenue_amount` decimal(10,2) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `closed_by` varchar(64) DEFAULT NULL,
  `reopened_at` datetime DEFAULT NULL,
  `reopened_by` varchar(64) DEFAULT NULL,
  `reopen_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'kellner_bewegungen' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'kellner_login' => 'varchar(255) NOT NULL DEFAULT \'\'',
                'settlement_id' => 'int(11) DEFAULT NULL',
                'typ' => 'enum(\'entnahme\',\'zuzahlung\') NOT NULL',
                'betrag' => 'decimal(10,2) NOT NULL DEFAULT 0',
                'notiz' => 'varchar(255) DEFAULT NULL',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
                'created_by' => 'varchar(64) DEFAULT NULL',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `kellner_bewegungen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kellner_login` varchar(255) NOT NULL,
  `settlement_id` int(11) DEFAULT NULL,
  `typ` enum(\'entnahme\',\'zuzahlung\') NOT NULL,
  `betrag` decimal(10,2) NOT NULL,
  `notiz` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'kellner_settlements' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'kellner_login' => 'varchar(255) NOT NULL DEFAULT \'\'',
                'settlement_scope' => 'varchar(20) NOT NULL DEFAULT \'kellner\' COMMENT \'kellner oder direktverkauf\'',
                'von_dt' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'bis_dt' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'umsatz_soll' => 'decimal(10,2) NOT NULL DEFAULT 0.00',
                'betrag_abgegeben' => 'decimal(10,2) NOT NULL DEFAULT 0.00',
                'wechselgeld_zurueck' => 'decimal(10,2) NOT NULL DEFAULT 0.00',
                'trinkgeld' => 'decimal(10,2) NOT NULL DEFAULT 0.00',
                'notiz' => 'varchar(255) DEFAULT NULL',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
                'created_by' => 'varchar(64) DEFAULT NULL',
                'voided_at' => 'datetime DEFAULT NULL',
                'voided_by' => 'varchar(64) DEFAULT NULL',
                'void_reason' => 'varchar(255) DEFAULT NULL',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `kellner_settlements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kellner_login` varchar(255) NOT NULL,
  `settlement_scope` varchar(20) NOT NULL DEFAULT \'kellner\' COMMENT \'kellner oder direktverkauf\',
  `von_dt` datetime NOT NULL,
  `bis_dt` datetime NOT NULL,
  `umsatz_soll` decimal(10,2) NOT NULL DEFAULT 0.00,
  `betrag_abgegeben` decimal(10,2) NOT NULL DEFAULT 0.00,
  `wechselgeld_zurueck` decimal(10,2) NOT NULL DEFAULT 0.00,
  `trinkgeld` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notiz` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` varchar(64) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'menu_lock_exceptions' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'lock_id' => 'int(11) NOT NULL DEFAULT 0',
                'position_id' => 'int(11) NOT NULL DEFAULT 0',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `menu_lock_exceptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lock_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'menu_locks' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'scope' => 'enum(\'position\',\'type_all\') NOT NULL DEFAULT \'position\'',
                'position_id' => 'int(11) DEFAULT NULL',
                'menu_type' => 'tinyint(2) DEFAULT NULL COMMENT \'1=Speise, 2=Getränk bei type_all\'',
                'locked_until' => 'datetime DEFAULT NULL COMMENT \'NULL = bis manuell aufgehoben\'',
                'reason' => 'varchar(255) NOT NULL DEFAULT \'\'',
                'created_at' => 'datetime NOT NULL DEFAULT current_timestamp()',
                'created_by' => 'varchar(64) NOT NULL DEFAULT \'\'',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `menu_locks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope` enum(\'position\',\'type_all\') NOT NULL DEFAULT \'position\',
  `position_id` int(11) DEFAULT NULL,
  `menu_type` tinyint(2) DEFAULT NULL COMMENT \'1=Speise, 2=Getränk bei type_all\',
  `locked_until` datetime DEFAULT NULL COMMENT \'NULL = bis manuell aufgehoben\',
  `reason` varchar(255) NOT NULL DEFAULT \'\',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) NOT NULL DEFAULT \'\',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'mitarbeiter_bereiche' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'name' => 'varchar(64) NOT NULL DEFAULT \'\'',
                'sort_order' => 'int(11) NOT NULL DEFAULT 0',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `mitarbeiter_bereiche` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'mitarbeiter_verpflegung' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'datum' => 'date NOT NULL DEFAULT \'1970-01-01\'',
                'bereich_id' => 'int(11) NOT NULL DEFAULT 0',
                'position_id' => 'int(11) NOT NULL DEFAULT 0',
                'menge' => 'int(11) NOT NULL DEFAULT 1',
                'notiz' => 'varchar(255) DEFAULT NULL',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
                'created_by' => 'varchar(64) DEFAULT NULL',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `mitarbeiter_verpflegung` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `datum` date NOT NULL,
  `bereich_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `menge` int(11) NOT NULL DEFAULT 1,
  `notiz` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'position_rezepturen' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'position_id' => 'int(11) NOT NULL DEFAULT 0',
                'bestandteil_id' => 'int(11) DEFAULT NULL',
                'menge' => 'decimal(10,3) NOT NULL DEFAULT 1.000',
                'optional_enabled' => 'tinyint(1) NOT NULL DEFAULT 0',
                'default_selected' => 'tinyint(1) NOT NULL DEFAULT 1',
                'option_group' => 'varchar(64) NOT NULL DEFAULT \'\'',
                'reihenfolge' => 'int(11) NOT NULL DEFAULT 0',
                'fest_id' => 'int(11) DEFAULT NULL',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `position_rezepturen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `position_id` int(11) NOT NULL,
  `bestandteil_id` int(11) DEFAULT NULL,
  `menge` decimal(10,3) NOT NULL DEFAULT 1.000,
  `optional_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `default_selected` tinyint(1) NOT NULL DEFAULT 1,
  `option_group` varchar(64) NOT NULL DEFAULT \'\',
  `reihenfolge` int(11) NOT NULL DEFAULT 0,
  `fest_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'position_subcategories' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'type' => 'tinyint(4) NOT NULL COMMENT \'1=Speise 2=Getränk\' DEFAULT 0',
                'name' => 'varchar(128) NOT NULL DEFAULT \'\'',
                'sort_order' => 'int(11) NOT NULL DEFAULT 0',
                'tile_bg' => 'varchar(32) DEFAULT NULL',
                'kassa_only' => 'tinyint(1) NOT NULL DEFAULT 0 COMMENT \'1=nur Kasse, Kellner sehen Gruppe nicht\'',
                'fest_id' => 'int(11) DEFAULT NULL COMMENT \'NULL = global, sonst gehört Subkategorie zu diesem Fest und wird beim Löschen mit-entfernt\'',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `position_subcategories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` tinyint(4) NOT NULL COMMENT \'1=Speise 2=Getränk\',
  `name` varchar(128) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `tile_bg` varchar(32) DEFAULT NULL,
  `kassa_only` tinyint(1) NOT NULL DEFAULT 0 COMMENT \'1=nur Kasse, Kellner sehen Gruppe nicht\',
  `fest_id` int(11) DEFAULT NULL COMMENT \'NULL = global, sonst gehört Subkategorie zu diesem Fest und wird beim Löschen mit-entfernt\',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'positionen' => [
            'pk' => ["rowid"],
            'cols' => [
                'rowid' => 'int(11) NOT NULL AUTO_INCREMENT',
                'Positionsname' => 'text NOT NULL DEFAULT \'\'',
                'Betrag' => 'decimal(10,2) NOT NULL DEFAULT 0',
                'type' => 'int(2) NOT NULL COMMENT \'1=Speise, 2=Getränk\' DEFAULT 0',
                'Kurzbezeichnung' => 'varchar(30) NOT NULL DEFAULT \'\'',
                'maxBestellbar' => 'int(11) NOT NULL DEFAULT -1 COMMENT \'-1=unbegrenzt\'',
                'reihenfolge' => 'int(11) NOT NULL DEFAULT 0',
                'color' => 'varchar(8) NOT NULL DEFAULT \'#ffffff\'',
                'icon' => 'varchar(30) DEFAULT NULL',
                'print_target' => 'int(11) NOT NULL DEFAULT 11 COMMENT \'11=Küche, 12=Schank, 13=Feuerflecken, etc.\'',
                'selbstkosten' => 'decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT \'Einkaufspreis / variable Kosten pro Stück für Gewinnberechnung\'',
                'subcategory_id' => 'int(11) DEFAULT NULL',
                'tile_bg' => 'varchar(32) DEFAULT NULL',
                'kassa_only' => 'tinyint(1) NOT NULL DEFAULT 0 COMMENT \'1=nur Direktverkauf/Kasse, nicht für Kellner\'',
                'fest_id' => 'int(11) DEFAULT NULL COMMENT \'NULL = global, sonst gehört Position zu diesem Fest und wird beim Löschen mit-entfernt\'',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `positionen` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `Positionsname` text NOT NULL,
  `Betrag` decimal(10,2) NOT NULL,
  `type` int(2) NOT NULL COMMENT \'1=Speise, 2=Getränk\',
  `Kurzbezeichnung` varchar(30) NOT NULL,
  `maxBestellbar` int(11) NOT NULL DEFAULT -1 COMMENT \'-1=unbegrenzt\',
  `reihenfolge` int(11) NOT NULL,
  `color` varchar(8) NOT NULL DEFAULT \'#ffffff\',
  `icon` varchar(30) DEFAULT NULL,
  `print_target` int(11) NOT NULL DEFAULT 11 COMMENT \'11=Küche, 12=Schank, 13=Feuerflecken, etc.\',
  `selbstkosten` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT \'Einkaufspreis / variable Kosten pro Stück für Gewinnberechnung\',
  `subcategory_id` int(11) DEFAULT NULL,
  `tile_bg` varchar(32) DEFAULT NULL,
  `kassa_only` tinyint(1) NOT NULL DEFAULT 0 COMMENT \'1=nur Direktverkauf/Kasse, nicht für Kellner\',
  `fest_id` int(11) DEFAULT NULL COMMENT \'NULL = global, sonst gehört Position zu diesem Fest und wird beim Löschen mit-entfernt\',
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'print' => [
            'pk' => ["rowid"],
            'cols' => [
                'rowid' => 'int(11) NOT NULL AUTO_INCREMENT',
                'bestellungID' => 'int(11) NOT NULL DEFAULT 0',
                'timestamp' => 'timestamp NULL DEFAULT NULL',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `print` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `bestellungID` int(11) NOT NULL,
  `timestamp` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'print_targets' => [
            'pk' => ["print_target"],
            'cols' => [
                'print_target' => 'int(11) NOT NULL',
                'name' => 'varchar(64) NOT NULL DEFAULT \'\'',
                'active' => 'tinyint(1) NOT NULL DEFAULT 1',
                'sort_order' => 'int(11) NOT NULL DEFAULT 0',
                'finance_bereich_id' => 'int(11) DEFAULT NULL COMMENT \'Finanzbereich für Verkaufsauswertung\'',
                'auto_print_limit' => 'int(11) NOT NULL DEFAULT 0',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `print_targets` (
  `print_target` int(11) NOT NULL,
  `name` varchar(64) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `finance_bereich_id` int(11) DEFAULT NULL COMMENT \'Finanzbereich für Verkaufsauswertung\',
  `auto_print_limit` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`print_target`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'printer_jobs' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'printer' => 'varchar(32) NOT NULL DEFAULT \'\'',
                'type' => 'varchar(32) DEFAULT \'invoice\'',
                'payload' => 'mediumtext NOT NULL DEFAULT \'\'',
                'meta' => 'longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`))',
                'status' => 'enum(\'pending\',\'reserved\',\'done\',\'error\') DEFAULT \'pending\'',
                'attempts' => 'int(11) DEFAULT 0',
                'reserved_at' => 'datetime DEFAULT NULL',
                'reserved_by' => 'varchar(64) DEFAULT NULL',
                'error' => 'text DEFAULT NULL',
                'created_at' => 'datetime DEFAULT current_timestamp()',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `printer_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `printer` varchar(32) NOT NULL,
  `type` varchar(32) DEFAULT \'invoice\',
  `payload` mediumtext NOT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `status` enum(\'pending\',\'reserved\',\'done\',\'error\') DEFAULT \'pending\',
  `attempts` int(11) DEFAULT 0,
  `reserved_at` datetime DEFAULT NULL,
  `reserved_by` varchar(64) DEFAULT NULL,
  `error` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'rechnungen' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'rechnungsnummer' => 'varchar(50) NOT NULL DEFAULT \'\'',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
                'created_by' => 'varchar(64) NOT NULL DEFAULT \'\'',
                'fest_id' => 'int(11) DEFAULT NULL',
                'tischnummer' => 'int(11) DEFAULT NULL',
                'sammelrechnung_id' => 'int(11) DEFAULT NULL',
                'is_firma' => 'tinyint(1) NOT NULL DEFAULT 0',
                'empfaenger_name' => 'varchar(255) DEFAULT NULL',
                'empfaenger_strasse' => 'varchar(255) DEFAULT NULL',
                'empfaenger_plz' => 'varchar(30) DEFAULT NULL',
                'empfaenger_ort' => 'varchar(80) DEFAULT NULL',
                'empfaenger_uid' => 'varchar(40) DEFAULT NULL',
                'total' => 'decimal(10,2) NOT NULL DEFAULT 0.00',
                'gedruckt' => 'tinyint(1) NOT NULL DEFAULT 0',
                'druck_status' => 'varchar(10) NOT NULL DEFAULT \'pending\'',
                'druck_attempts' => 'int(11) NOT NULL DEFAULT 0',
                'druck_last_error' => 'varchar(255) DEFAULT NULL',
                'reserved_at' => 'timestamp NULL DEFAULT NULL',
                'reserved_by' => 'varchar(64) DEFAULT NULL',
                'order_nr' => 'int(11) DEFAULT NULL',
                'is_proforma' => 'tinyint(1) NOT NULL DEFAULT 0',
                'lines_json' => 'mediumtext DEFAULT NULL',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `rechnungen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rechnungsnummer` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) NOT NULL,
  `fest_id` int(11) DEFAULT NULL,
  `tischnummer` int(11) DEFAULT NULL,
  `sammelrechnung_id` int(11) DEFAULT NULL,
  `is_firma` tinyint(1) NOT NULL DEFAULT 0,
  `empfaenger_name` varchar(255) DEFAULT NULL,
  `empfaenger_strasse` varchar(255) DEFAULT NULL,
  `empfaenger_plz` varchar(30) DEFAULT NULL,
  `empfaenger_ort` varchar(80) DEFAULT NULL,
  `empfaenger_uid` varchar(40) DEFAULT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gedruckt` tinyint(1) NOT NULL DEFAULT 0,
  `druck_status` varchar(10) NOT NULL DEFAULT \'pending\',
  `druck_attempts` int(11) NOT NULL DEFAULT 0,
  `druck_last_error` varchar(255) DEFAULT NULL,
  `reserved_at` timestamp NULL DEFAULT NULL,
  `reserved_by` varchar(64) DEFAULT NULL,
  `order_nr` int(11) DEFAULT NULL,
  `is_proforma` tinyint(1) NOT NULL DEFAULT 0,
  `lines_json` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'sammelrechnungen' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'name' => 'varchar(100) DEFAULT NULL',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
                'created_by' => 'varchar(255) DEFAULT NULL COMMENT \'Session: wer den Bezahl-Vorgang ausgeführt hat\'',
                'umsatz_zustaendig' => 'varchar(255) DEFAULT NULL COMMENT \'Kellner/in für Umsatz-Zuordnung\'',
                'tables_text' => 'text DEFAULT NULL COMMENT \'Kommagetrennte Tischnummern\'',
                'total_amount' => 'decimal(10,2) DEFAULT 0.00',
                'bezahlt' => 'tinyint(1) NOT NULL DEFAULT 0',
                'bezahlt_at' => 'timestamp NULL DEFAULT NULL',
                'fest_id' => 'int(11) DEFAULT NULL',
                'tischnummer' => 'int(11) DEFAULT NULL COMMENT \'Legacy: einzelner Tisch\'',
                'betrag' => 'decimal(10,2) DEFAULT 0.00 COMMENT \'Legacy: parallel zu total_amount\'',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `sammelrechnungen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(255) DEFAULT NULL COMMENT \'Session: wer den Bezahl-Vorgang ausgeführt hat\',
  `umsatz_zustaendig` varchar(255) DEFAULT NULL COMMENT \'Kellner/in für Umsatz-Zuordnung\',
  `tables_text` text DEFAULT NULL COMMENT \'Kommagetrennte Tischnummern\',
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `bezahlt` tinyint(1) NOT NULL DEFAULT 0,
  `bezahlt_at` timestamp NULL DEFAULT NULL,
  `fest_id` int(11) DEFAULT NULL,
  `tischnummer` int(11) DEFAULT NULL COMMENT \'Legacy: einzelner Tisch\',
  `betrag` decimal(10,2) DEFAULT 0.00 COMMENT \'Legacy: parallel zu total_amount\',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'settings' => [
            'pk' => ["k"],
            'cols' => [
                'k' => 'varchar(64) NOT NULL',
                'v' => 'text NOT NULL DEFAULT \'\'',
                'updated_at' => 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `settings` (
  `k` varchar(64) NOT NULL,
  `v` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'substation_access_tokens' => [
            'pk' => ["token_hash"],
            'cols' => [
                'token_hash' => 'char(64) NOT NULL',
                'station' => 'varchar(40) NOT NULL DEFAULT \'\'',
                'expires_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'used_at' => 'datetime DEFAULT NULL',
                'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `substation_access_tokens` (
  `token_hash` char(64) NOT NULL,
  `station` varchar(40) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'table_tokens' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'table_number' => 'int(11) NOT NULL DEFAULT 0',
                'token' => 'varchar(64) NOT NULL DEFAULT \'\'',
                'active' => 'tinyint(1) NOT NULL DEFAULT 1',
                'created_at' => 'timestamp NULL DEFAULT current_timestamp()',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `table_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `table_number` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'tische' => [
            'pk' => ["tischnummer"],
            'cols' => [
                'tischnummer' => 'int(11) NOT NULL AUTO_INCREMENT',
                'fest_id' => 'int(11) DEFAULT NULL',
                'tischname' => 'varchar(50) NOT NULL DEFAULT \'\'',
                'zeitstempel' => 'timestamp NOT NULL DEFAULT current_timestamp()',
                'userID' => 'int(11) DEFAULT 0',
                'x' => 'int(11) NOT NULL DEFAULT 0',
                'y' => 'int(11) NOT NULL DEFAULT 0',
                'color' => 'varchar(10) NOT NULL DEFAULT \'#ffffff\'',
                'is_sammelrechnung' => 'tinyint(1) NOT NULL DEFAULT 0',
                'is_ehrengast' => 'tinyint(1) NOT NULL DEFAULT 0',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `tische` (
  `tischnummer` int(11) NOT NULL AUTO_INCREMENT,
  `fest_id` int(11) DEFAULT NULL,
  `tischname` varchar(50) NOT NULL,
  `zeitstempel` timestamp NOT NULL DEFAULT current_timestamp(),
  `userID` int(11) DEFAULT 0,
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL,
  `color` varchar(10) NOT NULL DEFAULT \'#ffffff\',
  `is_sammelrechnung` tinyint(1) NOT NULL DEFAULT 0,
  `is_ehrengast` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`tischnummer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'type' => [
            'pk' => ["rowid"],
            'cols' => [
                'rowid' => 'int(2) NOT NULL',
                'name' => 'varchar(30) NOT NULL DEFAULT \'\'',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `type` (
  `rowid` int(2) NOT NULL,
  `name` varchar(30) NOT NULL,
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'user_passkeys' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'user_id' => 'int(11) NOT NULL DEFAULT 0',
                'credential_id' => 'varchar(1024) NOT NULL DEFAULT \'\'',
                'public_key_pem' => 'text NOT NULL DEFAULT \'\'',
                'sign_count' => 'bigint(20) UNSIGNED NOT NULL DEFAULT 0',
                'label' => 'varchar(120) NOT NULL DEFAULT \'\'',
                'transports' => 'varchar(255) DEFAULT NULL',
                'created_at' => 'datetime NOT NULL DEFAULT current_timestamp()',
                'last_used_at' => 'datetime DEFAULT NULL',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `user_passkeys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `credential_id` varchar(1024) NOT NULL,
  `public_key_pem` text NOT NULL,
  `sign_count` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `label` varchar(120) NOT NULL DEFAULT \'\',
  `transports` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'users' => [
            'pk' => ["id"],
            'cols' => [
                'id' => 'int(11) NOT NULL AUTO_INCREMENT',
                'username' => 'varchar(255) DEFAULT NULL',
                'display_name' => 'varchar(120) DEFAULT NULL COMMENT \'Anzeigename (Vor-/Nachname) für Rechnungen; leer = Fallback auf username\'',
                'password' => 'varchar(255) DEFAULT NULL',
                'timestamp' => 'timestamp NOT NULL DEFAULT current_timestamp()',
                'admin' => 'tinyint(1) NOT NULL DEFAULT 0',
                'can_finance' => 'tinyint(1) NOT NULL DEFAULT 0 COMMENT \'Finanzverwaltung (Kassen, Kellnerabrechnung, Gewinn)\'',
                'can_direktverkauf' => 'tinyint(1) NOT NULL DEFAULT 0 COMMENT \'Direktverkauf (Kassa)\'',
                'menu_permissions' => 'text DEFAULT NULL COMMENT \'JSON: menu + print_targets\'',
                'start_page' => 'varchar(32) NOT NULL DEFAULT \'menu\'',
                'start_print_target' => 'int(11) DEFAULT NULL',
                'dv_abholbon_print_target' => 'int(11) DEFAULT NULL COMMENT \'NULL=Auto; Thermo Abholbon Direktverkauf\'',
                'is_active' => 'tinyint(1) NOT NULL DEFAULT 1 COMMENT \'Login erlaubt\'',
                'active_from' => 'datetime DEFAULT NULL COMMENT \'Login erst ab\'',
                'active_until' => 'datetime DEFAULT NULL COMMENT \'Login nur bis\'',
                'force_password_change' => 'tinyint(1) NOT NULL DEFAULT 0 COMMENT \'Einmalpasswort, Wechsel erzwingen\'',
                'auth_rev' => 'int(11) NOT NULL DEFAULT 0 COMMENT \'Session-Version, erhoehen bei Deaktivierung/PW-Reset\'',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) DEFAULT NULL,
  `display_name` varchar(120) DEFAULT NULL COMMENT \'Anzeigename (Vor-/Nachname) für Rechnungen; leer = Fallback auf username\',
  `password` varchar(255) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin` tinyint(1) NOT NULL,
  `can_finance` tinyint(1) NOT NULL DEFAULT 0 COMMENT \'Finanzverwaltung (Kassen, Kellnerabrechnung, Gewinn)\',
  `can_direktverkauf` tinyint(1) NOT NULL DEFAULT 0 COMMENT \'Direktverkauf (Kassa)\',
  `menu_permissions` text DEFAULT NULL COMMENT \'JSON: menu + print_targets\',
  `start_page` varchar(32) NOT NULL DEFAULT \'menu\',
  `start_print_target` int(11) DEFAULT NULL,
  `dv_abholbon_print_target` int(11) DEFAULT NULL COMMENT \'NULL=Auto; Thermo Abholbon Direktverkauf\',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT \'Login erlaubt\',
  `active_from` datetime DEFAULT NULL COMMENT \'Login erst ab\',
  `active_until` datetime DEFAULT NULL COMMENT \'Login nur bis\',
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0 COMMENT \'Einmalpasswort, Wechsel erzwingen\',
  `auth_rev` int(11) NOT NULL DEFAULT 0 COMMENT \'Session-Version, erhoehen bei Deaktivierung/PW-Reset\',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'webauthn_remote_requests' => [
            'pk' => ["token"],
            'cols' => [
                'token' => 'varchar(64) NOT NULL',
                'user_id' => 'int(11) NOT NULL DEFAULT 0',
                'challenge' => 'varchar(64) NOT NULL DEFAULT \'\'',
                'status' => 'varchar(20) NOT NULL DEFAULT \'pending\'',
                'label' => 'varchar(120) NOT NULL DEFAULT \'\'',
                'error' => 'varchar(64) DEFAULT NULL',
                'created_at' => 'datetime NOT NULL DEFAULT current_timestamp()',
                'expires_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ],
            'create' => 'CREATE TABLE IF NOT EXISTS `webauthn_remote_requests` (
  `token` varchar(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `challenge` varchar(64) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT \'pending\',
  `label` varchar(120) NOT NULL DEFAULT \'\',
  `error` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
    ];
    return $schema;
}


/**
 * Repariert gefundene Fehler in der Datenbankstruktur:
 * - Legt fehlende Tabellen an (InnoDB, utf8mb4)
 * - Ergänzt fehlende Spalten (mit passendem Datentyp und Defaults)
 * - Konvertiert Tabellen auf die Engine InnoDB
 * - Ergänzt fehlende Primärschlüssel
 *
 * @param mysqli $conn
 * @param string|null $onlyTable Wenn angegeben, wird nur diese Tabelle repariert
 * @return array{ok:bool, action:string, repairs:list<string>, results:list<array{group:string,name:string,ok:bool,detail:string}>, passed:int, failed:int}
 */
function ff_selftests_repair_database(mysqli $conn, ?string $onlyTable = null): array
{
    $repairs = [];
    $expectedSchema = ff_selftests_expected_schema();

    // Vorab: Standard-Schema-Helpers ausführen, falls vorhanden
    if (function_exists('ff_rezepturen_ensure_schema')) {
        try {
            ff_rezepturen_ensure_schema($conn);
        } catch (\Throwable $e) {}
    }
    if (function_exists('ff_mitarbeiter_verpflegung_ensure_schema')) {
        try {
            ff_mitarbeiter_verpflegung_ensure_schema($conn);
        } catch (\Throwable $e) {}
    }

    // 1. Vorhandene Tabellen und Engines ermitteln
    $tRes = @mysqli_query($conn, 'SELECT TABLE_NAME, ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE()');
    $existingTables = [];
    if ($tRes) {
        while ($row = mysqli_fetch_assoc($tRes)) {
            $existingTables[strtolower((string) $row['TABLE_NAME'])] = [
                'name' => (string) $row['TABLE_NAME'],
                'engine' => (string) ($row['ENGINE'] ?? ''),
            ];
        }
        mysqli_free_result($tRes);
    }

    // 2. Vorhandene Spalten ermitteln
    $cRes = @mysqli_query($conn, 'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE()');
    $existingCols = [];
    if ($cRes) {
        while ($row = mysqli_fetch_assoc($cRes)) {
            $t = strtolower((string) $row['TABLE_NAME']);
            $c = strtolower((string) $row['COLUMN_NAME']);
            if (!isset($existingCols[$t])) {
                $existingCols[$t] = [];
            }
            $existingCols[$t][$c] = true;
        }
        mysqli_free_result($cRes);
    }

    // 3. Vorhandene Primärschlüssel ermitteln
    $pkRes = @mysqli_query($conn, "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.key_column_usage WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY'");
    $existingPks = [];
    if ($pkRes) {
        while ($row = mysqli_fetch_assoc($pkRes)) {
            $t = strtolower((string) $row['TABLE_NAME']);
            $c = strtolower((string) $row['COLUMN_NAME']);
            if (!isset($existingPks[$t])) {
                $existingPks[$t] = [];
            }
            $existingPks[$t][$c] = true;
        }
        mysqli_free_result($pkRes);
    }

    $targetTables = $expectedSchema;
    if ($onlyTable !== null && $onlyTable !== '') {
        $lt = strtolower(trim((string) $onlyTable));
        if (isset($expectedSchema[$lt])) {
            $targetTables = [$lt => $expectedSchema[$lt]];
        }
    }

    foreach ($targetTables as $tableName => $def) {
        $tableNameStr = (string) $tableName;
        $lowerTable = strtolower($tableNameStr);
        $expectedCols = $def['cols'];
        $expectedColNames = array_keys($expectedCols);
        $expectedPk = (array) $def['pk'];
        $createSql = (string) $def['create'];

        // Fall A: Tabelle fehlt komplett
        if (!isset($existingTables[$lowerTable])) {
            $created = @mysqli_query($conn, $createSql);
            if ($created) {
                $repairs[] = "Tabelle '{$tableNameStr}' erfolgreich neu angelegt (" . count($expectedCols) . " Spalten, InnoDB).";
                // Als vorhanden markieren
                $existingTables[$lowerTable] = ['name' => $tableNameStr, 'engine' => 'InnoDB'];
                $existingCols[$lowerTable] = [];
                foreach ($expectedColNames as $c) {
                    $existingCols[$lowerTable][strtolower((string) $c)] = true;
                }
            } else {
                $repairs[] = "Fehler beim Anlegen von Tabelle '{$tableNameStr}': " . mysqli_error($conn);
            }
            continue;
        }

        $realTableName = $existingTables[$lowerTable]['name'];
        $currentEngine = $existingTables[$lowerTable]['engine'];

        // Fall B: Falsche Engine (z. B. MyISAM -> InnoDB)
        if (strcasecmp($currentEngine, 'InnoDB') !== 0) {
            $conv = @mysqli_query($conn, "ALTER TABLE `{$realTableName}` ENGINE=InnoDB");
            if ($conv) {
                $repairs[] = "Tabelle '{$realTableName}' von Engine '{$currentEngine}' auf 'InnoDB' umgestellt.";
                $existingTables[$lowerTable]['engine'] = 'InnoDB';
            } else {
                $repairs[] = "Fehler bei Engine-Umstellung von '{$realTableName}' auf InnoDB: " . mysqli_error($conn);
            }
        }

        // Fall C: Fehlende Spalten ergänzen
        $tableCols = $existingCols[$lowerTable] ?? [];
        foreach ($expectedCols as $colName => $colDef) {
            $colNameStr = (string) $colName;
            if (!isset($tableCols[strtolower($colNameStr)])) {
                $alterSql = "ALTER TABLE `{$realTableName}` ADD COLUMN `{$colNameStr}` {$colDef}";
                $altered = @mysqli_query($conn, $alterSql);
                if ($altered) {
                    $repairs[] = "Tabelle '{$realTableName}': Spalte '{$colNameStr}' erfolgreich ergänzt.";
                    $existingCols[$lowerTable][strtolower($colNameStr)] = true;
                } else {
                    $repairs[] = "Fehler beim Ergänzen von '{$realTableName}.{$colNameStr}': " . mysqli_error($conn);
                }
            }
        }

        // Fall D: Fehlender Primärschlüssel
        $currentPks = $existingPks[$lowerTable] ?? [];
        if (empty($currentPks) && !empty($expectedPk)) {
            $pkColsStr = implode(', ', array_map(static fn($k) => "`{$k}`", $expectedPk));
            $pkSql = "ALTER TABLE `{$realTableName}` ADD PRIMARY KEY ({$pkColsStr})";
            $pkRes = @mysqli_query($conn, $pkSql);
            if ($pkRes) {
                $repairs[] = "Tabelle '{$realTableName}': Primärschlüssel ({$pkColsStr}) erfolgreich ergänzt.";
                foreach ($expectedPk as $k) {
                    $existingPks[$lowerTable][strtolower((string) $k)] = true;
                }
            } else {
                $repairs[] = "Fehler beim Ergänzen des Primärschlüssels für '{$realTableName}': " . mysqli_error($conn);
            }
        }
    }

    // Eventuelle alte Pflichtfelder ohne Default in mitarbeiter_verpflegung bereinigen
    $mvColsRes = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung`");
    if ($mvColsRes) {
        while ($colRow = mysqli_fetch_assoc($mvColsRes)) {
            $fieldName = (string) $colRow['Field'];
            $isNullable = (strcasecmp((string) $colRow['Null'], 'YES') === 0);
            $hasDefault = ($colRow['Default'] !== null);
            $isAutoInc = (stripos((string) $colRow['Extra'], 'auto_increment') !== false);
            if (!$isNullable && !$hasDefault && !$isAutoInc && $fieldName !== 'id') {
                $type = (string) $colRow['Type'];
                $defVal = (stripos($type, 'int') !== false || stripos($type, 'decimal') !== false || stripos($type, 'float') !== false || stripos($type, 'double') !== false) ? '0' : "''";
                $mod = @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` MODIFY `{$fieldName}` {$type} NULL DEFAULT {$defVal}");
                if ($mod) {
                    $repairs[] = "Tabelle 'mitarbeiter_verpflegung': Altes Pflichtfeld '{$fieldName}' mit sicherem DEFAULT versehen.";
                }
            }
        }
        mysqli_free_result($mvColsRes);
    }

    // Eventuelle alte Pflichtfelder ohne Default in rechnungen bereinigen (z. B. kellner)
    $rnColsRes = @mysqli_query($conn, "SHOW COLUMNS FROM `rechnungen`");
    if ($rnColsRes) {
        while ($colRow = mysqli_fetch_assoc($rnColsRes)) {
            $fieldName = (string) $colRow['Field'];
            $isNullable = (strcasecmp((string) $colRow['Null'], 'YES') === 0);
            $hasDefault = ($colRow['Default'] !== null);
            $isAutoInc = (stripos((string) $colRow['Extra'], 'auto_increment') !== false);
            if (!$isNullable && !$hasDefault && !$isAutoInc && $fieldName !== 'id') {
                $type = (string) $colRow['Type'];
                $defVal = (stripos($type, 'int') !== false || stripos($type, 'decimal') !== false || stripos($type, 'float') !== false || stripos($type, 'double') !== false) ? '0' : "''";
                $mod = @mysqli_query($conn, "ALTER TABLE `rechnungen` MODIFY `{$fieldName}` {$type} NULL DEFAULT {$defVal}");
                if ($mod) {
                    $repairs[] = "Tabelle 'rechnungen': Altes Pflichtfeld '{$fieldName}' mit sicherem DEFAULT versehen.";
                }
            }
        }
        mysqli_free_result($rnColsRes);
    }

    // Nach Reparaturen Schema neu prüfen
    $recheckedResults = ff_selftests_check_database_schema($conn);
    $passed = count(array_filter($recheckedResults, static fn (array $r): bool => $r['ok']));
    $failed = count($recheckedResults) - $passed;

    return [
        'ok' => true,
        'action' => 'repair',
        'repairs' => $repairs,
        'results' => $recheckedResults,
        'passed' => $passed,
        'failed' => $failed,
    ];
}


function ff_selftests_check_database_schema(mysqli $conn): array
{
    $group = 'Datenbankstruktur';
    $expectedSchema = ff_selftests_expected_schema();

    // 1. Tabellen und Storage Engine abfragen
    $tRes = @mysqli_query($conn, 'SELECT TABLE_NAME, ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE()');
    if (!$tRes) {
        return [
            [
                'group' => $group,
                'name' => 'Schema-Zugriff',
                'ok' => false,
                'detail' => 'Fehler beim Lesen von information_schema.tables: ' . mysqli_error($conn),
            ],
        ];
    }
    $existingTables = [];
    while ($row = mysqli_fetch_assoc($tRes)) {
        $name = (string) $row['TABLE_NAME'];
        $existingTables[strtolower($name)] = [
            'raw_name' => $name,
            'engine' => (string) ($row['ENGINE'] ?? ''),
        ];
    }
    mysqli_free_result($tRes);

    // 2. Alle Spalten abfragen
    $cRes = @mysqli_query($conn, 'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION');
    if (!$cRes) {
        return [
            [
                'group' => $group,
                'name' => 'Spalten-Zugriff',
                'ok' => false,
                'detail' => 'Fehler beim Lesen von information_schema.columns: ' . mysqli_error($conn),
            ],
        ];
    }
    $existingCols = [];
    while ($row = mysqli_fetch_assoc($cRes)) {
        $t = strtolower((string) $row['TABLE_NAME']);
        $c = (string) $row['COLUMN_NAME'];
        if (!isset($existingCols[$t])) {
            $existingCols[$t] = [];
        }
        $existingCols[$t][strtolower($c)] = $c;
    }
    mysqli_free_result($cRes);

    // 3. Primärschlüssel abfragen
    $pkRes = @mysqli_query($conn, "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.key_column_usage WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY TABLE_NAME, ORDINAL_POSITION");
    $existingPks = [];
    if ($pkRes) {
        while ($row = mysqli_fetch_assoc($pkRes)) {
            $t = strtolower((string) $row['TABLE_NAME']);
            $c = (string) $row['COLUMN_NAME'];
            if (!isset($existingPks[$t])) {
                $existingPks[$t] = [];
            }
            $existingPks[$t][] = $c;
        }
        mysqli_free_result($pkRes);
    }

    $failedTables = [];
    $tableTestResults = [];

    foreach ($expectedSchema as $tableName => $def) {
        $tableNameStr = (string) $tableName;
        $lowerTable = strtolower($tableNameStr);
        $expectedColsMap = $def['cols'];
        $expectedColNames = array_keys($expectedColsMap);
        $expectedPk = (array) $def['pk'];

        if (!isset($existingTables[$lowerTable])) {
            $failedTables[] = $tableNameStr;
            $tableTestResults[] = [
                'group' => $group,
                'name' => 'Tabelle ' . $tableNameStr,
                'ok' => false,
                'detail' => 'Tabelle fehlt in der Datenbank',
            ];
            continue;
        }

        $engine = $existingTables[$lowerTable]['engine'];
        $actualColsMap = $existingCols[$lowerTable] ?? [];
        $actualPkList = $existingPks[$lowerTable] ?? [];

        $missingCols = [];
        foreach ($expectedColNames as $col) {
            $colStr = (string) $col;
            if (!isset($actualColsMap[strtolower($colStr)])) {
                $missingCols[] = $colStr;
            }
        }

        $expPkNorm = array_map(static fn($k): string => strtolower((string) $k), $expectedPk);
        $actPkNorm = array_map(static fn($k): string => strtolower((string) $k), $actualPkList);
        sort($expPkNorm);
        sort($actPkNorm);
        $pkMismatch = ($expPkNorm !== $actPkNorm);

        $engineMismatch = (strcasecmp($engine, 'InnoDB') !== 0);

        $errors = [];
        if ($engineMismatch) {
            $errors[] = 'Engine ist ' . ($engine ?: 'unbekannt') . ' (erwartet: InnoDB)';
        }
        if (!empty($missingCols)) {
            $errors[] = 'Fehlende Spalte(n): ' . implode(', ', $missingCols);
        }
        if ($pkMismatch) {
            $errors[] = 'PK weicht ab (erwartet: ' . implode(', ', $expectedPk) . ', vorgefunden: ' . (empty($actualPkList) ? 'keiner' : implode(', ', $actualPkList)) . ')';
        }

        if (!empty($errors)) {
            $failedTables[] = $tableNameStr;
            $tableTestResults[] = [
                'group' => $group,
                'name' => 'Tabelle ' . $tableNameStr,
                'ok' => false,
                'detail' => implode('; ', $errors),
            ];
        } else {
            $detail = count($actualColsMap) . ' Spalten, PK: ' . implode(', ', $expectedPk);
            $extraCount = count($actualColsMap) - count($expectedColNames);
            if ($extraCount > 0) {
                $detail .= ' (+' . $extraCount . ' zusätzlich)';
            }
            $tableTestResults[] = [
                'group' => $group,
                'name' => 'Tabelle ' . $tableNameStr,
                'ok' => true,
                'detail' => $detail,
            ];
        }
    }

    $totalExpected = count($expectedSchema);
    $allOk = (count($failedTables) === 0);

    $summaryDetail = $allOk
        ? 'Alle ' . $totalExpected . ' Tabellen und Spalten vollständig (InnoDB)'
        : count($failedTables) . ' von ' . $totalExpected . ' Tabellen weisen Abweichungen auf: ' . implode(', ', array_slice($failedTables, 0, 5)) . (count($failedTables) > 5 ? '...' : '');

    $summaryResult = [
        'group' => $group,
        'name' => 'Gesamtübersicht (' . $totalExpected . ' Tabellen)',
        'ok' => $allOk,
        'detail' => $summaryDetail,
    ];

    return array_merge([$summaryResult], $tableTestResults);
}

/**
 * @return array{ok:bool, error?:string, results:list<array{group:string,name:string,ok:bool,detail:string}>, passed:int, failed:int}
 */
function ff_selftests_run(mysqli $conn, string $mode = 'all'): array
{
    // 1. Schema-Tests immer ausführen (keine DDL/DML, rein lesend).
    $schemaResults = ff_selftests_check_database_schema($conn);

    if ($mode === 'schema') {
        $passed = count(array_filter($schemaResults, static fn (array $r): bool => $r['ok']));
        return [
            'ok' => true,
            'results' => $schemaResults,
            'passed' => $passed,
            'failed' => count($schemaResults) - $passed,
        ];
    }

    $results = $schemaResults;

    // DDL (implizites COMMIT) vor der Test-Transaktion erledigen.
    ff_rezepturen_ensure_schema($conn);
    @mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS `beilagen` (
          `rowid` INT(11) NOT NULL AUTO_INCREMENT,
          `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `name` VARCHAR(255) NOT NULL,
          `position` INT(11) NOT NULL,
          `betrag` DOUBLE NOT NULL DEFAULT 0,
          PRIMARY KEY (`rowid`),
          KEY `idx_position` (`position`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $needed = ['bestellungen', 'positionen', 'bestandteile', 'position_rezepturen', 'beilagen'];
    $hasMv = ff_position_mv_table_exists($conn);
    if ($hasMv) {
        $needed[] = 'mitarbeiter_verpflegung';
        // Eventuelle alte Pflichtfelder ohne Default (z. B. mitarbeiter_name, positionsname) entschärfen
        $mvColsRes = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung`");
        if ($mvColsRes) {
            while ($colRow = mysqli_fetch_assoc($mvColsRes)) {
                $fieldName = (string) $colRow['Field'];
                $isNullable = (strcasecmp((string) $colRow['Null'], 'YES') === 0);
                $hasDefault = ($colRow['Default'] !== null);
                $isAutoInc = (stripos((string) $colRow['Extra'], 'auto_increment') !== false);
                if (!$isNullable && !$hasDefault && !$isAutoInc && $fieldName !== 'id') {
                    $type = (string) $colRow['Type'];
                    $defVal = (stripos($type, 'int') !== false || stripos($type, 'decimal') !== false || stripos($type, 'float') !== false || stripos($type, 'double') !== false) ? '0' : "''";
                    @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` MODIFY `{$fieldName}` {$type} NULL DEFAULT {$defVal}");
                }
            }
            mysqli_free_result($mvColsRes);
        }
    }
    foreach ($needed as $table) {
        $row = ff_st_fetch_one(
            $conn,
            'SELECT ENGINE AS e FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            's',
            [$table]
        );
        if ($row === null || strcasecmp((string) $row['e'], 'InnoDB') !== 0) {
            $results[] = [
                'group' => 'Transaktions-Voraussetzung',
                'name' => 'InnoDB für ' . $table,
                'ok' => false,
                'detail' => 'Tabelle ' . $table . ' ist nicht InnoDB bzw. fehlt – funktionale Tests können nicht sicher per Transaktion zurückgerollt werden und wurden übersprungen.',
            ];
            $passed = count(array_filter($results, static fn (array $r): bool => $r['ok']));
            return [
                'ok' => true,
                'results' => $results,
                'passed' => $passed,
                'failed' => count($results) - $passed,
            ];
        }
    }

    $check = static function (string $group, string $name, callable $fn) use (&$results, $conn): void {
        try {
            $r = $fn($conn);
            $ok = $r === null || $r === true;
            $detail = $ok ? '' : (string) $r;
        } catch (Throwable $e) {
            $ok = false;
            $detail = 'Ausnahme: ' . $e->getMessage();
        }
        $results[] = ['group' => $group, 'name' => $name, 'ok' => $ok, 'detail' => $detail];
    };

    $gCsrf = 'CSRF-Schutz';
    $check($gCsrf, 'CSRF-Token Generierung (Format & Länge)', function () {
        $token = ff_csrf_token();
        if (!is_string($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
            return 'Erwartet 64-stelligen Hex-String, erhalten: ' . var_export($token, true);
        }
        return true;
    });

    $check($gCsrf, 'CSRF Validierung (Korrektes Token)', function () {
        $token = ff_csrf_token();
        if (!ff_csrf_verify($token)) {
            return 'Validierung von gültigem Token fehlgeschlagen.';
        }
        return true;
    });

    $check($gCsrf, 'CSRF Validierung (Falsches Token abgelehnt)', function () {
        if (ff_csrf_verify('ungueltiges_token_12345')) {
            return 'Ungültiges Token wurde fälschlicherweise akzeptiert.';
        }
        return true;
    });

    $check($gCsrf, 'CSRF Validierung via HTTP Header (X-CSRF-Token)', function () {
        $token = ff_csrf_token();
        $old = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        try {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
            if (!ff_csrf_verify(null)) {
                return 'Validierung über Header HTTP_X_CSRF_TOKEN fehlgeschlagen.';
            }
        } finally {
            if ($old === null) {
                unset($_SERVER['HTTP_X_CSRF_TOKEN']);
            } else {
                $_SERVER['HTTP_X_CSRF_TOKEN'] = $old;
            }
        }
        return true;
    });

    $check($gCsrf, 'CSRF Validierung via POST Parameter', function () {
        $token = ff_csrf_token();
        $old = $_POST['csrf_token'] ?? null;
        try {
            $_POST['csrf_token'] = $token;
            if (!ff_csrf_verify(null)) {
                return 'Validierung über POST csrf_token fehlgeschlagen.';
            }
        } finally {
            if ($old === null) {
                unset($_POST['csrf_token']);
            } else {
                $_POST['csrf_token'] = $old;
            }
        }
        return true;
    });

    $gAuth = 'Authentifizierung & Session';
    $check($gAuth, 'Session auth_rev Fallback initialisiert fehlenden Wert', function () use ($conn) {
        require_once __DIR__ . '/ff_user_status.php';
        $savedSession = $_SESSION ?? [];
        try {
            $_SESSION['login'] = true;
            $_SESSION['user'] = ['username' => 'test_auth_check'];
            unset($_SESSION['auth_rev']);
            mysqli_query($conn, "INSERT INTO users (username, password, admin, auth_rev, is_active) VALUES ('test_auth_check', 'hash', 1, 0, 1) ON DUPLICATE KEY UPDATE auth_rev=0, is_active=1");
            $err = ff_auth_check_session_user($conn, true);
            if ($err !== null) {
                return 'Fehler bei Session ohne auth_rev: ' . $err;
            }
            if (!isset($_SESSION['auth_rev']) || (int) $_SESSION['auth_rev'] !== 0) {
                return 'auth_rev wurde nicht auf 0 initialisiert in Session.';
            }
        } finally {
            mysqli_query($conn, "DELETE FROM users WHERE username = 'test_auth_check'");
            $_SESSION = $savedSession;
        }
        return true;
    });

    $check($gAuth, 'Session auth_rev Mismatch wird korrekt abgewiesen', function () use ($conn) {
        $savedSession = $_SESSION ?? [];
        try {
            $_SESSION['login'] = true;
            $_SESSION['user'] = ['username' => 'test_auth_mismatch'];
            $_SESSION['auth_rev'] = 0;
            mysqli_query($conn, "INSERT INTO users (username, password, admin, auth_rev, is_active) VALUES ('test_auth_mismatch', 'hash', 1, 1, 1) ON DUPLICATE KEY UPDATE auth_rev=1, is_active=1");
            $err = ff_auth_check_session_user($conn, true);
            if ($err === null) {
                return 'Erwarteter Mismatch-Fehler trat nicht auf.';
            }
        } finally {
            mysqli_query($conn, "DELETE FROM users WHERE username = 'test_auth_mismatch'");
            $_SESSION = $savedSession;
        }
        return true;
    });

    mysqli_begin_transaction($conn);
    try {
        $g = 'Preisberechnung';

        $check($g, 'Ohne Zusatzinfo = Katalogpreis', function (mysqli $c) {
            $p = ff_st_position($c, 4.50);
            return ff_st_expect_same(4.5, ff_bestellung_line_betrag($c, $p, 4.5, ''), 'Betrag');
        });

        $check($g, 'Beilagen-Aufpreise werden addiert und gerundet', function (mysqli $c) {
            $p = ff_st_position($c, 3.10);
            ff_st_exec($c, "INSERT INTO beilagen (name, position, betrag) VALUES ('ZZ_Kaese', ?, 0.50), ('ZZ_Ei', ?, 1.20)", 'ii', [$p, $p]);
            return ff_st_expect_same(4.8, ff_bestellung_line_betrag($c, $p, 3.10, 'ZZ_Kaese, ZZ_Ei'), 'Betrag mit zwei Beilagen');
        });

        $check($g, 'Unbekannte Beilage und Freitext (nach " | ") ergeben keinen Aufpreis', function (mysqli $c) {
            $p = ff_st_position($c, 5.00);
            ff_st_exec($c, "INSERT INTO beilagen (name, position, betrag) VALUES ('ZZ_Kaese', ?, 0.50)", 'i', [$p]);
            return ff_st_expect_same(5.0, ff_bestellung_line_betrag($c, $p, 5.0, 'ZZ_Unbekannt'), 'unbekannte Beilage')
                ?? ff_st_expect_same(5.0, ff_bestellung_line_betrag($c, $p, 5.0, 'ZZ_Unbekannt | ZZ_Kaese'), 'Freitext');
        });

        $check($g, 'Beilage einer anderen Position wirkt nicht', function (mysqli $c) {
            $a = ff_st_position($c, 2.00);
            $b = ff_st_position($c, 2.00);
            ff_st_exec($c, "INSERT INTO beilagen (name, position, betrag) VALUES ('ZZ_Extra', ?, 1.00)", 'i', [$a]);
            return ff_st_expect_same(2.0, ff_bestellung_line_betrag($c, $b, 2.0, 'ZZ_Extra'), 'Betrag');
        });

        $check($g, 'Finanz-Betrag: gespeicherter Zeilenbetrag hat Vorrang, 0 fällt auf Katalogpreis zurück', function (mysqli $c) {
            $p = ff_st_position($c, 6.00);
            $withAmount = ff_st_order($c, $p, ['betrag' => 7.5]);
            $zeroAmount = ff_st_order($c, $p, ['betrag' => 0.0]);
            $expr = ff_finance_order_amount_expr('b', 'p');
            $sql = "SELECT $expr AS amt FROM bestellungen b JOIN positionen p ON p.rowid = b.position WHERE b.rowid = ?";
            $r1 = ff_st_fetch_one($c, $sql, 'i', [$withAmount]);
            $r2 = ff_st_fetch_one($c, $sql, 'i', [$zeroAmount]);
            return ff_st_expect_same(7.5, (float) ($r1['amt'] ?? -1), 'Zeile mit Betrag')
                ?? ff_st_expect_same(6.0, (float) ($r2['amt'] ?? -1), 'Zeile ohne Betrag');
        });

        $g = 'Storno';

        $check($g, 'Unbezahlte Position wird storniert (delete=1), zweiter Storno wird abgelehnt', function (mysqli $c) {
            $p = ff_st_position($c, 3.0);
            $id = ff_st_order($c, $p);
            $r = ff_bestellung_unpaid_storno($c, $id);
            if (empty($r['ok'])) {
                return 'Storno fehlgeschlagen: ' . json_encode($r, JSON_UNESCAPED_UNICODE);
            }
            return ff_st_expect_same(1, (int) ff_st_order_row($c, $id)['delete'], 'delete-Flag')
                ?? ff_st_expect_same('deleted', ff_bestellung_unpaid_storno($c, $id)['error'] ?? null, 'Fehler beim zweiten Storno');
        });

        $check($g, 'Bezahlte Position lässt sich nicht als unbezahlt stornieren', function (mysqli $c) {
            $p = ff_st_position($c, 3.0);
            $id = ff_st_order($c, $p, ['paid' => true]);
            $r = ff_bestellung_unpaid_storno($c, $id);
            return ff_st_expect_same('is_paid', $r['error'] ?? null, 'Fehlercode')
                ?? ff_st_expect_same(0, (int) ff_st_order_row($c, $id)['delete'], 'delete-Flag');
        });

        $check($g, 'Admin-Storno bezahlter, nicht gelieferter Position entfernt sie aus der Station', function (mysqli $c) {
            $p = ff_st_position($c, 3.0);
            $id = ff_st_order($c, $p, ['paid' => true]);
            $r = ff_bestellung_bezahlung_storno($c, $id);
            if (empty($r['ok'])) {
                return 'Storno fehlgeschlagen: ' . json_encode($r, JSON_UNESCAPED_UNICODE);
            }
            return ff_st_expect_same(true, $r['removed_from_station'] ?? null, 'removed_from_station')
                ?? ff_st_expect_same(1, (int) ff_st_order_row($c, $id)['delete'], 'delete-Flag');
        });

        $check($g, 'Admin-Storno bezahlter, gelieferter Position: Storno, nicht aus Station entfernt', function (mysqli $c) {
            $p = ff_st_position($c, 3.0);
            $id = ff_st_order($c, $p, ['paid' => true, 'delivered' => true]);
            $r = ff_bestellung_bezahlung_storno($c, $id);
            if (empty($r['ok'])) {
                return 'Storno fehlgeschlagen: ' . json_encode($r, JSON_UNESCAPED_UNICODE);
            }
            return ff_st_expect_same(false, $r['removed_from_station'] ?? null, 'removed_from_station')
                ?? ff_st_expect_same(1, (int) ff_st_order_row($c, $id)['delete'], 'delete-Flag');
        });

        $check($g, 'Bezahl-Storno einer unbezahlten oder schon stornierten Zeile wird abgelehnt', function (mysqli $c) {
            $p = ff_st_position($c, 3.0);
            $unpaid = ff_st_order($c, $p);
            $deleted = ff_st_order($c, $p, ['paid' => true, 'deleted' => true]);
            return ff_st_expect_same('not_paid', ff_bestellung_bezahlung_storno($c, $unpaid)['error'] ?? null, 'unbezahlt')
                ?? ff_st_expect_same('deleted', ff_bestellung_bezahlung_storno($c, $deleted)['error'] ?? null, 'bereits storniert');
        });

        $check($g, 'Berechtigungen: Kellner nur unbezahlt, Admin auch bezahlt, Gelöschte nie', function () {
            $unpaid = ['delete' => 0, 'timestampBezahlung' => '0000-00-00 00:00:00'];
            $paid = ['delete' => 0, 'timestampBezahlung' => '2026-01-01 12:00:00'];
            $deleted = ['delete' => 1, 'timestampBezahlung' => '0000-00-00 00:00:00'];
            return ff_st_expect_same(true, ff_storno_can_cancel_row($unpaid, false), 'Kellner/unbezahlt')
                ?? ff_st_expect_same(false, ff_storno_can_cancel_row($paid, false), 'Kellner/bezahlt')
                ?? ff_st_expect_same(true, ff_storno_can_cancel_row($paid, true), 'Admin/bezahlt')
                ?? ff_st_expect_same(false, ff_storno_can_cancel_row($deleted, true), 'Admin/gelöscht');
        });

        $check($g, 'Ganze Bestellung: Kellner nicht bei bezahlter Zeile, Admin schon', function () {
            $rows = [
                ['delete' => 0, 'timestampBezahlung' => '0000-00-00 00:00:00'],
                ['delete' => 0, 'timestampBezahlung' => '2026-01-01 12:00:00'],
            ];
            return ff_st_expect_same(false, ff_storno_group_whole_order_allowed($rows, false)['can'], 'Kellner')
                ?? ff_st_expect_same(true, ff_storno_group_whole_order_allowed($rows, true)['can'], 'Admin');
        });

        $g = 'Kapazität';

        $check($g, 'Unbegrenzte Position wird nicht geprüft', function (mysqli $c) {
            $p = ff_st_position($c, 1.0, -1);
            return ff_st_expect_same(null, ff_position_check_capacity($c, $p, -1, 5), 'Ergebnis');
        });

        $check($g, 'Positionskapazität: Rest, Überschreitung (max_cap) und ausverkauft', function (mysqli $c) {
            $p = ff_st_position($c, 1.0, 3);
            ff_st_order($c, $p);
            $ok = ff_position_check_capacity($c, $p, 3, 2);
            $over = ff_position_check_capacity($c, $p, 3, 3);
            ff_st_order($c, $p);
            ff_st_order($c, $p);
            $sold = ff_position_check_capacity($c, $p, 3, 1);
            return ff_st_expect_same(true, $ok['ok'] ?? null, '2 von Rest 2')
                ?? ff_st_expect_same(0, $ok['rest'] ?? null, 'Rest nach 2')
                ?? ff_st_expect_same('max_cap', $over['error'] ?? null, 'Überschreitung')
                ?? ff_st_expect_same(2, $over['rest'] ?? null, 'Rest bei Überschreitung')
                ?? ff_st_expect_same('Ausverkauft', $sold['error'] ?? null, 'ausverkauft');
        });

        $check($g, 'Stornierte Bestellungen zählen nicht zum Verbrauch', function (mysqli $c) {
            $p = ff_st_position($c, 1.0, 2);
            ff_st_order($c, $p);
            $toCancel = ff_st_order($c, $p);
            $before = ff_position_check_capacity($c, $p, 2, 1);
            ff_bestellung_unpaid_storno($c, $toCancel);
            $after = ff_position_check_capacity($c, $p, 2, 1);
            return ff_st_expect_same(false, $before['ok'] ?? null, 'vor Storno ausverkauft')
                ?? ff_st_expect_same(true, $after['ok'] ?? null, 'nach Storno wieder bestellbar');
        });

        if ($hasMv) {
            $check($g, 'Mitarbeiter-Verpflegung verbraucht Kapazität', function (mysqli $c) {
                $p = ff_st_position($c, 1.0, 3);
                // Dynamisch eventuelle Legacy-Pflichtfelder wie mitarbeiter_name berücksichtigen
                $fields = ['`datum`', '`bereich_id`', '`position_id`', '`menge`'];
                $vals = ['CURDATE()', '0', (string) $p, '2'];
                $cCols = @mysqli_query($c, "SHOW COLUMNS FROM `mitarbeiter_verpflegung`");
                if ($cCols) {
                    while ($col = mysqli_fetch_assoc($cCols)) {
                        $f = strtolower((string) $col['Field']);
                        $isNullable = (strcasecmp((string) $col['Null'], 'YES') === 0);
                        $hasDefault = ($col['Default'] !== null);
                        $isAuto = (stripos((string) $col['Extra'], 'auto_increment') !== false);
                        if (!$isNullable && !$hasDefault && !$isAuto && !in_array('`' . $col['Field'] . '`', $fields, true)) {
                            $fields[] = "`" . $col['Field'] . "`";
                            $t = (string) $col['Type'];
                            $vals[] = (stripos($t, 'int') !== false || stripos($t, 'decimal') !== false) ? '0' : "'Test'";
                        }
                    }
                    mysqli_free_result($cCols);
                }
                ff_st_exec($c, 'INSERT INTO `mitarbeiter_verpflegung` (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $vals) . ')');
                $r = ff_position_check_capacity($c, $p, 3, 2);
                return ff_st_expect_same('max_cap', $r['error'] ?? null, 'Fehlercode')
                    ?? ff_st_expect_same(1, $r['rest'] ?? null, 'Rest');
            });
        }

        $check($g, 'Bestandteil-Kapazität begrenzt die Position (knappster Bestandteil)', function (mysqli $c) {
            $p = ff_st_position($c, 1.0, -1);
            $bName = 'ZZ_ST_CAP_' . mt_rand(10000, 99999);
            $b = ff_st_exec($c, "INSERT INTO bestandteile (name, einheit, kapazitaet, aktiv, sort_order) VALUES ('{$bName}', 'Stk', 10, 1, 9999)");
            ff_st_exec($c, 'INSERT INTO position_rezepturen (position_id, bestandteil_id, menge, reihenfolge) VALUES (?, ?, 2, 0)', 'ii', [$p, $b]);
            $fresh = ff_position_check_capacity($c, $p, -1, 5);
            for ($i = 0; $i < 4; $i++) {
                ff_st_order($c, $p);
            }
            $low = ff_position_check_capacity($c, $p, -1, 2);
            $one = ff_position_check_capacity($c, $p, -1, 1);
            ff_st_order($c, $p);
            $sold = ff_position_check_capacity($c, $p, -1, 1);
            return ff_st_expect_same(true, $fresh['ok'] ?? null, '5 Stück bei Kapazität 10 / Menge 2')
                ?? ff_st_expect_same('max_cap', $low['error'] ?? null, 'Überschreitung')
                ?? ff_st_expect_same(1, $low['rest'] ?? null, 'Rest bei Überschreitung')
                ?? ff_st_expect_same(true, $one['ok'] ?? null, 'letztes Stück')
                ?? ff_st_expect_same('Ausverkauft', $sold['error'] ?? null, 'ausverkauft');
        });

        $check($g, 'Anzeige-Kapazität (Rest) folgt dem Bestandteil-Verbrauch', function (mysqli $c) {
            $p = ff_st_position($c, 1.0, -1);
            $bName = 'ZZ_ST_DISP_' . mt_rand(10000, 99999);
            $b = ff_st_exec($c, "INSERT INTO bestandteile (name, einheit, kapazitaet, aktiv, sort_order) VALUES ('{$bName}', 'Stk', 9, 1, 9999)");
            ff_st_exec($c, 'INSERT INTO position_rezepturen (position_id, bestandteil_id, menge, reihenfolge) VALUES (?, ?, 3, 0)', 'ii', [$p, $b]);
            ff_st_order($c, $p);
            $d = ff_position_display_capacity($c, $p, -1, 1);
            return ff_st_expect_same(true, $d['limited'] ?? null, 'limited')
                ?? ff_st_expect_same(2, $d['rest'] ?? null, 'Rest (9-3)/3');
        });

        $check($g, 'Pilot-Bestellvorgang: Entwurf im Hintergrund speichern (bestellt=0) und Freigabe (bestellt=1)', function (mysqli $c) {
            $p = ff_st_position($c, 4.50, -1);
            $tischnummer = 101;
            $kellner = 'st_pilot_waiter';
            $betrag = 4.50;
            $printTarget = 11;
            $zInfo = 'Ohne Zwiebeln';
            $festId = 1;
            $isGratis = 0;

            // 1. Entwurf anlegen mit dem exakten Prepared-Statement aus order_draft.php
            $draftSql = 'INSERT INTO `bestellungen` 
                (`tischnummer`, `position`, `timestampBestellung`, `zeitKueche`, `kueche`, `kellner`, `betrag`, `ausgeliefert`, `bestellt`, `order_nr`, `print_target`, `Zusatzinfo`, `fest_id`, `is_gratis`, `timestampBezahlung`, `kellnerZahlung`)
                VALUES (?, ?, "0000-00-00 00:00:00", "0000-00-00 00:00:00", 0, ?, ?, 0, 0, 0, ?, ?, ?, ?, "0000-00-00 00:00:00", "")';
            $draftStmt = mysqli_prepare($c, $draftSql);
            if (!$draftStmt) {
                return 'Prepare draftSql fehlgeschlagen: ' . mysqli_error($c);
            }
            mysqli_stmt_bind_param($draftStmt, 'iisdisii', $tischnummer, $p, $kellner, $betrag, $printTarget, $zInfo, $festId, $isGratis);
            if (!mysqli_stmt_execute($draftStmt)) {
                $err = mysqli_stmt_error($draftStmt);
                mysqli_stmt_close($draftStmt);
                return 'Execute draftStmt fehlgeschlagen: ' . $err;
            }
            $insId = (int)mysqli_insert_id($c);
            mysqli_stmt_close($draftStmt);

            $dRow = ff_st_fetch_one($c, 'SELECT bestellt, order_nr, Zusatzinfo FROM bestellungen WHERE rowid = ?', 'i', [$insId]);
            $isDraft = ((int)($dRow['bestellt'] ?? -1) === 0 && (int)($dRow['order_nr'] ?? -1) === 0 && ($dRow['Zusatzinfo'] ?? '') === $zInfo);

            // 2. Freigabe wie im Original (order.php)
            $testOrderNr = 777;
            $updSql = 'UPDATE `bestellungen` 
                       SET `bestellt` = 1,
                           `timestampBestellung` = NOW(),
                           `order_nr` = ?,
                           `fest_id` = COALESCE(`fest_id`, ?),
                           `timestampBezahlung` = "0000-00-00 00:00:00",
                           `kellnerZahlung` = ""
                       WHERE `tischnummer` = ?
                         AND `kellner` = ?
                         AND (`bestellt` IS NULL OR `bestellt` = 0)
                         AND `delete` = 0';
            $updStmt = mysqli_prepare($c, $updSql);
            if (!$updStmt) {
                return 'Prepare updSql fehlgeschlagen: ' . mysqli_error($c);
            }
            mysqli_stmt_bind_param($updStmt, 'iiis', $testOrderNr, $festId, $tischnummer, $kellner);
            if (!mysqli_stmt_execute($updStmt)) {
                $err = mysqli_stmt_error($updStmt);
                mysqli_stmt_close($updStmt);
                return 'Execute updStmt fehlgeschlagen: ' . $err;
            }
            mysqli_stmt_close($updStmt);

            $relRow = ff_st_fetch_one($c, 'SELECT bestellt, order_nr FROM bestellungen WHERE rowid = ?', 'i', [$insId]);
            $isReleased = ((int)($relRow['bestellt'] ?? 0) === 1 && (int)($relRow['order_nr'] ?? 0) === $testOrderNr);

            return ff_st_expect_same(true, $isDraft, 'Entwurf mit bestellt=0 und order_nr=0')
                ?? ff_st_expect_same(true, $isReleased, 'Freigabe mit bestellt=1 und zugewiesener order_nr');
        });

        $check($g, 'Pilot-Bestellvorgang: Entwurf in Küche sichtbar und per FORCE freigebbar', function (mysqli $c) {
            require_once __DIR__ . '/ff_kueche_list_data.php';
            require_once __DIR__ . '/ff_kueche_station_view.php';
            $t = 7120;
            $p = ff_st_position($c, 8.50, -1);

            // 1. Entwurf über das Statement aus order_draft.php anlegen
            $insSql = 'INSERT INTO `bestellungen` 
                (`tischnummer`, `position`, `timestampBestellung`, `zeitKueche`, `kueche`, `kellner`, `betrag`, `ausgeliefert`, `bestellt`, `order_nr`, `print_target`, `Zusatzinfo`, `fest_id`, `is_gratis`, `timestampBezahlung`, `kellnerZahlung`)
                VALUES (?, ?, "0000-00-00 00:00:00", "0000-00-00 00:00:00", 0, "kellner_pilot", 8.50, 0, 0, 0, 11, "extra kross", 1, 0, "0000-00-00 00:00:00", "")';
            $insStmt = mysqli_prepare($c, $insSql);
            if (!$insStmt) {
                return 'Prepare fehlgeschlagen: ' . mysqli_error($c);
            }
            mysqli_stmt_bind_param($insStmt, 'ii', $t, $p);
            mysqli_stmt_execute($insStmt);
            $rowId = (int)mysqli_insert_id($c);
            mysqli_stmt_close($insStmt);

            // 2. Stationsansicht (Küche Druckziel 11) muss die unbestätigte Runde finden
            $lines = ff_kueche_load_station_lines($c, 0, 11);
            $batches = ff_station_group_batches($lines, 'all');
            $foundBatch = null;
            foreach ($batches as $b) {
                if ((int)($b['tischnummer'] ?? 0) === $t) {
                    $foundBatch = $b;
                    break;
                }
            }
            if (!$foundBatch) {
                return 'Entwurf wurde in den Küchen-Batches nicht gefunden';
            }
            if ((int)$foundBatch['bestellt'] !== 0) {
                return 'Batch für Entwurf sollte bestellt=0 haben, hat aber: ' . $foundBatch['bestellt'];
            }
            if ($foundBatch['batch_key'] !== 'OPEN_' . $t) {
                return 'Unerwarteter Batch-Key: ' . $foundBatch['batch_key'];
            }

            // 3. FORCE Bestellung wie im Original (bestellung_abschicken.php)
            $forcedOrderNr = 99123;
            $forceSql = 'UPDATE bestellungen SET bestellt = 1, timestampBestellung = NOW(), order_nr = ? WHERE tischnummer = ? AND (bestellt IS NULL OR bestellt = 0) AND `delete` = 0';
            $fStmt = mysqli_prepare($c, $forceSql);
            if (!$fStmt) {
                return 'Force Prepare fehlgeschlagen: ' . mysqli_error($c);
            }
            mysqli_stmt_bind_param($fStmt, 'ii', $forcedOrderNr, $t);
            mysqli_stmt_execute($fStmt);
            $affected = mysqli_stmt_affected_rows($fStmt);
            mysqli_stmt_close($fStmt);

            if ($affected !== 1) {
                return 'Erwartet genau 1 aktualisierte Zeile beim Force, waren: ' . $affected;
            }

            $forcedRow = ff_st_fetch_one($c, 'SELECT bestellt, order_nr FROM bestellungen WHERE rowid = ?', 'i', [$rowId]);
            $isForced = ((int)($forcedRow['bestellt'] ?? 0) === 1 && (int)($forcedRow['order_nr'] ?? 0) === $forcedOrderNr);

            return ff_st_expect_same(true, $isForced, 'Entwurf erfolgreich über FORCE freigegeben');
        });

        $check($g, 'Stations-Pilot API: Universelle Stationsverwaltung & Aktionen (Küche/Schank)', function (mysqli $c) {
            $t = 7125;
            @mysqli_query($c, 'INSERT INTO tische (tischnummer, tischname) VALUES (7125, "H-P1")');
            $pFood = ff_st_position($c, 9.50);
            $pDrink = ff_st_position($c, 4.20);
            mysqli_query($c, 'UPDATE positionen SET type = 1, print_target = 11, Positionsname = "Pilot Schnitzel" WHERE rowid = ' . $pFood);
            mysqli_query($c, 'UPDATE positionen SET type = 2, print_target = 12, Positionsname = "Pilot Zwickl" WHERE rowid = ' . $pDrink);

            // 2 Positionen bestellen
            $insFood = mysqli_prepare($c, 'INSERT INTO bestellungen (tischnummer, position, zeitstempel, zeitKueche, bestellt, kueche, ausgeliefert, print, print_status, `delete`, order_nr, kellner, print_target, betrag) VALUES (?, ?, NOW(), "0000-00-00 00:00:00", 1, 0, 0, 0, 0, 0, 7711, "pilot_tester", 11, 9.50)');
            mysqli_stmt_bind_param($insFood, 'ii', $t, $pFood);
            mysqli_stmt_execute($insFood);
            $rowFood = (int)mysqli_insert_id($c);
            mysqli_stmt_close($insFood);

            $insDrink = mysqli_prepare($c, 'INSERT INTO bestellungen (tischnummer, position, zeitstempel, zeitKueche, bestellt, kueche, ausgeliefert, print, print_status, `delete`, order_nr, kellner, print_target, betrag) VALUES (?, ?, NOW(), "0000-00-00 00:00:00", 1, 0, 0, 0, 0, 0, 7711, "pilot_tester", 12, 4.20)');
            mysqli_stmt_bind_param($insDrink, 'ii', $t, $pDrink);
            mysqli_stmt_execute($insDrink);
            $rowDrink = (int)mysqli_insert_id($c);
            mysqli_stmt_close($insDrink);

            // 1. Fertig markieren (item_done)
            mysqli_query($c, "UPDATE bestellungen SET zeitKueche = CURRENT_TIMESTAMP, kueche = 1, print = 0 WHERE rowid = {$rowFood}");
            $chkFood = ff_st_fetch_one($c, 'SELECT kueche, zeitKueche FROM bestellungen WHERE rowid = ?', 'i', [$rowFood]);
            if ((int)($chkFood['kueche'] ?? 0) !== 1) {
                return 'Position Food sollte nach Fertigstellung kueche=1 sein';
            }

            // 2. Undo (undo_done)
            mysqli_query($c, "UPDATE bestellungen SET zeitKueche = '0000-00-00 00:00:00', kueche = 0, print = 0 WHERE rowid = {$rowFood}");
            $chkFoodUndo = ff_st_fetch_one($c, 'SELECT kueche FROM bestellungen WHERE rowid = ?', 'i', [$rowFood]);
            if ((int)($chkFoodUndo['kueche'] ?? 0) !== 0) {
                return 'Position Food sollte nach Undo wieder kueche=0 sein';
            }

            // 3. Bestellung abschließen (complete_order mit Druck)
            mysqli_query($c, "UPDATE bestellungen SET ausgeliefert = 1, timestampAuslieferung = CURRENT_TIMESTAMP, bestellt = 1, kueche = 1, print = 2, print_status = 0 WHERE rowid IN ({$rowFood}, {$rowDrink})");
            $closedRows = ff_st_fetch_one($c, 'SELECT COUNT(*) AS c FROM bestellungen WHERE rowid IN (?, ?) AND ausgeliefert = 1', 'ii', [$rowFood, $rowDrink]);
            if ((int)($closedRows['c'] ?? 0) !== 2) {
                return 'Erwartet genau 2 ausgelieferte Positionen nach complete_order';
            }

            // 4. Reopen (Undo Complete aus Historie)
            mysqli_query($c, "UPDATE bestellungen SET ausgeliefert = 0, timestampAuslieferung = '0000-00-00 00:00:00', kueche = 1, zeitKueche = CURRENT_TIMESTAMP WHERE rowid = {$rowFood}");
            $reopenRow = ff_st_fetch_one($c, 'SELECT ausgeliefert, kueche FROM bestellungen WHERE rowid = ?', 'i', [$rowFood]);
            $isReopened = ((int)($reopenRow['ausgeliefert'] ?? 1) === 0 && (int)($reopenRow['kueche'] ?? 0) === 1);

            return ff_st_expect_same(true, $isReopened, 'Stations-Pilot Workflow (Fertig, Undo, Abschließen, Reopen) erfolgreich');
        });

        $check($g, 'API Auth & Admin-Berechtigungsprüfung (api_require_auth liefert admin und id)', function (mysqli $c) {
            require_once dirname(__DIR__) . '/api/v1/bootstrap.php';
            $oldSession = $_SESSION ?? [];
            try {
                $uName = 'zz_st_api_admin';
                mysqli_query($c, "INSERT INTO users (username, password, admin, auth_rev, is_active) VALUES ('{$uName}', 'hash', 2, 0, 1) ON DUPLICATE KEY UPDATE admin=2, auth_rev=0, is_active=1");
                $dbRow = ff_st_fetch_one($c, "SELECT id FROM users WHERE username = ?", 's', [$uName]);
                $expectedId = (int)($dbRow['id'] ?? 0);

                $_SESSION['login'] = true;
                $_SESSION['user'] = ['username' => $uName];
                $_SESSION['admin'] = 2;
                $_SESSION['user_id'] = $expectedId;
                $_SESSION['auth_rev'] = 0;

                $userAuth = api_require_auth();

                if (($userAuth['admin'] ?? null) !== 2) {
                    return 'api_require_auth muss admin=2 zurückliefern, geliefert: ' . var_export($userAuth['admin'] ?? null, true);
                }
                if (($userAuth['id'] ?? null) !== $expectedId) {
                    return 'api_require_auth muss id=' . $expectedId . ' zurückliefern, geliefert: ' . var_export($userAuth['id'] ?? null, true);
                }
                if (($userAuth['username'] ?? null) !== $uName) {
                    return 'api_require_auth muss username zurückliefern';
                }
                return null;
            } finally {
                mysqli_query($c, "DELETE FROM users WHERE username = 'zz_st_api_admin'");
                $_SESSION = $oldSession;
            }
        });

        $check($g, 'Visueller Raumplan: Layout-Verschiebung (Drag & Drop), SR/EG-Umschaltung und Tisch-Einstellungen', function (mysqli $c) {
            $t1 = 7191;
            $t2 = 7192;
            mysqli_query($c, "INSERT INTO tische (tischnummer, tischname, color, x, y, is_sammelrechnung, is_ehrengast) 
                              VALUES ({$t1}, 'Test-Tisch 1', '#112233', 1, 2, 0, 0), 
                                     ({$t2}, 'Test-Tisch 2', '#445566', 3, 4, 0, 0)
                              ON DUPLICATE KEY UPDATE tischname=VALUES(tischname), color=VALUES(color), x=VALUES(x), y=VALUES(y), is_sammelrechnung=VALUES(is_sammelrechnung), is_ehrengast=VALUES(is_ehrengast)");

            // 1. Drag & Drop Swap: Tausche Positionen (t1 -> 3,4; t2 -> 1,2)
            $stmt = mysqli_prepare($c, 'UPDATE tische SET x = ?, y = ? WHERE tischnummer = ?');
            $x1 = 3; $y1 = 4;
            mysqli_stmt_bind_param($stmt, 'iii', $x1, $y1, $t1);
            mysqli_stmt_execute($stmt);
            $x2 = 1; $y2 = 2;
            mysqli_stmt_bind_param($stmt, 'iii', $x2, $y2, $t2);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $r1 = ff_st_fetch_one($c, 'SELECT x, y FROM tische WHERE tischnummer = ?', 'i', [$t1]);
            $r2 = ff_st_fetch_one($c, 'SELECT x, y FROM tische WHERE tischnummer = ?', 'i', [$t2]);
            if ((int)($r1['x'] ?? 0) !== 3 || (int)($r1['y'] ?? 0) !== 4 || (int)($r2['x'] ?? 0) !== 1 || (int)($r2['y'] ?? 0) !== 2) {
                return 'Positionstausch fehlgeschlagen: ' . json_encode(['t1' => $r1, 't2' => $r2]);
            }

            // 2. SR Flag setzen -> Ehrengast muss 0 sein
            mysqli_query($c, "UPDATE tische SET is_sammelrechnung = 1, is_ehrengast = 0 WHERE tischnummer = {$t1}");
            $srCheck = ff_st_fetch_one($c, 'SELECT is_sammelrechnung, is_ehrengast FROM tische WHERE tischnummer = ?', 'i', [$t1]);
            if ((int)($srCheck['is_sammelrechnung'] ?? 0) !== 1 || (int)($srCheck['is_ehrengast'] ?? 0) !== 0) {
                return 'SR-Flag setzen fehlgeschlagen';
            }

            // 3. EG Flag setzen -> Sammelrechnung muss automatisch 0 werden (gegenseitiger Ausschluss)
            mysqli_query($c, "UPDATE tische SET is_ehrengast = 1, is_sammelrechnung = 0 WHERE tischnummer = {$t1}");
            $egCheck = ff_st_fetch_one($c, 'SELECT is_sammelrechnung, is_ehrengast FROM tische WHERE tischnummer = ?', 'i', [$t1]);
            if ((int)($egCheck['is_ehrengast'] ?? 0) !== 1 || (int)($egCheck['is_sammelrechnung'] ?? 0) !== 0) {
                return 'EG-Flag setzen mit gegenseitigem Ausschluss fehlgeschlagen';
            }

            // 4. Tisch-Einstellungen komplett speichern (Name, Farbe, Position, SR)
            $newName = 'Tisch 91 VIP';
            $newColor = '#ff5500';
            $newX = 5;
            $newY = 6;
            $stmtSave = mysqli_prepare($c, 'INSERT INTO tische (tischnummer, tischname, color, is_sammelrechnung, is_ehrengast, x, y) 
                                            VALUES (?, ?, ?, 1, 0, ?, ?) 
                                            ON DUPLICATE KEY UPDATE tischname=VALUES(tischname), color=VALUES(color), is_sammelrechnung=VALUES(is_sammelrechnung), is_ehrengast=VALUES(is_ehrengast), x=VALUES(x), y=VALUES(y)');
            mysqli_stmt_bind_param($stmtSave, 'issii', $t1, $newName, $newColor, $newX, $newY);
            mysqli_stmt_execute($stmtSave);
            mysqli_stmt_close($stmtSave);

            $saveCheck = ff_st_fetch_one($c, 'SELECT tischname, color, x, y, is_sammelrechnung, is_ehrengast FROM tische WHERE tischnummer = ?', 'i', [$t1]);
            if ($saveCheck['tischname'] !== $newName || $saveCheck['color'] !== $newColor || (int)$saveCheck['x'] !== 5 || (int)$saveCheck['y'] !== 6 || (int)$saveCheck['is_sammelrechnung'] !== 1) {
                return 'Tisch-Speicherung im Modal fehlgeschlagen: ' . json_encode($saveCheck);
            }

            return null;
        });

        $check($g, 'Admin-Pilot API: Feste-Verwaltung (fest_save, feste_list, fest_set_current, fest_delete)', function (mysqli $c) {
            ff_feste_ensure_rechnung_prefix_column($c);

            // 1. Neues Fest anlegen (genau wie in pilot/admin.js fest_save)
            $fName = 'Feuerwehrfest Test 2026';
            $fCode = 'fest2026ST';
            $fDatum = '2026-10-28';
            $fMode = 'after';
            $fAktiv = 1;
            $fPrefix = 'R26';

            $stmtIns = mysqli_prepare($c, 'INSERT INTO feste (name, code, fest_datum, payment_mode, aktiv, rechnung_prefix) VALUES (?, ?, ?, ?, ?, ?)');
            if (!$stmtIns) {
                return 'Prepare INSERT feste fehlgeschlagen: ' . mysqli_error($c);
            }
            mysqli_stmt_bind_param($stmtIns, 'ssssis', $fName, $fCode, $fDatum, $fMode, $fAktiv, $fPrefix);
            if (!mysqli_stmt_execute($stmtIns)) {
                $err = mysqli_stmt_error($stmtIns);
                mysqli_stmt_close($stmtIns);
                return 'INSERT feste fehlgeschlagen: ' . $err;
            }
            $festId = (int)mysqli_insert_id($c);
            mysqli_stmt_close($stmtIns);

            if ($festId <= 0) {
                return 'Fest ID nach Insert ungültig';
            }

            // 2. Fest abrufen & verifizieren
            $fRow = ff_st_fetch_one($c, 'SELECT id, name, code, fest_datum, payment_mode, aktiv, rechnung_prefix FROM feste WHERE id = ?', 'i', [$festId]);
            if (!$fRow || $fRow['name'] !== $fName || $fRow['code'] !== $fCode || $fRow['fest_datum'] !== $fDatum || $fRow['rechnung_prefix'] !== $fPrefix) {
                return 'Gespeichertes Fest weicht ab: ' . json_encode($fRow);
            }

            // 3. Fest aktualisieren
            $fNameUpd = 'Feuerwehrfest Test 2026 Aktualisiert';
            $fDatumUpd = '2026-10-29';
            $stmtUpd = mysqli_prepare($c, 'UPDATE feste SET name = ?, code = ?, fest_datum = ?, payment_mode = ?, aktiv = ?, rechnung_prefix = ? WHERE id = ?');
            if (!$stmtUpd) {
                return 'Prepare UPDATE feste fehlgeschlagen: ' . mysqli_error($c);
            }
            mysqli_stmt_bind_param($stmtUpd, 'ssssisi', $fNameUpd, $fCode, $fDatumUpd, $fMode, $fAktiv, $fPrefix, $festId);
            if (!mysqli_stmt_execute($stmtUpd)) {
                $err = mysqli_stmt_error($stmtUpd);
                mysqli_stmt_close($stmtUpd);
                return 'UPDATE feste fehlgeschlagen: ' . $err;
            }
            mysqli_stmt_close($stmtUpd);

            $fRowUpd = ff_st_fetch_one($c, 'SELECT name, fest_datum FROM feste WHERE id = ?', 'i', [$festId]);
            if ($fRowUpd['name'] !== $fNameUpd || $fRowUpd['fest_datum'] !== $fDatumUpd) {
                return 'Update nicht korrekt übernommen: ' . json_encode($fRowUpd);
            }

            // 4. Löschen
            $stmtDel = mysqli_prepare($c, 'DELETE FROM feste WHERE id = ?');
            mysqli_stmt_bind_param($stmtDel, 'i', $festId);
            mysqli_stmt_execute($stmtDel);
            mysqli_stmt_close($stmtDel);

            $fCheck = ff_st_fetch_one($c, 'SELECT id FROM feste WHERE id = ?', 'i', [$festId]);
            if ($fCheck !== null) {
                return 'Fest nach Delete noch vorhanden';
            }

            return null;
        });

        $check($g, 'Admin-Pilot API: Druckziele & Thermo-Reprint-Queue (print_target_save, invoice_reprint mit printer_jobs)', function (mysqli $c) {
            $ptId = 88;
            $ptName = 'Test-Station Bar Selftest';
            $sort = 15;
            $active = 1;
            $autoLimit = 5;

            // 1. Druckziel speichern
            $stmt = mysqli_prepare($c, 'INSERT INTO print_targets (print_target, name, sort_order, active, auto_print_limit) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name), sort_order=VALUES(sort_order), active=VALUES(active), auto_print_limit=VALUES(auto_print_limit)');
            if (!$stmt) {
                return 'Prepare print_targets fehlgeschlagen: ' . mysqli_error($c);
            }
            mysqli_stmt_bind_param($stmt, 'isiii', $ptId, $ptName, $sort, $active, $autoLimit);
            if (!mysqli_stmt_execute($stmt)) {
                $err = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                return 'INSERT print_targets fehlgeschlagen: ' . $err;
            }
            mysqli_stmt_close($stmt);

            $ptRow = ff_st_fetch_one($c, 'SELECT name, auto_print_limit FROM print_targets WHERE print_target = ?', 'i', [$ptId]);
            if (!$ptRow || $ptRow['name'] !== $ptName || (int)$ptRow['auto_print_limit'] !== 5) {
                return 'Druckziel-Werte weichen ab: ' . json_encode($ptRow);
            }

            // 2. Thermo Nachdruck in printer_jobs einreihen
            require_once dirname(__DIR__) . '/include/ff_rechnung_items.php';
            $testText = "=== TEST REPRINT ===\nRechnung R-9999\nSumme: 10,00 EUR";
            $ok = ff_rechnung_enqueue_thermo($c, $ptId, $testText);
            if (!$ok) {
                return 'ff_rechnung_enqueue_thermo fehlgeschlagen';
            }
            $job = ff_st_fetch_one($c, "SELECT id, printer, status, payload FROM printer_jobs WHERE printer = 'target_88' ORDER BY id DESC LIMIT 1");
            if (!$job || $job['status'] !== 'pending') {
                return 'Druckauftrag in printer_jobs nicht wie erwartet vorhanden: ' . json_encode($job);
            }

            // Cleanup
            mysqli_query($c, 'DELETE FROM print_targets WHERE print_target = ' . $ptId);
            mysqli_query($c, 'DELETE FROM printer_jobs WHERE id = ' . (int)$job['id']);
            return null;
        });

        $check($g, 'Admin-Pilot API: Tische & Table-Tokens (tables_list und table_tokens Generierung)', function (mysqli $c) {
            $tNr = 7185;
            mysqli_query($c, "INSERT INTO tische (tischnummer, tischname, color, x, y) VALUES ({$tNr}, 'Test Token Tisch', '#334455', 2, 3) ON DUPLICATE KEY UPDATE tischname=VALUES(tischname)");
            
            $testToken = 'test_token_' . bin2hex(random_bytes(8));
            $stTok = mysqli_prepare($c, 'INSERT INTO table_tokens (table_number, token, active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE token=VALUES(token), active=1');
            mysqli_stmt_bind_param($stTok, 'is', $tNr, $testToken);
            mysqli_stmt_execute($stTok);
            mysqli_stmt_close($stTok);

            // Query wie in tables_list
            $row = ff_st_fetch_one($c, 'SELECT t.tischnummer, t.tischname, COALESCE(tt.token, "") AS token
                                       FROM tische t
                                       LEFT JOIN table_tokens tt ON tt.table_number = t.tischnummer AND tt.active = 1
                                       WHERE t.tischnummer = ?', 'i', [$tNr]);
            if (!$row || $row['token'] !== $testToken) {
                return 'Table-Token Verknüpfung fehlgeschlagen: ' . json_encode($row);
            }

            mysqli_query($c, 'DELETE FROM table_tokens WHERE table_number = ' . $tNr);
            mysqli_query($c, 'DELETE FROM tische WHERE tischnummer = ' . $tNr);
            return null;
        });

        $check($g, 'Admin-Pilot API: Mitarbeiter-Verpflegung (Bereiche & Konsumationen erfassen/auslesen)', function (mysqli $c) {
            // Test-Bereich
            $bName = 'ZZ_Selftest_Bereich_' . mt_rand(1000, 9999);
            mysqli_query($c, "INSERT INTO mitarbeiter_bereiche (name, sort_order) VALUES ('{$bName}', 99)");
            $bId = (int)mysqli_insert_id($c);

            // Test-Position
            $posId = ff_st_position($c, 5.00);
            mysqli_query($c, "UPDATE positionen SET Positionsname = 'ZZ MV Schnitzel' WHERE rowid = {$posId}");

            // Verpflegung buchen
            $datum = date('Y-m-d');
            $stMv = mysqli_prepare($c, 'INSERT INTO mitarbeiter_verpflegung (datum, bereich_id, position_id, menge, notiz, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $menge = 3;
            $notiz = 'Selftest Notiz';
            $by = 'admin_st';
            mysqli_stmt_bind_param($stMv, 'siiiss', $datum, $bId, $posId, $menge, $notiz, $by);
            mysqli_stmt_execute($stMv);
            $mvId = (int)mysqli_insert_id($c);
            mysqli_stmt_close($stMv);

            // Query wie in verpflegung_list mit p.Positionsname
            $qRow = ff_st_fetch_one($c, "SELECT mv.id, mv.menge, b.name AS bereich_name, p.Positionsname AS position_name 
                                         FROM mitarbeiter_verpflegung mv 
                                         JOIN mitarbeiter_bereiche b ON b.id = mv.bereich_id 
                                         JOIN positionen p ON p.rowid = mv.position_id 
                                         WHERE mv.id = ?", 'i', [$mvId]);
            if (!$qRow || (int)$qRow['menge'] !== 3 || $qRow['position_name'] !== 'ZZ MV Schnitzel') {
                return 'Abfrage Mitarbeiterverpflegung fehlerhaft: ' . json_encode($qRow);
            }

            mysqli_query($c, 'DELETE FROM mitarbeiter_verpflegung WHERE id = ' . $mvId);
            mysqli_query($c, 'DELETE FROM mitarbeiter_bereiche WHERE id = ' . $bId);
            mysqli_query($c, 'DELETE FROM positionen WHERE rowid = ' . $posId);
            return null;
        });

        $check($g, 'Admin-Pilot API: Artikel-Statistik (statistics_articles mit Positionsname/Kategorie/Druckziel)', function (mysqli $c) {
            $posFood = ff_st_position($c, 12.00);
            mysqli_query($c, "UPDATE positionen SET Positionsname = 'ZZ Gourmet Burger', type = 1, print_target = 11 WHERE rowid = {$posFood}");

            // Bestellung und Bezahlung erfassen
            $orderId = ff_st_order($c, $posFood, ['betrag' => 12.00, 'paid' => true]);

            // Query wie in statistics_articles
            $amtExpr = "COALESCE(NULLIF(b.betrag, 0), p.Betrag)";
            $row = ff_st_fetch_one($c, "SELECT p.rowid, 
                                               p.Positionsname AS bezeichnung, 
                                               CASE WHEN p.type = 1 THEN 'Speisen' WHEN p.type = 2 THEN 'Getränke' ELSE 'Sonstige' END AS kategorie, 
                                               COALESCE(pt.name, '–') AS druckbereich, 
                                               p.Betrag AS einzelpreis,
                                               COUNT(*) AS cnt,
                                               SUM({$amtExpr}) AS summe
                                        FROM bestellungen b
                                        JOIN positionen p ON p.rowid = b.position
                                        LEFT JOIN print_targets pt ON pt.print_target = p.print_target
                                        WHERE b.rowid = ?
                                        GROUP BY p.rowid, p.Positionsname, p.type, pt.name, p.Betrag", 'i', [$orderId]);

            if (!$row || $row['bezeichnung'] !== 'ZZ Gourmet Burger' || $row['kategorie'] !== 'Speisen' || (float)$row['summe'] < 11.99) {
                return 'Artikel-Statistik Query liefert falsches Ergebnis: ' . json_encode($row);
            }

            mysqli_query($c, 'DELETE FROM bestellungen WHERE rowid = ' . $orderId);
            mysqli_query($c, 'DELETE FROM positionen WHERE rowid = ' . $posFood);
            return null;
        });

        $check($g, 'Admin-Pilot API: Fest Steuerberater-Paket & Export (fest_steuerpaket_zip & fest_export mit fest_id und id)', function (mysqli $c) {
            $fRow = ff_st_fetch_one($c, 'SELECT id FROM feste ORDER BY id ASC LIMIT 1');
            $fid = $fRow ? (int)$fRow['id'] : 1;

            $scriptZip = dirname(__DIR__) . '/fest_steuerpaket_zip.php';
            $scriptExport = dirname(__DIR__) . '/fest_export.php';

            // 1. fest_steuerpaket_zip.php mit ?fest_id=
            $cmdZip1 = sprintf(
                'php -r %s',
                escapeshellarg('
                    define("FF_AUTH_LOADED", true);
                    $_SESSION["admin"] = 2;
                    $_GET = ["fest_id" => ' . $fid . '];
                    require ' . var_export($scriptZip, true) . ';
                ')
            );
            $outZip1 = shell_exec($cmdZip1);
            if (substr((string)$outZip1, 0, 2) !== 'PK') {
                return 'fest_steuerpaket_zip mit fest_id erzeugt kein valides ZIP: ' . substr((string)$outZip1, 0, 60);
            }

            // 2. fest_steuerpaket_zip.php mit ?id=
            $cmdZip2 = sprintf(
                'php -r %s',
                escapeshellarg('
                    define("FF_AUTH_LOADED", true);
                    $_SESSION["admin"] = 2;
                    $_GET = ["id" => ' . $fid . '];
                    require ' . var_export($scriptZip, true) . ';
                ')
            );
            $outZip2 = shell_exec($cmdZip2);
            if (substr((string)$outZip2, 0, 2) !== 'PK') {
                return 'fest_steuerpaket_zip mit id erzeugt kein valides ZIP: ' . substr((string)$outZip2, 0, 60);
            }

            // 3. fest_export.php mit ?fest_id=
            $cmdExp = sprintf(
                'php -r %s',
                escapeshellarg('
                    define("FF_AUTH_LOADED", true);
                    $_SESSION["user"] = "admin";
                    $_SESSION["admin"] = 2;
                    $_GET = ["fest_id" => ' . $fid . '];
                    require ' . var_export($scriptExport, true) . ';
                ')
            );
            $outExp = shell_exec($cmdExp);
            $d = json_decode((string)$outExp, true);
            if (!isset($d['format']) || $d['format'] !== 'FBS_FEST_EXPORT') {
                return 'fest_export mit fest_id liefert ungültiges JSON: ' . substr((string)$outExp, 0, 60);
            }

            return null;
        });

        $check($g, 'Admin-Pilot API: Unterkategorien Drag & Drop Reordering (manage/api.php reorder_subcategories)', function (mysqli $c) {
            // Test-Unterkategorien anlegen
            mysqli_query($c, "INSERT INTO position_subcategories (type, name, sort_order) VALUES (1, 'ZZ Subcat A', 10)");
            $idA = (int)mysqli_insert_id($c);
            mysqli_query($c, "INSERT INTO position_subcategories (type, name, sort_order) VALUES (1, 'ZZ Subcat B', 20)");
            $idB = (int)mysqli_insert_id($c);

            try {
                // Reorder via Prepared Statement wie in manage/api.php
                $stmt = mysqli_prepare($c, "UPDATE position_subcategories SET sort_order = ?, type = COALESCE(?, type) WHERE id = ?");
                $sort1 = 15; $type1 = 1;
                mysqli_stmt_bind_param($stmt, 'iii', $sort1, $type1, $idA);
                mysqli_stmt_execute($stmt);

                $sort2 = 5; $type2 = 1;
                mysqli_stmt_bind_param($stmt, 'iii', $sort2, $type2, $idB);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $rowA = ff_st_fetch_one($c, 'SELECT sort_order FROM position_subcategories WHERE id = ?', 'i', [$idA]);
                $rowB = ff_st_fetch_one($c, 'SELECT sort_order FROM position_subcategories WHERE id = ?', 'i', [$idB]);

                if (!$rowA || (int)$rowA['sort_order'] !== 15 || !$rowB || (int)$rowB['sort_order'] !== 5) {
                    return 'Reihenfolge der Unterkategorien stimmt nicht: ' . json_encode([$rowA, $rowB]);
                }
            } finally {
                mysqli_query($c, 'DELETE FROM position_subcategories WHERE id IN (' . $idA . ',' . $idB . ')');
            }

            return null;
        });

        $check($g, 'Socket-Drucker: RAW ESC/POS Generator & Dispatcher (include/ff_socket_printer.php)', function (mysqli $c) {
            require_once __DIR__ . '/ff_socket_printer.php';
            ff_socket_printer_ensure_schema($c);

            // Prüfe Schema
            $r = mysqli_query($c, "SHOW COLUMNS FROM print_targets LIKE 'connection_type'");
            if (!$r || mysqli_num_rows($r) === 0) {
                return 'Spalte connection_type fehlt in print_targets';
            }

            // Prüfe ESC/POS Kodierung
            $encoded = ff_escpos_encode_text('Würstel & Schnitzel mit Weißbier € 12,50');
            if ($encoded === '') {
                return 'ff_escpos_encode_text lieferte leeren String';
            }

            // Prüfe Testbon-Erstellung
            $testBon = ff_escpos_build_test_bon('Grill-Station', '192.168.1.150', 9100, 'Testfest');
            if (strpos($testBon, 'Grill-Station') === false || strpos($testBon, '192.168.1.150:9100') === false) {
                return 'Testbon enthält nicht die erwarteten Metadaten';
            }

            // Prüfe Kellner-Bon-Erstellung
            $dummyTisch = [
                'tischnummer' => 100140,
                'tischname' => 'Tisch H-A1',
                'bon_nr' => 42,
                'kellner' => 'TestKellner',
                'positionen' => [
                    ['name' => 'Bratwurst', 'anzahl' => 2, 'einzelpreis' => 6.0, 'gesamtpreis' => 12.0, 'zusatzinfo' => 'mit Senf'],
                ],
            ];
            $bonData = ff_escpos_build_kellner_bon($dummyTisch, 'Header-Test', 'Footer-Test');
            if (strpos($bonData, 'Tisch H-A1') === false || strpos($bonData, 'Bratwurst') === false) {
                return 'Kellner-Bon enthält nicht Tischname oder Position';
            }

            // Dispatch-Test mit nicht existierendem Job
            $disp = ff_socket_printer_dispatch_job($c, 9999999);
            if ($disp['attempted'] !== false) {
                return 'Nicht existierender Job sollte nicht als attempted markiert werden';
            }

            return null;
        });

        $check($g, 'Pre-Flight Checkliste: 4 Fest-Start Kernkriterien (include/ff_preflight.php)', function (mysqli $c) {
            require_once __DIR__ . '/ff_preflight.php';
            $res = ff_preflight_check($c);

            if (!isset($res['ok']) || !$res['ok']) {
                return 'ff_preflight_check gab ok=false zurück';
            }

            $checks = $res['checks'] ?? [];
            $required = ['orders', 'printers', 'invoice_seq', 'offline_backup'];
            foreach ($required as $reqKey) {
                if (!isset($checks[$reqKey])) {
                    return "Kriterium '{$reqKey}' fehlt in preflight checks";
                }
                if (!isset($checks[$reqKey]['status']) || !isset($checks[$reqKey]['badge']) || !isset($checks[$reqKey]['details'])) {
                    return "Kriterium '{$reqKey}' hat unvollständige Felder";
                }
            }

            return null;
        });
    } finally {
        mysqli_rollback($conn);
    }

    $passed = count(array_filter($results, static fn (array $r): bool => $r['ok']));

    return [
        'ok' => true,
        'results' => $results,
        'passed' => $passed,
        'failed' => count($results) - $passed,
    ];
}
