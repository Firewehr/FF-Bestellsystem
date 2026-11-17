<?php
declare(strict_types=1);

/**
 * Schreibende Kernabläufe für Küche/Schank: fertig melden, abschließen, Teillieferung, wieder öffnen.
 *
 * Nur Datenbank-Änderungen. Station-Events, Druck-Wecksignal, Push-Auswertung und Auto-Druck-Nachrücken
 * lösen die Endpunkte danach selbst aus (bestellung_abschliessen.php, kueche_fertig.php,
 * station_teillieferung_druck.php, api/v1/station.php). So sind die Abläufe in den Selbsttests prüfbar.
 *
 * Transaktionen: Abschließen besteht aus mehreren Anweisungen und läuft über ff_order_close_rows_tx()
 * (BEGIN … FOR UPDATE … COMMIT). Fertig melden, zurücknehmen, Teillieferung und wieder öffnen sind je
 * ein einzelnes UPDATE mit Bedingungen; InnoDB führt das atomar aus und prüft die Bedingungen unter der
 * Zeilensperre neu, eine eigene Transaktion bringt dort nichts.
 *
 * Voraussetzung: include/db.php ist geladen (ff_db_query, ff_db_in_ints).
 */

if (!function_exists('ff_order_rowids')) {
    /**
     * Positive, eindeutige Zeilen-IDs in Eingabereihenfolge.
     *
     * @param array<int|string, mixed> $raw
     * @return int[]
     */
    function ff_order_rowids(array $raw): array
    {
        $ids = [];
        foreach ($raw as $v) {
            $n = (int) $v;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
        return array_values($ids);
    }

    /**
     * Druckziele der Zeilen (Zeile → Position → Küche 11).
     *
     * @param int[] $rowids
     * @return int[]
     */
    function ff_order_rows_print_targets(mysqli $conn, array $rowids, ?int $tischnummer = null): array
    {
        if ($rowids === []) {
            return [];
        }
        [$in, $inT, $inV] = ff_db_in_ints($rowids);
        $sql = 'SELECT DISTINCT COALESCE(b.print_target, p.print_target, 11) AS pt FROM bestellungen b '
            . 'JOIN positionen p ON p.rowid = b.position WHERE b.rowid IN (' . $in . ')';
        if ($tischnummer !== null) {
            $sql .= ' AND b.tischnummer = ?';
            $inT .= 'i';
            $inV[] = $tischnummer;
        }
        $res = ff_db_query($conn, $sql, $inT, $inV);
        $targets = [];
        if (!$res) {
            error_log('ff_order_rows_print_targets: Druckziele konnten nicht ermittelt werden: ' . mysqli_error($conn));
            return [];
        }
        while ($r = mysqli_fetch_assoc($res)) {
            $targets[] = (int) $r['pt'];
        }
        mysqli_free_result($res);
        return $targets;
    }

    /**
     * Position(en) als fertig melden (rechts anzeigen), NICHT ausliefern; print=0 (Bon erst beim Abschließen).
     *
     * @param int[] $rowids
     * @param bool  $onlyOpen true: nur nicht gelöschte, nicht ausgelieferte Zeilen (Station-API);
     *                        false: ohne Filter wie kueche_fertig.php
     */
    function ff_order_mark_ready(mysqli $conn, array $rowids, bool $onlyOpen = true): bool
    {
        if ($rowids === []) {
            return false;
        }
        [$in, $inT, $inV] = ff_db_in_ints($rowids);
        $sql = 'UPDATE bestellungen SET zeitKueche = CURRENT_TIMESTAMP, kueche = 1, print = 0 WHERE rowid IN (' . $in . ')'
            . ($onlyOpen ? ' AND `delete` = 0 AND ausgeliefert = 0' : '');
        return (bool) ff_db_query($conn, $sql, $inT, $inV);
    }

    /**
     * Fertig-Meldung zurücknehmen: Position(en) wieder offen in Küche/Schank.
     *
     * @param int[] $rowids
     */
    function ff_order_undo_ready(mysqli $conn, array $rowids): bool
    {
        if ($rowids === []) {
            return false;
        }
        [$in, $inT, $inV] = ff_db_in_ints($rowids);
        $printStatusSql = '';
        $chk = ff_mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'print_status'");
        if ($chk && mysqli_num_rows($chk) > 0) {
            $printStatusSql = ', print_status = 0';
        }
        if ($chk) {
            mysqli_free_result($chk);
        }
        $sql = "UPDATE bestellungen SET zeitKueche = '0000-00-00 00:00:00', kueche = 0, print = 0" . $printStatusSql
            . ' WHERE `delete` = 0 AND rowid IN (' . $in . ')';
        return (bool) ff_db_query($conn, $sql, $inT, $inV);
    }

    /**
     * Bestellung abschließen: Zeilen als fertig und ausgeliefert markieren.
     *
     * mit Druck: bestellt=1, kueche=1, print=2, print_status=0 + Eintrag in `print` → Thermo-Client
     *            (print_target.php). Schon per Teillieferung gedruckte Zeilen (print_status=1) werden
     *            nur ausgeliefert, nicht erneut in die Druck-Queue gestellt.
     * ohne Druck: nur ausliefern; $setOrderedWithoutPrint setzt zusätzlich bestellt=1, kueche=1
     *            (Station-API). bestellung_abschliessen.php setzt bestellt dabei nicht.
     *
     * Sperrt die Zeilen mit FOR UPDATE und schließt nur Zeilen ab, die noch nicht ausgeliefert sind.
     * Ein zweites Abschließen derselben Zeilen (Doppelklick, zwei Geräte) ändert nichts mehr und stellt
     * keinen zweiten Bon in die Queue. Die Sperre wirkt nur innerhalb einer Transaktion: Endpunkte
     * rufen ff_order_close_rows_tx() auf; die Selbsttests laufen ohnehin in einer Transaktion.
     *
     * @param int[] $rowids
     * @return array{closed:int, printed_rowids:int[]}
     * @throws RuntimeException bei einem Datenbankfehler (Aufrufer rollt zurück)
     */
    function ff_order_close_rows(mysqli $conn, array $rowids, bool $mitDruck, bool $setOrderedWithoutPrint = false): array
    {
        $rowids = ff_order_rowids($rowids);
        if ($rowids === []) {
            return ['closed' => 0, 'printed_rowids' => []];
        }
        [$in, $inT, $inV] = ff_db_in_ints($rowids);

        $lock = ff_db_query(
            $conn,
            "SELECT rowid, COALESCE(print_status, 0) AS ps FROM bestellungen WHERE rowid IN ($in) AND ausgeliefert = 0 FOR UPDATE",
            $inT,
            $inV
        );
        if (!$lock) {
            throw new RuntimeException('Positionen konnten nicht gesperrt werden: ' . mysqli_error($conn));
        }
        $openIds = [];
        $alreadyPrinted = [];
        while ($r = mysqli_fetch_assoc($lock)) {
            $id = (int) $r['rowid'];
            $openIds[$id] = $id;
            if ((int) $r['ps'] === 1) {
                $alreadyPrinted[$id] = true;
            }
        }
        mysqli_free_result($lock);
        // Eingabereihenfolge beibehalten (Reihenfolge der print-Einträge)
        $openIds = array_values(array_filter($rowids, static fn (int $id): bool => isset($openIds[$id])));
        if ($openIds === []) {
            return ['closed' => 0, 'printed_rowids' => []];
        }

        $run = static function (string $sql, string $types, array $params) use ($conn): void {
            if (!ff_db_query($conn, $sql, $types, $params)) {
                throw new RuntimeException('Abschließen fehlgeschlagen: ' . mysqli_error($conn));
            }
        };

        [$in, $inT, $inV] = ff_db_in_ints($openIds);
        // Offene Positionen (Ein-Klick) ebenfalls als fertig markieren; vorhandene Fertig-Zeit bleibt
        $run(
            "UPDATE bestellungen SET kueche = 1, zeitKueche = IF(zeitKueche IS NULL OR zeitKueche IN ('0000-00-00 00:00:00','1970-01-01 00:00:00'), CURRENT_TIMESTAMP, zeitKueche) WHERE rowid IN ($in)",
            $inT,
            $inV
        );

        $printedRowIds = [];
        if ($mitDruck) {
            $toPrint = array_values(array_filter($openIds, static fn (int $id): bool => !isset($alreadyPrinted[$id])));
            $skipPrint = array_values(array_filter($openIds, static fn (int $id): bool => isset($alreadyPrinted[$id])));
            if ($skipPrint !== []) {
                [$inS, $inST, $inSV] = ff_db_in_ints($skipPrint);
                $run("UPDATE bestellungen SET ausgeliefert = 1, timestampAuslieferung = CURRENT_TIMESTAMP, bestellt = 1, kueche = 1 WHERE rowid IN ($inS)", $inST, $inSV);
            }
            if ($toPrint !== []) {
                [$inP, $inPT, $inPV] = ff_db_in_ints($toPrint);
                $run("UPDATE bestellungen SET ausgeliefert = 1, timestampAuslieferung = CURRENT_TIMESTAMP, bestellt = 1, kueche = 1, print = 2, print_status = 0 WHERE rowid IN ($inP)", $inPT, $inPV);
                $timestamp = date('Y-m-d H:i:s');
                foreach ($toPrint as $rowid) {
                    $run('INSERT INTO print (bestellungID, timestamp) VALUES (?, ?)', 'is', [$rowid, $timestamp]);
                }
                $printedRowIds = $toPrint;
            }
        } else {
            $setSql = $setOrderedWithoutPrint
                ? 'ausgeliefert = 1, timestampAuslieferung = CURRENT_TIMESTAMP, bestellt = 1, kueche = 1'
                : 'ausgeliefert = 1, timestampAuslieferung = CURRENT_TIMESTAMP';
            $run("UPDATE bestellungen SET {$setSql} WHERE rowid IN ($in)", $inT, $inV);
        }

        return ['closed' => count($openIds), 'printed_rowids' => $printedRowIds];
    }

    /**
     * ff_order_close_rows() in einer eigenen Transaktion (BEGIN … FOR UPDATE … COMMIT).
     * Station-Events, Druck-Wecksignal und Auto-Druck löst der Aufrufer erst danach aus.
     *
     * @param int[] $rowids
     * @return array{closed:int, printed_rowids:int[]}
     * @throws RuntimeException wenn die Transaktion nicht durchläuft (dann ist nichts geändert)
     */
    function ff_order_close_rows_tx(mysqli $conn, array $rowids, bool $mitDruck, bool $setOrderedWithoutPrint = false): array
    {
        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException('Transaktion konnte nicht gestartet werden: ' . mysqli_error($conn));
        }
        try {
            $res = ff_order_close_rows($conn, $rowids, $mitDruck, $setOrderedWithoutPrint);
            if (!mysqli_commit($conn)) {
                throw new RuntimeException('Abschließen konnte nicht gespeichert werden: ' . mysqli_error($conn));
            }
            return $res;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            throw $e;
        }
    }

    /**
     * Teillieferung drucken: fertige, noch ungedruckte, nicht ausgelieferte Zeilen → print=3
     * (print_target.php druckt sie, obwohl andere Positionen der Bestellung noch offen sind).
     *
     * @param int[] $rowids
     * @return int Anzahl markierter Zeilen, -1 bei DB-Fehler
     */
    function ff_order_print_partial(mysqli $conn, array $rowids, ?int $tischnummer = null): int
    {
        if ($rowids === []) {
            return 0;
        }
        [$in, $inT, $inV] = ff_db_in_ints($rowids);
        $sql = 'UPDATE bestellungen SET print = 3, print_status = 0, kueche = 1 '
            . 'WHERE rowid IN (' . $in . ') '
            . ($tischnummer !== null ? 'AND tischnummer = ? ' : '')
            . 'AND `delete` = 0 AND ausgeliefert = 0 AND COALESCE(print_status, 0) = 0 '
            . "AND (kueche = 1 OR (zeitKueche IS NOT NULL AND zeitKueche NOT IN ('0000-00-00 00:00:00', '1970-01-01 00:00:00')))";
        if ($tischnummer !== null) {
            $inT .= 'i';
            $inV[] = $tischnummer;
        }
        if (!ff_db_query($conn, $sql, $inT, $inV)) {
            return -1;
        }
        return ff_db_affected_rows();
    }

    /**
     * Abgeschlossene Bestellung wieder auf die Station setzen (Undo aus der Historie).
     *
     * @param int[] $rowids
     */
    function ff_order_reopen_rows(mysqli $conn, array $rowids): bool
    {
        if ($rowids === []) {
            return false;
        }
        [$in, $inT, $inV] = ff_db_in_ints($rowids);
        $sql = "UPDATE bestellungen SET ausgeliefert = 0, timestampAuslieferung = '0000-00-00 00:00:00', kueche = 1, zeitKueche = CURRENT_TIMESTAMP WHERE rowid IN ($in) AND `delete` = 0";
        return (bool) ff_db_query($conn, $sql, $inT, $inV);
    }
}
