<?php
/**
 * ============================================================================
 * Datenbank-Update- & Migrationsskript für Feuerwehr-Bestellsystem
 * ============================================================================
 * 
 * Unterstützt alte Altdatenbanken (MyISAM, latin1, altes Tabellenschema) und
 * migriert auf das moderne, transaktionssichere Schema (InnoDB, utf8mb4_unicode_ci,
 * 24 Tabellen inkl. QR-Table-Tokens, Allergenen, Rezepturen, etc.).
 * 
 * NEUES FEATURE:
 * Quelle und Ziel können auf UNTERSCHIEDLICHEN SERVERn mit unterschiedlichen
 * Benutzernamen und Passwörtern liegen (z. B. mysqlsvr83 -> mysqlsvr88 bei World4You).
 * Der Datentransfer erfolgt bei separaten Servern automatisch über einen
 * speichereffizienten Streaming-Batch-Import (MYSQLI_USE_RESULT + Multi-Row INSERTs).
 * 
 * Unterstützte Modi:
 * 1. Clone & Upgrade (Quelle -> Ziel):
 *    Erstellt die Ziel-Datenbank auf dem Ziel-Server mit modernem Schema und
 *    überträgt alle Daten (Benutzer, Tische, Positionen, Beilagen, Bestellungen...)
 *    mit automatischer Spalten- und Zeichensatz-Transformation.
 * 
 * 2. In-Place Update:
 *    Aktualisiert eine bestehende Datenbank direkt vor Ort auf dem Quell-Server.
 * 
 * Kann sowohl über den BROWSER (visuelle Web-UI mit Live-Log) als auch über die
 * BEFEHLSZEILE (CLI) aufgerufen werden.
 * ============================================================================
 */

declare(strict_types=1);

// Maximale Ausführungszeit & Speicher für große Migrationen
@ini_set('max_execution_time', '600');
@ini_set('memory_limit', '512M');

// Standard-Konfiguration ermitteln
$defaultSourceHost = 'mysqlsvr83.world4you.com';
$defaultSourcePort = 3306;
$defaultSourceUser = '';
$defaultSourcePass = '';
$defaultSourceDb   = '2729544db7';

$defaultTargetHost = '127.0.0.1';
$defaultTargetPort = 3306;
$defaultTargetUser = 'root';
$defaultTargetPass = '';
$defaultTargetDb   = '';

$dbConfigFile = __DIR__ . '/include/db.php';
if (file_exists($dbConfigFile)) {
    $dbConfigContent = file_get_contents($dbConfigFile);
    if (preg_match('/\$hostname\s*=\s*[\'"]([^\'"]+)[\'"]/', $dbConfigContent, $m)) {
        $defaultTargetHost = $m[1];
        if (strpos($m[1], 'world4you') !== false && empty($defaultSourceHost)) {
            $defaultSourceHost = $m[1];
        }
    }
    if (preg_match('/\$username\s*=\s*[\'"]([^\'"]+)[\'"]/', $dbConfigContent, $m)) {
        $defaultTargetUser = $m[1];
        if (empty($defaultSourceUser)) {
            $defaultSourceUser = $m[1];
        }
    }
    if (preg_match('/\$password\s*=\s*[\'"]([^\'"]*)[\'"]/', $dbConfigContent, $m)) {
        $defaultTargetPass = $m[1];
        if (empty($defaultSourcePass)) {
            $defaultSourcePass = $m[1];
        }
    }
    if (preg_match('/\$dbname\s*=\s*[\'"]([^\'"]+)[\'"]/', $dbConfigContent, $m)) {
        $defaultTargetDb = $m[1];
    }
}

// ----------------------------------------------------------------------------
// CLI-Parameter parsen (falls über CLI aufgerufen)
// ----------------------------------------------------------------------------
$isCli = (php_sapi_name() === 'cli');
$cliArgs = [];
if ($isCli) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            $parts = explode('=', substr($arg, 2), 2);
            $key = $parts[0];
            $val = $parts[1] ?? true;
            $cliArgs[$key] = $val;
        }
    }
}

// ----------------------------------------------------------------------------
// Hilfsfunktion für sichere Datenbankverbindungen
// ----------------------------------------------------------------------------
function ff_db_connect(string $host, int $port, string $user, string $pass, ?string $db = null, int $timeout = 8): array {
    $conn = @mysqli_init();
    if (!$conn) {
        return ['conn' => null, 'error' => 'mysqli_init fehlgeschlagen.'];
    }
    @mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, $timeout);
    $connected = @mysqli_real_connect($conn, $host, $user, $pass, null, $port);
    if (!$connected) {
        return ['conn' => null, 'error' => mysqli_connect_error() ?: 'Verbindung fehlgeschlagen.'];
    }
    @mysqli_set_charset($conn, 'utf8mb4');
    @mysqli_query($conn, "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

    if ($db !== null && $db !== '') {
        if (!@mysqli_select_db($conn, $db)) {
            $err = mysqli_error($conn) ?: "Datenbank '{$db}' existiert nicht oder kein Zugriff.";
            return ['conn' => $conn, 'error' => $err];
        }
    }
    return ['conn' => $conn, 'error' => null];
}

