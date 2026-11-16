<?php
/**
 * Merker „Datenbank-Schema ist aktuell“.
 *
 * Alle Schema-Prüfungen (CREATE TABLE / SHOW COLUMNS / ALTER) laufen gebündelt im Migrationslauf
 * (include/ff_schema_migrate.php). Danach steht in settings.schema_fingerprint der Fingerabdruck
 * der Schema-Dateien. Passt er zum aktuellen Code, beenden sich alle ensure-Funktionen sofort
 * ohne DB-Abfrage. Passt er nicht (Deploy mit Schema-Änderung, älteres Backup zurückgespielt,
 * Schema-Cache geleert), prüfen sie wie früher selbst – bis ein Admin-Aufruf den Lauf startet.
 *
 * Neue ensure-Funktion: in FF_SCHEMA_GATE_FILES (falls neue Datei) und in
 * ff_schema_migrate_steps() eintragen; der Selbsttest „Schema-Migration“ prüft das.
 */
declare(strict_types=1);

if (defined('FF_SCHEMA_GATE_LOADED')) {
    return;
}
define('FF_SCHEMA_GATE_LOADED', true);

/** Von Hand erhöhen, wenn sich am Migrationslauf etwas ändert, das keine Datei unten betrifft. */
const FF_SCHEMA_GATE_VERSION = 1;

/** Dateien mit Schema-Code (relativ zu include/). Ihre Änderungszeit geht in den Fingerabdruck ein. */
const FF_SCHEMA_GATE_FILES = [
    'ff_schema_gate.php',
    'ff_schema_migrate.php',
    'ff_schema_helpers.php',
    'ff_schema_login.php',
    'settings.php',
    'ff_auto_print.php',
    'ff_csrf.php',
    'ff_direktverkauf_auth.php',
    'ff_fest_scope.php',
    'ff_finance_bereich_helpers.php',
    'ff_finance_schema.php',
    'ff_login_throttle.php',
    'ff_pilot_linked_articles.php',
    'ff_pilot_orders.php',
    'ff_position_kassa_helpers.php',
    'ff_printer_devices.php',
    'ff_rechnungen_ensure_columns.php',
    'ff_rechnung_items.php',
    'ff_rechnung_seq.php',
    'ff_rezepturen_schema.php',
    'ff_sammelrechnung.php',
    'ff_schreibaus.php',
    'ff_socket_printer.php',
    'ff_station_events.php',
    'ff_table_display.php',
    'ff_user_permissions.php',
    'ff_user_status.php',
    'ff_webauthn.php',
    'menu_lock_helpers.php',
    'menu_tile_helpers.php',
    'user_landing.php',
    'ff_event_notifications.php',
];

/** Fingerabdruck des aktuellen Codes (Version + Änderungszeiten der Schema-Dateien). */
function ff_schema_fingerprint(): string
{
    static $fp = null;
    if ($fp === null) {
        $parts = ['v' . FF_SCHEMA_GATE_VERSION];
        foreach (FF_SCHEMA_GATE_FILES as $f) {
            $parts[] = $f . ':' . (int) @filemtime(__DIR__ . '/' . $f);
        }
        $fp = substr(sha1(implode('|', $parts)), 0, 16);
    }
    return $fp;
}

/** Läuft gerade der Migrationslauf? Dann müssen alle ensure-Funktionen wirklich prüfen. */
function ff_schema_migrating(?bool $set = null): bool
{
    static $on = false;
    if ($set !== null) {
        $on = $set;
    }
    return $on;
}

/**
 * true = Schema laut Merker aktuell, ensure-Funktionen dürfen sofort zurückkehren.
 * Kostet keine eigene Abfrage: Die Einstellungen werden pro Request ohnehin einmal geladen.
 * $reset = true verwirft das Ergebnis dieses Requests (nach Migration / Cache leeren).
 */
function ff_schema_is_current(?mysqli $conn = null, bool $reset = false): bool
{
    static $current = null;
    static $checking = false;
    if ($reset) {
        $current = null;
        return false;
    }
    if (ff_schema_migrating()) {
        return false;
    }
    if ($current !== null) {
        return $current;
    }
    // Rückruf aus dem Laden der Einstellungen (settings_ensure_table) → dort normal prüfen
    if ($checking) {
        return false;
    }
    $conn = $conn ?? (($GLOBALS['conn'] ?? null) instanceof mysqli ? $GLOBALS['conn'] : null);
    if ($conn === null) {
        return false;
    }
    $checking = true;
    try {
        require_once __DIR__ . '/settings.php';
        $stored = (string) setting_get($conn, 'schema_fingerprint', '');
        $current = $stored !== '' && hash_equals($stored, ff_schema_fingerprint());
    } catch (Throwable $e) {
        $current = false;
    } finally {
        $checking = false;
    }
    return $current;
}
