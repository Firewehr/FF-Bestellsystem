<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_finance_auth.php';
require_once __DIR__ . '/include/ff_schreibaus.php';
require_once __DIR__ . '/include/ff_finance_bereich_helpers.php';
require_once __DIR__ . '/include/ff_finance_undo.php';
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');
ff_finance_require($conn);
ff_finance_ensure_schema($conn);
ff_schreibaus_ensure_column($conn);

$by = (string) ($_SESSION['user']['username'] ?? '');
$action = trim((string) ($_REQUEST['action'] ?? 'preview'));

function ff_kellner_parse_dt(string $raw): ?string
{
    $raw = trim(str_replace('T', ' ', $raw));
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

function ff_kellner_api_scope_from_request(): string
{
    $s = trim((string) ($_REQUEST['scope'] ?? 'kellner'));
    return $s === 'dv' || $s === 'direktverkauf' ? 'dv' : 'kellner';
}

/** @return array{where:string,von:?string,bis:?string} */
function ff_kellner_build_open_orders_filter(mysqli $conn, string $kellner, ?string $von, ?string $bis, string $scope = 'kellner'): array
{
    $kEsc = mysqli_real_escape_string($conn, $kellner);
    $fest = ff_finance_fest_filter_sql($conn, 'b');
    $tisch = $scope === 'dv' ? ' AND b.tischnummer = 999999 ' : ' AND b.tischnummer <> 999999 ';
    $base = ff_finance_order_paid_base_sql('b') . " AND b.kellnerZahlung = '{$kEsc}' AND b.settlement_id IS NULL{$fest}{$tisch}";
    $where = $base;
    if ($von !== null) {
        $v = mysqli_real_escape_string($conn, $von);
        $where .= " AND b.timestampBezahlung >= '{$v}'";
    }
    if ($bis !== null) {
        $b = mysqli_real_escape_string($conn, $bis);
        $where .= " AND b.timestampBezahlung <= '{$b}'";
    }
    return ['where' => $where, 'von' => $von, 'bis' => $bis];
}

/** @return array{von:string,bis:string}|null */
function ff_kellner_range_from_open_orders(mysqli $conn, string $where): ?array
{
    $sql = "SELECT MIN(b.timestampBezahlung) AS von_dt, MAX(b.timestampBezahlung) AS bis_dt
        FROM bestellungen b WHERE {$where}";
    $res = mysqli_query($conn, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row || empty($row['von_dt']) || $row['von_dt'] === '0000-00-00 00:00:00') {
        return null;
    }
    return [
        'von' => (string) $row['von_dt'],
        'bis' => (string) $row['bis_dt'],
    ];
}

if ($action === 'list_open_summary') {
    $scope = ff_kellner_api_scope_from_request();
    $tisch = $scope === 'dv' ? ' AND b.tischnummer = 999999 ' : ' AND b.tischnummer <> 999999 ';
    $fest = ff_finance_fest_filter_sql($conn, 'b');
    $amt = ff_finance_order_amount_expr('b', 'p');
    $sql = "SELECT b.kellnerZahlung AS kellner_login, COUNT(*) AS cnt, COALESCE(SUM({$amt}), 0) AS umsatz
        FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position
        WHERE " . ff_finance_order_paid_base_sql('b') . "
        AND b.kellnerZahlung IS NOT NULL AND b.kellnerZahlung <> ''
        AND b.settlement_id IS NULL{$fest}{$tisch}
        GROUP BY b.kellnerZahlung
        ORDER BY umsatz DESC";
    $rows = [];
    $res = mysqli_query($conn, $sql);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $login = (string) $r['kellner_login'];
        $label = ff_finance_kellner_label($conn, $login);
        $mov = ['entnahmen' => 0.0, 'zuzahlungen' => 0.0];
        $kEsc = mysqli_real_escape_string($conn, $login);
        $mr = mysqli_query(
            $conn,
            "SELECT typ, COALESCE(SUM(betrag),0) AS s FROM kellner_bewegungen WHERE kellner_login = '{$kEsc}' AND settlement_id IS NULL GROUP BY typ"
        );
        while ($mr && ($m = mysqli_fetch_assoc($mr))) {
            if ($m['typ'] === 'entnahme') {
                $mov['entnahmen'] = (float) $m['s'];
            } else {
                $mov['zuzahlungen'] = (float) $m['s'];
            }
        }
        $umsatz = round((float) $r['umsatz'], 2);
        $rows[] = [
            'kellner_login' => $login,
            'label' => $label,
            'positionen' => (int) $r['cnt'],
            'umsatz_soll' => $umsatz,
            'umsatz_abgabe' => round($umsatz - $mov['entnahmen'] + $mov['zuzahlungen'], 2),
            'entnahmen' => round($mov['entnahmen'], 2),
            'zuzahlungen' => round($mov['zuzahlungen'], 2),
        ];
    }
    echo json_encode(['ok' => true, 'kellner' => $rows, 'scope' => $scope], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list_movements') {
    $kellner = trim((string) ($_GET['kellner'] ?? $_POST['kellner'] ?? ''));
    if ($kellner === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_params']);
        exit;
    }
    $st = mysqli_prepare(
        $conn,
        'SELECT id, typ, betrag, notiz, created_at, created_by FROM kellner_bewegungen WHERE kellner_login = ? AND settlement_id IS NULL ORDER BY created_at DESC LIMIT 50'
    );
    mysqli_stmt_bind_param($st, 's', $kellner);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = [
            'id' => (int) $r['id'],
            'typ' => (string) $r['typ'],
            'betrag' => round((float) $r['betrag'], 2),
            'notiz' => (string) ($r['notiz'] ?? ''),
            'created_at' => (string) $r['created_at'],
            'created_by' => (string) ($r['created_by'] ?? ''),
        ];
    }
    mysqli_stmt_close($st);
    echo json_encode(['ok' => true, 'movements' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @return list<array{login:string,label:string}>
 */
function ff_kellner_finance_list_kellner(mysqli $conn, string $scope): array
{
    $tisch = $scope === 'dv' ? ' AND b.tischnummer = 999999 ' : ' AND b.tischnummer <> 999999 ';
    $fest = ff_finance_fest_filter_sql($conn, 'b');
    $seen = [];
    $rows = [];
    $add = static function (string $login) use (&$seen, &$rows, $conn): void {
        $login = trim($login);
        if ($login === '' || isset($seen[$login])) {
            return;
        }
        $seen[$login] = true;
        $rows[] = ['login' => $login, 'label' => ff_finance_kellner_label($conn, $login)];
    };

    $sql = "SELECT DISTINCT b.kellnerZahlung AS k FROM bestellungen b
        WHERE b.kellnerZahlung IS NOT NULL AND b.kellnerZahlung <> ''{$fest}{$tisch}
        ORDER BY k";
    $res = mysqli_query($conn, $sql);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $add((string) ($r['k'] ?? ''));
    }

    $openSql = "SELECT DISTINCT b.kellnerZahlung AS k FROM bestellungen b
        WHERE " . ff_finance_order_paid_base_sql('b') . "
        AND b.kellnerZahlung IS NOT NULL AND b.kellnerZahlung <> ''
        AND b.settlement_id IS NULL{$fest}{$tisch}";
    $resO = mysqli_query($conn, $openSql);
    while ($resO && ($r = mysqli_fetch_assoc($resO))) {
        $add((string) ($r['k'] ?? ''));
    }

    $sqlOrdK = "SELECT DISTINCT b.kellner AS k FROM bestellungen b
        WHERE b.kellner IS NOT NULL AND b.kellner <> '' AND b.`delete` = 0{$fest}{$tisch}
        ORDER BY k";
    $resOrdK = mysqli_query($conn, $sqlOrdK);
    while ($resOrdK && ($r = mysqli_fetch_assoc($resOrdK))) {
        $add((string) ($r['k'] ?? ''));
    }

    $resMov = mysqli_query(
        $conn,
        "SELECT DISTINCT kellner_login AS k FROM kellner_bewegungen
         WHERE settlement_id IS NULL AND kellner_login IS NOT NULL AND kellner_login <> ''
         ORDER BY k"
    );
    while ($resMov && ($r = mysqli_fetch_assoc($resMov))) {
        $add((string) ($r['k'] ?? ''));
    }

    require_once __DIR__ . '/include/admin_statistik_body.php';
    foreach (ff_admin_statistik_usernames($conn) as $login) {
        $add($login);
    }

    usort($rows, static function (array $a, array $b): int {
        return strcasecmp((string) $a['label'], (string) $b['label']);
    });

    return $rows;
}

if ($action === 'list_kellner') {
    $scope = ff_kellner_api_scope_from_request();
    echo json_encode(['ok' => true, 'kellner' => ff_kellner_finance_list_kellner($conn, $scope)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'history') {
    $scope = ff_kellner_api_scope_from_request();
    $scopeDb = $scope === 'dv' ? 'direktverkauf' : 'kellner';
    $scopeEsc = mysqli_real_escape_string($conn, $scopeDb);
    $history = [];
    $res = mysqli_query(
        $conn,
        "SELECT * FROM kellner_settlements WHERE settlement_scope = '{$scopeEsc}' ORDER BY created_at DESC LIMIT 30"
    );
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $login = (string) ($r['kellner_login'] ?? '');
        $r['kellner_label'] = ff_finance_kellner_label($conn, $login);
        $history[] = $r;
    }
    echo json_encode(['ok' => true, 'history' => $history], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'preview') {
    $scope = ff_kellner_api_scope_from_request();
    $kellner = trim((string) ($_GET['kellner'] ?? $_POST['kellner'] ?? ''));
    $von = ff_kellner_parse_dt((string) ($_GET['von'] ?? $_POST['von'] ?? ''));
    $bis = ff_kellner_parse_dt((string) ($_GET['bis'] ?? $_POST['bis'] ?? ''));
    if ($kellner === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_kellner']);
        exit;
    }
    if ($von !== null && $bis !== null && strtotime($von) > strtotime($bis)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_range']);
        exit;
    }
    $filter = ff_kellner_build_open_orders_filter($conn, $kellner, $von, $bis, $scope);
    $kEsc = mysqli_real_escape_string($conn, $kellner);
    $rangeLabel = ff_kellner_range_from_open_orders($conn, $filter['where']);
    require_once __DIR__ . '/include/ff_table_display.php';
    $amt = ff_finance_order_amount_expr('b', 'p');
    $sql = "SELECT b.rowid, {$amt} AS line_amt, p.Positionsname, b.timestampBezahlung, b.kellnerZahlung,
        b.tischnummer, t.tischname, COALESCE(b.bon_id, '') AS bon_id
        FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position
        LEFT JOIN tische t ON t.tischnummer = b.tischnummer
        WHERE {$filter['where']}
        ORDER BY b.timestampBezahlung ASC";
    $lines = [];
    $sum = 0.0;
    $res = mysqli_query($conn, $sql);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $amt = (float) ($r['line_amt'] ?? 0);
        $sum += $amt;
        $td = ff_table_display_from_row($r);
        $lines[] = [
            'rowid' => (int) $r['rowid'],
            'name' => (string) $r['Positionsname'],
            'betrag' => round($amt, 2),
            'bezahlt' => (string) $r['timestampBezahlung'],
            'tisch' => (int) ($r['tischnummer'] ?? 0),
            'tischname' => (string) ($td['tischname'] ?? ''),
            'tisch_label' => (string) ($td['display_table'] ?? ''),
        ];
    }
    $mov = ['entnahmen' => 0.0, 'zuzahlungen' => 0.0];
    $mr = mysqli_query(
        $conn,
        "SELECT typ, COALESCE(SUM(betrag),0) AS s FROM kellner_bewegungen WHERE kellner_login = '{$kEsc}' AND settlement_id IS NULL GROUP BY typ"
    );
    while ($mr && ($m = mysqli_fetch_assoc($mr))) {
        if ($m['typ'] === 'entnahme') {
            $mov['entnahmen'] = (float) $m['s'];
        } else {
            $mov['zuzahlungen'] = (float) $m['s'];
        }
    }
    $umsatzSoll = round($sum, 2);
    $umsatzAbgabe = round($umsatzSoll - $mov['entnahmen'] + $mov['zuzahlungen'], 2);
    $breakdown = ff_kellner_paid_breakdown($conn, $kellner, $scope);
    $settledLines = [];
    if ($breakdown['settled']['count'] > 0) {
        require_once __DIR__ . '/include/ff_table_display.php';
        $tischSt = $scope === 'dv' ? ' AND b.tischnummer = 999999 ' : ' AND b.tischnummer <> 999999 ';
        $scopeKs = $scope === 'dv'
            ? "ks.settlement_scope = 'direktverkauf'"
            : "(ks.settlement_scope = 'kellner' OR ks.settlement_scope IS NULL OR ks.settlement_scope = '')";
        $sqlSt = "SELECT b.rowid, {$amt} AS line_amt, p.Positionsname, b.timestampBezahlung, b.settlement_id,
            b.tischnummer, t.tischname, COALESCE(b.bon_id, '') AS bon_id, ks.created_at AS settled_at
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            LEFT JOIN tische t ON t.tischnummer = b.tischnummer
            INNER JOIN kellner_settlements ks ON ks.id = b.settlement_id
                AND (ks.voided_at IS NULL OR ks.voided_at = '0000-00-00 00:00:00') AND {$scopeKs}
            WHERE " . ff_finance_order_paid_base_sql('b') . " AND b.kellnerZahlung = '{$kEsc}'{$tischSt}
            ORDER BY b.timestampBezahlung ASC LIMIT 200";
        $resSt = mysqli_query($conn, $sqlSt);
        while ($resSt && ($r = mysqli_fetch_assoc($resSt))) {
            $td = ff_table_display_from_row($r);
            $settledLines[] = [
                'rowid' => (int) $r['rowid'],
                'name' => (string) $r['Positionsname'],
                'betrag' => round((float) ($r['line_amt'] ?? 0), 2),
                'bezahlt' => (string) $r['timestampBezahlung'],
                'settlement_id' => (int) ($r['settlement_id'] ?? 0),
                'settled_at' => (string) ($r['settled_at'] ?? ''),
                'tisch_label' => (string) ($td['display_table'] ?? ''),
            ];
        }
    }
    $stornoNachLines = [];
    $scopeKsSt = $scope === 'dv'
        ? "ks.settlement_scope = 'direktverkauf'"
        : "(ks.settlement_scope = 'kellner' OR ks.settlement_scope IS NULL OR ks.settlement_scope = '')";
    $tischSt2 = $scope === 'dv' ? ' AND b.tischnummer = 999999 ' : ' AND b.tischnummer <> 999999 ';
    $sqlSto = "SELECT b.rowid, {$amt} AS line_amt, p.Positionsname, b.timestampBezahlung, b.settlement_id,
            ks.created_at AS settled_at
        FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position
        INNER JOIN kellner_settlements ks ON ks.id = b.settlement_id
            AND (ks.voided_at IS NULL OR ks.voided_at = '0000-00-00 00:00:00') AND {$scopeKsSt}
        WHERE b.`delete` = 1 AND b.settlement_id IS NOT NULL
        AND b.kellnerZahlung = '{$kEsc}'{$tischSt2}
        ORDER BY b.timestampBezahlung ASC LIMIT 100";
    $resSto = mysqli_query($conn, $sqlSto);
    while ($resSto && ($r = mysqli_fetch_assoc($resSto))) {
        $stornoNachLines[] = [
            'rowid' => (int) $r['rowid'],
            'name' => (string) $r['Positionsname'],
            'betrag' => round((float) ($r['line_amt'] ?? 0), 2),
            'bezahlt' => (string) $r['timestampBezahlung'],
            'settlement_id' => (int) ($r['settlement_id'] ?? 0),
            'settled_at' => (string) ($r['settled_at'] ?? ''),
            'storniert' => true,
        ];
    }
    echo json_encode([
        'ok' => true,
        'kellner_login' => $kellner,
        'kellner_label' => ff_finance_kellner_label($conn, $kellner),
        'umsatz_soll' => $umsatzSoll,
        'umsatz_abgabe' => $umsatzAbgabe,
        'lines' => $lines,
        'movements' => $mov,
        'zeitraum_von' => $rangeLabel['von'] ?? $von,
        'zeitraum_bis' => $rangeLabel['bis'] ?? $bis,
        'alle_offenen' => ($von === null && $bis === null),
        'breakdown' => $breakdown,
        'settled_lines' => $settledLines,
        'storno_nach_abrechnung_lines' => $stornoNachLines,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'movement') {
    $kellner = trim((string) ($_POST['kellner'] ?? ''));
    $typ = (string) ($_POST['typ'] ?? '');
    $betrag = (float) str_replace(',', '.', (string) ($_POST['betrag'] ?? '0'));
    $notiz = trim((string) ($_POST['notiz'] ?? ''));
    if ($kellner === '' || $betrag <= 0 || ($typ !== 'entnahme' && $typ !== 'zuzahlung')) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_params']);
        exit;
    }
    $st = mysqli_prepare($conn, 'INSERT INTO kellner_bewegungen (kellner_login, typ, betrag, notiz, created_by) VALUES (?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($st, 'ssdss', $kellner, $typ, $betrag, $notiz, $by);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'unsettle') {
    ff_finance_super_admin_require($conn);
    $settlementId = (int) ($_POST['settlement_id'] ?? 0);
    $phrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if (!ff_finance_undo_confirm_phrase_ok($phrase)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'confirm_phrase'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = ff_kellner_unsettle($conn, $settlementId, $by, $reason);
    if (!$result['ok']) {
        http_response_code(400);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'settle') {
    $scope = ff_kellner_api_scope_from_request();
    $scopeDb = $scope === 'dv' ? 'direktverkauf' : 'kellner';
    $kellner = trim((string) ($_POST['kellner'] ?? ''));
    $von = ff_kellner_parse_dt((string) ($_POST['von'] ?? ''));
    $bis = ff_kellner_parse_dt((string) ($_POST['bis'] ?? ''));
    $abgegeben = (float) str_replace(',', '.', (string) ($_POST['betrag_abgegeben'] ?? '0'));
    $wechsel = (float) str_replace(',', '.', (string) ($_POST['wechselgeld_zurueck'] ?? '0'));
    $notiz = trim((string) ($_POST['notiz'] ?? ''));
    if ($kellner === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_kellner']);
        exit;
    }
    if ($von !== null && $bis !== null && strtotime($von) > strtotime($bis)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_range']);
        exit;
    }
    $filter = ff_kellner_build_open_orders_filter($conn, $kellner, $von, $bis, $scope);
    $kEsc = mysqli_real_escape_string($conn, $kellner);
    $amtExpr = ff_finance_order_amount_expr('b', 'p');
    $sql = "SELECT b.rowid, {$amtExpr} AS amt, b.timestampBezahlung
        FROM bestellungen b JOIN positionen p ON p.rowid = b.position
        WHERE {$filter['where']}";
    $ids = [];
    $umsatz = 0.0;
    $minTs = null;
    $maxTs = null;
    $res = mysqli_query($conn, $sql);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $ids[] = (int) $r['rowid'];
        $umsatz += (float) $r['amt'];
        $ts = (string) $r['timestampBezahlung'];
        if ($minTs === null || $ts < $minTs) {
            $minTs = $ts;
        }
        if ($maxTs === null || $ts > $maxTs) {
            $maxTs = $ts;
        }
    }
    if ($ids === []) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'no_open_orders']);
        exit;
    }
    $vonStore = $von ?? $minTs ?? date('Y-m-d H:i:s');
    $bisStore = $bis ?? $maxTs ?? date('Y-m-d H:i:s');
    $umsatz = round($umsatz, 2);
    $mov = ['entnahmen' => 0.0, 'zuzahlungen' => 0.0];
    $mr = mysqli_query(
        $conn,
        "SELECT typ, COALESCE(SUM(betrag),0) AS s FROM kellner_bewegungen WHERE kellner_login = '{$kEsc}' AND settlement_id IS NULL GROUP BY typ"
    );
    while ($mr && ($m = mysqli_fetch_assoc($mr))) {
        if ($m['typ'] === 'entnahme') {
            $mov['entnahmen'] = (float) $m['s'];
        } else {
            $mov['zuzahlungen'] = (float) $m['s'];
        }
    }
    $umsatzAbgabe = round($umsatz - $mov['entnahmen'] + $mov['zuzahlungen'], 2);
    $trinkgeld = round($abgegeben - $wechsel - $umsatzAbgabe, 2);
    mysqli_begin_transaction($conn);
    $st = mysqli_prepare(
        $conn,
        'INSERT INTO kellner_settlements (kellner_login, settlement_scope, von_dt, bis_dt, umsatz_soll, betrag_abgegeben, wechselgeld_zurueck, trinkgeld, notiz, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    mysqli_stmt_bind_param($st, 'ssssddddss', $kellner, $scopeDb, $vonStore, $bisStore, $umsatz, $abgegeben, $wechsel, $trinkgeld, $notiz, $by);
    mysqli_stmt_execute($st);
    $settlementId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($st);
    if ($ids !== []) {
        $idList = implode(',', array_map('intval', $ids));
        mysqli_query(
            $conn,
            "UPDATE bestellungen SET settlement_id = {$settlementId}, settled_at = NOW(), settled_by = '" . mysqli_real_escape_string($conn, $by) . "' WHERE rowid IN ({$idList})"
        );
    }
    mysqli_query($conn, 'UPDATE kellner_bewegungen SET settlement_id = ' . $settlementId . " WHERE kellner_login = '{$kEsc}' AND settlement_id IS NULL");
    mysqli_commit($conn);
    echo json_encode([
        'ok' => true,
        'settlement_id' => $settlementId,
        'umsatz_soll' => $umsatz,
        'umsatz_abgabe' => $umsatzAbgabe,
        'entnahmen' => round($mov['entnahmen'], 2),
        'zuzahlungen' => round($mov['zuzahlungen'], 2),
        'trinkgeld' => $trinkgeld,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$history = [];
$res = mysqli_query($conn, 'SELECT * FROM kellner_settlements ORDER BY created_at DESC LIMIT 30');
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $login = (string) ($r['kellner_login'] ?? '');
    $r['kellner_label'] = ff_finance_kellner_label($conn, $login);
    $history[] = $r;
}
echo json_encode(['ok' => true, 'history' => $history], JSON_UNESCAPED_UNICODE);
