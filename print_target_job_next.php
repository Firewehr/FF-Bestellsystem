<?php
/**
 * Nächsten Kellner-Bon-Job aus printer_jobs für ein Print-Target holen (Thermo-Client).
 * Analog token-Prüfung wie print_target.php.
 */
header('Content-Type: application/json; charset=utf-8');

$ffT0 = microtime(true);
$ffTimings = [];

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_schema_helpers.php';

$ffTimings['bootstrap_ms'] = round((microtime(true) - $ffT0) * 1000, 1);

$ffT1 = microtime(true);
ff_schema_ensure_hot_paths($conn);
$ffTimings['schema_ms'] = round((microtime(true) - $ffT1) * 1000, 1);

$print_target = isset($_GET['print_target']) ? (int)$_GET['print_target'] : 0;
$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';

if ($print_target <= 0) {
    echo json_encode(['ok' => false, 'error' => 'print_target fehlt oder ungültig'], JSON_UNESCAPED_UNICODE);
    exit;
}

$serverToken = setting_get($conn, 'printer_token', '');
if ($serverToken !== '' && $token !== $serverToken) {
    echo json_encode(['ok' => false, 'error' => 'Ungültiger Token'], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

$thermoBonHeader = (string)setting_get($conn, 'thermo_bon_header', '');
$thermoBonFooter = (string)setting_get($conn, 'thermo_bon_footer', '');
$thermoBonJsonExtra = [
    'thermo_bon_header' => $thermoBonHeader,
    'thermo_bon_footer' => $thermoBonFooter,
];

$printerKey = 'target_' . $print_target;

$ffT2 = microtime(true);
mysqli_begin_transaction($conn);
$sql = "SELECT id, payload, type FROM printer_jobs
        WHERE printer = '" . mysqli_real_escape_string($conn, $printerKey) . "'
          AND type IN ('rechnung_thermo', 'kellner_bon')
          AND status = 'pending'
        ORDER BY id ASC
        LIMIT 1
        FOR UPDATE";
$res = mysqli_query($conn, $sql);
$ffTimings['queue_query_ms'] = round((microtime(true) - $ffT2) * 1000, 1);
if (!$res) {
    mysqli_rollback($conn);
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    exit;
}

$row = mysqli_fetch_assoc($res);
if (!$row) {
    mysqli_commit($conn);
    $ffTimings['total_ms'] = round((microtime(true) - $ffT0) * 1000, 1);
    echo json_encode(array_merge([
        'ok' => true, 'count' => 0, 'tische' => [], 'print_target' => $print_target,
        'server_timings' => $ffTimings,
    ], $thermoBonJsonExtra), JSON_UNESCAPED_UNICODE);
    exit;
}

$jobId = (int)$row['id'];
$jobType = (string)($row['type'] ?? 'kellner_bon');
$upd = "UPDATE printer_jobs SET status = 'reserved', reserved_at = NOW(), reserved_by = 'print_client'
        WHERE id = " . $jobId . " AND status = 'pending'";
mysqli_query($conn, $upd);
if (mysqli_affected_rows($conn) !== 1) {
    mysqli_rollback($conn);
    echo json_encode(array_merge([
        'ok' => true, 'count' => 0, 'tische' => [], 'print_target' => $print_target,
    ], $thermoBonJsonExtra), JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_commit($conn);

$data = json_decode($row['payload'], true);
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Ungültiger Job-Payload', 'job_id' => $jobId], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($jobType === 'rechnung_thermo') {
    $text = isset($data['text']) ? (string)$data['text'] : '';
    if ($text === '') {
        echo json_encode(['ok' => false, 'error' => 'Leerer Thermo-Rechnungstext', 'job_id' => $jobId], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $lines = substr_count($text, "\n") + 1;
    echo json_encode(array_merge([
        'ok' => true,
        'job_id' => $jobId,
        'job_type' => 'rechnung_thermo',
        'bon_nr' => 0,
        'count' => max(1, $lines),
        'text' => $text,
        'tische' => [],
        'print_target' => $print_target,
    ], $thermoBonJsonExtra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($data['tische'])) {
    echo json_encode(['ok' => false, 'error' => 'Ungültiger Job-Payload', 'job_id' => $jobId], JSON_UNESCAPED_UNICODE);
    exit;
}

$tische = $data['tische'];
$bonNr = isset($data['bon_nr']) ? (int)$data['bon_nr'] : 0;
$count = 0;
foreach ($tische as $t) {
    if (!empty($t['positionen']) && is_array($t['positionen'])) {
        foreach ($t['positionen'] as $p) {
            $count += isset($p['anzahl']) ? (int)$p['anzahl'] : 1;
        }
    }
}

echo json_encode(array_merge([
    'ok' => true,
    'job_id' => $jobId,
    'job_type' => 'kellner_bon',
    'bon_nr' => $bonNr,
    'count' => $count,
    'tische' => $tische,
    'print_target' => $print_target,
], $thermoBonJsonExtra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
