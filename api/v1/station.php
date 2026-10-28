<?php
declare(strict_types=1);

/**
 * Universal Station API (Küche, Schank und beliebige Druckziele).
 * 
 * Bietet GET für aktive Bestellungen, Summen, Historie und Druckziel-Listen
 * sowie POST für Fertig-Markieren, Undo, Abschließen, Teillieferung und Wiedereröffnen.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/bestellung_batch_key_sql.php';
require_once dirname(__DIR__, 2) . '/include/ff_kueche_list_data.php';
require_once dirname(__DIR__, 2) . '/include/ff_position_kassa_helpers.php';
require_once dirname(__DIR__, 2) . '/include/ff_rezepturen_schema.php';
require_once dirname(__DIR__, 2) . '/include/ff_station_events.php';
require_once dirname(__DIR__, 2) . '/include/ff_auto_print.php';
require_once dirname(__DIR__, 2) . '/include/ff_event_notifications.php';
require_once dirname(__DIR__, 2) . '/include/settings.php';
require_once dirname(__DIR__, 2) . '/include/user_landing.php';

$user = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = api_get_request_data();

ff_rezepturen_ensure_schema($conn);
ff_position_kassa_ensure_schema($conn);
@mysqli_query(
    $conn,
    "CREATE TABLE IF NOT EXISTS print_targets (
        print_target INT(11) NOT NULL,
        name VARCHAR(64) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (print_target)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// -----------------------------------------------------------------------------
// POST: Aktionen
// -----------------------------------------------------------------------------
if ($method === 'POST') {
    api_require_csrf($data);

    $action = (string) ($data['action'] ?? '');
    $rawIds = (array) ($data['rowids'] ?? $data['listePositionen'] ?? []);
    if (isset($data['rowid']) && (int) $data['rowid'] > 0) {
        $rawIds[] = (int) $data['rowid'];
    }

    $rowids = [];
    foreach ($rawIds as $id) {
        $n = (int) $id;
        if ($n > 0) {
            $rowids[$n] = $n;
        }
    }
    $rowids = array_values($rowids);

    // 1. Position(en) oder Batch als FERTIG markieren
    if ($action === 'item_done' || $action === 'batch_done') {
        if ($rowids === []) {
            api_error('Keine gültigen Positionen angegeben.', 'missing_rowids', 400);
        }
        $in = implode(',', $rowids);
        $sql = "UPDATE bestellungen SET zeitKueche = CURRENT_TIMESTAMP, kueche = 1, print = 0 WHERE rowid IN ($in) AND `delete` = 0 AND ausgeliefert = 0";
        if (!mysqli_query($conn, $sql)) {
            api_error('Fehler beim Aktualisieren: ' . mysqli_error($conn), 'db_error', 500);
        }

        ff_event_notifications_evaluate_rows($conn, $rowids, 'api_station_item_done');

        // Druckziele für Event-Publishing ermitteln
        $targets = ff_station_get_print_targets_for_rows($conn, $rowids);
        if ($targets !== []) {
            ff_station_events_publish($conn, $targets, 'station_change');
        }

        api_response([
            'ok' => true,
            'action' => $action,
            'affected' => count($rowids),
            'csrf_token' => ff_csrf_token(),
            'message' => count($rowids) . ' Position(en) als fertig markiert.',
        ]);
    }

    // 2. Position(en) wieder als OFFEN markieren (Undo)
    if ($action === 'undo_done') {
        if ($rowids === []) {
            api_error('Keine gültigen Positionen angegeben.', 'missing_rowids', 400);
        }
        $in = implode(',', $rowids);

        $printStatusSql = '';
        $chk = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'print_status'");
        if ($chk && mysqli_num_rows($chk) > 0) {
            $printStatusSql = ', print_status = 0';
        }

        $sql = "UPDATE bestellungen SET zeitKueche = '0000-00-00 00:00:00', kueche = 0, print = 0" . $printStatusSql
            . " WHERE `delete` = 0 AND rowid IN ($in)";
        if (!mysqli_query($conn, $sql)) {
            api_error('Fehler beim Zurücksetzen: ' . mysqli_error($conn), 'db_error', 500);
        }

        ff_event_notifications_evaluate_rows($conn, $rowids, 'api_station_undo_done');

        $targets = ff_station_get_print_targets_for_rows($conn, $rowids);
        if ($targets !== []) {
            ff_station_events_publish($conn, $targets, 'station_change');
        }

        api_response([
            'ok' => true,
            'action' => 'undo_done',
            'affected' => count($rowids),
            'csrf_token' => ff_csrf_token(),
            'message' => count($rowids) . ' Position(en) wieder als offen markiert.',
        ]);
    }

    // 3. Bestellung abschließen (mit oder ohne Druck)
    if ($action === 'complete_order') {
        if ($rowids === []) {
            api_error('Keine Positionen zum Abschließen angegeben.', 'missing_rowids', 400);
        }
        $mitDruck = !empty($data['mit_druck']);
        $tischnummer = (int) ($data['tischnummer'] ?? 0);
        $in = implode(',', $rowids);

        // Offene Positionen (Ein-Klick) ebenfalls als fertig markieren
        @mysqli_query(
            $conn,
            "UPDATE bestellungen SET kueche = 1, zeitKueche = IF(zeitKueche IS NULL OR zeitKueche IN ('0000-00-00 00:00:00','1970-01-01 00:00:00'), CURRENT_TIMESTAMP, zeitKueche) WHERE rowid IN ($in)"
        );

        $printed = 0;
        $printedRowIds = [];
        $timestamp = date('Y-m-d H:i:s');

        if ($mitDruck) {
            foreach ($rowids as $rowid) {
                // Bereits als Teillieferung gedruckt?
                $chk = mysqli_query($conn, 'SELECT COALESCE(print_status, 0) AS ps FROM bestellungen WHERE rowid = ' . $rowid . ' LIMIT 1');
                $alreadyPrinted = false;
                if ($chk && ($cr = mysqli_fetch_assoc($chk))) {
                    $alreadyPrinted = ((int) ($cr['ps'] ?? 0) === 1);
                }

                if ($alreadyPrinted) {
                    $sql = "UPDATE bestellungen SET ausgeliefert = 1, timestampAuslieferung = CURRENT_TIMESTAMP, bestellt = 1, kueche = 1 WHERE rowid = " . $rowid;
                    mysqli_query($conn, $sql);
                } else {
                    $sql = "UPDATE bestellungen SET ausgeliefert = 1, timestampAuslieferung = CURRENT_TIMESTAMP, bestellt = 1, kueche = 1, print = 2, print_status = 0 WHERE rowid = " . $rowid;
                    mysqli_query($conn, $sql);
                    @mysqli_query($conn, "INSERT INTO print (bestellungID, timestamp) VALUES (" . $rowid . ",'" . mysqli_real_escape_string($conn, $timestamp) . "')");
                    $printed++;
                    $printedRowIds[] = $rowid;
                }
            }

            if ($printedRowIds !== []) {
                $printTargets = ff_station_get_print_targets_for_rows($conn, $printedRowIds);
                ff_print_events_publish($conn, $printTargets);
            }
        } else {
            $sql = "UPDATE bestellungen SET ausgeliefert = 1, timestampAuslieferung = CURRENT_TIMESTAMP, bestellt = 1, kueche = 1 WHERE rowid IN ($in)";
            mysqli_query($conn, $sql);
        }

        ff_event_notifications_evaluate_rows($conn, $rowids, 'api_station_complete_order');

        $affectedTargets = ff_station_get_print_targets_for_rows($conn, $rowids);
        if ($affectedTargets !== []) {
            ff_station_events_publish($conn, $affectedTargets, 'station_change');
            if ($mitDruck) {
                ff_auto_print_refill($conn, $affectedTargets);
            }
        }

        api_response([
            'ok' => true,
            'action' => 'complete_order',
            'closed' => count($rowids),
            'mit_druck' => $mitDruck,
            'queued_print' => $printed,
            'csrf_token' => ff_csrf_token(),
            'message' => 'Bestellung abgeschlossen' . ($mitDruck ? ' und Bon gedruckt.' : '.'),
        ]);
    }

    // 4. Teillieferung drucken
    if ($action === 'print_partial') {
        if ($rowids === []) {
            api_error('Keine Positionen für Teillieferungs-Druck angegeben.', 'missing_rowids', 400);
        }
        $tischnummer = (int) ($data['tischnummer'] ?? 0);
        $printTarget = (int) ($data['print_target'] ?? 0);
        $in = implode(',', $rowids);

        $sql = "UPDATE bestellungen SET print = 2, print_status = 0, kueche = 1 "
            . "WHERE rowid IN ($in) AND `delete` = 0 AND ausgeliefert = 0 AND COALESCE(print_status, 0) = 0 "
            . "AND (kueche = 1 OR (zeitKueche IS NOT NULL AND zeitKueche NOT IN ('0000-00-00 00:00:00', '1970-01-01 00:00:00')))";

        if (!mysqli_query($conn, $sql)) {
            api_error('Fehler bei Teillieferung: ' . mysqli_error($conn), 'db_error', 500);
        }
        $affected = (int) mysqli_affected_rows($conn);

        if ($affected <= 0) {
            api_error('Keine fertigen, noch ungedruckten Positionen gefunden.', 'nothing_to_print', 400);
        }

        ff_event_notifications_evaluate_rows($conn, $rowids, 'api_station_teillieferung_druck');

        $targets = ff_station_get_print_targets_for_rows($conn, $rowids);
        if ($targets !== []) {
            ff_print_events_publish($conn, $targets);
            ff_station_events_publish($conn, $targets, 'station_change');
        }

        api_response([
            'ok' => true,
            'action' => 'print_partial',
            'printed_count' => $affected,
            'csrf_token' => ff_csrf_token(),
            'message' => $affected . ' Position(en) als Teillieferung gedruckt.',
        ]);
    }

    // 5. Force Bestellung (bestellt = 1 setzen)
    if ($action === 'force_order') {
        $tischnummer = (int) ($data['tischnummer'] ?? 0);
        if ($tischnummer <= 0 && $rowids === []) {
            api_error('Tischnummer oder Positionen erforderlich.', 'missing_params', 400);
        }

        if ($rowids !== []) {
            $in = implode(',', $rowids);
            mysqli_query($conn, "UPDATE bestellungen SET bestellt = 1 WHERE rowid IN ($in) AND `delete` = 0");
        } elseif ($tischnummer > 0) {
            mysqli_query($conn, "UPDATE bestellungen SET bestellt = 1 WHERE tischnummer = " . $tischnummer . " AND `delete` = 0 AND ausgeliefert = 0");
        }

        $targets = $rowids !== [] ? ff_station_get_print_targets_for_rows($conn, $rowids) : [11, 12];
        ff_station_events_publish($conn, $targets, 'station_change');

        api_response([
            'ok' => true,
            'action' => 'force_order',
            'csrf_token' => ff_csrf_token(),
            'message' => 'Bestellung forciert.',
        ]);
    }

    // 6. Abgeschlossene Bestellung reaktivieren (Undo Complete aus Historie)
    if ($action === 'reopen_order') {
        if ($rowids === []) {
            api_error('Keine Positionen zum Wiedereröffnen angegeben.', 'missing_rowids', 400);
        }
        $in = implode(',', $rowids);
        $sql = "UPDATE bestellungen SET ausgeliefert = 0, timestampAuslieferung = '0000-00-00 00:00:00', kueche = 1, zeitKueche = CURRENT_TIMESTAMP WHERE rowid IN ($in) AND `delete` = 0";
        if (!mysqli_query($conn, $sql)) {
            api_error('Fehler beim Wiedereröffnen: ' . mysqli_error($conn), 'db_error', 500);
        }

        $targets = ff_station_get_print_targets_for_rows($conn, $rowids);
        if ($targets !== []) {
            ff_station_events_publish($conn, $targets, 'station_change');
        }

        api_response([
            'ok' => true,
            'action' => 'reopen_order',
            'affected' => count($rowids),
            'csrf_token' => ff_csrf_token(),
            'message' => count($rowids) . ' Position(en) wieder auf die Station gesetzt.',
        ]);
    }

    api_error('Ungültige Aktion: ' . $action, 'invalid_action', 400);
}

// -----------------------------------------------------------------------------
// GET: Daten abrufen
// -----------------------------------------------------------------------------
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$view = (string) ($data['view'] ?? 'active');
$targetParam = $data['target'] ?? $data['print_target'] ?? 0;
$targetId = ($targetParam === 'all' || $targetParam === '') ? 0 : (int) $targetParam;
$posType = (int) ($data['type'] ?? $data['pos_type'] ?? 0);

// Fest / Zahlungsmodus laden
$paymentMode = 'after';
$fres = @mysqli_query($conn, 'SELECT payment_mode FROM feste WHERE aktiv = 1 LIMIT 1');
if ($fres && ($frow = mysqli_fetch_assoc($fres))) {
    $paymentMode = $frow['payment_mode'] ?: 'after';
}

// ---------------------------------------------------------
// VIEW: HISTORIE (Zuletzt ausgeliefert)
// ---------------------------------------------------------
if ($view === 'history') {
    $batchKeySql = ff_sql_bestellung_batch_key('b');
    $where = 'b.`delete` = 0 AND b.ausgeliefert = 1 ';
    if ($targetId > 0) {
        $where .= 'AND (COALESCE(b.print_target, p.print_target) = ' . $targetId . ') ';
    }
    if ($posType > 0) {
        $where .= 'AND p.type = ' . $posType . ' ';
    }

    $sql = "SELECT b.rowid, b.tischnummer, b.bestellt, b.kellner, b.zeitstempel, b.zeitKueche, b.timestampAuslieferung, "
        . "b.Zusatzinfo, b.kueche, b.ausgeliefert, b.order_nr, b.bon_id, b.position, "
        . "p.Positionsname, p.type AS pos_type, COALESCE(b.print_target, p.print_target, 11) AS print_target, "
        . "{$batchKeySql} AS batch_key, "
        . "COALESCE(t.tischname, IF(b.tischnummer = 999999, 'Direktverkauf', '')) AS tischname "
        . "FROM bestellungen b "
        . "JOIN positionen p ON b.position = p.rowid "
        . "LEFT JOIN tische t ON t.tischnummer = b.tischnummer "
        . "WHERE " . $where
        . "ORDER BY b.timestampAuslieferung DESC, b.zeitstempel DESC LIMIT 120";

    $res = mysqli_query($conn, $sql);
    $historyOrders = [];
    if ($res) {
        $grouped = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $bk = (string) ($row['batch_key'] ?? '');
            $tn = (int) ($row['tischnummer'] ?? 0);
            $key = $tn . '_' . $bk;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'batch_key' => $bk,
                    'tischnummer' => $tn,
                    'tischname' => (string) ($row['tischname'] ?? ''),
                    'is_direktverkauf' => ($tn === 999999),
                    'bon_id' => trim((string) ($row['bon_id'] ?? '')),
                    'kellner' => (string) ($row['kellner'] ?? ''),
                    'order_nr' => (int) ($row['order_nr'] ?? 0),
                    'timestamp_auslieferung' => (string) ($row['timestampAuslieferung'] ?? ''),
                    'timestamp_bestellung' => (string) ($row['zeitstempel'] ?? ''),
                    'items' => [],
                    'rowids' => [],
                ];
            }
            $grouped[$key]['rowids'][] = (int) $row['rowid'];
            $pname = (string) ($row['Positionsname'] ?? '');
            $zi = trim((string) ($row['Zusatzinfo'] ?? ''));
            $itemKey = $pname . '||' . $zi;
            if (!isset($grouped[$key]['items'][$itemKey])) {
                $grouped[$key]['items'][$itemKey] = [
                    'name' => $pname,
                    'zusatzinfo' => $zi,
                    'anzahl' => 0,
                    'pos_type' => (int) ($row['pos_type'] ?? 1),
                ];
            }
            $grouped[$key]['items'][$itemKey]['anzahl']++;
        }
        foreach ($grouped as $g) {
            $g['items'] = array_values($g['items']);
            $historyOrders[] = $g;
        }
    }

    api_response([
        'ok' => true,
        'view' => 'history',
        'target' => $targetId,
        'orders' => $historyOrders,
        'csrf_token' => ff_csrf_token(),
        'total' => count($historyOrders),
    ]);
}

// ---------------------------------------------------------
// VIEW: ACTIVE (Aktive Stationsbestellungen)
// ---------------------------------------------------------

// 1. Druckziele & Wartestatistiken ermitteln
$targets = [
    [
        'id' => 0,
        'name' => 'Alle Stationen',
        'count' => 0,
        'active' => 1,
    ]
];

$allTargetsRes = mysqli_query($conn, 'SELECT print_target, name, active, sort_order FROM print_targets WHERE active = 1 ORDER BY sort_order ASC');
$totalWaitingAll = 0;
if ($allTargetsRes) {
    while ($ptRow = mysqli_fetch_assoc($allTargetsRes)) {
        $ptId = (int) $ptRow['print_target'];
        $ptName = (string) $ptRow['name'];
        $waitingCount = ff_druckziel_waiting_count($conn, $ptId);
        $totalWaitingAll += $waitingCount;
        $targets[] = [
            'id' => $ptId,
            'name' => $ptName,
            'count' => $waitingCount,
            'active' => (int) $ptRow['active'],
        ];
    }
}
$targets[0]['count'] = $totalWaitingAll;

// 2. Aktive Zeilen laden
$batchKeySql = ff_sql_bestellung_batch_key('b');
$whereScope = 'b.`delete` = 0 AND b.ausgeliefert = 0 ';
if ($targetId > 0) {
    $whereScope .= 'AND (COALESCE(b.print_target, p.print_target) = ' . $targetId . ') ';
}
if ($posType > 0) {
    $whereScope .= 'AND p.type = ' . $posType . ' ';
}

$sql = "SELECT b.rowid, b.tischnummer, b.bestellt, b.kellner, b.zeitstempel, b.zeitKueche, "
    . "CONCAT_WS(', ', NULLIF(b.Zusatzinfo, ''), NULLIF((SELECT GROUP_CONCAT("
    . "CASE WHEN rc.selected = 1 THEN CONCAT('extra ', rc.name_snapshot) ELSE CONCAT('ohne ', rc.name_snapshot) END "
    . "ORDER BY rc.bestandteil_id SEPARATOR ', ') "
    . "FROM bestellung_rezeptur_komponenten rc "
    . "WHERE rc.bestellung_rowid = b.rowid AND rc.optional_enabled = 1 "
    . "AND rc.selected <> rc.default_selected), '')) AS Zusatzinfo, "
    . "b.kueche, b.ausgeliefert, b.print, b.print_status, b.order_nr, b.bon_id, b.position, b.timestampBezahlung, "
    . "p.Positionsname, p.type AS pos_type, "
    . "COALESCE(b.print_target, p.print_target, 11) AS print_target, "
    . "COALESCE(pt.name, CONCAT('Station ', COALESCE(b.print_target, p.print_target, 11))) AS print_target_name, "
    . "{$batchKeySql} AS batch_key, "
    . "COALESCE(t.tischname, IF(b.tischnummer = 999999, 'Direktverkauf', '')) AS tischname "
    . "FROM bestellungen b "
    . "JOIN positionen p ON b.position = p.rowid "
    . "LEFT JOIN print_targets pt ON pt.print_target = COALESCE(b.print_target, p.print_target, 11) "
    . "LEFT JOIN position_subcategories s ON s.id = p.subcategory_id "
    . "LEFT JOIN tische t ON t.tischnummer = b.tischnummer "
    . "WHERE " . $whereScope
    . ff_position_sql_station_visible('p', 's')
    . "ORDER BY b.zeitstempel ASC";

$res = mysqli_query($conn, $sql);
$lines = [];
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $lines[] = $r;
    }
}

// 3. Batches und derived Daten zusammenbauen
$batches = ff_station_group_batches($lines, 'all');

$now = time();
$orders = [];
$summaryMap = [];
$waitingItemsCount = 0;

foreach ($batches as $batch) {
    $batchLines = $batch['lines'] ?? [];
    $tn = (int) ($batch['tischnummer'] ?? 0);
    $isDv = ($tn === 999999);
    $batchKey = (string) ($batch['batch_key'] ?? '');
    $tischname = (string) ($batch['tischname'] ?? '');

    // Direktverkauf Bon-Nr.
    $bonId = '';
    if ($isDv) {
        foreach ($batchLines as $bl) {
            $bId = trim((string) ($bl['bon_id'] ?? ''));
            if ($bId !== '') {
                $bonId = $bId;
                break;
            }
        }
        if ($bonId !== '') {
            $tischname = 'Bon #' . $bonId;
        } else {
            $tischname = 'Direktverkauf';
        }
    }

    // Tisch-Zone (Z = Zelt, H = Halle, T = Terrasse, etc.)
    $zone = '';
    if (!$isDv && preg_match('/^([A-Za-z0-9]+)[-_]/', $tischname, $zm)) {
        $zone = strtoupper($zm[1]);
    }

    $openItems = [];
    $doneItems = [];
    $allRowids = [];
    $openRowids = [];
    $doneRowids = [];
    $printRowids = [];
    $earliestTime = null;
    $isPaidDv = false;
    $hasUnpaidDv = false;
    $orderNrs = [];

    // Derivierung
    foreach ($batchLines as $ln) {
        $rid = (int) $ln['rowid'];
        $allRowids[] = $rid;
        $on = (int) ($ln['order_nr'] ?? 0);
        if ($on > 0) {
            $orderNrs[$on] = $on;
        }

        $pname = (string) ($ln['Positionsname'] ?? '');
        $zi = trim((string) ($ln['Zusatzinfo'] ?? ''));
        $posTypeItem = (int) ($ln['pos_type'] ?? 1);
        $ptItem = (int) ($ln['print_target'] ?? 11);
        $ptNameItem = (string) ($ln['print_target_name'] ?? '');
        $ts = !empty($ln['zeitstempel']) ? strtotime((string) $ln['zeitstempel']) : 0;
        if ($ts > 0 && ($earliestTime === null || $ts < $earliestTime)) {
            $earliestTime = $ts;
        }

        if ($isDv) {
            $tb = trim((string) ($ln['timestampBezahlung'] ?? ''));
            $isPaid = ($tb !== '' && $tb !== '0000-00-00 00:00:00' && $tb !== '1970-01-01 00:00:00');
            if ($isPaid) {
                $isPaidDv = true;
            } else {
                $hasUnpaidDv = true;
            }
        }

        // Offen vs. Fertig: Ein Item gilt an der Station als offen, wenn zeitKueche nicht gesetzt ist
        $zk = trim((string) ($ln['zeitKueche'] ?? ''));
        $isOpen = ($zk === '' || $zk === '0000-00-00 00:00:00' || $zk === '1970-01-01 00:00:00');

        if ($isOpen) {
            $waitingItemsCount++;
            $openRowids[] = $rid;
            $aggKey = $pname . '||' . $zi . '||' . $ptItem;
            if (!isset($openItems[$aggKey])) {
                $openItems[$aggKey] = [
                    'rowid' => $rid,
                    'all_rowids' => [],
                    'anzahl' => 0,
                    'positionsname' => $pname,
                    'zusatzinfo' => $zi,
                    'pos_type' => $posTypeItem,
                    'print_target' => $ptItem,
                    'print_target_name' => $ptNameItem,
                    'bestellt' => (int) ($ln['bestellt'] ?? 0),
                ];
            }
            $openItems[$aggKey]['anzahl']++;
            $openItems[$aggKey]['all_rowids'][] = $rid;

            // Für kumulierte Vorbereitungs-Übersicht (Summary)
            $sumKey = mb_strtolower($pname, 'UTF-8');
            if (!isset($summaryMap[$sumKey])) {
                $summaryMap[$sumKey] = [
                    'name' => $pname,
                    'pos_key' => $sumKey,
                    'anzahl' => 0,
                    'tables' => [],
                    'extras' => [],
                    'pos_type' => $posTypeItem,
                    'print_target' => $ptItem,
                ];
            }
            $summaryMap[$sumKey]['anzahl']++;
            if (!in_array($tischname, $summaryMap[$sumKey]['tables'], true)) {
                $summaryMap[$sumKey]['tables'][] = $tischname;
            }
            if ($zi !== '' && !in_array($zi, $summaryMap[$sumKey]['extras'], true)) {
                $summaryMap[$sumKey]['extras'][] = $zi;
            }
        } else {
            // Fertig
            $doneRowids[] = $rid;
            if ((int) ($ln['print'] ?? 0) === 2 && (int) ($ln['print_status'] ?? 0) === 0) {
                $printRowids[] = $rid;
            }
            $aggKey = $pname . '||' . $zi . '||' . $ptItem;
            if (!isset($doneItems[$aggKey])) {
                $doneItems[$aggKey] = [
                    'rowid' => $rid,
                    'undo_rowids' => [],
                    'anzahl' => 0,
                    'positionsname' => $pname,
                    'zusatzinfo' => $zi,
                    'pos_type' => $posTypeItem,
                    'print_target' => $ptItem,
                    'print_target_name' => $ptNameItem,
                    'zeitKueche' => $zk,
                ];
            }
            $doneItems[$aggKey]['anzahl']++;
            $doneItems[$aggKey]['undo_rowids'][] = $rid;
        }
    }

    $ageSec = $earliestTime !== null ? max(0, $now - $earliestTime) : 0;
    $waitStatus = 'ok';
    if ($ageSec >= 1200) {
        $waitStatus = 'late';
    } elseif ($ageSec >= 600) {
        $waitStatus = 'warn';
    }

    $orders[] = [
        'batch_key' => $batchKey,
        'tischnummer' => $tn,
        'tischname' => $tischname,
        'is_direktverkauf' => $isDv,
        'bon_id' => $bonId,
        'kellner' => ff_user_display_label($conn, (string) ($batch['kellner'] ?? '')),
        'zone' => $zone,
        'order_nrs' => array_values($orderNrs),
        'bestellt' => (int) ($batch['bestellt'] ?? 0),
        'is_paid' => $isDv ? ($isPaidDv && !$hasUnpaidDv) : true,
        'timestamp' => $earliestTime ? date('Y-m-d H:i:s', $earliestTime) : '',
        'age_sec' => $ageSec,
        'wait_status' => $waitStatus,
        'open_count' => count($openRowids),
        'done_count' => count($doneRowids),
        'print_status' => (count($printRowids) > 0 ? 1 : 0),
        'print_rowids' => array_values(array_unique($printRowids)),
        'items_open' => array_values($openItems),
        'items_done' => array_values($doneItems),
        'all_rowids' => $allRowids,
        'open_rowids' => $openRowids,
        'done_rowids' => $doneRowids,
    ];
}

// Summary sortieren (meistbestellte zuerst)
$summaryList = array_values($summaryMap);
usort($summaryList, static fn($a, $b) => $b['anzahl'] <=> $a['anzahl']);

// Auslastungsstatus
$loadLevel = 'normal';
if ($waitingItemsCount > 80) {
    $loadLevel = 'overload';
} elseif ($waitingItemsCount > 30) {
    $loadLevel = 'busy';
}

$teillieferungEnabled = setting_get($conn, 'station_teillieferung_druck', '0') === '1';
$oneClickEnabled = setting_get($conn, 'station_one_click_abschliessen', '0') === '1';

// Revision Fingerprint für schnelles Polling / SSE-Validierung
$revisionToken = md5($waitingItemsCount . ':' . count($orders) . ':' . ($orders[0]['batch_key'] ?? '') . ':' . ($orders[0]['age_sec'] ?? ''));

api_response([
    'ok' => true,
    'view' => 'active',
    'stats' => [
        'waiting_items' => $waitingItemsCount,
        'total_orders' => count($orders),
        'load_level' => $loadLevel,
        'payment_mode' => $paymentMode,
        'teillieferung_enabled' => $teillieferungEnabled,
        'one_click_abschliessen' => $oneClickEnabled,
        'server_time' => date('Y-m-d H:i:s'),
    ],
    'targets' => $targets,
    'selected_target' => $targetId,
    'selected_type' => $posType,
    'orders' => $orders,
    'summary' => $summaryList,
    'revision' => $revisionToken,
    'csrf_token' => ff_csrf_token(),
]);

/**
 * Hilfsfunktion: Ermittelt die betroffenen print_targets für eine Liste von Bestellungs-Rowids.
 *
 * @param mysqli $conn
 * @param list<int> $rowids
 * @return list<int>
 */
function ff_station_get_print_targets_for_rows(mysqli $conn, array $rowids): array
{
    if ($rowids === []) {
        return [];
    }
    $in = implode(',', array_map('intval', $rowids));
    $res = mysqli_query(
        $conn,
        "SELECT DISTINCT COALESCE(b.print_target, p.print_target, 11) AS pt FROM bestellungen b "
        . "JOIN positionen p ON p.rowid = b.position WHERE b.rowid IN ($in)"
    );
    $targets = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $targets[] = (int) $r['pt'];
        }
    }
    return $targets;
}
