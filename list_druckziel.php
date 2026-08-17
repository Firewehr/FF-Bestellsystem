<?php
/**
 * Stationsliste pro Druckziel (z. B. Küche=11, Schank=12).
 * Aufruf: list_druckziel.php?print_target=11
 */
require_once __DIR__ . '/auth.php';
include_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
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

$containerId = 'druckzielOrders' . $print_target;
$stationCtx = ['wartende' => 0, 'summary' => [], 'sidebar_bg' => '#f1f5f9'];
$dzSummary = [];
$sidebarBg = '#f1f5f9';

try {
    $stationCtx = ff_station_build_context($conn, [
        'mode' => 'druckziel',
        'print_target' => $print_target,
        'payment_mode' => $paymentMode,
    ]);
    $sidebarBg = $stationCtx['sidebar_bg'];
    $dzSummary = $stationCtx['summary'];
} catch (Exception $e) {
    $stationCtx['error'] = (string) $e->getMessage();
}

$summaryTitle = ($stationCtx['summary_mode'] ?? 'open') === 'all'
    ? 'Übersicht – ' . $targetNameEsc . ' (noch nicht ausgeliefert)'
    : 'Übersicht – ' . $targetNameEsc . ' (offen / in Zubereitung)';
$summaryTitleEsc = htmlspecialchars($summaryTitle, ENT_QUOTES, 'UTF-8');

$showSummaryTop = setting_get($conn, 'station_summary_top', '1') === '1';
$showSummaryRight = setting_get($conn, 'station_summary_right', '1') === '1';

ff_station_render_page_open([
    'title' => $targetName,
    'title_esc' => $targetNameEsc,
    'bell_btn_id' => 'druckzielBellBtn' . $print_target,
    'lock_panel_id' => 'DruckzielLockPanel' . $print_target,
    'history_onclick' => 'DruckzielHistory(' . (int) $print_target . ');',
    'back_onclick' => 'if(typeof AnzahlBestellungenAktuell!==\'undefined\')AnzahlBestellungenAktuell=-1;',
    'orders_container_id' => $containerId,
    'sidebar_bg' => $sidebarBg,
    'show_sidebar' => $showSummaryRight,
]);
echo '<!-- ff-station-modular-v2 -->';
ff_station_render_body(
    $conn,
    $stationCtx,
    'AnzahlOffeneBestellungenDruckziel',
    'wartende Positionen',
    $showSummaryTop ? $summaryTitleEsc : ''
);
if ($showSummaryRight) {
    ff_station_render_page_sidebar_open($sidebarBg);
    ff_station_render_sidebar(
        (int) ($stationCtx['wartende'] ?? 0),
        $dzSummary,
        'Keine offenen Positionen in dieser Station.',
        false
    );
}
ff_station_render_page_close($showSummaryRight);
?>
