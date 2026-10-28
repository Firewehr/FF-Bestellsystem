<?php
/**
 * Generiert eine neue Bon-ID für Direktverkauf
 * Gibt JSON zurück mit der neuen Bon-ID
 */
require_once('auth.php');
require_once('include/db.php');
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
ff_direktverkauf_require($conn);
require_once __DIR__ . '/include/menu_list_helpers.php';
require_once __DIR__ . '/include/ff_direktverkauf_bon_helpers.php';

header('Content-Type: application/json; charset=utf-8');

// Spalte bon_id in bestellungen anlegen falls nicht vorhanden
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'bon_id'");
if ($chk && mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, "ALTER TABLE bestellungen ADD COLUMN bon_id VARCHAR(10) NULL DEFAULT NULL");
    @mysqli_query($conn, "CREATE INDEX idx_bon_id ON bestellungen (bon_id)");
}

ff_direktverkauf_clear_session_bon();
$bon_id = ff_direktverkauf_alloc_next_bon_id($conn);
ff_direktverkauf_set_session_bon($bon_id);

echo json_encode([
    'ok' => true,
    'bon_id' => $bon_id,
    'today_ymd' => ff_direktverkauf_today_ymd(),
    'day_prefix' => ff_direktverkauf_bon_today_day(),
    'timezone' => date_default_timezone_get(),
], JSON_UNESCAPED_UNICODE);
