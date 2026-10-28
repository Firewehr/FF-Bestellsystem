<?php
include_once('include/db.php');
require_once 'include/settings.php';
require_once __DIR__ . '/include/ff_rechnungen_ensure_columns.php';

$token = $_POST['token'] ?? '';
$expected = settings_get($conn, 'printer_token', '');
if($expected !== '' && !hash_equals($expected, $token)){
    header('HTTP/1.1 403 Forbidden');
    echo 'forbidden';
    exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$reserved_by = $_POST['reserved_by'] ?? '';
$status = $_POST['status'] ?? 'done'; // done|error
$error = substr($_POST['error'] ?? '', 0, 250);
if($id<=0){
    echo 'missing id';
    exit;
}

// Best effort: Spalten
ff_rechnungen_ensure_print_columns($conn);

$maxAttempts = intval(settings_get($conn, 'PRINTER_MAX_ATTEMPTS', '5'));

// Nur bestaetigen, wenn Reservation passt (verhindert falsche ACKs)
$cond = "id={$id}";
if($reserved_by !== ''){
    $reserved_by_esc = mysqli_real_escape_string($conn, $reserved_by);
    $cond .= " AND reserved_by='{$reserved_by_esc}'";
}

if($status === 'done'){
    @mysqli_query($conn, "UPDATE rechnungen SET gedruckt=1, druck_status='done' WHERE {$cond}");
    echo 'OK';
    exit;
}

// Fehler: attempts hochzaehlen und ggf. auf error setzen
$error_esc = mysqli_real_escape_string($conn, $error);
@mysqli_query($conn, "UPDATE rechnungen SET druck_attempts=druck_attempts+1, druck_last_error='{$error_esc}' WHERE {$cond}");

$res = @mysqli_query($conn, "SELECT druck_attempts FROM rechnungen WHERE id={$id} LIMIT 1");
$attempts = 0;
if($res && ($row=mysqli_fetch_assoc($res))){ $attempts = intval($row['druck_attempts']); }

if($attempts >= $maxAttempts){
    @mysqli_query($conn, "UPDATE rechnungen SET druck_status='error' WHERE id={$id}");
    echo 'ERROR_MAX_ATTEMPTS';
} else {
    // wieder pending setzen, damit spaeter erneut gedruckt wird
    @mysqli_query($conn, "UPDATE rechnungen SET druck_status='pending' WHERE id={$id}");
    echo 'RETRY';
}
