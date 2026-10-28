<?php

require_once('auth.php');
header("Cache-Control: no-cache");
$positionsid = intval($_POST['positionsid'] ?? 0);
$tischnummer = intval($_POST['Tischnummer'] ?? 0);
$kuechefertig = isset($_POST['kuechefertig']) ? (string)$_POST['kuechefertig'] : '0';

$ziRaw = isset($_POST['Zusatzinfo']) ? trim((string)$_POST['Zusatzinfo']) : '';

$menge = isset($_POST['menge']) ? (int)$_POST['menge'] : 1;
if ($menge < 1) {
    $menge = 1;
}
if ($menge > 50) {
    $menge = 50;
}

require_once('include/db.php');
require_once('include/settings.php');
require_once('include/ff_schreibaus.php');
require_once('include/menu_lock_helpers.php');
require_once('include/menu_list_helpers.php');
require_once('include/beilage_helpers.php');
require_once __DIR__ . '/include/ff_position_kassa_helpers.php';
require_once __DIR__ . '/include/ff_position_stock_summary.php';
require_once __DIR__ . '/include/ff_schema_helpers.php';
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
require_once __DIR__ . '/include/ff_order_recipe_components.php';
require_once __DIR__ . '/include/ff_event_notifications.php';
ff_schema_ensure_hot_paths($conn);
ff_event_notifications_bootstrap($conn);

if ($tischnummer === 999999) {
    ff_direktverkauf_require($conn);
} else {
    ff_direktverkauf_block_table_order($conn);
}

ff_position_kassa_ensure_schema($conn);

