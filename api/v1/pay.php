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
require_once dirname(__DIR__, 2) . '/include/ff_pilot_orders.php';
require_once dirname(__DIR__, 2) . '/include/ff_payment.php';

$user = api_require_auth();
$data = api_get_request_data();
api_require_csrf($data);

$tischnummer = (int)($data['tischnummer'] ?? 0);
if ($tischnummer <= 0) {
    api_error('Tischnummer erforderlich.', 'missing_table', 400);
}

$action = (string)($data['action'] ?? 'pay'); // 'pay', 'ehrengast', 'with_invoice', 'rechnung_nachtraeglich'
$username = (string)$user['username'];
$protectedPilotActions = ['pay', 'ehrengast', 'sumup_prepare', 'sumup_confirm'];
$paymentKey = null;
if (in_array($action, $protectedPilotActions, true)) {
    $providedKey = trim((string)($data['idempotency_key'] ?? ''));
    if ($providedKey !== '') {
        if (!ff_pilot_valid_client_key($providedKey)) {
            api_error('Ungültiger Idempotenzschlüssel.', 'invalid_idempotency_key', 400);
        }
        $paymentKey = $providedKey;
    }
}

// Tisch-Flags prüfen
$tStmt = ff_mysqli_prepare($conn, 'SELECT is_ehrengast, COALESCE(is_sammelrechnung,0) AS is_sammelrechnung, tischname FROM tische WHERE tischnummer = ? LIMIT 1');
if (!$tStmt) {
    api_error('Datenbankfehler: ' . mysqli_error($conn), 'db_error', 500);
}
mysqli_stmt_bind_param($tStmt, 'i', $tischnummer);
ff_mysqli_stmt_execute($tStmt);
$tRes = mysqli_stmt_get_result($tStmt);
$tableRow = $tRes ? mysqli_fetch_assoc($tRes) : null;
mysqli_stmt_close($tStmt);

if (!$tableRow) {
    api_error("Tisch {$tischnummer} existiert nicht.", 'table_not_found', 404);
}

$isSammel = (int)$tableRow['is_sammelrechnung'] === 1;
$isEhren = (int)$tableRow['is_ehrengast'] === 1;
ff_pilot_orders_ensure_schema($conn);

