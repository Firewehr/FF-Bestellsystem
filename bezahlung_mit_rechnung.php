<?php
/**
 * Bezahlt ausgewählte Zeilen und legt sofort eine echte Rechnung an.
 * Rechnungsnummer immer aus dem laufenden Kreis (Präfix optional pro Fest bzw. global,
 * Kalenderjahr, Zähler settings rechnung_next) — nie gleich der Bestellnummer.
 * Nachdruck: Admin → Rechnungen (PDF/Thermo).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_rechnung_items.php';
require_once __DIR__ . '/include/ff_rechnung_seq.php';

function br_json_out(array $a, int $code = 200): void {
    if ($code !== 200) {
        http_response_code($code);
    }
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = $_POST['listePositionen'] ?? null;
if (!is_array($raw)) {
    br_json_out(['ok' => false, 'error' => 'listePositionen_fehlt'], 400);
}

$ids = [];
foreach ($raw as $row) {
    $n = (int)$row;
    if ($n > 0) {
        $ids[$n] = true;
    }
}
$ids = array_keys($ids);
if ($ids === []) {
    br_json_out(['ok' => false, 'error' => 'keine_gueltigen_ids'], 400);
}

$tischnummer = isset($_POST['tischnummer']) ? (int)$_POST['tischnummer'] : 0;
if ($tischnummer <= 0) {
    br_json_out(['ok' => false, 'error' => 'tischnummer_fehlt'], 400);
}

$thermoRaw = isset($_POST['thermo_ziel']) ? trim((string)$_POST['thermo_ziel']) : '0';
$sessionPt = isset($_POST['session_print_target']) ? (int)$_POST['session_print_target'] : 0;
$openPdf = isset($_POST['open_pdf']) && (string)$_POST['open_pdf'] === '1';
$paymentMode = isset($_POST['payment_mode']) && (string)$_POST['payment_mode'] === 'instant' ? 'instant' : 'after';
$kuecheFilterOpen = ($paymentMode === 'after') ? ' AND b.kueche=1 ' : '';

mysqli_set_charset($conn, 'utf8mb4');

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS rechnungen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rechnungsnummer VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(64) NOT NULL,
    fest_id INT NULL,
    tischnummer INT NULL,
    sammelrechnung_id INT NULL,
    order_nr INT NULL,
    is_firma TINYINT(1) NOT NULL DEFAULT 0,
    empfaenger_name VARCHAR(255) NULL,
    empfaenger_strasse VARCHAR(255) NULL,
    empfaenger_plz VARCHAR(30) NULL,
    empfaenger_ort VARCHAR(80) NULL,
    empfaenger_uid VARCHAR(40) NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    gedruckt TINYINT(1) NOT NULL DEFAULT 0,
    druck_status VARCHAR(10) NOT NULL DEFAULT 'pending',
    druck_attempts INT NOT NULL DEFAULT 0,
    druck_last_error VARCHAR(255) NULL,
    reserved_at TIMESTAMP NULL,
    reserved_by VARCHAR(64) NULL,
    is_proforma TINYINT(1) NOT NULL DEFAULT 0,
    lines_json MEDIUMTEXT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8");
require_once __DIR__ . '/include/ff_rechnungen_ensure_columns.php';
ff_rechnungen_ensure_extra_columns($conn);
ff_bestellungen_ensure_rechnung_id_column($conn);

$validTargets = [];
$ptq = @mysqli_query($conn, 'SELECT print_target FROM print_targets WHERE active=1');
if ($ptq) {
    while ($pt = mysqli_fetch_assoc($ptq)) {
        $validTargets[(int)$pt['print_target']] = true;
    }
}
$validTargets[11] = true;
$validTargets[12] = true;

$thermoTarget = 0;
if ($thermoRaw === 'session') {
    $thermoTarget = $sessionPt > 0 ? $sessionPt : 11;
} else {
    $thermoTarget = (int)$thermoRaw;
}
if ($thermoTarget > 0 && !isset($validTargets[$thermoTarget])) {
    br_json_out(['ok' => false, 'error' => 'Ungueltiges Thermo-Druckziel'], 400);
}

$kellner = isset($_SESSION['user']['username']) ? (string)$_SESSION['user']['username'] : '';
$kellnerEsc = mysqli_real_escape_string($conn, $kellner);
session_write_close();

$in = implode(',', array_map('intval', $ids));

$fest_id = null;
$resFest = @mysqli_query($conn, 'SELECT id FROM feste WHERE aktiv=1 LIMIT 1');
if ($resFest && ($rf = mysqli_fetch_assoc($resFest))) {
    $fest_id = (int)$rf['id'] ?: null;
}

mysqli_begin_transaction($conn);

$chkSql = "SELECT rowid, tischnummer, order_nr, rechnung_id,
           (timestampBezahlung IS NULL OR timestampBezahlung='0000-00-00 00:00:00') AS noch_offen
           FROM bestellungen WHERE `delete`=0 AND rowid IN ($in)";
$chkRes = mysqli_query($conn, $chkSql);
if (!$chkRes) {
    mysqli_rollback($conn);
    br_json_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
}

$rows = [];
$orderNrs = [];
$tischMismatch = false;
while ($r = mysqli_fetch_assoc($chkRes)) {
    if ((int)$r['tischnummer'] !== $tischnummer) {
        $tischMismatch = true;
    }
    $onr = (int)($r['order_nr'] ?? 0);
    if ($onr > 0) {
        $orderNrs[$onr] = true;
    }
    $rows[] = $r;
}

if (count($rows) !== count($ids)) {
    mysqli_rollback($conn);
    br_json_out(['ok' => false, 'error' => 'Einige Positionen nicht gefunden oder ungueltig'], 400);
}
if ($tischMismatch) {
    mysqli_rollback($conn);
    br_json_out(['ok' => false, 'error' => 'Tischnummer passt nicht zu den Positionen'], 400);
}

$needPay = false;
foreach ($rows as $r) {
    if ((int)$r['noch_offen'] === 1) {
        $needPay = true;
    }
}

foreach ($rows as $r) {
    $exR = (int)($r['rechnung_id'] ?? 0);
    if ($exR > 0) {
        $rq = mysqli_query($conn, 'SELECT IFNULL(is_proforma,0) AS ip FROM rechnungen WHERE id=' . $exR . ' LIMIT 1');
        $ip = 0;
        if ($rq && ($rr = mysqli_fetch_assoc($rq))) {
            $ip = (int)$rr['ip'];
        }
        mysqli_rollback($conn);
        if ($ip === 1) {
            br_json_out(['ok' => false, 'error' => 'Diese Positionen sind noch mit einer Proforma-Rechnung verknüpft. Bitte normale Bezahlung nutzen oder im Admin die Proforma-Rechnung entfernen.'], 409);
        }
        br_json_out(['ok' => false, 'error' => 'Mindestens eine Position ist bereits einer Rechnung zugeordnet.'], 409);
    }
}

// Mehrere Bestellnummern auf eine Rechnung sind erlaubt; order_nr in rechnungen nur bei einer Bestellnummer gesetzt.
$multipleOrders = (count($orderNrs) > 1);

if (count($orderNrs) === 0) {
    mysqli_rollback($conn);
    br_json_out(['ok' => false, 'error' => 'Keine Bestellnummer (order_nr). Tisch erst „Abschicken“, dann bezahlen.'], 400);
}

$orderNr = $multipleOrders ? 0 : (int)array_key_first($orderNrs);

$selSorted = $ids;
sort($selSorted, SORT_NUMERIC);

$expectedOpen = [];

if ($needPay) {
    $orderInList = implode(',', array_map('intval', array_keys($orderNrs)));
    $expSql = 'SELECT b.rowid FROM bestellungen b WHERE b.delete=0 AND b.bestellt=1 AND b.tischnummer=' . $tischnummer
        . ' AND b.order_nr IN (' . $orderInList . ')'
        . " AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung='0000-00-00 00:00:00')"
        . ' AND (b.is_gratis IS NULL OR b.is_gratis=0)'
        . $kuecheFilterOpen
        . ' ORDER BY b.rowid';
    $expRes = mysqli_query($conn, $expSql);
    if (!$expRes) {
        mysqli_rollback($conn);
        br_json_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
    }
    while ($er = mysqli_fetch_assoc($expRes)) {
        $expectedOpen[] = (int)$er['rowid'];
    }
    sort($expectedOpen, SORT_NUMERIC);
    if ($expectedOpen === []) {
        mysqli_rollback($conn);
        br_json_out(['ok' => false, 'error' => 'Keine passenden offenen Positionen fuer diese Bestellung (Ansicht/Zahlungsmodus prüfen).'], 400);
    }
    foreach ($selSorted as $ridSel) {
        if (!in_array($ridSel, $expectedOpen, true)) {
            mysqli_rollback($conn);
            br_json_out(['ok' => false, 'error' => 'Auswahl enthält Zeilen, die nicht zu den offenen Positionen dieser Bestellung(en) gehören.'], 400);
        }
    }
    $upd = "UPDATE bestellungen SET timestampBezahlung=CURRENT_TIMESTAMP, kellnerZahlung='" . $kellnerEsc . "'
            WHERE `delete`=0 AND rowid IN ($in)
            AND (timestampBezahlung IS NULL OR timestampBezahlung='0000-00-00 00:00:00')";
    if (!mysqli_query($conn, $upd)) {
        mysqli_rollback($conn);
        br_json_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
    }
}

$sqlItems = "SELECT b.rowid, p.Positionsname, p.Kurzbezeichnung, p.Betrag
        FROM bestellungen b
        JOIN positionen p ON p.rowid=b.position
        WHERE b.delete=0 AND b.rowid IN ($in)
          AND b.tischnummer=" . $tischnummer . "
          AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung<>'0000-00-00 00:00:00'
          AND (b.is_gratis IS NULL OR b.is_gratis=0)";
$resItems = mysqli_query($conn, $sqlItems);
if (!$resItems) {
    mysqli_rollback($conn);
    br_json_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
}

$items = [];
$total = 0.0;
while ($ir = mysqli_fetch_assoc($resItems)) {
    $items[] = $ir;
    $total += (float)$ir['Betrag'];
}
if (count($items) === 0) {
    mysqli_rollback($conn);
    br_json_out(['ok' => false, 'error' => 'Keine bezahlten Positionen fuer Rechnung'], 400);
}

$year = (int)date('Y');
$prefix = ff_rechnung_prefix_for_fest($conn, $fest_id);
$nextSeqVal = ff_rechnung_next_read($conn);
$rechnungsnummer = $prefix . $year . '-' . str_pad((string)$nextSeqVal, 4, '0', STR_PAD_LEFT);

$created_by = $kellner !== '' ? $kellner : 'system';
$fest_id_int = $fest_id ?? 0;

$stmt = mysqli_prepare($conn, 'INSERT INTO rechnungen (rechnungsnummer, created_by, fest_id, tischnummer, sammelrechnung_id, order_nr, is_firma,
    empfaenger_name, empfaenger_strasse, empfaenger_plz, empfaenger_ort, empfaenger_uid, total, gedruckt, druck_status, druck_attempts, is_proforma, lines_json)
    VALUES (?,?,?,?,?,?,0,?,?,?,?,?,?,0,\'pending\',0,0,NULL)');
if (!$stmt) {
    mysqli_rollback($conn);
    br_json_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
}

$sammel0 = 0;
$emp = '';
mysqli_stmt_bind_param(
    $stmt,
    'ssiiiisssssd',
    $rechnungsnummer,
    $created_by,
    $fest_id_int,
    $tischnummer,
    $sammel0,
    $orderNr,
    $emp,
    $emp,
    $emp,
    $emp,
    $emp,
    $total
);
if (!mysqli_stmt_execute($stmt)) {
    mysqli_rollback($conn);
    br_json_out(['ok' => false, 'error' => mysqli_stmt_error($stmt)], 500);
}
$rechnung_id = (int)mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

settings_set($conn, FF_RECHNUNG_NEXT_KEY, (string)($nextSeqVal + 1));

foreach ($items as $it) {
    mysqli_query($conn, 'UPDATE bestellungen SET rechnung_id=' . $rechnung_id . ' WHERE rowid=' . (int)$it['rowid']);
}

mysqli_commit($conn);

$thermoOk = false;
$thermoEnqueueError = null;
if ($thermoTarget > 0) {
    $rFull = mysqli_query($conn, 'SELECT * FROM rechnungen WHERE id=' . $rechnung_id . ' LIMIT 1');
    $rrow = $rFull ? mysqli_fetch_assoc($rFull) : null;
    if ($rrow) {
        $txt = ff_rechnung_build_thermal_text($conn, $rrow);
        if ($txt === '') {
            $thermoEnqueueError = 'Thermotext leer (Rechnungspositionen prüfen)';
        } else {
            $thermoOk = ff_rechnung_enqueue_thermo($conn, $thermoTarget, $txt);
            if (!$thermoOk) {
                $dbErr = mysqli_error($conn);
                $thermoEnqueueError = ($dbErr !== '') ? $dbErr : 'printer_jobs INSERT fehlgeschlagen';
            }
        }
    } else {
        $thermoEnqueueError = 'Rechnung nach Speichern nicht lesbar';
    }
}

mysqli_close($conn);

br_json_out([
    'ok' => true,
    'rechnung_id' => $rechnung_id,
    'rechnungsnummer' => $rechnungsnummer,
    'rechnung_nummer_art' => 'laufend',
    'order_nr' => $orderNr,
    'total' => round($total, 2),
    'thermo_enqueued' => $thermoOk,
    'thermo_print_target' => $thermoTarget,
    'thermo_enqueue_error' => $thermoEnqueueError,
    'pdf_url' => $openPdf ? ('rechnung_pdf.php?id=' . $rechnung_id) : null,
]);
