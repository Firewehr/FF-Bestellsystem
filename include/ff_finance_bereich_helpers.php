<?php
/**
 * Finanzbereiche (kassen_bereiche): Auswertung, Zeitraum, Verkaufszuordnung.
 */
declare(strict_types=1);

require_once __DIR__ . '/ff_finance_schema.php';
require_once __DIR__ . '/ff_schreibaus.php';

function ff_finance_fest_count(mysqli $conn): int
{
    static $cnt = null;
    if ($cnt !== null) {
        return $cnt;
    }
    $res = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM feste');
    $cnt = ($res && ($r = mysqli_fetch_assoc($res))) ? (int) ($r['c'] ?? 0) : 0;

    return $cnt;
}

/** SQL-Zusatz fest_id für bestellungen-Alias (aktuelles Fest). Bei nur einem Fest: auch Zeilen ohne fest_id. */
function ff_finance_fest_filter_sql(mysqli $conn, string $alias = 'b'): string
{
    static $cached = null;
    if ($cached !== null) {
        return str_replace('__ALIAS__', $alias, $cached);
    }
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `bestellungen` LIKE 'fest_id'");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        $cached = '';
        return '';
    }
    mysqli_free_result($chk);
    require_once __DIR__ . '/settings.php';
    $festId = (int) setting_get($conn, 'current_fest_id', '0');
    if ($festId <= 0) {
        $fAct = @mysqli_query($conn, 'SELECT id FROM feste WHERE aktiv=1 LIMIT 1');
        if ($fAct && ($r = mysqli_fetch_assoc($fAct))) {
            $festId = (int) $r['id'];
        }
    }
    if ($festId > 0) {
        if (ff_finance_fest_count($conn) <= 1) {
            $cached = ' AND (__ALIAS__.fest_id = ' . $festId . ' OR __ALIAS__.fest_id IS NULL)';
        } else {
            $cached = ' AND __ALIAS__.fest_id = ' . $festId;
        }
    } else {
        $cached = '';
    }

    return str_replace('__ALIAS__', $alias, $cached);
}

/** Zusatzfilter: offene Kellner-Abrechnung (bezahlt, kein Personal/Schreibaus, nicht abgerechnet). */
function ff_finance_kellner_open_extra_sql(string $b = 'b'): string
{
    return " AND {$b}.timestampBezahlung IS NOT NULL AND {$b}.timestampBezahlung <> '0000-00-00 00:00:00'"
        . " AND COALESCE({$b}.is_gratis, 0) = 0 AND COALESCE({$b}.schreibaus, 0) = 0"
        . " AND {$b}.settlement_id IS NULL";
}

function ff_finance_order_paid_base_sql(string $b = 'b'): string
{
    return "{$b}.`delete` = 0 AND {$b}.timestampBezahlung IS NOT NULL AND {$b}.timestampBezahlung <> '0000-00-00 00:00:00'
        AND COALESCE({$b}.is_gratis, 0) = 0 AND COALESCE({$b}.schreibaus, 0) = 0";
}

function ff_finance_order_amount_expr(string $b = 'b', string $p = 'p'): string
{
    return "COALESCE(NULLIF({$b}.betrag, 0), {$p}.Betrag)";
}

function ff_finance_current_fest_id(mysqli $conn): int
{
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `bestellungen` LIKE 'fest_id'");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        return 0;
    }
    mysqli_free_result($chk);
    require_once __DIR__ . '/settings.php';
    $festId = (int) setting_get($conn, 'current_fest_id', '0');
    if ($festId <= 0) {
        $fAct = @mysqli_query($conn, 'SELECT id FROM feste WHERE aktiv=1 LIMIT 1');
        if ($fAct && ($r = mysqli_fetch_assoc($fAct))) {
            $festId = (int) $r['id'];
        }
    }

    return $festId;
}

/**
 * Aufschlüsselung bezahlter Kassenzeilen eines Kellners (Abgleich History ↔ Finanzen).
 *
 * @return array<string, mixed>
 */
function ff_kellner_paid_breakdown(mysqli $conn, string $kellner, string $scope = 'kellner'): array
{
    $kEsc = mysqli_real_escape_string($conn, trim($kellner));
    $amt = ff_finance_order_amount_expr('b', 'p');
    $paid = ff_finance_order_paid_base_sql('b');
    $festId = ff_finance_current_fest_id($conn);
    $festCur = ff_finance_fest_filter_sql($conn, 'b');
    $festWrong = $festId > 0 ? " AND b.fest_id IS NOT NULL AND b.fest_id <> {$festId}" : '';
    $tisch = $scope === 'dv' ? ' AND b.tischnummer = 999999 ' : ' AND b.tischnummer <> 999999 ';
    $scopeKs = $scope === 'dv'
        ? "ks.settlement_scope = 'direktverkauf'"
        : "(ks.settlement_scope = 'kellner' OR ks.settlement_scope IS NULL OR ks.settlement_scope = '')";
    $settledExists = "EXISTS (SELECT 1 FROM kellner_settlements ks WHERE ks.id = b.settlement_id"
        . " AND (ks.voided_at IS NULL OR ks.voided_at = '0000-00-00 00:00:00') AND {$scopeKs})";
    $baseKz = "{$paid} AND b.kellnerZahlung = '{$kEsc}'{$tisch}";
    $scopeFest = "b.`delete` = 0 AND b.kellnerZahlung = '{$kEsc}'{$festCur}";
    $unpaidSql = "({$scopeFest} AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung = '0000-00-00 00:00:00'))";
    $gratisSql = "({$scopeFest} AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung <> '0000-00-00 00:00:00'"
        . ' AND COALESCE(b.is_gratis, 0) = 1)';
    $schreibSql = "({$scopeFest} AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung <> '0000-00-00 00:00:00'"
        . ' AND COALESCE(b.is_gratis, 0) = 0 AND COALESCE(b.schreibaus, 0) = 1)';

    $buckets = [
        'settled' => "{$baseKz} AND {$settledExists}",
        'open_current_fest' => "{$baseKz} AND b.settlement_id IS NULL{$festCur}",
        'open_other_fest' => "{$baseKz} AND b.settlement_id IS NULL{$festWrong}",
        'paid_other_kellner' => "{$paid} AND b.kellner = '{$kEsc}' AND (b.kellnerZahlung IS NULL OR TRIM(b.kellnerZahlung) = '' OR b.kellnerZahlung <> '{$kEsc}')",
        'excluded_unpaid' => $unpaidSql,
        'excluded_gratis' => $gratisSql,
        'excluded_schreibaus' => $schreibSql,
    ];
    $out = [
        'fest_id' => $festId,
        'fest_count' => ff_finance_fest_count($conn),
        'settled' => ['count' => 0, 'sum' => 0.0],
        'open_current_fest' => ['count' => 0, 'sum' => 0.0],
        'open_other_fest' => ['count' => 0, 'sum' => 0.0],
        'paid_other_kellner' => ['count' => 0, 'sum' => 0.0],
        'excluded_unpaid' => ['count' => 0, 'sum' => 0.0],
        'excluded_gratis' => ['count' => 0, 'sum' => 0.0],
        'excluded_schreibaus' => ['count' => 0, 'sum' => 0.0],
    ];
    foreach ($buckets as $key => $where) {
        $sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM({$amt}), 0) AS summe
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            WHERE {$where}";
        $res = mysqli_query($conn, $sql);
        if ($res && ($row = mysqli_fetch_assoc($res))) {
            $out[$key] = [
                'count' => (int) ($row['cnt'] ?? 0),
                'sum' => round((float) ($row['summe'] ?? 0), 2),
            ];
        }
    }

    return $out;
}