// Aktives Fest
$activeFestId = null;
$festRes = ff_mysqli_query($conn, 'SELECT id, payment_mode FROM feste WHERE aktiv = 1 LIMIT 1');
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

    $rawIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['rowids'] ?? [])), fn($id) => $id > 0)));
    sort($rawIds);
    if (!mysqli_begin_transaction($conn)) {
        api_error('Datenbankfehler bei Transaktionsstart.', 'db_error', 500);
    }
    try {
        if ($paymentKey !== null) {
            $replay = ff_pilot_payment_request_begin(
                $conn,
                $paymentKey,
                $action,
                $username,
                $tischnummer,
                hash('sha256', json_encode(['table' => $tischnummer, 'rowids' => $rawIds]))
            );
            if ($replay !== null) {
                mysqli_commit($conn);
                api_response($replay);
            }
        }

        $whereIds = '';
        $types = 'i';
        $params = [$tischnummer];
        if ($rawIds !== []) {
            $whereIds = ' AND rowid IN (' . implode(',', array_fill(0, count($rawIds), '?')) . ')';
            $types .= str_repeat('i', count($rawIds));
            $params = array_merge($params, $rawIds);
        }
        $lock = ff_mysqli_prepare(
            $conn,
            "SELECT rowid FROM bestellungen WHERE tischnummer = ? AND `delete` = 0
             AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00'){$whereIds} FOR UPDATE"
        );
        if (!$lock) throw new RuntimeException('Ehrengast-Positionen konnten nicht gesperrt werden.');
        mysqli_stmt_bind_param($lock, $types, ...$params);
        if (!ff_mysqli_stmt_execute($lock)) {
            $error = mysqli_stmt_error($lock);
            mysqli_stmt_close($lock);
            throw new RuntimeException('Ehrengast-Positionen konnten nicht gesperrt werden: ' . $error);
        }
        $result = mysqli_stmt_get_result($lock);
        $rowids = [];
        while ($result && ($row = mysqli_fetch_assoc($result))) $rowids[] = (int)$row['rowid'];
        mysqli_stmt_close($lock);
        if ($rowids === []) throw new RuntimeException('Keine offenen Positionen zum Abschließen.');

        $marks = implode(',', array_fill(0, count($rowids), '?'));
        $update = ff_mysqli_prepare(
            $conn,
            "UPDATE bestellungen SET timestampBezahlung = CURRENT_TIMESTAMP, kueche = 1, is_gratis = 1, kellnerZahlung = ?
             WHERE tischnummer = ? AND `delete` = 0 AND rowid IN ({$marks})
             AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00')"
        );
        if (!$update) throw new RuntimeException('Ehrengast-Positionen konnten nicht aktualisiert werden.');
        mysqli_stmt_bind_param($update, 'si' . str_repeat('i', count($rowids)), $username, $tischnummer, ...$rowids);
        if (!ff_mysqli_stmt_execute($update)) {
            $error = mysqli_stmt_error($update);
            mysqli_stmt_close($update);
            throw new RuntimeException('Konnte Ehrengast-Positionen nicht abschließen: ' . $error);
        }
        $affected = mysqli_stmt_affected_rows($update);
        mysqli_stmt_close($update);

        $stillOpenStmt = ff_mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS c FROM bestellungen WHERE `delete` = 0 AND tischnummer = ?
             AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00')"
        );
        mysqli_stmt_bind_param($stillOpenStmt, 'i', $tischnummer);
        if (!ff_mysqli_stmt_execute($stillOpenStmt)) {
            $error = mysqli_stmt_error($stillOpenStmt);
            mysqli_stmt_close($stillOpenStmt);
            throw new RuntimeException('Offene Ehrengast-Positionen konnten nicht geprüft werden: ' . $error);
        }
        $stillOpenResult = mysqli_stmt_get_result($stillOpenStmt);
        $stillOpenRow = $stillOpenResult ? mysqli_fetch_assoc($stillOpenResult) : null;
        mysqli_stmt_close($stillOpenStmt);
        $stillOpen = (int)($stillOpenRow['c'] ?? 0);
        if ($stillOpen === 0) {
            $clear = ff_mysqli_prepare($conn, 'UPDATE tische SET is_ehrengast = 0 WHERE tischnummer = ? LIMIT 1');
            mysqli_stmt_bind_param($clear, 'i', $tischnummer);
            if (!ff_mysqli_stmt_execute($clear)) {
                $error = mysqli_stmt_error($clear);
                mysqli_stmt_close($clear);
                throw new RuntimeException('Ehrengast-Markierung konnte nicht zurückgesetzt werden: ' . $error);
            }
            mysqli_stmt_close($clear);
        }

        $response = [
            'ok' => true,
            'affected' => $affected,
            'table_cleared' => ($stillOpen === 0),
            'message' => 'Ehrengäste-Tisch erfolgreich abgeschlossen (0,00 €).',
        ];
        if ($paymentKey !== null) {
            ff_pilot_payment_request_complete($conn, $paymentKey, $action, $username, $response);
        }
        mysqli_commit($conn);
        api_response($response);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        api_error($e->getMessage(), 'ehrengast_failed', 409);
    }
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
             WHERE b.tischnummer = ? AND b.`delete` = 0 
               AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung NOT IN ('0000-00-00 00:00:00', '1970-01-01 00:00:00')
               AND (b.rechnung_id IS NULL OR b.rechnung_id = 0)
             ORDER BY b.timestampBezahlung ASC";
    $pRes = ff_db_query($conn, $pSql, 'i', [(int)$tischnummer]);
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
    $insR = "INSERT INTO rechnungen (rechnungsnummer, created_by, fest_id, tischnummer, order_nr, total, lines_json, gedruckt, druck_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'pending')";
    $okIns = ff_db_query($conn, $insR, 'ssiiids', [
        $rechnungsnummer,
        $username,
        $activeFestId ?: null,
        (int)$tischnummer,
        $firstOrderNr ?: null,
        (float)$total,
        $linesJson ?: '[]',
    ]);
    if (!$okIns) {
        api_error('Fehler beim Erstellen der Rechnung: ' . mysqli_error($conn), 'db_error', 500);
    }

    $rechnungId = ff_db_insert_id();
    [$inRows, $inTypes, $inIds] = ff_db_in_ints($paidRows);
    ff_db_query($conn, "UPDATE bestellungen SET rechnung_id = ? WHERE rowid IN ({$inRows})", 'i' . $inTypes, array_merge([$rechnungId], $inIds));

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
// 3. SUMUP-APP: intent anlegen und erst nach Rückkehr bestätigen
// ---------------------------------------------------------
if ($action === 'sumup_prepare' || $action === 'sumup_confirm') {
    if ($isSammel || $isEhren) {
        api_error('Für diesen Tisch ist keine SumUp-Einzelzahlung möglich.', 'sumup_not_available', 409);
    }
    if (setting_get($conn, 'sumup_open_app_button', '0') !== '1' || trim((string)setting_get($conn, 'sumup_affiliate_key', '')) === '') {
        api_error('SumUp ist nicht aktiviert.', 'sumup_not_configured', 409);
    }

    if ($action === 'sumup_prepare') {
        $rawIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['rowids'] ?? [])), fn($id) => $id > 0)));
        sort($rawIds);
        if ($rawIds === []) {
            api_error('Keine Positionen für SumUp ausgewählt.', 'no_items_selected', 400);
        }
        if (!mysqli_begin_transaction($conn)) {
            api_error('Datenbankfehler bei Transaktionsstart.', 'db_error', 500);
        }
        try {
            if ($paymentKey !== null) {
                $replay = ff_pilot_payment_request_begin(
                    $conn,
                    $paymentKey,
                    $action,
                    $username,
                    $tischnummer,
                    hash('sha256', json_encode(['table' => $tischnummer, 'rowids' => $rawIds]))
                );
                if ($replay !== null) {
                    mysqli_commit($conn);
                    api_response($replay);
                }
            }
            $marks = implode(',', array_fill(0, count($rawIds), '?'));
            $types = 'i' . str_repeat('i', count($rawIds));
            $params = array_merge([$tischnummer], $rawIds);
            $stmt = ff_mysqli_prepare($conn, "SELECT rowid, COALESCE(betrag, 0) AS betrag FROM bestellungen WHERE tischnummer = ? AND `delete` = 0 AND bestellt = 1 AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00') AND rowid IN ({$marks}) FOR UPDATE");
            if (!$stmt) {
                throw new RuntimeException('SumUp-Positionen konnten nicht gesperrt werden.');
            }
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            if (!ff_mysqli_stmt_execute($stmt)) {
                $error = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new RuntimeException('SumUp-Positionen konnten nicht gesperrt werden: ' . $error);
            }
            $res = mysqli_stmt_get_result($stmt);
            $locked = [];
            $amount = 0.0;
            while ($res && ($row = mysqli_fetch_assoc($res))) {
                $locked[] = (int)$row['rowid'];
                $amount += (float)$row['betrag'];
            }
            mysqli_stmt_close($stmt);
            sort($locked);
            $expected = $rawIds;
            sort($expected);
            if ($locked !== $expected || $amount <= 0) {
                throw new RuntimeException('Ausgewählte Positionen sind nicht mehr offen.');
            }
            $intent = bin2hex(random_bytes(32));
            $json = json_encode($locked);
            $insert = ff_mysqli_prepare($conn, 'INSERT INTO pilot_sumup_requests (intent_token, username, tischnummer, rowids_json, amount) VALUES (?, ?, ?, ?, ?)');
            if (!$insert || $json === false) {
                throw new RuntimeException('SumUp-Vorgang konnte nicht gespeichert werden.');
            }
            mysqli_stmt_bind_param($insert, 'ssisd', $intent, $username, $tischnummer, $json, $amount);
            if (!ff_mysqli_stmt_execute($insert)) {
                $error = mysqli_stmt_error($insert);
                mysqli_stmt_close($insert);
                throw new RuntimeException('SumUp-Vorgang konnte nicht gespeichert werden: ' . $error);
            }
            mysqli_stmt_close($insert);
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))) . '/pilot/index.html';
            $response = [
                'ok' => true,
                'intent_token' => $intent,
                'amount' => round($amount, 2),
                'success_url' => $base . '?sumup_intent=' . rawurlencode($intent) . '&sumup_return=success',
                'failure_url' => $base . '?sumup_intent=' . rawurlencode($intent) . '&sumup_return=failed',
            ];
            if ($paymentKey !== null) {
                ff_pilot_payment_request_complete($conn, $paymentKey, $action, $username, $response);
            }
            mysqli_commit($conn);
            api_response($response);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            api_error($e->getMessage(), 'sumup_prepare_failed', 409);
        }
    }

    $intent = trim((string)($data['intent_token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $intent)) {
        api_error('Ungültige SumUp-Bestätigung.', 'invalid_sumup_intent', 400);
    }
    if (!mysqli_begin_transaction($conn)) {
        api_error('Datenbankfehler bei Transaktionsstart.', 'db_error', 500);
    }
    try {
        if ($paymentKey !== null) {
            $replay = ff_pilot_payment_request_begin(
                $conn,
                $paymentKey,
                $action,
                $username,
                $tischnummer,
                hash('sha256', json_encode(['table' => $tischnummer, 'intent' => $intent]))
            );
            if ($replay !== null) {
                mysqli_commit($conn);
                api_response($replay);
            }
        }
        $intentStmt = ff_mysqli_prepare($conn, 'SELECT rowids_json, amount, status FROM pilot_sumup_requests WHERE intent_token = ? AND username = ? AND tischnummer = ? FOR UPDATE');
        if (!$intentStmt) throw new RuntimeException('SumUp-Vorgang konnte nicht gesperrt werden.');
        mysqli_stmt_bind_param($intentStmt, 'ssi', $intent, $username, $tischnummer);
        if (!ff_mysqli_stmt_execute($intentStmt)) {
            $error = mysqli_stmt_error($intentStmt);
            mysqli_stmt_close($intentStmt);
            throw new RuntimeException('SumUp-Vorgang konnte nicht geladen werden: ' . $error);
        }
        $intentRes = mysqli_stmt_get_result($intentStmt);
        $intentRow = $intentRes ? mysqli_fetch_assoc($intentRes) : null;
        mysqli_stmt_close($intentStmt);
        if (!$intentRow) throw new RuntimeException('SumUp-Vorgang wurde nicht gefunden.');
        if ($intentRow['status'] === 'confirmed') {
            $response = ['ok' => true, 'idempotent_replay' => true, 'total' => round((float)$intentRow['amount'], 2), 'message' => 'SumUp-Zahlung war bereits bestätigt.'];
            if ($paymentKey !== null) {
                ff_pilot_payment_request_complete($conn, $paymentKey, $action, $username, $response);
            }
            mysqli_commit($conn);
            api_response($response);
        }
        $ids = json_decode((string)$intentRow['rowids_json'], true);
        if (!is_array($ids) || $ids === []) throw new RuntimeException('SumUp-Vorgang enthält keine Positionen.');
        $ids = array_map('intval', $ids);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $types = 'i' . str_repeat('i', count($ids));
        $params = array_merge([$tischnummer], $ids);
        $check = ff_mysqli_prepare($conn, "SELECT rowid FROM bestellungen WHERE tischnummer = ? AND `delete` = 0 AND bestellt = 1 AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00') AND rowid IN ({$marks}) FOR UPDATE");
        if (!$check) throw new RuntimeException('SumUp-Positionen konnten nicht gesperrt werden.');
        mysqli_stmt_bind_param($check, $types, ...$params);
        if (!ff_mysqli_stmt_execute($check)) {
            $error = mysqli_stmt_error($check);
            mysqli_stmt_close($check);
            throw new RuntimeException('SumUp-Positionen konnten nicht geprüft werden: ' . $error);
        }
        $checkRes = mysqli_stmt_get_result($check);
        $count = $checkRes ? mysqli_num_rows($checkRes) : 0;
        mysqli_stmt_close($check);
        if ($count !== count($ids)) throw new RuntimeException('Eine SumUp-Position wurde bereits anders abgerechnet.');
        $update = ff_mysqli_prepare($conn, "UPDATE bestellungen SET timestampBezahlung = CURRENT_TIMESTAMP, kellnerZahlung = ? WHERE rowid IN ({$marks})");
        if (!$update) throw new RuntimeException('SumUp-Zahlung konnte nicht vorbereitet werden.');
        mysqli_stmt_bind_param($update, 's' . str_repeat('i', count($ids)), $username, ...$ids);
        if (!ff_mysqli_stmt_execute($update) || mysqli_stmt_affected_rows($update) !== count($ids)) {
            $error = mysqli_stmt_error($update);
            mysqli_stmt_close($update);
            throw new RuntimeException('SumUp-Zahlung konnte nicht gespeichert werden: ' . $error);
        }
        mysqli_stmt_close($update);
        $done = ff_mysqli_prepare($conn, "UPDATE pilot_sumup_requests SET status = 'confirmed', confirmed_at = NOW() WHERE intent_token = ?");
        if (!$done) throw new RuntimeException('SumUp-Status konnte nicht vorbereitet werden.');
        mysqli_stmt_bind_param($done, 's', $intent);
        if (!ff_mysqli_stmt_execute($done) || mysqli_stmt_affected_rows($done) !== 1) {
            $error = mysqli_stmt_error($done);
            mysqli_stmt_close($done);
            throw new RuntimeException('SumUp-Status konnte nicht gespeichert werden: ' . $error);
        }
        mysqli_stmt_close($done);
        $response = ['ok' => true, 'total' => round((float)$intentRow['amount'], 2), 'message' => 'SumUp-Zahlung erfolgreich bestätigt.'];
        if ($paymentKey !== null) {
            ff_pilot_payment_request_complete($conn, $paymentKey, $action, $username, $response);
        }
        mysqli_commit($conn);
        api_response($response);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        api_error($e->getMessage(), 'sumup_confirm_failed', 409);
    }
}

// ---------------------------------------------------------
// 4. REGULÄRES BEZAHLEN (BAR / MIT RECHNUNG)
// ---------------------------------------------------------
$rawIds = (array)($data['rowids'] ?? []);
$rowids = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn($id) => $id > 0)));
sort($rowids);

