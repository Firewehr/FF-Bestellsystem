<?php
require_once('auth.php');
header("Cache-Control: no-cache, must-revalidate");
$Tischnummer = intval($_GET['tischnummer'] ?? 0);
include_once('include/db.php');
require_once __DIR__ . '/include/menu_lock_helpers.php';
require_once __DIR__ . '/include/menu_tile_helpers.php';
require_once __DIR__ . '/include/menu_list_helpers.php';
require_once __DIR__ . '/include/ff_position_kassa_helpers.php';

ff_menu_ensure_schema($conn);
$menuCols = ff_menu_column_count($conn);
$menuColsMob = ff_menu_column_count_mobile($conn);

$rootId = 'posRootSpeisen_' . (int)$Tischnummer;
$sql1 = "SELECT p.*, s.name AS subcat_name, s.tile_bg AS sub_tile_bg
         FROM positionen p
         LEFT JOIN position_subcategories s ON s.id = p.subcategory_id
         WHERE p.type = 1" . ff_position_sql_kellner_visible('p', 's') . "
         ORDER BY COALESCE(s.sort_order, 99999), COALESCE(s.id, 0), p.reihenfolge, p.rowid";
$result1 = mysqli_query($conn, $sql1);
$rows = [];
if ($result1) {
    while ($r = mysqli_fetch_assoc($result1)) {
        $rows[] = $r;
    }
}
$hasSpeisen = count($rows) > 0;
$sections = [];
foreach ($rows as $rowww) {
    $subKey = !empty($rowww['subcategory_id']) ? (string)(int)$rowww['subcategory_id'] : '_none_';
    $subLabel = !empty($rowww['subcat_name']) ? (string)$rowww['subcat_name'] : 'Ohne Unterkategorie';
    if (!isset($sections[$subKey])) {
        $sections[$subKey] = ['label' => $subLabel, 'items' => []];
    }
    $sections[$subKey]['items'][] = $rowww;
}

$ffPositionIds = ff_menu_collect_position_ids($rows);
$ffOpenCounts = ff_menu_batch_open_counts_tisch($conn, $Tischnummer);
$ffGlobalCounts = ff_menu_batch_global_counts($conn);
$ffLockMap = ff_menu_batch_lock_map($conn, $ffPositionIds, 1);

