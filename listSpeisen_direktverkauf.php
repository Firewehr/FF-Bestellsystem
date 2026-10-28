<?php

$Tischnummer = 999999;

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
    require_once __DIR__ . '/include/db.php';
    ff_direktverkauf_require($conn);
} elseif (!defined('FF_DV_FRAGMENT_CAPTURE')) {
    require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
    ff_direktverkauf_require($conn);
}

if (!headers_sent()) {
    header('Cache-Control: no-cache, must-revalidate');
}
require_once __DIR__ . '/include/menu_lock_helpers.php';
require_once __DIR__ . '/include/menu_tile_helpers.php';
require_once __DIR__ . '/include/menu_list_helpers.php';
require_once __DIR__ . '/include/ff_position_stock_summary.php';
require_once __DIR__ . '/include/ff_fest_scope.php';

ff_timing_mark('start');
ff_menu_ensure_schema($conn);
ff_fest_scope_ensure_columns($conn);
$kellnerFilter = ff_direktverkauf_kellner_filter_sql($conn);
$bonId = trim((string) ($_GET['bon_id'] ?? ''));
$menuCols = ff_menu_column_count($conn);
$menuColsMob = ff_menu_column_count_mobile($conn);

$rootId = 'posRootDvSpeisen_' . (int)$Tischnummer;
$sql1 = "SELECT p.*, s.name AS subcat_name, s.tile_bg AS sub_tile_bg
         FROM positionen p
         LEFT JOIN position_subcategories s ON s.id = p.subcategory_id
         WHERE p.type = 1" . ff_fest_scope_position_sql($conn, 'p') . "
         ORDER BY COALESCE(s.sort_order, 99999), COALESCE(s.id, 0), p.reihenfolge, p.rowid";
$result1 = mysqli_query($conn, $sql1);
$rows = [];
if ($result1) {
    while ($r = mysqli_fetch_assoc($result1)) {
        $rows[] = $r;
    }
}
$sections = [];
foreach ($rows as $rowww) {
    $subKey = !empty($rowww['subcategory_id']) ? (string)(int)$rowww['subcategory_id'] : '_none_';
    $subLabel = !empty($rowww['subcat_name']) ? (string)$rowww['subcat_name'] : 'Sonstige';
    if (!isset($sections[$subKey])) {
        $sections[$subKey] = ['label' => $subLabel, 'items' => [], 'color' => ff_menu_subcat_color_style($rowww['sub_tile_bg'] ?? null)];
    }
    $sections[$subKey]['items'][] = $rowww;
}

ff_timing_mark('positionen_sql');
$ffPositionIds = ff_menu_collect_position_ids($rows);
ff_timing_mark('gruppieren');
ff_position_stock_snapshot_begin($conn);
$ffOpenCounts = ff_menu_batch_open_counts_direkt($conn, $kellnerFilter, $bonId);
$ffGlobalCounts = ff_menu_batch_global_counts($conn, $kellnerFilter);
$ffLockMap = ff_menu_batch_lock_map($conn, $ffPositionIds, 1);
ff_timing_mark('batch');

echo '<div id="' . htmlspecialchars($rootId, ENT_QUOTES, 'UTF-8') . '" class="pos-menu-page">';
if (count($sections) > 1) {
    echo '<div class="pos-subcat-filter category-nav mb-2" data-root="#' . htmlspecialchars($rootId, ENT_QUOTES, 'UTF-8') . '">';
    echo '<button type="button" class="category-button ff-subcat-chip active" data-subkey="_all_">Alle</button>';
    foreach ($sections as $sk => $sec) {
        $secDomId = 'subcat-' . htmlspecialchars($rootId, ENT_QUOTES, 'UTF-8') . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$sk);
        echo '<button type="button" class="category-button ff-subcat-chip" data-subkey="' . htmlspecialchars($sk, ENT_QUOTES, 'UTF-8') . '" data-target="#' . $secDomId . '">' . htmlspecialchars($sec['label'], ENT_QUOTES, 'UTF-8') . '</button>';
    }
    echo '</div>';
}

