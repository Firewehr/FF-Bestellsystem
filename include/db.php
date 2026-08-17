<?php
require_once __DIR__ . '/runtime_bootstrap.php';

// Globale Variablen
$Titellogin = 'Bestellsystem';
$FFName = 'FF-Obritzberg';

// ——— Datenbank: Defaults (Probebetrieb / Entwicklung) ———
$hostname = 'localhost';
$username = 'ffobritzberg';
$password = 'FFObritzberg';
$dbname = 'ffobritzberg';

// Optional: Umgebungsvariablen (z. B. Apache SetEnv oder php-fpm pool)
$ffDbHost = getenv('FF_DB_HOST');
if ($ffDbHost !== false && $ffDbHost !== '') {
    $hostname = $ffDbHost;
}
$ffDbUser = getenv('FF_DB_USER');
if ($ffDbUser !== false && $ffDbUser !== '') {
    $username = $ffDbUser;
}
$ffDbPass = getenv('FF_DB_PASS');
if ($ffDbPass !== false) {
    $password = $ffDbPass;
}
$ffDbName = getenv('FF_DB_NAME');
if ($ffDbName !== false && $ffDbName !== '') {
    $dbname = $ffDbName;
}

// Optional: include/db.local.php überschreibt $hostname, $username, $password, $dbname
$dbLocal = __DIR__ . '/db.local.php';
if (is_readable($dbLocal)) {
    require $dbLocal;
}

// Create connection
$conn = mysqli_connect($hostname, $username, $password, $dbname);
// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');

/** PDO (z. B. fest_io, Rechnungsjobs) – gleiche Zugangsdaten wie $conn */
if (!function_exists('db')) {
    function db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        global $hostname, $username, $password, $dbname;
        $dsn = 'mysql:host=' . $hostname . ';dbname=' . $dbname . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $pdo;
    }
}

/* Wartezeit-Anzeige Küche/Schank/Druckziel (kein extra include/wartezeit_format.php nötig) */
if (!function_exists('ff_wartezeit_seit')) {
    function ff_wartezeit_seit($zeitstempel)
    {
        if ($zeitstempel === null || $zeitstempel === '' || $zeitstempel === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime((string) $zeitstempel);
        if ($ts === false || $ts <= 0) {
            return '';
        }
        $sec = max(0, time() - $ts);
        $h = (int) floor($sec / 3600);
        $m = (int) floor(($sec % 3600) / 60);
        $s = (int) ($sec % 60);
        return $h . ':' . sprintf('%02d:%02d', $m, $s);
    }
}
if (!function_exists('ff_wartezeit_seit_unix')) {
    function ff_wartezeit_seit_unix($unix)
    {
        if ($unix === null || $unix === '' || (int) $unix <= 0) {
            return '';
        }
        $unix = (int) $unix;
        $sec = max(0, time() - $unix);
        $h = (int) floor($sec / 3600);
        $m = (int) floor(($sec % 3600) / 60);
        $s = (int) ($sec % 60);
        return $h . ':' . sprintf('%02d:%02d', $m, $s);
    }
}

/**
 * mysqli_stmt_bind_param mit dynamischer Parameterliste (Referenzen für PHP 7/8).
 *
 * @param list<int|float|string|null> $params
 */
if (!function_exists('ff_mysqli_stmt_bind')) {
    function ff_mysqli_stmt_bind(mysqli_stmt $stmt, string $types, array $params): bool
    {
        if ($types === '' && $params === []) {
            return true;
        }
        if (strlen($types) !== count($params)) {
            return false;
        }
        $bind = [$types];
        foreach ($params as $i => $val) {
            $bind[] = &$params[$i];
        }
        return call_user_func_array([$stmt, 'bind_param'], $bind);
    }
}