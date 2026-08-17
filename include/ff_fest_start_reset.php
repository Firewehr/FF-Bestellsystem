<?php
/**
 * Notfall-Reset (nur Bestellungen + print) und Fest-Start (Verkaufsdaten leeren, Stammdaten behalten).
 */
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ff_rechnung_seq.php';

if (!function_exists('ff_reset_table_exists')) {
    function ff_reset_table_exists(mysqli $conn, string $table): bool
    {
        $t = mysqli_real_escape_string($conn, $table);
        $q = mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
        return $q && mysqli_num_rows($q) > 0;
    }
}

if (!function_exists('ff_reset_truncate_table')) {
    /** @return string|null Fehlermeldung oder null bei Erfolg */
    function ff_reset_truncate_table(mysqli $conn, string $table): ?string
    {
        if (!ff_reset_table_exists($conn, $table)) {
            return null;
        }
        if (!mysqli_query($conn, 'TRUNCATE TABLE `' . str_replace('`', '', $table) . '`')) {
            return mysqli_error($conn) ?: ('TRUNCATE ' . $table . ' fehlgeschlagen');
        }
        return null;
    }
}

if (!function_exists('ff_reset_fest_start_reset_sequences')) {
    /**
     * Bon-/Bestell- und Rechnungszähler für neuen Festbetrieb zurücksetzen.
     * current_fest_id, Speisekarte, bon_nr_start bleiben unverändert.
     */
    function ff_reset_fest_start_reset_sequences(mysqli $conn): void
    {
        settings_ensure_table($conn);
        setting_set($conn, 'order_nr_seq', '0');
        setting_set($conn, 'bon_nr_seq', '0');
        setting_set($conn, FF_RECHNUNG_NEXT_KEY, '1');
        @mysqli_query($conn, "DELETE FROM settings WHERE k REGEXP '^rechnung_next_[0-9]{4}$'");
        @mysqli_query($conn, "DELETE FROM settings WHERE k LIKE 'RECHNUNG_COUNTER_%'");
    }
}

if (!function_exists('ff_reset_notfall')) {
    /**
     * @return array{ok:bool, cleared:list<string>, error:?string}
     */
    function ff_reset_notfall(mysqli $conn): array
    {
        $cleared = [];
        $err = ff_reset_truncate_table($conn, 'bestellungen');
        if ($err !== null) {
            return ['ok' => false, 'cleared' => $cleared, 'error' => $err];
        }
        $cleared[] = 'bestellungen';

        $errPrint = ff_reset_truncate_table($conn, 'print');
        if ($errPrint !== null) {
            return ['ok' => false, 'cleared' => $cleared, 'error' => $errPrint];
        }
        if (ff_reset_table_exists($conn, 'print')) {
            $cleared[] = 'print';
        }

        return ['ok' => true, 'cleared' => $cleared, 'error' => null];
    }
}

if (!function_exists('ff_reset_fest_start')) {
    /**
     * Verkaufs- und Auswertungsdaten löschen für einen sauberen Fest-Start.
     *
     * Bleiben erhalten (Stammdaten):
     *   - users (Benutzer)
     *   - positionen, position_subcategories, beilagen (Speisekarte)
     *   - tische
     *   - feste (mit Einstellungen)
     *   - print_targets (Druckziele)
     *   - mitarbeiter_bereiche (Verpflegungs-Bereiche)
     *   - kassen_bereiche (Finanzbereiche / Kassen-Stammdaten)
     *   - settings (Systemeinstellungen, außer Nummernkreise siehe unten)
     *
     * Gelöscht (Bewegungsdaten und Auswertung):
     *   - bestellungen, bestellung_meta
     *   - print, printer_jobs (Druck-Warteschlangen)
     *   - rechnungen, sammelrechnungen
     *   - buchungen, mitarbeiter_verpflegung
     *   - menu_locks + menu_lock_exceptions (Sperren)
     *   - Finanzmodul: kassen_sessions/_bewegungen, kellner_settlements/_bewegungen
     *     (kassen_bereiche bleiben — Stammdaten der Kassenbereiche)
     *
     * Zähler (settings) werden auf Null gesetzt:
     *   order_nr_seq, bon_nr_seq, rechnung_next (alle Jahre + Legacy-Keys).
     *
     * @return array{ok:bool, cleared:list<string>, error:?string}
     */
    function ff_reset_fest_start(mysqli $conn): array
    {
        // Reihenfolge wichtig: Kinder vor Eltern (Fremdschlüssel).
        $tablesInOrder = [
            'menu_lock_exceptions',
            'menu_locks',
            'print',
            'bestellungen',
            'bestellung_meta',
            'sammelrechnungen',
            'rechnungen',
            'printer_jobs',
            'buchungen',
            'mitarbeiter_verpflegung',

            // Finance-Modul: Kellner-Abrechnungen + Bewegungen.
            // settlement_id-Verweise auf bestellungen sind schon weg (bestellungen kommt davor).
            'kellner_bewegungen',
            'kellner_settlements',

            // Finance-Modul: Sessions und Bewegungen (Bereiche-Stammdaten bleiben).
            'kassen_bewegungen',
            'kassen_sessions',
        ];

        $cleared = [];
        // TRUNCATE ist DDL (impliziter Commit) — keine umschließende Transaktion.
        foreach ($tablesInOrder as $table) {
            if (!ff_reset_table_exists($conn, $table)) {
                continue;
            }
            $err = ff_reset_truncate_table($conn, $table);
            if ($err !== null) {
                return ['ok' => false, 'cleared' => $cleared, 'error' => $err];
            }
            $cleared[] = $table;
        }

        ff_reset_fest_start_reset_sequences($conn);
        $cleared[] = 'settings (Zähler: order_nr_seq, bon_nr_seq, rechnung_next)';

        return ['ok' => true, 'cleared' => $cleared, 'error' => null];
    }
}