// ----------------------------------------------------------------------------
// Schema-Definitionen für das moderne Bestellsystem
// ----------------------------------------------------------------------------
function ff_migration_get_target_tables(): array {
    return [
        'type' => "CREATE TABLE IF NOT EXISTS `type` (
            `rowid` int(2) NOT NULL,
            `name` varchar(30) NOT NULL,
            PRIMARY KEY (`rowid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'users' => "CREATE TABLE IF NOT EXISTS `users` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `username` varchar(255) DEFAULT NULL,
            `display_name` varchar(120) DEFAULT NULL COMMENT 'Anzeigename für Rechnungen',
            `password` varchar(255) DEFAULT NULL,
            `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `admin` tinyint(1) NOT NULL DEFAULT 0,
            `can_finance` tinyint(1) NOT NULL DEFAULT 0,
            `start_page` varchar(32) NOT NULL DEFAULT 'menu',
            `start_print_target` int(11) DEFAULT NULL,
            `dv_abholbon_print_target` int(11) DEFAULT NULL,
            `auth_rev` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'feste' => "CREATE TABLE IF NOT EXISTS `feste` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `code` varchar(32) NOT NULL,
            `rechnung_prefix` varchar(16) DEFAULT NULL,
            `fest_datum` date DEFAULT NULL,
            `aktiv` tinyint(1) NOT NULL DEFAULT 1,
            `ist_aktuell` tinyint(1) NOT NULL DEFAULT 0,
            `payment_mode` enum('instant','after') NOT NULL DEFAULT 'after',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_code` (`code`),
            KEY `idx_aktiv` (`aktiv`),
            KEY `idx_ist_aktuell` (`ist_aktuell`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tische' => "CREATE TABLE IF NOT EXISTS `tische` (
            `tischnummer` int(11) NOT NULL AUTO_INCREMENT,
            `fest_id` int(11) DEFAULT NULL,
            `tischname` varchar(50) NOT NULL,
            `zeitstempel` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `userID` int(11) NOT NULL DEFAULT 0,
            `x` int(11) NOT NULL DEFAULT 0,
            `y` int(11) NOT NULL DEFAULT 0,
            `color` varchar(10) NOT NULL DEFAULT '#ffffff',
            `is_sammelrechnung` tinyint(1) NOT NULL DEFAULT 0,
            `is_ehrengast` tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`tischnummer`),
            KEY `idx_fest_id` (`fest_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'table_tokens' => "CREATE TABLE IF NOT EXISTS `table_tokens` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `table_number` int(11) NOT NULL,
            `token` varchar(64) NOT NULL,
            `active` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_table` (`table_number`),
            KEY `idx_token` (`token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'print_targets' => "CREATE TABLE IF NOT EXISTS `print_targets` (
            `print_target` int(11) NOT NULL,
            `name` varchar(64) NOT NULL,
            `active` tinyint(1) NOT NULL DEFAULT 1,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `finance_bereich_id` int(11) DEFAULT NULL,
            PRIMARY KEY (`print_target`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'position_subcategories' => "CREATE TABLE IF NOT EXISTS `position_subcategories` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `type` tinyint(4) NOT NULL COMMENT '1=Speise 2=Getränk',
            `name` varchar(128) NOT NULL,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `tile_bg` varchar(32) DEFAULT NULL,
            `kassa_only` tinyint(1) NOT NULL DEFAULT 0,
            `fest_id` int(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_type_sort` (`type`,`sort_order`),
            KEY `idx_fest_id` (`fest_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'positionen' => "CREATE TABLE IF NOT EXISTS `positionen` (
            `rowid` int(11) NOT NULL AUTO_INCREMENT,
            `Positionsname` text NOT NULL,
            `Betrag` decimal(10,2) NOT NULL,
            `type` int(2) NOT NULL COMMENT '1=Speise, 2=Getränk',
            `Kurzbezeichnung` varchar(30) DEFAULT NULL,
            `maxBestellbar` int(11) NOT NULL DEFAULT -1,
            `reihenfolge` int(11) NOT NULL DEFAULT 0,
            `color` varchar(8) NOT NULL DEFAULT '#ffffff',
            `icon` varchar(30) NOT NULL DEFAULT '',
            `print_target` int(11) NOT NULL DEFAULT 11,
            `selbstkosten` decimal(10,2) NOT NULL DEFAULT 0.00,
            `subcategory_id` int(11) DEFAULT NULL,
            `tile_bg` varchar(32) DEFAULT NULL,
            `kassa_only` tinyint(1) NOT NULL DEFAULT 0,
            `fest_id` int(11) DEFAULT NULL,
            PRIMARY KEY (`rowid`),
            KEY `idx_type` (`type`),
            KEY `idx_print_target` (`print_target`),
            KEY `idx_kassa_only` (`kassa_only`),
            KEY `idx_fest_id` (`fest_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'beilagen' => "CREATE TABLE IF NOT EXISTS `beilagen` (
            `rowid` int(11) NOT NULL AUTO_INCREMENT,
            `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `name` varchar(255) NOT NULL,
            `position` int(11) NOT NULL,
            `betrag` double NOT NULL DEFAULT 0,
            `fest_id` int(11) DEFAULT NULL,
            PRIMARY KEY (`rowid`),
            KEY `idx_position` (`position`),
            KEY `idx_fest_id` (`fest_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'bestellungen' => "CREATE TABLE IF NOT EXISTS `bestellungen` (
            `rowid` int(11) NOT NULL AUTO_INCREMENT,
            `fest_id` int(11) DEFAULT NULL,
            `sammelrechnung_id` int(11) DEFAULT NULL,
            `rechnung_id` int(11) DEFAULT NULL,
            `zeitstempel` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `position` int(11) NOT NULL,
            `ausgeliefert` int(11) NOT NULL DEFAULT 0,
            `tischnummer` int(11) NOT NULL,
            `kellner` text NOT NULL,
            `bestellung` int(11) NOT NULL DEFAULT 0,
            `kueche` int(11) NOT NULL DEFAULT 0,
            `delete` int(11) NOT NULL DEFAULT 0,
            `zeitKueche` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
            `print` tinyint(1) NOT NULL DEFAULT 0,
            `print_target` int(11) DEFAULT NULL,
            `print_status` tinyint(1) NOT NULL DEFAULT 0,
            `timestampBestellung` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
            `timestampAuslieferung` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
            `timestampBezahlung` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
            `kellnerZahlung` text,
            `Zusatzinfo` varchar(255) DEFAULT NULL,
            `betrag` double NOT NULL DEFAULT 0,
            `bestellt` tinyint(1) NOT NULL DEFAULT 0,
            `is_gratis` tinyint(1) NOT NULL DEFAULT 0,
            `bon_id` varchar(10) DEFAULT NULL,
            `order_nr` int(11) DEFAULT NULL,
            `settlement_id` int(11) DEFAULT NULL,
            `settled_at` datetime DEFAULT NULL,
            `settled_by` varchar(64) DEFAULT NULL,
            PRIMARY KEY (`rowid`),
            KEY `idx_tischnummer` (`tischnummer`),
            KEY `idx_position` (`position`),
            KEY `idx_fest_id` (`fest_id`),
            KEY `idx_sammelrechnung_id` (`sammelrechnung_id`),
            KEY `idx_rechnung_id` (`rechnung_id`),
            KEY `idx_print_target` (`print_target`),
            KEY `idx_bon_id` (`bon_id`),
            KEY `idx_order_nr` (`order_nr`),
            KEY `idx_kueche` (`kueche`),
            KEY `idx_delete` (`delete`),
            KEY `idx_settlement_id` (`settlement_id`),
            KEY `idx_print_pipeline` (`delete`,`kueche`,`bestellt`,`print`,`print_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'print' => "CREATE TABLE IF NOT EXISTS `print` (
            `rowid` int(11) NOT NULL AUTO_INCREMENT,
            `bestellungID` int(11) NOT NULL,
            `timestamp` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`rowid`),
            KEY `idx_bestellungID` (`bestellungID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'sammelrechnungen' => "CREATE TABLE IF NOT EXISTS `sammelrechnungen` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` varchar(255) DEFAULT NULL,
            `umsatz_zustaendig` varchar(255) DEFAULT NULL,
            `tables_text` text,
            `total_amount` decimal(10,2) DEFAULT 0.00,
            `bezahlt` tinyint(1) NOT NULL DEFAULT 0,
            `bezahlt_at` timestamp NULL DEFAULT NULL,
            `fest_id` int(11) DEFAULT NULL,
            `tischnummer` int(11) DEFAULT NULL,
            `betrag` decimal(10,2) DEFAULT 0.00,
            `name` varchar(100) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_fest_id` (`fest_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'menu_locks' => "CREATE TABLE IF NOT EXISTS `menu_locks` (
            `item_type` enum('position','beilage') NOT NULL,
            `item_id` int(11) NOT NULL,
            `locked_by_user` varchar(255) NOT NULL,
            `locked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `active` tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`item_type`,`item_id`),
            KEY `idx_active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'menu_lock_exceptions' => "CREATE TABLE IF NOT EXISTS `menu_lock_exceptions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `item_type` enum('position','beilage') NOT NULL,
            `item_id` int(11) NOT NULL,
            `table_number` int(11) NOT NULL,
            `allowed_by_user` varchar(255) NOT NULL,
            `allowed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `active` tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `idx_item_table` (`item_type`,`item_id`,`table_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'rechnungen' => "CREATE TABLE IF NOT EXISTS `rechnungen` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `rechnungsnummer` varchar(64) NOT NULL,
            `rechnung_seq` int(11) NOT NULL DEFAULT 0,
            `tischnummer` int(11) NOT NULL,
            `kellner` varchar(255) NOT NULL,
            `kellner_user_id` int(11) DEFAULT NULL,
            `betrag` decimal(10,2) NOT NULL,
            `zahlungsart` varchar(32) NOT NULL DEFAULT 'bar',
            `status` varchar(32) NOT NULL DEFAULT 'bezahlt',
            `zeitstempel` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `storno_grund` varchar(255) DEFAULT NULL,
            `storno_zeit` timestamp NULL DEFAULT NULL,
            `storno_user` varchar(255) DEFAULT NULL,
            `bemerkung` text,
            `fest_id` int(11) DEFAULT NULL,
            `sumup_transaction_id` varchar(128) DEFAULT NULL,
            `sumup_transaction_code` varchar(64) DEFAULT NULL,
            `is_sammelrechnung` tinyint(1) NOT NULL DEFAULT 0,
            `sammelrechnung_id` int(11) DEFAULT NULL,
            `settlement_id` int(11) DEFAULT NULL,
            `settled_at` datetime DEFAULT NULL,
            `settled_by` varchar(64) DEFAULT NULL,
            `signature_qr` text,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_rechnungsnummer` (`rechnungsnummer`),
            KEY `idx_tischnummer` (`tischnummer`),
            KEY `idx_zeitstempel` (`zeitstempel`),
            KEY `idx_fest_id` (`fest_id`),
            KEY `idx_settlement_id` (`settlement_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'buchungen' => "CREATE TABLE IF NOT EXISTS `buchungen` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `rechnung_id` int(11) NOT NULL,
            `tischnummer` int(11) NOT NULL,
            `kellner` varchar(255) NOT NULL,
            `betrag` decimal(10,2) NOT NULL,
            `zeitstempel` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_rechnung_id` (`rechnung_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'kassen_bereiche' => "CREATE TABLE IF NOT EXISTS `kassen_bereiche` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(64) NOT NULL,
            `code` varchar(32) NOT NULL,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `aktiv` tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'kassen_sessions' => "CREATE TABLE IF NOT EXISTS `kassen_sessions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `bereich_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `gestartet_um` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `beendet_um` timestamp NULL DEFAULT NULL,
            `anfangsbestand` decimal(10,2) NOT NULL DEFAULT 0.00,
            `endbestand` decimal(10,2) DEFAULT NULL,
            `differenz` decimal(10,2) DEFAULT NULL,
            `status` enum('offen','abgeschlossen') NOT NULL DEFAULT 'offen',
            `abschluss_notiz` text,
            PRIMARY KEY (`id`),
            KEY `idx_bereich_id` (`bereich_id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'kassen_bewegungen' => "CREATE TABLE IF NOT EXISTS `kassen_bewegungen` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `session_id` int(11) NOT NULL,
            `bereich_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `typ` enum('einlage','entnahme','abschlag','ausgabe') NOT NULL,
            `betrag` decimal(10,2) NOT NULL,
            `grund` varchar(255) NOT NULL,
            `beleg_nr` varchar(64) DEFAULT NULL,
            `zeitstempel` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_session_id` (`session_id`),
            KEY `idx_bereich_id` (`bereich_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'kellner_settlements' => "CREATE TABLE IF NOT EXISTS `kellner_settlements` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `fest_id` int(11) DEFAULT NULL,
            `kellner` varchar(255) NOT NULL,
            `kellner_user_id` int(11) DEFAULT NULL,
            `zeitstempel` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `abgerechnet_von` varchar(255) NOT NULL,
            `summe_bar` decimal(10,2) NOT NULL DEFAULT 0.00,
            `summe_karte` decimal(10,2) NOT NULL DEFAULT 0.00,
            `anzahl_rechnungen` int(11) NOT NULL DEFAULT 0,
            `abgaben_abgezogen` decimal(10,2) NOT NULL DEFAULT 0.00,
            `ist_betrag` decimal(10,2) NOT NULL DEFAULT 0.00,
            `soll_betrag` decimal(10,2) NOT NULL DEFAULT 0.00,
            `differenz` decimal(10,2) NOT NULL DEFAULT 0.00,
            `notiz` text,
            PRIMARY KEY (`id`),
            KEY `idx_fest_id` (`fest_id`),
            KEY `idx_kellner` (`kellner`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'kellner_bewegungen' => "CREATE TABLE IF NOT EXISTS `kellner_bewegungen` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `fest_id` int(11) DEFAULT NULL,
            `kellner` varchar(255) NOT NULL,
            `kellner_user_id` int(11) DEFAULT NULL,
            `typ` enum('abschlag','wechselgeld') NOT NULL,
            `betrag` decimal(10,2) NOT NULL,
            `zeitstempel` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `erfasst_von` varchar(255) NOT NULL,
            `notiz` text,
            PRIMARY KEY (`id`),
            KEY `idx_fest_id` (`fest_id`),
            KEY `idx_kellner` (`kellner`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'mitarbeiter_bereiche' => "CREATE TABLE IF NOT EXISTS `mitarbeiter_bereiche` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(64) NOT NULL,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'mitarbeiter_verpflegung' => "CREATE TABLE IF NOT EXISTS `mitarbeiter_verpflegung` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `bereich_id` int(11) NOT NULL,
            `mitarbeiter_name` varchar(120) NOT NULL,
            `position_id` int(11) NOT NULL,
            `positionsname` varchar(255) NOT NULL,
            `anzahl` int(11) NOT NULL DEFAULT 1,
            `zeitstempel` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `erfasst_von` varchar(255) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_bereich_id` (`bereich_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'settings' => "CREATE TABLE IF NOT EXISTS `settings` (
            `k` varchar(64) NOT NULL,
            `v` text,
            PRIMARY KEY (`k`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'printer_jobs' => "CREATE TABLE IF NOT EXISTS `printer_jobs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `printer_ip` varchar(64) NOT NULL,
            `printer_name` varchar(100) NOT NULL,
            `content` mediumtext NOT NULL,
            `status` enum('pending','processing','done','error') NOT NULL DEFAULT 'pending',
            `attempts` int(11) NOT NULL DEFAULT 0,
            `error_msg` text,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `locked_at` timestamp NULL DEFAULT NULL,
            `locked_by` varchar(64) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_status_printer` (`status`,`printer_ip`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'bestandteile' => "CREATE TABLE IF NOT EXISTS `bestandteile` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `einheit` varchar(30) NOT NULL DEFAULT 'Stk',
            `sort_order` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_name` (`name`),
            KEY `idx_sort` (`sort_order`, `name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'position_rezepturen' => "CREATE TABLE IF NOT EXISTS `position_rezepturen` (
            `position_id` int(11) NOT NULL,
            `bestandteil_id` int(11) NOT NULL,
            `menge` decimal(10,3) NOT NULL DEFAULT 1.000,
            `optional_enabled` tinyint(1) NOT NULL DEFAULT 0,
            `default_selected` tinyint(1) NOT NULL DEFAULT 1,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`position_id`, `bestandteil_id`),
            KEY `idx_bestandteil` (`bestandteil_id`),
            KEY `idx_sort` (`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'allergene' => "CREATE TABLE IF NOT EXISTS `allergene` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_name` (`name`),
            KEY `idx_sort` (`sort_order`, `name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'bestandteil_allergene' => "CREATE TABLE IF NOT EXISTS `bestandteil_allergene` (
            `bestandteil_id` int(11) NOT NULL,
            `allergen_id` int(11) NOT NULL,
            PRIMARY KEY (`bestandteil_id`, `allergen_id`),
            KEY `idx_allergen` (`allergen_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'bestellung_rezeptur_snapshots' => "CREATE TABLE IF NOT EXISTS `bestellung_rezeptur_snapshots` (
            `bestellung_rowid` int(11) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`bestellung_rowid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'bestellung_rezeptur_komponenten' => "CREATE TABLE IF NOT EXISTS `bestellung_rezeptur_komponenten` (
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
    ];
}

// ----------------------------------------------------------------------------
// Spalten-Erweiterungen für In-Place Upgrades vorhandener Tabellen
// ----------------------------------------------------------------------------
function ff_migration_get_column_patches(): array {
    return [
        'positionen' => [
            'print_target' => "ADD COLUMN `print_target` int(11) NOT NULL DEFAULT 11",
            'selbstkosten' => "ADD COLUMN `selbstkosten` decimal(10,2) NOT NULL DEFAULT 0.00",
            'subcategory_id' => "ADD COLUMN `subcategory_id` int(11) DEFAULT NULL",
            'tile_bg' => "ADD COLUMN `tile_bg` varchar(32) DEFAULT NULL",
            'kassa_only' => "ADD COLUMN `kassa_only` tinyint(1) NOT NULL DEFAULT 0",
            'fest_id' => "ADD COLUMN `fest_id` int(11) DEFAULT NULL",
        ],
        'tische' => [
            'fest_id' => "ADD COLUMN `fest_id` int(11) DEFAULT NULL",
            'userID' => "ADD COLUMN `userID` int(11) NOT NULL DEFAULT 0",
            'is_sammelrechnung' => "ADD COLUMN `is_sammelrechnung` tinyint(1) NOT NULL DEFAULT 0",
            'is_ehrengast' => "ADD COLUMN `is_ehrengast` tinyint(1) NOT NULL DEFAULT 0",
        ],
        'users' => [
            'display_name' => "ADD COLUMN `display_name` varchar(120) DEFAULT NULL",
            'can_finance' => "ADD COLUMN `can_finance` tinyint(1) NOT NULL DEFAULT 0",
            'start_page' => "ADD COLUMN `start_page` varchar(32) NOT NULL DEFAULT 'menu'",
            'start_print_target' => "ADD COLUMN `start_print_target` int(11) DEFAULT NULL",
            'dv_abholbon_print_target' => "ADD COLUMN `dv_abholbon_print_target` int(11) DEFAULT NULL",
            'auth_rev' => "ADD COLUMN `auth_rev` int(11) NOT NULL DEFAULT 0",
        ],
        'beilagen' => [
            'fest_id' => "ADD COLUMN `fest_id` int(11) DEFAULT NULL",
        ],
        'bestellungen' => [
            'fest_id' => "ADD COLUMN `fest_id` int(11) DEFAULT NULL",
            'sammelrechnung_id' => "ADD COLUMN `sammelrechnung_id` int(11) DEFAULT NULL",
            'rechnung_id' => "ADD COLUMN `rechnung_id` int(11) DEFAULT NULL",
            'print_target' => "ADD COLUMN `print_target` int(11) DEFAULT NULL",
            'print_status' => "ADD COLUMN `print_status` tinyint(1) NOT NULL DEFAULT 0",
            'is_gratis' => "ADD COLUMN `is_gratis` tinyint(1) NOT NULL DEFAULT 0",
            'bon_id' => "ADD COLUMN `bon_id` varchar(10) DEFAULT NULL",
            'order_nr' => "ADD COLUMN `order_nr` int(11) DEFAULT NULL",
            'settlement_id' => "ADD COLUMN `settlement_id` int(11) DEFAULT NULL",
            'settled_at' => "ADD COLUMN `settled_at` datetime DEFAULT NULL",
            'settled_by' => "ADD COLUMN `settled_by` varchar(64) DEFAULT NULL",
        ],
        'sammelrechnungen' => [
            'created_at' => "ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'created_by' => "ADD COLUMN `created_by` varchar(255) DEFAULT NULL",
            'umsatz_zustaendig' => "ADD COLUMN `umsatz_zustaendig` varchar(255) DEFAULT NULL",
            'tables_text' => "ADD COLUMN `tables_text` text",
            'total_amount' => "ADD COLUMN `total_amount` decimal(10,2) DEFAULT 0.00",
            'bezahlt' => "ADD COLUMN `bezahlt` tinyint(1) NOT NULL DEFAULT 0",
            'bezahlt_at' => "ADD COLUMN `bezahlt_at` timestamp NULL DEFAULT NULL",
            'fest_id' => "ADD COLUMN `fest_id` int(11) DEFAULT NULL",
            'tischnummer' => "ADD COLUMN `tischnummer` int(11) DEFAULT NULL",
            'betrag' => "ADD COLUMN `betrag` decimal(10,2) DEFAULT 0.00",
            'name' => "ADD COLUMN `name` varchar(100) DEFAULT NULL",
        ]
    ];
}

// ----------------------------------------------------------------------------
// Standard-Seeds für neue / migrierte Datenbanken
// ----------------------------------------------------------------------------
function ff_migration_run_seeds(mysqli $conn, array &$log): void {
    // 1. type (1=Speise, 2=Getränk)
    @mysqli_query($conn, "INSERT IGNORE INTO `type` (`rowid`, `name`) VALUES (1, 'Speise'), (2, 'Getränk')");
    $log[] = ['type' => 'success', 'msg' => 'Stammdaten: Typen (Speise / Getränk) sichergestellt.'];

    // 2. Druckziele (11=Küche, 12=Schank, 13=Feuerflecken)
    @mysqli_query($conn, "INSERT IGNORE INTO `print_targets` (`print_target`, `name`, `active`, `sort_order`) VALUES
        (11, 'Küche', 1, 10),
        (12, 'Schank', 1, 20),
        (13, 'Feuerflecken', 1, 30)");
    $log[] = ['type' => 'success', 'msg' => 'Stammdaten: Druckziele (Küche, Schank, Feuerflecken) sichergestellt.'];

    // 3. Feste (mindestens 1 aktives Fest)
    $festRes = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM `feste`");
    $festCount = $festRes ? (int)(mysqli_fetch_assoc($festRes)['c'] ?? 0) : 0;
    if ($festCount === 0) {
        @mysqli_query($conn, "INSERT INTO `feste` (`name`, `code`, `rechnung_prefix`, `aktiv`, `ist_aktuell`, `payment_mode`)
            VALUES ('Feuerwehrfest', 'fest2026', 'R', 1, 1, 'after')");
        $log[] = ['type' => 'success', 'msg' => 'Stammdaten: Standard-Veranstaltung (Feuerwehrfest) angelegt.'];
    }

    // 4. Default Settings
    $defaultSettings = [
        'kellner_nur_eigene' => '1',
        'fast_refresh' => '0',
        'current_fest_id' => '1',
        'current_fest_code' => 'fest2026',
        'seller_name' => 'Freiwillige Feuerwehr',
        'seller_address' => 'Hauptstraße 1',
        'seller_uid' => '',
        'rechnung_prefix' => 'R',
        'rechnung_festname' => 'Feuerwehrfest',
        'rechnung_logo' => '',
        'thermo_bon_header' => '',
        'thermo_bon_footer' => '',
        'printer_token' => '',
        'offline_backup_token' => '',
        'printer_warn_after_sec' => '60',
        'printer_job_stuck_reserved_min' => '10',
        'order_nr_seq' => '0',
        'bon_nr_seq' => '0',
        'bon_nr_start' => '1',
        'karte_spalten' => '3',
        'karte_spalten_mobil' => '3',
        'tisch_raster_spalten' => '5',
        'tisch_raster_spalten_mobil' => '5',
        'station_summary_top' => '1',
        'station_summary_right' => '1',
        'station_spalten' => '0',
        'station_spalten_mobil' => '0',
        'station_one_click_abschliessen' => '0',
        'station_teillieferung_druck' => '0',
        'app_title' => 'Bestellsystem',
        'session_max_idle_sec' => '900'
    ];
    $stmtSet = mysqli_prepare($conn, "INSERT IGNORE INTO `settings` (`k`, `v`) VALUES (?, ?)");
    if ($stmtSet) {
        foreach ($defaultSettings as $k => $v) {
            mysqli_stmt_bind_param($stmtSet, 'ss', $k, $v);
            mysqli_stmt_execute($stmtSet);
        }
        mysqli_stmt_close($stmtSet);
    }
    $log[] = ['type' => 'success', 'msg' => 'Stammdaten: Standard-Systemeinstellungen sichergestellt.'];

    // 5. Mitarbeiter Bereiche
    $bereiche = [
        ['Küche', 10],
        ['Schank', 20],
        ['Kellner', 30],
        ['Kommando', 40],
        ['Jugendfeuerwehr', 50],
        ['Sonstige', 99]
    ];
    $stmtBer = mysqli_prepare($conn, "INSERT IGNORE INTO `mitarbeiter_bereiche` (`name`, `sort_order`) VALUES (?, ?)");
    if ($stmtBer) {
        foreach ($bereiche as $b) {
            mysqli_stmt_bind_param($stmtBer, 'si', $b[0], $b[1]);
            mysqli_stmt_execute($stmtBer);
        }
        mysqli_stmt_close($stmtBer);
    }
    $log[] = ['type' => 'success', 'msg' => 'Stammdaten: Mitarbeiter-Bereiche sichergestellt.'];

    // 6. Allergene (Standard EU 14)
    $allergene = [
        'A (Gluten)', 'B (Krebstiere)', 'C (Eier)', 'D (Fisch)',
        'E (Erdnüsse)', 'F (Soja)', 'G (Milch / Laktose)', 'H (Schalenfrüchte)',
        'L (Sellerie)', 'M (Senf)', 'N (Sesam)', 'O (Sulfite)',
        'P (Lupinen)', 'R (Weichtiere)'
    ];
    $stmtAll = mysqli_prepare($conn, "INSERT IGNORE INTO `allergene` (`name`, `sort_order`) VALUES (?, ?)");
    if ($stmtAll) {
        $sort = 10;
        foreach ($allergene as $allName) {
            mysqli_stmt_bind_param($stmtAll, 'si', $allName, $sort);
            mysqli_stmt_execute($stmtAll);
            $sort += 10;
        }
        mysqli_stmt_close($stmtAll);
    }
    $log[] = ['type' => 'success', 'msg' => 'Stammdaten: EU-Allergene (A-R) sichergestellt.'];

    // 7. Standard Admin-User falls gar kein User existiert
    $userCountRes = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM `users`");
    $userCount = $userCountRes ? (int)(mysqli_fetch_assoc($userCountRes)['c'] ?? 0) : 0;
    if ($userCount === 0) {
        $pwHash = password_hash('admin123', PASSWORD_DEFAULT);
        @mysqli_query($conn, "INSERT INTO `users` (`username`, `password`, `admin`, `can_finance`, `start_page`)
            VALUES ('admin', '{$pwHash}', 2, 1, 'menu')");
        $log[] = ['type' => 'warning', 'msg' => 'Sicherheit: Kein Benutzer vorhanden -> Standard-Admin angelegt (Benutzer: admin / Kennwort: admin123). Bitte sofort ändern!'];
    }

    // 8. Positionen print_target Defaults aktualisieren falls 0 oder ungesetzt
    @mysqli_query($conn, "UPDATE `positionen` SET `print_target` = 12 WHERE (`print_target` IS NULL OR `print_target` = 0) AND `type` = 2");
    @mysqli_query($conn, "UPDATE `positionen` SET `print_target` = 11 WHERE (`print_target` IS NULL OR `print_target` = 0) AND `type` = 1");

    // 9. Table Tokens generieren für Tische die noch keinen haben
    $tischeRes = @mysqli_query($conn, "SELECT tischnummer FROM `tische`");
    if ($tischeRes) {
        $tokenStmt = mysqli_prepare($conn, "INSERT IGNORE INTO `table_tokens` (`table_number`, `token`, `active`) VALUES (?, ?, 1)");
        $tokensCreated = 0;
        while ($tRow = mysqli_fetch_assoc($tischeRes)) {
            $tNum = (int)$tRow['tischnummer'];
            $chkTok = mysqli_query($conn, "SELECT id FROM `table_tokens` WHERE `table_number` = {$tNum} LIMIT 1");
            if ($chkTok && mysqli_num_rows($chkTok) === 0) {
                $rndToken = bin2hex(random_bytes(32));
                if ($tokenStmt) {
                    mysqli_stmt_bind_param($tokenStmt, 'is', $tNum, $rndToken);
                    mysqli_stmt_execute($tokenStmt);
                    $tokensCreated++;
                }
            }
            if ($chkTok) mysqli_free_result($chkTok);
        }
        if ($tokenStmt) mysqli_stmt_close($tokenStmt);
        mysqli_free_result($tischeRes);
        if ($tokensCreated > 0) {
            $log[] = ['type' => 'success', 'msg' => "QR-Bestellsystem: {$tokensCreated} Tisch-Sicherheitstoken für bestehende Tische generiert."];
        }
    }
}

// ----------------------------------------------------------------------------
// Hauptmigrationsfunktion: Führt die Migration / das Update durch
// ----------------------------------------------------------------------------
function ff_execute_migration(array $params): array {
    $log = [];

    // Quell-Server Konfiguration
    $srcHost = trim((string)($params['source_host'] ?? ($params['host'] ?? '127.0.0.1')));
    $srcPort = (int)($params['source_port'] ?? ($params['port'] ?? 3306));
    $srcUser = trim((string)($params['source_user'] ?? ($params['user'] ?? 'root')));
    $srcPass = (string)($params['source_pass'] ?? ($params['pass'] ?? ''));
    $srcDb   = trim((string)($params['source_db'] ?? ''));

    $mode = (string)($params['mode'] ?? 'clone_and_upgrade');
    $convertEngine = !empty($params['convert_innodb_utf8mb4']);

    if ($srcDb === '') {
        return ['ok' => false, 'error' => 'Bitte geben Sie den Namen der Quell-Datenbank an.', 'log' => $log];
    }

    // Ziel-Server Konfiguration
    if ($mode === 'inplace') {
        $tgtHost = $srcHost;
        $tgtPort = $srcPort;
        $tgtUser = $srcUser;
        $tgtPass = $srcPass;
        $tgtDb   = $srcDb;
    } else {
        $tgtHost = trim((string)($params['target_host'] ?? ''));
        if ($tgtHost === '') $tgtHost = $srcHost;

        $tgtPort = (int)($params['target_port'] ?? 0);
        if ($tgtPort <= 0) $tgtPort = $srcPort;

        $tgtUser = trim((string)($params['target_user'] ?? ''));
        if ($tgtUser === '') $tgtUser = $srcUser;

        $tgtPass = array_key_exists('target_pass', $params) ? (string)$params['target_pass'] : $srcPass;
        $tgtDb   = trim((string)($params['target_db'] ?? ''));

        if ($tgtDb === '') {
            return ['ok' => false, 'error' => 'Bitte geben Sie den Namen der Ziel-Datenbank an.', 'log' => $log];
        }
    }

    $isSameServer = ($srcHost === $tgtHost && $srcPort === $tgtPort && $srcUser === $tgtUser && $srcPass === $tgtPass);

    $log[] = ['type' => 'info', 'msg' => "Start der Migration im Modus: " . ($mode === 'inplace' ? "In-Place Update (direkt auf '{$srcDb}')" : "Clone & Upgrade ('{$srcDb}' auf {$srcHost} -> '{$tgtDb}' auf {$tgtHost})")];

    // 1. Verbindung zur Quell-Datenbank aufbauen
    $srcRes = ff_db_connect($srcHost, $srcPort, $srcUser, $srcPass, $srcDb);
    if ($srcRes['error']) {
        return ['ok' => false, 'error' => "Fehler bei Verbindung zur Quell-Datenbank ({$srcHost}:{$srcPort}): " . $srcRes['error'], 'log' => $log];
    }
    $srcConn = $srcRes['conn'];
    $log[] = ['type' => 'success', 'msg' => "Erfolgreich mit Quell-Server verbunden ({$srcHost}:{$srcPort}), Quell-DB '{$srcDb}' geöffnet."];

    // Vorhandene Tabellen in Quell-DB auflisten
    $srcTablesRes = mysqli_query($srcConn, "SHOW TABLES FROM `{$srcDb}`");
    $srcTables = [];
    while ($r = mysqli_fetch_row($srcTablesRes)) {
        $srcTables[] = $r[0];
    }
    $log[] = ['type' => 'info', 'msg' => "Gefundene Tabellen in Quell-DB: " . implode(', ', $srcTables)];

    $targetTablesDef = ff_migration_get_target_tables();
    $columnPatches = ff_migration_get_column_patches();

    // =========================================================================
    // MODUS A: CLONE & UPGRADE (Quell-DB -> Ziel-DB, ggf. separater Server)
    // =========================================================================
    if ($mode === 'clone_and_upgrade' && !($isSameServer && strcasecmp($srcDb, $tgtDb) === 0)) {
        // Ziel-Verbindung aufbauen
        if ($isSameServer) {
            $tgtConn = $srcConn;
            $log[] = ['type' => 'info', 'msg' => "Quelle und Ziel liegen auf demselben MySQL-Server."];
        } else {
            $tgtRes = ff_db_connect($tgtHost, $tgtPort, $tgtUser, $tgtPass);
            if ($tgtRes['error']) {
                return ['ok' => false, 'error' => "Fehler bei Verbindung zum Ziel-Server ({$tgtHost}:{$tgtPort}): " . $tgtRes['error'], 'log' => $log];
            }
            $tgtConn = $tgtRes['conn'];
            $log[] = ['type' => 'success', 'msg' => "Erfolgreich mit separatem Ziel-Server verbunden ({$tgtHost}:{$tgtPort}) als '{$tgtUser}'."];
        }

        @mysqli_query($tgtConn, "SET FOREIGN_KEY_CHECKS = 0;");

        // Ziel-Datenbank erstellen/auswählen
        $createDbSql = "CREATE DATABASE IF NOT EXISTS `{$tgtDb}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        if (!@mysqli_query($tgtConn, $createDbSql)) {
            // Falls keine globalen CREATE-Rechte vorhanden sind, testen ob DB bereits existiert
            if (!@mysqli_select_db($tgtConn, $tgtDb)) {
                return ['ok' => false, 'error' => "Ziel-Datenbank '{$tgtDb}' auf {$tgtHost} konnte weder erstellt noch ausgewählt werden: " . mysqli_error($tgtConn), 'log' => $log];
            }
            $log[] = ['type' => 'info', 'msg' => "Ziel-Datenbank '{$tgtDb}' existiert bereits auf {$tgtHost}."];
        } else {
            $log[] = ['type' => 'success', 'msg' => "Ziel-Datenbank '{$tgtDb}' auf {$tgtHost} initialisiert."];
        }

        if (!@mysqli_select_db($tgtConn, $tgtDb)) {
            return ['ok' => false, 'error' => "Konnte nicht zur Ziel-Datenbank '{$tgtDb}' wechseln: " . mysqli_error($tgtConn), 'log' => $log];
        }

        // Alle 24 modernen Tabellen im Ziel anlegen
        foreach ($targetTablesDef as $tblName => $createSql) {
            if (!mysqli_query($tgtConn, $createSql)) {
                $log[] = ['type' => 'error', 'msg' => "Fehler beim Anlegen von '{$tblName}' im Ziel: " . mysqli_error($tgtConn)];
            } else {
                $log[] = ['type' => 'info', 'msg' => "Tabelle '{$tblName}' in Ziel-DB bereitgestellt."];
            }
        }

        // Daten aus Quell-DB kopieren für jede übereinstimmende Tabelle
        foreach ($srcTables as $tblName) {
            // Falls Tabelle in Target-Definitionen fehlt (z.B. legacy 'tabelle'), Struktur im Ziel anlegen
            if (!isset($targetTablesDef[$tblName])) {
                $cRes = mysqli_query($srcConn, "SHOW CREATE TABLE `{$srcDb}`.`{$tblName}`");
                if ($cRes && ($ctRow = mysqli_fetch_row($cRes))) {
                    $createLikeSql = preg_replace('/^CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', $ctRow[1]);
                    @mysqli_query($tgtConn, $createLikeSql);
                    $log[] = ['type' => 'info', 'msg' => "Legacy-Tabelle '{$tblName}' Struktur auf Ziel-Server übertragen."];
                }
            }

            // Spalten von Quelle und Ziel analysieren
            $srcColsRes = mysqli_query($srcConn, "SHOW COLUMNS FROM `{$srcDb}`.`{$tblName}`");
            if (!$srcColsRes) continue;
            $srcCols = [];
            while ($c = mysqli_fetch_assoc($srcColsRes)) {
                $srcCols[strtolower($c['Field'])] = $c['Field'];
            }

            $tgtColsRes = mysqli_query($tgtConn, "SHOW COLUMNS FROM `{$tgtDb}`.`{$tblName}`");
            if (!$tgtColsRes) continue;
            $tgtCols = [];
            while ($c = mysqli_fetch_assoc($tgtColsRes)) {
                $tgtCols[strtolower($c['Field'])] = $c['Field'];
            }

            // Gemeinsame Spalten ermitteln mit exaktem Namen in Quelle und Ziel
            $tgtColsList = [];
            $srcColsList = [];
            $srcColNames = [];
            foreach ($srcCols as $lowerName => $origSrcCol) {
                if (isset($tgtCols[$lowerName])) {
                    $tgtColsList[] = '`' . $tgtCols[$lowerName] . '`';
                    $srcColsList[] = '`' . $origSrcCol . '`';
                    $srcColNames[] = $origSrcCol;
                }
            }

            if (empty($tgtColsList)) {
                $log[] = ['type' => 'warning', 'msg' => "Tabelle '{$tblName}': Keine gemeinsamen Spalten zum Kopieren gefunden."];
                continue;
            }

            // Zählen wie viele Zeilen vorhanden sind
            $countRes = mysqli_query($srcConn, "SELECT COUNT(*) AS c FROM `{$srcDb}`.`{$tblName}`");
            $rowCount = $countRes ? (int)(mysqli_fetch_assoc($countRes)['c'] ?? 0) : 0;

            if ($rowCount === 0) {
                $log[] = ['type' => 'info', 'msg' => "Tabelle '{$tblName}': 0 Datensätze in Quelle."];
                continue;
            }

            // Ziel-Tabelle vor dem Import leeren (idempotent)
            mysqli_query($tgtConn, "TRUNCATE TABLE `{$tgtDb}`.`{$tblName}`");

            $tgtStr = implode(', ', $tgtColsList);
            $srcStr = implode(', ', $srcColsList);

            // Versuche High-Speed Direktabfrage wenn Quelle und Ziel auf demselben Server liegen
            $copiedDirectly = false;
            if ($isSameServer && strcasecmp($srcDb, $tgtDb) !== 0) {
                $copySql = "INSERT INTO `{$tgtDb}`.`{$tblName}` ({$tgtStr}) SELECT {$srcStr} FROM `{$srcDb}`.`{$tblName}`";
                if (@mysqli_query($srcConn, $copySql)) {
                    $log[] = ['type' => 'success', 'msg' => "Tabelle '{$tblName}': {$rowCount} Datensätze via High-Speed-Query kopiert."];
                    $copiedDirectly = true;
                }
            }

            // Fallback & Serverübergreifender Batch-Streaming-Transfer (unterschiedliche Server / User)
            if (!$copiedDirectly) {
                $selectSql = "SELECT {$srcStr} FROM `{$srcDb}`.`{$tblName}`";
                $srcQueryRes = mysqli_query($srcConn, $selectSql, MYSQLI_USE_RESULT);
                if (!$srcQueryRes) {
                    $log[] = ['type' => 'error', 'msg' => "Tabelle '{$tblName}': Lesen aus Quell-Server fehlgeschlagen: " . mysqli_error($srcConn)];
                    continue;
                }

                $chunkValues = [];
                $copiedCount = 0;
                $chunkSize = 300;
                $chunkError = false;

                while ($row = mysqli_fetch_assoc($srcQueryRes)) {
                    $rowVals = [];
                    foreach ($srcColNames as $colName) {
                        $val = $row[$colName] ?? null;
                        if ($val === null) {
                            $rowVals[] = 'NULL';
                        } else {
                            $rowVals[] = "'" . mysqli_real_escape_string($tgtConn, (string)$val) . "'";
                        }
                    }
                    $chunkValues[] = '(' . implode(',', $rowVals) . ')';
                    $copiedCount++;

                    if (count($chunkValues) >= $chunkSize) {
                        $insChunk = "INSERT INTO `{$tgtDb}`.`{$tblName}` ({$tgtStr}) VALUES " . implode(',', $chunkValues);
                        if (!mysqli_query($tgtConn, $insChunk)) {
                            $log[] = ['type' => 'error', 'msg' => "Tabelle '{$tblName}': Fehler beim Batch-Einfügen auf Ziel-Server: " . mysqli_error($tgtConn)];
                            $chunkError = true;
                            break;
                        }
                        $chunkValues = [];
                    }
                }

                if (!$chunkError && !empty($chunkValues)) {
                    $insChunk = "INSERT INTO `{$tgtDb}`.`{$tblName}` ({$tgtStr}) VALUES " . implode(',', $chunkValues);
                    if (!mysqli_query($tgtConn, $insChunk)) {
                        $log[] = ['type' => 'error', 'msg' => "Tabelle '{$tblName}': Fehler beim letzten Batch auf Ziel-Server: " . mysqli_error($tgtConn)];
                        $chunkError = true;
                    }
                }

                if (is_object($srcQueryRes)) {
                    mysqli_free_result($srcQueryRes);
                }

                if (!$chunkError) {
                    $log[] = ['type' => 'success', 'msg' => "Tabelle '{$tblName}': {$copiedCount} Datensätze serverübergreifend erfolgreich übertragen."];
                }
            }
        }

        // Spezifische Spalten-Defaults nach dem Kopieren im Ziel setzen
        @mysqli_query($tgtConn, "UPDATE `{$tgtDb}`.`positionen` SET `print_target` = 12 WHERE (`print_target` IS NULL OR `print_target` = 0) AND `type` = 2");
        @mysqli_query($tgtConn, "UPDATE `{$tgtDb}`.`positionen` SET `print_target` = 11 WHERE (`print_target` IS NULL OR `print_target` = 0) AND `type` = 1");
        @mysqli_query($tgtConn, "UPDATE `{$tgtDb}`.`bestellungen` b JOIN `{$tgtDb}`.`positionen` p ON p.rowid = b.position SET b.print_target = p.print_target WHERE b.print_target IS NULL");
        @mysqli_query($tgtConn, "UPDATE `{$tgtDb}`.`users` SET `start_page` = 'menu' WHERE `start_page` IS NULL OR `start_page` = ''");
        @mysqli_query($tgtConn, "UPDATE `{$tgtDb}`.`users` SET `can_finance` = 1 WHERE `admin` > 0 AND (`can_finance` IS NULL OR `can_finance` = 0)");

        // Seeds & Standardwerte in der Ziel-DB ausführen
        ff_migration_run_seeds($tgtConn, $log);

        @mysqli_query($tgtConn, "SET FOREIGN_KEY_CHECKS = 1;");
    }
    // =========================================================================
    // MODUS B: IN-PLACE UPGRADE (Bestehende DB direkt auf Quell-Server)
    // =========================================================================
    else {
        // 1. Alle neuen Tabellen erstellen falls noch nicht vorhanden
        foreach ($targetTablesDef as $tblName => $createSql) {
            if (!in_array($tblName, $srcTables, true)) {
                if (mysqli_query($srcConn, $createSql)) {
                    $log[] = ['type' => 'success', 'msg' => "Neue Tabelle '{$tblName}' erfolgreich angelegt."];
                } else {
                    $log[] = ['type' => 'error', 'msg' => "Fehler beim Anlegen von '{$tblName}': " . mysqli_error($srcConn)];
                }
            }
        }

        // 2. Bestehende Tabellen auf InnoDB und utf8mb4 konvertieren
        if ($convertEngine) {
            foreach ($srcTables as $tblName) {
                $alterTableSql = "ALTER TABLE `{$tblName}` ENGINE=InnoDB CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                if (@mysqli_query($srcConn, $alterTableSql)) {
                    $log[] = ['type' => 'success', 'msg' => "Tabelle '{$tblName}': Auf InnoDB & utf8mb4 konvertiert."];
                } else {
                    $log[] = ['type' => 'warning', 'msg' => "Tabelle '{$tblName}': Konvertierung InnoDB/utf8mb4 mit Hinweis: " . mysqli_error($srcConn)];
                }
            }
        }

        // 3. Spezifische Spalten-Patches für bestehende Tabellen anwenden
        foreach ($columnPatches as $tblName => $patches) {
            if (!in_array($tblName, $srcTables, true)) {
                continue;
            }

            $existingColsRes = mysqli_query($srcConn, "SHOW COLUMNS FROM `{$tblName}`");
            $existingCols = [];
            while ($c = mysqli_fetch_assoc($existingColsRes)) {
                $existingCols[strtolower($c['Field'])] = true;
            }

            foreach ($patches as $colName => $alterSql) {
                if (!isset($existingCols[strtolower($colName)])) {
                    $fullAlter = "ALTER TABLE `{$tblName}` " . $alterSql;
                    if (mysqli_query($srcConn, $fullAlter)) {
                        $log[] = ['type' => 'success', 'msg' => "Tabelle '{$tblName}': Fehlende Spalte '{$colName}' hinzugefügt."];
                    } else {
                        $log[] = ['type' => 'error', 'msg' => "Tabelle '{$tblName}': Fehler beim Hinzufügen von '{$colName}': " . mysqli_error($srcConn)];
                    }
                }
            }
        }

        // 4. Feldlänge tische.tischname auf VARCHAR(50) erweitern
        if (in_array('tische', $srcTables, true)) {
            @mysqli_query($srcConn, "ALTER TABLE `tische` MODIFY `tischname` VARCHAR(50) NOT NULL");
            $log[] = ['type' => 'info', 'msg' => "Tabelle 'tische': Feldlänge für 'tischname' auf VARCHAR(50) erweitert."];
        }

        // 5. Seeds & Standardwerte ausführen
        ff_migration_run_seeds($srcConn, $log);
    }

    $log[] = ['type' => 'success', 'msg' => '=== Migration & Schema-Aktualisierung erfolgreich abgeschlossen! ==='];
    return ['ok' => true, 'log' => $log];
}

// ----------------------------------------------------------------------------
// Analyse-Funktion: Prüft Verbindungen und Schemata ohne Änderungen
// ----------------------------------------------------------------------------
function ff_analyze_databases(array $params): array {
    $srcHost = trim((string)($params['source_host'] ?? ($params['host'] ?? '127.0.0.1')));
    $srcPort = (int)($params['source_port'] ?? ($params['port'] ?? 3306));
    $srcUser = trim((string)($params['source_user'] ?? ($params['user'] ?? 'root')));
    $srcPass = (string)($params['source_pass'] ?? ($params['pass'] ?? ''));
    $srcDb   = trim((string)($params['source_db'] ?? ''));

    $mode = (string)($params['mode'] ?? 'clone_and_upgrade');

    if ($mode === 'inplace') {
        $tgtHost = $srcHost;
        $tgtPort = $srcPort;
        $tgtUser = $srcUser;
        $tgtPass = $srcPass;
        $tgtDb   = $srcDb;
    } else {
        $tgtHost = trim((string)($params['target_host'] ?? ''));
        if ($tgtHost === '') $tgtHost = $srcHost;
        $tgtPort = (int)($params['target_port'] ?? 0);
        if ($tgtPort <= 0) $tgtPort = $srcPort;
        $tgtUser = trim((string)($params['target_user'] ?? ''));
        if ($tgtUser === '') $tgtUser = $srcUser;
        $tgtPass = array_key_exists('target_pass', $params) ? (string)$params['target_pass'] : $srcPass;
        $tgtDb   = trim((string)($params['target_db'] ?? ''));
    }

    // 1. Quell-Server prüfen
    $srcRes = ff_db_connect($srcHost, $srcPort, $srcUser, $srcPass, $srcDb);
    if ($srcRes['error']) {
        return ['ok' => false, 'error' => "Quell-Server ({$srcHost}:{$srcPort}): " . $srcRes['error'], 'report' => []];
    }
    $srcConn = $srcRes['conn'];

    $srcTablesRes = mysqli_query($srcConn, "SHOW TABLE STATUS FROM `{$srcDb}`");
    $tablesReport = [];
    $targetTablesDef = ff_migration_get_target_tables();
    $columnPatches = ff_migration_get_column_patches();

    while ($tbl = mysqli_fetch_assoc($srcTablesRes)) {
        $tName = $tbl['Name'];
        $engine = $tbl['Engine'];
        $collation = $tbl['Collation'];
        $rows = (int)($tbl['Rows'] ?? 0);

        $colsRes = mysqli_query($srcConn, "SHOW COLUMNS FROM `{$srcDb}`.`{$tName}`");
        $cols = [];
        while ($c = mysqli_fetch_assoc($colsRes)) {
            $cols[] = $c['Field'];
        }

        $missingCols = [];
        if (isset($columnPatches[$tName])) {
            foreach ($columnPatches[$tName] as $colName => $alter) {
                if (!in_array($colName, $cols, true) && !in_array(strtolower($colName), array_map('strtolower', $cols), true)) {
                    $missingCols[] = $colName;
                }
            }
        }

        $tablesReport[$tName] = [
            'engine' => $engine,
            'collation' => $collation,
            'rows' => $rows,
            'columns_count' => count($cols),
            'missing_target_columns' => $missingCols
        ];
    }

    $missingTables = [];
    foreach (array_keys($targetTablesDef) as $tName) {
        if (!isset($tablesReport[$tName])) {
            $missingTables[] = $tName;
        }
    }

    // 2. Ziel-Server prüfen falls Clone-Modus
    $isSameServer = ($srcHost === $tgtHost && $srcPort === $tgtPort && $srcUser === $tgtUser && $srcPass === $tgtPass);
    $targetExists = false;
    $targetServerStatus = 'Identisch mit Quell-Server';

    if ($mode === 'clone_and_upgrade' && $tgtDb !== '') {
        if ($isSameServer) {
            $tgtConn = $srcConn;
            $targetServerStatus = "Gleicher Server ({$srcHost}:{$srcPort})";
        } else {
            $tgtRes = ff_db_connect($tgtHost, $tgtPort, $tgtUser, $tgtPass);
            if ($tgtRes['error']) {
                return ['ok' => false, 'error' => "Ziel-Server ({$tgtHost}:{$tgtPort}): Verbindung fehlgeschlagen: " . $tgtRes['error'], 'report' => []];
            }
            $tgtConn = $tgtRes['conn'];
            $targetServerStatus = "Separater Server ({$tgtHost}:{$tgtPort}) erfolgreich erreichbar";
        }

        $tgtCheck = @mysqli_query($tgtConn, "SHOW DATABASES LIKE '" . mysqli_real_escape_string($tgtConn, $tgtDb) . "'");
        $targetExists = ($tgtCheck && mysqli_num_rows($tgtCheck) > 0);
    }

    return [
        'ok' => true,
        'report' => [
            'mode' => $mode,
            'is_same_server' => $isSameServer,
            'source_server' => [
                'host' => $srcHost,
                'port' => $srcPort,
                'user' => $srcUser,
                'db' => $srcDb,
            ],
            'target_server' => [
                'host' => $tgtHost,
                'port' => $tgtPort,
                'user' => $tgtUser,
                'db' => $tgtDb,
                'exists' => $targetExists,
                'status_text' => $targetServerStatus
            ],
            'existing_tables' => $tablesReport,
            'missing_tables' => $missingTables
        ]
    ];
}

// ----------------------------------------------------------------------------
// CLI-Ausführungshandler
// ----------------------------------------------------------------------------
if ($isCli) {
    echo "========================================================\n";
    echo "Feuerwehr-Bestellsystem: Datenbank Migration & Update CLI\n";
    echo "========================================================\n\n";

    if (isset($cliArgs['help'])) {
        echo "Optionen:\n";
        echo "  Quell-Server:\n";
        echo "    --source-host=...          Quell-Host (Default: {$defaultSourceHost})\n";
        echo "    --source-port=3306         Quell-Port\n";
        echo "    --source-user=...          Quell-Benutzer\n";
        echo "    --source-pass=...          Quell-Passwort\n";
        echo "    --source-db=...            Quell-Datenbank (z.B. 2729544db7)\n\n";
        echo "  Ziel-Server:\n";
        echo "    --target-host=...          Ziel-Host (Default: {$defaultTargetHost})\n";
        echo "    --target-port=3306         Ziel-Port\n";
        echo "    --target-user=...          Ziel-Benutzer\n";
        echo "    --target-pass=...          Ziel-Passwort\n";
        echo "    --target-db=...            Ziel-Datenbank (z.B. 5514037db13)\n\n";
        echo "  Fallback (falls Quelle & Ziel auf gleichem Server):\n";
        echo "    --host=...                 MySQL Host für Quelle & Ziel\n";
        echo "    --port=3306                MySQL Port für Quelle & Ziel\n";
        echo "    --user=...                 MySQL Benutzer für Quelle & Ziel\n";
        echo "    --pass=...                 MySQL Passwort für Quelle & Ziel\n\n";
        echo "  Aktion & Modus:\n";
        echo "    --mode=clone|inplace       'clone' (Default) oder 'inplace'\n";
        echo "    --analyze                  Nur Analyse durchführen (ohne Änderungen)\n";
        echo "    --run                      Migration/Update ausführen\n\n";
        exit(0);
    }

    $params = [
        'source_host' => $cliArgs['source-host'] ?? ($cliArgs['host'] ?? $defaultSourceHost),
        'source_port' => (int)($cliArgs['source-port'] ?? ($cliArgs['port'] ?? $defaultSourcePort)),
        'source_user' => $cliArgs['source-user'] ?? ($cliArgs['user'] ?? $defaultSourceUser),
        'source_pass' => (string)($cliArgs['source-pass'] ?? ($cliArgs['pass'] ?? $defaultSourcePass)),
        'source_db'   => $cliArgs['source-db'] ?? ($cliArgs['source'] ?? $defaultSourceDb),

        'target_host' => $cliArgs['target-host'] ?? ($cliArgs['host'] ?? $defaultTargetHost),
        'target_port' => (int)($cliArgs['target-port'] ?? ($cliArgs['port'] ?? $defaultTargetPort)),
        'target_user' => $cliArgs['target-user'] ?? ($cliArgs['user'] ?? $defaultTargetUser),
        'target_pass' => (string)($cliArgs['target-pass'] ?? ($cliArgs['pass'] ?? $defaultTargetPass)),
        'target_db'   => $cliArgs['target-db'] ?? ($cliArgs['target'] ?? $defaultTargetDb),

        'mode' => isset($cliArgs['inplace']) || ($cliArgs['mode'] ?? '') === 'inplace' ? 'inplace' : 'clone_and_upgrade',
        'convert_innodb_utf8mb4' => true
    ];

    if (isset($cliArgs['analyze'])) {
        echo "Führe Analyse durch...\n";
        $res = ff_analyze_databases($params);
        if (!$res['ok']) {
            echo "FEHLER: " . $res['error'] . "\n";
            exit(1);
        }
        $rep = $res['report'];
        echo "Quell-Server: {$rep['source_server']['host']}:{$rep['source_server']['port']} | DB: {$rep['source_server']['db']}\n";
        echo "Ziel-Server:  {$rep['target_server']['host']}:{$rep['target_server']['port']} | DB: {$rep['target_server']['db']} ({$rep['target_server']['status_text']})\n";
        echo "Gefundene Tabellen (" . count($rep['existing_tables']) . "):\n";
        foreach ($rep['existing_tables'] as $tName => $tInfo) {
            echo "  - {$tName}: {$tInfo['rows']} Zeilen [{$tInfo['engine']}, {$tInfo['collation']}]";
            if (!empty($tInfo['missing_target_columns'])) {
                echo " -> FEHLENDE SPALTEN: " . implode(', ', $tInfo['missing_target_columns']);
            }
            echo "\n";
        }
        echo "\nNoch fehlende neue Tabellen (" . count($rep['missing_tables']) . "):\n";
        echo "  " . implode(', ', $rep['missing_tables']) . "\n\n";
        exit(0);
    }

    if (isset($cliArgs['run'])) {
        echo "Starte Migration...\n";
        $res = ff_execute_migration($params);
        foreach ($res['log'] as $entry) {
            $prefix = $entry['type'] === 'error' ? '[FEHLER] ' : ($entry['type'] === 'warning' ? '[WARNUNG] ' : '[OK] ');
            echo $prefix . $entry['msg'] . "\n";
        }
        if (!$res['ok']) {
            echo "\nABBRUCH: " . ($res['error'] ?? 'Unbekannter Fehler') . "\n";
            exit(1);
        }
        echo "\nFERTIG! Datenbank ist auf dem neuesten Stand.\n";
        exit(0);
    }

    echo "Tipp: Führe mit --analyze oder --run aus. Siehe --help für Parameter.\n";
    exit(0);
}

// ----------------------------------------------------------------------------
// Web-Interface (HTML / CSS / JS)
// ----------------------------------------------------------------------------
$action = $_POST['action'] ?? '';
$separateTarget = !empty($_POST['separate_target']) || (!empty($_POST['target_host']) && $_POST['target_host'] !== ($_POST['source_host'] ?? ''));

$webParams = [
    'source_host' => $_POST['source_host'] ?? $defaultSourceHost,
    'source_port' => (int)($_POST['source_port'] ?? $defaultSourcePort),
    'source_user' => $_POST['source_user'] ?? $defaultSourceUser,
    'source_pass' => $_POST['source_pass'] ?? $defaultSourcePass,
    'source_db'   => $_POST['source_db']   ?? $defaultSourceDb,

    'separate_target' => $separateTarget,
    'target_host' => $_POST['target_host'] ?? $defaultTargetHost,
    'target_port' => (int)($_POST['target_port'] ?? $defaultTargetPort),
    'target_user' => $_POST['target_user'] ?? $defaultTargetUser,
    'target_pass' => $_POST['target_pass'] ?? $defaultTargetPass,
    'target_db'   => $_POST['target_db']   ?? $defaultTargetDb,

    'mode' => $_POST['mode'] ?? 'clone_and_upgrade',
    'convert_innodb_utf8mb4' => !empty($_POST['convert_innodb_utf8mb4']) || empty($_POST),
];

$analysisResult = null;
$migrationResult = null;

if ($action === 'analyze') {
    $analysisResult = ff_analyze_databases($webParams);
} elseif ($action === 'migrate') {
    $migrationResult = ff_execute_migration($webParams);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Datenbank-Update & Migration · Feuerwehr-Bestellsystem</title>
    <style>
        :root {
            --primary: #c0392b;
            --primary-dark: #962d22;
            --secondary: #2b6cb0;
            --success: #27ae60;
            --warning: #d35400;
            --bg: #f4f6f9;
            --card-bg: #ffffff;
            --text: #2c3e50;
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px 16px 60px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
        }
        .container {
            max-width: 980px;
            margin: 0 auto;
        }
        .header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 24px 28px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 4px 15px rgba(192, 57, 43, 0.25);
        }
        .header h1 {
            margin: 0 0 6px;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header p {
            margin: 0;
            opacity: 0.95;
            font-size: 14px;
        }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .card-title {
            margin: 0 0 16px;
            font-size: 17px;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 1px solid #edf2f7;
            padding-bottom: 10px;
        }
        .server-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 16px;
        }
        .server-box {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
        }
        .server-box-title {
            font-size: 14px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        label {
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
        }
        input[type="text"], input[type="password"], input[type="number"], select {
            padding: 8px 11px;
            border: 1.5px solid #cbd5e0;
            border-radius: 6px;
            font-size: 13.5px;
            font-family: inherit;
            background: #fff;
            transition: border-color 0.2s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(192, 57, 43, 0.15);
        }
        .radio-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 8px 0 16px;
            background: #f8fafc;
            padding: 14px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .radio-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
        }
        .radio-item input { margin-top: 3px; }
        .radio-label strong { display: block; font-size: 14px; }
        .radio-label span { font-size: 12px; color: #718096; }
        .button-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }
        button {
            padding: 11px 20px;
            border: none;
            border-radius: 7px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-analyze {
            background: var(--secondary);
            color: white;
        }
        .btn-analyze:hover { background: #2c5282; }
        .btn-migrate {
            background: var(--primary);
            color: white;
        }
        .btn-migrate:hover { background: var(--primary-dark); }
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #def7ec; color: #03543f; border: 1px solid #bcf0da; }
        .alert-error { background: #fde8e8; color: #9b1c1c; border: 1px solid #fbd5d5; }
        .alert-warning { background: #fef08a; color: #713f12; border: 1px solid #fde047; }
        .log-box {
            background: #1a202c;
            color: #e2e8f0;
            padding: 16px;
            border-radius: 8px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 13px;
            max-height: 450px;
            overflow-y: auto;
            line-height: 1.6;
            margin-top: 12px;
        }
        .log-line { margin: 2px 0; }
        .log-info { color: #63b3ed; }
        .log-success { color: #68d391; }
        .log-warning { color: #f6e05e; }
        .log-error { color: #fc8181; font-weight: bold; }
        table.status-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 12px;
        }
        table.status-table th, table.status-table td {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            text-align: left;
        }
        table.status-table th { background: #f8fafc; font-weight: 600; }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-green { background: #def7ec; color: #03543f; }
        .badge-orange { background: #feecdc; color: #9c4221; }
        .badge-blue { background: #ebf8ff; color: #2b6cb0; }
    </style>
</head>
<body>

<div class="container">

    <div class="header">
        <h1>🚒 Datenbank-Update & Migration</h1>
        <p>Konvertiert MyISAM/latin1 auf modernes InnoDB/utf8mb4 und migriert Daten – auch zwischen zwei separaten MySQL-Servern mit unterschiedlichen Zugangsdaten.</p>
    </div>

    <?php if ($migrationResult): ?>
        <?php if ($migrationResult['ok']): ?>
            <div class="alert alert-success">
                ✅ <strong>Migration erfolgreich abgeschlossen!</strong> Alle Tabellen und Daten wurden auf das moderne Schema übertragen.
            </div>
        <?php else: ?>
            <div class="alert alert-error">
                ❌ <strong>Fehler bei der Migration:</strong> <?= htmlspecialchars($migrationResult['error'] ?? '') ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-title">📜 Migrations-Protokoll</div>
            <div class="log-box">
                <?php foreach ($migrationResult['log'] as $entry): ?>
                    <div class="log-line log-<?= htmlspecialchars($entry['type']) ?>">
                        <?= htmlspecialchars($entry['msg']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="card">
            <div class="card-title">⚙️ Betriebsmodus wählen</div>

            <div class="radio-group">
                <label class="radio-item">
                    <input type="radio" name="mode" value="clone_and_upgrade" <?= $webParams['mode'] === 'clone_and_upgrade' ? 'checked' : '' ?> onchange="toggleMode()">
                    <div class="radio-label">
                        <strong>1. Neue Ziel-Datenbank erstellen & Bestandsdaten migrieren (Empfohlen)</strong>
                        <span>Erstellt die Ziel-DB auf dem Ziel-Server mit sauberem Schema (InnoDB, utf8mb4) und kopiert alle Daten. Quell-DB bleibt unberührt. Funktioniert lokal oder serverübergreifend!</span>
                    </div>
                </label>
                <label class="radio-item">
                    <input type="radio" name="mode" value="inplace" <?= $webParams['mode'] === 'inplace' ? 'checked' : '' ?> onchange="toggleMode()">
                    <div class="radio-label">
                        <strong>2. In-Place Update (Direkt auf Quell-Server)</strong>
                        <span>Konvertiert Tabellen vor Ort auf InnoDB/utf8mb4, ergänzt fehlende Spalten und legt neue Tabellen an, ohne eine zweite Datenbank zu benötigen.</span>
                    </div>
                </label>
            </div>
        </div>

        <div class="card">
            <div class="card-title">🗄️ Server-Verbindungen & Datenbanken</div>

            <div class="server-grid">
                <!-- Quell-Server Box -->
                <div class="server-box">
                    <div class="server-box-title">
                        <span>📥 Quell-Server (Altsystem)</span>
                    </div>

                    <div class="form-grid">
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="source_host">Quell-MySQL Host</label>
                            <input type="text" id="source_host" name="source_host" value="<?= htmlspecialchars($webParams['source_host']) ?>" placeholder="z. B. mysqlsvr83.world4you.com" required>
                        </div>
                        <div class="form-group">
                            <label for="source_port">Port</label>
                            <input type="number" id="source_port" name="source_port" value="<?= (int)$webParams['source_port'] ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="source_user">Benutzername</label>
                            <input type="text" id="source_user" name="source_user" value="<?= htmlspecialchars($webParams['source_user']) ?>" required>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="source_pass">Passwort</label>
                            <input type="password" id="source_pass" name="source_pass" value="<?= htmlspecialchars($webParams['source_pass']) ?>">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="source_db">Quell-Datenbankname</label>
                            <input type="text" id="source_db" name="source_db" value="<?= htmlspecialchars($webParams['source_db']) ?>" placeholder="z. B. 2729544db7" required>
                        </div>
                    </div>
                </div>

                <!-- Ziel-Server Box -->
                <div class="server-box" id="targetServerBox">
                    <div class="server-box-title">
                        <span>📤 Ziel-Server (Neues System)</span>
                        <label style="font-size: 11px; font-weight: normal; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                            <input type="checkbox" id="separate_target" name="separate_target" value="1" <?= $webParams['separate_target'] ? 'checked' : '' ?> onchange="toggleSeparateTarget()">
                            <span>Anderer Server / Zugangsdaten</span>
                        </label>
                    </div>

                    <div id="targetCredsWrap">
                        <div class="form-grid">
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label for="target_host">Ziel-MySQL Host</label>
                                <input type="text" id="target_host" name="target_host" value="<?= htmlspecialchars($webParams['target_host']) ?>" placeholder="z. B. mysqlsvr88.world4you.com">
                            </div>
                            <div class="form-group">
                                <label for="target_port">Port</label>
                                <input type="number" id="target_port" name="target_port" value="<?= (int)$webParams['target_port'] ?>">
                            </div>
                            <div class="form-group">
                                <label for="target_user">Benutzername</label>
                                <input type="text" id="target_user" name="target_user" value="<?= htmlspecialchars($webParams['target_user']) ?>">
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label for="target_pass">Passwort</label>
                                <input type="password" id="target_pass" name="target_pass" value="<?= htmlspecialchars($webParams['target_pass']) ?>">
                            </div>
                        </div>
                    </div>

                    <div id="sameServerNote" style="font-size: 12px; color: #718096; background: #edf2f7; padding: 8px 10px; border-radius: 6px; margin-bottom: 12px; display: none;">
                        ℹ️ Ziel verwendet dieselben Server-Zugangsdaten (Host, User, Passwort) wie die Quelle.
                    </div>

                    <div class="form-group">
                        <label for="target_db">Ziel-Datenbankname</label>
                        <input type="text" id="target_db" name="target_db" value="<?= htmlspecialchars($webParams['target_db']) ?>" placeholder="z. B. 5514037db13">
                    </div>
                </div>
            </div>

            <div style="margin-top: 14px;">
                <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                    <input type="checkbox" name="convert_innodb_utf8mb4" value="1" <?= $webParams['convert_innodb_utf8mb4'] ? 'checked' : '' ?>>
                    <span>Bestehende MyISAM-Tabellen auf <strong>InnoDB</strong> und Zeichensatz auf <strong>utf8mb4</strong> konvertieren</span>
                </label>
            </div>

            <div class="button-bar">
                <button type="submit" name="action" value="analyze" class="btn-analyze">
                    🔍 Verbindung & Schema analysieren
                </button>
                <button type="submit" name="action" value="migrate" class="btn-migrate" onclick="return confirm('Möchten Sie die Datenbank-Migration / das Update jetzt starten? Bitte stellen Sie sicher, dass ein Backup existiert.');">
                    🚀 Migration / Update jetzt ausführen
                </button>
            </div>
        </div>
    </form>

    <?php if ($analysisResult): ?>
        <div class="card">
            <?php if ($analysisResult['ok']): ?>
                <?php $rep = $analysisResult['report']; ?>
                <div class="card-title">📊 Analyse-Ergebnis</div>
                
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 13.5px;">
                    <div><strong>Quell-Server:</strong> <?= htmlspecialchars($rep['source_server']['host']) ?>:<?= (int)$rep['source_server']['port'] ?> (DB: <code><?= htmlspecialchars($rep['source_server']['db']) ?></code>) <span class="badge badge-green">Verbunden</span></div>
                    <?php if ($rep['mode'] === 'clone_and_upgrade'): ?>
                        <div style="margin-top: 6px;">
                            <strong>Ziel-Server:</strong> <?= htmlspecialchars($rep['target_server']['host']) ?>:<?= (int)$rep['target_server']['port'] ?> (DB: <code><?= htmlspecialchars($rep['target_server']['db']) ?></code>)
                            <span class="badge badge-green">Verbunden</span>
                            <?= $rep['target_server']['exists'] ? '<span class="badge badge-green">DB existiert bereits</span>' : '<span class="badge badge-orange">DB wird neu erstellt</span>' ?>
                            <?php if (!$rep['is_same_server']): ?>
                                <span class="badge badge-blue">Serverübergreifender Transfer aktiv</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <table class="status-table">
                    <thead>
                        <tr>
                            <th>Tabelle</th>
                            <th>Engine</th>
                            <th>Collation</th>
                            <th>Zeilen</th>
                            <th>Status / Fehlende Spalten</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rep['existing_tables'] as $tName => $tInfo): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($tName) ?></strong></td>
                                <td>
                                    <?= htmlspecialchars($tInfo['engine']) ?>
                                    <?php if ($tInfo['engine'] === 'MyISAM'): ?>
                                        <span class="badge badge-orange">wird InnoDB</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($tInfo['collation']) ?></td>
                                <td><?= number_format($tInfo['rows'], 0, ',', '.') ?></td>
                                <td>
                                    <?php if (!empty($tInfo['missing_target_columns'])): ?>
                                        <span class="badge badge-orange">Fehlende Spalten: <?= htmlspecialchars(implode(', ', $tInfo['missing_target_columns'])) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-green">Vollständig</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (!empty($rep['missing_tables'])): ?>
                    <div style="margin-top: 16px; font-size: 13px; color: #4a5568;">
                        <strong>Neue Tabellen, die erstellt werden (<?= count($rep['missing_tables']) ?>):</strong><br>
                        <span style="color: #2b6cb0;"><?= htmlspecialchars(implode(', ', $rep['missing_tables'])) ?></span>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="alert alert-error" style="margin: 0;">
                    ❌ <strong>Analyse fehlgeschlagen:</strong> <?= htmlspecialchars($analysisResult['error'] ?? '') ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<script>
function toggleSeparateTarget() {
    const isSeparate = document.getElementById('separate_target').checked;
    const credsWrap = document.getElementById('targetCredsWrap');
    const note = document.getElementById('sameServerNote');
    if (credsWrap && note) {
        credsWrap.style.display = isSeparate ? 'block' : 'none';
        note.style.display = isSeparate ? 'none' : 'block';
    }
}

function toggleMode() {
    const isClone = document.querySelector('input[name="mode"][value="clone_and_upgrade"]').checked;
    const targetBox = document.getElementById('targetServerBox');
    if (targetBox) {
        targetBox.style.opacity = isClone ? '1' : '0.4';
        const inputs = targetBox.querySelectorAll('input');
        inputs.forEach(inp => inp.disabled = !isClone);
    }
    toggleSeparateTarget();
}

toggleMode();
</script>

</body>
</html>
