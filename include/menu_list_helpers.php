<?php
/**
 * Batch-Abfragen für Speise-/Getränkekarten (vermeidet N+1 pro Kachel).
 */
declare(strict_types=1);

require_once __DIR__ . '/menu_lock_helpers.php';
require_once __DIR__ . '/ff_position_stock_summary.php';

/** SQL-Zusatz für Direktverkauf, wenn nur eigene Bestellungen des Kellners zählen. */
function ff_direktverkauf_kellner_filter_sql(mysqli $conn, string $alias = 'bestellungen'): string
{
    require_once __DIR__ . '/settings.php';
    if (setting_get($conn, 'kellner_nur_eigene', '1') !== '1') {
        return '';
    }
    $user = (string) ($_SESSION['user']['username'] ?? '');
    if ($user === '') {
        return '';
    }
    $esc = mysqli_real_escape_string($conn, $user);
    if ($alias === '') {
        return " AND (`kellner`='{$esc}' OR `kellnerZahlung`='{$esc}')";
    }

    return " AND (`{$alias}`.`kellner`='{$esc}' OR `{$alias}`.`kellnerZahlung`='{$esc}')";
}

/** @param list<array<string,mixed>> $rows */
function ff_menu_collect_position_ids(array $rows): array
{
    $ids = [];
    foreach ($rows as $r) {
        $id = (int)($r['rowid'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    return array_keys($ids);
}

/** SQL: noch nicht abgeschickt (Warenkorb am Tisch, nicht DV). */
function ff_tisch_unsent_sql_condition(string $alias = 'b'): string
{
    $a = $alias !== '' ? $alias . '.' : '';

    return $a . '`delete`=0 AND (' . $a . 'bestellt IS NULL OR ' . $a . 'bestellt=0)'
        . " AND ({$a}timestampBezahlung IS NULL OR {$a}timestampBezahlung='0000-00-00 00:00:00')";
}

/** Anzahl nicht abgeschickter Positionen am Tisch (eigener Kellner, wenn eingestellt). */
function ff_tisch_count_unsent(mysqli $conn, int $tischnummer): int
{
    if ($tischnummer <= 0 || $tischnummer === 999999) {
        return 0;
    }
    $kel = ff_tisch_kellner_scope_filter_sql($conn, 'b');
    $sql = 'SELECT COUNT(*) AS cnt FROM bestellungen b WHERE b.tischnummer=' . (int) $tischnummer
        . ' AND ' . ff_tisch_unsent_sql_condition('b') . $kel;
    $res = mysqli_query($conn, $sql);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        return (int) ($row['cnt'] ?? 0);
    }

    return 0;
}

/**
 * Nicht abgeschickte Positionen am Tisch stornieren (beim Verlassen ohne Abschicken).
 *
 * @return array{ok:bool,affected?:int,message?:string,error?:string}
 */
function ff_tisch_clear_unsent_orders(mysqli $conn, int $tischnummer): array
{
    if ($tischnummer <= 0 || $tischnummer === 999999) {
        return ['ok' => false, 'error' => 'bad_table', 'message' => 'Ungültiger Tisch.'];
    }
    $kel = ff_tisch_kellner_scope_filter_sql($conn, 'b');
    $sql = "UPDATE bestellungen b SET
            b.`delete` = 1,
            b.kueche = 0,
            b.zeitKueche = '0000-00-00 00:00:00',
            b.ausgeliefert = 0
        WHERE b.tischnummer = " . (int) $tischnummer . '
          AND ' . ff_tisch_unsent_sql_condition('b') . $kel;
    if (!mysqli_query($conn, $sql)) {
        return ['ok' => false, 'error' => 'db', 'message' => 'Löschen fehlgeschlagen.'];
    }
    $aff = mysqli_affected_rows($conn);

    return [
        'ok' => true,
        'affected' => $aff,
        'message' => $aff > 0 ? ($aff . ' Position(en) entfernt.') : 'Keine offenen Entwürfe.',
    ];
}

/** Offene (noch nicht abgeschickte) Bestellungen pro Position am Tisch. @return array<int,int> */
function ff_menu_batch_open_counts_tisch(mysqli $conn, int $tischnummer): array
{
    $map = [];
    if ($tischnummer <= 0 || $tischnummer === 999999) {
        return $map;
    }
    $sql = 'SELECT `position`, COUNT(*) AS cnt FROM `bestellungen`
            WHERE tischnummer=' . (int)$tischnummer . '
              AND kueche=0 AND `delete`=0
              AND (bestellt IS NULL OR bestellt=0)
            GROUP BY `position`';
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $map[(int)$row['position']] = (int)$row['cnt'];
        }
    }
    return $map;
}

