<?php
/**
 * Öffentlich (kein Login nötig): Passkey-Assertion prüfen und einloggen.
 * Baut die Session identisch zu login.php auf (klassischer Login bleibt unverändert nutzbar).
 */
require_once __DIR__ . '/include/runtime_bootstrap.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
require_once __DIR__ . '/include/ff_user_permissions.php';
require_once __DIR__ . '/include/ff_user_status.php';
require_once __DIR__ . '/include/ff_webauthn.php';
require_once __DIR__ . '/include/ff_session_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'no_db'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload) || !isset($payload['credential']) || !is_array($payload['credential'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request'], JSON_UNESCAPED_UNICODE);
    exit;
}

ff_users_ensure_direktverkauf_column($conn);
ff_users_ensure_menu_permissions_column($conn);
ff_users_ensure_status_columns($conn);
ff_users_ensure_auth_rev_column($conn);

$result = ff_webauthn_login_verify($conn, $payload['credential']);
if (!$result['ok']) {
    http_response_code(400);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$row = $result['user_row'];
$loginAllowed = ff_user_login_allowed($row, $conn);
if (!$loginAllowed['ok']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => $loginAllowed['error'] ?? 'login_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$_SESSION = [];
session_regenerate_id(true);
$_SESSION['login'] = true;
$_SESSION['user'] = ['username' => $row['username']];
$_SESSION['user_id'] = (int) ($row['id'] ?? 0);
$_SESSION['auth_rev'] = (int) ($row['auth_rev'] ?? 0);
$_SESSION['admin'] = $row['admin'];
$ffPerms = ff_user_permissions_decode($row);
$ffLegacy = ff_user_permissions_sync_legacy_flags($ffPerms);
$_SESSION['menu_permissions'] = $ffPerms;
$_SESSION['can_finance'] = $ffLegacy['can_finance'];
$_SESSION['can_direktverkauf'] = $ffLegacy['can_direktverkauf'];
$_SESSION['force_password_change'] = (int) ($row['force_password_change'] ?? 0);
$spLogin = ff_user_normalize_start_page($row['start_page'] ?? 'menu');
$_SESSION['ff_menu_compact'] = ((int) $row['admin'] < 1 && $spLogin !== 'menu') ? 1 : 0;

if ((int) ($row['force_password_change'] ?? 0) === 1) {
    echo json_encode(['ok' => true, 'redirect' => 'index.php#pwForceChange'], JSON_UNESCAPED_UNICODE);
    exit;
}

$land = ff_user_login_landing_hash(
    $conn,
    (int) $row['admin'],
    $row['start_page'] ?? 'menu',
    $row['start_print_target'] ?? null
);
echo json_encode(['ok' => true, 'redirect' => 'index.php#' . $land], JSON_UNESCAPED_UNICODE);
