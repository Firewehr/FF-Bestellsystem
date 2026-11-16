<?php
require_once __DIR__ . '/runtime_bootstrap.php';

// Globale Variablen
$Titellogin = 'Bestellsystem';
$FFName = 'FF-Manhartsbrunn';

// ——— Datenbank: Defaults (Probebetrieb / Entwicklung) ———
$hostname = 'localhost';
$username = 'bestellsystem';
$password = '';
$dbname = 'bestellsystem';

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
// Optional: persistente Verbindung (spart bei entferntem DB-Server den Verbindungsaufbau pro Request).
// Aktivieren per Umgebungsvariable FF_DB_PERSISTENT=1 oder $ffDbPersistent = true; in db.local.php.
$ffDbPersistent = !empty($ffDbPersistent) || getenv('FF_DB_PERSISTENT') === '1';
if (function_exists('mysqli_report')) {
    @mysqli_report(MYSQLI_REPORT_OFF);
}
$conn = mysqli_connect(($ffDbPersistent ? 'p:' : '') . $hostname, $username, $password, $dbname);
// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');

// Helfer (db(), ff_db_query() …) liegen in ff_db_helpers.php – db.php wird nicht deployt, siehe dort.
require_once __DIR__ . '/ff_db_helpers.php';
