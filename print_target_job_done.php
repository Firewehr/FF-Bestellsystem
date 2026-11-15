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

require_once __DIR__ . '/include/ff_printer_devices.php';
$printerAuth = ff_printer_auth_require_json($conn, $token);
$assignedTarget = (int)($printerAuth['device']['print_target'] ?? 0);

if ($jobId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'job_id fehlt'], JSON_UNESCAPED_UNICODE);
    exit;
}

$newStatus = $status === 'error' ? 'error' : 'done';
mysqli_set_charset($conn, 'utf8mb4');

$finalStatus = $newStatus === 'error' ? 'error' : 'done';
$errValue = ($newStatus === 'error' && $errorMsg !== '') ? mb_substr($errorMsg, 0, 2000) : null;

// Geräte mit zugewiesenem Druckziel dürfen nur Jobs ihres eigenen Ziels quittieren.
$sql = "UPDATE printer_jobs SET status = ?, attempts = attempts + 1, error = ?
         WHERE id = ? AND type IN ('kellner_bon', 'rechnung_thermo')"
    . ($assignedTarget > 0 ? ' AND printer = ?' : '');
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'Datenbankfehler'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($assignedTarget > 0) {
    $printerKey = 'target_' . $assignedTarget;
    mysqli_stmt_bind_param($stmt, 'ssis', $finalStatus, $errValue, $jobId, $printerKey);
} else {
    mysqli_stmt_bind_param($stmt, 'ssi', $finalStatus, $errValue, $jobId);
}
if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    echo json_encode(['ok' => false, 'error' => 'Datenbankfehler'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_close($stmt);

echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => $newStatus], JSON_UNESCAPED_UNICODE);
