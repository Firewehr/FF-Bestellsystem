<?php

require_once('auth.php');
header("Cache-Control: no-cache");
header('Content-Type: application/json; charset=utf-8');

$tischnummer = intval($_POST['tischnummer'] ?? 0);
if ($tischnummer <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_tischnummer']);
    exit;
}

require_once('include/db.php');
require_once('include/settings.php');

// Best-effort schema additions (ignore errors if already exists)
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS feste (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(255) NOT NULL, code VARCHAR(16) NOT NULL, fest_datum DATE NULL, aktiv TINYINT(1) NOT NULL DEFAULT 1, payment_mode ENUM('after','instant') NOT NULL DEFAULT 'after', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), KEY aktiv(aktiv)) ENGINE=MyISAM DEFAULT CHARSET=utf8");

// order_nr Spalte sicherstellen
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'order_nr'");
if ($chk && mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, "ALTER TABLE bestellungen ADD COLUMN order_nr INT(11) NULL DEFAULT NULL COMMENT 'Bestellnummer (beim Abschicken vergeben)'");
    @mysqli_query($conn, "ALTER TABLE bestellungen ADD KEY idx_order_nr (order_nr)");
}

// Nächste Bestellnummer atomar holen
mysqli_query($conn, "UPDATE settings SET v = LAST_INSERT_ID(v + 1) WHERE k = 'order_nr_seq'");
$orderNr = (int)mysqli_insert_id($conn);
if ($orderNr <= 0) {
    setting_set($conn, 'order_nr_seq', '1');
    $orderNr = 1;
}

// Mark all positions for the table as 'bestellt' + order_nr zuweisen
$sql = "UPDATE bestellungen 
        SET bestellt=1, timestampBestellung=current_timestamp, order_nr=$orderNr 
        WHERE tischnummer=$tischnummer 
        AND (bestellt IS NULL OR bestellt=0)
        AND `delete`=0";

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)]);
    exit;
}

// Prüfen, ob überhaupt Zeilen betroffen waren
$affected = mysqli_affected_rows($conn);
if ($affected === 0) {
    echo json_encode(['ok' => false, 'error' => 'keine_bestellungen_gefunden']);
    exit;
}


// Attach current fest_id to newly submitted rows (best-effort)
$currentFestId = 0;
$fres = mysqli_query($conn, "SELECT id, payment_mode FROM feste WHERE aktiv=1 LIMIT 1");
$paymentMode = 'after';

if ($fres && ($frow = mysqli_fetch_assoc($fres))) {
    $currentFestId = (int)$frow['id'];
    $paymentMode = $frow['payment_mode'];
}

if ($currentFestId > 0) {
    @mysqli_query($conn, "UPDATE bestellungen SET fest_id=$currentFestId WHERE tischnummer=$tischnummer AND fest_id IS NULL AND bestellt=1");
}

// Determine if we must force immediate payment
$requirePayment = false;

// Honorary tables never pay
$tres = mysqli_query($conn, "SELECT is_sammelrechnung, is_ehrengast FROM tische WHERE tischnummer=$tischnummer LIMIT 1");
$isSammel = 0; $isEhren = 0;
if ($tres && ($trow = mysqli_fetch_assoc($tres))) {
    $isSammel = (int)($trow['is_sammelrechnung'] ?? 0);
    $isEhren = (int)($trow['is_ehrengast'] ?? 0);
}

if ($isEhren === 0) {
    if ($currentFestId > 0) {
        if ($paymentMode === 'instant' && $isSammel === 0) {
            $requirePayment = true;
        }
    }

}

echo json_encode([
    'ok' => true,
    'tischnummer' => $tischnummer,
    'order_nr' => $orderNr,
    'require_payment' => $requirePayment ? 1 : 0,
    'is_sammelrechnung' => $isSammel,
    'is_ehrengast' => $isEhren,
    'fest_id' => $currentFestId
]);