/**
 * Kellner-Filter für Tisch-Zähler (wie tisch_anzeigen.php / list_tische.php).
 */
function ff_tisch_kellner_scope_filter_sql(mysqli $conn, string $alias = 'b'): string
{
    require_once __DIR__ . '/settings.php';
    if (setting_get($conn, 'kellner_nur_eigene', '1') !== '1') {
        return '';
    }
    if (!empty($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1) {
        return '';
    }

    return ff_direktverkauf_kellner_filter_sql($conn, $alias);
}

/**
 * Unbezahlte, kassierbare Positionen am Tisch (gleiche Logik wie list_BestellungenZahlen.php).
 */
function ff_tisch_count_unpaid_kasse(mysqli $conn, int $tischnummer, ?string $paymentMode = null): int
{
    if ($tischnummer <= 0 || $tischnummer === 999999) {
        return 0;
    }
    require_once __DIR__ . '/ff_schreibaus.php';
    if ($paymentMode === null || ($paymentMode !== 'instant' && $paymentMode !== 'after')) {
        $paymentMode = ff_aktiver_payment_mode($conn);
    }
    $where = ff_tisch_open_pay_sql_condition($conn, $tischnummer, $paymentMode);
    $kellner = ff_tisch_kellner_scope_filter_sql($conn, 'b');
    $sql = 'SELECT COUNT(*) AS cnt FROM bestellungen b
            INNER JOIN positionen p ON p.rowid = b.position
            WHERE b.tischnummer = ' . (int) $tischnummer . '
              AND ' . $where . $kellner;
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return 0;
    }
    $row = mysqli_fetch_assoc($res);

    return (int) ($row['cnt'] ?? 0);
}

/**
 * Unbezahlte Zeilen ohne gültige Speisekartenposition (erscheinen sonst weder in Zahlen noch Historie).
 */
function ff_tisch_count_unpaid_orphan(mysqli $conn, int $tischnummer, ?string $paymentMode = null): int
{
    if ($tischnummer <= 0 || $tischnummer === 999999) {
        return 0;
    }
    require_once __DIR__ . '/ff_schreibaus.php';
    if ($paymentMode === null || ($paymentMode !== 'instant' && $paymentMode !== 'after')) {
        $paymentMode = ff_aktiver_payment_mode($conn);
    }
    $where = ff_schreibaus_open_sql_condition($paymentMode);
    $kellner = ff_tisch_kellner_scope_filter_sql($conn, 'b');
    $sql = 'SELECT COUNT(*) AS cnt FROM bestellungen b
            LEFT JOIN positionen p ON p.rowid = b.position
            WHERE b.tischnummer = ' . (int) $tischnummer . '
              AND p.rowid IS NULL
              AND ' . $where . $kellner;
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return 0;
    }
    $row = mysqli_fetch_assoc($res);

    return (int) ($row['cnt'] ?? 0);
}

/**
 * Historie Direktverkauf: Admin sieht alle Kassa-Mitarbeiter (optional ?kellner=), sonst nur eigene.
 */
function ff_direktverkauf_normalize_bon_id(string $raw): string
{
    $s = trim($raw);
    if ($s === '') {
        return '';
    }
    // Eingaben wie „#28-004“, „Bon #28-004“ (History, DV-Filter, Links)
    $s = preg_replace('/^#+/u', '', $s) ?? $s;
    $s = preg_replace('/^bon\s*#?\s*/iu', '', $s) ?? $s;
    $s = trim($s);
    if (preg_match('/^(\d{1,2})-(\d{1,3})$/', $s, $m)) {
        return sprintf('%02d-%03d', (int) $m[1], (int) $m[2]);
    }

    return '';
}

