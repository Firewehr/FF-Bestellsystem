<?php
/**
 * Migrationslauf: alle Schema-Prüfungen (ensure-Funktionen) gebündelt, danach Merker setzen.
 *
 * Läuft automatisch beim ersten Admin-Aufruf, wenn der Merker nicht zum Code passt
 * (ff_schema_migrate_if_needed), oder per Knopf in der Pre-Flight-Checkliste. Kellner, Stationen
 * und Druck-Clients starten ihn nie – so fällt kein ALTER TABLE in die Stoßzeit.
 *
 * Muss außerhalb einer Transaktion laufen (CREATE/ALTER schreiben offene Transaktionen fest).
 */
declare(strict_types=1);

require_once __DIR__ . '/ff_schema_gate.php';

/** Automatischer Lauf nach Fehlschlag frühestens nach so vielen Sekunden erneut. */
const FF_SCHEMA_MIGRATE_RETRY_SEC = 600;

/**
 * Alle ensure-Funktionen in Ausführungsreihenfolge: [Datei relativ zu include/, Funktion].
 * Der Selbsttest „Schema-Migration“ meldet ensure-Funktionen, die hier fehlen.
 *
 * @return list<array{0:string,1:string}>
 */
function ff_schema_migrate_steps(): array
{
    return [
        ['settings.php', 'settings_ensure_table'],
        ['ff_schema_helpers.php', 'ff_schema_ensure_hot_paths'],
        ['ff_user_status.php', 'ff_users_ensure_status_columns'],
        ['ff_user_status.php', 'ff_users_ensure_auth_rev_column'],
        ['user_landing.php', 'ff_users_ensure_landing_columns'],
        ['ff_direktverkauf_auth.php', 'ff_users_ensure_direktverkauf_column'],
        ['ff_user_permissions.php', 'ff_users_ensure_menu_permissions_column'],
        ['ff_login_throttle.php', 'ff_login_throttle_ensure_schema'],
        ['ff_csrf.php', 'ff_csrf_log_ensure_schema'],
        ['ff_webauthn.php', 'ff_webauthn_ensure_schema'],
        ['ff_webauthn.php', 'ff_webauthn_remote_ensure_schema'],
        ['ff_fest_scope.php', 'ff_fest_scope_ensure_columns'],
        ['ff_rechnung_seq.php', 'ff_feste_ensure_rechnung_prefix_column'],
        ['menu_tile_helpers.php', 'ff_menu_ensure_schema'],
        ['ff_position_kassa_helpers.php', 'ff_position_ensure_kassa_only_column'],
        ['ff_position_kassa_helpers.php', 'ff_subcategory_ensure_kassa_only_column'],
        ['ff_position_kassa_helpers.php', 'ff_position_kassa_ensure_schema'],
        ['menu_lock_helpers.php', 'menu_lock_ensure_tables'],
        ['ff_rezepturen_schema.php', 'ff_mitarbeiter_verpflegung_ensure_schema'],
        ['ff_rezepturen_schema.php', 'ff_rezepturen_ensure_schema'],
        ['ff_pilot_linked_articles.php', 'ff_pilot_linked_articles_ensure_schema'],
        ['ff_pilot_orders.php', 'ff_pilot_orders_ensure_schema'],
        ['ff_auto_print.php', 'ff_auto_print_ensure_schema'],
        ['ff_station_events.php', 'ff_station_events_ensure_schema'],
        ['ff_socket_printer.php', 'ff_socket_printer_ensure_schema'],
        ['ff_printer_devices.php', 'ff_printer_devices_ensure_schema'],
        ['ff_rechnung_items.php', 'ff_printer_jobs_ensure_table'],
        ['ff_rechnungen_ensure_columns.php', 'ff_bestellungen_ensure_rechnung_id_column'],
        ['ff_rechnungen_ensure_columns.php', 'ff_bestellungen_ensure_is_gratis_column'],
        ['ff_rechnungen_ensure_columns.php', 'ff_rechnungen_ensure_extra_columns'],
        ['ff_rechnungen_ensure_columns.php', 'ff_rechnungen_ensure_unique_rechnungsnummer'],
        ['ff_rechnungen_ensure_columns.php', 'ff_rechnungen_ensure_print_columns'],
        ['ff_schreibaus.php', 'ff_schreibaus_ensure_column'],
        ['ff_sammelrechnung.php', 'ff_sammelrechnung_ensure_schema'],
        ['ff_finance_schema.php', 'ff_finance_ensure_schema'],
        ['ff_finance_schema.php', 'ff_finance_ensure_undo_columns'],
        ['ff_finance_schema.php', 'ff_finance_ensure_settlement_scope_column'],
        ['ff_finance_schema.php', 'ff_finance_ensure_kontrolle_only_column'],
        ['ff_event_notifications.php', 'ff_event_notifications_bootstrap'],
        // Datenzeilen (keine Schema-Änderung): laufen auch im Betrieb weiter selbst
        ['ff_table_display.php', 'ff_ensure_direktverkauf_tisch_row'],
        ['ff_finance_bereich_helpers.php', 'ff_finance_ensure_kellner_direkt_print_targets'],
    ];
}

/** ensure-Helfer, die keine eigenen Schritte sind (generisch bzw. nur Datenzeilen-Prüfung). */
const FF_SCHEMA_MIGRATE_NOT_STEPS = ['ff_ensure_column', 'ff_ensure_table', 'ff_ensure_index'];

/**
 * Führt alle Schritte aus und setzt bei vollständigem Schema den Merker.
 *
 * @return array{ok:bool, ran:bool, message:string, duration_ms:int, errors:list<string>, missing:list<string>, fingerprint:string}
 */
