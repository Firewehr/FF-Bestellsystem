<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_finance_auth.php';
require_once __DIR__ . '/include/ff_finance_bereich_helpers.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');
ff_finance_require($conn);

$action = trim((string) ($_REQUEST['action'] ?? 'evaluate'));

if ($action === 'list_bereiche') {
    echo json_encode(['ok' => true, 'bereiche' => ff_finance_list_bereiche($conn, false)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list_print_targets') {
    ff_finance_ensure_schema($conn);
    ff_finance_ensure_kellner_direkt_print_targets($conn);
    $rows = [];
    $fixed = [];
    $res = mysqli_query($conn, 'SELECT print_target, name, COALESCE(finance_bereich_id, 0) AS finance_bereich_id FROM print_targets ORDER BY sort_order, name');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $name = (string) $r['name'];
        $isFixed = ff_finance_print_target_is_kellner_direkt_fixed($name);
        $row = [
            'print_target' => (int) $r['print_target'],
            'name' => $name,
            'finance_bereich_id' => $isFixed ? 0 : (int) $r['finance_bereich_id'],
            'is_kellner_direkt_fixed' => $isFixed,
        ];
        if ($isFixed) {
            $fixed[] = $row;
        } else {
            $rows[] = $row;
        }
    }
    echo json_encode([
        'ok' => true,
        'print_targets' => $rows,
        'print_targets_kellner_direkt_fixed' => $fixed,
        'bereiche' => ff_finance_list_bereiche($conn, true),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_print_mapping') {
    $pt = (int) ($_POST['print_target'] ?? 0);
    $bid = (int) ($_POST['finance_bereich_id'] ?? 0);
    if ($pt <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_params']);
        exit;
    }
    ff_finance_ensure_schema($conn);
    $stName = mysqli_prepare($conn, 'SELECT name FROM print_targets WHERE print_target = ? LIMIT 1');
    if ($stName) {
        mysqli_stmt_bind_param($stName, 'i', $pt);
        mysqli_stmt_execute($stName);
        $resName = mysqli_stmt_get_result($stName);
        $rowName = $resName ? mysqli_fetch_assoc($resName) : null;
        mysqli_stmt_close($stName);
        if ($rowName && ff_finance_print_target_is_kellner_direkt_fixed((string) ($rowName['name'] ?? ''))) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'error' => 'print_target_fixed',
                'message' => 'Dieses Druckziel ist fest „Kellner / Direktverkauf“ und kann keinem Finanzbereich zugeordnet werden.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    $nullBid = $bid > 0 ? $bid : null;
    if ($nullBid === null) {
        $st = mysqli_prepare($conn, 'UPDATE print_targets SET finance_bereich_id = NULL WHERE print_target = ? LIMIT 1');
        mysqli_stmt_bind_param($st, 'i', $pt);
    } else {
        $st = mysqli_prepare($conn, 'UPDATE print_targets SET finance_bereich_id = ? WHERE print_target = ? LIMIT 1');
        mysqli_stmt_bind_param($st, 'ii', $bid, $pt);
    }
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$vonRaw = trim((string) ($_GET['von'] ?? $_POST['von'] ?? ''));
$bisRaw = trim((string) ($_GET['bis'] ?? $_POST['bis'] ?? ''));
$range = ff_finance_parse_datetime_range($vonRaw, $bisRaw);
$bereichId = isset($_REQUEST['bereich_id']) ? (int) $_REQUEST['bereich_id'] : -1;

if ($bereichId === -1) {
    $data = ff_finance_evaluate_all_bereiche($conn, $range);
    echo json_encode(array_merge(['ok' => true, 'von' => $range['von_sql'] ?? null, 'bis' => $range['bis_sql'] ?? null], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($bereichId === ff_finance_bereich_id_kellner_direkt()) {
    ff_finance_ensure_kellner_direkt_print_targets($conn);
    $m = ff_finance_metrics_bereich($conn, $bereichId, $range);
    echo json_encode([
        'ok' => true,
        'bereich_id' => $bereichId,
        'name' => ff_finance_label_kellner_direkt(),
        'von' => $range['von_sql'] ?? null,
        'bis' => $range['bis_sql'] ?? null,
        'metrics' => $m,
        'kellner_direkt_breakdown' => ff_finance_kellner_direkt_breakdown($conn, $range),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($bereichId === 0) {
    $m = ff_finance_metrics_bereich($conn, 0, $range);
    echo json_encode([
        'ok' => true,
        'bereich_id' => 0,
        'name' => 'Unzugeordnet',
        'von' => $range['von_sql'] ?? null,
        'bis' => $range['bis_sql'] ?? null,
        'metrics' => $m,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = '';
foreach (ff_finance_list_bereiche($conn, false) as $b) {
    if ((int) $b['id'] === $bereichId) {
        $name = (string) $b['name'];
        break;
    }
}
if ($name === '') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'bereich_not_found']);
    exit;
}

$m = ff_finance_metrics_bereich($conn, $bereichId, $range);
echo json_encode([
    'ok' => true,
    'bereich_id' => $bereichId,
    'name' => $name,
    'von' => $range['von_sql'] ?? null,
    'bis' => $range['bis_sql'] ?? null,
    'metrics' => $m,
    'kassen_sessions' => ff_finance_kassen_sessions_detail($conn, $bereichId, $range),
], JSON_UNESCAPED_UNICODE);
