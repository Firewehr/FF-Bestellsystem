<?php
declare(strict_types=1);

/**
 * Bootstrap für API v1 Endpunkte des FeuerwehrBestellsystems.
 * Setzt JSON-Header, startet die Session, bindet die Datenbank ein
 * und stellt zentrale Auth- und Validierungshelfer bereit.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/include/runtime_bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/ff_session_bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/include/db.php';
require_once dirname(__DIR__, 2) . '/include/ff_csrf.php';

/**
 * Gibt eine einheitliche JSON-Antwort aus und beendet das Skript.
 */
function api_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Gibt eine standardisierte Fehlermeldung als JSON aus.
 */
function api_error(string $message, string $code = 'error', int $status = 400, array $extra = []): void
{
    $payload = array_merge(['ok' => false, 'error' => $code, 'message' => $message], $extra);
    api_response($payload, $status);
}

/**
 * Prüft, ob der Benutzer angemeldet ist.
 */
function api_require_auth(): array
{
    if (empty($_SESSION['login']) || empty($_SESSION['user']['username'])) {
        api_error('Nicht angemeldet. Bitte einloggen.', 'unauthorized', 401);
    }
    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        require_once dirname(__DIR__, 2) . '/include/ff_user_status.php';
        $err = ff_auth_check_session_user($conn);
        if ($err !== null) {
            ff_auth_terminate_session($err);
        }
    }

    $username = (string)$_SESSION['user']['username'];
    $admin = isset($_SESSION['admin']) ? (int)$_SESSION['admin'] : null;
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $canFinance = isset($_SESSION['can_finance']) ? (int)$_SESSION['can_finance'] : null;
    $canDirektverkauf = isset($_SESSION['can_direktverkauf']) ? (int)$_SESSION['can_direktverkauf'] : null;

    if ($admin === null || $userId === null || $canFinance === null || $canDirektverkauf === null) {
        if (isset($conn) && $conn instanceof mysqli) {
            $st = mysqli_prepare($conn, 'SELECT id, admin, COALESCE(can_finance, 0) AS can_finance, COALESCE(can_direktverkauf, 0) AS can_direktverkauf FROM users WHERE username = ? LIMIT 1');
            if ($st) {
                mysqli_stmt_bind_param($st, 's', $username);
                mysqli_stmt_execute($st);
                $res = mysqli_stmt_get_result($st);
                if ($res && ($row = mysqli_fetch_assoc($res))) {
                    $userId = (int)$row['id'];
                    $admin = (int)$row['admin'];
                    $canFinance = (int)$row['can_finance'];
                    $canDirektverkauf = (int)$row['can_direktverkauf'];
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['admin'] = $admin;
                    $_SESSION['can_finance'] = $canFinance;
                    $_SESSION['can_direktverkauf'] = $canDirektverkauf;
                }
                mysqli_stmt_close($st);
            }
        }
    }

    if (!is_array($_SESSION['user'])) {
        $_SESSION['user'] = ['username' => $username];
    }
    $_SESSION['user']['id'] = (int)($userId ?? 0);
    $_SESSION['user']['username'] = $username;
    $_SESSION['user']['admin'] = (int)($admin ?? 0);
    $_SESSION['user']['can_finance'] = (int)($canFinance ?? 0);
    $_SESSION['user']['can_direktverkauf'] = (int)($canDirektverkauf ?? 0);

    return $_SESSION['user'];
}

/**
 * Liest den Request-Body (unterstützt JSON und URL-encoded).
 */
function api_get_request_data(): array
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        return $_GET;
    }

    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $inputSource = php_sapi_name() === 'cli' ? 'php://stdin' : 'php://input';
        $raw = file_get_contents($inputSource);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            api_error('Ungültiges JSON-Format im Request-Body', 'bad_json', 400);
        }
        return $decoded;
    }

    return $_POST;
}

/**
 * Validiert das CSRF-Token für zustandsändernde Requests.
 */
function api_require_csrf(?array $requestData = null): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_SERVER['HTTP_X_XSRF_TOKEN']
        ?? ($requestData['csrf_token'] ?? null)
        ?? ($requestData['csrf'] ?? null)
        ?? ($_POST['csrf_token'] ?? null);

    if (!ff_csrf_verify(is_string($token) ? $token : null)) {
        api_error('Ungültiges oder fehlendes CSRF-Sicherheitstoken.', 'invalid_csrf', 403);
    }
}
