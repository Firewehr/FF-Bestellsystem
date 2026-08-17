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
ff_schema_ensure_hot_paths($conn);

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
// Bei begrenzter Verfügbarkeit: Gäste-Bestellungen + Mitarbeiter-Verpflegung
$capCheck = ff_position_check_capacity($conn, (int) $positionsid, $maxBestellbar, $menge);
if ($capCheck !== null && empty($capCheck['ok'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    $payload = ['ok' => false, 'error' => (string) ($capCheck['error'] ?? 'max_cap')];
    if (!empty($capCheck['message'])) {
        $payload['message'] = $capCheck['message'];
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
// Optional: ein Hinweis pro Portion (JSON-Array, Länge muss zu menge passen)
$hinweiseList = null;
if (!empty($_POST['hinweise_json'])) {
    $decoded = json_decode((string)$_POST['hinweise_json'], true);
    if (is_array($decoded) && count($decoded) === $menge) {
        $hinweiseList = $decoded;
    }
}

// Bon-ID aus POST (für Direktverkauf)
$bon_id = isset($_POST['bon_id']) ? trim($_POST['bon_id']) : '';
$bon_id_sql = $bon_id !== '' ? "'" . mysqli_real_escape_string($conn, $bon_id) . "'" : 'NULL';

$ptSql = (int)$print_target;
$kellnerEsc = mysqli_real_escape_string($conn, $_SESSION['user']['username']);
$paymentMode = ff_aktiver_payment_mode($conn);
// Direktverkauf: „Am Ende bezahlen“ → wie bisher sofort kueche=1 (erscheint nicht in offener Küchenliste).
// „Sofort bezahlen“ → kueche=0 bis Küche/Schank fertig, dann wie gewohnt bezahlen.
$direktAlsOffenInKueche = ($tischnummer === 999999 && $paymentMode === 'instant');

$insertKuecheFertig = ($kuechefertig == "1" && !$direktAlsOffenInKueche);
$zeitKuecheExpr = ($isKassaOnly || $insertKuecheFertig) ? 'NOW()' : "'0000-00-00 00:00:00'";
$kuecheFlag = ($isKassaOnly || $insertKuecheFertig) ? 1 : 0;
$ausgeliefertFlag = $isKassaOnly ? 1 : 0;

$rowValues = [];
for ($i = 0; $i < $menge; $i++) {
    if ($hinweiseList !== null) {
        $rowZi = isset($hinweiseList[$i]) ? trim((string)$hinweiseList[$i]) : '';
    } else {
        $rowZi = $ziRaw;
    }
    if (function_exists('mb_strlen')) {
        if (mb_strlen($rowZi, 'UTF-8') > 255) {
            $rowZi = mb_substr($rowZi, 0, 255, 'UTF-8');
        }
    } elseif (strlen($rowZi) > 255) {
        $rowZi = substr($rowZi, 0, 255);
    }
    $ziEsc = mysqli_real_escape_string($conn, $rowZi);
    $lineBetrag = ff_bestellung_line_betrag($conn, (int)$positionsid, $betrag, $rowZi);
    $betragEsc = number_format($lineBetrag, 2, '.', '');

    $rowValues[] = '(' . (int)$tischnummer
        . ',' . (int)$positionsid
        // timestampBestellung erst beim Abschicken setzen (sonst eigene Batch-Nr. pro Klick).
        // Direktverkauf: NOW() behalten (kein Abschicken; Gruppierung läuft über bon_id).
        . ',' . ($tischnummer === 999999 ? 'NOW()' : "'0000-00-00 00:00:00'")
        . ',' . $zeitKuecheExpr
        . ',' . $kuecheFlag
        . ",'" . $kellnerEsc . "'"
        . ',' . $betragEsc
        . ',' . $ausgeliefertFlag
        . ',0'
        . ',' . $ptSql
        . ',' . $bon_id_sql
        . ",'" . $ziEsc . "'"
        . ')';
}

if ($rowValues !== []) {
    $sql = 'INSERT INTO `bestellungen` (`tischnummer`,`position`,`timestampBestellung`,`zeitKueche`,`kueche`,`kellner`,`betrag`,`ausgeliefert`,`bestellt`,`print_target`,`bon_id`,`Zusatzinfo`) VALUES '
        . implode(',', $rowValues);
    if (!mysqli_query($conn, $sql)) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'save_failed',
            'message' => 'Speichern fehlgeschlagen.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
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
$stock = ff_menu_stock_state($conn, (int)$positionsid, $maxBestellbar, $kellnerFilterDv);
$rest = (int)$stock['rest'];
$needsTileReload = ($maxBestellbar > 0 && ($rest <= 10));

$payload = [
    'ok' => true,
    'position_id' => (int)$positionsid,
    'open_cnt' => $openCnt,
    'rest' => $rest,
    'max_bestellbar' => $maxBestellbar,
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
