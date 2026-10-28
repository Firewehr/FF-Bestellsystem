<?php
/**
 * Einmalig pro Request: Fehleranzeige für Produktion absichern.
 * Debug: Umgebungsvariable FF_APP_DEBUG=1 oder leere Datei `.ff-debug` im Projektroot (neben index.php).
 */
if (defined('FF_RUNTIME_BOOTSTRAP')) {
    return;
}
define('FF_RUNTIME_BOOTSTRAP', true);

/** Fest-Betrieb: einheitliche Zeitzone für Bon-IDs und „heute“ (unabhängig von falscher Windows-Zeitzone des PCs). */
date_default_timezone_set('Europe/Vienna');

$ffRoot = dirname(__DIR__);
$ffDebug = getenv('FF_APP_DEBUG') === '1' || strcasecmp((string) getenv('FF_APP_DEBUG'), 'true') === 0;
if (!$ffDebug && is_file($ffRoot . DIRECTORY_SEPARATOR . '.ff-debug')) {
    $ffDebug = true;
}

if ($ffDebug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

if (function_exists('mysqli_report')) {
    @mysqli_report(MYSQLI_REPORT_OFF);
}