/** Anzeigename für Kellner-Abrechnung (users.display_name, sonst Login). */
function ff_finance_kellner_label(mysqli $conn, string $login): string
{
    $login = trim($login);
    if ($login === '') {
        return '';
    }
    require_once __DIR__ . '/user_landing.php';
    ff_users_ensure_landing_columns($conn);

    return ff_user_display_label($conn, $login);
}

/**
 * @return array{von_sql:string,bis_sql:string,has_time:bool}|null
 */
function ff_finance_parse_datetime_range(string $vonRaw, string $bisRaw): ?array
{
    $vonRaw = trim(str_replace('T', ' ', $vonRaw));
    $bisRaw = trim(str_replace('T', ' ', $bisRaw));
    if ($vonRaw === '' && $bisRaw === '') {
        return null;
    }
    $hasTime = (strpos($vonRaw, ':') !== false) || (strpos($bisRaw, ':') !== false);

    $parse = static function (string $raw, bool $endOfDayIfDateOnly): ?string {
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $endOfDayIfDateOnly ? ($raw . ' 23:59:59') : ($raw . ' 00:00:00');
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    };

    $vonSql = $parse($vonRaw, false);
    $bisSql = $parse($bisRaw, true);
    if ($vonSql === null && $bisSql === null) {
        return null;
    }
    if ($vonSql === null && $bisSql !== null) {
        $vonSql = substr($bisSql, 0, 10) . ' 00:00:00';
    }
    if ($bisSql === null && $vonSql !== null) {
        $bisSql = substr($vonSql, 0, 10) . ' 23:59:59';
    }
    if (strtotime($vonSql) > strtotime($bisSql)) {
        return null;
    }
    return ['von_sql' => $vonSql, 'bis_sql' => $bisSql, 'has_time' => $hasTime];
}

function ff_finance_effective_print_target_expr(string $b = 'b', string $p = 'p'): string
{
    return "COALESCE(NULLIF({$b}.print_target, 0), NULLIF({$p}.print_target, 0), 0)";
}

function ff_finance_buchung_timestamp_expr(string $bu = 'bu'): string
{
    return "COALESCE(TIMESTAMP({$bu}.datum, TIME({$bu}.created_at)), {$bu}.created_at)";
}

/**
 * @return array{entnahmen:float,zuzahlungen:float}
 */
function ff_kassen_session_movements_sum(mysqli $conn, int $sessionId): array
{
    $ent = 0.0;
    $zuz = 0.0;
    $res = mysqli_query(
        $conn,
        'SELECT typ, COALESCE(SUM(betrag), 0) AS s FROM kassen_bewegungen WHERE session_id = ' . (int) $sessionId . ' GROUP BY typ'
    );
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        if (($r['typ'] ?? '') === 'entnahme') {
            $ent = (float) $r['s'];
        } else {
            $zuz = (float) $r['s'];
        }
    }

    return ['entnahmen' => $ent, 'zuzahlungen' => $zuz];
}

function ff_finance_print_target_join_sql(string $b = 'b', string $p = 'p'): string
{
    $effPt = ff_finance_effective_print_target_expr($b, $p);

    return "LEFT JOIN print_targets pt ON pt.print_target = {$effPt}";
}

/** Verkauf ohne Zuordnung zu einem Finanzbereich (Druckziel nicht gemappt). */
function ff_finance_verkauf_unzugeordnet_where(): string
{
    return ' AND (pt.finance_bereich_id IS NULL OR pt.finance_bereich_id = 0) ';
}

/** Virtuelle Bereichs-ID für Auswertung „Kellner / Direktverkauf“ (kein Finanzbereich). */
function ff_finance_bereich_id_kellner_direkt(): int
{
    return -2;
}

function ff_finance_label_kellner_direkt(): string
{
    return 'Kellner / Direktverkauf';
}

/**
 * Druckziel-Namen, deren Verkauf immer der Kategorie Kellner/Direktverkauf zugeordnet ist.
 *
 * @return list<string>
 */
function ff_finance_print_target_kellner_direkt_fixed_names(): array
{
    return ['küche', 'kueche', 'schank', 'feuerflecken', 'kassa', 'kasse'];
}

function ff_finance_print_target_normalize_name(string $name): string
{
    $n = mb_strtolower(trim($name), 'UTF-8');

    return str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $n);
}

function ff_finance_print_target_is_kellner_direkt_fixed(string $name): bool
{
    $norm = ff_finance_print_target_normalize_name($name);
    foreach (ff_finance_print_target_kellner_direkt_fixed_names() as $fixed) {
        if ($norm === ff_finance_print_target_normalize_name($fixed)) {
            return true;
        }
    }

    return false;
}

