<?php
// Liefert offene Rechnungen als JSON fuer den lokalen Bondruck-Service.
// Druckt NICHT am Server.

include_once("include/db.php");
require_once 'include/settings.php';
require_once __DIR__ . '/include/ff_rechnungen_ensure_columns.php';

// Token optional (damit nicht jeder drucken kann)
$token = $_GET['token'] ?? '';
$expected = settings_get($conn, 'printer_token', '');
if($expected !== '' && !hash_equals($expected, $token)){
    header('HTTP/1.1 403 Forbidden');
    echo 'forbidden';
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Best effort: neue Spalten vorhanden?
ff_rechnungen_ensure_print_columns($conn);

$maxAttempts = intval(settings_get($conn, 'PRINTER_MAX_ATTEMPTS', '5'));

// Hole max. 5 pending Rechnungen und reserviere sie (damit 2 Services nicht doppelt drucken)
$reserved_by = bin2hex(random_bytes(8));
$res = mysqli_query($conn, "SELECT * FROM rechnungen WHERE gedruckt=0 AND (druck_status='pending' OR druck_status IS NULL) AND druck_attempts < {$maxAttempts} ORDER BY id ASC LIMIT 5");
if(!$res){
    echo json_encode(['error'=>mysqli_error($conn)]);
    exit;
}

$seller_name = settings_get($conn, 'seller_name', '');
$seller_address = settings_get($conn, 'seller_address', '');
$seller_uid = settings_get($conn, 'seller_uid', '');

require_once __DIR__ . '/include/ff_rechnung_items.php';

$out = [];
while ($r = mysqli_fetch_assoc($res)) {
    $rechnung_id = (int)$r['id'];

    @mysqli_query($conn, "UPDATE rechnungen SET druck_status='reserved', reserved_at=NOW(), reserved_by='{$reserved_by}' WHERE id={$rechnung_id} AND (druck_status='pending' OR druck_status IS NULL) AND gedruckt=0");
    $r2 = mysqli_query($conn, "SELECT * FROM rechnungen WHERE id={$rechnung_id} LIMIT 1");
    if ($r2 && ($rr = mysqli_fetch_assoc($r2))) {
        $r = $rr;
    }
    if (isset($r['druck_status']) && $r['druck_status'] !== 'reserved') {
        continue;
    }

    $items = ff_rechnung_load_items_grouped($conn, $r);
    $text = ff_rechnung_build_thermal_text($conn, $r);

    $out[] = [
        'id' => $rechnung_id,
        'reserved_by' => $reserved_by,
        'rechnungsnummer' => $r['rechnungsnummer'],
        'created_at' => $r['created_at'],
        'is_firma' => (int)$r['is_firma'],
        'empfaenger' => [
            'name' => $r['empfaenger_name'] ?? '',
            'strasse' => $r['empfaenger_strasse'] ?? '',
            'plz' => $r['empfaenger_plz'] ?? '',
            'ort' => $r['empfaenger_ort'] ?? '',
            'uid' => $r['empfaenger_uid'] ?? '',
        ],
        'seller' => [
            'name' => $seller_name,
            'address' => $seller_address,
            'uid' => $seller_uid,
        ],
        'total' => (float)$r['total'],
        'items' => $items,
        'text' => $text,
    ];
}

echo json_encode($out);
