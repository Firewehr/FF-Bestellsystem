<?php
/**
 * Anzeige-Label für Tische / Direktverkauf (Arduino, API).
 */
declare(strict_types=1);

/** Direktverkauf-Konstante im System */
const FF_TISCH_DIREKTVERKAUF = 999999;

/**
 * @return array{display_table: string, tischname: string, is_direktverkauf: bool}
 */
function ff_table_display_meta(int $tischnummer, ?string $tischnameDb = null, ?string $bonId = null): array
{
    if ($tischnummer === FF_TISCH_DIREKTVERKAUF) {
        $bon = trim((string) $bonId);
        return [
            'display_table' => 'DIR',
            'tischname' => 'DIR',
            'is_direktverkauf' => true,
            'bon_id' => $bon !== '' ? $bon : null,
        ];
    }
    $name = trim((string) $tischnameDb);
    if ($name === '') {
        $name = 'Tisch ' . $tischnummer;
    }
    return [
        'display_table' => $name,
        'tischname' => $name,
        'is_direktverkauf' => false,
        'bon_id' => null,
    ];
}

function ff_table_display_from_row(array $row): array
{
    $tn = (int) ($row['tischnummer'] ?? 0);
    $tname = isset($row['tischname']) ? (string) $row['tischname'] : '';
    $bon = isset($row['bon_id']) ? (string) $row['bon_id'] : '';
    return ff_table_display_meta($tn, $tname, $bon);
}

/**
 * Tisch 999999 für print_target-JOIN (fehlte oft in tische → kein DV-Bon am Thermodrucker).
 */
function ff_ensure_direktverkauf_tisch_row(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $dv = (int) FF_TISCH_DIREKTVERKAUF;
    $chk = @mysqli_query($conn, 'SELECT 1 FROM tische WHERE tischnummer=' . $dv . ' LIMIT 1');
    if ($chk && mysqli_num_rows($chk) > 0) {
        mysqli_free_result($chk);
        return;
    }
    if ($chk) {
        mysqli_free_result($chk);
    }
    @mysqli_query(
        $conn,
        'INSERT INTO tische (tischnummer, tischname, userID, x, y) VALUES ('
        . $dv . ", 'Direktverkauf', 0, 0, 0) ON DUPLICATE KEY UPDATE tischname='Direktverkauf'"
    );
}

/** SQL-Ausdruck: Anzeigename auf dem Thermo-Bon (DV = Bon #…). */
function ff_sql_bon_tischname_select(string $b = 'b', string $t = 't'): string
{
    $dv = (int) FF_TISCH_DIREKTVERKAUF;

    return 'CASE WHEN ' . $b . '.tischnummer = ' . $dv
        . ' THEN CONCAT(\'Bon #\', COALESCE(NULLIF(TRIM(' . $b . '.bon_id), \'\'), \'DV\'))'
        . ' ELSE COALESCE(NULLIF(TRIM(' . $t . '.tischname), \'\'), CONCAT(\'Tisch \', ' . $b . '.tischnummer)) END AS tischname';
}

/** Gruppierungsschlüssel: DV pro bon_id, sonst pro Tischnummer. */
function ff_print_target_tisch_bucket_key(int $tischnummer, string $bonId): string
{
    $bonId = trim($bonId);
    if ($tischnummer === FF_TISCH_DIREKTVERKAUF && $bonId !== '') {
        return 'dv:' . $bonId;
    }

    return 't:' . $tischnummer;
}

/**
 * Sibling-Filter (print_target.php): DV nur über bon_id, nicht 5-Min-Fenster über alle Gäste.
 *
 * @param array<string, mixed> $row
 */
function ff_print_target_passes_sibling_filter(
    array $row,
    array $blockedByTisch_BonId,
    array $blockedByTisch_Timestamp,
    array $blockedByTisch_FiveMinKeys
): bool {
    $t = (int) ($row['tischnummer'] ?? 0);
    $bi = trim((string) ($row['bon_id'] ?? ''));

    if ($t === FF_TISCH_DIREKTVERKAUF) {
        if ($bi === '') {
            return true;
        }

        return !isset($blockedByTisch_BonId[$t][$bi]);
    }

    $tb = (string) ($row['timestampBestellung'] ?? '');
    $tbValid = ($tb !== '' && $tb !== '0000-00-00 00:00:00' && $tb !== '1970-01-01 00:00:00');

    if ($bi !== '') {
        return !isset($blockedByTisch_BonId[$t][$bi]);
    }
    if ($tbValid) {
        return !isset($blockedByTisch_Timestamp[$t][$tb]);
    }
    $bucket = (int) floor(((int) ($row['zeitstempel_unix'] ?? 0)) / 300);
    $b1 = (string) ($bucket - 1);
    $b2 = (string) $bucket;
    $b3 = (string) ($bucket + 1);

    return !isset($blockedByTisch_FiveMinKeys[$t][$b1])
        && !isset($blockedByTisch_FiveMinKeys[$t][$b2])
        && !isset($blockedByTisch_FiveMinKeys[$t][$b3]);
}