/** Entfernt fälschliche Finanzbereich-Zuordnung an festen Druckzielen. */
function ff_finance_sql_exclude_fixed_print_targets(mysqli $conn, string $ptAlias = 'pt'): string
{
    $in = [];
    foreach (ff_finance_print_target_kellner_direkt_fixed_names() as $n) {
        $in[] = "'" . mysqli_real_escape_string($conn, mb_strtolower(trim($n), 'UTF-8')) . "'";
    }
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $ptAlias) ?: 'pt';

    return " AND ({$alias}.name IS NULL OR LOWER(TRIM({$alias}.name)) NOT IN (" . implode(',', $in) . ')) ';
}

function ff_finance_ensure_kellner_direkt_print_targets(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ff_finance_ensure_schema($conn);

    $res = mysqli_query($conn, 'SELECT print_target, name, finance_bereich_id FROM print_targets');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        if (!ff_finance_print_target_is_kellner_direkt_fixed((string) ($r['name'] ?? ''))) {
            continue;
        }
        if (!empty($r['finance_bereich_id'])) {
            $pt = (int) $r['print_target'];
            mysqli_query($conn, 'UPDATE print_targets SET finance_bereich_id = NULL WHERE print_target = ' . $pt . ' LIMIT 1');
        }
    }
}

/**
 * Unzugeordneter Verkauf, der Kellner-Abrechnung oder Direktverkauf (Tisch 999999) ist.
 */
function ff_finance_verkauf_kellner_direkt_where(string $b = 'b'): string
{
    return " AND ({$b}.tischnummer = 999999 OR ({$b}.kellnerZahlung IS NOT NULL AND TRIM({$b}.kellnerZahlung) <> '')) ";
}

/** Direktverkauf-Anteil (Tisch 999999, ohne Finanzbereich am Druckziel). */
function ff_finance_verkauf_direktverkauf_where(string $b = 'b'): string
{
    return " AND {$b}.tischnummer = 999999 ";
}

/** Kellner-Anteil (Kellner-Kasse, nicht Direktverkauf-Tisch). */
function ff_finance_verkauf_kellner_anteil_where(string $b = 'b'): string
{
    return " AND {$b}.tischnummer <> 999999 AND {$b}.kellnerZahlung IS NOT NULL AND TRIM({$b}.kellnerZahlung) <> '' ";
}

/**
 * @return array{kellner:float,direktverkauf:float,summe:float}
 */
function ff_finance_kellner_direkt_breakdown(mysqli $conn, ?array $range, string $extraBezDateSql = ''): array
{
    $base = ff_finance_verkauf_unzugeordnet_where();
    $kellner = ff_finance_sum_verkauf($conn, $base . ff_finance_verkauf_kellner_anteil_where(), $range, $extraBezDateSql);
    $direkt = ff_finance_sum_verkauf($conn, $base . ff_finance_verkauf_direktverkauf_where(), $range, $extraBezDateSql);

    return [
        'kellner' => $kellner,
        'direktverkauf' => $direkt,
        'summe' => round($kellner + $direkt, 2),
    ];
}

/**
 * Kellner-Kasse: Abrechnungs-Summen aus Bestellzeilen (nicht nur Abrechnungsprotokoll).
 * „Kachel“ = wie verkauf_kellner_anteil (ohne Finanzbereich am Druckziel).
 *
 * @return array{
 *   abgerechnet_alle:float,
 *   offen_alle:float,
 *   abgerechnet_kachel:float,
 *   offen_kachel:float,
 *   zugeordnet_finanzbereich:float,
 *   abgerechnet_protokoll:float,
 *   protokoll_storno_nach_abrechnung:float
 * }
 */
/** JOIN: nur gültige Kellner-Abrechnungsprotokolle (nicht aufgehoben). */
function ff_finance_kellner_settlement_join_sql(mysqli $conn, string $b = 'b', ?array $range = null): string
{
    $scope = "(ks.settlement_scope = 'kellner' OR ks.settlement_scope IS NULL OR ks.settlement_scope = '')";
    $void = "(ks.voided_at IS NULL OR ks.voided_at = '0000-00-00 00:00:00')";
    $when = '';
    if ($range !== null) {
        $vonEsc = mysqli_real_escape_string($conn, $range['von_sql']);
        $bisEsc = mysqli_real_escape_string($conn, $range['bis_sql']);
        $when = " AND ks.created_at >= '{$vonEsc}' AND ks.created_at <= '{$bisEsc}' ";
    }

    return "INNER JOIN kellner_settlements ks ON ks.id = {$b}.settlement_id AND {$void} AND {$scope}{$when}";
}

/**
 * Abgleich Protokoll-Soll vs. noch gültige abgerechnete Zeilen (gleiche Logik wie Tab Kellner).
 *
 * @return array{protokoll_soll:float,zeilen_gueltig:float,storno_nach_abrechnung:float,protokoll_zeilen_diff:float}
 */
