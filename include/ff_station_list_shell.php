<?php
/**
 * Gemeinsame Hülle: Navbar + Grid (Hauptliste links, Warteschlange rechts).
 * Für list_druckziel.php, list_kueche.php, list_schank.php (und Druckziel 11/12).
 */
require_once __DIR__ . '/user_landing.php';

/**
 * @param array{
 *   title: string,
 *   title_esc?: string,
 *   bell_btn_id: string,
 *   lock_panel_id: string,
 *   history_onclick: string,
 *   back_onclick: string,
 *   orders_container_id: string,
 *   sidebar_bg: string,
 *   forecast_print_target?: int
 * } $cfg
 */
function ff_station_render_page_open(array $cfg): void
{
    $titleEsc = $cfg['title_esc'] ?? htmlspecialchars((string) ($cfg['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordersId = htmlspecialchars((string) ($cfg['orders_container_id'] ?? 'stationOrders'), ENT_QUOTES, 'UTF-8');
    $bellId = htmlspecialchars((string) ($cfg['bell_btn_id'] ?? 'stationBellBtn'), ENT_QUOTES, 'UTF-8');
    $lockId = htmlspecialchars((string) ($cfg['lock_panel_id'] ?? 'StationLockPanel'), ENT_QUOTES, 'UTF-8');
    $sidebarBg = htmlspecialchars((string) ($cfg['sidebar_bg'] ?? '#f1f5f9'), ENT_QUOTES, 'UTF-8');
    $historyOnclick = (string) ($cfg['history_onclick'] ?? '');
    $forecastPrintTarget = (int) ($cfg['forecast_print_target'] ?? 0);
    $backExtra = trim((string) ($cfg['back_onclick'] ?? ''));
    $backOnclick = ff_nav_home_onclick();
    if ($backExtra !== '') {
        $backOnclick = rtrim($backExtra, ';') . ';' . $backOnclick;
    }

    echo '<nav class="navbar app-navbar sticky-top ff-station-navbar">';
    echo '<div class="container-fluid d-flex align-items-center flex-wrap py-1 px-2 gap-2">';
    echo '<a href="#indexPage" class="btn btn-outline-secondary btn-sm flex-shrink-0" onclick="' . htmlspecialchars($backOnclick, ENT_QUOTES, 'UTF-8') . '">← Zurück</a>';
    echo '<span class="navbar-brand mb-0 ms-2 me-auto text-truncate d-block ff-station-title" title="' . $titleEsc . '">' . $titleEsc . '</span>';
    echo '<div class="d-flex align-items-center justify-content-end flex-wrap gap-2 ms-auto ff-station-actions">';
    echo '<div class="ff-station-stats d-none d-md-flex" id="ffStationStats" aria-live="off" title="Offene Bestellungen · Positionen · längste Wartezeit"></div>';
    if ($forecastPrintTarget > 0) {
        echo '<button type="button" class="btn btn-outline-light btn-sm" onclick="ffOpenStationForecast(' . $forecastPrintTarget . ')">Prognose</button>';
        echo '<a class="btn btn-outline-light btn-sm" href="substation.php">Sub-Stationen</a>';
    }
    echo '<button type="button" class="btn btn-outline-secondary btn-sm" id="' . $bellId . '" onclick="ffToggleOpsBell(this);" title="Neue-Bestellung-Klingel">Klingel</button>';
    echo '<button type="button" class="btn btn-outline-primary btn-sm" onclick="' . htmlspecialchars($historyOnclick, ENT_QUOTES, 'UTF-8') . '">Historie</button>';
    echo '<button type="button" class="btn btn-outline-warning btn-sm" onclick="window._pauseOperationsPoll=true;loadContent(\'menu_locks_ui.php?embed=1\',\'' . $lockId . '\',function(){var p=document.getElementById(\'' . $lockId . '\');if(p)p.classList.remove(\'d-none\');});">Sperren</button>';
    echo '</div></div></nav>';
    echo '<script>if(typeof ffSyncOpsBellButton===\'function\'){setTimeout(function(){var b=document.getElementById(\'' . $bellId . '\');if(b)ffSyncOpsBellButton(b);},0);}</script>';
    echo '<div id="' . $lockId . '" class="app-content border-bottom bg-light d-none"></div>';
    echo '<div class="kueche-layout druckziel-kueche-layout ff-station-layout">';
    echo '<div class="kueche-main">';
    echo '<div id="' . $ordersId . '" class="kueche-orders kueche-orders-flow">';
}

/** Sidebar öffnen (nach ff_station_render_page_open, nach Listeninhalt). */
function ff_station_render_page_sidebar_open(string $sidebarBg): void
{
    echo '</div></div>';
    echo '<div class="kueche-sidebar ff-station-sidebar" style="background-color:' . htmlspecialchars($sidebarBg, ENT_QUOTES, 'UTF-8') . '">';
}

function ff_station_render_page_close(): void
{
    echo '</div></div>';
}
