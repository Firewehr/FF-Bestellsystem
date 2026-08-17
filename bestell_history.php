<?php
/**
 * Bestell-History / Auswertung – eigenständige Seite MIT Layout.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_favicon_helpers.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_finance_bereich_helpers.php';
require_once __DIR__ . '/include/ff_bestellung_storno.php';
require_once __DIR__ . '/include/ff_bestellung_verschieben.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/menu_list_helpers.php';

mysqli_set_charset($conn, 'utf8mb4');

/** Virtueller Tisch Direktverkauf (Kassa). */
const FF_HIST_TISCH_DIREKTVERKAUF = 999999;

$isAdmin     = !empty($_SESSION['admin']) && (int)$_SESSION['admin'] >= 1;
$currentUser = $_SESSION['user']['username'] ?? '';

$orderSearch = isset($_GET['q']) ? trim($_GET['q']) : '';
$bonSearch = isset($_GET['bon']) ? trim((string) $_GET['bon']) : '';
$dateFrom    = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$timeFrom    = isset($_GET['time_from']) ? trim($_GET['time_from']) : '';
$dateTo      = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$timeTo      = isset($_GET['time_to']) ? trim($_GET['time_to']) : '';
$tableNo     = isset($_GET['table']) ? (int)$_GET['table'] : 0;
$posId       = isset($_GET['pos']) ? (int)$_GET['pos'] : 0;
$typeFilter  = isset($_GET['typ']) ? (int)$_GET['typ'] : 0;
if (!in_array($typeFilter, [0, 1, 2, 3], true)) {
    $typeFilter = 0;
}
$kellnerSel  = isset($_GET['kellner']) ? trim($_GET['kellner']) : '';
$fromTisch = isset($_GET['from']) && (string) $_GET['from'] === 'tisch';
$tischReturn = isset($_GET['return']) ? trim((string) $_GET['return']) : 'historie';
if (!in_array($tischReturn, ['zahlen', 'historie', 'rechnung', 'bestellen'], true)) {
    $tischReturn = 'historie';
}
$tischRequirePay = isset($_GET['require_pay']) && (string) $_GET['require_pay'] === '1';
$histFromCtx = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
$fromDv = $histFromCtx === 'dv';
$fromDruckziel = $histFromCtx === 'druckziel';
$fromStation = in_array($histFromCtx, ['station', 'druckziel', 'dv'], true);
$histReturnPt = (int) ($_GET['pt'] ?? $_GET['print_target'] ?? 0);
$bhBackHref = 'index.php';
$bhBackLabel = 'Zurück zur Startseite';
if ($fromDv) {
    $bhBackHref = 'index.php#DirektHistory';
    $bhBackLabel = 'Zurück zur DV-Historie';
} elseif ($fromDruckziel && $histReturnPt > 0) {
    $bhBackHref = 'index.php#DruckzielHistory_' . $histReturnPt;
    $bhBackLabel = 'Zurück zur Druckziel-Historie';
} elseif ($fromDruckziel) {
    $bhBackHref = 'index.php#DruckzielHistory';
    $bhBackLabel = 'Zurück zur Druckziel-Historie';
}
if ($fromTisch && $tableNo > 0) {
    $bhBackHref = 'index.php?tischnummer=' . $tableNo;
    if ($tischReturn !== '' && $tischReturn !== 'bestellen') {
        $bhBackHref .= '&view=' . rawurlencode($tischReturn);
    }
    $bhBackHref .= '#listTischBestellungen';
    if ($tischReturn === 'zahlen') {
        $bhBackLabel = 'Zurück zum Abrechnen';
    } elseif ($tischReturn === 'historie') {
        $bhBackLabel = 'Zurück zur Tisch-Historie';
    } elseif ($tischReturn === 'rechnung') {
        $bhBackLabel = 'Zurück zur Rechnungsauswahl';
    } else {
        $bhBackLabel = 'Zurück zur Speisekarte';
    }
}
$openOnly    = isset($_GET['open']) && (string)$_GET['open'] === '1';
$pendingOnly = isset($_GET['pending']) && (string)$_GET['pending'] === '1';

$resultsOrder     = [];
$resultsBon       = [];
$resultsOrderAll  = [];
$resultsBonAll    = [];
$orderDeliveryStats = ['total' => 0, 'delivered' => 0, 'fertig_ts' => null];
$resultsFilter = [];
$recentOrders  = [];
$sumFilterTop  = 0.0;
$sumFilterPaid = 0.0;
$sumFilterOpen = 0.0;
$cntFilterOpen = 0;
$sumFilterSettled = 0.0;
$cntFilterSettled = 0;
$sumFilterOtherFest = 0.0;
$cntFilterOtherFest = 0;
$cntFilterPaidRows = 0;
$cntFilterUnpaidRows = 0;
$cntFilterGratisRows = 0;
$cntFilterSchreibausRows = 0;
$histKellnerBreakdown = null;
$histKellnerAlleZeilen = $isAdmin && isset($_GET['alle']) && (string) $_GET['alle'] === '1';
$abrechnungFilter = '';
if ($isAdmin && isset($_GET['abrechnung'])) {
    $af = trim((string) $_GET['abrechnung']);
    if (in_array($af, ['offen', 'abgerechnet', 'ehrengast', 'unbezahlt', 'schreibaus', 'storniert', 'alle'], true)) {
        $abrechnungFilter = $af;
    }
}
$histKellnerAbrechnungView = false;

if (!$isAdmin) {
    $dateFrom = $timeFrom = $dateTo = $timeTo = '';
    $posId    = 0;
    // typ (Speisen/Getränke) darf Kellner nutzen
    if ($currentUser !== '') {
        $kellnerSel = $currentUser;
    }
}

/** Bon-Nr. Direktverkauf (Format TT-XXX, z. B. 28-002). */
function ff_hist_normalize_bon_id(string $raw): string
{
    return ff_direktverkauf_normalize_bon_id($raw);
}

