<?php
/**
 * ============================================================================
 * Datenbank-Update- & Migrationsskript für Feuerwehr-Bestellsystem
 * ============================================================================
 * 
 * Unterstützt Alt-Installationen (MyISAM, latin1, altes Tabellenschema) und
 * migriert auf das vollständige, moderne, transaktionssichere Schema
 * (InnoDB, utf8mb4_unicode_ci, alle 34 Tabellen inkl. Buchungen, Kassenführung,
 * Kellner-Settlements, QR-Tokens, Rezepturen, Allergenen, WebAuthn etc.).
 * 
 * Unterstützt:
 * - Gleiche oder UNTERSCHIEDLICHE Server für Alt- und Neu-Datenbank
 * - Intelligente Schema-Reparatur fehlerhafter / veralteter Tabellen (z. B. buchungen)
 * - 100% Kompatibilität mit login.php und allen ff_*_ensure_schema-Funktionen
 * ============================================================================
 */

declare(strict_types=1);

// Maximale Ausführungszeit & Speicher für große Migrationen
@ini_set('max_execution_time', '600');
@ini_set('memory_limit', '512M');
if (function_exists('mysqli_report')) {
    @mysqli_report(MYSQLI_REPORT_OFF);
}

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
    if (function_exists('mysqli_report')) {
        @mysqli_report(MYSQLI_REPORT_OFF);
    }
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
// Vollständige Schema-Definitionen (Abgeglichen mit include/sql.sql & ff_*_schema)
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
            `can_direktverkauf` tinyint(1) NOT NULL DEFAULT 0,
            `menu_permissions` text DEFAULT NULL,
            `start_page` varchar(32) NOT NULL DEFAULT 'menu',
            `start_print_target` int(11) DEFAULT NULL,
            `dv_abholbon_print_target` int(11) DEFAULT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `active_from` datetime DEFAULT NULL,
            `active_until` datetime DEFAULT NULL,
            `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
            `auth_rev` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'user_passkeys' => "CREATE TABLE IF NOT EXISTS `user_passkeys` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `credential_id` varchar(1024) NOT NULL,
            `public_key_pem` text NOT NULL,
            `sign_count` bigint(20) unsigned NOT NULL DEFAULT 0,
            `label` varchar(120) NOT NULL DEFAULT '',
            `transports` varchar(255) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'webauthn_remote_requests' => "CREATE TABLE IF NOT EXISTS `webauthn_remote_requests` (
            `token` varchar(64) NOT NULL,
            `user_id` int(11) NOT NULL,
            `challenge` varchar(64) NOT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'pending',
            `label` varchar(120) NOT NULL DEFAULT '',
            `error` varchar(64) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` datetime NOT NULL,
            PRIMARY KEY (`token`),
            KEY `idx_user_id` (`user_id`)
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
            `auto_print_limit` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`print_target`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'ff_station_events' => "CREATE TABLE IF NOT EXISTS `ff_station_events` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `print_target` int(11) NOT NULL,
            `event_type` varchar(32) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_station_events_target_id` (`print_target`, `id`),
            KEY `idx_station_events_created` (`created_at`)
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
            KEY `idx_kassa_only` (`kassa_only`),
            KEY `idx_fest_id` (`fest_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'positionen' => "CREATE TABLE IF NOT EXISTS `positionen` (
            `rowid` int(11) NOT NULL AUTO_INCREMENT,
            `Positionsname` text NOT NULL,
            `Betrag` decimal(10,2) NOT NULL,
            `type` int(2) NOT NULL COMMENT '1=Speise, 2=Getränk',
            `Kurzbezeichnung` varchar(30) NOT NULL DEFAULT '',
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
            `kellnerZahlung` text NOT NULL,
            `Zusatzinfo` varchar(255) DEFAULT NULL,
            `betrag` double NOT NULL DEFAULT 0,
            `bestellt` tinyint(1) NOT NULL DEFAULT 0,
            `is_gratis` tinyint(1) NOT NULL DEFAULT 0,
            `bon_id` varchar(10) DEFAULT NULL,
            `order_nr` int(11) DEFAULT NULL,
            `settlement_id` int(11) DEFAULT NULL,
            `settled_at` datetime DEFAULT NULL,
            `settled_by` varchar(64) DEFAULT NULL,
            `auto_print` tinyint(1) NOT NULL DEFAULT 0,
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
            `name` varchar(100) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` varchar(255) DEFAULT NULL,
            `umsatz_zustaendig` varchar(255) DEFAULT NULL,
            `tables_text` text DEFAULT NULL,
            `total_amount` decimal(10,2) DEFAULT 0.00,
            `bezahlt` tinyint(1) NOT NULL DEFAULT 0,
            `bezahlt_at` timestamp NULL DEFAULT NULL,
            `fest_id` int(11) DEFAULT NULL,
            `tischnummer` int(11) DEFAULT NULL,
            `betrag` decimal(10,2) DEFAULT 0.00,
            PRIMARY KEY (`id`),
            KEY `idx_fest_id` (`fest_id`),
            KEY `idx_tischnummer` (`tischnummer`),
            KEY `idx_bezahlt` (`bezahlt`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'menu_locks' => "CREATE TABLE IF NOT EXISTS `menu_locks` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `scope` enum('position','type_all') NOT NULL DEFAULT 'position',
            `position_id` int(11) DEFAULT NULL,
            `menu_type` tinyint(2) DEFAULT NULL,
            `locked_until` datetime DEFAULT NULL,
            `reason` varchar(255) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `idx_scope_type` (`scope`,`menu_type`),
            KEY `idx_position` (`position_id`),
            KEY `idx_until` (`locked_until`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'menu_lock_exceptions' => "CREATE TABLE IF NOT EXISTS `menu_lock_exceptions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `lock_id` int(11) NOT NULL,
            `position_id` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `ux_lock_pos` (`lock_id`,`position_id`),
            KEY `idx_lock` (`lock_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'rechnungen' => "CREATE TABLE IF NOT EXISTS `rechnungen` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `rechnungsnummer` varchar(50) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
            `druck_status` varchar(10) NOT NULL DEFAULT 'pending',
            `druck_attempts` int(11) NOT NULL DEFAULT 0,
            `druck_last_error` varchar(255) DEFAULT NULL,
            `reserved_at` timestamp NULL DEFAULT NULL,
            `reserved_by` varchar(64) DEFAULT NULL,
            `order_nr` int(11) DEFAULT NULL,
            `is_proforma` tinyint(1) NOT NULL DEFAULT 0,
            `lines_json` mediumtext DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_rechnungsnummer` (`rechnungsnummer`),
            KEY `idx_fest_id` (`fest_id`),
            KEY `idx_sammelrechnung_id` (`sammelrechnung_id`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_order_nr` (`order_nr`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'buchungen' => "CREATE TABLE IF NOT EXISTS `buchungen` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `typ` enum('einnahme','ausgabe') NOT NULL,
            `bezeichnung` varchar(255) NOT NULL,
            `betrag` decimal(10,2) NOT NULL,
            `datum` date DEFAULT NULL,
            `kategorie` varchar(100) DEFAULT NULL,
            `notiz` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` varchar(64) DEFAULT NULL,
            `bereich_id` int(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_typ` (`typ`),
            KEY `idx_datum` (`datum`),
            KEY `idx_bereich_id` (`bereich_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'kassen_bereiche' => "CREATE TABLE IF NOT EXISTS `kassen_bereiche` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(128) NOT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `kontrolle_only` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'kassen_sessions' => "CREATE TABLE IF NOT EXISTS `kassen_sessions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `bereich_id` int(11) NOT NULL,
            `status` enum('open','closed') NOT NULL DEFAULT 'open',
            `opening_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
            `opened_at` datetime NOT NULL,
            `opened_by` varchar(64) NOT NULL,
            `closing_amount` decimal(10,2) DEFAULT NULL,
            `revenue_amount` decimal(10,2) DEFAULT NULL,
            `closed_at` datetime DEFAULT NULL,
            `closed_by` varchar(64) DEFAULT NULL,
            `reopened_at` datetime DEFAULT NULL,
            `reopened_by` varchar(64) DEFAULT NULL,
            `reopen_reason` varchar(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_bereich_status` (`bereich_id`,`status`),
            KEY `idx_opened_at` (`opened_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'kassen_bewegungen' => "CREATE TABLE IF NOT EXISTS `kassen_bewegungen` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `session_id` int(11) NOT NULL,
            `typ` enum('entnahme','zuzahlung') NOT NULL,
            `betrag` decimal(10,2) NOT NULL,
            `notiz` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` varchar(64) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_session` (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'kellner_settlements' => "CREATE TABLE IF NOT EXISTS `kellner_settlements` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `kellner_login` varchar(255) NOT NULL,
            `settlement_scope` varchar(20) NOT NULL DEFAULT 'kellner',
            `von_dt` datetime NOT NULL,
            `bis_dt` datetime NOT NULL,
            `umsatz_soll` decimal(10,2) NOT NULL DEFAULT 0.00,
            `betrag_abgegeben` decimal(10,2) NOT NULL DEFAULT 0.00,
            `wechselgeld_zurueck` decimal(10,2) NOT NULL DEFAULT 0.00,
            `trinkgeld` decimal(10,2) NOT NULL DEFAULT 0.00,
            `notiz` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` varchar(64) DEFAULT NULL,
            `voided_at` datetime DEFAULT NULL,
            `voided_by` varchar(64) DEFAULT NULL,
            `void_reason` varchar(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_kellner` (`kellner_login`),
            KEY `idx_von_bis` (`von_dt`,`bis_dt`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'kellner_bewegungen' => "CREATE TABLE IF NOT EXISTS `kellner_bewegungen` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `kellner_login` varchar(255) NOT NULL,
            `settlement_id` int(11) DEFAULT NULL,
            `typ` enum('entnahme','zuzahlung') NOT NULL,
            `betrag` decimal(10,2) NOT NULL,
            `notiz` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` varchar(64) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_kellner` (`kellner_login`),
            KEY `idx_settlement` (`settlement_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'mitarbeiter_bereiche' => "CREATE TABLE IF NOT EXISTS `mitarbeiter_bereiche` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(64) NOT NULL,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'mitarbeiter_verpflegung' => "CREATE TABLE IF NOT EXISTS `mitarbeiter_verpflegung` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'settings' => "CREATE TABLE IF NOT EXISTS `settings` (
            `k` varchar(64) NOT NULL,
            `v` text NOT NULL,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`k`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'printer_jobs' => "CREATE TABLE IF NOT EXISTS `printer_jobs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `printer` varchar(32) NOT NULL,
            `type` varchar(32) DEFAULT 'invoice',
            `payload` mediumtext NOT NULL,
            `meta` json DEFAULT NULL,
            `status` enum('pending','reserved','done','error') DEFAULT 'pending',
            `attempts` int(11) DEFAULT 0,
            `reserved_at` datetime DEFAULT NULL,
            `reserved_by` varchar(64) DEFAULT NULL,
            `error` text DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'bestandteile' => "CREATE TABLE IF NOT EXISTS `bestandteile` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'position_rezepturen' => "CREATE TABLE IF NOT EXISTS `position_rezepturen` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'allergene' => "CREATE TABLE IF NOT EXISTS `allergene` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_name` (`name`),
            KEY `idx_sort` (`sort_order`,`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'bestandteil_allergene' => "CREATE TABLE IF NOT EXISTS `bestandteil_allergene` (
            `bestandteil_id` int(11) NOT NULL,
            `allergen_id` int(11) NOT NULL,
            PRIMARY KEY (`bestandteil_id`,`allergen_id`),
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
        'users' => [
            'display_name' => "ADD COLUMN `display_name` varchar(120) DEFAULT NULL",
            'can_finance' => "ADD COLUMN `can_finance` tinyint(1) NOT NULL DEFAULT 0",
            'can_direktverkauf' => "ADD COLUMN `can_direktverkauf` tinyint(1) NOT NULL DEFAULT 0",
            'menu_permissions' => "ADD COLUMN `menu_permissions` text DEFAULT NULL",
            'start_page' => "ADD COLUMN `start_page` varchar(32) NOT NULL DEFAULT 'menu'",
            'start_print_target' => "ADD COLUMN `start_print_target` int(11) DEFAULT NULL",
            'dv_abholbon_print_target' => "ADD COLUMN `dv_abholbon_print_target` int(11) DEFAULT NULL",
            'is_active' => "ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1",
            'active_from' => "ADD COLUMN `active_from` datetime DEFAULT NULL",
            'active_until' => "ADD COLUMN `active_until` datetime DEFAULT NULL",
            'force_password_change' => "ADD COLUMN `force_password_change` tinyint(1) NOT NULL DEFAULT 0",
            'auth_rev' => "ADD COLUMN `auth_rev` int(11) NOT NULL DEFAULT 0",
        ],
        'tische' => [
            'fest_id' => "ADD COLUMN `fest_id` int(11) DEFAULT NULL",
            'userID' => "ADD COLUMN `userID` int(11) NOT NULL DEFAULT 0",
            'is_sammelrechnung' => "ADD COLUMN `is_sammelrechnung` tinyint(1) NOT NULL DEFAULT 0",
            'is_ehrengast' => "ADD COLUMN `is_ehrengast` tinyint(1) NOT NULL DEFAULT 0",
        ],
        'positionen' => [
            'print_target' => "ADD COLUMN `print_target` int(11) NOT NULL DEFAULT 11",
            'selbstkosten' => "ADD COLUMN `selbstkosten` decimal(10,2) NOT NULL DEFAULT 0.00",
            'subcategory_id' => "ADD COLUMN `subcategory_id` int(11) DEFAULT NULL",
            'tile_bg' => "ADD COLUMN `tile_bg` varchar(32) DEFAULT NULL",
            'kassa_only' => "ADD COLUMN `kassa_only` tinyint(1) NOT NULL DEFAULT 0",
            'fest_id' => "ADD COLUMN `fest_id` int(11) DEFAULT NULL",
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
            'auto_print' => "ADD COLUMN `auto_print` tinyint(1) NOT NULL DEFAULT 0",
        ],
        'print_targets' => [
            'finance_bereich_id' => "ADD COLUMN `finance_bereich_id` int(11) DEFAULT NULL",
            'auto_print_limit' => "ADD COLUMN `auto_print_limit` int(11) NOT NULL DEFAULT 0",
        ],
        'buchungen' => [
            'typ' => "ADD COLUMN `typ` enum('einnahme','ausgabe') NOT NULL DEFAULT 'ausgabe' AFTER `id`",
            'bezeichnung' => "ADD COLUMN `bezeichnung` varchar(255) NOT NULL DEFAULT '' AFTER `typ`",
            'betrag' => "ADD COLUMN `betrag` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `bezeichnung`",
            'datum' => "ADD COLUMN `datum` date DEFAULT NULL AFTER `betrag`",
            'kategorie' => "ADD COLUMN `kategorie` varchar(100) DEFAULT NULL AFTER `datum`",
            'notiz' => "ADD COLUMN `notiz` text DEFAULT NULL AFTER `kategorie`",
            'created_by' => "ADD COLUMN `created_by` varchar(64) DEFAULT NULL AFTER `created_at`",
            'bereich_id' => "ADD COLUMN `bereich_id` int(11) DEFAULT NULL AFTER `notiz`",
        ],
        'kassen_bereiche' => [
            'is_active' => "ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1 AFTER `name`",
            'kontrolle_only' => "ADD COLUMN `kontrolle_only` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_active`",
        ],
        'kellner_settlements' => [
            'kellner_login' => "ADD COLUMN `kellner_login` varchar(255) NOT NULL DEFAULT '' AFTER `id`",
            'settlement_scope' => "ADD COLUMN `settlement_scope` varchar(20) NOT NULL DEFAULT 'kellner'",
            'von_dt' => "ADD COLUMN `von_dt` datetime DEFAULT NULL",
            'bis_dt' => "ADD COLUMN `bis_dt` datetime DEFAULT NULL",
            'umsatz_soll' => "ADD COLUMN `umsatz_soll` decimal(10,2) NOT NULL DEFAULT 0.00",
            'betrag_abgegeben' => "ADD COLUMN `betrag_abgegeben` decimal(10,2) NOT NULL DEFAULT 0.00",
            'wechselgeld_zurueck' => "ADD COLUMN `wechselgeld_zurueck` decimal(10,2) NOT NULL DEFAULT 0.00",
            'trinkgeld' => "ADD COLUMN `trinkgeld` decimal(10,2) NOT NULL DEFAULT 0.00",
            'voided_at' => "ADD COLUMN `voided_at` datetime DEFAULT NULL",
            'voided_by' => "ADD COLUMN `voided_by` varchar(64) DEFAULT NULL",
            'void_reason' => "ADD COLUMN `void_reason` varchar(255) DEFAULT NULL",
        ],
        'kellner_bewegungen' => [
            'kellner_login' => "ADD COLUMN `kellner_login` varchar(255) NOT NULL DEFAULT '' AFTER `id`",
            'settlement_id' => "ADD COLUMN `settlement_id` int(11) DEFAULT NULL",
            'typ' => "ADD COLUMN `typ` enum('entnahme','zuzahlung') NOT NULL DEFAULT 'entnahme'",
            'betrag' => "ADD COLUMN `betrag` decimal(10,2) NOT NULL DEFAULT 0.00",
            'notiz' => "ADD COLUMN `notiz` varchar(255) DEFAULT NULL",
        ],
        'kassen_sessions' => [
            'reopened_at' => "ADD COLUMN `reopened_at` datetime DEFAULT NULL",
            'reopened_by' => "ADD COLUMN `reopened_by` varchar(64) DEFAULT NULL",
            'reopen_reason' => "ADD COLUMN `reopen_reason` varchar(255) DEFAULT NULL",
        ],
        'sammelrechnungen' => [
            'name' => "ADD COLUMN `name` varchar(100) DEFAULT NULL",
            'created_at' => "ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'created_by' => "ADD COLUMN `created_by` varchar(255) DEFAULT NULL",
            'umsatz_zustaendig' => "ADD COLUMN `umsatz_zustaendig` varchar(255) DEFAULT NULL",
            'tables_text' => "ADD COLUMN `tables_text` text DEFAULT NULL",
            'total_amount' => "ADD COLUMN `total_amount` decimal(10,2) DEFAULT 0.00",
            'bezahlt' => "ADD COLUMN `bezahlt` tinyint(1) NOT NULL DEFAULT 0",
            'bezahlt_at' => "ADD COLUMN `bezahlt_at` timestamp NULL DEFAULT NULL",
            'fest_id' => "ADD COLUMN `fest_id` int(11) DEFAULT NULL",
            'tischnummer' => "ADD COLUMN `tischnummer` int(11) DEFAULT NULL",
            'betrag' => "ADD COLUMN `betrag` decimal(10,2) DEFAULT 0.00",
        ],
        'rechnungen' => [
            'order_nr' => "ADD COLUMN `order_nr` int(11) DEFAULT NULL",
            'sammelrechnung_id' => "ADD COLUMN `sammelrechnung_id` int(11) DEFAULT NULL",
            'is_proforma' => "ADD COLUMN `is_proforma` tinyint(1) NOT NULL DEFAULT 0",
            'lines_json' => "ADD COLUMN `lines_json` mediumtext DEFAULT NULL",
            'druck_status' => "ADD COLUMN `druck_status` varchar(10) NOT NULL DEFAULT 'pending'",
            'druck_attempts' => "ADD COLUMN `druck_attempts` int(11) NOT NULL DEFAULT 0",
            'druck_last_error' => "ADD COLUMN `druck_last_error` varchar(255) DEFAULT NULL",
            'reserved_at' => "ADD COLUMN `reserved_at` timestamp NULL DEFAULT NULL",
            'reserved_by' => "ADD COLUMN `reserved_by` varchar(64) DEFAULT NULL",
        ],
        'bestandteile' => [
            'kapazitaet' => "ADD COLUMN `kapazitaet` int(11) NOT NULL DEFAULT -1 AFTER `einheit`",
            'aktiv' => "ADD COLUMN `aktiv` tinyint(1) NOT NULL DEFAULT 1 AFTER `kapazitaet`",
            'fest_id' => "ADD COLUMN `fest_id` int(11) DEFAULT NULL",
        ],
        'position_rezepturen' => [
            'id' => "ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST",
            'bestandteil_id' => "ADD COLUMN `bestandteil_id` int(11) NOT NULL DEFAULT 0 AFTER `position_id`",
            'menge' => "ADD COLUMN `menge` decimal(10,3) NOT NULL DEFAULT 1.000",
            'optional_enabled' => "ADD COLUMN `optional_enabled` tinyint(1) NOT NULL DEFAULT 0",
            'default_selected' => "ADD COLUMN `default_selected` tinyint(1) NOT NULL DEFAULT 1",
            'option_group' => "ADD COLUMN `option_group` varchar(64) NOT NULL DEFAULT ''",
            'reihenfolge' => "ADD COLUMN `reihenfolge` int(11) NOT NULL DEFAULT 0",
            'fest_id' => "ADD COLUMN `fest_id` int(11) DEFAULT NULL",
        ],
        'bestellung_rezeptur_komponenten' => [
            'optional_enabled' => "ADD COLUMN `optional_enabled` tinyint(1) NOT NULL DEFAULT 0",
            'default_selected' => "ADD COLUMN `default_selected` tinyint(1) NOT NULL DEFAULT 1",
            'selected' => "ADD COLUMN `selected` tinyint(1) NOT NULL DEFAULT 1",
        ],
        'mitarbeiter_verpflegung' => [
            'datum' => "ADD COLUMN `datum` date NOT NULL DEFAULT '1970-01-01' AFTER `id`",
            'bereich_id' => "ADD COLUMN `bereich_id` int(11) NOT NULL DEFAULT 0 AFTER `datum`",
            'position_id' => "ADD COLUMN `position_id` int(11) NOT NULL DEFAULT 0 AFTER `bereich_id`",
            'menge' => "ADD COLUMN `menge` int(11) NOT NULL DEFAULT 1 AFTER `position_id`",
            'notiz' => "ADD COLUMN `notiz` varchar(255) DEFAULT NULL AFTER `menge`",
            'created_at' => "ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'created_by' => "ADD COLUMN `created_by` varchar(64) DEFAULT NULL",
        ],
        'mitarbeiter_bereiche' => [
            'name' => "ADD COLUMN `name` varchar(64) NOT NULL DEFAULT '' AFTER `id`",
            'sort_order' => "ADD COLUMN `sort_order` int(11) NOT NULL DEFAULT 0 AFTER `name`",
        ]
    ];
}

// ----------------------------------------------------------------------------
// Spalten-Erweiterungen auf eine Verbindung anwenden
// ----------------------------------------------------------------------------
function ff_apply_schema_patches(mysqli $conn, array &$log): void {
    $columnPatches = ff_migration_get_column_patches();

    $tblRes = @mysqli_query($conn, "SHOW TABLES");
    if (!$tblRes) return;
    $existingTables = [];
    while ($r = mysqli_fetch_row($tblRes)) {
        $existingTables[strtolower($r[0])] = $r[0];
    }
    @mysqli_free_result($tblRes);

    foreach ($columnPatches as $tblName => $patches) {
        $realTblName = $existingTables[strtolower($tblName)] ?? null;
        if (!$realTblName) {
            continue;
        }

        $colsRes = @mysqli_query($conn, "SHOW COLUMNS FROM `{$realTblName}`");
        if (!$colsRes) {
            continue;
        }
        $existingCols = [];
        while ($c = mysqli_fetch_assoc($colsRes)) {
            $existingCols[strtolower($c['Field'])] = true;
        }
        @mysqli_free_result($colsRes);

        foreach ($patches as $colName => $alterSql) {
            if (!isset($existingCols[strtolower($colName)])) {
                $fullAlter = "ALTER TABLE `{$realTblName}` " . $alterSql;
                try {
                    if (@mysqli_query($conn, $fullAlter)) {
                        $log[] = ['type' => 'success', 'msg' => "Tabelle '{$realTblName}': Spalte '{$colName}' erfolgreich ergänzt."];
                        $existingCols[strtolower($colName)] = true;
                    } else {
                        $log[] = ['type' => 'warning', 'msg' => "Tabelle '{$realTblName}': Spalte '{$colName}' konnte nicht ergänzt werden: " . mysqli_error($conn)];
                    }
                } catch (\Throwable $e) {
                    $log[] = ['type' => 'warning', 'msg' => "Tabelle '{$realTblName}': Hinweis bei Spalte '{$colName}': " . $e->getMessage()];
                }
            }
        }
    }

    // Spezifische Spalten-Typ-Anpassungen
    if (isset($existingTables['tische'])) {
        try {
            @mysqli_query($conn, "ALTER TABLE `{$existingTables['tische']}` MODIFY `tischname` VARCHAR(50) NOT NULL");
        } catch (\Throwable $e) {
            // Ignorieren falls nicht möglich
        }
    }
}

// ----------------------------------------------------------------------------
// Intelligente Tabellen-Bereinigung & -Reparatur (z. B. fehlerhaftes buchungen, veraltetes users)
// ----------------------------------------------------------------------------
function ff_sanitize_and_repair_tables(mysqli $conn, array &$log): void {
    $targetDefs = ff_migration_get_target_tables();

    // 1. Spezieller Check für 'buchungen'
    $tblChk = @mysqli_query($conn, "SHOW TABLES LIKE 'buchungen'");
    if ($tblChk && mysqli_num_rows($tblChk) > 0) {
        $cNotiz = @mysqli_query($conn, "SHOW COLUMNS FROM `buchungen` LIKE 'notiz'");
        $cTyp   = @mysqli_query($conn, "SHOW COLUMNS FROM `buchungen` LIKE 'typ'");
        $hasNotiz = ($cNotiz && mysqli_num_rows($cNotiz) > 0);
        $hasTyp   = ($cTyp && mysqli_num_rows($cTyp) > 0);
        if ($cNotiz) @mysqli_free_result($cNotiz);
        if ($cTyp) @mysqli_free_result($cTyp);

        if (!$hasNotiz || !$hasTyp) {
            $cntRes = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM `buchungen`");
            $rCount = $cntRes ? (int)(mysqli_fetch_assoc($cntRes)['c'] ?? 0) : 0;
            if ($cntRes) @mysqli_free_result($cntRes);

            if ($rCount === 0) {
                @mysqli_query($conn, "DROP TABLE `buchungen`");
                if (@mysqli_query($conn, $targetDefs['buchungen'])) {
                    $log[] = ['type' => 'success', 'msg' => "Reparatur: Leere Tabelle 'buchungen' durch korrekte Struktur (mit 'notiz', 'typ', 'bereich_id') ersetzt."];
                }
            } else {
                // Bei vorhandenen Datensätzen fehlende Spalten nachrüsten
                if (!$hasTyp) {
                    @mysqli_query($conn, "ALTER TABLE `buchungen` ADD COLUMN `typ` enum('einnahme','ausgabe') NOT NULL DEFAULT 'ausgabe' AFTER `id`");
                }
                if (!$hasNotiz) {
                    @mysqli_query($conn, "ALTER TABLE `buchungen` ADD COLUMN `notiz` text DEFAULT NULL");
                }
                @mysqli_query($conn, "ALTER TABLE `buchungen` ADD COLUMN `bezeichnung` varchar(255) NOT NULL DEFAULT '' AFTER `typ`");
                @mysqli_query($conn, "ALTER TABLE `buchungen` ADD COLUMN `betrag` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `bezeichnung`");
                @mysqli_query($conn, "ALTER TABLE `buchungen` ADD COLUMN `datum` date DEFAULT NULL AFTER `betrag`");
                @mysqli_query($conn, "ALTER TABLE `buchungen` ADD COLUMN `kategorie` varchar(100) DEFAULT NULL AFTER `datum`");
                @mysqli_query($conn, "ALTER TABLE `buchungen` ADD COLUMN `bereich_id` int(11) DEFAULT NULL AFTER `notiz`");
                $log[] = ['type' => 'success', 'msg' => "Reparatur: Fehlende Spalten in Tabelle 'buchungen' erfolgreich ergänzt."];
            }
        }
    }
    if ($tblChk) @mysqli_free_result($tblChk);

    // 2. Prüfen von 'menu_locks' und 'menu_lock_exceptions'
    $mlChk = @mysqli_query($conn, "SHOW TABLES LIKE 'menu_locks'");
    if ($mlChk && mysqli_num_rows($mlChk) > 0) {
        $cScope = @mysqli_query($conn, "SHOW COLUMNS FROM `menu_locks` LIKE 'scope'");
        if ($cScope && mysqli_num_rows($cScope) === 0) {
            @mysqli_query($conn, "DROP TABLE `menu_locks`");
            @mysqli_query($conn, $targetDefs['menu_locks']);
            $log[] = ['type' => 'success', 'msg' => "Reparatur: Veraltete Tabelle 'menu_locks' auf moderne Struktur aktualisiert."];
        }
        if ($cScope) @mysqli_free_result($cScope);
    }
    if ($mlChk) @mysqli_free_result($mlChk);

    // 3. Prüfen von 'kassen_bereiche'
    $kbChk = @mysqli_query($conn, "SHOW TABLES LIKE 'kassen_bereiche'");
    if ($kbChk && mysqli_num_rows($kbChk) > 0) {
        $cActive = @mysqli_query($conn, "SHOW COLUMNS FROM `kassen_bereiche` LIKE 'is_active'");
        if ($cActive && mysqli_num_rows($cActive) === 0) {
            @mysqli_query($conn, "ALTER TABLE `kassen_bereiche` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1 AFTER `name`");
            @mysqli_query($conn, "ALTER TABLE `kassen_bereiche` ADD COLUMN `kontrolle_only` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_active`");
            $log[] = ['type' => 'success', 'msg' => "Reparatur: Spalten 'is_active' und 'kontrolle_only' an 'kassen_bereiche' ergänzt."];
        }
        if ($cActive) @mysqli_free_result($cActive);
    }
    if ($kbChk) @mysqli_free_result($kbChk);

    // 4. Prüfen von 'users' (Fehlende Berechtigungs- und Konfigurationsspalten)
    $uChk = @mysqli_query($conn, "SHOW TABLES LIKE 'users'");
    if ($uChk && mysqli_num_rows($uChk) > 0) {
        $uCols = [];
        $ucRes = @mysqli_query($conn, "SHOW COLUMNS FROM `users`");
        if ($ucRes) {
            while ($c = mysqli_fetch_assoc($ucRes)) {
                $uCols[strtolower($c['Field'])] = true;
            }
            @mysqli_free_result($ucRes);
        }
        $userPatches = [
            'display_name' => "ADD COLUMN `display_name` varchar(120) DEFAULT NULL",
            'can_finance' => "ADD COLUMN `can_finance` tinyint(1) NOT NULL DEFAULT 0",
            'can_direktverkauf' => "ADD COLUMN `can_direktverkauf` tinyint(1) NOT NULL DEFAULT 0",
            'menu_permissions' => "ADD COLUMN `menu_permissions` text DEFAULT NULL",
            'start_page' => "ADD COLUMN `start_page` varchar(32) NOT NULL DEFAULT 'menu'",
            'start_print_target' => "ADD COLUMN `start_print_target` int(11) DEFAULT NULL",
            'dv_abholbon_print_target' => "ADD COLUMN `dv_abholbon_print_target` int(11) DEFAULT NULL",
            'is_active' => "ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1",
            'active_from' => "ADD COLUMN `active_from` datetime DEFAULT NULL",
            'active_until' => "ADD COLUMN `active_until` datetime DEFAULT NULL",
            'force_password_change' => "ADD COLUMN `force_password_change` tinyint(1) NOT NULL DEFAULT 0",
            'auth_rev' => "ADD COLUMN `auth_rev` int(11) NOT NULL DEFAULT 0",
        ];
        foreach ($userPatches as $uCol => $uSql) {
            if (!isset($uCols[$uCol])) {
                try {
                    if (@mysqli_query($conn, "ALTER TABLE `users` " . $uSql)) {
                        $log[] = ['type' => 'success', 'msg' => "Reparatur: Spalte '{$uCol}' an 'users' ergänzt."];
                    }
                } catch (\Throwable $e) {
                    $log[] = ['type' => 'warning', 'msg' => "Tabelle 'users': Spalte '{$uCol}': " . $e->getMessage()];
                }
            }
        }
    }
    if ($uChk) @mysqli_free_result($uChk);

    // 5. Prüfen von 'kellner_settlements'
    $ksChk = @mysqli_query($conn, "SHOW TABLES LIKE 'kellner_settlements'");
    if ($ksChk && mysqli_num_rows($ksChk) > 0) {
        $cLogin = @mysqli_query($conn, "SHOW COLUMNS FROM `kellner_settlements` LIKE 'kellner_login'");
        $hasLogin = ($cLogin && mysqli_num_rows($cLogin) > 0);
        if ($cLogin) @mysqli_free_result($cLogin);

        if (!$hasLogin) {
            $cntRes = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM `kellner_settlements`");
            $rCount = $cntRes ? (int)(mysqli_fetch_assoc($cntRes)['c'] ?? 0) : 0;
            if ($cntRes) @mysqli_free_result($cntRes);

            if ($rCount === 0) {
                @mysqli_query($conn, "DROP TABLE `kellner_settlements`");
                if (@mysqli_query($conn, $targetDefs['kellner_settlements'])) {
                    $log[] = ['type' => 'success', 'msg' => "Reparatur: Leere Tabelle 'kellner_settlements' durch vollständige Struktur (inkl. 'kellner_login', 'settlement_scope') ersetzt."];
                }
            } else {
                $cK = @mysqli_query($conn, "SHOW COLUMNS FROM `kellner_settlements` LIKE 'kellner'");
                if ($cK && mysqli_num_rows($cK) > 0) {
                    @mysqli_query($conn, "ALTER TABLE `kellner_settlements` CHANGE COLUMN `kellner` `kellner_login` varchar(255) NOT NULL");
                    $log[] = ['type' => 'success', 'msg' => "Reparatur: Spalte 'kellner' in 'kellner_settlements' zu 'kellner_login' umbenannt."];
                } else {
                    @mysqli_query($conn, "ALTER TABLE `kellner_settlements` ADD COLUMN `kellner_login` varchar(255) NOT NULL DEFAULT '' AFTER `id`");
                    $log[] = ['type' => 'success', 'msg' => "Reparatur: Spalte 'kellner_login' an 'kellner_settlements' ergänzt."];
                }
                if ($cK) @mysqli_free_result($cK);
            }
        }
    }
    if ($ksChk) @mysqli_free_result($ksChk);

    // 6. Prüfen von 'kellner_bewegungen'
    $kbwChk = @mysqli_query($conn, "SHOW TABLES LIKE 'kellner_bewegungen'");
    if ($kbwChk && mysqli_num_rows($kbwChk) > 0) {
        $cLogin = @mysqli_query($conn, "SHOW COLUMNS FROM `kellner_bewegungen` LIKE 'kellner_login'");
        $hasLogin = ($cLogin && mysqli_num_rows($cLogin) > 0);
        if ($cLogin) @mysqli_free_result($cLogin);

        if (!$hasLogin) {
            $cntRes = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM `kellner_bewegungen`");
            $rCount = $cntRes ? (int)(mysqli_fetch_assoc($cntRes)['c'] ?? 0) : 0;
            if ($cntRes) @mysqli_free_result($cntRes);

            if ($rCount === 0) {
                @mysqli_query($conn, "DROP TABLE `kellner_bewegungen`");
                if (@mysqli_query($conn, $targetDefs['kellner_bewegungen'])) {
                    $log[] = ['type' => 'success', 'msg' => "Reparatur: Leere Tabelle 'kellner_bewegungen' durch vollständige Struktur ersetzt."];
                }
            } else {
                $cK = @mysqli_query($conn, "SHOW COLUMNS FROM `kellner_bewegungen` LIKE 'kellner'");
                if ($cK && mysqli_num_rows($cK) > 0) {
                    @mysqli_query($conn, "ALTER TABLE `kellner_bewegungen` CHANGE COLUMN `kellner` `kellner_login` varchar(255) NOT NULL");
                    $log[] = ['type' => 'success', 'msg' => "Reparatur: Spalte 'kellner' in 'kellner_bewegungen' zu 'kellner_login' umbenannt."];
                } else {
                    @mysqli_query($conn, "ALTER TABLE `kellner_bewegungen` ADD COLUMN `kellner_login` varchar(255) NOT NULL DEFAULT '' AFTER `id`");
                    $log[] = ['type' => 'success', 'msg' => "Reparatur: Spalte 'kellner_login' an 'kellner_bewegungen' ergänzt."];
                }
                if ($cK) @mysqli_free_result($cK);
            }
        }
    }
    if ($kbwChk) @mysqli_free_result($kbwChk);

    // 7. Prüfen von 'position_rezepturen'
    $przChk = @mysqli_query($conn, "SHOW TABLES LIKE 'position_rezepturen'");
    if ($przChk && mysqli_num_rows($przChk) > 0) {
        $cId = @mysqli_query($conn, "SHOW COLUMNS FROM `position_rezepturen` LIKE 'id'");
        $hasId = ($cId && mysqli_num_rows($cId) > 0);
        if ($cId) @mysqli_free_result($cId);

        if (!$hasId) {
            $cntRes = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM `position_rezepturen`");
            $rCount = $cntRes ? (int)(mysqli_fetch_assoc($cntRes)['c'] ?? 0) : 0;
            if ($cntRes) @mysqli_free_result($cntRes);

            if ($rCount === 0) {
                @mysqli_query($conn, "DROP TABLE `position_rezepturen`");
                if (@mysqli_query($conn, $targetDefs['position_rezepturen'])) {
                    $log[] = ['type' => 'success', 'msg' => "Reparatur: Leere Tabelle 'position_rezepturen' durch vollständige Struktur (inkl. 'id' als Primärschlüssel) ersetzt."];
                }
            } else {
                $cRowid = @mysqli_query($conn, "SHOW COLUMNS FROM `position_rezepturen` LIKE 'rowid'");
                if ($cRowid && mysqli_num_rows($cRowid) > 0) {
                    @mysqli_query($conn, "ALTER TABLE `position_rezepturen` CHANGE COLUMN `rowid` `id` int(11) NOT NULL AUTO_INCREMENT");
                    $log[] = ['type' => 'success', 'msg' => "Reparatur: Spalte 'rowid' in 'position_rezepturen' zu 'id' umbenannt."];
                } else {
                    $pkRes = @mysqli_query($conn, "SHOW INDEX FROM `position_rezepturen` WHERE Key_name = 'PRIMARY'");
                    $hasPk = ($pkRes && mysqli_num_rows($pkRes) > 0);
                    if ($pkRes) @mysqli_free_result($pkRes);

                    if ($hasPk) {
                        @mysqli_query($conn, "ALTER TABLE `position_rezepturen` DROP PRIMARY KEY, ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                    } else {
                        @mysqli_query($conn, "ALTER TABLE `position_rezepturen` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                    }
                    $log[] = ['type' => 'success', 'msg' => "Reparatur: Fehlende Spalte 'id' (Auto-Increment Primärschlüssel) an 'position_rezepturen' ergänzt."];
                }
                if ($cRowid) @mysqli_free_result($cRowid);
            }
        }
    }
    if ($przChk) @mysqli_free_result($przChk);

    // 8. Prüfen von 'mitarbeiter_verpflegung'
    $mvChk = @mysqli_query($conn, "SHOW TABLES LIKE 'mitarbeiter_verpflegung'");
    if ($mvChk && mysqli_num_rows($mvChk) > 0) {
        $cPosId = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'position_id'");
        $hasPosId = ($cPosId && mysqli_num_rows($cPosId) > 0);
        if ($cPosId) @mysqli_free_result($cPosId);

        if (!$hasPosId) {
            $cPos = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'position'");
            if ($cPos && mysqli_num_rows($cPos) > 0) {
                @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` CHANGE COLUMN `position` `position_id` int(11) NOT NULL");
                $log[] = ['type' => 'success', 'msg' => "Reparatur: Spalte 'position' in 'mitarbeiter_verpflegung' zu 'position_id' umbenannt."];
            } else {
                @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` ADD COLUMN `position_id` int(11) NOT NULL DEFAULT 0 AFTER `bereich_id`");
                $log[] = ['type' => 'success', 'msg' => "Reparatur: Spalte 'position_id' an 'mitarbeiter_verpflegung' ergänzt."];
            }
            if ($cPos) @mysqli_free_result($cPos);
        }

        $cMenge = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'menge'");
        $hasMenge = ($cMenge && mysqli_num_rows($cMenge) > 0);
        if ($cMenge) @mysqli_free_result($cMenge);

        if (!$hasMenge) {
            $cAnzahl = @mysqli_query($conn, "SHOW COLUMNS FROM `mitarbeiter_verpflegung` LIKE 'anzahl'");
            if ($cAnzahl && mysqli_num_rows($cAnzahl) > 0) {
                @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` CHANGE COLUMN `anzahl` `menge` int(11) NOT NULL DEFAULT 1");
                $log[] = ['type' => 'success', 'msg' => "Reparatur: Spalte 'anzahl' in 'mitarbeiter_verpflegung' zu 'menge' umbenannt."];
            } else {
                @mysqli_query($conn, "ALTER TABLE `mitarbeiter_verpflegung` ADD COLUMN `menge` int(11) NOT NULL DEFAULT 1 AFTER `position_id`");
                $log[] = ['type' => 'success', 'msg' => "Reparatur: Spalte 'menge' an 'mitarbeiter_verpflegung' ergänzt."];
            }
            if ($cAnzahl) @mysqli_free_result($cAnzahl);
        }
    }
    if ($mvChk) @mysqli_free_result($mvChk);

    // 9. Übergreifende Spaltenpatches für alle Tabellen anwenden
    ff_apply_schema_patches($conn, $log);
}

// ----------------------------------------------------------------------------
// Standard-Seeds für neue / migrierte Datenbanken
// ----------------------------------------------------------------------------
function ff_migration_run_seeds(mysqli $conn, array &$log): void {
    // 1. type (1=Speise, 2=Getränk)
    @mysqli_query($conn, "INSERT IGNORE INTO `type` (`rowid`, `name`) VALUES (1, 'Speise'), (2, 'Getränk')");
    $log[] = ['type' => 'success', 'msg' => 'Stammdaten: Typen (Speise / Getränk) sichergestellt.'];

    // 2. Druckziele (11=Küche, 12=Schank, 13=Feuerflecken)
    @mysqli_query($conn, "INSERT IGNORE INTO `print_targets` (`print_target`, `name`, `active`, `sort_order`, `auto_print_limit`) VALUES
        (11, 'Küche', 1, 10, 0),
        (12, 'Schank', 1, 20, 0),
        (13, 'Feuerflecken', 1, 30, 0)");
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
    if ($userCountRes) @mysqli_free_result($userCountRes);

    if ($userCount === 0) {
        $pwHash = password_hash('admin123', PASSWORD_DEFAULT);
        $uCols = [];
        $ucRes = @mysqli_query($conn, "SHOW COLUMNS FROM `users`");
        if ($ucRes) {
            while ($c = mysqli_fetch_assoc($ucRes)) {
                $uCols[strtolower($c['Field'])] = true;
            }
            @mysqli_free_result($ucRes);
        }
        $fields = ['`username`', '`password`', '`admin`'];
        $values = ["'admin'", "'{$pwHash}'", "2"];
        if (isset($uCols['can_finance'])) { $fields[] = '`can_finance`'; $values[] = '1'; }
        if (isset($uCols['can_direktverkauf'])) { $fields[] = '`can_direktverkauf`'; $values[] = '1'; }
        if (isset($uCols['start_page'])) { $fields[] = '`start_page`'; $values[] = "'menu'"; }

        try {
            @mysqli_query($conn, "INSERT INTO `users` (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")");
            $log[] = ['type' => 'warning', 'msg' => 'Sicherheit: Kein Benutzer vorhanden -> Standard-Admin angelegt (Benutzer: admin / Kennwort: admin123). Bitte sofort ändern!'];
        } catch (\Throwable $e) {
            $log[] = ['type' => 'warning', 'msg' => 'Hinweis bei Standard-Admin: ' . $e->getMessage()];
        }
    }

    // 8. Positionen print_target Defaults aktualisieren falls 0 oder ungesetzt
    try {
        $pCols = [];
        $pcRes = @mysqli_query($conn, "SHOW COLUMNS FROM `positionen`");
        if ($pcRes) {
            while ($c = mysqli_fetch_assoc($pcRes)) {
                $pCols[strtolower($c['Field'])] = true;
            }
            @mysqli_free_result($pcRes);
        }
        if (isset($pCols['print_target']) && isset($pCols['type'])) {
            @mysqli_query($conn, "UPDATE `positionen` SET `print_target` = 12 WHERE (`print_target` IS NULL OR `print_target` = 0) AND `type` = 2");
            @mysqli_query($conn, "UPDATE `positionen` SET `print_target` = 11 WHERE (`print_target` IS NULL OR `print_target` = 0) AND `type` = 1");
        }
    } catch (\Throwable $e) {
        // Ignorieren falls nicht möglich
    }

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

    // 10. Interne Schema-Funktionen aufrufen (idempotent & schützt vor künftigen Login-Fehlern)
    $helperFiles = [
        __DIR__ . '/include/ff_finance_schema.php' => 'ff_finance_ensure_schema',
        __DIR__ . '/include/ff_user_status.php' => 'ff_users_ensure_status_columns',
        __DIR__ . '/include/ff_user_permissions.php' => 'ff_users_ensure_menu_permissions_column',
        __DIR__ . '/include/ff_direktverkauf_auth.php' => 'ff_users_ensure_direktverkauf_column',
        __DIR__ . '/include/ff_rezepturen_schema.php' => 'ff_rezepturen_ensure_schema',
        __DIR__ . '/include/ff_webauthn.php' => 'ff_webauthn_ensure_schema',
        __DIR__ . '/include/ff_station_events.php' => 'ff_station_events_ensure_schema',
        __DIR__ . '/include/ff_auto_print.php' => 'ff_auto_print_ensure_schema',
        __DIR__ . '/include/menu_tile_helpers.php' => 'ff_menu_ensure_schema',
    ];
    foreach ($helperFiles as $file => $fn) {
        if (file_exists($file)) {
            try {
                require_once $file;
                if (function_exists($fn)) {
                    $fn($conn);
                }
            } catch (\Throwable $e) {
                // Bei Fehlern protokollieren
                $log[] = ['type' => 'warning', 'msg' => "Schema-Helper {$fn}(): " . $e->getMessage()];
            }
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

        // Vorab: Reparatur eventuell bereits unvollständig angelegter Tabellen im Ziel
        ff_sanitize_and_repair_tables($tgtConn, $log);

        // Alle modernen Tabellen im Ziel bereitstellen
        foreach ($targetTablesDef as $tblName => $createSql) {
            try {
                if (!@mysqli_query($tgtConn, $createSql)) {
                    $log[] = ['type' => 'error', 'msg' => "Fehler beim Anlegen von '{$tblName}' im Ziel: " . mysqli_error($tgtConn)];
                } else {
                    $log[] = ['type' => 'info', 'msg' => "Tabelle '{$tblName}' in Ziel-DB bereitgestellt."];
                }
            } catch (\Throwable $e) {
                $log[] = ['type' => 'warning', 'msg' => "Tabelle '{$tblName}' im Ziel: " . $e->getMessage()];
            }
        }

        // Ziel-Tabellen um alle neuen Spalten erweitern (falls Tabelle im Ziel bereits mit älterer Struktur existierte)
        ff_apply_schema_patches($tgtConn, $log);

        // Daten aus Quell-DB kopieren für jede übereinstimmende Tabelle
        foreach ($srcTables as $tblName) {
            if (!isset($targetTablesDef[$tblName])) {
                $cRes = mysqli_query($srcConn, "SHOW CREATE TABLE `{$srcDb}`.`{$tblName}`");
                if ($cRes && ($ctRow = mysqli_fetch_row($cRes))) {
                    $createLikeSql = preg_replace('/^CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', $ctRow[1]);
                    @mysqli_query($tgtConn, $createLikeSql);
                    $log[] = ['type' => 'info', 'msg' => "Legacy-Tabelle '{$tblName}' Struktur auf Ziel-Server übertragen."];
                }
            }

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

            $countRes = mysqli_query($srcConn, "SELECT COUNT(*) AS c FROM `{$srcDb}`.`{$tblName}`");
            $rowCount = $countRes ? (int)(mysqli_fetch_assoc($countRes)['c'] ?? 0) : 0;

            if ($rowCount === 0) {
                $log[] = ['type' => 'info', 'msg' => "Tabelle '{$tblName}': 0 Datensätze in Quelle."];
                continue;
            }

            mysqli_query($tgtConn, "TRUNCATE TABLE `{$tgtDb}`.`{$tblName}`");

            $tgtStr = implode(', ', $tgtColsList);
            $srcStr = implode(', ', $srcColsList);

            $copiedDirectly = false;
            if ($isSameServer && strcasecmp($srcDb, $tgtDb) !== 0) {
                $copySql = "INSERT INTO `{$tgtDb}`.`{$tblName}` ({$tgtStr}) SELECT {$srcStr} FROM `{$srcDb}`.`{$tblName}`";
                if (@mysqli_query($srcConn, $copySql)) {
                    $log[] = ['type' => 'success', 'msg' => "Tabelle '{$tblName}': {$rowCount} Datensätze via High-Speed-Query kopiert."];
                    $copiedDirectly = true;
                }
            }

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

        // Schema-Sanitisierung & Spalten-Patches auf Ziel anwenden
        ff_sanitize_and_repair_tables($tgtConn, $log);

        // Nach dem Kopieren: Spalten-Defaults & Berechtigungen sicher setzen
        try {
            $pCols = [];
            $pcRes = @mysqli_query($tgtConn, "SHOW COLUMNS FROM `positionen`");
            if ($pcRes) {
                while ($c = mysqli_fetch_assoc($pcRes)) {
                    $pCols[strtolower($c['Field'])] = true;
                }
                @mysqli_free_result($pcRes);
            }
            if (isset($pCols['print_target']) && isset($pCols['type'])) {
                @mysqli_query($tgtConn, "UPDATE `positionen` SET `print_target` = 12 WHERE (`print_target` IS NULL OR `print_target` = 0) AND `type` = 2");
                @mysqli_query($tgtConn, "UPDATE `positionen` SET `print_target` = 11 WHERE (`print_target` IS NULL OR `print_target` = 0) AND `type` = 1");
            }

            $bCols = [];
            $bcRes = @mysqli_query($tgtConn, "SHOW COLUMNS FROM `bestellungen`");
            if ($bcRes) {
                while ($c = mysqli_fetch_assoc($bcRes)) {
                    $bCols[strtolower($c['Field'])] = true;
                }
                @mysqli_free_result($bcRes);
            }
            if (isset($bCols['print_target']) && isset($pCols['print_target'])) {
                @mysqli_query($tgtConn, "UPDATE `bestellungen` b JOIN `positionen` p ON p.rowid = b.position SET b.print_target = p.print_target WHERE b.print_target IS NULL");
            }

            $uCols = [];
            $ucRes = @mysqli_query($tgtConn, "SHOW COLUMNS FROM `users`");
            if ($ucRes) {
                while ($c = mysqli_fetch_assoc($ucRes)) {
                    $uCols[strtolower($c['Field'])] = true;
                }
                @mysqli_free_result($ucRes);
            }
            if (isset($uCols['start_page'])) {
                @mysqli_query($tgtConn, "UPDATE `users` SET `start_page` = 'menu' WHERE `start_page` IS NULL OR `start_page` = ''");
            }
            $userSets = [];
            if (isset($uCols['can_finance'])) $userSets[] = "`can_finance` = 1";
            if (isset($uCols['can_direktverkauf'])) $userSets[] = "`can_direktverkauf` = 1";
            if (!empty($userSets) && isset($uCols['admin'])) {
                @mysqli_query($tgtConn, "UPDATE `users` SET " . implode(', ', $userSets) . " WHERE `admin` > 0");
            }
        } catch (\Throwable $e) {
            $log[] = ['type' => 'warning', 'msg' => 'Hinweis bei Standardwert-Zuweisung im Ziel: ' . $e->getMessage()];
        }

        // Seeds ausführen
        ff_migration_run_seeds($tgtConn, $log);

        @mysqli_query($tgtConn, "SET FOREIGN_KEY_CHECKS = 1;");
    }
    // =========================================================================
    // MODUS B: IN-PLACE UPGRADE (Bestehende DB direkt auf Quell-Server)
    // =========================================================================
    else {
        // Tabellen-Sanitisierung (z. B. leere fehlerhafte buchungen Tabelle reparieren)
        ff_sanitize_and_repair_tables($srcConn, $log);

        // 1. Alle neuen Tabellen erstellen falls noch nicht vorhanden
        foreach ($targetTablesDef as $tblName => $createSql) {
            if (!in_array($tblName, $srcTables, true)) {
                try {
                    if (@mysqli_query($srcConn, $createSql)) {
                        $log[] = ['type' => 'success', 'msg' => "Neue Tabelle '{$tblName}' erfolgreich angelegt."];
                    } else {
                        $log[] = ['type' => 'error', 'msg' => "Fehler beim Anlegen von '{$tblName}': " . mysqli_error($srcConn)];
                    }
                } catch (\Throwable $e) {
                    $log[] = ['type' => 'warning', 'msg' => "Tabelle '{$tblName}': " . $e->getMessage()];
                }
            }
        }

        // 2. Bestehende Tabellen auf InnoDB und utf8mb4 konvertieren
        if ($convertEngine) {
            foreach ($srcTables as $tblName) {
                $alterTableSql = "ALTER TABLE `{$tblName}` ENGINE=InnoDB CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                if (@mysqli_query($srcConn, $alterTableSql)) {
                    $log[] = ['type' => 'success', 'msg' => "Tabelle '{$tblName}' auf InnoDB & utf8mb4 konvertiert."];
                } else {
                    $log[] = ['type' => 'warning', 'msg' => "Tabelle '{$tblName}': Konvertierung InnoDB/utf8mb4 mit Hinweis: " . mysqli_error($srcConn)];
                }
            }
        }

        // 3. Spezifische Spalten-Patches für bestehende Tabellen anwenden
        ff_apply_schema_patches($srcConn, $log);

        // 4. Defaults für User & Positionen setzen
        try {
            $uCols = [];
            $ucRes = @mysqli_query($srcConn, "SHOW COLUMNS FROM `users`");
            if ($ucRes) {
                while ($c = mysqli_fetch_assoc($ucRes)) {
                    $uCols[strtolower($c['Field'])] = true;
                }
                @mysqli_free_result($ucRes);
            }
            if (isset($uCols['start_page'])) {
                @mysqli_query($srcConn, "UPDATE `users` SET `start_page` = 'menu' WHERE `start_page` IS NULL OR `start_page` = ''");
            }
            $userSets = [];
            if (isset($uCols['can_finance'])) $userSets[] = "`can_finance` = 1";
            if (isset($uCols['can_direktverkauf'])) $userSets[] = "`can_direktverkauf` = 1";
            if (!empty($userSets) && isset($uCols['admin'])) {
                @mysqli_query($srcConn, "UPDATE `users` SET " . implode(', ', $userSets) . " WHERE `admin` > 0");
            }
        } catch (\Throwable $e) {
            $log[] = ['type' => 'warning', 'msg' => 'Hinweis bei Standardwert-Zuweisung: ' . $e->getMessage()];
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
    try {
        $analysisResult = ff_analyze_databases($webParams);
    } catch (\Throwable $e) {
        $analysisResult = ['ok' => false, 'error' => 'Analyse-Fehler: ' . $e->getMessage()];
    }
} elseif ($action === 'migrate') {
    try {
        $migrationResult = ff_execute_migration($webParams);
    } catch (\Throwable $e) {
        $migrationResult = [
            'ok' => false,
            'error' => 'Migrations-Ausnahme: ' . $e->getMessage(),
            'log' => [
                ['type' => 'error', 'msg' => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')']
            ]
        ];
    }
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
                        <span>Konvertiert Tabellen vor Ort auf InnoDB/utf8mb4, repariert und ergänzt fehlende Spalten (z. B. in buchungen) und legt neue Tabellen an, ohne eine zweite Datenbank zu benötigen.</span>
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