/** Mögliche Schreibweisen derselben Bon-ID (DB vs. Anzeige). @return list<string> */
function ff_direktverkauf_bon_match_variants(string $bonId): array
{
    $norm = ff_direktverkauf_normalize_bon_id($bonId);
    $raw = trim($bonId);
    if ($norm === '') {
        return $raw !== '' ? [$raw] : [];
    }
    if (!preg_match('/^(\d+)-(\d+)$/', $norm, $m)) {
        return [$norm];
    }
    $d = (int) $m[1];
    $s = (int) $m[2];

    return array_values(array_unique([
        sprintf('%02d-%03d', $d, $s),
        sprintf('%d-%d', $d, $s),
        sprintf('%02d-%d', $d, $s),
        sprintf('%d-%03d', $d, $s),
        $norm,
        $raw,
    ]));
}

function ff_direktverkauf_history_scope_sql(
    mysqli $conn,
    bool $isAdmin,
    string $alias = 'b',
    ?string $kellnerFilter = null
): string {
    /** Wie Tisch-Historie: alle Kassa-Zeilen; optional Admin-Filter ?kellner= */
    if (!$isAdmin) {
        return '';
    }
    $kellner = $kellnerFilter !== null
        ? trim($kellnerFilter)
        : trim((string) ($_GET['kellner'] ?? ''));
    if ($kellner === '') {
        return '';
    }
    $esc = mysqli_real_escape_string($conn, $kellner);

    return " AND (`{$alias}`.`kellner` = '{$esc}' OR `{$alias}`.`kellnerZahlung` = '{$esc}')";
}

/** SQL: nur aktueller Direktverkauf-Bon (optional, TRIM wegen utf8mb4_bin-Spalte). */
function ff_direktverkauf_bon_filter_sql(mysqli $conn, string $bonId, string $alias = 'bestellungen'): string
{
    $variants = ff_direktverkauf_bon_match_variants($bonId);
    if ($variants === []) {
        return '';
    }
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    if ($a === '') {
        $a = 'bestellungen';
    }
    $in = [];
    foreach ($variants as $v) {
        $in[] = "'" . mysqli_real_escape_string($conn, $v) . "'";
    }

    return ' AND TRIM(CAST(`' . $a . '`.`bon_id` AS CHAR CHARACTER SET utf8mb4)) IN (' . implode(',', $in) . ')';
}

/**
 * Unbezahlte DV-Zeilen am Bon (Summe, IDs) — Paybar/Warenkorb, wie zahlen_direktverkauf.php.
 *
 * @return array{sum: float, count: int, ids: list<int>, sum_fmt: string}
 */
function ff_direktverkauf_open_pay_summary(mysqli $conn, string $bonId = ''): array
{
    $kellnerFilter = ff_direktverkauf_kellner_filter_sql($conn, 'b');
    $bonSql = ff_direktverkauf_bon_filter_sql($conn, $bonId, 'b');
    $amt = 'COALESCE(NULLIF(b.betrag, 0), p.Betrag)';
    $sql = 'SELECT b.rowid AS rowidBestellung, ' . $amt . ' AS betrag
            FROM bestellungen b
            INNER JOIN positionen p ON p.rowid = b.position
            WHERE b.tischnummer = 999999
              ' . $kellnerFilter . $bonSql . "
              AND b.`delete` = 0
              AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung = '0000-00-00 00:00:00')
            ORDER BY b.rowid ASC";
    $sum = 0.0;
    $ids = [];
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $sum += (float) ($row['betrag'] ?? 0);
            $ids[] = (int) ($row['rowidBestellung'] ?? 0);
        }
    }

    return [
        'sum' => $sum,
        'count' => count($ids),
        'ids' => $ids,
        'sum_fmt' => number_format($sum, 2, ',', '.') . ' €',
    ];
}

/**
 * Offene Positionen Direktverkauf am aktuellen Bon (Kachel-Zähler).
 * Unbezahlt auf dem Bon — wie bestellung_minus.php / Paybar (inkl. kassa_only mit kueche=1).
 *
 * @return array<int,int>
 */
function ff_menu_batch_open_counts_direkt(mysqli $conn, string $kellnerFilterSql = '', string $bonId = ''): array
{
    $map = [];
    $sql = 'SELECT `position`, COUNT(*) AS cnt FROM `bestellungen`
            WHERE tischnummer=999999'
        . $kellnerFilterSql
        . ff_direktverkauf_bon_filter_sql($conn, $bonId, 'bestellungen')
        . " AND `delete`=0
              AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00')
            GROUP BY `position`";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $map[(int)$row['position']] = (int)$row['cnt'];
        }
    }
    return $map;
}

