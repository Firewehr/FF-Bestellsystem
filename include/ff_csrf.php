<?php
/**
 * Zentraler CSRF-Schutz (Cross-Site Request Forgery) für das Feuerwehr-Bestellsystem.
 *
 * Verwaltet ein kryptografisch sicheres Token pro Benutzersitzung,
 * liefert Hilfsfunktionen zur Validierung und setzt das XSRF-TOKEN Cookie für JavaScript.
 */
declare(strict_types=1);

if (defined('FF_CSRF_LOADED')) {
    return;
}
define('FF_CSRF_LOADED', true);

/**
 * Gibt das CSRF-Token für die aktuelle Session zurück.
 * Falls noch keines existiert, wird ein sicheres 32-Byte Hex-Token (64 Zeichen) generiert.
 */
function ff_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
        }
    }

    $token = (string) $_SESSION['csrf_token'];

    // Cookie für JavaScript-Clients (z. B. fetch / XMLHttpRequest) setzen
    if (!headers_sent()) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
        $cookieParams = session_get_cookie_params();
        $cookiePath = !empty($cookieParams['path']) ? $cookieParams['path'] : '/';
        @setcookie('XSRF-TOKEN', $token, [
            'expires' => 0,
            'path' => $cookiePath,
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => $secure,
            'httponly' => false, // absichtlich false, damit JavaScript den Header setzen kann
            'samesite' => 'Lax',
        ]);
    }

    return $token;
}

/**
 * Prüft das übermittelte CSRF-Token gegen das erwartete Session-Token.
 *
 * Sucht das Token in:
 *   1. HTTP Header 'X-CSRF-Token' (Standard für modern Web / fetch)
 *   2. POST-Parameter 'csrf_token' oder 'csrf'
 *   3. GET-Parameter 'csrf_token' oder 'csrf'
 *
 * @param string|null $token Optional manuell übergebenes Token
 * @return bool true wenn gültig, sonst false
 */
function ff_csrf_verify(?string $token = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($expected === '') {
        $expected = ff_csrf_token();
    }
    if ($token === null) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['csrf_token']
            ?? $_POST['csrf']
            ?? $_GET['csrf_token']
            ?? $_GET['csrf']
            ?? null;
    }
    if (!is_string($token) || $token === '') {
        return false;
    }
    return hash_equals($expected, $token);
}

/**
 * Verlangt ein gültiges CSRF-Token. Bricht andernfalls mit HTTP 403 Forbidden ab.
 *
 * @param string|null $token
 * @param bool $json Ob die Fehlermeldung als JSON ausgegeben werden soll
 */
function ff_csrf_require(?string $token = null, bool $json = true): void
{
    if (!ff_csrf_verify($token)) {
        http_response_code(403);
        if ($json) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok' => false,
                'error' => 'csrf_invalid',
                'message' => 'Ungültiges oder fehlendes CSRF-Token. Bitte Seite neu laden.'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo 'Ungültiges oder fehlendes CSRF-Token. Bitte Seite neu laden.';
        }
        exit;
    }
}

/**
 * Erzeugt ein HTML-Hidden-Input-Tag mit dem aktuellen CSRF-Token für Formulare.
 */