foreach ($sections as $sk => $sec) {
    $secDomId = 'subcat-' . htmlspecialchars($rootId, ENT_QUOTES, 'UTF-8') . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$sk);
    echo '<div id="' . $secDomId . '" class="pos-subcat-section category" data-subkey="' . htmlspecialchars($sk, ENT_QUOTES, 'UTF-8') . '"' . ($sec['color'] !== '' ? ' style="' . htmlspecialchars($sec['color'], ENT_QUOTES, 'UTF-8') . '"' : '') . '>';
    echo '<h6 class="pos-menu-subheading text-secondary fw-semibold small text-uppercase mt-3 mb-2 px-1">'
        . htmlspecialchars($sec['label'], ENT_QUOTES, 'UTF-8')
        . ' <span class="badge bg-secondary ms-1 fw-normal opacity-75" style="font-size: 0.7rem;">' . count($sec['items']) . '</span>'
        . '</h6>';
    echo '<div class="pos-menu-grid" style="--pos-cols:' . (int)$menuCols . '; --pos-cols-mobile:' . (int)$menuColsMob . ';">';
    foreach ($sec['items'] as $rowww) {
        try {

        $pid = (int)$rowww['rowid'];
        $openCnt = (int)($ffOpenCounts[$pid] ?? 0);
        $anzahlBestellt = (int)($ffGlobalCounts[$pid] ?? 0);

        $text = '';
        $maxBestellbar = (int)$rowww['maxBestellbar'];
        $cap = ff_position_display_capacity($conn, $pid, $maxBestellbar, $anzahlBestellt);
        $isLimited = !empty($cap['limited']);
        $rest = $isLimited ? (int)($cap['rest'] ?? 0) : 999999;
        if ($isLimited) {
            if ($rest > 0 && $rest < 10) {
                $text = ' Noch ' . $rest . '×';
            }
        }

        $lockInfo = $ffLockMap[$pid] ?? null;
        $menuLocked = ($lockInfo !== null);
        $lockHint = '';
        if ($menuLocked) {
            $lockHint = 'Gesperrt: ' . ($lockInfo['reason'] !== '' ? $lockInfo['reason'] . ' — ' : '') . $lockInfo['until_label'];
            $text = ($text !== '' ? $text . ' ' : '') . 'GESPERRT (' . htmlspecialchars($lockInfo['until_label'], ENT_QUOTES, 'UTF-8') . ')';
            if ($lockInfo['reason'] !== '') {
                $text .= ' — ' . htmlspecialchars($lockInfo['reason'], ENT_QUOTES, 'UTF-8');
            }
        }

        $baseBg = ff_menu_resolve_base_bg($rowww, $rowww['sub_tile_bg'] ?? null);
        $Colour = $baseBg;
        $fontColour = ff_menu_font_color_row($rowww);

        $soldOut = ($isLimited && $rest <= 0);
        $lowStock = ($isLimited && $rest > 0 && $rest < 10);

        if ($lowStock && !$menuLocked) {
            $Colour = '#ff7f00';
            $fontColour = '#ffffff';
        } elseif ($menuLocked) {
            $Colour = '#e9ecef';
            $fontColour = '#6c757d';
        }

        $showSelected = ($openCnt > 0 && !$soldOut && !$menuLocked && !$lowStock);
        
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

        $restAttr = $isLimited ? ' data-rest="' . (int)$rest . '"' : '';
        $disabledAttr = '';
        if ($soldOut) {
            $disabledAttr = ' disabled="disabled" title="Ausverkauft"';
        } elseif ($menuLocked) {
            $disabledAttr = ' disabled="disabled" title="' . htmlspecialchars($lockHint, ENT_QUOTES, 'UTF-8') . '"';
        }

        $btnStyle = 'white-space: normal !important; height: 80px; color:' . htmlspecialchars($fontColour, ENT_QUOTES, 'UTF-8') . ';';
        if (true) {
            $btnStyle .= ' background:' . htmlspecialchars($Colour, ENT_QUOTES, 'UTF-8') . ';';
        }

        echo '<button id="btn-pos-' . (int)$rowww['rowid'] . '" class="' . htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8') . '"' . $restAttr . $disabledAttr . '
            onclick="if(this.disabled) return false; saveBestellung(' . (int)$rowww['rowid'] . ',1,' . (int)$Tischnummer . ',1); return false;"
                style="' . $btnStyle . '">';
        $posName = trim((string)($rowww['Positionsname'] ?? ''));
        $posNameParts = $posName === '' ? [''] : preg_split('/\s+/', $posName, 2);
        $firstWord = $posNameParts[0] ?? '';
        $restName = count($posNameParts) > 1 ? $posNameParts[1] : '';
        $qtyText = ff_menu_split_leading_quantity($restName);
        echo '<span class="pos-tile-name">';
        if ($firstWord !== '') {
            echo '<span class="pos-first-word">' . htmlspecialchars($firstWord, ENT_QUOTES, 'UTF-8')
                . ($qtyText !== '' ? ' <span class="pos-qty">' . htmlspecialchars($qtyText, ENT_QUOTES, 'UTF-8') . '</span>' : '')
                . '</span>';
        }
        if ($restName !== '') {
            echo '<span class="pos-rest-name">' . htmlspecialchars($restName, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        echo '</span>';
        echo '<span class="cnt" id="cnt-' . (int)$rowww['rowid'] . '" data-cnt="' . (int)$openCnt . '">';
        if ($openCnt > 0) {
            echo (int)$openCnt . 'x';
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

        $hinweisRest = $isLimited ? (int)$rest : 0;
        echo '<a href="#" class="btnMinus" title="1x entfernen"
                  onclick="minusPosition(event, ' . (int)$Tischnummer . ', ' . (int)$rowww['rowid'] . ', 1); return false;">–</a>';
        echo '<a href="#" class="btnHinweis" aria-label="Hinweis" title="Hinweise pro Portion (auch unterschiedlich)"
                  onclick="var b=document.getElementById(\'btn-pos-' . (int)$rowww['rowid'] . '\'); if(b && b.disabled && ' . ($openCnt > 0 ? 'false' : 'true') . ') return false; ffOpenPosHinweisModal(' . (int)$rowww['rowid'] . ',1,' . (int)$Tischnummer . ',1,' . (int)$openCnt . ',' . $hinweisRest . '); return false;">💬</a>';

        echo '</div>';
        } catch (Exception $e) {
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
    echo '</div></div>';
}

echo '</div>';
ff_timing_mark('render');
ff_timing_flush();
?>
<style>
.pos-menu-cell { min-width: 0; }
.posWrap button[disabled] { cursor: not-allowed; opacity: 0.92; pointer-events: none; }
</style>