function ff_finance_kellner_protokoll_abgleich(mysqli $conn, ?array $range): array
{
    ff_finance_ensure_schema($conn);
    ff_schreibaus_ensure_column($conn);

    $fest = ff_finance_fest_filter_sql($conn, 'b');
    $whereSettle = '';
    if ($range !== null) {
        $vonEsc = mysqli_real_escape_string($conn, $range['von_sql']);
        $bisEsc = mysqli_real_escape_string($conn, $range['bis_sql']);
        $whereSettle = " AND ks.created_at >= '{$vonEsc}' AND ks.created_at <= '{$bisEsc}' ";
    }

    $amt = ff_finance_order_amount_expr('b', 'p');
    $paidKel = ff_finance_order_paid_base_sql('b') . $fest
        . " AND b.tischnummer <> 999999 AND b.kellnerZahlung IS NOT NULL AND TRIM(b.kellnerZahlung) <> ''";

    $protokollSoll = 0.0;
    $zeilenGueltig = 0.0;
    $stornoNach = 0.0;
    $res = mysqli_query(
        $conn,
        'SELECT ks.id, ks.umsatz_soll FROM kellner_settlements ks WHERE ks.voided_at IS NULL '
        . " AND (ks.settlement_scope = 'kellner' OR ks.settlement_scope IS NULL OR ks.settlement_scope = '') "
        . $whereSettle
    );
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $sid = (int) ($row['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $soll = (float) ($row['umsatz_soll'] ?? 0);
        $protokollSoll += $soll;
        $r2 = mysqli_query(
            $conn,
            "SELECT COALESCE(SUM({$amt}), 0) AS s FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            WHERE b.settlement_id = {$sid} AND {$paidKel}"
        );
        $aktiv = ($r2 && ($x = mysqli_fetch_assoc($r2))) ? (float) ($x['s'] ?? 0) : 0.0;
        $zeilenGueltig += $aktiv;
        $stornoNach += max(0, round($soll - $aktiv, 2));
    }

    $protokollSoll = round($protokollSoll, 2);
    $zeilenGueltig = round($zeilenGueltig, 2);
    $stornoNach = round($stornoNach, 2);

    return [
        'protokoll_soll' => $protokollSoll,
        'zeilen_gueltig' => $zeilenGueltig,
        'storno_nach_abrechnung' => $stornoNach,
        'protokoll_zeilen_diff' => round($protokollSoll - $zeilenGueltig - $stornoNach, 2),
    ];
}

function ff_finance_kellner_protokoll_storno_nach_abrechnung(mysqli $conn, ?array $range): float
{
    $ab = ff_finance_kellner_protokoll_abgleich($conn, $range);

    return (float) ($ab['storno_nach_abrechnung'] ?? 0);
}

function ff_finance_kellner_abrechnung_summary(mysqli $conn, ?array $range, float $abgerechnetProtokoll = 0.0): array
{
    ff_finance_ensure_schema($conn);
    ff_schreibaus_ensure_column($conn);

    $fest = ff_finance_fest_filter_sql($conn, 'b');
    $whereBez = '';
    if ($range !== null) {
        $vonEsc = mysqli_real_escape_string($conn, $range['von_sql']);
        $bisEsc = mysqli_real_escape_string($conn, $range['bis_sql']);
        $whereBez = " AND b.timestampBezahlung >= '{$vonEsc}' AND b.timestampBezahlung <= '{$bisEsc}' ";
    }

    $joinPt = ff_finance_print_target_join_sql('b', 'p');
    $amt = ff_finance_order_amount_expr('b', 'p');
    $paid = ff_finance_order_paid_base_sql('b') . $fest;
    $baseKel = "{$paid} AND b.tischnummer <> 999999 AND b.kellnerZahlung IS NOT NULL AND TRIM(b.kellnerZahlung) <> ''{$whereBez}";
    $unz = ff_finance_verkauf_unzugeordnet_where();
    $zugeordnetPt = ' AND pt.finance_bereich_id IS NOT NULL AND pt.finance_bereich_id > 0 ';

    $sumKel = static function (mysqli $c, string $join, string $amount, string $base, string $extra): float {
        $sql = "SELECT COALESCE(SUM({$amount}), 0) AS s FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            {$join}
            WHERE {$base} {$extra}";
        $res = mysqli_query($c, $sql);

        return ($res && ($r = mysqli_fetch_assoc($res))) ? round((float) ($r['s'] ?? 0), 2) : 0.0;
    };

    $ksJoin = ff_finance_kellner_settlement_join_sql($conn, 'b', $range);
    $abgleich = ff_finance_kellner_protokoll_abgleich($conn, $range);
    $protokollStorno = (float) ($abgleich['storno_nach_abrechnung'] ?? 0);
    $zeilenAnProtokoll = (float) ($abgleich['zeilen_gueltig'] ?? 0);
    $protokollSoll = (float) ($abgleich['protokoll_soll'] ?? 0);
    $storno = ff_finance_kellner_storno_summen($conn, $range);
    $stornoZeilen = ff_finance_storno_nach_abrechnung_zeilen($conn, $range, 'kellner');

    return [
        'abgerechnet_alle' => $zeilenAnProtokoll,
        'abgerechnet_zeilen_gueltig' => $zeilenAnProtokoll,
        'offen_alle' => $sumKel($conn, $joinPt, $amt, $baseKel, 'AND b.settlement_id IS NULL'),
        'abgerechnet_kachel' => $sumKel($conn, $joinPt . ' ' . $ksJoin, $amt, $baseKel, $unz),
        'offen_kachel' => $sumKel($conn, $joinPt, $amt, $baseKel, 'AND b.settlement_id IS NULL' . $unz),
        'zugeordnet_finanzbereich' => $sumKel($conn, $joinPt, $amt, $baseKel, $zugeordnetPt),
        'abgerechnet_protokoll' => $protokollSoll > 0 ? $protokollSoll : round($abgerechnetProtokoll, 2),
        'protokoll_soll_abgleich' => $protokollSoll,
        'protokoll_zeilen_diff' => (float) ($abgleich['protokoll_zeilen_diff'] ?? 0),
        'protokoll_storno_nach_abrechnung' => $protokollStorno,
        'storno_geloescht_summe' => (float) ($storno['geloescht_summe'] ?? 0),
        'storno_nach_abrechnung_summe' => (float) ($storno['nach_abrechnung_summe'] ?? 0),
        'storno_offen_summe' => (float) ($storno['offen_summe'] ?? 0),
        'storno_geloescht_anzahl' => (int) ($storno['geloescht_anzahl'] ?? 0),
        'storno_nach_abrechnung_zeilen' => $stornoZeilen,
    ];
}

/**
 * Storno-Positionen Kellner-Kasse (Tisch ≠ 999999), ausgeschlossen in abgerechnet/offen-Summen.
 *
 * @return array{geloescht_summe:float,geloescht_anzahl:int,nach_abrechnung_summe:float,offen_summe:float}
 */
function ff_finance_kellner_storno_summen(mysqli $conn, ?array $range): array
{
    ff_finance_ensure_schema($conn);
    ff_schreibaus_ensure_column($conn);

    $fest = ff_finance_fest_filter_sql($conn, 'b');
    $whereBez = '';
    if ($range !== null) {
        $vonEsc = mysqli_real_escape_string($conn, $range['von_sql']);
        $bisEsc = mysqli_real_escape_string($conn, $range['bis_sql']);
        $whereBez = " AND b.timestampBezahlung >= '{$vonEsc}' AND b.timestampBezahlung <= '{$bisEsc}' ";
    }

    $amt = ff_finance_order_amount_expr('b', 'p');
    $scope = "b.tischnummer <> 999999 AND b.kellnerZahlung IS NOT NULL AND TRIM(b.kellnerZahlung) <> ''{$fest}{$whereBez}";
    $paidStorno = "b.`delete` = 1 AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung <> '0000-00-00 00:00:00'"
        . ' AND COALESCE(b.is_gratis, 0) = 0 AND COALESCE(b.schreibaus, 0) = 0';

    $fetch = static function (mysqli $c, string $amount, string $base, string $extra): array {
        $sql = "SELECT COALESCE(SUM({$amount}), 0) AS s, COUNT(*) AS c FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            WHERE {$base} {$extra}";
        $res = mysqli_query($c, $sql);

        return ($res && ($r = mysqli_fetch_assoc($res)))
            ? ['sum' => round((float) ($r['s'] ?? 0), 2), 'cnt' => (int) ($r['c'] ?? 0)]
            : ['sum' => 0.0, 'cnt' => 0];
    };

    $all = $fetch($conn, $amt, $paidStorno . " AND {$scope}", '');
    $nachAbg = $fetch($conn, $amt, $paidStorno . " AND {$scope}", 'AND b.settlement_id IS NOT NULL');
    $offen = $fetch($conn, $amt, $paidStorno . " AND {$scope}", 'AND b.settlement_id IS NULL');

    return [
        'geloescht_summe' => $all['sum'],
        'geloescht_anzahl' => $all['cnt'],
        'nach_abrechnung_summe' => $nachAbg['sum'],
        'offen_summe' => $offen['sum'],
    ];
}

/**
 * Stornierte Zeilen, die noch am Abrechnungsblatt hängen (settlement_id erhalten).
 *
 * @return list<array{rowid:int,settlement_id:int,kellner_login:string,kellner_label:string,name:string,betrag:float,bezahlt:string,abrechnung_am:string,tischnummer:int}>
 */
function ff_finance_storno_nach_abrechnung_zeilen(mysqli $conn, ?array $range, string $scope = 'kellner'): array
{
    ff_finance_ensure_schema($conn);
    ff_schreibaus_ensure_column($conn);

    $fest = ff_finance_fest_filter_sql($conn, 'b');
    $whereKs = '';
    if ($range !== null) {
        $vonEsc = mysqli_real_escape_string($conn, $range['von_sql']);
        $bisEsc = mysqli_real_escape_string($conn, $range['bis_sql']);
        $whereKs = " AND ks.created_at >= '{$vonEsc}' AND ks.created_at <= '{$bisEsc}' ";
    }
    $tisch = $scope === 'dv' ? ' AND b.tischnummer = 999999 ' : ' AND b.tischnummer <> 999999 ';
    $scopeKs = $scope === 'dv'
        ? "ks.settlement_scope = 'direktverkauf'"
        : "(ks.settlement_scope = 'kellner' OR ks.settlement_scope IS NULL OR ks.settlement_scope = '')";
    $amt = ff_finance_order_amount_expr('b', 'p');
    $sql = "SELECT b.rowid, b.settlement_id, b.kellnerZahlung, b.timestampBezahlung, b.tischnummer,
            p.Positionsname, ks.created_at AS abrechnung_am, {$amt} AS line_amt
        FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position
        INNER JOIN kellner_settlements ks ON ks.id = b.settlement_id
            AND (ks.voided_at IS NULL OR ks.voided_at = '0000-00-00 00:00:00') AND {$scopeKs}
        WHERE b.`delete` = 1 AND b.settlement_id IS NOT NULL
        AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung <> '0000-00-00 00:00:00'
        AND COALESCE(b.is_gratis, 0) = 0 AND COALESCE(b.schreibaus, 0) = 0
        {$fest}{$tisch}{$whereKs}
        ORDER BY ks.created_at DESC, b.timestampBezahlung DESC
        LIMIT 100";
    $rows = [];
    $res = mysqli_query($conn, $sql);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $login = (string) ($r['kellnerZahlung'] ?? '');
        $rows[] = [
            'rowid' => (int) ($r['rowid'] ?? 0),
            'settlement_id' => (int) ($r['settlement_id'] ?? 0),
            'kellner_login' => $login,
            'kellner_label' => ff_finance_kellner_label($conn, $login),
            'name' => (string) ($r['Positionsname'] ?? ''),
            'betrag' => round((float) ($r['line_amt'] ?? 0), 2),
            'bezahlt' => (string) ($r['timestampBezahlung'] ?? ''),
            'abrechnung_am' => (string) ($r['abrechnung_am'] ?? ''),
            'tischnummer' => (int) ($r['tischnummer'] ?? 0),
        ];
    }

    return $rows;
}