/** Eine offene Menge am DV-Bon (schneller als ff_menu_batch_open_counts_direkt bei jedem Klick). */
function ff_menu_open_count_direkt(mysqli $conn, int $positionId, string $kellnerFilterSql = '', string $bonId = ''): int
{
    if ($positionId <= 0) {
        return 0;
    }
    $sql = 'SELECT COUNT(*) AS cnt FROM `bestellungen`
            WHERE tischnummer=999999
              AND `position`=' . (int) $positionId
        . $kellnerFilterSql
        . ff_direktverkauf_bon_filter_sql($conn, $bonId, 'bestellungen')
        . " AND `delete`=0
              AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00')";
    $res = mysqli_query($conn, $sql);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        return (int) ($row['cnt'] ?? 0);
    }

    return 0;
}

/** Gesamtverbrauch pro Position (Bestellungen + Mitarbeiter-Verpflegung, für maxBestellbar). @return array<int,int> */
function ff_menu_batch_global_counts(mysqli $conn, string $extraWhereSql = ''): array
{
    return ff_position_batch_consumed_totals($conn, $extraWhereSql);
}

/**
 * Sperrstatus für viele Positionen auf einmal.
 * @param list<int> $positionIds
 * @return array<int,array{reason:string,until_raw:?string,until_label:string}>
 */
function ff_menu_batch_lock_map(mysqli $conn, array $positionIds, int $menuType): array
{
    menu_lock_cleanup_expired($conn);

    $map = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $positionIds), static function ($id) {
        return $id > 0;
    })));
    if ($ids === []) {
        return $map;
    }

    $in = implode(',', $ids);
    $res = mysqli_query($conn, "SELECT position_id, reason, locked_until FROM menu_locks
            WHERE scope='position' AND position_id IN ({$in})
              AND (locked_until IS NULL OR locked_until > NOW())");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $map[(int)$row['position_id']] = menu_lock_format_row($row);
        }
    }

    $res2 = mysqli_query($conn, 'SELECT l.id, l.reason, l.locked_until FROM menu_locks l
            WHERE l.scope=\'type_all\' AND l.menu_type=' . (int)$menuType . '
              AND (l.locked_until IS NULL OR l.locked_until > NOW())
            LIMIT 1');
    if ($res2 && ($lock = mysqli_fetch_assoc($res2))) {
        $lid = (int)$lock['id'];
        $exceptions = [];
        $exRes = mysqli_query($conn, 'SELECT position_id FROM menu_lock_exceptions WHERE lock_id=' . $lid);
        if ($exRes) {
            while ($ex = mysqli_fetch_assoc($exRes)) {
                $exceptions[(int)$ex['position_id']] = true;
            }
        }
        $formatted = menu_lock_format_row($lock);
        foreach ($ids as $pid) {
            if (!isset($map[$pid]) && !isset($exceptions[$pid])) {
                $map[$pid] = $formatted;
            }
        }
    }

    return $map;
}

/** Einzelne offene Menge (z. B. nach bestellung_save). */
function ff_menu_open_count_tisch(mysqli $conn, int $tischnummer, int $positionId): int
{
    if ($tischnummer <= 0 || $tischnummer === 999999 || $positionId <= 0) {
        return 0;
    }
    $sql = 'SELECT COUNT(*) AS cnt FROM `bestellungen`
            WHERE tischnummer=' . (int)$tischnummer . '
              AND kueche=0 AND `delete`=0
              AND (bestellt IS NULL OR bestellt=0)
              AND `position`=' . (int)$positionId;
    $res = mysqli_query($conn, $sql);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        return (int)$row['cnt'];
    }
    return 0;
}

/** @return array{global_cnt:int,rest:int,max_bestellbar:int} */
function ff_menu_stock_state(mysqli $conn, int $positionId, int $maxBestellbar, string $extraWhereSql = ''): array
{
    $globalCnt = 0;
    if ($maxBestellbar > 0 && $positionId > 0) {
        $globalCnt = ff_position_consumed_total($conn, $positionId, $extraWhereSql);
    }
    $rest = $maxBestellbar > 0 ? ($maxBestellbar - $globalCnt) : 999999;
    return [
        'global_cnt' => $globalCnt,
        'rest' => $rest,
        'max_bestellbar' => $maxBestellbar,
    ];
}
