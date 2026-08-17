<?php
/**
 * Prüft, ob am Tisch noch eine Sofortzahlungs-Sperre aktiv sein soll.
 * JSON: { ok, require_payment, open_unpaid }
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_schreibaus.php';
require_once __DIR__ . '/include/menu_list_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$tischnummer = (int) ($_GET['tischnummer'] ?? 0);
if ($tischnummer <= 0 || $tischnummer === 999999) {
    echo json_encode(['ok' => true, 'require_payment' => 0, 'open_unpaid' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

$paymentMode = ff_aktiver_payment_mode($conn);
$isEhrengast = 0;
$isSammelrechnung = 0;
$tres = @mysqli_query($conn, 'SELECT is_ehrengast, IFNULL(is_sammelrechnung,0) AS is_sammelrechnung FROM tische WHERE tischnummer=' . $tischnummer . ' LIMIT 1');
if ($tres && ($trow = mysqli_fetch_assoc($tres))) {
    $isEhrengast = (int) ($trow['is_ehrengast'] ?? 0);
    $isSammelrechnung = (int) ($trow['is_sammelrechnung'] ?? 0);
}

$openUnpaid = ff_tisch_count_unpaid_kasse($conn, $tischnummer, $paymentMode);
$requirePayment = (
    $paymentMode === 'instant'
    && $openUnpaid > 0
    && $isSammelrechnung === 0
    && $isEhrengast === 0
) ? 1 : 0;

echo json_encode([
    'ok' => true,
    'require_payment' => $requirePayment,
    'open_unpaid' => $openUnpaid,
], JSON_UNESCAPED_UNICODE);