/** Unzugeordnet ohne Kellner / Direktverkauf (z. B. Tischverkauf ohne Druckziel-Bereich). */
function ff_finance_verkauf_echt_unzugeordnet_where(string $b = 'b'): string
{
    return ff_finance_verkauf_unzugeordnet_where()
        . " AND NOT ({$b}.tischnummer = 999999 OR ({$b}.kellnerZahlung IS NOT NULL AND TRIM({$b}.kellnerZahlung) <> '')) ";
}

/**
 * @param string $extraBezDateSql Zusatz auf b.timestampBezahlung
 */
function ff_finance_sum_verkauf(
    mysqli $conn,
    string $whereExtra,
    ?array $range,
    string $extraBezDateSql = ''
): float {
    ff_finance_ensure_schema($conn);
    ff_schreibaus_ensure_column($conn);

    $fest = ff_finance_fest_filter_sql($conn, 'b');
    $whereBez = $extraBezDateSql;
    if ($range !== null) {
        $vonEsc = mysqli_real_escape_string($conn, $range['von_sql']);
        $bisEsc = mysqli_real_escape_string($conn, $range['bis_sql']);
        $whereBez .= " AND b.timestampBezahlung >= '{$vonEsc}' AND b.timestampBezahlung <= '{$bisEsc}' ";
    }

    $joinPt = ff_finance_print_target_join_sql('b', 'p');
    $amt = ff_finance_order_amount_expr('b', 'p');
    $basePaid = ff_finance_order_paid_base_sql('b') . $fest;

    $res = mysqli_query(
        $conn,
        "SELECT COALESCE(SUM({$amt}), 0) AS s FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position
        {$joinPt}
        WHERE {$basePaid}{$whereBez} {$whereExtra}"
    );
    if ($res && ($r = mysqli_fetch_assoc($res))) {
        return round((float) $r['s'], 2);
    }

    return 0.0;
}

