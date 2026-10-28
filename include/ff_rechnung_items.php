<?php
/**
 * Rechnungspositionen: aus DB-Zeilen (rechnung_id) oder aus Snapshot lines_json.
 */
require_once __DIR__ . '/print_templates.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/user_landing.php';

/**
 * @return array<int, array{cnt:int,name:string,betrag:float}>
 */
function ff_rechnung_load_items_grouped(mysqli $conn, array $rechnungRow): array {
    $rid = (int)($rechnungRow['id'] ?? 0);
    $linesJson = trim((string)($rechnungRow['lines_json'] ?? ''));
    if ($linesJson !== '') {
        $dec = json_decode($linesJson, true);
        if (is_array($dec)) {
            $out = [];
            foreach ($dec as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cnt = max(1, (int)($row['cnt'] ?? 1));
                $name = (string)($row['name'] ?? '');
                $betrag = (float)($row['betrag'] ?? 0);
                $zi = (string)($row['zusatzinfo'] ?? '');
                $out[] = ['cnt' => $cnt, 'name' => $name, 'betrag' => $betrag, 'zusatzinfo' => $zi];
            }
            return $out;
        }
    }

    // Wie am Bon: nach Position + Zusatzinfo gruppieren, damit gleiche Position mit
    // unterschiedlichen Zusatzpositionen (z. B. 5 Schnitzel mit 5 Beilagen) als eigene
    // Zeilen erscheinen.
    $rechnung_id = $rid;
    $ires = mysqli_query($conn, "SELECT p.Kurzbezeichnung, p.Positionsname, p.Betrag, b.Zusatzinfo, COUNT(*) AS cnt
        FROM bestellungen b JOIN positionen p ON p.rowid=b.position
        WHERE b.rechnung_id={$rechnung_id} AND b.delete=0
        GROUP BY b.position, b.Zusatzinfo
        ORDER BY p.Positionsname ASC, b.Zusatzinfo ASC");
    $items = [];
    if ($ires) {
        while ($ir = mysqli_fetch_assoc($ires)) {
            $items[] = [
                'cnt' => (int)$ir['cnt'],
                'name' => (string)($ir['Kurzbezeichnung'] ?: $ir['Positionsname'] ?? ''),
                'betrag' => (float)$ir['Betrag'],
                'zusatzinfo' => (string)($ir['Zusatzinfo'] ?? ''),
            ];
        }
    }
    return $items;
}

/**
 * Thermo-Beleg im Bon-Stil: zentrierter, großer Verkäufer-Header,
 * großer Tischname, dicke Bestell-/Bon-Zeilen, große Summe – jeweils mit
 * ESC/POS-Bytes direkt im Text. Das Python-Druckskript reicht den Text 1:1
 * an den Drucker durch (encode_text ohne Modifikation), der Drucker
 * interpretiert die ESC-Sequenzen wie beim Bon.
 */
function ff_rechnung_build_thermal_text(mysqli $conn, array $rechnungRow): string {
    ff_users_ensure_landing_columns($conn);
    $seller_name = (string)settings_get($conn, 'seller_name', '');
    $seller_address = (string)settings_get($conn, 'seller_address', '');
    $seller_uid = (string)settings_get($conn, 'seller_uid', '');

    $r = $rechnungRow;
    $items = ff_rechnung_load_items_grouped($conn, $r);

    // ESC/POS Steuerbytes (identisch zum Bon-Renderer in print_client.py)
    $ESC_BIG    = "\x1B\x21\x30"; // double height + double width
    $ESC_NORM   = "\x1B\x21\x00"; // back to normal
    $ESC_BOLD_ON  = "\x1B\x45\x01";
    $ESC_BOLD_OFF = "\x1B\x45\x00";
    $ESC_CENTER = "\x1B\x61\x01";
    $ESC_LEFT   = "\x1B\x61\x00";

    // Tischname laden (für die "Tisch: …"-Zeile oben, wie am Bon)
    $tischnummer = (int)($r['tischnummer'] ?? 0);
    $tischname = '';
    if ($tischnummer > 0) {
        $tres = @mysqli_query($conn, "SELECT tischname FROM tische WHERE tischnummer=" . $tischnummer . " LIMIT 1");
        if ($tres && ($trow = mysqli_fetch_assoc($tres))) {
            $tischname = (string)($trow['tischname'] ?? '');
        }
    }

    $lines = [];

    // === Verkäufer-Header zentriert, erste Zeile groß (wie Bon) ===
    $sellerName = trim($seller_name) !== '' ? trim($seller_name) : 'FEUERWEHR';
    $lines[] = $ESC_CENTER . $ESC_BIG . $sellerName . $ESC_NORM;
    foreach (_ff_split_addr_lines($seller_address) as $addrLn) {
        $lines[] = $addrLn;
    }
    if (trim($seller_uid) !== '') {
        $lines[] = 'UID/ZVR: ' . trim($seller_uid);
    }
    $lines[] = $ESC_LEFT;
    $lines[] = '';

    // === Titel "RECHNUNG" (zentriert + groß, wie Tischname am Bon) ===
    $lines[] = $ESC_CENTER . $ESC_BIG . 'RECHNUNG' . $ESC_NORM . $ESC_LEFT;
    $lines[] = '';

    // === Tisch (groß, wie Bon) - falls Rechnung einem Tisch zugeordnet ist ===
    if ($tischnummer > 0 && $tischnummer !== 999999) {
        $tlbl = $tischname !== '' ? $tischname : (string)$tischnummer;
        $lines[] = $ESC_BIG . 'Tisch: ' . mb_strimwidth($tlbl, 0, 13, '', 'UTF-8') . $ESC_NORM;
        $lines[] = '';
    } elseif ($tischnummer === 999999) {
        $lines[] = $ESC_BIG . 'Direktverkauf' . $ESC_NORM;
        $lines[] = '';
    }

    // === Proforma-Marker (falls Kostenübersicht statt Endrechnung) ===
    if (!empty($r['is_proforma'])) {
        $lines[] = '*** KOSTENUEBERSICHT ***';
        $lines[] = '(noch nicht bezahlt)';
        $lines[] = str_repeat('-', 42);
    }

    // === Rechnungs-/Bestellnummer fett (wie Bon-Bestell-Nr.) ===
    $rnr = (string)($r['rechnungsnummer'] ?? '');
    $lines[] = $ESC_BOLD_ON . pt_line('Rechnung', $rnr) . $ESC_BOLD_OFF;
    // Alle verknüpften Bestellnummern auflisten - bei "mit Rechnung bezahlen"
    // können mehrere Bestellungen auf eine Rechnung kommen.
    $onrList = [];
    $rid = (int)($r['id'] ?? 0);
    if ($rid > 0) {
        $resOnr = @mysqli_query(
            $conn,
            'SELECT DISTINCT order_nr FROM bestellungen WHERE rechnung_id=' . $rid
            . ' AND `delete`=0 AND order_nr IS NOT NULL AND order_nr > 0 ORDER BY order_nr ASC'
        );
        if ($resOnr) {
            while ($ro = mysqli_fetch_assoc($resOnr)) {
                $onrList[] = (int)$ro['order_nr'];
            }
        }
    }
    if (count($onrList) === 0) {
        $singleOnr = (int)($r['order_nr'] ?? 0);
        if ($singleOnr > 0) {
            $onrList[] = $singleOnr;
        }
    }
    if (count($onrList) > 0) {
        $onrTxt = implode(', ', array_map('strval', $onrList));
        $lines[] = $ESC_BOLD_ON . pt_line('Bestell-Nr.', $onrTxt) . $ESC_BOLD_OFF;
    }

    // === Kellner / Datum / Druckzeit (wie Bon) ===
    $kellner = ff_user_display_label($conn, (string)($r['created_by'] ?? ''));
    if ($kellner !== '') {
        $lines[] = pt_line('KellnerIn', $kellner);
    }
    $createdRaw = trim((string)($r['created_at'] ?? ''));
    $datumStr = $createdRaw;
    if ($createdRaw !== '') {
        $ts = strtotime($createdRaw);
        if ($ts !== false) {
            $datumStr = date('d.m.Y', $ts);
        }
    }
    $lines[] = pt_line('Datum (Beleg)', $datumStr);
    $lines[] = pt_line('Druckzeit', date('H:i') . ' Uhr');
    $lines[] = '';

    // === Trennlinie ===
    $lines[] = str_repeat('-', 42);

    // === Empfänger (Firmenrechnung) ===
    if ((int)($r['is_firma'] ?? 0) === 1) {
        $lines[] = 'Empfaenger:';
        if (trim((string)($r['empfaenger_name'] ?? '')) !== '') {
            $lines[] = (string)$r['empfaenger_name'];
        }
        $street = trim((string)($r['empfaenger_strasse'] ?? ''));
        if ($street !== '') {
            $lines[] = $street;
        }
        $plz = trim((string)($r['empfaenger_plz'] ?? ''));
        $ort = trim((string)($r['empfaenger_ort'] ?? ''));
        $city = trim(($plz !== '' ? $plz . ' ' : '') . $ort);
        if ($city !== '') {
            $lines[] = $city;
        }
        $uid = trim((string)($r['empfaenger_uid'] ?? ''));
        if ($uid !== '') {
            $lines[] = 'UID: ' . $uid;
        }
        $lines[] = str_repeat('-', 42);
    }

    // === Positionen (mit Zusatzinfo + Wrapping) ===
    // Linksbündig erzwingen, falls vorher etwas zentriert wurde (paranoid):
    $lines[] = $ESC_LEFT;
    $sum = 0.0;
    foreach ($items as $it) {
        $amount = (float)$it['betrag'] * (int)$it['cnt'];
        $sum += $amount;
        $name = (string)$it['name'];
        $lines[] = pt_line((int)$it['cnt'] . 'x ' . mb_strimwidth($name, 0, 26, '', 'UTF-8'), pt_money($amount));
        $zi = trim((string)($it['zusatzinfo'] ?? ''));
        if ($zi !== '') {
            // 1:1 wie am Bon (print_client.py textwrap.wrap):
            //  - Erste Zeile mit "   -> " (6 Zeichen Einrückung)
            //  - Folgezeilen mit "      " (6 Leerzeichen, ohne Pfeil)
            //  - Text-Breite 38 Zeichen (entspricht textwrap width=38)
            //  - Lange Wörter werden zerschnitten (cut=true)
            $prefix = '   -> ';
            $cont = '      ';
            $wrapped = wordwrap($zi, 38, "\n", true);
            $parts = explode("\n", $wrapped);
            foreach ($parts as $idx => $part) {
                $lines[] = $ESC_LEFT . ($idx === 0 ? $prefix : $cont) . $part;
            }
        }
    }

    $lines[] = str_repeat('-', 42);

    // === Summe groß (wie SUMME am Bon) ===
    // Im Doppelmodus passen nur ~21 Zeichen pro Zeile auf die Bonbreite.
    $lines[] = $ESC_BIG . pt_line('Summe', pt_money($sum), 21) . $ESC_NORM;
    $lines[] = '';

    // === Footer (zentriert) — gleiche Zeilen wie PDF A4 (ff_rechnung_footer_lines) ===
    foreach (ff_rechnung_footer_lines($conn) as $fLn) {
        $lines[] = $ESC_CENTER . $fLn . $ESC_LEFT;
    }
    $lines[] = '';

    return implode("\n", $lines);
}

function _ff_split_addr_lines($s): array {
    $s = str_replace("\r", '', (string)$s);
    $parts = explode("\n", $s);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * Fußzeilentext für Thermo-Rechnung und PDF (A4).
 * Vorrang: rechnung_thermo_footer, sonst thermo_bon_footer, sonst Standard.
 */
function ff_rechnung_footer_text(mysqli $conn): string
{
    $footerRaw = (string) settings_get($conn, 'rechnung_thermo_footer', '');
    if (trim($footerRaw) === '') {
        $footerRaw = (string) settings_get($conn, 'thermo_bon_footer', '');
    }
    if (trim($footerRaw) === '') {
        $footerRaw = 'Danke fuer Ihren Besuch!';
    }
    return $footerRaw;
}

/** @return string[] */
function ff_rechnung_footer_lines(mysqli $conn): array
{
    return _ff_split_addr_lines(ff_rechnung_footer_text($conn));
}

/**
 * Stellt sicher, dass printer_jobs existiert (wie Direktverkauf-Abholbon).
 * Ohne diese Tabelle schlägt das INSERT still fehl → kein Thermodruck.
 */
function ff_printer_jobs_ensure_table(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $chk = @mysqli_query($conn, "SHOW TABLES LIKE 'printer_jobs'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        $ensured = true;
        return;
    }
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS printer_jobs (
      id INT(11) NOT NULL AUTO_INCREMENT,
      printer VARCHAR(64) NOT NULL,
      type VARCHAR(32) DEFAULT 'invoice',
      payload MEDIUMTEXT NOT NULL,
      meta TEXT NULL,
      status VARCHAR(16) DEFAULT 'pending',
      attempts INT(11) DEFAULT 0,
      reserved_at DATETIME NULL,
      reserved_by VARCHAR(64) NULL,
      error TEXT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_printer_status (printer, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ensured = true;
}

/**
 * Rechnung als Thermo-Job einreihen (printer_jobs, type rechnung_thermo).
 */
function ff_rechnung_enqueue_thermo(mysqli $conn, int $printTarget, string $text): bool {
    if ($printTarget <= 0 || $text === '') {
        return false;
    }
    ff_printer_jobs_ensure_table($conn);
    $printerKey = 'target_' . $printTarget;
    $payload = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return false;
    }
    $escPayload = mysqli_real_escape_string($conn, $payload);
    $pk = mysqli_real_escape_string($conn, $printerKey);
    $ins = "INSERT INTO printer_jobs (printer, type, payload, meta, status, attempts, reserved_at, reserved_by, created_at)
            VALUES ('{$pk}', 'rechnung_thermo', '{$escPayload}', '{}', 'pending', 0, NULL, NULL, NOW())";
    $ok = (bool)mysqli_query($conn, $ins);
    if ($ok) {
        $jobId = (int)mysqli_insert_id($conn);
        require_once __DIR__ . '/ff_socket_printer.php';
        ff_socket_printer_dispatch_job($conn, $jobId);
    }
    return $ok;
}
