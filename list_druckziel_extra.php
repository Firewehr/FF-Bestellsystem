<?php
/**
 * Stationsliste pro Druckziel (nur Anzahl / Content mit Auto-Reload).
 * Aufruf: diese_datei.php?print_target=11
 */
require_once __DIR__ . '/auth.php';
include_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_kueche_station_view.php';
require_once __DIR__ . '/include/ff_station_list_shell.php';
require_once __DIR__ . '/include/ff_print_target_labels.php';

ff_users_ensure_landing_columns($conn);

$print_target = isset($_GET['print_target']) ? (int) $_GET['print_target'] : 0;
if ($print_target <= 0) {
    echo '<div class="ui-body">Ungültiges Druckziel.</div>';
    exit;
}

$chk = @mysqli_query($conn, "SHOW COLUMNS FROM positionen LIKE 'print_target'");
if ($chk && mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, 'ALTER TABLE positionen ADD COLUMN print_target INT(11) NOT NULL DEFAULT 11');
}
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'print_target'");
if ($chk && mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, 'ALTER TABLE bestellungen ADD COLUMN print_target INT(11) NULL DEFAULT NULL');
}

$targetName = isset($_GET['name']) ? trim((string) $_GET['name']) : '';
if ($targetName === '') {
    $res = mysqli_query($conn, 'SELECT name FROM print_targets WHERE print_target=' . $print_target . ' LIMIT 1');
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $targetName = (string) ($row['name'] ?? '');
    }
}
if ($targetName === '') {
    $targetName = ff_print_target_display_name($conn, $print_target);
}
$targetNameEsc = htmlspecialchars($targetName, ENT_QUOTES, 'UTF-8');

$paymentMode = 'after';
$fres = @mysqli_query($conn, 'SELECT payment_mode FROM feste WHERE aktiv=1 LIMIT 1');
if ($fres && ($frow = mysqli_fetch_assoc($fres))) {
    $paymentMode = $frow['payment_mode'] ?: 'after';
}

$stationCtx = ['wartende' => 0, 'summary' => [], 'sidebar_bg' => '#f1f5f9'];

try {
    $stationCtx = ff_station_build_context($conn, [
        'mode' => 'druckziel',
        'print_target' => $print_target,
        'payment_mode' => $paymentMode,
    ]);
} catch (Exception $e) {
    $stationCtx['error'] = (string) $e->getMessage();
}

$wartendeAnzahl = (int) ($stationCtx['wartende'] ?? 0);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Wartende Bestellungen - <?php echo $targetNameEsc; ?></title>
    <meta http-equiv="refresh" content="5"> <!-- Reload alle 5 Sekunden -->
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 50px;
            background-color: #f8fafc;
            color: #1e293b;
        }
        .counter-box {
            font-size: 4rem;
            font-weight: bold;
            background: #ffffff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            display: inline-block;
            border: 2px solid #e2e8f0;
        }
        .label {
            font-size: 1.2rem;
            color: #64748b;
            margin-top: 15px;
        }
    </style>
</head>
<body>

    <div class="counter-box" id="AnzahlOffeneBestellungenDruckziel">
        <?php echo $wartendeAnzahl; ?>
    </div>
    <div class="label">Wartende Positionen für <?php echo $targetNameEsc; ?></div>

</body>
</html>