function ff_schema_migrate(mysqli $conn): array
{
    $t0 = microtime(true);
    $out = ['ok' => false, 'ran' => false, 'message' => '', 'duration_ms' => 0, 'errors' => [], 'missing' => [], 'fingerprint' => ff_schema_fingerprint()];

    // Sperre pro Datenbank (GET_LOCK gilt serverweit, der DB-Server kann geteilt sein)
    $lockRes = ff_mysqli_query($conn, "SELECT GET_LOCK(CONCAT('ff_schema_migrate_', DATABASE()), 0)");
    $lockRow = $lockRes ? mysqli_fetch_row($lockRes) : null;
    if (!$lockRow || (int) $lockRow[0] !== 1) {
        $out['message'] = 'Migration läuft bereits in einer anderen Anfrage.';
        return $out;
    }

    require_once __DIR__ . '/settings.php';
    ff_schema_migrating(true);
    $out['ran'] = true;
    try {
        foreach (ff_schema_migrate_steps() as [$file, $fn]) {
            try {
                require_once __DIR__ . '/' . $file;
                if (!function_exists($fn)) {
                    $out['errors'][] = $fn . ': Funktion fehlt';
                    continue;
                }
                $fn($conn);
            } catch (Throwable $e) {
                $out['errors'][] = $fn . ': ' . $e->getMessage();
            }
        }
    } finally {
        ff_schema_migrating(false);
    }

    // Kontrolle gegen die Soll-Struktur (nur lesend)
    require_once __DIR__ . '/ff_selftests.php';
    foreach (ff_selftests_check_database_schema($conn) as $r) {
        if (empty($r['ok'])) {
            $out['missing'][] = $r['name'] . ($r['detail'] !== '' ? ': ' . $r['detail'] : '');
        }
    }

    $out['ok'] = $out['errors'] === [] && $out['missing'] === [];
    setting_set($conn, 'schema_migrate_last_try', (string) time());
    setting_set($conn, 'schema_migrate_last_result', $out['ok'] ? 'ok' : 'fehler');
    setting_set($conn, 'schema_migrate_last_detail', mb_substr(implode(' | ', array_merge($out['errors'], $out['missing'])), 0, 2000));
    if ($out['ok']) {
        setting_set($conn, 'schema_fingerprint', $out['fingerprint']);
        setting_set($conn, 'schema_migrated_at', date('Y-m-d H:i:s'));
        $out['message'] = 'Datenbank-Schema ist aktuell.';
    } else {
        setting_set($conn, 'schema_fingerprint', '');
        $out['message'] = 'Datenbank-Schema unvollständig – Prüfungen laufen weiter bei jedem Aufruf.';
        error_log('ff_schema_migrate: ' . implode('; ', array_merge($out['errors'], $out['missing'])));
    }
    ff_schema_is_current($conn, true);

    ff_mysqli_query($conn, "SELECT RELEASE_LOCK(CONCAT('ff_schema_migrate_', DATABASE()))");
    $out['duration_ms'] = (int) round((microtime(true) - $t0) * 1000);
    return $out;
}

/**
 * Für Admin-Aufrufe: startet den Lauf, wenn der Merker nicht passt. Nach einem Fehlschlag
 * frühestens nach FF_SCHEMA_MIGRATE_RETRY_SEC erneut (sonst bremst jeder Admin-Klick).
 *
 * @return array|null Ergebnis des Laufs oder null, wenn nichts zu tun war
 */
function ff_schema_migrate_if_needed(mysqli $conn): ?array
{
    if (ff_schema_is_current($conn)) {
        return null;
    }
    require_once __DIR__ . '/settings.php';
    $lastTry = (int) setting_get($conn, 'schema_migrate_last_try', '0');
    $lastFp = (string) setting_get($conn, 'schema_migrate_last_fp', '');
    if ($lastFp === ff_schema_fingerprint() && $lastTry > 0 && time() - $lastTry < FF_SCHEMA_MIGRATE_RETRY_SEC) {
        return null;
    }
    setting_set($conn, 'schema_migrate_last_fp', ff_schema_fingerprint());
    return ff_schema_migrate($conn);
}

/** Merker verwerfen (Schema-Cache leeren): ensure-Funktionen prüfen wieder selbst. */
function ff_schema_reset_marker(mysqli $conn): void
{
    require_once __DIR__ . '/settings.php';
    setting_set($conn, 'schema_fingerprint', '');
    setting_set($conn, 'schema_migrate_last_try', '0');
    ff_schema_is_current($conn, true);
}

/**
 * Status für Pre-Flight und Diagnose.
 *
 * @return array{current:bool, fingerprint:string, stored:string, migrated_at:string, last_result:string, last_detail:string, last_try:string}
 */
function ff_schema_migrate_status(mysqli $conn): array
{
    require_once __DIR__ . '/settings.php';
    $lastTry = (int) setting_get($conn, 'schema_migrate_last_try', '0');
    return [
        'current' => ff_schema_is_current($conn),
        'fingerprint' => ff_schema_fingerprint(),
        'stored' => (string) setting_get($conn, 'schema_fingerprint', ''),
        'migrated_at' => (string) setting_get($conn, 'schema_migrated_at', ''),
        'last_result' => (string) setting_get($conn, 'schema_migrate_last_result', ''),
        'last_detail' => (string) setting_get($conn, 'schema_migrate_last_detail', ''),
        'last_try' => $lastTry > 0 ? date('Y-m-d H:i:s', $lastTry) : '',
    ];
}
