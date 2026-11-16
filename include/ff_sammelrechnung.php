<?php
declare(strict_types=1);

require_once __DIR__ . '/ff_schema_gate.php';

/**
 * Sammelrechnung bezahlen (SammelrechnungBezahlt.php): Positionen mehrerer Sammelrechnungs-Tische
 * einer neuen Sammelrechnung zuordnen, als bezahlt markieren und die Tische freigeben.
 *
 * ff_sammelrechnung_pay() läuft innerhalb der Transaktion des Aufrufers (BEGIN/COMMIT/ROLLBACK macht
 * der Aufrufer) und sperrt Tische und Positionen mit FOR UPDATE. So kann dieselbe Sammelrechnung nicht
 * zweimal bezahlt werden (Doppelklick, zwei Geräte): Die zweite Anfrage wartet auf die Sperre und
 * findet danach bezahlte Positionen bzw. einen freigegebenen Tisch vor.
 *
 * ff_sammelrechnung_ensure_schema() vor dem BEGIN aufrufen: Ein ALTER TABLE würde die Transaktion
 * vorzeitig festschreiben.
 *
 * Voraussetzung: include/db.php, include/ff_schema_helpers.php sind geladen.
 */

if (!class_exists('FfSammelrechnungError')) {
    /** Fachlicher Abbruch mit HTTP-Status und Fehlerschlüssel für die JSON-Antwort. */
    class FfSammelrechnungError extends RuntimeException
    {
        /** @var string */
        public $errorKey;

        public function __construct(int $httpCode, string $errorKey, string $message)
        {
            parent::__construct($message, $httpCode);
            $this->errorKey = $errorKey;
        }
    }
}

