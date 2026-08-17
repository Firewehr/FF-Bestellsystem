<?php
/**
 * Erstellt eine Rechnung (bezahlt oder Tischstand/Vorschau), optional Thermodruck an gewähltem Print-Target.
 */
require_once 'auth.php';
require_once 'include/db.php';
require_once 'include/settings.php';
require_once __DIR__ . '/include/ff_rechnung_items.php';
require_once __DIR__ . '/include/ff_rechnung_seq.php';

mysqli_report(MYSQLI_REPORT_OFF);
if (ob_get_level()) {
    @ob_clean();
}

function respond($ok, $msg, $extra = []) {
    if (ob_get_level()) {
        @ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$tischnummer = isset($_POST['tischnummer']) ? (int)$_POST['tischnummer'] : 0;
$sammelrechnung_id = isset($_POST['sammelrechnung_id']) ? (int)$_POST['sammelrechnung_id'] : 0;

$is_firma = isset($_POST['is_firma']) ? (int)$_POST['is_firma'] : 0;
$empfaenger_name = trim($_POST['empfaenger_name'] ?? '');
$empfaenger_strasse = trim($_POST['empfaenger_strasse'] ?? '');
$empfaenger_plz = trim($_POST['empfaenger_plz'] ?? '');
$empfaenger_ort = trim($_POST['empfaenger_ort'] ?? '');
$empfaenger_uid = trim($_POST['empfaenger_uid'] ?? '');

$basis = isset($_POST['basis']) ? trim((string)$_POST['basis']) : 'bezahlt';
if (!in_array($basis, ['bezahlt', 'tischstand'], true)) {
    $basis = 'bezahlt';
}
if ($sammelrechnung_id > 0) {
    $basis = 'bezahlt';
}

$thermoRaw = isset($_POST['thermo_ziel']) ? trim((string)$_POST['thermo_ziel']) : '0';
$sessionPt = isset($_POST['session_print_target']) ? (int)$_POST['session_print_target'] : 0;

if ($tischnummer <= 0 && $sammelrechnung_id <= 0) {
    respond(false, 'tischnummer oder sammelrechnung_id fehlt');
}

mysqli_set_charset($conn, 'utf8mb4');

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS rechnungen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rechnungsnummer VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(64) NOT NULL,
    fest_id INT NULL,
    tischnummer INT NULL,
    sammelrechnung_id INT NULL,
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
    reserved_by VARCHAR(64) NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8");

require_once __DIR__ . '/include/ff_rechnungen_ensure_columns.php';
ff_rechnungen_ensure_extra_columns($conn);
ff_rechnungen_ensure_print_columns($conn);
ff_bestellungen_ensure_rechnung_id_column($conn);
ff_bestellungen_ensure_is_gratis_column($conn);

$openPdf = isset($_POST['open_pdf']) && (string) $_POST['open_pdf'] === '1';

$fest_id = null;
$resFest = @mysqli_query($conn, "SELECT id FROM feste WHERE aktiv=1 LIMIT 1");
if ($resFest && ($rowFest = mysqli_fetch_assoc($resFest))) {
    $fest_id = (int)$rowFest['id'];
}
if ($fest_id === 0) {
    $fest_id = null;
}

$paymentMode = 'after';
$fres = @mysqli_query($conn, "SELECT payment_mode FROM feste WHERE aktiv=1 LIMIT 1");
if ($fres && ($frow = mysqli_fetch_assoc($fres))) {
    $paymentMode = ($frow['payment_mode'] === 'instant') ? 'instant' : 'after';
}

$validTargets = [];
$ptq = @mysqli_query($conn, "SELECT print_target FROM print_targets WHERE active=1");
if ($ptq) {
    while ($pt = mysqli_fetch_assoc($ptq)) {
        $validTargets[(int)$pt['print_target']] = true;
    }
}
if (!isset($validTargets[11])) {
    $validTargets[11] = true;
}
if (!isset($validTargets[12])) {
    $validTargets[12] = true;
}

$thermoTarget = 0;
if ($thermoRaw === 'session') {
    $thermoTarget = $sessionPt > 0 ? $sessionPt : 11;
} else {
    $thermoTarget = (int)$thermoRaw;
}
if ($thermoTarget > 0 && !isset($validTargets[$thermoTarget])) {
    respond(false, 'Ungültiges Thermo-Druckziel.');
}

$kuecheFilterBezahlt = ' AND b.kueche=1 ';
$kuecheFilterOffen = ($paymentMode === 'after') ? ' AND b.kueche=1 ' : '';

$items = [];
$total = 0.0;
$isProforma = 0;
$linesSnapshot = null;
$linkRowids = true;

if ($sammelrechnung_id > 0) {
    // Nach SammelrechnungBezahlt: bezahlt + sammelrechnung_id; kueche kann 0 oder 1 sein (instant/after)
    $sql = "SELECT b.rowid, b.order_nr, p.Positionsname, p.Kurzbezeichnung,
            COALESCE(NULLIF(b.betrag, 0), p.Betrag) AS Betrag
            FROM bestellungen b
            JOIN positionen p ON p.rowid=b.position
            WHERE b.delete=0
              AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung<>'0000-00-00 00:00:00'
              AND (b.is_gratis IS NULL OR b.is_gratis=0)
              AND (b.rechnung_id IS NULL OR b.rechnung_id = 0)
              AND b.sammelrechnung_id=" . (int) $sammelrechnung_id;
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        respond(false, 'DB Fehler: ' . mysqli_error($conn));
    }
    while ($r = mysqli_fetch_assoc($result)) {
        $items[] = $r;
        $total += (float)$r['Betrag'];
    }
    if (count($items) === 0) {
        respond(false, 'Keine bezahlten Positionen ohne Rechnung für diese Sammelrechnung. Bereits verbucht? → Admin → Rechnungen (PDF/Thermo).');
    }
} elseif ($basis === 'tischstand') {
    // Wie am Bon: Position + Zusatzinfo zusammengruppieren, damit z. B. 5 Schnitzel
    // mit unterschiedlichen Beilagen/Zusatzpositionen 5 separate Zeilen ergeben.
    $sql = "SELECT p.Positionsname, p.Kurzbezeichnung, p.Betrag, b.Zusatzinfo, COUNT(*) AS cnt,
                   SUM(COALESCE(b.betrag, p.Betrag)) AS summe
            FROM bestellungen b
            JOIN positionen p ON p.rowid=b.position
            WHERE b.tischnummer=" . $tischnummer . "
              AND b.delete=0
              AND b.bestellt=1
              AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung='0000-00-00 00:00:00')
              " . $kuecheFilterOffen . "
              AND (b.is_gratis IS NULL OR b.is_gratis=0)
            GROUP BY p.rowid, p.Positionsname, p.Kurzbezeichnung, p.Betrag, b.Zusatzinfo
            ORDER BY p.Positionsname ASC, b.Zusatzinfo ASC";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        respond(false, 'DB Fehler: ' . mysqli_error($conn));
    }
    $snap = [];
    while ($r = mysqli_fetch_assoc($result)) {
        $cnt = (int)$r['cnt'];
        $name = (string)($r['Kurzbezeichnung'] ?: $r['Positionsname'] ?? '');
        $unit = (float)$r['Betrag'];
        $summe = (float)$r['summe'];
        $zi = (string)($r['Zusatzinfo'] ?? '');
        $total += $summe;
        $snap[] = ['cnt' => $cnt, 'name' => $name, 'betrag' => $unit, 'zusatzinfo' => $zi];
    }
    if (count($snap) === 0) {
        respond(false, 'Keine offenen Positionen am Tisch fuer eine Kostenuebersicht.');
    }
    $linesSnapshot = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $isProforma = 1;
    $linkRowids = false;
} else {
    $filterRowIds = [];
    if (!empty($_POST['listePositionen']) && is_array($_POST['listePositionen'])) {
        foreach ($_POST['listePositionen'] as $rid) {
            $rid = (int) $rid;
            if ($rid > 0) {
                $filterRowIds[$rid] = true;
            }
        }
    }
    $filterRowIds = array_keys($filterRowIds);

    $kuecheBezahltSql = (count($filterRowIds) > 0) ? '' : $kuecheFilterBezahlt;
    $sql = "SELECT b.rowid, b.order_nr, p.Positionsname, p.Kurzbezeichnung,
            COALESCE(NULLIF(b.betrag, 0), p.Betrag) AS Betrag
            FROM bestellungen b
            JOIN positionen p ON p.rowid=b.position
            WHERE b.delete=0 " . $kuecheBezahltSql . "
              AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung<>'0000-00-00 00:00:00'
              AND (b.is_gratis IS NULL OR b.is_gratis=0)
              AND (b.rechnung_id IS NULL OR b.rechnung_id = 0)
              AND b.tischnummer=" . $tischnummer;
    if (count($filterRowIds) > 0) {
        $sql .= ' AND b.rowid IN (' . implode(',', array_map('intval', $filterRowIds)) . ')';
    }
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        respond(false, 'DB Fehler: ' . mysqli_error($conn));
    }
    while ($r = mysqli_fetch_assoc($result)) {
        $items[] = $r;
        $total += (float)$r['Betrag'];
    }
    if (count($items) === 0) {
        if (count($filterRowIds) > 0) {
            respond(false, 'Keine gültigen Positionen in der Auswahl (bereits verrechnet oder ungültig). Bitte erneut aus Historie/Zahlen wählen.');
        }
        respond(false, 'Keine bezahlten Positionen ohne Rechnung an diesem Tisch. Wenn schon abgerechnet: Admin → Rechnungen → PDF öffnen. Sonst „Aktueller Tisch (offen)“ für eine Kostenübersicht (Proforma).');
    }
}