// Wenn Feste gepflegt werden: ohne aktives Fest (aktiv=1) keine neuen Tisch-Buchungen (nach Festabschluss gesperrt). Direktverkauf 999999 ausgenommen.
if ($tischnummer !== 999999) {
    $fc = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM feste');
    $nF = 0;
    if ($fc && ($fr = mysqli_fetch_assoc($fc))) {
        $nF = (int) ($fr['c'] ?? 0);
    }
    if ($nF > 0) {
        $fa = @mysqli_query($conn, 'SELECT id FROM feste WHERE aktiv=1 LIMIT 1');
        if (!$fa || mysqli_num_rows($fa) === 0) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok' => false,
                'error' => 'kein_aktives_fest',
                'message' => 'Kein aktives Fest – neue Buchungen sind gesperrt. Bitte im Admin ein Fest aktiv schalten („Als aktuelles Fest setzen“) oder ein neues Fest anlegen.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// Spalten-Migration: zentralisiert in ff_schema_ensure_hot_paths() oben.

// Betrag, Druckziel und maxBestellbar der Position
$notificationFestId = 0;
$activeFestResult = mysqli_query($conn, 'SELECT id FROM feste WHERE aktiv = 1 ORDER BY id DESC LIMIT 1');
if ($activeFestResult && ($activeFest = mysqli_fetch_assoc($activeFestResult))) {
    $notificationFestId = (int) $activeFest['id'];
}
$betrag = 0.0;
$print_target = 11;
$maxBestellbar = 0;
$isKassaOnly = false;
$res = mysqli_query(
    $conn,
    'SELECT p.Betrag, p.type, COALESCE(p.print_target, 11) AS print_target, COALESCE(p.maxBestellbar, 0) AS maxBestellbar, '
    . 'COALESCE(p.kassa_only, 0) AS kassa_only, COALESCE(s.kassa_only, 0) AS sub_kassa_only '
    . 'FROM positionen p LEFT JOIN position_subcategories s ON s.id = p.subcategory_id '
    . 'WHERE p.rowid=' . (int) $positionsid . ' LIMIT 1'
);
if ($res && ($row = mysqli_fetch_assoc($res))) {
    $betrag = (float)$row['Betrag'];
    $print_target = isset($row['print_target']) ? (int) $row['print_target'] : 11;
    $maxBestellbar = (int)($row['maxBestellbar'] ?? 0);
    $posType = (int)($row['type'] ?? 1);
    $isKassaOnly = ff_position_is_kassa_only($row);
    if ($tischnummer !== 999999 && $isKassaOnly) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => false,
            'error' => 'kassa_only',
            'message' => 'Diese Position ist nur an der Kasse (Direktverkauf) bestellbar.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (menu_lock_is_blocked($conn, (int)$positionsid, $posType)) {
        $info = menu_lock_get_status($conn, (int)$positionsid, $posType);
        $msg = 'Diese Position ist vorübergehend gesperrt.';
        if ($info) {
            $msg .= ' ' . ($info['reason'] !== '' ? $info['reason'] . ' — ' : '') . $info['until_label'] . '.';
        }
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'locked', 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
// Optional: ein Hinweis pro Portion (JSON-Array, Länge muss zu menge passen)
$hinweiseList = null;
if (!empty($_POST['hinweise_json'])) {
    $decoded = json_decode((string)$_POST['hinweise_json'], true);
    if (!is_array($decoded) || count($decoded) !== $menge) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'bad_hinweise_json'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $hinweiseList = $decoded;
}

$recipeItems = ff_order_recipe_components($conn, $positionsid);
$selectedRecipeIds = null;
if (array_key_exists('recipe_components_json', $_POST)) {
    $decodedRecipeSelections = json_decode((string) $_POST['recipe_components_json'], true);
    if (!is_array($decodedRecipeSelections) || count($decodedRecipeSelections) !== $menge) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'bad_recipe_components_json'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $allowedOptionalIds = [];
    foreach ($recipeItems as $component) {
        if ($component['optional_enabled']) {
            $allowedOptionalIds[(int) $component['bestandteil_id']] = true;
        }
    }
    foreach ($decodedRecipeSelections as $selection) {
        if (!is_array($selection)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'bad_recipe_components_json'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        foreach ($selection as $componentId) {
            if (!is_scalar($componentId) || !isset($allowedOptionalIds[(int) $componentId])) {
                http_response_code(400);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['ok' => false, 'error' => 'invalid_recipe_component'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
    $selectedRecipeIds = $decodedRecipeSelections;
}

$recipeSelections = [];
$hasLimitedRecipeComponent = false;
$selectedTotals = [];
for ($i = 0; $i < $menge; $i++) {
    $portionIds = $selectedRecipeIds === null ? null : $selectedRecipeIds[$i];
    $components = ff_order_recipe_selection($recipeItems, $portionIds);
    $recipeSelections[] = $components;
    foreach ($recipeItems as $component) {
        if ((int) $component['kapazitaet'] > 0) {
            $hasLimitedRecipeComponent = true;
        }
    }
    foreach ($components as $component) {
        $componentId = (int) $component['bestandteil_id'];
        $selectedTotals[$componentId] = ($selectedTotals[$componentId] ?? 0.0) + (float) $component['menge'];
    }
}

// Bon-ID aus POST (für Direktverkauf)
$bon_id = isset($_POST['bon_id']) ? trim($_POST['bon_id']) : '';
$ptSql = (int)$print_target;
$kellner = (string) $_SESSION['user']['username'];
$paymentMode = ff_aktiver_payment_mode($conn);
// Direktverkauf: „Am Ende bezahlen“ → wie bisher sofort kueche=1 (erscheint nicht in offener Küchenliste).
// „Sofort bezahlen“ → kueche=0 bis Küche/Schank fertig, dann wie gewohnt bezahlen.
$direktAlsOffenInKueche = ($tischnummer === 999999 && $paymentMode === 'instant');

$insertKuecheFertig = ($kuechefertig == "1" && !$direktAlsOffenInKueche);
$zeitKuecheExpr = ($isKassaOnly || $insertKuecheFertig) ? 'NOW()' : "'0000-00-00 00:00:00'";
$kuecheFlag = ($isKassaOnly || $insertKuecheFertig) ? 1 : 0;
$ausgeliefertFlag = $isKassaOnly ? 1 : 0;

$timestampExpr = $tischnummer === 999999 ? 'NOW()' : "'0000-00-00 00:00:00'";
$insertSql = 'INSERT INTO `bestellungen`
    (`tischnummer`,`position`,`timestampBestellung`,`zeitKueche`,`kueche`,`kellner`,`betrag`,`ausgeliefert`,`bestellt`,`print_target`,`bon_id`,`Zusatzinfo`,`fest_id`)
    VALUES (?, ?, ' . $timestampExpr . ', ' . $zeitKuecheExpr . ', ?, ?, ?, ?, 0, ?, ?, ?, ?)';
$insert = mysqli_prepare($conn, $insertSql);
if (!$insert) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_prepare_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!mysqli_begin_transaction($conn)) {
    mysqli_stmt_close($insert);
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'transaction_start_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Kapazitätsprüfung innerhalb der Transaktion mit FOR UPDATE Sperre, um Race Conditions / Überbuchungen
    // bei gleichzeitigem Abschicken mehrerer Kellner auszuschließen.
    if ($hasLimitedRecipeComponent) {
        $componentsById = [];
        foreach ($recipeItems as $component) {
            $componentsById[(int) $component['bestandteil_id']] = $component;
        }

        $lockIds = [];
        foreach ($selectedTotals as $cid => $qty) {
            $comp = $componentsById[$cid] ?? null;
            if ($comp && (int) ($comp['kapazitaet'] ?? -1) > 0) {
                $lockIds[] = (int) $cid;
            }
        }
        if (!empty($lockIds)) {
            $lockSql = 'SELECT id, kapazitaet FROM bestandteile WHERE id IN (' . implode(',', $lockIds) . ') FOR UPDATE';
            $lockRes = mysqli_query($conn, $lockSql);
            if ($lockRes) {
                while ($lRow = mysqli_fetch_assoc($lockRes)) {
                    $lId = (int) $lRow['id'];
                    if (isset($componentsById[$lId])) {
                        $componentsById[$lId]['kapazitaet'] = (int) $lRow['kapazitaet'];
                    }
                }
                mysqli_free_result($lockRes);
            }
        }

        foreach ($selectedTotals as $componentId => $quantity) {
            $component = $componentsById[$componentId] ?? null;
            $capacity = (int) ($component['kapazitaet'] ?? -1);
            if ($capacity <= 0) {
                continue;
            }
            $consumed = ff_bestandteil_consumed_total($conn, (int) $componentId);
            if ($consumed + $quantity > $capacity) {
                mysqli_rollback($conn);
                mysqli_stmt_close($insert);
                http_response_code(400);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'ok' => false,
                    'error' => 'max_cap',
                    'message' => 'Für ' . (string) ($component['name'] ?? 'einen Bestandteil') . ' reicht die verfügbare Kapazität nicht aus.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    } else {
        if ($maxBestellbar > 0) {
            $lockPos = mysqli_query($conn, 'SELECT rowid, maxBestellbar FROM positionen WHERE rowid = ' . (int) $positionsid . ' FOR UPDATE');
            if ($lockPos && ($lpRow = mysqli_fetch_assoc($lockPos))) {
                $maxBestellbar = (int) ($lpRow['maxBestellbar'] ?? $maxBestellbar);
                mysqli_free_result($lockPos);
            }
        }

        $capCheck = ff_position_check_capacity($conn, (int) $positionsid, $maxBestellbar, $menge);
        if ($capCheck !== null && empty($capCheck['ok'])) {
            mysqli_rollback($conn);
            mysqli_stmt_close($insert);
            http_response_code(400);
            header('Content-Type: application/json; charset=UTF-8');
            $payload = ['ok' => false, 'error' => (string) ($capCheck['error'] ?? 'max_cap')];
            if (!empty($capCheck['message'])) {
                $payload['message'] = $capCheck['message'];
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    for ($i = 0; $i < $menge; $i++) {
        $rowZi = $hinweiseList !== null ? trim((string) ($hinweiseList[$i] ?? '')) : $ziRaw;
        if (function_exists('mb_strlen') && mb_strlen($rowZi, 'UTF-8') > 255) {
            $rowZi = mb_substr($rowZi, 0, 255, 'UTF-8');
        } elseif (strlen($rowZi) > 255) {
            $rowZi = substr($rowZi, 0, 255);
        }
        $lineBetrag = ff_bestellung_line_betrag($conn, (int) $positionsid, $betrag, $rowZi);
        $deliveredFlag = $ausgeliefertFlag;
        $printTarget = $ptSql;
        $bonValue = $bon_id !== '' ? $bon_id : null;
        $festValue = $notificationFestId > 0 ? $notificationFestId : null;
        mysqli_stmt_bind_param(
            $insert,
            'iiisdiissi',
            $tischnummer,
            $positionsid,
            $kuecheFlag,
            $kellner,
            $lineBetrag,
            $deliveredFlag,
            $printTarget,
            $bonValue,
            $rowZi,
            $festValue
        );
        if (!mysqli_stmt_execute($insert)) {
            throw new RuntimeException('save_failed');
        }
        $orderRowId = (int) mysqli_insert_id($conn);
        ff_order_recipe_save_snapshot($conn, $orderRowId, $recipeItems, $recipeSelections[$i] ?? []);
    }
    mysqli_stmt_close($insert);
    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    mysqli_stmt_close($insert);
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage() === 'save_failed' ? 'save_failed' : 'recipe_snapshot_save_failed',
        'message' => 'Speichern fehlgeschlagen.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($notificationFestId > 0) {
    try {
        ff_event_notifications_evaluate($conn, $notificationFestId);
    } catch (Throwable $error) {
        error_log('bestellung_save.php: Push-Auswertung fehlgeschlagen nach Bestellspeicherung für Fest '
            . $notificationFestId . ': ' . $error->getMessage());
    }
}

if ($print_target > 0) {
    require_once __DIR__ . '/include/ff_station_events.php';
    ff_station_events_publish($conn, [(int) $print_target], 'draft_updated');
}

header('Content-Type: application/json; charset=UTF-8');
$openCnt = 0;
$kellnerFilterDv = '';
if ($tischnummer === 999999 && setting_get($conn, 'kellner_nur_eigene', '1') === '1') {
    $kellnerFilterDv = " AND `bestellungen`.`kellner`='" . mysqli_real_escape_string($conn, (string)($_SESSION['user']['username'] ?? '')) . "'";
}
$bonForCnt = '';
if ($tischnummer === 999999) {
    $bonForCnt = ff_direktverkauf_normalize_bon_id($bon_id);
    if ($bonForCnt === '') {
        $bonForCnt = trim($bon_id);
    }
    $openCnt = ff_menu_open_count_direkt($conn, (int) $positionsid, $kellnerFilterDv, $bonForCnt);
} elseif ($tischnummer > 0) {
    $openCnt = ff_menu_open_count_tisch($conn, $tischnummer, (int)$positionsid);
}
$positionConsumed = ff_position_consumed_total($conn, (int)$positionsid);
$displayCap = ff_position_display_capacity($conn, (int)$positionsid, $maxBestellbar, $positionConsumed);
$isLimited = !empty($displayCap['limited']);
$rest = $isLimited ? max(0, (int)($displayCap['rest'] ?? 0)) : 999999;
$needsTileReload = ($isLimited && ($rest <= 10));

$payload = [
    'ok' => true,
    'position_id' => (int)$positionsid,
    'open_cnt' => $openCnt,
    'rest' => $rest,
    'max_bestellbar' => $maxBestellbar,
    'is_limited' => $isLimited,
    // Gemeinsame Bestandteile: andere Kacheln haben ggf. veraltete Restmengen
    'stock_shared' => count(ff_position_recipe_components($conn, (int)$positionsid)) > 0,
    'needs_tile_reload' => $needsTileReload,
];
if ($tischnummer === 999999) {
    $dvPay = ff_direktverkauf_open_pay_summary($conn, $bonForCnt ?? '');
    $payload['dv_sum'] = $dvPay['sum'];
    $payload['dv_count'] = $dvPay['count'];
    $payload['dv_ids'] = $dvPay['ids'];
    $payload['dv_sum_fmt'] = $dvPay['sum_fmt'];
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);

mysqli_close($conn);
