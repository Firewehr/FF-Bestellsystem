<?php
/**
 * Kellner-Bon-Job abschließen (Thermo-Client nach Druck).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';

$token = isset($_POST['token']) ? trim((string)$_POST['token']) : '';
$jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$errorMsg = isset($_POST['error']) ? trim((string)$_POST['error']) : '';

$serverToken = setting_get($conn, 'printer_token', '');
if ($serverToken !== '' && $token !== $serverToken) {
    echo json_encode(['ok' => false, 'error' => 'Ungültiger Token'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($jobId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'job_id fehlt'], JSON_UNESCAPED_UNICODE);
    exit;
}

$newStatus = $status === 'error' ? 'error' : 'done';
mysqli_set_charset($conn, 'utf8mb4');

if ($newStatus === 'error' && $errorMsg !== '') {
    $escErr = "'" . mysqli_real_escape_string($conn, mb_substr($errorMsg, 0, 2000)) . "'";
} else {
    $escErr = 'NULL';
}

$sql = "UPDATE printer_jobs SET status = '" . ($newStatus === 'error' ? 'error' : 'done') . "',
        attempts = attempts + 1,
        error = " . $escErr . "
        WHERE id = " . $jobId . " AND type IN ('kellner_bon', 'rechnung_thermo')";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => $newStatus], JSON_UNESCAPED_UNICODE);
