<?php
declare(strict_types=1);

/**
 * Schreibende Kernabläufe für Küche/Schank: fertig melden, abschließen, Teillieferung, wieder öffnen.
 *
 * Nur Datenbank-Änderungen. Station-Events, Druck-Wecksignal, Push-Auswertung und Auto-Druck-Nachrücken
 * lösen die Endpunkte danach selbst aus (bestellung_abschliessen.php, kueche_fertig.php,
 * station_teillieferung_druck.php, api/v1/station.php). So sind die Abläufe in den Selbsttests prüfbar
 * und lassen sich später in eine Transaktion legen.
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
        $chk = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'print_status'");
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
     * @param int[] $rowids
     * @return array{closed:int, printed_rowids:int[]}
     */
    function ff_order_close_rows(mysqli $conn, array $rowids, bool $mitDruck, bool $setOrderedWithoutPrint = false): array
    {
        if ($rowids === []) {
            return ['closed' => 0, 'printed_rowids' => []];
        }
        [$in, $inT, $inV] = ff_db_in_ints($rowids);

        // Offene Positionen (Ein-Klick) ebenfalls als fertig markieren; vorhandene Fertig-Zeit bleibt
        ff_db_query(
            $conn,
            "UPDATE bestellungen SET kueche = 1, zeitKueche = IF(zeitKueche IS NULL OR zeitKueche IN ('0000-00-00 00:00:00','1970-01-01 00:00:00'), CURRENT_TIMESTAMP, zeitKueche) WHERE rowid IN ($in)",
            $inT,
            $inV
        );

        $printedRowIds = [];
        if ($mitDruck) {
            $timestamp = date('Y-m-d H:i:s');
            foreach ($rowids as $rowid) {
                $chk = ff_db_query($conn, 'SELECT COALESCE(print_status, 0) AS ps FROM bestellungen WHERE rowid = ? LIMIT 1', 'i', [$rowid]);
                $alreadyPrinted = false;
                if ($chk && ($cr = mysqli_fetch_assoc($chk))) {
                    $alreadyPrinted = ((int) ($cr['ps'] ?? 0) === 1);
                }
                if ($alreadyPrinted) {
                    ff_db_query($conn, 'UPDATE bestellungen SET ausgeliefert = 1, timestampAuslieferung = CURRENT_TIMESTAMP, bestellt = 1, kueche = 1 WHERE rowid = ?', 'i', [$rowid]);
                } else {
                    ff_db_query($conn, 'UPDATE bestellungen SET ausgeliefert = 1, timestampAuslieferung = CURRENT_TIMESTAMP, bestellt = 1, kueche = 1, print = 2, print_status = 0 WHERE rowid = ?', 'i', [$rowid]);
                    ff_db_query($conn, 'INSERT INTO print (bestellungID, timestamp) VALUES (?, ?)', 'is', [$rowid, $timestamp]);
                    $printedRowIds[] = $rowid;
                }
            }
        } else {
            $set = $setOrderedWithoutPrint
                ? 'ausgeliefert = 1, timestampAuslieferung = CURRENT_TIMESTAMP, bestellt = 1, kueche = 1'
                : 'ausgeliefert = 1, timestampAuslieferung = CURRENT_TIMESTAMP';
            ff_db_query($conn, "UPDATE bestellungen SET {$set} WHERE rowid IN ($in)", $inT, $inV);
        }

        return ['closed' => count($rowids), 'printed_rowids' => $printedRowIds];
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
