<?php
/**
 * Passwort eines Benutzers zurücksetzen (Admin).
 * Optional Einmalpasswort (force_password_change) und automatisch generiertes Kennwort.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_user_status.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userid = (int) ($_POST['userid'] ?? 0);
$pw = (string) ($_POST['pw'] ?? '');
$generate = isset($_POST['generate']) && (string) $_POST['generate'] === '1';
$forcePw = !isset($_POST['force_password_change']) || (string) $_POST['force_password_change'] !== '0';

if ($userid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request'], JSON_UNESCAPED_UNICODE);
    exit;
}

ff_users_ensure_status_columns($conn);
ff_users_ensure_auth_rev_column($conn);

$st = mysqli_prepare($conn, 'SELECT id, username, admin FROM users WHERE id = ? LIMIT 1');
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($st, 'i', $userid);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($st);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int) ($row['admin'] ?? 0) === 2 && (int) ($_SESSION['admin'] ?? 0) < 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'super_admin_locked'], JSON_UNESCAPED_UNICODE);
    exit;
}

$me = trim((string) ($_SESSION['user']['username'] ?? ''));
if ($me !== '' && $me === (string) ($row['username'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'own_account_locked', 'message' => 'Eigenes Passwort bitte über „Passwort ändern“ im Menü.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($pw === '' && $generate) {
    $pw = ff_user_generate_temp_password(10);
}

if ($pw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_password'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strlen($pw) < 6) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'password_too_short'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hash = password_hash($pw, PASSWORD_BCRYPT);
if ($hash === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'hash_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$forceInt = $forcePw ? 1 : 0;
$upd = mysqli_prepare($conn, 'UPDATE users SET password = ?, force_password_change = ? WHERE id = ? LIMIT 1');
if (!$upd) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($upd, 'sii', $hash, $forceInt, $userid);
$ok = mysqli_stmt_execute($upd);
mysqli_stmt_close($upd);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'update_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

ff_user_bump_auth_rev($conn, $userid);

echo json_encode([
    'ok' => true,
    'password' => $pw,
    'username' => (string) ($row['username'] ?? ''),
    'force_password_change' => $forceInt,
    'message' => $forceInt === 1
        ? 'Passwort gesetzt. Der Benutzer muss es beim nächsten Login ändern (bestehende Sitzungen wurden beendet).'
        : 'Passwort gesetzt. Bestehende Sitzungen wurden beendet.',
], JSON_UNESCAPED_UNICODE);