if ($rowids === []) {
    api_error('Keine Positionen zum Bezahlen ausgewählt.', 'no_items_selected', 400);
}

// Sammelrechnung-Schutz: darf nicht einzeln bezahlt werden
if ($isSammel) {
    api_error('Dieser Tisch ist als Sammelrechnung markiert und kann nur zentral abgerechnet werden.', 'sammelrechnung_locked', 409);
}

// Schema-Prüfungen VOR der Transaktion: ein ALTER TABLE (nur falls eine Spalte fehlt)
// würde sonst die Zahlung mittendrin implizit festschreiben.
if ($action === 'with_invoice') {
    ff_rechnungen_ensure_extra_columns($conn);
    ff_bestellungen_ensure_rechnung_id_column($conn);
    ff_feste_ensure_rechnung_prefix_column($conn);
}

// In Transaktion abwickeln
if (!mysqli_begin_transaction($conn)) {
    api_error('Datenbankfehler bei Transaktionsstart.', 'db_error', 500);
}

try {
    if ($paymentKey !== null) {
        $replay = ff_pilot_payment_request_begin(
            $conn,
            $paymentKey,
            $action,
            $username,
            $tischnummer,
            hash('sha256', json_encode(['table' => $tischnummer, 'rowids' => $rowids]))
        );
        if ($replay !== null) {
            mysqli_commit($conn);
            api_response($replay);
        }
    }

    $payRes = ff_payment_pay_rows(
        $conn,
        (int) $tischnummer,
        $rowids,
        $username,
        $action === 'with_invoice',
        $activeFestId,
        (array) ($data['recipient'] ?? [])
    );
    $affected = $payRes['affected'];
    $totalAmount = $payRes['total'];
    $rechnungId = $payRes['rechnung_id'];
    $rechnungsnummer = $payRes['rechnungsnummer'];
    $linesJson = $payRes['lines_json'];
    $firstOrderNr = $payRes['first_order_nr'];
    $response = [
        'ok' => true,
        'affected' => $affected,
        'total' => round($totalAmount, 2),
        'rechnung_id' => $rechnungId,
        'rechnungsnummer' => $rechnungsnummer,
        'message' => $rechnungsnummer
            ? "Zahlung verbucht und Rechnung {$rechnungsnummer} erstellt."
            : "Zahlung in Höhe von " . number_format($totalAmount, 2, ',', '.') . " € verbucht.",
    ];
    if ($paymentKey !== null) {
        ff_pilot_payment_request_complete($conn, $paymentKey, $action, $username, $response);
    }
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

    api_response($response);

} catch (Throwable $e) {
    mysqli_rollback($conn);
    api_error($e->getMessage(), 'payment_failed', 500);
}