echo '<div id="' . htmlspecialchars($rootId, ENT_QUOTES, 'UTF-8') . '" class="pos-menu-page">';
if (count($sections) > 1) {
    echo '<div class="pos-subcat-filter mb-2 d-flex flex-wrap gap-1 align-items-center" data-root="#' . htmlspecialchars($rootId, ENT_QUOTES, 'UTF-8') . '">';
    echo '<span class="small text-muted me-1">Unterkategorie:</span>';
    echo '<button type="button" class="btn btn-sm btn-primary ff-subcat-chip" data-subkey="_all_">Alle</button>';
    foreach ($sections as $sk => $sec) {
        echo '<button type="button" class="btn btn-sm btn-outline-secondary ff-subcat-chip" data-subkey="' . htmlspecialchars($sk, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($sec['label'], ENT_QUOTES, 'UTF-8') . '</button>';
    }
    echo '</div>';
}

foreach ($sections as $sk => $sec) {
    echo '<div class="pos-subcat-section" data-subkey="' . htmlspecialchars($sk, ENT_QUOTES, 'UTF-8') . '">';
    echo '<h6 class="pos-menu-subheading text-secondary fw-semibold small text-uppercase mt-3 mb-2 px-1">' . htmlspecialchars($sec['label'], ENT_QUOTES, 'UTF-8') . '</h6>';
    echo '<div class="pos-menu-grid" style="--pos-cols:' . (int)$menuCols . '; --pos-cols-mobile:' . (int)$menuColsMob . ';">';
    foreach ($sec['items'] as $rowww) {
        try {

        $pid = (int)$rowww['rowid'];
        $openCnt = (int)($ffOpenCounts[$pid] ?? 0);
        $anzahlBestellt = (int)($ffGlobalCounts[$pid] ?? 0);
        $text = '';

        $maxBestellbar = (int)$rowww['maxBestellbar'];
        $rest = 999999;
        if ($maxBestellbar > 0) {
            $rest = $maxBestellbar - $anzahlBestellt;
            if ($rest <= 0) {
                $text = ' AUSVERKAUFT!';
            } elseif ($rest < 10) {
                $text = ' Noch ' . $rest . '×';
            }
        }

        $lockInfo = $ffLockMap[$pid] ?? null;
        $menuLocked = ($lockInfo !== null);
        $lockHint = '';
        if ($menuLocked) {
            $lockHint = 'Gesperrt: ' . ($lockInfo['reason'] !== '' ? $lockInfo['reason'] . ' — ' : '') . $lockInfo['until_label'];
            $text = ($text !== '' ? $text . ' ' : '') . '<span class="text-danger fw-bold">GESPERRT</span> (' . htmlspecialchars($lockInfo['until_label'], ENT_QUOTES, 'UTF-8') . ')';
            if ($lockInfo['reason'] !== '') {
                $text .= ' — ' . htmlspecialchars($lockInfo['reason'], ENT_QUOTES, 'UTF-8');
            }
        }

        $baseBg = ff_menu_resolve_base_bg($rowww, $rowww['sub_tile_bg'] ?? null);
        $Colour = $baseBg;
        $fontColour = ff_menu_font_color_row($rowww);

        $soldOut = ($maxBestellbar > 0 && $rest <= 0);
        $lowStock = ($maxBestellbar > 0 && $rest > 0 && $rest < 10);

        if ($soldOut) {
            $Colour = '#ff0000';
            $fontColour = '#ffffff';
        } elseif ($lowStock && !$menuLocked) {
            $Colour = '#ff7f00';
            $fontColour = '#ffffff';
        } elseif ($menuLocked) {
            $Colour = '#e9ecef';
            $fontColour = '#6c757d';
        }

        $showSelected = ($openCnt > 0 && !$soldOut && !$menuLocked && !$lowStock);
        if ($showSelected) {
            $fontColour = '#0f172a';
        }

        $dataBaseForWrap = $baseBg;
        if ($soldOut || $lowStock || $menuLocked) {
            $dataBaseForWrap = $Colour;
        }

        $btnClass = 'ui-btn ui-corner-all big pos-menu-tile';
        if ($showSelected) {
            $btnClass .= ' pos-tile--selected';
        } elseif ($soldOut) {
            $btnClass .= ' pos-tile--sold-out';
        } elseif ($lowStock && !$menuLocked) {
            $btnClass .= ' pos-tile--low-stock';
        }

        echo '<div class="posWrap" data-basebg="' . htmlspecialchars($dataBaseForWrap, ENT_QUOTES, 'UTF-8') . '">';

        $restAttr = ($maxBestellbar > 0) ? ' data-rest="' . (int)$rest . '"' : '';
        $disabledAttr = '';
        if ($soldOut) {
            $disabledAttr = ' disabled="disabled" title="Ausverkauft"';
        } elseif ($menuLocked) {
            $disabledAttr = ' disabled="disabled" title="' . htmlspecialchars($lockHint, ENT_QUOTES, 'UTF-8') . '"';
        }
        $btnStyle = 'white-space: normal !important; height: 80px; color:' . htmlspecialchars($fontColour, ENT_QUOTES, 'UTF-8') . ';';
        if (!$showSelected) {
            $btnStyle .= ' background:' . htmlspecialchars($Colour, ENT_QUOTES, 'UTF-8') . ';';
        }

        echo '<button id="btn-pos-' . (int)$rowww['rowid'] . '" class="' . htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8') . '"' . $restAttr . $disabledAttr . '
                onclick="if(this.disabled||parseInt(this.getAttribute(\'data-rest\')||999,10)<=0) return false; saveBestellung(' . (int)$rowww['rowid'] . ',1,' . (int)$Tischnummer . ',0); return false;"
                style="' . $btnStyle . '">';

        echo htmlspecialchars($rowww['Positionsname'], ENT_QUOTES, 'UTF-8');

        echo ' <span class="cnt" id="cnt-' . (int)$rowww['rowid'] . '" data-cnt="' . (int)$openCnt . '">';
        if ($openCnt > 0) {
            echo ' (' . (int)$openCnt . 'x)';
        }
        echo '</span>';

        if ($text !== '') {
            $metaClass = 'pos-tile-meta';
            if ($soldOut) {
                $metaClass .= ' pos-tile-meta--sold';
            } elseif ($lowStock && !$menuLocked) {
                $metaClass .= ' pos-tile-meta--warn';
            }
            echo '<span class="' . htmlspecialchars($metaClass, ENT_QUOTES, 'UTF-8') . '">' . $text . '</span>';
        }
        echo '</button>';

        $hinweisRest = ($maxBestellbar > 0) ? (int)$rest : 0;
        echo '<a href="#" class="btnMinus" title="1x entfernen"
                  onclick="minusPosition(event, ' . (int)$Tischnummer . ', ' . (int)$rowww['rowid'] . ', 1); return false;">–</a>';
        echo '<a href="#" class="btnHinweis" title="Hinweise pro Portion (auch unterschiedlich)"
                  onclick="var b=document.getElementById(\'btn-pos-' . (int)$rowww['rowid'] . '\'); if(b && b.disabled && ' . ($openCnt > 0 ? 'false' : 'true') . ') return false; ffOpenPosHinweisModal(' . (int)$rowww['rowid'] . ',1,' . (int)$Tischnummer . ',0,' . (int)$openCnt . ',' . $hinweisRest . '); return false;">…</a>';

        echo '</div>';
        } catch (Exception $e) {
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
    echo '</div></div>';
}

if (!$hasSpeisen) {
    echo '<div class="col-12 p-4 text-center text-muted"><p>Keine Speisen in der Karte. Bitte im Admin Produkte anlegen (type=1).</p></div>';
}
echo '</div>';
?>

<style>
.posWrap button[disabled] { cursor: not-allowed; opacity: 0.92; pointer-events: none; }
</style>
