<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_print_target_labels.php';

$print_target = isset($_GET['print_target']) ? (int) $_GET['print_target'] : 0;
if ($print_target <= 0) {
    echo '<nav class="navbar app-navbar sticky-top"><div class="container-fluid">';
    echo '<a href="#indexPage" class="btn btn-outline-secondary btn-sm" onclick="' . htmlspecialchars(ff_nav_home_onclick(), ENT_QUOTES, 'UTF-8') . '">← Zurück</a>';
    echo '<span class="navbar-brand mb-0">Historie</span></div></nav>';
    echo '<div class="app-content py-3"><div class="alert alert-warning mb-0">Ungültiges Druckziel.</div></div>';
    exit;
}

$targetName = ff_print_target_display_name($conn, $print_target);
$targetNameEsc = htmlspecialchars($targetName, ENT_QUOTES, 'UTF-8');
$ptJs = (int) $print_target;

require_once __DIR__ . '/include/ff_station_history.php';

$ptFilter = ' AND (COALESCE(bestellungen.print_target, positionen.print_target) = ' . $ptJs . ') ';
$backOnclick = "if(typeof DruckzielAnsicht==='function'){DruckzielAnsicht(" . $ptJs . ",'');}return false;";
ff_station_history_render(
    $conn,
    $ptFilter,
    'Historie – ' . $targetName,
    $backOnclick,
    'Neueste Bestellungen für dieses Druckziel zuerst, nach Bestellung gruppiert.',
    'druckziel',
    $print_target
);
