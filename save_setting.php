<?php
require_once('auth.php');
header("Cache-Control: no-cache");
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

require_once('include/db.php');
require_once('include/settings.php');

$k = isset($_POST['k']) ? trim((string)$_POST['k']) : '';
$v = isset($_POST['v']) ? trim((string)$_POST['v']) : '';

if ($k === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_key']);
    exit;
}

// Nur erlaubte Keys
$allowed = [
    'kellner_nur_eigene',
    'fast_refresh',
    'current_fest_id',
    'karte_spalten',
    'karte_spalten_mobil',
    'tisch_raster_spalten',
    'tisch_raster_spalten_mobil',
    'session_max_idle_sec',
    'station_summary_top',
    'station_summary_right',
    'station_spalten',
    'station_spalten_mobil',
    'station_one_click_abschliessen',
    'station_teillieferung_druck',
    'app_title',
];
if (!in_array($k, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'key_not_allowed']);
    exit;
}

if ($k === 'session_max_idle_sec') {
    $n = (int) $v;
    if ($n === 0) {
        $v = '0';
    } else {
        if ($n < 60) {
            $n = 60;
        }
        if ($n > 604800) {
            $n = 604800;
        }
        $v = (string) $n;
    }
}

if ($k === 'station_summary_top' || $k === 'station_summary_right'
    || $k === 'station_one_click_abschliessen' || $k === 'station_teillieferung_druck') {
    $v = ($v === '1' || $v === 'true' || $v === 'on') ? '1' : '0';
}

if ($k === 'station_spalten') {
    $n = (int) $v;
    if ($n < 0) {
        $n = 0;
    }
    if ($n > 6) {
        $n = 6;
    }
    $v = (string) $n;
}

if ($k === 'station_spalten_mobil') {
    $n = (int) $v;
    if ($n < 0) {
        $n = 0;
    }
    if ($n > 2) {
        $n = 2;
    }
    $v = (string) $n;
}

if ($k === 'app_title') {
    $v = trim($v);
    if (function_exists('mb_substr')) {
        $v = mb_substr($v, 0, 80, 'UTF-8');
    } else {
        $v = substr($v, 0, 80);
    }
    // Leer = Admin-Override löschen → Fallback $FFName / $Titellogin aus db.php
}

// Kritische Keys nur Super-Admin
if ($k === 'current_fest_id' && (int)$_SESSION['admin'] < 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$ok = setting_set($conn, $k, $v);
echo json_encode(['ok' => (bool)$ok]);