if (!function_exists('ff_sammelrechnung_pay')) {
    /** Spalten von `sammelrechnungen` nachziehen (war seit Commit 3c55c6c teils entfernt). */
    function ff_sammelrechnung_ensure_schema(mysqli $conn): void
    {
        if (ff_schema_is_current($conn)) {
            return; // Schema laut Migrationslauf aktuell (include/ff_schema_gate.php)
        }
        ff_ensure_table($conn, 'sammelrechnungen', 'CREATE TABLE IF NOT EXISTS sammelrechnungen (id INT AUTO_INCREMENT PRIMARY KEY)');
        ff_ensure_column($conn, 'sammelrechnungen', 'created_at', 'ALTER TABLE sammelrechnungen ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        ff_ensure_column($conn, 'sammelrechnungen', 'created_by', 'ALTER TABLE sammelrechnungen ADD COLUMN created_by VARCHAR(255) NULL DEFAULT NULL');
        ff_ensure_column($conn, 'sammelrechnungen', 'tables_text', 'ALTER TABLE sammelrechnungen ADD COLUMN tables_text TEXT NULL');
        ff_ensure_column($conn, 'sammelrechnungen', 'total_amount', 'ALTER TABLE sammelrechnungen ADD COLUMN total_amount DECIMAL(10,2) NULL DEFAULT 0.00');
        ff_ensure_column($conn, 'sammelrechnungen', 'umsatz_zustaendig', 'ALTER TABLE sammelrechnungen ADD COLUMN umsatz_zustaendig VARCHAR(255) NULL DEFAULT NULL');
        ff_ensure_column($conn, 'sammelrechnungen', 'bezahlt', 'ALTER TABLE sammelrechnungen ADD COLUMN bezahlt TINYINT(1) NOT NULL DEFAULT 0');
        ff_ensure_column($conn, 'sammelrechnungen', 'bezahlt_at', 'ALTER TABLE sammelrechnungen ADD COLUMN bezahlt_at TIMESTAMP NULL DEFAULT NULL');
        ff_ensure_column($conn, 'bestellungen', 'sammelrechnung_id', 'ALTER TABLE bestellungen ADD COLUMN sammelrechnung_id INT NULL');
        ff_ensure_column($conn, 'tische', 'is_sammelrechnung', 'ALTER TABLE tische ADD COLUMN is_sammelrechnung TINYINT(1) NOT NULL DEFAULT 0');
    }

    /** Hat die Tabelle sammelrechnungen die Spalte? */
    function ff_sammelrechnung_has_column(mysqli $conn, string $name): bool
    {
        static $cache = [];
        if (!array_key_exists($name, $cache)) {
            $res = ff_db_query(
                $conn,
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                'ss',
                ['sammelrechnungen', $name]
            );
            $cache[$name] = $res instanceof mysqli_result && mysqli_num_rows($res) > 0;
        }
        return $cache[$name];
    }

    /**
     * @param int[] $rows   Bestellzeilen (rowid)
     * @param int[] $tables Tischnummern, alle als Sammelrechnung gekennzeichnet
     * @return array{sammelrechnung_id:int, affected:int, total:float}
     * @throws FfSammelrechnungError bei fachlichem Abbruch oder DB-Fehler (Aufrufer rollt zurück)
     */
    function ff_sammelrechnung_pay(mysqli $conn, array $rows, array $tables, string $umsatzUser, string $createdBy): array
    {
        $positive = static fn (array $ids): array => array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $rows = $positive($rows);
        $tables = $positive($tables);
        if ($rows === [] || $tables === []) {
            throw new FfSammelrechnungError(400, 'missing', 'Keine Positionen oder Tische übermittelt. Bitte Seite neu laden.');
        }
        $umsatzUser = trim($umsatzUser);
        if ($umsatzUser === '') {
            throw new FfSammelrechnungError(400, 'missing_zustaendig', 'Bitte einen Benutzer für die Umsatz-Zuordnung wählen.');
        }
        $uq = ff_db_query($conn, 'SELECT username FROM users WHERE username = ? LIMIT 1', 's', [$umsatzUser]);
        if (!$uq || !mysqli_fetch_assoc($uq)) {
            throw new FfSammelrechnungError(400, 'invalid_user', 'Ungültiger Benutzer.');
        }

        // 1. Tische sperren: alle müssen (noch) als Sammelrechnung gekennzeichnet sein
        [$inTables, $inTablesTypes, $inTablesIds] = ff_db_in_ints($tables);
        $qT = ff_db_query(
            $conn,
            "SELECT tischnummer FROM tische WHERE tischnummer IN ($inTables) AND IFNULL(is_sammelrechnung, 0) = 1 FOR UPDATE",
            $inTablesTypes,
            $inTablesIds
        );
        if (!$qT) {
            throw new FfSammelrechnungError(500, 'db', 'Datenbankfehler (Tische): ' . mysqli_error($conn));
        }
        $allowedTisch = [];
        while ($tr = mysqli_fetch_assoc($qT)) {
            $allowedTisch[(int) $tr['tischnummer']] = true;
        }
        mysqli_free_result($qT);
        foreach ($tables as $tn) {
            if (empty($allowedTisch[$tn])) {
                throw new FfSammelrechnungError(400, 'not_sammelrechnung_tisch', 'Mindestens ein gewählter Tisch ist nicht als Sammelrechnung gekennzeichnet.');
            }
        }

        // 2. Positionen sperren: müssen zu den Tischen gehören, nicht storniert und noch offen sein
        [$inRows, $inRowsTypes, $inRowsIds] = ff_db_in_ints($rows);
        $qR = ff_db_query(
            $conn,
            "SELECT rowid, (timestampBezahlung IS NOT NULL AND timestampBezahlung <> '0000-00-00 00:00:00') AS paid
             FROM bestellungen
             WHERE rowid IN ($inRows) AND tischnummer IN ($inTables) AND `delete` = 0
             FOR UPDATE",
            $inRowsTypes . $inTablesTypes,
            array_merge($inRowsIds, $inTablesIds)
        );
        if (!$qR) {
            throw new FfSammelrechnungError(500, 'db', 'Datenbankfehler (Prüfung): ' . mysqli_error($conn));
        }
        $matchCnt = 0;
        $paidCnt = 0;
        while ($rr = mysqli_fetch_assoc($qR)) {
            $matchCnt++;
            if ((int) $rr['paid'] === 1) {
                $paidCnt++;
            }
        }
        mysqli_free_result($qR);
        if ($matchCnt !== count($rows)) {
            throw new FfSammelrechnungError(400, 'position_tisch_mismatch', 'Nicht alle Positionen gehören zu den gewählten Sammelrechnungs-Tischen. Bitte Seite neu laden.');
        }
        if ($paidCnt > 0) {
            throw new FfSammelrechnungError(409, 'already_paid', 'Mindestens eine Position wurde inzwischen bezahlt. Bitte Seite neu laden.');
        }

        // 3. Summe (Preise aus positionen nur lesen, nicht sperren)
        $resSum = ff_db_query(
            $conn,
            "SELECT SUM(COALESCE(NULLIF(b.betrag, 0), p.Betrag)) AS total
             FROM bestellungen b
             JOIN positionen p ON p.rowid = b.position
             WHERE b.rowid IN ($inRows)",
            $inRowsTypes,
            $inRowsIds
        );
        if (!$resSum) {
            throw new FfSammelrechnungError(500, 'db', 'Datenbankfehler (Summe): ' . mysqli_error($conn));
        }
        $rSum = mysqli_fetch_assoc($resSum);
        $total = round((float) ($rSum['total'] ?? 0), 2);

        // 4. Sammelrechnung anlegen. Spaltennamen aus festen Literalen (Schema-abhängig), Werte als Parameter
        $insCols = [];
        $insValuesSql = []; // nur '?' und feste SQL-Ausdrücke (1, CURRENT_TIMESTAMP)
        $insTypes = '';
        $insParams = [];
        foreach ([['created_by', $createdBy], ['umsatz_zustaendig', $umsatzUser], ['tables_text', implode(',', $tables)]] as [$col, $val]) {
            if (ff_sammelrechnung_has_column($conn, $col)) {
                $insCols[] = $col;
                $insValuesSql[] = '?';
                $insTypes .= 's';
                $insParams[] = $val;
            }
        }
        $amountCol = null;
        if (ff_sammelrechnung_has_column($conn, 'total_amount')) {
            $amountCol = 'total_amount';
        } elseif (ff_sammelrechnung_has_column($conn, 'betrag')) {
            $amountCol = 'betrag';
        }
        if ($amountCol !== null) {
            $insCols[] = $amountCol;
            $insValuesSql[] = '?';
            $insTypes .= 'd';
            $insParams[] = $total;
        }
        if (ff_sammelrechnung_has_column($conn, 'bezahlt')) {
            $insCols[] = 'bezahlt';
            $insValuesSql[] = '1';
        }
        if (ff_sammelrechnung_has_column($conn, 'bezahlt_at')) {
            $insCols[] = 'bezahlt_at';
            $insValuesSql[] = 'CURRENT_TIMESTAMP';
        }
        if ($insCols === []) {
            throw new FfSammelrechnungError(500, 'schema', 'Tabelle sammelrechnungen: keine passenden Spalten (created_by / tables_text / total_amount). Bitte SQL-Migration ausführen.');
        }
        $sqlIns = 'INSERT INTO sammelrechnungen (' . implode(', ', $insCols) . ') VALUES (' . implode(', ', $insValuesSql) . ')';
        if (!ff_db_query($conn, $sqlIns, $insTypes, $insParams)) {
            throw new FfSammelrechnungError(500, 'insert_failed', 'Sammelrechnung konnte nicht gespeichert werden: ' . mysqli_error($conn));
        }
        $sammelId = ff_db_insert_id();
        if ($sammelId <= 0) {
            throw new FfSammelrechnungError(500, 'insert_failed', 'Sammelrechnung-ID fehlt nach dem Speichern.');
        }

        // 5. Positionen bezahlen. Nur Zahlung/Umsatz – kueche/zeitKueche setzt weiterhin nur Küche/Schank
        // („Fertig“), wie bei BestellungBezahlt.php.
        $sqlUpd = "UPDATE bestellungen SET
            timestampBezahlung = CURRENT_TIMESTAMP,
            kellnerZahlung = ?,
            kellner = ?,
            sammelrechnung_id = ?
            WHERE rowid IN ($inRows) AND `delete` = 0";
        if (!ff_db_query($conn, $sqlUpd, 'ssi' . $inRowsTypes, array_merge([$umsatzUser, $umsatzUser, $sammelId], $inRowsIds))) {
            throw new FfSammelrechnungError(500, 'update_failed', 'Bezahlen fehlgeschlagen: ' . mysqli_error($conn));
        }
        $affected = ff_db_affected_rows();
        if ($affected < 1) {
            throw new FfSammelrechnungError(500, 'update_failed', 'Es wurden keine Bestellzeilen aktualisiert. Bitte Seite neu laden.');
        }

        // 6. Tische freigeben
        if (!ff_db_query($conn, "UPDATE tische SET is_sammelrechnung = 0 WHERE tischnummer IN ($inTables)", $inTablesTypes, $inTablesIds)) {
            throw new FfSammelrechnungError(500, 'update_failed', 'Tische konnten nicht freigegeben werden: ' . mysqli_error($conn));
        }

        return ['sammelrechnung_id' => $sammelId, 'affected' => $affected, 'total' => $total];
    }
}
