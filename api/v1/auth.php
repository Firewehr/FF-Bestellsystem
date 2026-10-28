<?php
declare(strict_types=1);

/**
 * Authentifizierungs- und Benutzerinfo-Endpunkt für API v1.
 * Liefert Login-Status, Benutzerinformationen und CSRF-Token.
 * Unterstützt auch Login und Logout direkt für SPAs.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/ff_user_status.php';
require_once dirname(__DIR__, 2) . '/include/ff_user_permissions.php';
require_once dirname(__DIR__, 2) . '/include/ff_direktverkauf_auth.php';
require_once dirname(__DIR__, 2) . '/include/ff_schema_login.php';
require_once dirname(__DIR__, 2) . '/include/user_landing.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = api_get_request_data();

if ($method === 'POST') {
    $action = (string)($data['action'] ?? 'login');

    if ($action === 'logout') {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        api_response(['ok' => true, 'logged_in' => false]);
    }

    if ($action === 'login') {
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if ($username === '' || $password === '') {
            api_error('Benutzername und Passwort erforderlich.', 'missing_credentials', 400);
        }

        ff_users_ensure_direktverkauf_column($conn);
        ff_users_ensure_menu_permissions_column($conn);
        ff_users_ensure_status_columns($conn);
        ff_users_ensure_auth_rev_column($conn);

        $stmt = mysqli_prepare($conn, 'SELECT id, username, password, admin, COALESCE(can_finance,0) AS can_finance, COALESCE(can_direktverkauf,0) AS can_direktverkauf, start_page, start_print_target, menu_permissions, COALESCE(is_active,1) AS is_active, active_from, active_until, COALESCE(force_password_change,0) AS force_password_change, COALESCE(auth_rev,0) AS auth_rev FROM users WHERE username = ? LIMIT 1');
        if (!$stmt) {
            api_error('Datenbankfehler bei der Anmeldung.', 'db_error', 500);
        }
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $user = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if (!$user || !password_verify($password, $user['password'])) {
            api_error('Benutzername oder Passwort falsch.', 'invalid_credentials', 401);
        }

        $loginAllowed = ff_user_login_allowed($user, $conn);
        if (!$loginAllowed['ok']) {
            api_error($loginAllowed['error'] ?? 'Anmeldung derzeit nicht möglich.', 'login_blocked', 403);
        }

        if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $ustmt = mysqli_prepare($conn, 'UPDATE users SET password=? WHERE username=?');
            if ($ustmt) {
                mysqli_stmt_bind_param($ustmt, 'ss', $newHash, $user['username']);
                mysqli_stmt_execute($ustmt);
                mysqli_stmt_close($ustmt);
            }
        }

        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['login'] = true;
        ff_schema_run_login($conn);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'admin' => (int)$user['admin'],
        ];
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['auth_rev'] = (int)($user['auth_rev'] ?? 0);
        $_SESSION['admin'] = (int)$user['admin'];
        $ffPerms = ff_user_permissions_decode($user);
        $ffLegacy = ff_user_permissions_sync_legacy_flags($ffPerms);
        $_SESSION['menu_permissions'] = $ffPerms;
        $_SESSION['can_finance'] = (int)$ffLegacy['can_finance'];
        $_SESSION['can_direktverkauf'] = (int)$ffLegacy['can_direktverkauf'];
        $_SESSION['force_password_change'] = (int)($user['force_password_change'] ?? 0);
        $spLogin = ff_user_normalize_start_page($user['start_page'] ?? 'menu');
        $_SESSION['ff_menu_compact'] = ((int)$user['admin'] < 1 && $spLogin !== 'menu') ? 1 : 0;

        $csrfToken = ff_csrf_token();

        api_response([
            'ok' => true,
            'logged_in' => true,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'admin' => (int)$user['admin'],
                'can_finance' => (int)$user['can_finance'],
                'can_direktverkauf' => (int)$user['can_direktverkauf'],
            ],
            'csrf_token' => $csrfToken,
        ]);
    }

    api_error('Unbekannte Aktion.', 'invalid_action', 400);
}

// GET: Session-Status und Token abfragen
$isLoggedIn = !empty($_SESSION['login']) && !empty($_SESSION['user']['username']);
if ($isLoggedIn && isset($conn) && $conn instanceof mysqli) {
    $err = ff_auth_check_session_user($conn);
    if ($err !== null) {
        ff_auth_terminate_session($err);
    }
}
$csrfToken = ff_csrf_token();

$response = [
    'ok' => true,
    'logged_in' => $isLoggedIn,
    'csrf_token' => $csrfToken,
    'user' => null,
];

if ($isLoggedIn) {
    $response['user'] = [
        'id' => (int)($_SESSION['user_id'] ?? 0),
        'username' => (string)$_SESSION['user']['username'],
        'admin' => (int)($_SESSION['admin'] ?? 0),
        'can_finance' => (int)($_SESSION['can_finance'] ?? 0),
        'can_direktverkauf' => (int)($_SESSION['can_direktverkauf'] ?? 0),
    ];
}

api_response($response);
