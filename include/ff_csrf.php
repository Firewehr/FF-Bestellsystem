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