$year = (int)date('Y');
$prefix = ff_rechnung_prefix_for_fest($conn, $fest_id);
$next = ff_rechnung_next_read($conn);
$rechnungsnummer = $prefix . $year . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

$created_by = isset($_SESSION['user']['username']) ? (string) $_SESSION['user']['username'] : 'system';
$fest_id_int = $fest_id !== null ? (int) $fest_id : 0;
$tischnummerIns = $tischnummer > 0 ? (int) $tischnummer : 0;
$sammelIns = $sammelrechnung_id > 0 ? (int) $sammelrechnung_id : 0;

$rnEsc = mysqli_real_escape_string($conn, $rechnungsnummer);
$cbEsc = mysqli_real_escape_string($conn, $created_by);
$enEsc = mysqli_real_escape_string($conn, $empfaenger_name);
$esEsc = mysqli_real_escape_string($conn, $empfaenger_strasse);
$epEsc = mysqli_real_escape_string($conn, $empfaenger_plz);
$eoEsc = mysqli_real_escape_string($conn, $empfaenger_ort);
$euEsc = mysqli_real_escape_string($conn, $empfaenger_uid);
$totalSql = number_format($total, 2, '.', '');

$sqlIns = 'INSERT INTO rechnungen (rechnungsnummer, created_by, fest_id, tischnummer, sammelrechnung_id, is_firma,
    empfaenger_name, empfaenger_strasse, empfaenger_plz, empfaenger_ort, empfaenger_uid, total, gedruckt, druck_status, druck_attempts, is_proforma, lines_json)
    VALUES (\'' . $rnEsc . '\', \'' . $cbEsc . '\', ' . $fest_id_int . ', ' . $tischnummerIns . ', ' . $sammelIns . ', ' . (int) $is_firma . ',
    \'' . $enEsc . '\', \'' . $esEsc . '\', \'' . $epEsc . '\', \'' . $eoEsc . '\', \'' . $euEsc . '\', ' . $totalSql . ', 0, \'pending\', 0, 0, NULL)';

if (!mysqli_query($conn, $sqlIns)) {
    respond(false, 'DB Fehler: ' . mysqli_error($conn));
}
$rechnung_id = (int) mysqli_insert_id($conn);
if ($rechnung_id <= 0) {
    respond(false, 'Rechnung konnte nicht gespeichert werden (keine ID).');
}

if ($isProforma === 1 && $linesSnapshot !== null) {
    $esc = mysqli_real_escape_string($conn, $linesSnapshot);
    @mysqli_query($conn, "UPDATE rechnungen SET is_proforma=1, lines_json='{$esc}' WHERE id=" . $rechnung_id);
} else {
    @mysqli_query($conn, "UPDATE rechnungen SET is_proforma=0, lines_json=NULL WHERE id=" . $rechnung_id);
}

if ($linkRowids) {
    foreach ($items as $it) {
        @mysqli_query($conn, 'UPDATE bestellungen SET rechnung_id=' . $rechnung_id . ' WHERE rowid=' . (int)$it['rowid']);
    }
}

$orderNrIns = null;
if ($linkRowids && $items !== []) {
    $onrs = [];
    foreach ($items as $it) {
        $o = (int)($it['order_nr'] ?? 0);
        if ($o > 0) {
            $onrs[$o] = true;
        }
    }
    $keys = array_keys($onrs);
    sort($keys, SORT_NUMERIC);
    if (count($keys) === 1) {
        $orderNrIns = (int)$keys[0];
    }
}
if ($orderNrIns !== null && $orderNrIns > 0 && $rechnung_id > 0) {
    @mysqli_query($conn, 'UPDATE rechnungen SET order_nr=' . $orderNrIns . ' WHERE id=' . $rechnung_id);
}

settings_set($conn, FF_RECHNUNG_NEXT_KEY, (string)($next + 1));

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

respond(true, 'Rechnung erstellt', [
    'rechnung_id' => $rechnung_id,
    'rechnungsnummer' => $rechnungsnummer,
    'order_nr' => $orderNrIns,
    'total' => round($total, 2),
    'is_proforma' => $isProforma,
    'thermo_enqueued' => $thermoOk,
    'thermo_print_target' => $thermoTarget,
    'thermo_enqueue_error' => $thermoEnqueueError,
    'pdf_url' => $openPdf ? ('rechnung_pdf.php?id=' . $rechnung_id) : null,
]);
