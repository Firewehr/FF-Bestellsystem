<?php
declare(strict_types=1);

/**
 * Endpunkt für Bezahlung, Splitten, Ehrengäste-Abschluss und Rechnungserstellung.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/settings.php';
require_once dirname(__DIR__, 2) . '/include/ff_rechnung_seq.php';
require_once dirname(__DIR__, 2) . '/include/ff_rechnung_items.php';
require_once dirname(__DIR__, 2) . '/include/ff_rechnungen_ensure_columns.php';
require_once dirname(__DIR__, 2) . '/include/ff_station_events.php';
require_once dirname(__DIR__, 2) . '/include/ff_event_notifications.php';

$user = api_require_auth();
$data = api_get_request_data();
api_require_csrf($data);

$tischnummer = (int)($data['tischnummer'] ?? 0);
if ($tischnummer <= 0) {
    api_error('Tischnummer erforderlich.', 'missing_table', 400);
}

$action = (string)($data['action'] ?? 'pay'); // 'pay', 'ehrengast', 'with_invoice', 'rechnung_nachtraeglich'
$username = (string)$user['username'];
$usernameEsc = mysqli_real_escape_string($conn, $username);

// Tisch-Flags prüfen
$tStmt = mysqli_prepare($conn, 'SELECT is_ehrengast, COALESCE(is_sammelrechnung,0) AS is_sammelrechnung, tischname FROM tische WHERE tischnummer = ? LIMIT 1');
if (!$tStmt) {
    api_error('Datenbankfehler: ' . mysqli_error($conn), 'db_error', 500);
}
mysqli_stmt_bind_param($tStmt, 'i', $tischnummer);
mysqli_stmt_execute($tStmt);
$tRes = mysqli_stmt_get_result($tStmt);
$tableRow = $tRes ? mysqli_fetch_assoc($tRes) : null;
mysqli_stmt_close($tStmt);

if (!$tableRow) {
    api_error("Tisch {$tischnummer} existiert nicht.", 'table_not_found', 404);
}

$isSammel = (int)$tableRow['is_sammelrechnung'] === 1;
$isEhren = (int)$tableRow['is_ehrengast'] === 1;

// Aktives Fest
$activeFestId = null;
$festRes = mysqli_query($conn, 'SELECT id, payment_mode FROM feste WHERE aktiv = 1 LIMIT 1');
if ($festRes && ($fRow = mysqli_fetch_assoc($festRes))) {
    $activeFestId = (int)$fRow['id'];
}

// ---------------------------------------------------------
// 1. EHRENGAST ABSCHLIESSEN
// ---------------------------------------------------------
if ($action === 'ehrengast') {
    if (!$isEhren) {
        api_error('Dieser Tisch ist nicht als Ehrengast markiert.', 'not_ehrengast', 403);
    }

    $rawIds = (array)($data['rowids'] ?? []);
    if ($rawIds === []) {
        // Alle offenen Positionen des Tisches nehmen
        $allSql = "SELECT rowid FROM bestellungen WHERE tischnummer = {$tischnummer} AND `delete` = 0 AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00')";
        $allRes = mysqli_query($conn, $allSql);
        while ($ar = mysqli_fetch_assoc($allRes)) {
            $rawIds[] = (int)$ar['rowid'];
        }
    }

    $rowids = array_filter(array_map('intval', $rawIds), fn($id) => $id > 0);
    if ($rowids === []) {
        api_error('Keine offenen Positionen zum Abschließen.', 'no_items', 400);
    }

    $inClause = implode(',', $rowids);
    $updSql = "UPDATE `bestellungen` SET `timestampBezahlung` = CURRENT_TIMESTAMP, `kueche` = 1, `is_gratis` = 1, `kellnerZahlung` = '{$usernameEsc}' "
            . "WHERE `tischnummer` = {$tischnummer} AND `delete` = 0 AND `rowid` IN ({$inClause}) AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00')";

    if (!mysqli_query($conn, $updSql)) {
        api_error('Konnte Ehrengast-Positionen nicht abschließen: ' . mysqli_error($conn), 'db_error', 500);
    }

    $affected = mysqli_affected_rows($conn);

    // Prüfen, ob noch offene Posten da sind -> falls nein, Ehrengast-Flag zurücksetzen
    $chkSql = "SELECT COUNT(*) AS c FROM bestellungen WHERE `delete` = 0 AND tischnummer = {$tischnummer} AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00')";
    $chkRes = mysqli_query($conn, $chkSql);
    $stillOpen = 0;
    if ($chkRes && ($cw = mysqli_fetch_assoc($chkRes))) {
        $stillOpen = (int)$cw['c'];
    }
    if ($stillOpen === 0) {
        @mysqli_query($conn, "UPDATE tische SET is_ehrengast = 0 WHERE tischnummer = {$tischnummer} LIMIT 1");
    }

    api_response([
        'ok' => true,
        'affected' => $affected,
        'table_cleared' => ($stillOpen === 0),
        'message' => 'Ehrengäste-Tisch erfolgreich abgeschlossen (0,00 €).',
    ]);
}

// ---------------------------------------------------------
// 2. NACHTRÄGLICHE RECHNUNG
// ---------------------------------------------------------
if ($action === 'rechnung_nachtraeglich') {
    ff_rechnungen_ensure_extra_columns($conn);
    ff_bestellungen_ensure_rechnung_id_column($conn);

    // Finde bezahlte Positionen dieses Tisches ohne Rechnung
    $pSql = "SELECT b.rowid, b.position, COALESCE(b.betrag, p.Betrag) AS betrag, b.Zusatzinfo, b.order_nr,
                    COALESCE(p.Positionsname, '(Position)') AS name
             FROM bestellungen b
             LEFT JOIN positionen p ON p.rowid = b.position
             WHERE b.tischnummer = {$tischnummer} AND b.`delete` = 0 
               AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung NOT IN ('0000-00-00 00:00:00', '1970-01-01 00:00:00')
               AND (b.rechnung_id IS NULL OR b.rechnung_id = 0)
             ORDER BY b.timestampBezahlung ASC";
    $pRes = mysqli_query($conn, $pSql);
    $paidRows = [];
    $total = 0.0;
    $groupedLines = [];
    $firstOrderNr = null;

    if ($pRes) {
        while ($pr = mysqli_fetch_assoc($pRes)) {
            $paidRows[] = (int)$pr['rowid'];
            $bVal = (float)$pr['betrag'];
            $total += $bVal;
            if ($firstOrderNr === null && !empty($pr['order_nr'])) {
                $firstOrderNr = (int)$pr['order_nr'];
            }
            $gKey = (string)$pr['name'] . '|' . (string)$pr['Zusatzinfo'] . '|' . number_format($bVal, 2, '.', '');
            if (!isset($groupedLines[$gKey])) {
                $groupedLines[$gKey] = [
                    'cnt' => 0,
                    'name' => (string)$pr['name'],
                    'betrag' => $bVal,
                    'zusatzinfo' => (string)$pr['Zusatzinfo'],
                ];
            }
            $groupedLines[$gKey]['cnt']++;
        }
    }

    if ($paidRows === []) {
        api_error('Keine bezahlten Positionen ohne Rechnung gefunden.', 'no_paid_items', 400);
    }

    $rPrefix = ff_rechnung_prefix_for_fest($conn, $activeFestId);
    $seq = ff_rechnung_next_allocate($conn);
    $rechnungsnummer = $rPrefix . $seq;

    $linesJson = json_encode(array_values($groupedLines), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $escLines = mysqli_real_escape_string($conn, $linesJson ?: '[]');
    $escRnr = mysqli_real_escape_string($conn, $rechnungsnummer);

    $insR = "INSERT INTO rechnungen (rechnungsnummer, created_by, fest_id, tischnummer, order_nr, total, lines_json, gedruckt, druck_status)
             VALUES ('{$escRnr}', '{$usernameEsc}', " . ($activeFestId ?: 'NULL') . ", {$tischnummer}, " . ($firstOrderNr ?: 'NULL') . ", {$total}, '{$escLines}', 0, 'pending')";

    if (!mysqli_query($conn, $insR)) {
        api_error('Fehler beim Erstellen der Rechnung: ' . mysqli_error($conn), 'db_error', 500);
    }

    $rechnungId = (int)mysqli_insert_id($conn);
    $inRows = implode(',', $paidRows);
    mysqli_query($conn, "UPDATE bestellungen SET rechnung_id = {$rechnungId} WHERE rowid IN ({$inRows})");

    // Thermodruck falls gewünscht
    $thermoTarget = (int)($data['thermo_target'] ?? 0);
    if ($thermoTarget > 0) {
        $rRow = [
            'id' => $rechnungId,
            'rechnungsnummer' => $rechnungsnummer,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $username,
            'tischnummer' => $tischnummer,
            'order_nr' => $firstOrderNr,
            'total' => $total,
            'lines_json' => $linesJson,
        ];
        $text = ff_rechnung_build_thermal_text($conn, $rRow);
        ff_rechnung_enqueue_thermo($conn, $thermoTarget, $text);
    }

    api_response([
        'ok' => true,
        'rechnung_id' => $rechnungId,
        'rechnungsnummer' => $rechnungsnummer,
        'total' => round($total, 2),
        'message' => "Rechnung {$rechnungsnummer} erfolgreich nachträglich erstellt.",
    ]);
}

// ---------------------------------------------------------
// 3. REGULÄRES BEZAHLEN (BAR / SUMUP / KARTE / MIT RECHNUNG)
// ---------------------------------------------------------
$rawIds = (array)($data['rowids'] ?? []);
$rowids = array_filter(array_map('intval', $rawIds), fn($id) => $id > 0);

if ($rowids === []) {
    api_error('Keine Positionen zum Bezahlen ausgewählt.', 'no_items_selected', 400);
}

// Sammelrechnung-Schutz: darf nicht einzeln bezahlt werden
if ($isSammel) {
    api_error('Dieser Tisch ist als Sammelrechnung markiert und kann nur zentral abgerechnet werden.', 'sammelrechnung_locked', 409);
}

$inClause = implode(',', $rowids);

// Summe und Validierung der Positionen
$sumSql = "SELECT b.rowid, b.position, COALESCE(b.betrag, p.Betrag) AS betrag, b.Zusatzinfo, b.order_nr,
                  COALESCE(p.Positionsname, '(Position)') AS name
           FROM bestellungen b
           LEFT JOIN positionen p ON p.rowid = b.position
           WHERE b.`delete` = 0 AND b.tischnummer = {$tischnummer} 
             AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung = '0000-00-00 00:00:00')
             AND b.rowid IN ({$inClause})";

$sRes = mysqli_query($conn, $sumSql);
$payableRows = [];
$totalAmount = 0.0;
$groupedLines = [];
$firstOrderNr = null;

if ($sRes) {
    while ($sr = mysqli_fetch_assoc($sRes)) {
        $payableRows[] = (int)$sr['rowid'];
        $bVal = (float)$sr['betrag'];
        $totalAmount += $bVal;
        if ($firstOrderNr === null && !empty($sr['order_nr'])) {
            $firstOrderNr = (int)$sr['order_nr'];
        }
        $gKey = (string)$sr['name'] . '|' . (string)$sr['Zusatzinfo'] . '|' . number_format($bVal, 2, '.', '');
        if (!isset($groupedLines[$gKey])) {
            $groupedLines[$gKey] = [
                'cnt' => 0,
                'name' => (string)$sr['name'],
                'betrag' => $bVal,
                'zusatzinfo' => (string)$sr['Zusatzinfo'],
            ];
        }
        $groupedLines[$gKey]['cnt']++;
    }
}

if ($payableRows === []) {
    api_error('Die ausgewählten Positionen wurden bereits abgerechnet oder existieren nicht mehr.', 'already_paid', 409);
}

$inPayable = implode(',', $payableRows);

// In Transaktion abwickeln
if (!mysqli_begin_transaction($conn)) {
    api_error('Datenbankfehler bei Transaktionsstart.', 'db_error', 500);
}

try {
    $rechnungId = null;
    $rechnungsnummer = null;

    // Falls MIT RECHNUNG gewählt wurde
    if ($action === 'with_invoice') {
        ff_rechnungen_ensure_extra_columns($conn);
        ff_bestellungen_ensure_rechnung_id_column($conn);

        $rPrefix = ff_rechnung_prefix_for_fest($conn, $activeFestId);
        $seq = ff_rechnung_next_allocate($conn);
        $rechnungsnummer = $rPrefix . $seq;

        $linesJson = json_encode(array_values($groupedLines), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $escLines = mysqli_real_escape_string($conn, $linesJson ?: '[]');
        $escRnr = mysqli_real_escape_string($conn, $rechnungsnummer);

        $recipient = (array)($data['recipient'] ?? []);
        $isFirma = !empty($recipient['is_firma']) ? 1 : 0;
        $empfName = mysqli_real_escape_string($conn, (string)($recipient['name'] ?? ''));
        $empfStr = mysqli_real_escape_string($conn, (string)($recipient['strasse'] ?? ''));
        $empfPlz = mysqli_real_escape_string($conn, (string)($recipient['plz'] ?? ''));
        $empfOrt = mysqli_real_escape_string($conn, (string)($recipient['ort'] ?? ''));
        $empfUid = mysqli_real_escape_string($conn, (string)($recipient['uid'] ?? ''));

        $insR = "INSERT INTO rechnungen 
                 (rechnungsnummer, created_by, fest_id, tischnummer, order_nr, total, lines_json, gedruckt, druck_status, is_firma, empfaenger_name, empfaenger_strasse, empfaenger_plz, empfaenger_ort, empfaenger_uid)
                 VALUES ('{$escRnr}', '{$usernameEsc}', " . ($activeFestId ?: 'NULL') . ", {$tischnummer}, " . ($firstOrderNr ?: 'NULL') . ", {$totalAmount}, '{$escLines}', 0, 'pending', {$isFirma}, '{$empfName}', '{$empfStr}', '{$empfPlz}', '{$empfOrt}', '{$empfUid}')";

        if (!mysqli_query($conn, $insR)) {
            throw new RuntimeException('Rechnung konnte nicht angelegt werden: ' . mysqli_error($conn));
        }

        $rechnungId = (int)mysqli_insert_id($conn);
    }

    // Positionen als bezahlt markieren
    $updSql = "UPDATE `bestellungen` 
               SET `timestampBezahlung` = CURRENT_TIMESTAMP, 
                   `kellnerZahlung` = '{$usernameEsc}'"
               . ($rechnungId ? ", `rechnung_id` = {$rechnungId} " : " ")
               . "WHERE `rowid` IN ({$inPayable})";

    if (!mysqli_query($conn, $updSql)) {
        throw new RuntimeException('Bezahlung konnte nicht gespeichert werden: ' . mysqli_error($conn));
    }

    $affected = mysqli_affected_rows($conn);
    mysqli_commit($conn);

    // Thermo-Druckauftrag falls gewünscht
    $thermoTarget = (int)($data['thermo_target'] ?? 0);
    if ($action === 'with_invoice' && $thermoTarget > 0 && $rechnungId > 0) {
        $rRow = [
            'id' => $rechnungId,
            'rechnungsnummer' => $rechnungsnummer,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $username,
            'tischnummer' => $tischnummer,
            'order_nr' => $firstOrderNr,
            'total' => $totalAmount,
            'lines_json' => $linesJson ?? '',
        ];
        $text = ff_rechnung_build_thermal_text($conn, $rRow);
        ff_rechnung_enqueue_thermo($conn, $thermoTarget, $text);
    }

    // Push-Benachrichtigung für Admin-Finanzen
    if ($activeFestId > 0) {
        try {
            ff_event_notifications_evaluate($conn, $activeFestId);
        } catch (Throwable $e) {}
    }

    api_response([
        'ok' => true,
        'affected' => $affected,
        'total' => round($totalAmount, 2),
        'rechnung_id' => $rechnungId,
        'rechnungsnummer' => $rechnungsnummer,
        'message' => $rechnungsnummer 
            ? "Zahlung verbucht und Rechnung {$rechnungsnummer} erstellt."
            : "Zahlung in Höhe von " . number_format($totalAmount, 2, ',', '.') . " € verbucht.",
    ]);

} catch (Throwable $e) {
    mysqli_rollback($conn);
    api_error($e->getMessage(), 'payment_failed', 500);
}