/** Anzeige Best.Nr. oder Bon # mit Link zur Detailansicht. */
function ff_hist_order_ref_cell_html(array $r): string
{
    $tn = (int) ($r['tischnummer'] ?? 0);
    $bon = trim((string) ($r['bon_id'] ?? ''));
    if ($tn === FF_HIST_TISCH_DIREKTVERKAUF && $bon !== '') {
        $href = 'bestell_history.php?bon=' . rawurlencode($bon);

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="badge bg-dark text-decoration-none">Bon #' . htmlspecialchars($bon, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    $onr = (int) ($r['order_nr'] ?? 0);
    if ($onr > 0) {
        $href = 'bestell_history.php?q=' . $onr;

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="badge bg-primary text-decoration-none">' . $onr . '</a>';
    }

    return '—';
}

// Bon-Nr. (28-004) hat Vorrang vor q — (int)"28-004" wäre sonst fälschlich Bestellung 28.
if ($bonSearch !== '') {
    $normBon = ff_hist_normalize_bon_id($bonSearch);
    $bonSearch = $normBon !== '' ? $normBon : trim($bonSearch);
    $orderSearch = '';
} elseif ($orderSearch !== '') {
    $normalizedBon = ff_hist_normalize_bon_id($orderSearch);
    if ($normalizedBon !== '') {
        $bonSearch = $normalizedBon;
        $orderSearch = '';
    }
}

$hasBonSearch = ($bonSearch !== '');
$hasOrderSearch = !$hasBonSearch
    && $orderSearch !== ''
    && ctype_digit($orderSearch)
    && (int) $orderSearch > 0;
$hasFilter = !$hasOrderSearch && !$hasBonSearch && (
    $openOnly ||
    $pendingOnly ||
    $dateFrom !== '' ||
    $dateTo !== '' ||
    $tableNo > 0 ||
    $posId > 0 ||
    $typeFilter > 0 ||
    ($isAdmin && $kellnerSel !== '') ||
    ($isAdmin && $abrechnungFilter === 'storniert')
);

if ($isAdmin && $kellnerSel !== '' && !$histKellnerAlleZeilen && !$openOnly && !$pendingOnly
    && $dateFrom === '' && $dateTo === '' && $tableNo === 0 && $posId === 0 && $typeFilter === 0
    && ($abrechnungFilter === '' || $abrechnungFilter === 'offen')) {
    $histKellnerAbrechnungView = true;
}

function ff_hist_abrechnung_filter_sql(string $mode): string
{
    switch ($mode) {
        case 'offen':
            return ' AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung <> \'0000-00-00 00:00:00\''
                . ' AND COALESCE(b.is_gratis, 0) = 0 AND COALESCE(b.schreibaus, 0) = 0 AND b.settlement_id IS NULL';
        case 'abgerechnet':
            return ' AND b.settlement_id IS NOT NULL';
        case 'ehrengast':
            return ' AND COALESCE(b.is_gratis, 0) = 1';
        case 'unbezahlt':
            return ' AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung = \'0000-00-00 00:00:00\')';
        case 'schreibaus':
            return ' AND COALESCE(b.schreibaus, 0) = 1';
        case 'storniert':
            return '';
        case 'alle':
        default:
            return '';
    }
}

/** Welche Zeilen (aktiv / storniert / beides) für Filter & Kellner-Abrechnungs-Ansicht. */
function ff_hist_delete_base_sql(
    string $abrechnungFilter,
    bool $openOnly,
    bool $pendingOnly,
    bool $histKellnerAbrechnungView,
    bool $histKellnerAlleZeilen
): string {
    if ($openOnly || $pendingOnly || $histKellnerAbrechnungView) {
        return 'b.`delete` = 0';
    }
    if ($abrechnungFilter === 'storniert') {
        return 'b.`delete` = 1';
    }
    if ($abrechnungFilter === 'alle' || $histKellnerAlleZeilen) {
        return '1=1';
    }

    return 'b.`delete` = 0';
}

/** Sortierschlüssel Abrechnungs-Status (0=storniert, 1=unbezahlt … 5=abgerechnet). */
function ff_hist_abrechnung_sort_key(array $r): int
{
    if ((int) ($r['delete'] ?? 0) === 1) {
        return 0;
    }
    if ((int) ($r['settlement_id'] ?? 0) > 0) {
        return 5;
    }
    if ((int) ($r['is_gratis'] ?? 0) === 1) {
        return 3;
    }
    if ((int) ($r['schreibaus'] ?? 0) === 1) {
        return 4;
    }
    $bez = (string) ($r['timestampBezahlung'] ?? '');
    if ($bez === '' || $bez === '0000-00-00 00:00:00') {
        return 1;
    }

    return 2;
}

function ff_hist_type_sql(int $typeFilter): string {
    if ($typeFilter === 1) {
        return 'p.type = 1';
    }
    if ($typeFilter === 2) {
        return 'p.type = 2';
    }
    if ($typeFilter === 3) {
        return '(p.type IS NULL OR p.type NOT IN (1, 2))';
    }

    return '';
}

function ff_hist_type_label(int $type): string {
    switch ($type) {
        case 1: return 'Speise';
        case 2: return 'Getränk';
        default: return 'Sonstig';
    }
}
function ff_hist_eur($v): string {
    return number_format((float)$v, 2, ',', '') . ' €';
}
/** Zeitpunkt, wenn alle Positionen der Bestellung ausgeliefert sind (sonst null). */
function ff_hist_bestellung_fertig_ts(array $rows): ?string {
    if ($rows === []) {
        return null;
    }
    $ts = null;
    foreach ($rows as $r) {
        if ((int)($r['ausgeliefert'] ?? 0) !== 1) {
            return null;
        }
        $ta = $r['timestampAuslieferung'] ?? '';
        if ($ta === '' || $ta === '0000-00-00 00:00:00') {
            return null;
        }
        if ($ts === null || $ta > $ts) {
            $ts = $ta;
        }
    }

    return $ts;
}

/** @return array{total:int, delivered:int, fertig_ts:?string} */
function ff_hist_order_delivery_stats(array $rows): array {
    $total = count($rows);
    $delivered = 0;
    foreach ($rows as $r) {
        if ((int)($r['ausgeliefert'] ?? 0) === 1) {
            $delivered++;
        }
    }

    return [
        'total' => $total,
        'delivered' => $delivered,
        'fertig_ts' => ff_hist_bestellung_fertig_ts($rows),
    ];
}

function ff_hist_payment_mode(mysqli $conn): string {
    $paymentMode = 'after';
    $fres = mysqli_query($conn, 'SELECT payment_mode FROM feste WHERE aktiv=1 LIMIT 1');
    if ($fres && ($frow = mysqli_fetch_assoc($fres))) {
        $paymentMode = ($frow['payment_mode'] === 'instant') ? 'instant' : 'after';
    }

    return $paymentMode;
}

/** Unbezahlt (wie Kasse „offen“). */
function ff_hist_open_sql(string $paymentMode): string {
    if ($paymentMode === 'instant') {
        return "b.timestampBezahlung = '0000-00-00 00:00:00' AND b.bestellt = 1";
    }

    return "b.timestampBezahlung = '0000-00-00 00:00:00'";
}

/** Noch in Küche/Schank-Pipeline (wie Schank-Liste): nicht ausgeliefert – auch wenn schon kassiert. */
function ff_hist_pending_sql(): string {
    return 'b.ausgeliefert = 0';
}

/** Kassierende Person (wie Finanzbereich / kellnerZahlung, Fallback Aufnahme). */
function ff_hist_kasse_kellner_expr(string $b = 'b'): string
{
    return "COALESCE(NULLIF(TRIM({$b}.kellnerZahlung), ''), {$b}.kellner)";
}

/** Zählt zur offenen Kellner-Abrechnung (wie Finanzen). */
function ff_hist_row_counts_abrechnung_umsatz(array $r): bool
{
    if ((int) ($r['delete'] ?? 0) === 1) {
        return false;
    }
    $bez = (string) ($r['timestampBezahlung'] ?? '');
    if ($bez === '' || $bez === '0000-00-00 00:00:00') {
        return false;
    }
    if ((int) ($r['is_gratis'] ?? 0) === 1 || (int) ($r['schreibaus'] ?? 0) === 1) {
        return false;
    }
    if ((int) ($r['settlement_id'] ?? 0) > 0) {
        return false;
    }

    return true;
}

/** Anzeige-Betrag für Kellner-Filter: 0 € wenn nicht Abrechnungs-Umsatz. */
function ff_hist_row_abrechnung_betrag(array $r): float
{
    return ff_hist_row_counts_abrechnung_umsatz($r) ? (float) ($r['betrag'] ?? 0) : 0.0;
}

function ff_hist_betrag_cell_html(array $r, bool $kellnerKassenMode): string
{
    $listen = (float) ($r['betrag'] ?? 0);
    if (!$kellnerKassenMode) {
        return ff_hist_eur($listen);
    }
    $ab = ff_hist_row_abrechnung_betrag($r);
    if (abs($ab) < 0.009) {
        $html = '<span class="text-muted">0,00 €</span>';
        if (abs($listen) > 0.009) {
            $html .= ' <span class="small text-muted" title="Positionspreis, kein Kassen-Umsatz">(' . ff_hist_eur($listen) . ')</span>';
        }

        return $html;
    }

    return ff_hist_eur($ab);
}

function ff_hist_settlement_badge_html(array $r): string
{
    if ((int) ($r['delete'] ?? 0) === 1) {
        $sid = (int) ($r['settlement_id'] ?? 0);
        $hint = $sid > 0 ? ' (war Blatt #' . $sid . ')' : ' (vor Abrechnung)';

        return '<span class="badge bg-secondary">Storniert' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    if ((int) ($r['is_gratis'] ?? 0) === 1) {
        return '<span class="badge bg-info text-dark">Ehrengast / 0 €</span>';
    }
    if ((int) ($r['schreibaus'] ?? 0) === 1) {
        return '<span class="badge bg-light text-dark border">Schreibaus</span>';
    }
    $sid = (int) ($r['settlement_id'] ?? 0);
    if ($sid > 0) {
        return '<span class="badge bg-secondary">Abgerechnet #' . $sid . '</span>';
    }
    $bez = (string) ($r['timestampBezahlung'] ?? '');
    if ($bez !== '' && $bez !== '0000-00-00 00:00:00') {
        return '<span class="badge bg-warning text-dark">Offen</span>';
    }

    return '<span class="badge bg-light text-dark border">Unbezahlt</span>';
}

/**
 * Tischliste für Filter-Dropdown (Admin: inkl. Direktverkauf #999999).
 *
 * @return list<array{tischnummer:int,tischname:string}>
 */
function ff_hist_filter_tables(mysqli $conn, bool $isAdmin, string $currentUser): array
{
    $tables = [];
    $tableSqlExtra = '';
    if (!$isAdmin && $currentUser !== '') {
        $tableSqlExtra = " AND b.kellner = '" . mysqli_real_escape_string($conn, $currentUser) . "' ";
    }
    $tRes = mysqli_query(
        $conn,
        "SELECT DISTINCT b.tischnummer, COALESCE(t.tischname, CONCAT('Tisch ', b.tischnummer)) AS tischname
         FROM bestellungen b
         LEFT JOIN tische t ON t.tischnummer = b.tischnummer
         WHERE b.`delete`=0 {$tableSqlExtra}
         ORDER BY tischname ASC"
    );
    if ($tRes) {
        while ($r = mysqli_fetch_assoc($tRes)) {
            $tables[] = $r;
        }
    }
    if ($isAdmin) {
        $hasDv = false;
        foreach ($tables as $t) {
            if ((int) ($t['tischnummer'] ?? 0) === FF_HIST_TISCH_DIREKTVERKAUF) {
                $hasDv = true;
                break;
            }
        }
        if (!$hasDv) {
            array_unshift($tables, [
                'tischnummer' => FF_HIST_TISCH_DIREKTVERKAUF,
                'tischname' => 'Direktverkauf',
            ]);
        }
    }

    return $tables;
}

function ff_hist_kellner_scope_sql(mysqli $conn, bool $isAdmin, string $currentUser, string $kellnerSel): string {
    if ($isAdmin) {
        if ($kellnerSel !== '') {
            $kEsc = mysqli_real_escape_string($conn, $kellnerSel);

            return " AND (b.kellner = '{$kEsc}' OR b.kellnerZahlung = '{$kEsc}') ";
        }

        return '';
    }
    $login = trim($currentUser);
    if ($login === '') {
        return '';
    }
    $kEsc = mysqli_real_escape_string($conn, $login);

    return " AND (b.kellner = '{$kEsc}' OR b.kellnerZahlung = '{$kEsc}') ";
}

/** Detail-Suche (Nr./Bon): Stations-Historie / DV-Details — Bon einsehen & nachdrucken. */
function ff_hist_lookup_kellner_scope_sql(
    mysqli $conn,
    bool $isAdmin,
    string $currentUser,
    string $kellnerSel,
    bool $fromExternalLookup
): string {
    if ($fromExternalLookup) {
        return '';
    }

    return ff_hist_kellner_scope_sql($conn, $isAdmin, $currentUser, $kellnerSel);
}

function ff_hist_format_duration(int $seconds): string {
    if ($seconds < 0) {
        $seconds = 0;
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }

    return sprintf('%02d:%02d', $m, $s);
}

function ff_hist_zeit_kueche_ok(?string $zeitKueche): bool {
    $zk = trim((string) $zeitKueche);

    return $zk !== '' && $zk !== '0000-00-00 00:00:00' && $zk !== '1970-01-01 00:00:00';
}

/** Wartezeit: erst Küche/Schank (zeitKueche), dann Auslieferung – nicht nur kueche=1 (z. B. nach Sammelrechnung-Kasse). */
function ff_hist_wait_cell(array $r): string {
    $now = time();
    $kueche = (int)($r['kueche'] ?? 0);
    $ausg = (int)($r['ausgeliefert'] ?? 0);
    $schankFertig = ($kueche === 1 && ff_hist_zeit_kueche_ok($r['zeitKueche'] ?? null));

    if (!$schankFertig) {
        $ts = strtotime((string)($r['zeitstempel'] ?? ''));
        if ($ts === false) {
            return '–';
        }
        $label = 'Küche/Schank ';
        $tb = (string)($r['timestampBezahlung'] ?? '');
        if ($tb !== '' && $tb !== '0000-00-00 00:00:00' && $kueche === 1) {
            $label = 'Schank (bezahlt, noch offen) ';
        }

        return $label . ff_hist_format_duration($now - $ts);
    }
    if ($ausg === 0) {
        $ts = strtotime((string)($r['zeitKueche'] ?? ''));
        if ($ts !== false) {
            return 'Auslieferung ' . ff_hist_format_duration($now - $ts);
        }

        return 'Auslieferung –';
    }

    return '–';
}

/** Sekunden für Sortierung (laufende Wartezeit; erledigt = 0). */
function ff_hist_wait_seconds(array $r): int {
    $now = time();
    $kueche = (int)($r['kueche'] ?? 0);
    $ausg = (int)($r['ausgeliefert'] ?? 0);
    $schankFertig = ($kueche === 1 && ff_hist_zeit_kueche_ok($r['zeitKueche'] ?? null));

    if (!$schankFertig) {
        $ts = strtotime((string)($r['zeitstempel'] ?? ''));
        if ($ts === false) {
            return -1;
        }

        return max(0, $now - $ts);
    }
    if ($ausg === 0) {
        $ts = strtotime((string)($r['zeitKueche'] ?? ''));
        if ($ts === false) {
            return -1;
        }

        return max(0, $now - $ts);
    }

    return 0;
}

$paymentMode = ff_hist_payment_mode($conn);
$openSql = ff_hist_open_sql($paymentMode);

$tables = ff_hist_filter_tables($conn, $isAdmin, $currentUser);
$positions = [];
$kellnerList = [];

if ($isAdmin) {

    $pRes = mysqli_query($conn, "SELECT DISTINCT p.rowid, p.Positionsname
                                 FROM bestellungen b JOIN positionen p ON p.rowid = b.position
                                 WHERE b.`delete`=0 ORDER BY p.Positionsname ASC");
    if ($pRes) { while ($r = mysqli_fetch_assoc($pRes)) { $positions[] = $r; } }

    ff_users_ensure_landing_columns($conn);
    $festHist = ff_finance_fest_filter_sql($conn, 'b');
    $kRes = mysqli_query(
        $conn,
        'SELECT DISTINCT ' . ff_hist_kasse_kellner_expr('b') . " AS k_login FROM bestellungen b
         WHERE b.`delete`=0 AND TRIM(" . ff_hist_kasse_kellner_expr('b') . ") <> ''{$festHist}
         ORDER BY k_login ASC"
    );
    if ($kRes) {
        while ($r = mysqli_fetch_assoc($kRes)) {
            $login = trim((string) ($r['k_login'] ?? ''));
            if ($login === '') {
                continue;
            }
            $kellnerList[] = ['login' => $login, 'label' => ff_finance_kellner_label($conn, $login)];
        }
    }
}

if ($hasOrderSearch) {
    $orderNr = (int)$orderSearch;
    $extraCond = '';
    if ($openOnly && $pendingOnly) {
        $extraCond = ' AND (' . $openSql . ' OR ' . ff_hist_pending_sql() . ') ';
    } elseif ($openOnly) {
        $extraCond = ' AND (' . $openSql . ') ';
    } elseif ($pendingOnly) {
        $extraCond = ' AND (' . ff_hist_pending_sql() . ') ';
    }
    $kellnerCond = ff_hist_lookup_kellner_scope_sql($conn, $isAdmin, $currentUser, $kellnerSel, $fromStation);
    $typeCond = '';
    $typeSql = ff_hist_type_sql($typeFilter);
    if ($typeSql !== '') {
        $typeCond = ' AND ' . $typeSql . ' ';
    }
    $deleteCond = ($openOnly || $pendingOnly) ? ' AND b.`delete` = 0 ' : '';
    $sql = "SELECT b.rowid, b.tischnummer, b.kellner, b.kellnerZahlung, b.zeitstempel,
                   b.timestampBestellung, b.timestampBezahlung, b.timestampAuslieferung, b.zeitKueche,
                   b.order_nr, b.bestellung, b.settlement_id,
                   b.Zusatzinfo, b.kueche, b.ausgeliefert, b.`delete`,
                   COALESCE(b.betrag, p.Betrag) AS betrag,
                   p.Positionsname, p.type, t.tischname
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            JOIN tische t ON t.tischnummer = b.tischnummer
            WHERE (b.order_nr = {$orderNr} OR b.bestellung = {$orderNr})
              {$deleteCond}{$extraCond}{$kellnerCond}{$typeCond}
            ORDER BY b.`delete` ASC, p.type ASC, p.Positionsname ASC, b.rowid ASC";
    $res = mysqli_query($conn, $sql);
    if ($res) { while ($r = mysqli_fetch_assoc($res)) { $resultsOrder[] = $r; } }

    $sqlAll = "SELECT b.ausgeliefert, b.timestampAuslieferung, b.`delete`
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            WHERE (b.order_nr = {$orderNr} OR b.bestellung = {$orderNr})
              {$deleteCond}{$kellnerCond}{$typeCond}
            ORDER BY b.rowid ASC";
    $resAll = mysqli_query($conn, $sqlAll);
    if ($resAll) {
        while ($r = mysqli_fetch_assoc($resAll)) {
            $resultsOrderAll[] = $r;
        }
    }
    $orderDeliveryStats = ff_hist_order_delivery_stats($resultsOrderAll);
}

if ($hasBonSearch) {
    $bonSqlMatch = ff_direktverkauf_bon_filter_sql($conn, $bonSearch, 'b');
    // Bon-Detail: alle Zeilen dieses Bons (nicht „nur unbezahlt“ aus dem Formular)
    $extraCond = '';
    $kellnerCond = $fromStation
        ? ''
        : ff_direktverkauf_history_scope_sql($conn, $isAdmin, 'b', $kellnerSel);
    $typeCond = '';
    $typeSql = ff_hist_type_sql($typeFilter);
    if ($typeSql !== '') {
        $typeCond = ' AND ' . $typeSql . ' ';
    }
    $sqlBon = "SELECT b.rowid, b.tischnummer, b.kellner, b.kellnerZahlung, b.zeitstempel,
                   b.timestampBestellung, b.timestampBezahlung, b.timestampAuslieferung, b.zeitKueche,
                   b.order_nr, b.bestellung, b.bon_id,
                   b.Zusatzinfo, b.kueche, b.ausgeliefert, b.`delete`,
                   COALESCE(b.betrag, p.Betrag) AS betrag,
                   COALESCE(NULLIF(TRIM(p.Positionsname), ''), '(Position nicht gefunden)') AS Positionsname,
                   p.type, COALESCE(t.tischname, 'Direktverkauf') AS tischname
            FROM bestellungen b
            LEFT JOIN positionen p ON p.rowid = b.position
            LEFT JOIN tische t ON t.tischnummer = b.tischnummer
            WHERE b.tischnummer = " . FF_HIST_TISCH_DIREKTVERKAUF . "
              {$bonSqlMatch}
              {$extraCond}{$kellnerCond}{$typeCond}
            ORDER BY p.type ASC, Positionsname ASC, b.rowid ASC";
    $resBon = mysqli_query($conn, $sqlBon);
    if ($resBon) {
        while ($r = mysqli_fetch_assoc($resBon)) {
            $resultsBon[] = $r;
        }
    }

    $sqlBonAll = "SELECT b.ausgeliefert, b.timestampAuslieferung, b.`delete`
            FROM bestellungen b
            LEFT JOIN positionen p ON p.rowid = b.position
            WHERE b.tischnummer = " . FF_HIST_TISCH_DIREKTVERKAUF . "
              {$bonSqlMatch}
              {$kellnerCond}{$typeCond}
            ORDER BY b.rowid ASC";
    $resBonAll = mysqli_query($conn, $sqlBonAll);
    if ($resBonAll) {
        while ($r = mysqli_fetch_assoc($resBonAll)) {
            $resultsBonAll[] = $r;
        }
    }
    $orderDeliveryStats = ff_hist_order_delivery_stats($resultsBonAll);
}

if (!$hasOrderSearch && !$hasBonSearch && $hasFilter) {
    $conds = [ff_hist_delete_base_sql($abrechnungFilter, $openOnly, $pendingOnly, $histKellnerAbrechnungView, $histKellnerAlleZeilen)];
    if ($dateFrom !== '') {
        $df = date('Y-m-d', strtotime($dateFrom));
        $tf = $timeFrom !== '' ? $timeFrom : '00:00';
        $conds[] = "COALESCE(NULLIF(b.timestampBestellung,'0000-00-00 00:00:00'), b.zeitstempel) >= '" . mysqli_real_escape_string($conn, $df . ' ' . $tf . ':00') . "'";
    }
    if ($dateTo !== '') {
        $dt = date('Y-m-d', strtotime($dateTo));
        $tt = $timeTo !== '' ? $timeTo : '23:59';
        $conds[] = "COALESCE(NULLIF(b.timestampBestellung,'0000-00-00 00:00:00'), b.zeitstempel) <= '" . mysqli_real_escape_string($conn, $dt . ' ' . $tt . ':59') . "'";
    }
    if ($tableNo > 0) { $conds[] = "b.tischnummer = {$tableNo}"; }
    if ($posId > 0) { $conds[] = "p.rowid = {$posId}"; }
    $typeSql = ff_hist_type_sql($typeFilter);
    if ($typeSql !== '') {
        $conds[] = $typeSql;
    }
    if ($openOnly && $pendingOnly) {
        $conds[] = '(' . $openSql . ' OR ' . ff_hist_pending_sql() . ')';
    } elseif ($openOnly) {
        $conds[] = $openSql;
    } elseif ($pendingOnly) {
        $conds[] = ff_hist_pending_sql();
    }
    if ($isAdmin && $kellnerSel !== '') {
        $festCond = trim(ff_finance_fest_filter_sql($conn, 'b'));
        if ($festCond !== '') {
            $conds[] = ltrim($festCond, 'AND ');
        }
    }
    if ($histKellnerAbrechnungView) {
        $openExtra = trim(ff_finance_kellner_open_extra_sql('b'));
        if ($openExtra !== '') {
            $conds[] = ltrim($openExtra, 'AND ');
        }
    } elseif ($isAdmin && $kellnerSel !== '' && $abrechnungFilter !== '' && $abrechnungFilter !== 'alle') {
        $abSql = trim(ff_hist_abrechnung_filter_sql($abrechnungFilter));
        if ($abSql !== '') {
            $conds[] = ltrim($abSql, 'AND ');
        }
    }

    $where = implode(' AND ', $conds) . ff_hist_kellner_scope_sql($conn, $isAdmin, $currentUser, $kellnerSel);
    $amtExpr = ff_finance_order_amount_expr('b', 'p');
    $sqlF = 'SELECT b.rowid, b.tischnummer, b.kellner, b.kellnerZahlung, ' . ff_hist_kasse_kellner_expr('b') . ' AS kellner_kasse,
                    b.fest_id, b.zeitstempel, b.timestampBezahlung, b.settlement_id,
                    COALESCE(b.is_gratis, 0) AS is_gratis, COALESCE(b.schreibaus, 0) AS schreibaus,
                    COALESCE(NULLIF(b.timestampBestellung, \'0000-00-00 00:00:00\'), b.zeitstempel) AS ts_order,
                    b.timestampBestellung, b.timestampAuslieferung, b.zeitKueche, b.order_nr, b.bon_id,
                    b.Zusatzinfo, b.kueche, b.ausgeliefert, b.`delete`,
                    ' . $amtExpr . ' AS betrag,
                    p.Positionsname, p.type, t.tischname
             FROM bestellungen b
             JOIN positionen p ON p.rowid = b.position
             LEFT JOIN tische t ON t.tischnummer = b.tischnummer
             WHERE ' . $where . '
             ORDER BY ts_order DESC, t.tischname ASC, p.type ASC, p.Positionsname ASC, b.rowid DESC';
    $resF = mysqli_query($conn, $sqlF);
    if ($resF) { while ($r = mysqli_fetch_assoc($resF)) { $resultsFilter[] = $r; } }
    foreach ($resultsFilter as $r) {
        $amt = (float) ($r['betrag'] ?? 0);
        $amtAb = ($isAdmin && $kellnerSel !== '') ? ff_hist_row_abrechnung_betrag($r) : $amt;
        $sumFilterTop += $amtAb;
        $bez = (string) ($r['timestampBezahlung'] ?? '');
        $paid = $bez !== '' && $bez !== '0000-00-00 00:00:00';
        if ($paid && (int) ($r['is_gratis'] ?? 0) === 0 && (int) ($r['schreibaus'] ?? 0) === 0) {
            $sumFilterPaid += $amt;
            $cntFilterPaidRows++;
        } elseif (!$paid) {
            $cntFilterUnpaidRows++;
        }
    }
    if ($isAdmin && $kellnerSel !== '') {
        $histKellnerBreakdown = ff_kellner_paid_breakdown($conn, $kellnerSel);
        $cntFilterOpen = (int) ($histKellnerBreakdown['open_current_fest']['count'] ?? 0);
        $sumFilterOpen = (float) ($histKellnerBreakdown['open_current_fest']['sum'] ?? 0);
        $cntFilterSettled = (int) ($histKellnerBreakdown['settled']['count'] ?? 0);
        $sumFilterSettled = (float) ($histKellnerBreakdown['settled']['sum'] ?? 0);
        $cntFilterOtherFest = (int) ($histKellnerBreakdown['open_other_fest']['count'] ?? 0);
        $sumFilterOtherFest = (float) ($histKellnerBreakdown['open_other_fest']['sum'] ?? 0);
        $cntFilterGratisRows = (int) ($histKellnerBreakdown['excluded_gratis']['count'] ?? 0);
        $cntFilterSchreibausRows = (int) ($histKellnerBreakdown['excluded_schreibaus']['count'] ?? 0);
        if ($cntFilterUnpaidRows === 0) {
            $cntFilterUnpaidRows = (int) ($histKellnerBreakdown['excluded_unpaid']['count'] ?? 0);
        }
    }
}

if (!$hasOrderSearch && !$hasBonSearch && !$hasFilter) {
    $recentKellner = ff_hist_kellner_scope_sql($conn, $isAdmin, $currentUser, $isAdmin ? '' : $currentUser);
    $festRecent = $isAdmin ? ff_finance_fest_filter_sql($conn, 'b') : '';
    $recentSql = 'SELECT b.order_nr, NULL AS bon_id, MIN(b.timestampBestellung) AS ts,
                         MAX(t.tischname) AS tischname, MAX(' . ff_hist_kasse_kellner_expr('b') . ') AS kellner,
                         b.tischnummer, COUNT(*) AS anzahl,
                         SUM(COALESCE(b.betrag, p.Betrag)) AS summe
                  FROM bestellungen b
                  JOIN positionen p ON p.rowid = b.position
                  JOIN tische t ON t.tischnummer = b.tischnummer
                  WHERE b.`delete` = 0 AND b.order_nr IS NOT NULL AND b.order_nr > 0
                  ' . $festRecent . $recentKellner . '
                  GROUP BY b.order_nr, b.tischnummer
                  ORDER BY MAX(b.timestampBestellung) DESC LIMIT 30';
    $recentRes = mysqli_query($conn, $recentSql);
    if ($recentRes) {
        while ($r = mysqli_fetch_assoc($recentRes)) {
            $recentOrders[] = $r;
        }
    }

    $recentDvSql = 'SELECT 0 AS order_nr, b.bon_id,
                         MIN(COALESCE(NULLIF(b.timestampBestellung, \'0000-00-00 00:00:00\'), b.zeitstempel)) AS ts,
                         MAX(t.tischname) AS tischname, MAX(' . ff_hist_kasse_kellner_expr('b') . ') AS kellner,
                         b.tischnummer, COUNT(*) AS anzahl,
                         SUM(COALESCE(b.betrag, p.Betrag)) AS summe
                  FROM bestellungen b
                  JOIN positionen p ON p.rowid = b.position
                  JOIN tische t ON t.tischnummer = b.tischnummer
                  WHERE b.`delete` = 0
                    AND b.tischnummer = ' . FF_HIST_TISCH_DIREKTVERKAUF . '
                    AND b.bon_id IS NOT NULL AND CHAR_LENGTH(TRIM(b.bon_id)) > 0
                  ' . $festRecent . $recentKellner . '
                  GROUP BY b.bon_id
                  ORDER BY ts DESC LIMIT 30';
    $recentDvRes = mysqli_query($conn, $recentDvSql);
    $recentDv = [];
    if ($recentDvRes) {
        while ($r = mysqli_fetch_assoc($recentDvRes)) {
            $recentDv[] = $r;
        }
    }
    $recentOrders = array_merge($recentOrders, $recentDv);
    usort($recentOrders, static function (array $a, array $b): int {
        $ta = !empty($a['ts']) ? strtotime((string) $a['ts']) : 0;
        $tb = !empty($b['ts']) ? strtotime((string) $b['ts']) : 0;

        return $tb <=> $ta;
    });
    $recentOrders = array_slice($recentOrders, 0, 30);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Bestell-History</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <?php echo ff_favicon_link_tags($conn); ?>
    <meta name="mobile-web-app-capable" content="yes">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #f0f2f5; min-height: 100vh; padding-bottom: 70px; }
        .app-navbar .btn-outline-secondary { border-color: rgba(255,255,255,0.7); color: #fff !important; }
        .app-navbar .btn-outline-secondary:hover { background: rgba(255,255,255,0.2); border-color: #fff; color: #fff !important; }
        .bh-content { max-width: 1200px; margin: 0 auto; padding: 1.5rem 1rem; }
        @media (min-width: 768px) { .bh-content { padding: 2rem; } }
        .bh-table th { font-size: 0.85rem; white-space: nowrap; }
        .bh-table td { font-size: 0.9rem; vertical-align: middle; height: auto; }
        .bh-badge-speise { background: #198754; color: #fff; }
        .bh-badge-getraenk { background: #991b1b; color: #fff; }
        .bh-badge-sonstig { background: #6f42c1; color: #fff; }
        .bh-sum-row td { font-weight: 700; border-top: 2px solid #333; }
        .bh-filter-hint { font-size: 0.8rem; color: #6c757d; }
        .bh-filter-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #dee2e6; padding: 1.25rem; margin-bottom: 1.5rem; }
        .bh-result-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #dee2e6; overflow: hidden; margin-bottom: 1.5rem; }
        .bh-result-card .card-header { background: linear-gradient(135deg, #fce8e8 0%, #fff5f5 100%); border-bottom: 1px solid #dee2e6; padding: 1rem 1.25rem; font-size: 1rem; }
        .bh-recent-row { cursor: pointer; transition: background 0.15s; }
        .bh-recent-row:hover { background: rgba(185, 28, 28, 0.08) !important; }
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .bh-table th.bh-sort { cursor: pointer; user-select: none; }
        .bh-table th.bh-sort:hover { background: #e9ecef; }
        .bh-table th.bh-sort .bh-sort-ind { font-size: 0.7rem; opacity: 0.45; margin-left: 0.2rem; }
        .bh-table th.bh-sort.bh-sort-active .bh-sort-ind { opacity: 1; color: #b91c1c; }
    </style>
</head>
<body>

<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <a href="<?php echo htmlspecialchars($bhBackHref, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">&larr; <?php echo htmlspecialchars($bhBackLabel, ENT_QUOTES, 'UTF-8'); ?></a>
        <span class="navbar-brand mb-0 flex-grow-1 text-center">Bestell-History</span>
        <span class="ms-auto small" style="color:rgba(255,255,255,0.8);"><?php echo htmlspecialchars($currentUser, ENT_QUOTES, 'UTF-8'); ?><?php if ($isAdmin): ?> (Admin)<?php endif; ?></span>
    </div>
</nav>

<div class="bh-content">

    <?php if ($isAdmin): ?>
    <div class="alert alert-info py-2 small mb-3">
        <strong>Admin — Rückerstattung:</strong> Kellner oder Tisch filtern, bezahlte Zeile mit <em>Zahlung stornieren</em>.
        <strong>Direktverkauf:</strong> Tisch <em>Direktverkauf (#999999)</em> wählen, Abrechnung <em>alle Status</em>, dann filtern — alle Kassa-Verkäufe mit Storno-Buttons.
        Alternativ: Tisch öffnen → <strong>Historie</strong> (dort alle Positionen am Tisch, nicht nur eigene).
        Noch nicht ausgeliefert → Position verschwindet auch in Küche/Schank.
    </div>
    <?php endif; ?>

    <form class="bh-filter-card" method="get" action="bestell_history.php">
    <div class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label">Bestellung Nr. / Bon</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="z.B. 123, 28-004 oder #28-004" value="<?php echo htmlspecialchars($hasBonSearch ? $bonSearch : $orderSearch, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="bh-filter-hint">Kellner: Bestell-Nr.; Direktverkauf: Bon (z.&nbsp;B. <code>28-004</code> oder <code>#28-004</code>) — zeigt <strong>alle</strong> Zeilen des Bons. <strong>Art</strong> filtert optional mit. Spaltenkopf tippen zum Sortieren.</div>
        </div>
        <?php if ($isAdmin): ?>
        <div class="col-md-5">
            <div class="row g-1">
                <div class="col-6"><label class="form-label">Datum von</label><input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-6"><label class="form-label">Zeit von</label><input type="time" name="time_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($timeFrom, ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-6"><label class="form-label">Datum bis</label><input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-6"><label class="form-label">Zeit bis</label><input type="time" name="time_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($timeTo, ENT_QUOTES, 'UTF-8'); ?>"></div>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Tisch</label>
            <select name="table" class="form-select form-select-sm">
                <option value="0" <?php echo $tableNo === 0 ? 'selected' : ''; ?>>alle Tische</option>
                <option value="<?php echo FF_HIST_TISCH_DIREKTVERKAUF; ?>" <?php echo $tableNo === FF_HIST_TISCH_DIREKTVERKAUF ? 'selected' : ''; ?>>Direktverkauf (#<?php echo FF_HIST_TISCH_DIREKTVERKAUF; ?>)</option>
                <?php foreach ($tables as $t):
                    $tn = (int) ($t['tischnummer'] ?? 0);
                    if ($tn === FF_HIST_TISCH_DIREKTVERKAUF) {
                        continue;
                    }
                    ?>
                <option value="<?php echo $tn; ?>" <?php echo $tableNo === $tn ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['tischname'] . ' (#' . $tn . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="bh-filter-hint mb-0 mt-1">Direktverkauf: Abrechnung <strong>alle Status</strong> wählen, sonst siehst du oft keine bezahlten Zeilen.</p>
            <label class="form-label mt-1">Art</label>
            <select name="typ" class="form-select form-select-sm">
                <option value="0" <?php echo $typeFilter === 0 ? 'selected' : ''; ?>>alle (Speisen &amp; Getränke)</option>
                <option value="1" <?php echo $typeFilter === 1 ? 'selected' : ''; ?>>nur Speisen</option>
                <option value="2" <?php echo $typeFilter === 2 ? 'selected' : ''; ?>>nur Getränke</option>
                <option value="3" <?php echo $typeFilter === 3 ? 'selected' : ''; ?>>nur Sonstiges</option>
            </select>
            <label class="form-label mt-1">Einzelposition</label>
            <select name="pos" class="form-select form-select-sm">
                <option value="0">alle Artikel</option>
                <?php foreach ($positions as $p): ?>
                <option value="<?php echo (int)$p['rowid']; ?>" <?php echo $posId === (int)$p['rowid'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['Positionsname'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Kasse (KellnerIn)</label>
            <select name="kellner" class="form-select form-select-sm">
                <option value="">alle</option>
                <?php foreach ($kellnerList as $k): ?>
                <?php $kLogin = is_array($k) ? (string) ($k['login'] ?? '') : (string) $k; $kLabel = is_array($k) ? (string) ($k['label'] ?? $kLogin) : (string) $k; ?>
                <option value="<?php echo htmlspecialchars($kLogin, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $kellnerSel === $kLogin ? 'selected' : ''; ?>><?php echo htmlspecialchars($kLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="bh-filter-hint mb-0 mt-1">Standard: dieselben Zeilen wie <strong>Finanzen → Kellner-Abrechnung</strong> (bezahlt, offen, aktuelles Fest).</p>
            <label class="form-label mt-1 mb-0 small">Abrechnung</label>
            <select name="abrechnung" class="form-select form-select-sm">
                <option value="" <?php echo $abrechnungFilter === '' ? 'selected' : ''; ?>>offen (Standard)</option>
                <option value="alle" <?php echo $abrechnungFilter === 'alle' ? 'selected' : ''; ?>>alle Status</option>
                <option value="offen" <?php echo $abrechnungFilter === 'offen' ? 'selected' : ''; ?>>offen</option>
                <option value="abgerechnet" <?php echo $abrechnungFilter === 'abgerechnet' ? 'selected' : ''; ?>>abgerechnet</option>
                <option value="ehrengast" <?php echo $abrechnungFilter === 'ehrengast' ? 'selected' : ''; ?>>Ehrengast / Personal</option>
                <option value="unbezahlt" <?php echo $abrechnungFilter === 'unbezahlt' ? 'selected' : ''; ?>>unbezahlt</option>
                <option value="schreibaus" <?php echo $abrechnungFilter === 'schreibaus' ? 'selected' : ''; ?>>Schreibaus</option>
                <option value="storniert" <?php echo $abrechnungFilter === 'storniert' ? 'selected' : ''; ?>>storniert</option>
            </select>
            <label class="form-check mt-1 mb-0">
                <input type="checkbox" class="form-check-input" name="alle" value="1" <?php echo $histKellnerAlleZeilen ? 'checked' : ''; ?>>
                <span class="form-check-label small">Alle Zeilen (auch unbezahlt, Personal, Schreibaus, …)</span>
            </label>
            <div class="col-12 mt-1">
                <label class="form-check form-check-inline mb-0">
                    <input type="checkbox" class="form-check-input" name="open" value="1" <?php echo $openOnly ? 'checked' : ''; ?>>
                    <span class="form-check-label small">Nur unbezahlt</span>
                </label>
                <label class="form-check form-check-inline mb-0">
                    <input type="checkbox" class="form-check-input" name="pending" value="1" <?php echo $pendingOnly ? 'checked' : ''; ?>>
                    <span class="form-check-label small">Noch nicht ausgeliefert (inkl. bezahlt)</span>
                </label>
            </div>
            <div class="mt-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filtern</button>
                <a href="bestell_history.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </div>
        <?php else: ?>
        <div class="col-md-3">
            <label class="form-label">Tisch</label>
            <select name="table" class="form-select form-select-sm">
                <option value="0">alle meine Tische</option>
                <?php foreach ($tables as $t): ?>
                <option value="<?php echo (int)$t['tischnummer']; ?>" <?php echo $tableNo === (int)$t['tischnummer'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['tischname'] . ' (#' . $t['tischnummer'] . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <label class="form-label mt-1">Art</label>
            <select name="typ" class="form-select form-select-sm">
                <option value="0" <?php echo $typeFilter === 0 ? 'selected' : ''; ?>>alle</option>
                <option value="1" <?php echo $typeFilter === 1 ? 'selected' : ''; ?>>nur Speisen</option>
                <option value="2" <?php echo $typeFilter === 2 ? 'selected' : ''; ?>>nur Getränke</option>
            </select>
            <label class="form-check mt-2 mb-0">
                <input type="checkbox" class="form-check-input" name="open" value="1" <?php echo $openOnly ? 'checked' : ''; ?>>
                <span class="form-check-label small">Nur unbezahlt</span>
            </label>
            <label class="form-check mt-1 mb-0">
                <input type="checkbox" class="form-check-input" name="pending" value="1" <?php echo $pendingOnly ? 'checked' : ''; ?>>
                <span class="form-check-label small">Noch nicht ausgeliefert (inkl. bezahlt, wie Schank)</span>
            </label>
        </div>
        <div class="col-md-3">
            <div class="bh-filter-hint mt-1">Nur deine Bestellungen (<strong><?php echo htmlspecialchars($currentUser, ENT_QUOTES, 'UTF-8'); ?></strong>). Tabelle: Spaltenkopf tippen zum Sortieren.</div>
            <button type="submit" class="btn btn-primary btn-sm mt-2">Anzeigen</button>
            <a href="bestell_history.php" class="btn btn-outline-secondary btn-sm mt-2">Reset</a>
        </div>
        <?php endif; ?>
    </div>
    </form>

    <?php
    $showActionsCol = ($currentUser !== '' || $isAdmin);
    ?>

    <?php
    $histDetailRows = $hasBonSearch ? $resultsBon : $resultsOrder;
    $histDetailHasData = ($hasOrderSearch && $resultsOrder !== []) || ($hasBonSearch && $resultsBon !== []);
    ?>
    <?php if ($histDetailHasData): ?>
    <?php
    $fertigTs = $orderDeliveryStats['fertig_ts'] ?? null;
    $ordTotal = (int)($orderDeliveryStats['total'] ?? 0);
    $ordDelivered = (int)($orderDeliveryStats['delivered'] ?? 0);
    $orderRowsFiltered = ($openOnly || $pendingOnly || $typeFilter > 0) && $ordTotal > 0 && count($histDetailRows) < $ordTotal;
    $histFirst = $histDetailRows[0];
    $histKellnerLbl = ff_finance_kellner_label($conn, trim((string) ($histFirst['kellnerZahlung'] ?? $histFirst['kellner'] ?? '')));
    ?>
    <div class="bh-result-card">
        <div class="card-header">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <?php if ($hasBonSearch): ?>
                <span class="badge bg-dark fs-6">Direktverkauf · Bon #<?php echo htmlspecialchars($bonSearch, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php else: ?>
                <span class="badge bg-primary fs-6">Bestellung Nr. <?php echo (int)$orderSearch; ?></span>
                <?php endif; ?>
                <span>Tisch: <strong><?php echo htmlspecialchars($histFirst['tischname'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></span>
                <span>Kasse: <strong><?php echo htmlspecialchars($histKellnerLbl, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                <?php if (!empty($histFirst['timestampBestellung']) && $histFirst['timestampBestellung'] !== '0000-00-00 00:00:00'): ?>
                <span><?php echo date('d.m.Y H:i', strtotime($histFirst['timestampBestellung'])); ?></span>
                <?php endif; ?>
                <?php if ($fertigTs): ?>
                <span class="badge bg-success fs-6">Gesamt fertig: <?php echo date('d.m.Y H:i', strtotime($fertigTs)); ?></span>
                <?php elseif ($ordTotal > 0 && $ordDelivered > 0): ?>
                <span class="badge bg-warning text-dark">Auslieferung: <?php echo $ordDelivered; ?> / <?php echo $ordTotal; ?> Positionen</span>
                <?php elseif ($ordTotal > 0): ?>
                <span class="badge bg-secondary">Noch nicht ausgeliefert (<?php echo $ordTotal; ?> Pos.)</span>
                <?php endif; ?>
                <?php if ($orderRowsFiltered): ?>
                <span class="small text-muted">Tabelle: gefilterte Zeilen (<?php echo count($histDetailRows); ?> von <?php echo $ordTotal; ?>)</span>
                <?php endif; ?>
                <?php
                $ordTisch = (int) ($histFirst['tischnummer'] ?? 0);
                $ordMovable = 0;
                $ordStorno = 0;
                $ordHasPaid = false;
                foreach ($histDetailRows as $or) {
                    if (!$hasBonSearch && $ordTisch > 0 && $ordTisch !== FF_HIST_TISCH_DIREKTVERKAUF
                        && $paymentMode === 'instant' && ff_verschieben_can_move_row($or, $isAdmin, $currentUser, $paymentMode)) {
                        $ordMovable++;
                    }
                    if ((int) ($or['delete'] ?? 0) !== 1 && ff_bestellung_row_is_paid($or)) {
                        $ordHasPaid = true;
                    }
                    if (ff_storno_can_cancel_row($or, $isAdmin)) {
                        $ordStorno++;
                    }
                }
                $canStornoWholeOrder = $ordStorno > 0 && ($isAdmin || !$ordHasPaid);
                if ($canStornoWholeOrder):
                    $stornoLabel = $hasBonSearch
                        ? ('Ganzen Bon #' . $bonSearch . ' (' . $ordStorno . ' Pos.) wirklich stornieren?')
                        : ('Ganze Bestellung Nr. ' . (int) $orderSearch . ' (' . $ordStorno . ' Pos.) wirklich stornieren?');
                ?>
                <button type="button" class="btn btn-sm btn-outline-danger" data-bh-storno-batch="1"
                        data-tisch="<?php echo (int) $ordTisch; ?>"
                        data-order-nr="<?php echo $hasBonSearch ? 0 : (int) $orderSearch; ?>"
                        data-bon-id="<?php echo $hasBonSearch ? htmlspecialchars($bonSearch, ENT_QUOTES, 'UTF-8') : ''; ?>"
                        data-batch-ts=""
                        data-label="<?php echo htmlspecialchars($stornoLabel, ENT_QUOTES, 'UTF-8'); ?>">
                    🗑 <?php echo $hasBonSearch ? 'Ganzen Bon' : 'Ganze Bestellung'; ?>
                </button>
                <?php endif; ?>
                <?php if ($ordMovable > 0): ?>
                <button type="button" class="btn btn-sm btn-outline-primary"
                        onclick="ffHistTischChangeOrder(<?php echo $ordTisch; ?>, <?php echo (int) $orderSearch; ?>, '', 'Ganze Bestellung Nr. <?php echo (int) $orderSearch; ?> (<?php echo (int) $ordMovable; ?> Pos.) verschieben:'); return false;">
                    ↷ Ganze Bestellung
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-striped bh-table bh-sortable mb-0">
                <thead class="table-light"><tr>
                    <th class="bh-sort" data-sort-key="typ">Typ<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="position">Position<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="hinweis">Hinweis<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort text-end" data-sort-key="betrag">Betrag<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="wait">Wartezeit<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort text-center" data-sort-key="kueche">Küche<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort text-center" data-sort-key="ausg">Ausg.<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="fertig">Ausgeliefert am<span class="bh-sort-ind"></span></th>
                    <?php if ($showActionsCol): ?><th>Aktion</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php $sum = 0; foreach ($histDetailRows as $r):
                    if ((int) ($r['delete'] ?? 0) === 0) {
                        $sum += (float) ($r['betrag'] ?? 0);
                    }
                    $type = (int)($r['type'] ?? 0); $bc = $type === 1 ? 'bh-badge-speise' : ($type === 2 ? 'bh-badge-getraenk' : 'bh-badge-sonstig');
                    $wSec = ff_hist_wait_seconds($r);
                    $posSort = mb_strtolower((string)($r['Positionsname'] ?? ''), 'UTF-8');
                    $hinSort = mb_strtolower(trim((string)($r['Zusatzinfo'] ?? '')), 'UTF-8');
                    $doneTs = (!empty($r['timestampAuslieferung']) && $r['timestampAuslieferung'] !== '0000-00-00 00:00:00') ? $r['timestampAuslieferung'] : '';
                    $fertigSort = $doneTs ? strtotime($doneTs) : 0;
                    $rowStorno = (int) ($r['delete'] ?? 0) === 1;
                ?>
                <tr class="<?php echo $rowStorno ? 'table-secondary text-muted' : ''; ?>" data-sort-typ="<?php echo $type; ?>" data-sort-position="<?php echo htmlspecialchars($posSort, ENT_QUOTES, 'UTF-8'); ?>" data-sort-hinweis="<?php echo htmlspecialchars($hinSort, ENT_QUOTES, 'UTF-8'); ?>" data-sort-betrag="<?php echo (float)($r['betrag'] ?? 0); ?>" data-sort-wait="<?php echo (int)$wSec; ?>" data-sort-kueche="<?php echo (int)($r['kueche'] ?? 0); ?>" data-sort-ausg="<?php echo (int)($r['ausgeliefert'] ?? 0); ?>" data-sort-fertig="<?php echo (int)$fertigSort; ?>">
                    <td><span class="badge <?php echo $bc; ?>"><?php echo ff_hist_type_label($type); ?></span></td>
                    <td><?php echo htmlspecialchars($r['Positionsname'] ?? '', ENT_QUOTES, 'UTF-8'); ?><?php if ($rowStorno): ?> <span class="badge bg-secondary">storniert</span><?php endif; ?></td>
                    <td class="text-muted"><?php echo htmlspecialchars(trim((string)($r['Zusatzinfo'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-end"><?php echo ff_hist_eur($r['betrag']); ?></td>
                    <td class="small text-nowrap"><?php echo htmlspecialchars(ff_hist_wait_cell($r), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-center"><?php echo ((int)($r['kueche'] ?? 0)) ? '<span class="text-success">&#10004;</span>' : '<span class="text-muted">&#9675;</span>'; ?></td>
                    <td class="text-center"><?php echo ((int)($r['ausgeliefert'] ?? 0)) ? '<span class="text-success">&#10004;</span>' : '<span class="text-muted">&#9675;</span>'; ?></td>
                    <td><?php echo $doneTs ? '<span class="text-success">' . date('d.m.Y H:i', strtotime($doneTs)) . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                    <?php if ($showActionsCol): ?>
                    <td class="text-nowrap">
                        <?php echo ff_hist_admin_storno_button_html($r, $isAdmin); ?>
                        <?php echo ff_hist_admin_tisch_change_button_html($r, $isAdmin, $currentUser, $paymentMode); ?>
                        <?php if ((int) ($r['delete'] ?? 0) !== 1): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Bon dieser Bestellung erneut an den Thermodrucker senden (falls verloren)" onclick="ffHistBonNachdruck(<?php echo (int) ($r['rowid'] ?? 0); ?>); return false;">🖨 Bon</button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="bh-sum-row"><td colspan="3" class="text-end">Summe</td><td class="text-end"><?php echo ff_hist_eur($sum); ?></td><td colspan="<?php echo $showActionsCol ? 5 : 4; ?>"></td></tr></tfoot>
            </table>
            </div>
        </div>
    </div>
    <?php elseif ($hasBonSearch): ?>
    <div class="alert alert-warning rounded-3">Keine Ergebnisse für <strong>Bon #<?php echo htmlspecialchars($bonSearch, ENT_QUOTES, 'UTF-8'); ?></strong><?php if ($openOnly || $pendingOnly || $typeFilter > 0): ?> (Filter „unbezahlt“ / Art prüfen)<?php elseif (!$fromStation && !$isAdmin && $currentUser !== ''): ?> — ggf. gehört der Bon einem anderen Kassa-Mitarbeiter<?php endif; ?>.</div>
    <?php elseif ($hasOrderSearch): ?>
    <div class="alert alert-warning rounded-3">Keine Ergebnisse für <strong>Bestellung Nr. <?php echo (int)$orderSearch; ?></strong><?php if ($openOnly || $pendingOnly || $typeFilter > 0): ?> (Filter prüfen)<?php elseif ($fromStation): ?> — Bestellung nicht gefunden oder storniert<?php endif; ?>.</div>
    <?php endif; ?>

    <?php if (!$hasOrderSearch && !$hasBonSearch && $hasFilter && $resultsFilter === []): ?>
    <div class="alert alert-warning rounded-3">Keine Treffer für den gewählten Filter<?php if ($openOnly || $pendingOnly): ?> (Filter prüfen: „unbezahlt“ schließt kassierte Sammelrechnung aus – ggf. „noch nicht ausgeliefert“)<?php endif; ?>.</div>
    <?php endif; ?>

    <?php if (!$hasOrderSearch && !$hasBonSearch && $hasFilter && $resultsFilter !== []): ?>
    <div class="bh-result-card">
        <div class="card-header">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <strong>Filter-Ergebnis</strong>
                <?php if ($isAdmin && $kellnerSel !== ''): ?>
                <?php
                $nTable = count($resultsFilter);
                $kelLabel = ff_finance_kellner_label($conn, $kellnerSel);
                $tableDiffers = !$histKellnerAbrechnungView && (
                    $nTable !== $cntFilterOpen
                    || abs($sumFilterTop - $sumFilterOpen) > 0.009
                    || $cntFilterUnpaidRows > 0
                );
                ?>
                <span class="badge bg-primary fs-6"><?php echo ff_hist_eur($sumFilterOpen); ?> · <?php echo (int) $cntFilterOpen; ?> Pos.</span>
                <span class="text-muted small">Kasse <strong><?php echo htmlspecialchars($kelLabel, ENT_QUOTES, 'UTF-8'); ?></strong> · offen · wie Kellner-Abrechnung<?php echo $histKellnerAbrechnungView ? '' : ' (Tabelle kann mehr Zeilen zeigen)'; ?></span>
                <?php if ($histKellnerAlleZeilen && $tableDiffers): ?>
                <span class="badge bg-secondary"><?php echo (int) $nTable; ?> Zeilen · Summe Abrechnung in Tabelle <?php echo ff_hist_eur($sumFilterTop); ?></span>
                <?php
                $extraCnt = max(0, $nTable - $cntFilterOpen);
                $exParts = [];
                if ($cntFilterUnpaidRows > 0) {
                    $exParts[] = (int) $cntFilterUnpaidRows . ' unbezahlt';
                }
                if ($cntFilterGratisRows > 0) {
                    $exParts[] = (int) $cntFilterGratisRows . ' Ehrengast/Personal (0 €, <code>is_gratis</code>)';
                }
                if ($cntFilterSchreibausRows > 0) {
                    $exParts[] = (int) $cntFilterSchreibausRows . ' Schreibaus';
                }
                if ($cntFilterSettled > 0) {
                    $exParts[] = (int) $cntFilterSettled . ' bereits abgerechnet';
                }
                ?>
                <p class="text-muted small mb-0 w-100"><strong><?php echo (int) $extraCnt; ?> Zeilen</strong> ohne Abrechnungs-Umsatz (in der Tabelle <strong>0,00 €</strong>): <?php echo $exParts !== [] ? implode(' · ', $exParts) : 'z. B. anderer Kellner an der Kasse'; ?>. Summe oben = <?php echo ff_hist_eur($sumFilterOpen); ?> (wie Finanzen).</p>
                <?php endif; ?>
                <?php if ($cntFilterSettled > 0): ?>
                <span class="badge bg-secondary">Bereits abgerechnet: <?php echo ff_hist_eur($sumFilterSettled); ?> · <?php echo (int) $cntFilterSettled; ?> Pos.</span>
                <?php endif; ?>
                <?php if ($cntFilterOtherFest > 0): ?>
                <span class="badge bg-info text-dark">Andere Fest-ID (offen): <?php echo ff_hist_eur($sumFilterOtherFest); ?> · <?php echo (int) $cntFilterOtherFest; ?> Pos.</span>
                <?php endif; ?>
                <?php else: ?>
                <span class="badge bg-secondary"><?php echo count($resultsFilter); ?> Positionen</span>
                <span class="badge bg-success">Gesamtbetrag: <?php echo ff_hist_eur($sumFilterTop); ?></span>
                <?php if ($kellnerSel !== '' && abs($sumFilterTop - $sumFilterPaid) > 0.009): ?>
                <span class="badge bg-primary">Bezahlt: <?php echo ff_hist_eur($sumFilterPaid); ?></span>
                <?php elseif ($kellnerSel !== '' && $sumFilterPaid > 0): ?>
                <span class="badge bg-primary">Bezahlt: <?php echo ff_hist_eur($sumFilterPaid); ?></span>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-striped bh-table bh-sortable mb-0">
                <thead class="table-light"><tr>
                    <th class="bh-sort" data-sort-key="datum">Datum/Zeit<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="bestnr">Bon / Best.Nr.<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="tisch">Tisch<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="kellner">Kasse<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="typ">Typ<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="position">Position<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="hinweis">Hinweis<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort text-end" data-sort-key="betrag">Betrag<span class="bh-sort-ind"></span></th>
                    <?php if ($isAdmin && $kellnerSel !== ''): ?>
                    <th class="bh-sort" data-sort-key="abrechnung">Abrechnung<span class="bh-sort-ind"></span></th>
                    <?php endif; ?>
                    <th class="bh-sort" data-sort-key="wait">Wartezeit<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort text-center" data-sort-key="kueche">Küche<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort text-center" data-sort-key="ausg">Ausg.<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="fertig">Ausgeliefert am<span class="bh-sort-ind"></span></th>
                    <?php if ($showActionsCol): ?><th>Aktion</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php $sumF = 0; $histShowAbrechnung = $isAdmin && $kellnerSel !== ''; $histKellnerBetragMode = $histShowAbrechnung; foreach ($resultsFilter as $r):
                    $betragAb = $histKellnerBetragMode ? ff_hist_row_abrechnung_betrag($r) : (float) ($r['betrag'] ?? 0);
                    $sumF += $betragAb;
                    $type = (int)($r['type'] ?? 0); $bc = $type === 1 ? 'bh-badge-speise' : ($type === 2 ? 'bh-badge-getraenk' : 'bh-badge-sonstig');
                    $ts = (!empty($r['timestampBestellung']) && $r['timestampBestellung'] !== '0000-00-00 00:00:00') ? $r['timestampBestellung'] : $r['ts_order'];
                    $doneTs = (!empty($r['timestampAuslieferung']) && $r['timestampAuslieferung'] !== '0000-00-00 00:00:00') ? $r['timestampAuslieferung'] : '';
                    $wSec = ff_hist_wait_seconds($r);
                    $tsSort = $ts ? strtotime($ts) : 0;
                    $fertigSort = $doneTs ? strtotime($doneTs) : 0;
                    $posSort = mb_strtolower((string)($r['Positionsname'] ?? ''), 'UTF-8');
                    $hinSort = mb_strtolower(trim((string)($r['Zusatzinfo'] ?? '')), 'UTF-8');
                    $tischSort = mb_strtolower((string)($r['tischname'] ?? '') . ' ' . ($r['tischnummer'] ?? ''), 'UTF-8');
                    $kKasse = trim((string) ($r['kellner_kasse'] ?? $r['kellner'] ?? ''));
                    $kKasseLbl = $kKasse !== '' ? ff_finance_kellner_label($conn, $kKasse) : '';
                    $abSort = ff_hist_abrechnung_sort_key($r);
                    $rowStornoF = (int) ($r['delete'] ?? 0) === 1;
                ?>
                <tr class="<?php echo $rowStornoF ? 'table-secondary text-muted' : ''; ?>" data-sort-datum="<?php echo (int)$tsSort; ?>" data-sort-bestnr="<?php echo (int)($r['order_nr'] ?? 0); ?>" data-sort-tisch="<?php echo htmlspecialchars($tischSort, ENT_QUOTES, 'UTF-8'); ?>" data-sort-kellner="<?php echo htmlspecialchars(mb_strtolower($kKasseLbl, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>" data-sort-typ="<?php echo $type; ?>" data-sort-position="<?php echo htmlspecialchars($posSort, ENT_QUOTES, 'UTF-8'); ?>" data-sort-hinweis="<?php echo htmlspecialchars($hinSort, ENT_QUOTES, 'UTF-8'); ?>" data-sort-betrag="<?php echo $betragAb; ?>" data-sort-abrechnung="<?php echo (int) $abSort; ?>" data-sort-wait="<?php echo (int)$wSec; ?>" data-sort-kueche="<?php echo (int)($r['kueche'] ?? 0); ?>" data-sort-ausg="<?php echo (int)($r['ausgeliefert'] ?? 0); ?>" data-sort-fertig="<?php echo (int)$fertigSort; ?>">
                    <td><?php echo $ts ? date('d.m.Y H:i', strtotime($ts)) : '-'; ?></td>
                    <td><?php echo ff_hist_order_ref_cell_html($r); ?></td>
                    <td><?php echo htmlspecialchars(($r['tischname'] ?? '') . ' (#' . $r['tischnummer'] . ')', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($kKasseLbl, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge <?php echo $bc; ?>"><?php echo ff_hist_type_label($type); ?></span></td>
                    <td><?php echo htmlspecialchars($r['Positionsname'] ?? '', ENT_QUOTES, 'UTF-8'); ?><?php if ((int) ($r['delete'] ?? 0) === 1): ?> <span class="badge bg-secondary">storniert</span><?php endif; ?></td>
                    <td class="text-muted"><?php echo htmlspecialchars(trim((string)($r['Zusatzinfo'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-end"><?php echo ff_hist_betrag_cell_html($r, $histKellnerBetragMode); ?></td>
                    <?php if ($histShowAbrechnung): ?>
                    <td><?php echo ff_hist_settlement_badge_html($r); ?></td>
                    <?php endif; ?>
                    <td class="small text-nowrap"><?php echo htmlspecialchars(ff_hist_wait_cell($r), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-center"><?php echo ((int)($r['kueche'] ?? 0)) ? '<span class="text-success">&#10004;</span>' : '<span class="text-muted">&#9675;</span>'; ?></td>
                    <td class="text-center"><?php echo ((int)($r['ausgeliefert'] ?? 0)) ? '<span class="text-success">&#10004;</span>' : '<span class="text-muted">&#9675;</span>'; ?></td>
                    <td><?php echo $doneTs ? '<span class="text-success">' . date('d.m.Y H:i', strtotime($doneTs)) . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                    <?php if ($showActionsCol): ?>
                    <td class="text-nowrap">
                        <?php echo ff_hist_admin_storno_button_html($r, $isAdmin); ?>
                        <?php echo ff_hist_admin_tisch_change_button_html($r, $isAdmin, $currentUser, $paymentMode); ?>
                        <?php if ((int) ($r['delete'] ?? 0) !== 1): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Bon dieser Bestellung erneut an den Thermodrucker senden (falls verloren)" onclick="ffHistBonNachdruck(<?php echo (int) ($r['rowid'] ?? 0); ?>); return false;">🖨 Bon</button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="bh-sum-row"><td colspan="7" class="text-end"><?php echo $histKellnerBetragMode ? 'Summe Abrechnung' : 'Summe'; ?></td><td class="text-end"><?php echo ff_hist_eur($sumF); ?></td><td colspan="<?php echo ($histShowAbrechnung ? 5 : 4) + ($showActionsCol ? 1 : 0); ?>"></td></tr></tfoot>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$hasOrderSearch && !$hasBonSearch && !$hasFilter && $recentOrders !== []): ?>
    <div class="bh-result-card">
        <div class="card-header"><strong>Letzte Bestellungen &amp; Direktverkauf-Bons</strong> <span class="badge bg-secondary ms-2"><?php echo count($recentOrders); ?></span></div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover bh-table bh-sortable mb-0">
                <thead class="table-light"><tr>
                    <th class="bh-sort" data-sort-key="bestnr">Nr. / Bon<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="tisch">Tisch<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="kellner">KellnerIn<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort" data-sort-key="zeit">Zeitpunkt<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort text-center" data-sort-key="anzahl">Positionen<span class="bh-sort-ind"></span></th>
                    <th class="bh-sort text-end" data-sort-key="summe">Summe<span class="bh-sort-ind"></span></th>
                </tr></thead>
                <tbody>
                <?php foreach ($recentOrders as $o):
                    $tsR = !empty($o['ts']) ? strtotime($o['ts']) : 0;
                    $tischRS = mb_strtolower((string)($o['tischname'] ?? ''), 'UTF-8');
                    $kRecent = trim((string) ($o['kellner'] ?? ''));
                    $kRecentLbl = $kRecent !== '' ? ff_finance_kellner_label($conn, $kRecent) : '';
                    $oBon = trim((string) ($o['bon_id'] ?? ''));
                    $isDvRecent = $oBon !== '';
                    $recentHref = $isDvRecent
                        ? ('bestell_history.php?bon=' . rawurlencode($oBon))
                        : ('bestell_history.php?q=' . (int) ($o['order_nr'] ?? 0));
                    $recentSortKey = $isDvRecent ? $oBon : (string) (int) ($o['order_nr'] ?? 0);
                ?>
                <tr class="bh-recent-row" data-sort-bestnr="<?php echo htmlspecialchars($recentSortKey, ENT_QUOTES, 'UTF-8'); ?>" data-sort-tisch="<?php echo htmlspecialchars($tischRS, ENT_QUOTES, 'UTF-8'); ?>" data-sort-kellner="<?php echo htmlspecialchars(mb_strtolower($kRecentLbl, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>" data-sort-zeit="<?php echo (int)$tsR; ?>" data-sort-anzahl="<?php echo (int)$o['anzahl']; ?>" data-sort-summe="<?php echo (float)($o['summe'] ?? 0); ?>" onclick="window.location.href='<?php echo htmlspecialchars($recentHref, ENT_QUOTES, 'UTF-8'); ?>';">
                    <td><?php if ($isDvRecent): ?><span class="badge bg-dark">Bon <?php echo htmlspecialchars($oBon, ENT_QUOTES, 'UTF-8'); ?></span><?php else: ?><span class="badge bg-primary"><?php echo (int)$o['order_nr']; ?></span><?php endif; ?></td>
                    <td><?php echo htmlspecialchars($o['tischname'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($kRecentLbl, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo !empty($o['ts']) ? date('d.m.Y H:i', strtotime($o['ts'])) : '-'; ?></td>
                    <td class="text-center"><?php echo (int)$o['anzahl']; ?></td>
                    <td class="text-end"><?php echo ff_hist_eur($o['summe']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php elseif (!$hasOrderSearch && !$hasBonSearch && !$hasFilter): ?>
    <div class="text-center text-muted mt-5 py-4">
        <div style="font-size:3rem; opacity:0.3;">&#128203;</div>
        <p class="mt-2">Noch keine Bestellungen mit Bestell-Nr. oder Direktverkauf-Bons vorhanden.<br>Nutze die Filter oben, um zu suchen.</p>
    </div>
    <?php endif; ?>

</div>
<script>
(function () {
    function bhSortVal(tr, key) {
        var attr = tr.getAttribute('data-sort-' + key);
        if (attr === null || attr === '') {
            return '';
        }
        var s = String(attr).trim();
        if (/^-?\d+(\.\d+)?$/.test(s)) {
            return parseFloat(s);
        }
        return s.toLowerCase();
    }

    function bhApplySort(table, key, dir) {
        var tbody = table.tBodies[0];
        if (!tbody) {
            return;
        }
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        rows.sort(function (a, b) {
            var va = bhSortVal(a, key);
            var vb = bhSortVal(b, key);
            var cmp = 0;
            if (typeof va === 'number' && typeof vb === 'number') {
                cmp = va - vb;
            } else {
                cmp = String(va).localeCompare(String(vb), 'de', { numeric: true, sensitivity: 'base' });
            }
            return dir === 'desc' ? -cmp : cmp;
        });
        rows.forEach(function (r) {
            tbody.appendChild(r);
        });
        table.querySelectorAll('th.bh-sort').forEach(function (th) {
            th.classList.remove('bh-sort-active');
            var ind = th.querySelector('.bh-sort-ind');
            if (ind) {
                ind.textContent = '';
            }
        });
        var active = table.querySelector('th.bh-sort[data-sort-key="' + key + '"]');
        if (active) {
            active.classList.add('bh-sort-active');
            var indA = active.querySelector('.bh-sort-ind');
            if (indA) {
                indA.textContent = dir === 'desc' ? ' ▼' : ' ▲';
            }
        }
    }

    document.querySelectorAll('table.bh-sortable').forEach(function (table) {
        table.querySelectorAll('th.bh-sort').forEach(function (th) {
            th.addEventListener('click', function (ev) {
                if (ev.target.closest('a, button, input, select')) {
                    return;
                }
                var key = th.getAttribute('data-sort-key');
                if (!key) {
                    return;
                }
                var prevKey = table.getAttribute('data-bh-sort-key');
                var prevDir = table.getAttribute('data-bh-sort-dir') || 'asc';
                var dir = 'asc';
                if (prevKey === key && prevDir === 'asc') {
                    dir = 'desc';
                }
                table.setAttribute('data-bh-sort-key', key);
                table.setAttribute('data-bh-sort-dir', dir);
                bhApplySort(table, key, dir);
            });
        });
        // Filter-/Datums-Tabellen: neueste zuerst als Standard anzeigen
        if (table.querySelector('th.bh-sort[data-sort-key="datum"]')) {
            table.setAttribute('data-bh-sort-key', 'datum');
            table.setAttribute('data-bh-sort-dir', 'desc');
            bhApplySort(table, 'datum', 'desc');
        } else if (table.querySelector('th.bh-sort[data-sort-key="zeit"]')) {
            table.setAttribute('data-bh-sort-key', 'zeit');
            table.setAttribute('data-bh-sort-dir', 'desc');
            bhApplySort(table, 'zeit', 'desc');
        }
    });
})();

<?php if ($isAdmin): ?>
window.ffRunStornoBatch = function(sourceTisch, orderNr, batchTimestamp, confirmText, reloadFn, bonId) {
    if (!confirm(confirmText || 'Ganze Bestellung wirklich stornieren?')) {
        return;
    }
    var fd = new FormData();
    fd.append('source_tischnummer', String(sourceTisch));
    if (bonId && String(bonId).trim() !== '') {
        fd.append('bon_id', String(bonId).trim());
    } else if (parseInt(orderNr, 10) > 0) {
        fd.append('order_nr', String(orderNr));
    } else if (batchTimestamp) {
        fd.append('batch_timestamp', String(batchTimestamp));
    } else {
        alert('Bestellung konnte nicht identifiziert werden.');
        return;
    }
    fetch('bestellung_storno_batch.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json().then(function(j) { return { r: r, j: j }; }); })
        .then(function(x) {
            if (!x.r.ok || !x.j || !x.j.ok) {
                alert((x.j && (x.j.message || x.j.error)) ? (x.j.message || x.j.error) : 'Storno fehlgeschlagen.');
                return;
            }
            if (x.j.message) {
                alert(x.j.message);
            }
            if (typeof reloadFn === 'function') {
                reloadFn();
            } else {
                window.location.reload();
            }
        })
        .catch(function() { alert('Netzwerkfehler beim Storno.'); });
};
window.ffHistBonNachdruck = function(rowid) {
    if (!rowid) { return; }
    if (!confirm('Bon dieser Bestellung erneut an den Thermodrucker senden?')) { return; }
    var fd = new FormData();
    fd.append('rowid', String(rowid));
    fetch('bestell_history_bon_reprint.php', { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
        .then(function(x) {
            if (!x.ok || !x.j || !x.j.ok) {
                alert((x.j && x.j.error) ? ('Nachdruck fehlgeschlagen: ' + x.j.error) : 'Nachdruck fehlgeschlagen.');
                return;
            }
            alert(x.j.message || 'Bon-Nachdruck in Warteschlange.');
        })
        .catch(function() { alert('Netzwerkfehler beim Bon-Nachdruck.'); });
};
window.ffHistStornierenEine = function(rowid, tischnummer, isPaid, stillOpenAtStation) {
    var msg;
    if (isPaid && !stillOpenAtStation) {
        msg = 'Bezahlung wirklich stornieren (Rückerstattung)?\n\nDie Position war bereits ausgeliefert — nur die Bezahlung wird zurückgesetzt.\n\nZählt danach nicht mehr in der Kellner-Abrechnung.';
    } else if (isPaid) {
        msg = 'Position wirklich stornieren?\n\nBezahlung wird zurückgesetzt und die Position aus Küche/Schank entfernt (noch nicht ausgeliefert).';
    } else {
        msg = 'Position wirklich stornieren?\n\nSie wird aus Küche/Schank und der Druckwarteschlange entfernt.';
    }
    if (!confirm(msg)) {
        return;
    }
    var url = isPaid
        ? 'bestellung_bez_storno.php?rowid=' + encodeURIComponent(String(rowid))
        : 'bestellung_loeschen.php?rowid=' + encodeURIComponent(String(rowid));
    fetch(url, { cache: 'no-store', credentials: 'same-origin' })
        .then(function(r) { return r.json().then(function(j) { return { r: r, j: j }; }); })
        .then(function(x) {
            if (!x.r.ok || !x.j || !x.j.ok) {
                alert((x.j && x.j.message) ? x.j.message : 'Storno nicht möglich.');
                return;
            }
            if (x.j.message) {
                alert(x.j.message);
            }
            window.location.reload();
        })
        .catch(function() { alert('Netzwerkfehler beim Storno.'); });
};
window.ffHistStornierenBestellung = function(sourceTisch, orderNr, batchTimestamp, bonId, label) {
    if (typeof label === 'undefined') {
        label = (typeof bonId === 'string' ? bonId : '') || '';
        bonId = '';
    }
    if (typeof window.ffRunStornoBatch !== 'function') {
        alert('Storno-Funktion nicht geladen (nur Admin).');
        return;
    }
    window.ffRunStornoBatch(sourceTisch, orderNr, batchTimestamp, label, function() {
        window.location.reload();
    }, bonId || '');
};

document.addEventListener('click', function(e) {
    var btn = e.target && e.target.closest ? e.target.closest('[data-bh-storno-batch]') : null;
    if (!btn) return;
    e.preventDefault();
    window.ffHistStornierenBestellung(
        parseInt(btn.getAttribute('data-tisch'), 10) || 0,
        parseInt(btn.getAttribute('data-order-nr'), 10) || 0,
        btn.getAttribute('data-batch-ts') || '',
        btn.getAttribute('data-bon-id') || '',
        btn.getAttribute('data-label') || 'Ganze Bestellung wirklich stornieren?'
    );
});

window.ffHistBezStorno = function(rowid, tischnummer, stillOpenAtStation) {
    window.ffHistStornierenEine(rowid, tischnummer, true, stillOpenAtStation);
};
<?php endif; ?>

window.ffHistBonNachdruck = window.ffHistBonNachdruck || function(rowid) {
    if (!rowid) { return; }
    if (!confirm('Bon dieser Bestellung erneut an den Thermodrucker senden?')) { return; }
    var fd = new FormData();
    fd.append('rowid', String(rowid));
    fetch('bestell_history_bon_reprint.php', { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
        .then(function(x) {
            if (!x.ok || !x.j || !x.j.ok) {
                var msg = (x.j && (x.j.message || x.j.error)) ? (x.j.message || x.j.error) : 'Nachdruck fehlgeschlagen.';
                alert('Nachdruck fehlgeschlagen: ' + msg);
                return;
            }
            alert(x.j.message || 'Bon-Nachdruck in Warteschlange.');
        })
        .catch(function() { alert('Netzwerkfehler beim Bon-Nachdruck.'); });
};

<?php if ($currentUser !== '' || $isAdmin): ?>
// Tisch ändern: kleines Modal mit Tisch-Auswahl, Update über bestellung_verschieben.php.
window.ffHistTischChange = function(rowid, currentTisch) {
    var modal = document.getElementById('ffHistTischChangeModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'ffHistTischChangeModal';
        modal.style.cssText = 'display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.55);z-index:9999;padding:20px;';
        modal.innerHTML = '<div style="background:#fff;max-width:440px;margin:60px auto;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.3);">'
            + '<div style="padding:14px 16px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">'
            +   '<strong>Tisch ändern</strong>'
            +   '<button type="button" id="ffHistTischChangeClose" style="border:none;background:none;font-size:24px;cursor:pointer;">&times;</button>'
            + '</div>'
            + '<div style="padding:16px;">'
            +   '<p class="mb-2 small text-muted" id="ffHistTischChangeInfo">Aktueller Tisch: <strong></strong></p>'
            +   '<label class="form-label small">Neuer Tisch:</label>'
            +   '<select id="ffHistTischChangeSelect" class="form-select mb-3" style="font-size:16px;">'
            +     '<option value="">-- Tisch wählen --</option>'
            +   '</select>'
            +   '<div class="alert alert-warning small py-2 px-2 mb-3" style="margin:0 0 12px 0;">Der bereits gedruckte Bon zeigt weiterhin die alte Tischnummer — bitte ggf. der Küche / Schank Bescheid geben.</div>'
            +   '<div class="d-flex gap-2">'
            +     '<button type="button" class="btn btn-secondary flex-fill" id="ffHistTischChangeCancel">Abbrechen</button>'
            +     '<button type="button" class="btn btn-primary flex-fill" id="ffHistTischChangeOk">Verschieben</button>'
            +   '</div>'
            + '</div></div>';
        document.body.appendChild(modal);
        document.getElementById('ffHistTischChangeClose').addEventListener('click', function(){ modal.style.display = 'none'; });
        document.getElementById('ffHistTischChangeCancel').addEventListener('click', function(){ modal.style.display = 'none'; });
    }

    var info = modal.querySelector('#ffHistTischChangeInfo strong');
    if (info) {
        info.textContent = '#' + currentTisch;
    }

    var sel = document.getElementById('ffHistTischChangeSelect');
    sel.innerHTML = '<option value="">-- Tische werden geladen ... --</option>';

    fetch('list_tische_json.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r){ return r.json(); })
        .then(function(data){
            sel.innerHTML = '<option value="">-- Tisch wählen --</option>';
            if (data && data.ok && Array.isArray(data.tische)) {
                data.tische.forEach(function(t){
                    if (parseInt(t.tischnummer, 10) === parseInt(currentTisch, 10)) {
                        return; // aktuellen Tisch ausblenden
                    }
                    var opt = document.createElement('option');
                    opt.value = String(t.tischnummer);
                    opt.textContent = t.tischname + ' (#' + t.tischnummer + ')';
                    sel.appendChild(opt);
                });
            }
        })
        .catch(function(){
            sel.innerHTML = '<option value="">Fehler beim Laden der Tische</option>';
        });

    var okBtn = document.getElementById('ffHistTischChangeOk');
    okBtn.onclick = function() {
        var ziel = parseInt(sel.value, 10);
        if (!ziel || ziel <= 0) {
            alert('Bitte einen Zieltisch auswählen.');
            return;
        }
        var fd = new FormData();
        fd.append('listePositionen[]', String(rowid));
        fd.append('ziel_tischnummer', String(ziel));
        okBtn.disabled = true;
        fetch('bestellung_verschieben.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
                okBtn.disabled = false;
                if (!res || !res.ok) {
                    alert((res && res.error) ? res.error : 'Verschieben fehlgeschlagen.');
                    return;
                }
                if ((res.moved || 0) < 1) {
                    alert('Position konnte nicht verschoben werden (z. B. inzwischen ausgeliefert oder Berechtigung fehlt).');
                    return;
                }
                modal.style.display = 'none';
                window.location.reload();
            })
            .catch(function(){
                okBtn.disabled = false;
                alert('Netzwerkfehler beim Verschieben.');
            });
    };

    modal.style.display = 'block';
};

/** Ganze Bestellung (order_nr oder batch_timestamp) verschieben. */
window.ffHistTischChangeOrder = function(currentTisch, orderNr, batchTimestamp, labelText) {
    var modal = document.getElementById('ffHistTischChangeModal');
    if (!modal) {
        window.ffHistTischChange(0, currentTisch);
        modal = document.getElementById('ffHistTischChangeModal');
    }
    var infoEl = document.getElementById('ffHistTischChangeInfo');
    if (infoEl) {
        infoEl.textContent = labelText || ('Ganze Bestellung von Tisch #' + currentTisch);
    }
    var sel = document.getElementById('ffHistTischChangeSelect');
    if (sel) {
        sel.innerHTML = '<option value="">-- Tische werden geladen ... --</option>';
        fetch('list_tische_json.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r){ return r.json(); })
            .then(function(data){
                sel.innerHTML = '<option value="">-- Tisch wählen --</option>';
                if (data && data.ok && Array.isArray(data.tische)) {
                    data.tische.forEach(function(t){
                        if (parseInt(t.tischnummer, 10) === parseInt(currentTisch, 10)) return;
                        var opt = document.createElement('option');
                        opt.value = String(t.tischnummer);
                        opt.textContent = t.tischname + ' (#' + t.tischnummer + ')';
                        sel.appendChild(opt);
                    });
                }
            });
    }
    var okBtn = document.getElementById('ffHistTischChangeOk');
    if (okBtn) {
        okBtn.onclick = function() {
            var ziel = parseInt(sel.value, 10);
            if (!ziel || ziel <= 0) {
                alert('Bitte einen Zieltisch auswählen.');
                return;
            }
            var fd = new FormData();
            fd.append('ziel_tischnummer', String(ziel));
            fd.append('source_tischnummer', String(currentTisch));
            if (parseInt(orderNr, 10) > 0) {
                fd.append('order_nr', String(orderNr));
            } else if (batchTimestamp) {
                fd.append('batch_timestamp', String(batchTimestamp));
            } else {
                alert('Bestellung konnte nicht identifiziert werden.');
                return;
            }
            okBtn.disabled = true;
            fetch('bestellung_verschieben.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    okBtn.disabled = false;
                    if (!res || !res.ok || (res.moved || 0) < 1) {
                        alert((res && res.error) ? res.error : 'Verschieben fehlgeschlagen oder keine Position verschoben.');
                        return;
                    }
                    alert((res.moved || 0) + ' Position(en) verschoben.');
                    modal.style.display = 'none';
                    window.location.reload();
                })
                .catch(function(){
                    okBtn.disabled = false;
                    alert('Netzwerkfehler beim Verschieben.');
                });
        };
    }
    modal.style.display = 'block';
};
<?php endif; ?>
</script>
<?php if ($fromTisch && $tableNo > 0): ?>
<script>
(function () {
    try {
        sessionStorage.setItem('ff_tisch_hist_return', <?php echo json_encode($tischReturn, JSON_UNESCAPED_UNICODE); ?>);
        sessionStorage.setItem('ff_tisch_hist_require_pay', <?php echo $tischRequirePay ? '"1"' : '"0"'; ?>);
    } catch (e) { /* ignore */ }
})();
</script>
<?php endif; ?>
</body>
</html>