/**
 * Umsatz-Kennzahlen ohne Doppelzählung: Verkauf je Druckziel-Bereich nur in Bereichen, Rest „unzugeordnet“.
 *
 * @param string $extraBezDateSql Zusatz auf b.timestampBezahlung (z. B. DATE-Filter aus gewinn_api)
 * @return array<string,mixed>
 */
function ff_finance_revenue_summary(mysqli $conn, ?array $range, string $extraBezDateSql = ''): array
{
    ff_finance_ensure_schema($conn);
    ff_finance_ensure_kellner_direkt_print_targets($conn);
    ff_schreibaus_ensure_column($conn);

    $fest = ff_finance_fest_filter_sql($conn, 'b');
    $whereBez = $extraBezDateSql;
    $whereKasse = '';
    if ($range !== null) {
        $vonEsc = mysqli_real_escape_string($conn, $range['von_sql']);
        $bisEsc = mysqli_real_escape_string($conn, $range['bis_sql']);
        $whereBez .= " AND b.timestampBezahlung >= '{$vonEsc}' AND b.timestampBezahlung <= '{$bisEsc}' ";
        $whereKasse = " AND s.closed_at >= '{$vonEsc}' AND s.closed_at <= '{$bisEsc}' ";
    }

    $joinPt = ff_finance_print_target_join_sql('b', 'p');
    $amt = ff_finance_order_amount_expr('b', 'p');
    $basePaid = ff_finance_order_paid_base_sql('b') . $fest;

    $verkaufGesamt = 0.0;
    $resG = mysqli_query(
        $conn,
        "SELECT COALESCE(SUM({$amt}), 0) AS s FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position
        WHERE {$basePaid}{$whereBez}"
    );
    if ($resG && ($rg = mysqli_fetch_assoc($resG))) {
        $verkaufGesamt = (float) $rg['s'];
    }

    $verkaufUnzugeordnetGesamt = ff_finance_sum_verkauf(
        $conn,
        ff_finance_verkauf_unzugeordnet_where(),
        $range,
        $extraBezDateSql
    );
    $kelDirektBreakdown = ff_finance_kellner_direkt_breakdown($conn, $range, $extraBezDateSql);
    $verkaufKellnerDirekt = (float) ($kelDirektBreakdown['summe'] ?? 0);
    $verkaufKellnerAnteil = (float) ($kelDirektBreakdown['kellner'] ?? 0);
    $verkaufDirektAnteil = (float) ($kelDirektBreakdown['direktverkauf'] ?? 0);
    $verkaufEchtUnzugeordnet = ff_finance_sum_verkauf(
        $conn,
        ff_finance_verkauf_echt_unzugeordnet_where(),
        $range,
        $extraBezDateSql
    );
    $verkaufUnzugeordnet = $verkaufUnzugeordnetGesamt;

    $bereicheUmsatz = [];
    $umsatzBereicheSumme = 0.0;
    foreach (ff_finance_list_bereiche($conn, true) as $br) {
        $m = ff_finance_metrics_bereich($conn, (int) $br['id'], $range);
        $brSum = (float) ($m['umsatz_gesamt'] ?? 0);
        $kontrolleOnly = !empty($br['kontrolle_only']);
        if (!$kontrolleOnly) {
            $umsatzBereicheSumme += $brSum;
        }
        $bereicheUmsatz[] = [
            'id' => (int) $br['id'],
            'name' => (string) $br['name'],
            'kontrolle_only' => $kontrolleOnly ? 1 : 0,
            'kassen_umsatz' => (float) ($m['kassen_umsatz'] ?? 0),
            'verkauf_umsatz' => (float) ($m['verkauf_umsatz'] ?? 0),
            'umsatz_gesamt' => $brSum,
        ];
    }

    $umsatzGesamtKombiniert = round($verkaufKellnerDirekt + $verkaufEchtUnzugeordnet + $umsatzBereicheSumme, 2);

    return [
        'verkauf_gesamt' => round($verkaufGesamt, 2),
        'verkauf_unzugeordnet' => round($verkaufUnzugeordnet, 2),
        'verkauf_kellner_direkt' => round($verkaufKellnerDirekt, 2),
        'verkauf_kellner_anteil' => round($verkaufKellnerAnteil, 2),
        'verkauf_direktverkauf_anteil' => round($verkaufDirektAnteil, 2),
        'verkauf_echt_unzugeordnet' => round($verkaufEchtUnzugeordnet, 2),
        'verkauf_bereiche_summe' => round($verkaufGesamt - $verkaufUnzugeordnet, 2),
        'umsatz_bereiche_summe' => round($umsatzBereicheSumme, 2),
        'umsatz_gesamt_kombiniert' => $umsatzGesamtKombiniert,
        'bereiche_umsatz' => $bereicheUmsatz,
    ];
}

/**
 * @return array<int, array{id:int,name:string,is_active:int,kontrolle_only:int}>
 */
function ff_finance_list_bereiche(mysqli $conn, bool $activeOnly = false): array
{
    ff_finance_ensure_schema($conn);
    $sql = 'SELECT id, name, is_active, COALESCE(kontrolle_only, 0) AS kontrolle_only FROM kassen_bereiche';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY name ASC';
    $rows = [];
    $res = mysqli_query($conn, $sql);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'is_active' => (int) $r['is_active'],
            'kontrolle_only' => (int) ($r['kontrolle_only'] ?? 0),
        ];
    }
    return $rows;
}

/**
 * Finanzbereich löschen (nur ohne offene Kasse und ohne Abschluss-Historie).
 *
 * @return array{ok:bool,message?:string,error?:string}
 */
