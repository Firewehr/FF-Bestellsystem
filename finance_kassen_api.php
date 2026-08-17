<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_finance_auth.php';
require_once __DIR__ . '/include/ff_finance_bereich_helpers.php';
require_once __DIR__ . '/include/ff_finance_undo.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');
ff_finance_require($conn);

$by = (string) ($_SESSION['user']['username'] ?? '');
$action = trim((string) ($_REQUEST['action'] ?? 'status'));

function ff_kassen_calc_revenue(float $closing, float $opening, float $entnahmen, float $zuzahlungen): float
{
    return round($closing - $opening + $entnahmen - $zuzahlungen, 2);
}

if ($action === 'list_bereiche') {
    $rows = [];
    $r = mysqli_query($conn, 'SELECT * FROM kassen_bereiche ORDER BY is_active DESC, name ASC');
    while ($r && ($x = mysqli_fetch_assoc($r))) {
        $rows[] = $x;
    }
    echo json_encode(['ok' => true, 'bereiche' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_bereich') {
    ff_finance_ensure_schema($conn);
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $active = isset($_POST['is_active']) && (string) $_POST['is_active'] !== '0' ? 1 : 0;
    $kontrolleOnly = isset($_POST['kontrolle_only']) && (string) $_POST['kontrolle_only'] !== '0' ? 1 : 0;
    if ($id > 0 && $name === '') {
        $stCur = mysqli_prepare($conn, 'SELECT name FROM kassen_bereiche WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($stCur, 'i', $id);
        mysqli_stmt_execute($stCur);
        $resCur = mysqli_stmt_get_result($stCur);
        $rowCur = $resCur ? mysqli_fetch_assoc($resCur) : null;
        mysqli_stmt_close($stCur);
        $name = trim((string) ($rowCur['name'] ?? ''));
    }
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_name']);
        exit;
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 128, 'UTF-8');
    }
    if ($id > 0) {
        $st = mysqli_prepare($conn, 'UPDATE kassen_bereiche SET name = ?, is_active = ?, kontrolle_only = ? WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($st, 'siii', $name, $active, $kontrolleOnly, $id);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    } else {
        $st = mysqli_prepare($conn, 'INSERT INTO kassen_bereiche (name, is_active, kontrolle_only) VALUES (?, ?, ?)');
        mysqli_stmt_bind_param($st, 'sii', $name, $active, $kontrolleOnly);
        mysqli_stmt_execute($st);
        $id = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($st);
    }
    echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete_bereich') {
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    $result = ff_finance_delete_bereich($conn, $id);
    if (!$result['ok']) {
        http_response_code(400);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'open') {
    $bereichId = (int) ($_POST['bereich_id'] ?? 0);
    $opening = (float) str_replace(',', '.', (string) ($_POST['opening_amount'] ?? '0'));
    if ($bereichId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_bereich']);
        exit;
    }
    $chk = mysqli_query($conn, 'SELECT id FROM kassen_sessions WHERE bereich_id = ' . $bereichId . " AND status = 'open' LIMIT 1");
    if ($chk && mysqli_num_rows($chk) > 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'already_open']);
        exit;
    }
    $st = mysqli_prepare($conn, 'INSERT INTO kassen_sessions (bereich_id, status, opening_amount, opened_at, opened_by) VALUES (?, \'open\', ?, NOW(), ?)');
    mysqli_stmt_bind_param($st, 'ids', $bereichId, $opening, $by);
    mysqli_stmt_execute($st);
    $sid = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($st);
    echo json_encode(['ok' => true, 'session_id' => $sid], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'movement') {
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    $typ = (string) ($_POST['typ'] ?? '');
    $betrag = (float) str_replace(',', '.', (string) ($_POST['betrag'] ?? '0'));
    $notiz = trim((string) ($_POST['notiz'] ?? ''));
    if ($sessionId <= 0 || $betrag <= 0 || ($typ !== 'entnahme' && $typ !== 'zuzahlung')) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_params']);
        exit;
    }
    $chk = mysqli_query($conn, "SELECT id FROM kassen_sessions WHERE id = {$sessionId} AND status = 'open' LIMIT 1");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'session_not_open']);
        exit;
    }
    $st = mysqli_prepare($conn, 'INSERT INTO kassen_bewegungen (session_id, typ, betrag, notiz, created_by) VALUES (?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($st, 'isdss', $sessionId, $typ, $betrag, $notiz, $by);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'close') {
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    $closing = (float) str_replace(',', '.', (string) ($_POST['closing_amount'] ?? '0'));
    if ($sessionId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_session']);
        exit;
    }
    $res = mysqli_query($conn, 'SELECT * FROM kassen_sessions WHERE id = ' . $sessionId . " AND status = 'open' LIMIT 1");
    $sess = $res ? mysqli_fetch_assoc($res) : null;
    if (!$sess) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'session_not_open']);
        exit;
    }
    $mov = ff_kassen_session_movements_sum($conn, $sessionId);
    $revenue = ff_kassen_calc_revenue($closing, (float) $sess['opening_amount'], $mov['entnahmen'], $mov['zuzahlungen']);
    $st = mysqli_prepare(
        $conn,
        'UPDATE kassen_sessions SET status = \'closed\', closing_amount = ?, revenue_amount = ?, closed_at = NOW(), closed_by = ? WHERE id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($st, 'dssi', $closing, $revenue, $by, $sessionId);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    echo json_encode([
        'ok' => true,
        'revenue_amount' => $revenue,
        'entnahmen' => $mov['entnahmen'],
        'zuzahlungen' => $mov['zuzahlungen'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'reopen') {
    ff_finance_super_admin_require($conn);
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    $phrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if (!ff_finance_undo_confirm_phrase_ok($phrase)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'confirm_phrase'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = ff_kassen_reopen($conn, $sessionId, $by, $reason);
    if (!$result['ok']) {
        http_response_code(400);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list_closed') {
    $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
    $vonRaw = trim((string) ($_GET['von'] ?? ''));
    $bisRaw = trim((string) ($_GET['bis'] ?? ''));
    $range = ff_finance_parse_datetime_range($vonRaw, $bisRaw);
    $whereKasse = '';
    if ($range !== null) {
        $vonEsc = mysqli_real_escape_string($conn, $range['von_sql']);
        $bisEsc = mysqli_real_escape_string($conn, $range['bis_sql']);
        $whereKasse = " AND s.closed_at >= '{$vonEsc}' AND s.closed_at <= '{$bisEsc}' ";
    }
    $rows = [];
    $sql = "SELECT s.*, b.name AS bereich_name FROM kassen_sessions s
        JOIN kassen_bereiche b ON b.id = s.bereich_id
        WHERE s.status = 'closed' {$whereKasse}
        ORDER BY s.closed_at DESC LIMIT {$limit}";
    $res = mysqli_query($conn, $sql);
    while ($res && ($s = mysqli_fetch_assoc($res))) {
        $sid = (int) ($s['id'] ?? 0);
        $mov = ff_kassen_session_movements_sum($conn, $sid);
        $moves = [];
        $mr = mysqli_query($conn, 'SELECT * FROM kassen_bewegungen WHERE session_id = ' . $sid . ' ORDER BY created_at ASC');
        while ($mr && ($m = mysqli_fetch_assoc($mr))) {
            $moves[] = $m;
        }
        $rows[] = [
            'session' => $s,
            'movements' => $moves,
            'entnahmen' => $mov['entnahmen'],
            'zuzahlungen' => $mov['zuzahlungen'],
        ];
    }
    echo json_encode(['ok' => true, 'closed_sessions' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

// status: Bereiche + offene Sessions + letzte Bewegungen
$out = ['bereiche' => [], 'open_sessions' => [], 'recent_closed' => []];
$r1 = mysqli_query($conn, 'SELECT b.*, s.id AS session_id, s.opening_amount, s.opened_at, s.opened_by FROM kassen_bereiche b LEFT JOIN kassen_sessions s ON s.bereich_id = b.id AND s.status = \'open\' WHERE b.is_active = 1 ORDER BY b.name');
if ($r1) {
    while ($row = mysqli_fetch_assoc($r1)) {
        $out['bereiche'][] = $row;
        if (!empty($row['session_id'])) {
            $sid = (int) $row['session_id'];
            $mov = ff_kassen_session_movements_sum($conn, $sid);
            $moves = [];
            $mr = mysqli_query($conn, 'SELECT * FROM kassen_bewegungen WHERE session_id = ' . $sid . ' ORDER BY created_at ASC');
            while ($mr && ($m = mysqli_fetch_assoc($mr))) {
                $moves[] = $m;
            }
            $out['open_sessions'][] = [
                'session' => $row,
                'movements' => $moves,
                'entnahmen' => $mov['entnahmen'],
                'zuzahlungen' => $mov['zuzahlungen'],
            ];
        }
    }
}
$r2 = mysqli_query(
    $conn,
    'SELECT s.*, b.name AS bereich_name FROM kassen_sessions s JOIN kassen_bereiche b ON b.id = s.bereich_id WHERE s.status = \'closed\' ORDER BY s.closed_at DESC LIMIT 20'
);
if ($r2) {
    while ($row = mysqli_fetch_assoc($r2)) {
        $out['recent_closed'][] = $row;
    }
}
echo json_encode(['ok' => true] + $out, JSON_UNESCAPED_UNICODE);
