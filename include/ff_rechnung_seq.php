<?php
/**
 * Rechnungs-Laufnummer (global, nicht pro Kalenderjahr) und optionales Präfix pro Fest.
 */
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/** Einheitlicher Settings-Schlüssel für den nächsten Zählerstand (unabhängig vom Jahr). */
const FF_RECHNUNG_NEXT_KEY = 'rechnung_next';

function ff_feste_ensure_rechnung_prefix_column(mysqli $conn): void
{
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM feste LIKE 'rechnung_prefix'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        return;
    }
    @mysqli_query($conn, 'ALTER TABLE feste ADD COLUMN rechnung_prefix VARCHAR(16) NULL DEFAULT NULL COMMENT \'Rechnungsnr.-Präfix; leer = global rechnung_prefix\' AFTER code');
}

/**
 * Nächster Zählerstand für Rechnungen (mindestens 1). Migriert einmalig aus alter Logik rechnung_next_JJJJ.
 */
function ff_rechnung_next_read(mysqli $conn): int
{
    $v = settings_get($conn, FF_RECHNUNG_NEXT_KEY, null);
    if ($v !== null && $v !== '') {
        return max(1, (int) $v);
    }

    $best = 1;
    $hadLegacy = false;
    $rq = @mysqli_query($conn, "SELECT v FROM settings WHERE k REGEXP '^rechnung_next_[0-9]{4}$'");
    if ($rq) {
        while ($row = mysqli_fetch_assoc($rq)) {
            $hadLegacy = true;
            $n = (int) ($row['v'] ?? 0);
            if ($n > $best) {
                $best = $n;
            }
        }
    }
    if ($hadLegacy) {
        settings_set($conn, FF_RECHNUNG_NEXT_KEY, (string) max(1, $best));
    }

    return max(1, $best);
}

/**
 * Reserviert die nächste Rechnungs-Laufnummer atomar per LAST_INSERT_ID()
 * und erhöht den Zählerstand in settings direkt, um Doppelvergaben bei
 * gleichzeitigem Kassieren auszuschließen.
 */
function ff_rechnung_next_allocate(mysqli $conn): int
{
    settings_ensure_table($conn);

    $keyEsc = mysqli_real_escape_string($conn, FF_RECHNUNG_NEXT_KEY);

    // Sicherstellen, dass der Eintrag rechnung_next existiert (atomar ohne Race Condition)
    $v = settings_get($conn, FF_RECHNUNG_NEXT_KEY, null);
    if ($v === null || $v === '') {
        $initial = ff_rechnung_next_read($conn);
        $initVal = max(1, (int) $initial);
        @mysqli_query($conn, "INSERT INTO settings (k, v) VALUES ('{$keyEsc}', '{$initVal}') ON DUPLICATE KEY UPDATE k = k");
    }

    // Atomar um 1 erhöhen und den neuen Wert über LAST_INSERT_ID abholen
    $ok = mysqli_query($conn, "UPDATE settings SET v = LAST_INSERT_ID(CAST(v AS UNSIGNED) + 1) WHERE k = '{$keyEsc}'");
    if ($ok) {
        $nextPlusOne = (int) mysqli_insert_id($conn);
        // Falls mysqli_insert_id() treiberbedingt 0 liefert: SELECT LAST_INSERT_ID() abfragen
        if ($nextPlusOne <= 0) {
            $res = mysqli_query($conn, 'SELECT LAST_INSERT_ID() AS lid');
            if ($res && ($row = mysqli_fetch_assoc($res))) {
                $nextPlusOne = (int) ($row['lid'] ?? 0);
            }
        }
        if ($nextPlusOne > 0) {
            $allocated = max(1, $nextPlusOne - 1);
            ff_settings_cache($conn, ['v' => (string) $nextPlusOne], FF_RECHNUNG_NEXT_KEY);
            return $allocated;
        }
    }

    // Fallback: Falls die Zeile unerwartet fehlte, anlegen und erneut atomar versuchen
    $initial = ff_rechnung_next_read($conn);
    $initVal = max(1, (int) $initial);
    @mysqli_query($conn, "INSERT INTO settings (k, v) VALUES ('{$keyEsc}', '" . ($initVal + 1) . "') ON DUPLICATE KEY UPDATE v = LAST_INSERT_ID(CAST(v AS UNSIGNED) + 1)");
    $res = mysqli_query($conn, 'SELECT LAST_INSERT_ID() AS lid');
    if ($res && ($row = mysqli_fetch_assoc($res)) && (int) ($row['lid'] ?? 0) > 0) {
        $nextPlusOne = (int) $row['lid'];
        $allocated = max(1, $nextPlusOne - 1);
        ff_settings_cache($conn, ['v' => (string) $nextPlusOne], FF_RECHNUNG_NEXT_KEY);
        return $allocated;
    }

    settings_set($conn, FF_RECHNUNG_NEXT_KEY, (string) ($initVal + 1));
    return $initVal;
}

/**
 * Präfix für die Rechnungsnummer: pro Fest (feste.rechnung_prefix), sonst globales settings rechnung_prefix.
 */
function ff_rechnung_prefix_for_fest(mysqli $conn, ?int $fest_id): string
{
    ff_feste_ensure_rechnung_prefix_column($conn);

    $fallback = trim((string) settings_get($conn, 'rechnung_prefix', 'R'));
    if ($fallback === '') {
        $fallback = 'R';
    }
    if ($fest_id === null || $fest_id <= 0) {
        return $fallback;
    }
    $q = mysqli_query($conn, 'SELECT rechnung_prefix FROM feste WHERE id=' . (int) $fest_id . ' LIMIT 1');
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $p = trim((string) ($r['rechnung_prefix'] ?? ''));
        if ($p !== '') {
            return $p;
        }
    }

    return $fallback;
}