function ff_finance_delete_bereich(mysqli $conn, int $bereichId): array
{
    ff_finance_ensure_schema($conn);
    if ($bereichId <= 0) {
        return ['ok' => false, 'error' => 'bad_id', 'message' => 'Ungültiger Bereich.'];
    }

    $name = '';
    foreach (ff_finance_list_bereiche($conn, false) as $b) {
        if ((int) $b['id'] === $bereichId) {
            $name = (string) $b['name'];
            break;
        }
    }
    if ($name === '') {
        return ['ok' => false, 'error' => 'not_found', 'message' => 'Finanzbereich nicht gefunden.'];
    }

    $chkOpen = mysqli_query(
        $conn,
        "SELECT id FROM kassen_sessions WHERE bereich_id = {$bereichId} AND status = 'open' LIMIT 1"
    );
    if ($chkOpen && mysqli_num_rows($chkOpen) > 0) {
        return [
            'ok' => false,
            'error' => 'open_session',
            'message' => 'Bereich „' . $name . '“ hat eine offene Kasse — zuerst abschließen.',
        ];
    }

    $chkHist = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM kassen_sessions WHERE bereich_id = ' . $bereichId);
    $histCnt = 0;
    if ($chkHist && ($rh = mysqli_fetch_assoc($chkHist))) {
        $histCnt = (int) ($rh['c'] ?? 0);
    }
    if ($histCnt > 0) {
        return [
            'ok' => false,
            'error' => 'has_sessions',
            'message' => 'Bereich „' . $name . '“ hat ' . $histCnt . ' Kassen-Abschluss/Abschlüsse in der Historie und kann nicht gelöscht werden.',
        ];
    }

    $stPt = mysqli_prepare($conn, 'UPDATE print_targets SET finance_bereich_id = NULL WHERE finance_bereich_id = ?');
    if ($stPt) {
        mysqli_stmt_bind_param($stPt, 'i', $bereichId);
        mysqli_stmt_execute($stPt);
        mysqli_stmt_close($stPt);
    }

    $tbB = @mysqli_query($conn, "SHOW TABLES LIKE 'buchungen'");
    if ($tbB && mysqli_num_rows($tbB) > 0) {
        $cB = @mysqli_query($conn, "SHOW COLUMNS FROM buchungen LIKE 'bereich_id'");
        if ($cB && mysqli_num_rows($cB) > 0) {
            mysqli_query($conn, 'UPDATE buchungen SET bereich_id = NULL WHERE bereich_id = ' . $bereichId);
        }
        if ($cB) {
            mysqli_free_result($cB);
        }
        mysqli_free_result($tbB);
    }

    $stDel = mysqli_prepare($conn, 'DELETE FROM kassen_bereiche WHERE id = ? LIMIT 1');
    if (!$stDel) {
        return ['ok' => false, 'error' => 'db', 'message' => 'Datenbankfehler beim Löschen.'];
    }
    mysqli_stmt_bind_param($stDel, 'i', $bereichId);
    $ok = mysqli_stmt_execute($stDel);
    $aff = mysqli_affected_rows($conn);
    mysqli_stmt_close($stDel);

    if (!$ok || $aff < 1) {
        return ['ok' => false, 'error' => 'delete_failed', 'message' => 'Löschen fehlgeschlagen.'];
    }

    return ['ok' => true, 'message' => 'Finanzbereich „' . $name . '“ wurde gelöscht.'];
}

/**
 * @param array{von_sql:string,bis_sql:string,has_time:bool}|null $range
 * @return array<string, float|int>
 */
function ff_finance_metrics_bereich(mysqli $conn, int $bereichId, ?array $range): array
{
    ff_finance_ensure_schema($conn);
    ff_schreibaus_ensure_column($conn);

    $vonEsc = '';
    $bisEsc = '';
    $whereBez = '';
    $whereKasse = '';
    $whereBuch = '';

    $fest = ff_finance_fest_filter_sql($conn, 'b');
    if ($range !== null) {
        $vonEsc = mysqli_real_escape_string($conn, $range['von_sql']);
        $bisEsc = mysqli_real_escape_string($conn, $range['bis_sql']);
        $whereBez = " AND b.timestampBezahlung >= '{$vonEsc}' AND b.timestampBezahlung <= '{$bisEsc}' ";
        $whereKasse = " AND s.closed_at >= '{$vonEsc}' AND s.closed_at <= '{$bisEsc}' ";
        $bt = ff_finance_buchung_timestamp_expr('bu');
        $whereBuch = " AND {$bt} >= '{$vonEsc}' AND {$bt} <= '{$bisEsc}' ";
    }

    $kassenUmsatz = 0.0;
    $kasseSql = "SELECT COALESCE(SUM(s.revenue_amount), 0) AS s FROM kassen_sessions s
        WHERE s.status = 'closed' {$whereKasse}";
    if ($bereichId > 0) {
        $kasseSql .= ' AND s.bereich_id = ' . $bereichId;
    } elseif ($bereichId === 0 || $bereichId === ff_finance_bereich_id_kellner_direkt()) {
        $kasseSql .= ' AND 1=0';
    }
    $resK = mysqli_query($conn, $kasseSql);
    if ($resK && ($rk = mysqli_fetch_assoc($resK))) {
        $kassenUmsatz = (float) $rk['s'];
    }

    $effPt = ff_finance_effective_print_target_expr('b', 'p');
    $joinPt = "LEFT JOIN print_targets pt ON pt.print_target = {$effPt}";
    $wherePt = '';
    if ($bereichId > 0) {
        $wherePt = ' AND pt.finance_bereich_id = ' . $bereichId . ff_finance_sql_exclude_fixed_print_targets($conn, 'pt');
    } elseif ($bereichId === ff_finance_bereich_id_kellner_direkt()) {
        $wherePt = ff_finance_verkauf_unzugeordnet_where() . ff_finance_verkauf_kellner_direkt_where();
    } elseif ($bereichId === 0) {
        $wherePt = ff_finance_verkauf_echt_unzugeordnet_where();
    }

    $verkauf = 0.0;
    $amt = ff_finance_order_amount_expr('b', 'p');
    $sqlU = "SELECT COALESCE(SUM({$amt}), 0) AS s
        FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position
        {$joinPt}
        WHERE " . ff_finance_order_paid_base_sql('b') . "{$fest}
        {$whereBez} {$wherePt}";
    $resU = mysqli_query($conn, $sqlU);
    if ($resU && ($ru = mysqli_fetch_assoc($resU))) {
        $verkauf = (float) $ru['s'];
    }

    $variableKosten = 0.0;
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM positionen LIKE 'selbstkosten'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        $sqlV = "SELECT COALESCE(SUM(COALESCE(p.selbstkosten, 0)), 0) AS s
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            {$joinPt}
            WHERE " . ff_finance_order_paid_base_sql('b') . "{$fest}
            {$whereBez} {$wherePt}";
        $resV = mysqli_query($conn, $sqlV);
        if ($resV && ($rv = mysqli_fetch_assoc($resV))) {
            $variableKosten = (float) $rv['s'];
        }
    }

    $whereBuchBereich = '';
    if ($bereichId > 0) {
        $whereBuchBereich = ' AND bu.bereich_id = ' . $bereichId;
    } elseif ($bereichId === 0) {
        $whereBuchBereich = ' AND (bu.bereich_id IS NULL OR bu.bereich_id = 0)';
    } elseif ($bereichId === ff_finance_bereich_id_kellner_direkt()) {
        // Fixe Buchungen gehören immer einem Finanzbereich — nicht zu Kellner/Direktverkauf.
        $whereBuchBereich = ' AND 1=0';
    }

    $fixEinnahmen = 0.0;
    $fixAusgaben = 0.0;
    $resE = mysqli_query($conn, "SELECT COALESCE(SUM(betrag),0) AS s FROM buchungen bu WHERE typ='einnahme' {$whereBuch} {$whereBuchBereich}");
    $resA = mysqli_query($conn, "SELECT COALESCE(SUM(betrag),0) AS s FROM buchungen bu WHERE typ='ausgabe' {$whereBuch} {$whereBuchBereich}");
    if ($resE && ($re = mysqli_fetch_assoc($resE))) {
        $fixEinnahmen = (float) $re['s'];
    }
    if ($resA && ($ra = mysqli_fetch_assoc($resA))) {
        $fixAusgaben = (float) $ra['s'];
    }

    $umsatzGesamt = round($kassenUmsatz + $verkauf, 2);
    $gewinn = round($umsatzGesamt + $fixEinnahmen - $variableKosten - $fixAusgaben, 2);

    return [
        'kassen_umsatz' => round($kassenUmsatz, 2),
        'verkauf_umsatz' => round($verkauf, 2),
        'umsatz_gesamt' => $umsatzGesamt,
        'variable_kosten' => round($variableKosten, 2),
        'fixe_einnahmen' => round($fixEinnahmen, 2),
        'fixe_ausgaben' => round($fixAusgaben, 2),
        'gewinn' => $gewinn,
    ];
}