function ff_csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(ff_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

// -----------------------------------------------------------------------------
// Zentrale Prüfung für alle schreibenden Anfragen (auth.php)
// -----------------------------------------------------------------------------

/** Hostname (ohne Schema/Port, klein) aus einer URL oder einem Host-Header. */
function ff_csrf_host_of(string $value, bool $isUrl): string
{
    $value = trim($value);
    if ($value === '' || strcasecmp($value, 'null') === 0) {
        return '';
    }
    if ($isUrl) {
        $host = parse_url($value, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }
    $value = trim(explode(',', $value)[0]);
    if ($value !== '' && $value[0] === '[') { // IPv6 [::1]:8080
        $end = strpos($value, ']');
        return $end !== false ? strtolower(substr($value, 0, $end + 1)) : '';
    }
    return strtolower((string) preg_replace('/:\d+$/', '', $value));
}

/**
 * Bewertet eine schreibende Anfrage. Reine Funktion (für Selbsttests).
 *
 * Gültig ist sie mit gültigem Token (Header X-CSRF-Token oder Feld csrf_token/csrf) oder – ohne bzw.
 * mit veraltetem Token – wenn Origin (ersatzweise Referer) auf denselben Host zeigt. Browser senden
 * Origin bei POST immer mit; eine fremde Seite kann ihn nicht fälschen. So bleiben ältere Formulare
 * ohne Token funktionsfähig, während Anfragen von fremden Seiten abgewiesen werden.
 *
 * @param array<string,mixed> $server $_SERVER
 * @param array<string,mixed> $post   $_POST
 * @return array{ok:bool, via:string} via: token | origin | referer | foreign | unknown
 */
function ff_csrf_request_check(array $server, array $post, string $expected): array
{
    $token = $server['HTTP_X_CSRF_TOKEN'] ?? $post['csrf_token'] ?? $post['csrf'] ?? null;
    if ($expected !== '' && is_string($token) && $token !== '' && hash_equals($expected, $token)) {
        return ['ok' => true, 'via' => 'token'];
    }

    $hosts = [];
    foreach (['HTTP_HOST', 'HTTP_X_FORWARDED_HOST', 'SERVER_NAME'] as $k) {
        $h = ff_csrf_host_of((string) ($server[$k] ?? ''), false);
        if ($h !== '') {
            $hosts[$h] = true;
        }
    }

    $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && strcasecmp($origin, 'null') !== 0) {
        $oh = ff_csrf_host_of($origin, true);
        return ($oh !== '' && isset($hosts[$oh])) ? ['ok' => true, 'via' => 'origin'] : ['ok' => false, 'via' => 'foreign'];
    }
    $referer = (string) ($server['HTTP_REFERER'] ?? '');
    if ($referer !== '') {
        $rh = ff_csrf_host_of($referer, true);
        return ($rh !== '' && isset($hosts[$rh])) ? ['ok' => true, 'via' => 'referer'] : ['ok' => false, 'via' => 'foreign'];
    }
    return ['ok' => false, 'via' => 'unknown'];
}

/** Modus der zentralen Prüfung: 'log' (nur protokollieren, Standard) oder 'enforce' (abweisen). */
function ff_csrf_mode(mysqli $conn): string
{
    $mode = function_exists('setting_get') ? (string) setting_get($conn, 'csrf_mode', 'log') : 'log';
    return $mode === 'enforce' ? 'enforce' : 'log';
}

function ff_csrf_log_ensure_schema(mysqli $conn): void
{
    if (!function_exists('ff_ensure_table')) {
        require_once __DIR__ . '/ff_schema_helpers.php';
    }
    ff_ensure_table(
        $conn,
        'ff_csrf_log',
        "CREATE TABLE IF NOT EXISTS ff_csrf_log (
            tag DATE NOT NULL,
            path VARCHAR(190) NOT NULL,
            via VARCHAR(16) NOT NULL,
            cnt INT NOT NULL DEFAULT 0,
            blocked INT NOT NULL DEFAULT 0,
            last_at DATETIME NOT NULL,
            last_user VARCHAR(100) NOT NULL DEFAULT '',
            PRIMARY KEY (tag, path, via)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** Zählt eine Anfrage ohne gültiges Token (eine Zeile pro Tag, Datei und Ergebnis). */
function ff_csrf_log_request(mysqli $conn, string $path, string $via, bool $blocked, string $user): void
{
    ff_csrf_log_ensure_schema($conn);
    $ok = ff_db_query(
        $conn,
        'INSERT INTO ff_csrf_log (tag, path, via, cnt, blocked, last_at, last_user) VALUES (CURDATE(), ?, ?, 1, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE cnt = cnt + 1, blocked = blocked + VALUES(blocked), last_at = NOW(), last_user = VALUES(last_user)',
        'ssis',
        [mb_substr($path, 0, 190), $via, $blocked ? 1 : 0, mb_substr($user, 0, 100)]
    );
    if (!$ok) {
        error_log('ff_csrf_log_request: ' . mysqli_error($conn));
    }
}

/**
 * Prüft jede schreibende Anfrage (POST/PUT/PATCH/DELETE) eingeloggter Benutzer; wird von auth.php aufgerufen.
 * Anfragen ohne gültiges Token werden protokolliert; im Modus 'enforce' werden ungültige abgewiesen (403).
 * Ausnahme für eine Datei: vor dem Einbinden von auth.php define('FF_CSRF_EXEMPT', true).
 */
function ff_csrf_guard(mysqli $conn): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) || defined('FF_CSRF_EXEMPT')) {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $check = ff_csrf_request_check($_SERVER, $_POST, (string) ($_SESSION['csrf_token'] ?? ''));
    if ($check['via'] === 'token') {
        return;
    }
    $blocked = !$check['ok'] && ff_csrf_mode($conn) === 'enforce';
    $path = (string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH);
    ff_csrf_log_request($conn, $path, $check['via'], $blocked, (string) ($_SESSION['user']['username'] ?? ''));
    if (!$check['ok']) {
        error_log('CSRF ' . ($blocked ? 'abgewiesen' : 'nur protokolliert') . ': ' . $method . ' ' . $path . ' (' . $check['via'] . ')');
    }
    if ($blocked) {
        ff_csrf_require('', stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html') === false);
    }
}
