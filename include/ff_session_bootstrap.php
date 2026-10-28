<?php
/**
 * Vor session_start(): Cookie-Parameter und GC-Lifetime.
 * „Secure“ nur bei HTTPS (bzw. Port 443) — HTTP-Probebetrieb bleibt nutzbar.
 * FF_SESSION_COOKIE_SECURE=1|0|true|false erzwingt Verhalten (z. B. hinter TLS-Proxy).
 * FF_SESSION_MAX_AGE optional (Sekunden, Minimum 60) — hat Vorrang vor Admin-Einstellung.
 * Sonst: settings.session_max_idle_sec — 0 = Limit praktisch aus (365 Tage), sonst 60–604800, Standard 900.
 */
if (defined('FF_SESSION_BOOTSTRAP')) {
    return;
}
define('FF_SESSION_BOOTSTRAP', true);

$ffSessLifetime = 900;
$ffSessFromEnv = false;
$ffAge = getenv('FF_SESSION_MAX_AGE');
if ($ffAge !== false && $ffAge !== '' && ctype_digit((string) $ffAge)) {
    $ffSessLifetime = max(60, (int) $ffAge);
    $ffSessFromEnv = true;
}

if (!$ffSessFromEnv) {
    $ffSettingsPhp = __DIR__ . '/settings.php';
    if (is_readable($ffSettingsPhp)) {
        require_once $ffSettingsPhp;
        global $conn;
        if (isset($conn) && $conn instanceof mysqli) {
            $raw = setting_get($conn, 'session_max_idle_sec', '900');
            if ($raw === '0' || $raw === 0) {
                // Admin „Leerlauf-Limit aus“: Cookie/GC sehr lang (1 Jahr); nicht unendlich (Browser-/PHP-Limits).
                $ffSessLifetime = 31536000;
            } elseif ($raw !== null && $raw !== '' && ctype_digit((string) $raw)) {
                $ffSessLifetime = max(60, min(604800, (int) $raw));
            }
        }
    }
}

$ffSessSecure = false;
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $ffSessSecure = true;
}
if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
    $ffSessSecure = true;
}
$ffEnv = getenv('FF_SESSION_COOKIE_SECURE');
if ($ffEnv === '1' || strcasecmp((string) $ffEnv, 'true') === 0) {
    $ffSessSecure = true;
}
if ($ffEnv === '0' || strcasecmp((string) $ffEnv, 'false') === 0) {
    $ffSessSecure = false;
}

/*
 * Mehrere Installationen dieser App unter derselben Domain, aber verschiedenen
 * Unterordnern (z. B. /pos/system1/, /pos/system2/ auf demselben Server) dürfen
 * sich NICHT gegenseitig als eingeloggt sehen. Ohne Anpassung wäre der Cookie-Pfad
 * "/" (gilt für die ganze Domain), sodass beide Instanzen dieselbe Session-Cookie
 * erhalten und – je nach PHP-Session-Storage – sogar dieselbe Session-Datei lesen.
 * Fix: Cookie-Pfad auf das tatsächliche App-Verzeichnis einschränken (ermittelt aus
 * dem Dateisystempfad dieser Datei relativ zu DOCUMENT_ROOT, unabhängig vom
 * aufrufenden Skript/Unterordner) UND zusätzlich einen pfad-eindeutigen
 * Session-Namen verwenden (zweite Absicherung, falls DOCUMENT_ROOT nicht passt).
 */
$ffSessBasePath = '/';
$ffAppRootDir = str_replace('\\', '/', dirname(__DIR__));
$ffDocRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']), '/') : '';
if ($ffDocRoot !== '' && strpos($ffAppRootDir, $ffDocRoot) === 0) {
    $ffSessBasePath = substr($ffAppRootDir, strlen($ffDocRoot));
    if ($ffSessBasePath === '') {
        $ffSessBasePath = '/';
    }
    if (substr($ffSessBasePath, -1) !== '/') {
        $ffSessBasePath .= '/';
    }
}
session_name('FFPOS_' . substr(sha1($ffAppRootDir), 0, 12));

ini_set('session.gc_maxlifetime', (string) $ffSessLifetime);

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => $ffSessLifetime,
        'path' => $ffSessBasePath,
        'domain' => '',
        'secure' => $ffSessSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} else {
    session_set_cookie_params($ffSessLifetime, $ffSessBasePath, '', $ffSessSecure, true);
}