/**
 * @param array{von_sql:string,bis_sql:string,has_time:bool}|null $range
 */
function ff_finance_evaluate_all_bereiche(mysqli $conn, ?array $range): array
{
    $bereiche = ff_finance_list_bereiche($conn, false);
    $rows = [];
    foreach ($bereiche as $b) {
        $m = ff_finance_metrics_bereich($conn, (int) $b['id'], $range);
        $rows[] = array_merge(['bereich_id' => (int) $b['id'], 'name' => $b['name']], $m);
    }

    $kellnerDirekt = ff_finance_metrics_bereich($conn, ff_finance_bereich_id_kellner_direkt(), $range);
    $unassigned = ff_finance_metrics_bereich($conn, 0, $range);
    $gesamt = ff_finance_metrics_bereich($conn, -1, $range);

    return [
        'bereiche' => $rows,
        'kellner_direktverkauf' => $kellnerDirekt,
        'unzugeordnet' => $unassigned,
        'gesamt' => $gesamt,
    ];
}

/**
 * Abgeschlossene Kassen-Sessions eines Bereichs inkl. Bewegungen (für Bereichsauswertung).
 *
 * @param array{von_sql:string,bis_sql:string,has_time:bool}|null $range
 * @return list<array<string,mixed>>
 */
function ff_finance_kassen_sessions_detail(mysqli $conn, int $bereichId, ?array $range): array
{
    if ($bereichId <= 0) {
        return [];
    }
    ff_finance_ensure_schema($conn);

    $whereKasse = '';
    if ($range !== null) {
        $vonEsc = mysqli_real_escape_string($conn, $range['von_sql']);
        $bisEsc = mysqli_real_escape_string($conn, $range['bis_sql']);
        $whereKasse = " AND s.closed_at >= '{$vonEsc}' AND s.closed_at <= '{$bisEsc}' ";
    }

    $out = [];
    $sql = "SELECT s.*, b.name AS bereich_name FROM kassen_sessions s
        JOIN kassen_bereiche b ON b.id = s.bereich_id
        WHERE s.status = 'closed' AND s.bereich_id = " . (int) $bereichId . " {$whereKasse}
        ORDER BY s.closed_at DESC";
    $res = mysqli_query($conn, $sql);
    while ($res && ($s = mysqli_fetch_assoc($res))) {
        $sid = (int) ($s['id'] ?? 0);
        $entCnt = 0;
        $zuzCnt = 0;
        $entSum = 0.0;
        $zuzSum = 0.0;
        $moves = [];
        if ($sid > 0) {
            $mr = mysqli_query($conn, 'SELECT * FROM kassen_bewegungen WHERE session_id = ' . $sid . ' ORDER BY created_at ASC');
            while ($mr && ($m = mysqli_fetch_assoc($mr))) {
                $moves[] = $m;
                $bet = (float) ($m['betrag'] ?? 0);
                if (($m['typ'] ?? '') === 'entnahme') {
                    $entCnt++;
                    $entSum += $bet;
                } else {
                    $zuzCnt++;
                    $zuzSum += $bet;
                }
            }
        }
        $opening = (float) ($s['opening_amount'] ?? 0);
        $closing = (float) ($s['closing_amount'] ?? 0);
        $revenue = (float) ($s['revenue_amount'] ?? 0);
        if ($revenue === 0.0 && $closing > 0) {
            $revenue = round($closing - $opening + $entSum - $zuzSum, 2);
        }
        $out[] = [
            'session_id' => $sid,
            'bereich_name' => (string) ($s['bereich_name'] ?? ''),
            'opened_at' => (string) ($s['opened_at'] ?? ''),
            'closed_at' => (string) ($s['closed_at'] ?? ''),
            'opening_amount' => round($opening, 2),
            'closing_amount' => round($closing, 2),
            'revenue_amount' => round($revenue, 2),
            'entnahmen_count' => $entCnt,
            'entnahmen_sum' => round($entSum, 2),
            'zuzahlungen_count' => $zuzCnt,
            'zuzahlungen_sum' => round($zuzSum, 2),
            'movements' => $moves,
        ];
    }

    return $out;
}
