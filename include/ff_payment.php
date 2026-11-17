<?php
declare(strict_types=1);

/**
 * Tischzahlung (Kellner-App, api/v1/pay.php): ausgewählte Positionen bezahlen, optional mit Rechnung.
 *
 * Läuft innerhalb der Transaktion des Aufrufers (BEGIN/COMMIT/ROLLBACK macht der Aufrufer) und sperrt
 * die Zeilen mit FOR UPDATE. Bei jedem Problem wird eine RuntimeException geworfen; der Aufrufer rollt
 * dann zurück. Thermo-Druck und Push-Auswertung löst der Aufrufer erst nach dem COMMIT aus.
 *
 * Wichtig: ff_rechnungen_ensure_extra_columns() / ff_bestellungen_ensure_rechnung_id_column() vor dem
 * BEGIN aufrufen. Fehlt dort eine Spalte, würde das ALTER TABLE die Transaktion vorzeitig festschreiben.
 *
 * Voraussetzung: include/db.php, include/ff_rechnung_seq.php sind geladen.
 */

if (!function_exists('ff_payment_pay_rows')) {
    /**
     * @param int[] $rowids     genau diese Zeilen müssen offen und am Tisch sein, sonst Abbruch
     * @param array{is_firma?:mixed,name?:string,strasse?:string,plz?:string,ort?:string,uid?:string} $recipient
     * @return array{affected:int,total:float,rechnung_id:?int,rechnungsnummer:?string,lines_json:string,first_order_nr:?int}
     */
    function ff_payment_pay_rows(
        mysqli $conn,
        int $tischnummer,
        array $rowids,
        string $username,
        bool $withInvoice,
        ?int $activeFestId,
        array $recipient = []
    ): array {
        $rowids = array_values(array_unique(array_filter(array_map('intval', $rowids), static fn (int $id): bool => $id > 0)));
        if ($rowids === []) {
            throw new RuntimeException('Keine Positionen zum Bezahlen ausgewählt.');
        }

        [$marks, $markTypes, $markIds] = ff_db_in_ints($rowids);
        $sumStmt = ff_mysqli_prepare(
            $conn,
            "SELECT b.rowid, b.position, COALESCE(b.betrag, p.Betrag) AS betrag, b.Zusatzinfo, b.order_nr,
                    COALESCE(p.Positionsname, '(Position)') AS name
             FROM bestellungen b LEFT JOIN positionen p ON p.rowid = b.position
             WHERE b.`delete` = 0 AND b.tischnummer = ?
               AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung = '0000-00-00 00:00:00')
               AND b.rowid IN ({$marks}) FOR UPDATE"
        );
        if (!$sumStmt) {
            throw new RuntimeException('Zahlungspositionen konnten nicht gesperrt werden.');
        }
        mysqli_stmt_bind_param($sumStmt, 'i' . $markTypes, $tischnummer, ...$markIds);
        if (!ff_mysqli_stmt_execute($sumStmt)) {
            $error = mysqli_stmt_error($sumStmt);
            mysqli_stmt_close($sumStmt);
            throw new RuntimeException('Zahlungspositionen konnten nicht geladen werden: ' . $error);
        }
        $sRes = mysqli_stmt_get_result($sumStmt);
        $payableRows = [];
        $totalAmount = 0.0;
        $groupedLines = [];
        $firstOrderNr = null;
        while ($sRes && ($sr = mysqli_fetch_assoc($sRes))) {
            $payableRows[] = (int) $sr['rowid'];
            $bVal = (float) $sr['betrag'];
            $totalAmount += $bVal;
            if ($firstOrderNr === null && !empty($sr['order_nr'])) {
                $firstOrderNr = (int) $sr['order_nr'];
            }
            $gKey = (string) $sr['name'] . '|' . (string) $sr['Zusatzinfo'] . '|' . number_format($bVal, 2, '.', '');
            if (!isset($groupedLines[$gKey])) {
                $groupedLines[$gKey] = ['cnt' => 0, 'name' => (string) $sr['name'], 'betrag' => $bVal, 'zusatzinfo' => (string) $sr['Zusatzinfo']];
            }
            $groupedLines[$gKey]['cnt']++;
        }
        mysqli_stmt_close($sumStmt);
        sort($payableRows);
        $expectedRows = $rowids;
        sort($expectedRows);
        if ($payableRows === [] || $payableRows !== $expectedRows) {
            throw new RuntimeException('Die ausgewählten Positionen wurden bereits abgerechnet oder existieren nicht mehr.');
        }

        $rechnungId = null;
        $rechnungsnummer = null;
        $linesJson = '';

        if ($withInvoice) {
            $rPrefix = ff_rechnung_prefix_for_fest($conn, $activeFestId);
            $seq = ff_rechnung_next_allocate($conn);
            $rechnungsnummer = $rPrefix . $seq;

            $linesJson = (string) json_encode(array_values($groupedLines), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $isFirma = !empty($recipient['is_firma']) ? 1 : 0;

            $insR = "INSERT INTO rechnungen
                     (rechnungsnummer, created_by, fest_id, tischnummer, order_nr, total, lines_json, gedruckt, druck_status, is_firma, empfaenger_name, empfaenger_strasse, empfaenger_plz, empfaenger_ort, empfaenger_uid)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'pending', ?, ?, ?, ?, ?, ?)";
            $okIns = ff_db_query($conn, $insR, 'ssiiidsisssss', [
                $rechnungsnummer,
                $username,
                $activeFestId ?: null,
                $tischnummer,
                $firstOrderNr ?: null,
                $totalAmount,
                $linesJson !== '' ? $linesJson : '[]',
                $isFirma,
                (string) ($recipient['name'] ?? ''),
                (string) ($recipient['strasse'] ?? ''),
                (string) ($recipient['plz'] ?? ''),
                (string) ($recipient['ort'] ?? ''),
                (string) ($recipient['uid'] ?? ''),
            ]);
            if (!$okIns) {
                throw new RuntimeException('Rechnung konnte nicht angelegt werden: ' . mysqli_error($conn));
            }
            $rechnungId = ff_db_insert_id();
        }

        // Positionen als bezahlt markieren
        [$inPayMarks, $inPayTypes, $inPayIds] = ff_db_in_ints($payableRows);
        $updSql = 'UPDATE `bestellungen` SET `timestampBezahlung` = CURRENT_TIMESTAMP, `kellnerZahlung` = ?'
            . ($rechnungId ? ', `rechnung_id` = ? ' : ' ')
            . "WHERE `rowid` IN ({$inPayMarks})";
        $updTypes = 's' . ($rechnungId ? 'i' : '') . $inPayTypes;
        $updParams = array_merge([$username], $rechnungId ? [$rechnungId] : [], $inPayIds);
        if (!ff_db_query($conn, $updSql, $updTypes, $updParams)) {
            throw new RuntimeException('Bezahlung konnte nicht gespeichert werden: ' . mysqli_error($conn));
        }

        return [
            'affected' => ff_db_affected_rows(),
            'total' => $totalAmount,
            'rechnung_id' => $rechnungId,
            'rechnungsnummer' => $rechnungsnummer,
            'lines_json' => $linesJson,
            'first_order_nr' => $firstOrderNr,
        ];
    }
}
