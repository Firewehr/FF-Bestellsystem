<?php
include_once('include/db.php');
require_once 'include/settings.php';
require_once __DIR__ . '/include/ff_rechnungen_ensure_columns.php';

require_once __DIR__ . '/include/ff_printer_devices.php';
ff_printer_auth_require_json($conn);

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
$cond = 'id = ?';
$condTypes = 'i';
$condParams = [(int)$id];
if($reserved_by !== ''){
    $cond .= ' AND reserved_by = ?';
    $condTypes .= 's';
    $condParams[] = $reserved_by;
}

if($status === 'done'){
    ff_db_query($conn, "UPDATE rechnungen SET gedruckt=1, druck_status='done' WHERE {$cond}", $condTypes, $condParams);
    echo 'OK';
    exit;
}

// Fehler: attempts hochzaehlen und ggf. auf error setzen
ff_db_query($conn, "UPDATE rechnungen SET druck_attempts=druck_attempts+1, druck_last_error=? WHERE {$cond}", 's' . $condTypes, array_merge([(string)$error], $condParams));

$res = ff_db_query($conn, 'SELECT druck_attempts FROM rechnungen WHERE id=? LIMIT 1', 'i', [(int)$id]);
$attempts = 0;
if($res && ($row=mysqli_fetch_assoc($res))){ $attempts = intval($row['druck_attempts']); }

if($attempts >= $maxAttempts){
    ff_db_query($conn, "UPDATE rechnungen SET druck_status='error' WHERE id=?", 'i', [(int)$id]);
    echo 'ERROR_MAX_ATTEMPTS';
} else {
    // wieder pending setzen, damit spaeter erneut gedruckt wird
    ff_db_query($conn, "UPDATE rechnungen SET druck_status='pending' WHERE id=?", 'i', [(int)$id]);
    echo 'RETRY';
}
