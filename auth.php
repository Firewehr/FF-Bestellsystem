<?php

if (defined('FF_AUTH_LOADED')) {
    return;
}
define('FF_AUTH_LOADED', true);

require_once __DIR__ . '/include/runtime_bootstrap.php';
require_once __DIR__ . '/include/ff_session_bootstrap.php';
$ffTs = microtime(true);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$GLOBALS['ff_t_session'] = (microtime(true) - $ffTs) * 1000;
/* session_regenerate_id() nur bei Login (login.php) – nicht bei jedem Include, sonst parallele AJAX-Requests (Zahlen, Küche, …) blockieren sich gegenseitig. */

//echo $_SERVER['HTTP_REFERER'];

if (empty($_SESSION['login'])) {
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $wantsJson = ($xhr === 'xmlhttprequest')
        || (stripos($accept, 'application/json') !== false)
        || (stripos($accept, 'application/javascript') !== false);
    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(401);
        }
        echo json_encode(['ok' => false, 'error' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_user_status.php';
$ffAuthInvalid = ff_auth_check_session_user($conn);
if ($ffAuthInvalid !== null) {
    ff_auth_terminate_session($ffAuthInvalid);
}

if (!empty($_SESSION['login'])) {
    $login_status = '
			<div style="border: 1px solid black">
				Sie sind als <strong>' . htmlspecialchars($_SESSION['user']['username']) . '</strong> angemeldet.<br />
				<a href="./logout.php">Sitzung beenden</a>
			</div>
		';
}

/* Diagnose: Server-Timing-Header (Browser: DevTools > Netzwerk > Timing) */
if (!headers_sent()) {
    $ffStart = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    header(sprintf(
        'Server-Timing: boot;dur=%.0f, session;dur=%.0f, dbconnect;dur=%.0f',
        (microtime(true) - $ffStart) * 1000,
        $GLOBALS['ff_t_session'] ?? 0,
        $GLOBALS['ff_t_dbconnect'] ?? 0
    ));
}
