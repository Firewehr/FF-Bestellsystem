<?php
declare(strict_types=1);

/**
 * Liefert die gesamte Speisekarte für die Kellner-App.
 * Beinhaltet Hauptkategorien, Unterkategorien, Positionen, Kapazitäten/Bestand,
 * Sperren, Wartezeiten und hinterlegte Beilagen/Zusatzinfos.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/menu_lock_helpers.php';
require_once dirname(__DIR__, 2) . '/include/menu_tile_helpers.php';
require_once dirname(__DIR__, 2) . '/include/menu_list_helpers.php';
require_once dirname(__DIR__, 2) . '/include/ff_position_stock_summary.php';

api_require_auth();

// Aktives Fest ermitteln
$activeFestId = 0;
$festRes = mysqli_query($conn, 'SELECT id FROM feste WHERE aktiv = 1 LIMIT 1');
if ($festRes && ($fRow = mysqli_fetch_assoc($festRes))) {
    $activeFestId = (int)$fRow['id'];
}

// Wartezeiten berechnen
$waitTimes = ['drink' => 0, 'food' => 0];
$wq = @mysqli_query(
    $conn,
    "SELECT print_target, UNIX_TIMESTAMP(MIN(zeitstempel)) AS oldest_unix
       FROM bestellungen
      WHERE `delete`=0
        AND kueche=0
        AND zeitKueche='0000-00-00 00:00:00'
        AND print_target IN (11,12)
      GROUP BY print_target"
);
if ($wq) {
    while ($wr = mysqli_fetch_assoc($wq)) {
        $ptId = (int)($wr['print_target'] ?? 0);
        $oldestUnix = (int)($wr['oldest_unix'] ?? 0);
        if ($oldestUnix > 0) {
            $mins = (int) round(max(0, time() - $oldestUnix) / 60);
            if ($mins <= 120) {
                if ($ptId === 12) $waitTimes['drink'] = $mins;
                if ($ptId === 11) $waitTimes['food'] = $mins;
            }
        }
    }
    @mysqli_free_result($wq);
}

// 1. Hauptkategorien (Typen: Getränke zuerst, dann Speisen wie im Original)
$types = [
    ['id' => 2, 'name' => 'Getränke', 'icon' => '🍺'],
    ['id' => 1, 'name' => 'Speisen', 'icon' => '🍔'],
];

// 2. Unterkategorien
$subcategories = [];
$subcategoriesMap = [];
$subSql = 'SELECT id, type, name, sort_order, COALESCE(kassa_only, 0) AS kassa_only, COALESCE(tile_bg, "") AS tile_bg '
    . 'FROM position_subcategories '
    . ($activeFestId > 0 ? "WHERE (fest_id IS NULL OR fest_id = {$activeFestId}) " : '')
    . 'ORDER BY type ASC, sort_order ASC, name ASC';
$subRes = mysqli_query($conn, $subSql);
if ($subRes) {
    while ($sRow = mysqli_fetch_assoc($subRes)) {
        $subId = (int)$sRow['id'];
        $cleanSubTile = ff_sanitize_category_tile_bg($sRow['tile_bg'] ?? null) ?: '';
        $subData = [
            'id' => $subId,
            'type' => (int)$sRow['type'],
            'name' => (string)$sRow['name'],
            'sort_order' => (int)$sRow['sort_order'],
            'kassa_only' => (int)$sRow['kassa_only'] === 1,
            'tile_bg' => $cleanSubTile,
            'color_style' => ff_menu_subcat_color_style($cleanSubTile),
        ];
        $subcategories[] = $subData;
        $subcategoriesMap[$subId] = $subData;
    }
    mysqli_free_result($subRes);
}

// 3. Beilagen & Zusatzinfos laden (pro Position zuordnen)
$beilagenByPos = [];
$bSql = 'SELECT rowid, name, position, COALESCE(betrag, 0) AS betrag FROM beilagen '
    . ($activeFestId > 0 ? "WHERE (fest_id IS NULL OR fest_id = {$activeFestId}) " : '')
    . 'ORDER BY name ASC';
$bRes = mysqli_query($conn, $bSql);
if ($bRes) {
    while ($bRow = mysqli_fetch_assoc($bRes)) {
        $posId = (int)$bRow['position'];
        if (!isset($beilagenByPos[$posId])) {
            $beilagenByPos[$posId] = [];
        }
        $beilagenByPos[$posId][] = [
            'id' => (int)$bRow['rowid'],
            'name' => (string)$bRow['name'],
            'price' => (float)$bRow['betrag'],
        ];
    }
    mysqli_free_result($bRes);
}

// 4. Rezepturen (Bestandteile) laden
$recipesByPos = [];
$rSql = 'SELECT pr.position_id, pr.bestandteil_id, pr.menge, pr.optional_enabled, pr.default_selected,
                pr.option_group, b.name, b.einheit, b.kapazitaet
         FROM position_rezepturen pr
         INNER JOIN bestandteile b ON b.id = pr.bestandteil_id
         WHERE b.aktiv = 1 '
    . ($activeFestId > 0 ? "AND (pr.fest_id IS NULL OR pr.fest_id = {$activeFestId}) " : '')
    . 'ORDER BY pr.position_id ASC, pr.reihenfolge ASC, pr.id ASC';
$rRes = mysqli_query($conn, $rSql);
if ($rRes) {
    while ($rRow = mysqli_fetch_assoc($rRes)) {
        $pId = (int)$rRow['position_id'];
        if (!isset($recipesByPos[$pId])) {
            $recipesByPos[$pId] = [];
        }
        $recipesByPos[$pId][] = [
            'bestandteil_id' => (int)$rRow['bestandteil_id'],
            'name' => (string)$rRow['name'],
            'einheit' => (string)($rRow['einheit'] ?? 'Stk'),
            'menge' => (float)$rRow['menge'],
            'optional_enabled' => (int)$rRow['optional_enabled'] === 1,
            'default_selected' => (int)$rRow['default_selected'] === 1,
            'option_group' => (string)($rRow['option_group'] ?? ''),
        ];
    }
    mysqli_free_result($rRes);
}

// Global konsumierte Mengen für Kapazitätsberechnung
$globalCounts = ff_menu_batch_global_counts($conn);

// 5. Positionen (Speisen & Getränke)
$posSql = 'SELECT rowid, Positionsname, Kurzbezeichnung, Betrag, type, '
    . 'COALESCE(subcategory_id, 0) AS subcategory_id, '
    . 'COALESCE(color, "") AS color, '
    . 'COALESCE(tile_bg, "") AS tile_bg, '
    . 'COALESCE(print_target, 11) AS print_target, '
    . 'COALESCE(maxBestellbar, 0) AS maxBestellbar, '
    . 'COALESCE(kassa_only, 0) AS kassa_only '
    . 'FROM positionen '
    . ($activeFestId > 0 ? "WHERE (fest_id IS NULL OR fest_id = {$activeFestId}) " : '')
    . 'ORDER BY type ASC, reihenfolge ASC, Positionsname ASC';

$posRes = mysqli_query($conn, $posSql);
if (!$posRes) {
    api_error('Konnte Speisekarte nicht abfragen: ' . mysqli_error($conn), 'db_error', 500);
}

$items = [];
while ($pRow = mysqli_fetch_assoc($posRes)) {
    $posId = (int)$pRow['rowid'];
    $posType = (int)$pRow['type'];
    $subId = (int)$pRow['subcategory_id'];
    $subTileBg = $subcategoriesMap[$subId]['tile_bg'] ?? null;

    $isLocked = menu_lock_is_blocked($conn, $posId, $posType);
    $lockInfo = $isLocked ? menu_lock_get_status($conn, $posId, $posType) : null;

    $maxOrderable = (int)$pRow['maxBestellbar'];
    $anzahlBestellt = (int)($globalCounts[$posId] ?? 0);
    $cap = ff_position_display_capacity($conn, $posId, $maxOrderable, $anzahlBestellt);
    $isLimited = !empty($cap['limited']);
    $rest = $isLimited ? (int)($cap['rest'] ?? 0) : null;
    $soldOut = ($isLimited && $rest !== null && $rest <= 0);
    $lowStock = ($isLimited && $rest !== null && $rest > 0 && $rest < 10);

    // Farb-Auflösung exakt nach bestehendem System
    $baseBg = ff_menu_resolve_base_bg($pRow, $subTileBg);
    $colour = $baseBg;
    $fontColour = ff_menu_font_color_row($pRow);

    if ($lowStock && !$isLocked) {
        $colour = '#ff7f00';
        $fontColour = '#ffffff';
    } elseif ($isLocked) {
        $colour = '#e9ecef';
        $fontColour = '#6c757d';
    }

    $items[] = [
        'id' => $posId,
        'name' => (string)$pRow['Positionsname'],
        'short_name' => (string)($pRow['Kurzbezeichnung'] ?: $pRow['Positionsname']),
        'price' => (float)$pRow['Betrag'],
        'type' => $posType,
        'subcategory_id' => $subId,
        'color' => (string)$pRow['color'],
        'tile_bg' => (string)$pRow['tile_bg'],
        'resolved_bg' => $colour,
        'resolved_color' => $fontColour,
        'print_target' => (int)$pRow['print_target'],
        'max_orderable' => $maxOrderable,
        'is_limited' => $isLimited,
        'rest' => $rest,
        'is_sold_out' => $soldOut,
        'is_low_stock' => $lowStock,
        'kassa_only' => (int)$pRow['kassa_only'] === 1,
        'is_locked' => $isLocked,
        'lock_reason' => $lockInfo ? (string)($lockInfo['reason'] ?? '') : null,
        'beilagen' => $beilagenByPos[$posId] ?? [],
        'recipe_components' => $recipesByPos[$posId] ?? [],
    ];
}
mysqli_free_result($posRes);

api_response([
    'ok' => true,
    'fest_id' => $activeFestId,
    'wait_times' => $waitTimes,
    'types' => $types,
    'subcategories' => $subcategories,
    'items' => $items,
]);
