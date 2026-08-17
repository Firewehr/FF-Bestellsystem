<?php
require_once 'auth.php';
require_once 'include/db.php';
require_once __DIR__ . '/include/ff_user_status.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$userid = (int) ($_POST['userid'] ?? 0);
if ($userid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_user']);
    exit;
}

ff_users_ensure_status_columns($conn);

$st = mysqli_prepare($conn, 'SELECT id, username, admin FROM users WHERE id = ? LIMIT 1');
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}
mysqli_stmt_bind_param($st, 'i', $userid);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($st);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

if ((int) ($row['admin'] ?? 0) === 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'super_admin_locked']);
    exit;
}

/* Eigenes Konto nicht deaktivierbar. */
$me = trim((string) ($_SESSION['user']['username'] ?? ''));
if ($me !== '' && $me === (string) ($row['username'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'own_account_locked']);
    exit;
}

$isActive = (isset($_POST['is_active']) && (string) $_POST['is_active'] === '0') ? 0 : 1;
$fromInPost = array_key_exists('active_from', $_POST);
$untilInPost = array_key_exists('active_until', $_POST);
$fromVal = $fromInPost ? ff_user_status_parse_dt($_POST['active_from'] ?? null) : null;
$untilVal = $untilInPost ? ff_user_status_parse_dt($_POST['active_until'] ?? null) : null;
if (!$fromInPost || !$untilInPost) {
    $stKeep = mysqli_prepare($conn, 'SELECT active_from, active_until FROM users WHERE id = ? LIMIT 1');
    if ($stKeep) {
        mysqli_stmt_bind_param($stKeep, 'i', $userid);
        mysqli_stmt_execute($stKeep);
        $resKeep = mysqli_stmt_get_result($stKeep);
        $rowKeep = $resKeep ? mysqli_fetch_assoc($resKeep) : null;
        mysqli_stmt_close($stKeep);
        if ($rowKeep) {
            if (!$fromInPost) {
                $fromVal = $rowKeep['active_from'];
            }
            if (!$untilInPost) {
                $untilVal = $rowKeep['active_until'];
            }
        }
    }
}

if ($fromVal !== null && $untilVal !== null && strtotime($untilVal) < strtotime($fromVal)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'range_invalid']);
    exit;
}

$up = mysqli_prepare($conn, 'UPDATE users SET is_active = ?, active_from = ?, active_until = ? WHERE id = ?');
if (!$up) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}
mysqli_stmt_bind_param($up, 'issi', $isActive, $fromVal, $untilVal, $userid);
$ok = mysqli_stmt_execute($up);
mysqli_stmt_close($up);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
    exit;
}

/* Inaktiv: alle laufenden Sessions sofort beenden. */
if ($isActive === 0) {
    ff_user_bump_auth_rev($conn, $userid);
}

$rowAfter = ['is_active' => $isActive, 'active_from' => $fromVal, 'active_until' => $untilVal, 'admin' => (int) ($row['admin'] ?? 0)];
$effActive = ff_user_status_effective_active($rowAfter, $conn) ? 1 : 0;
$winLabel = ff_user_status_window_label($fromVal, $untilVal);
$winHint = ff_user_status_window_hint($rowAfter);

echo json_encode([
    'ok' => true,
    'is_active' => $isActive,
    'active_from' => ff_user_status_dt_local($fromVal),
    'active_until' => ff_user_status_dt_local($untilVal),
    'window_set' => ($fromVal !== null || $untilVal !== null),
    'window_label' => $winLabel,
    'window_hint' => $winHint,
    'effective_active' => $effActive,
], JSON_UNESCAPED_UNICODE);
