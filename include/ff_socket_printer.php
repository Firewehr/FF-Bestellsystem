<?php
/**
 * include/ff_socket_printer.php
 *
 * Native Socket-Druckoption (RAW ESC/POS via TCP Port 9100) für Netzwerk-Bondrucker
 * direkt aus PHP ohne zwingenden Python-Client.
 *
 * Bietet automatischen Fallback: Ist der Netzwerkdrucker nicht erreichbar oder
 * ist connection_type = 'client', bleibt der Job in printer_jobs für den
 * Python-Print-Client (USB/Seriell) erhalten.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ff_schema_helpers.php';

/**
 * Stellt sicher, dass die Spalten für Netzwerk-/Socket-Druck in print_targets existieren.
 */
function ff_socket_printer_ensure_schema(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    ff_ensure_table(
        $conn,
        'print_targets',
        "CREATE TABLE IF NOT EXISTS print_targets (
            print_target INT(11) NOT NULL,
            name VARCHAR(64) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (print_target)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    ff_ensure_column(
        $conn,
        'print_targets',
        'connection_type',
        "ALTER TABLE print_targets ADD COLUMN connection_type VARCHAR(16) NOT NULL DEFAULT 'client' COMMENT 'client = Python Daemon, network = RAW Socket Port 9100'"
    );

    ff_ensure_column(
        $conn,
        'print_targets',
        'ip_address',
        "ALTER TABLE print_targets ADD COLUMN ip_address VARCHAR(64) NULL DEFAULT NULL COMMENT 'IPv4 oder Hostname des Netzwerk-Druckers'"
    );

    ff_ensure_column(
        $conn,
        'print_targets',
        'port',
        "ALTER TABLE print_targets ADD COLUMN port INT(11) NOT NULL DEFAULT 9100 COMMENT 'RAW ESC/POS Port (Standard 9100)'"
    );
}

/**
 * Konvertiert UTF-8 Text in CP1252 / Latin-1 Bytes für Thermo-Bondrucker.
 */
function ff_escpos_encode_text(string $text): string
{
    if (function_exists('iconv')) {
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($encoded !== false) {
            return $encoded;
        }
    }
    return utf8_decode($text);
}

/**
 * Sendet binäre RAW-Bytes direkt an einen TCP-Socket (Port 9100).
 *
 * @return array{ok: bool, duration_ms: float, bytes?: int, error?: string}
 */
function ff_socket_print_raw(string $ip, int $port = 9100, string $binaryData = '', float $timeout = 2.0): array
{
    $ip = trim($ip);
    if ($ip === '') {
        return ['ok' => false, 'duration_ms' => 0.0, 'error' => 'Keine IP-Adresse angegeben.'];
    }
    if ($port <= 0 || $port > 65535) {
        $port = 9100;
    }

    $t0 = microtime(true);
    $errno = 0;
    $errstr = '';

    // Verbindung mit kurzem Timeout aufbauen (verhindert Blockieren bei offline Druckern)
    $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if (!$fp) {
        $duration = round((microtime(true) - $t0) * 1000, 1);
        $msg = $errstr !== '' ? $errstr : "Verbindung zu {$ip}:{$port} fehlgeschlagen (Fehlercode {$errno}).";
        return ['ok' => false, 'duration_ms' => $duration, 'error' => $msg];
    }

    // Stream-Timeout für Write setzen
    stream_set_timeout($fp, (int) ceil($timeout));

    $bytesWritten = @fwrite($fp, $binaryData);
    @fflush($fp);
    @fclose($fp);

    $duration = round((microtime(true) - $t0) * 1000, 1);

    if ($bytesWritten === false || $bytesWritten < strlen($binaryData)) {
        return [
            'ok' => false,
            'duration_ms' => $duration,
            'error' => "Konnte nicht alle Daten übertragen ({$bytesWritten} von " . strlen($binaryData) . " Bytes).",
        ];
    }

    return [
        'ok' => true,
        'duration_ms' => $duration,
        'bytes' => $bytesWritten,
    ];
}

/**
 * Erzeugt einen standardisierten ESC/POS Testbon für den Socket-Druckertest.
 */
function ff_escpos_build_test_bon(string $targetName, string $ip, int $port, string $festName = ''): string
{
    $ESC_INIT   = "\x1B\x40";
    $ESC_BIG    = "\x1B\x21\x30"; // doppelte Höhe + Breite
    $ESC_NORM   = "\x1B\x21\x00"; // normal
    $ESC_BOLD_ON  = "\x1B\x45\x01";
    $ESC_BOLD_OFF = "\x1B\x45\x00";
    $ESC_CENTER = "\x1B\x61\x01";
    $ESC_LEFT   = "\x1B\x61\x00";
    $ESC_CUT    = "\x1B\x64\x03\x1D\x56\x01"; // 3 Zeilen Vorschub + Papierschnitt

    $header = $festName !== '' ? $festName : 'FEUERWEHR BESTELLSYSTEM';
    $now = date('d.m.Y H:i:s');

    $out = $ESC_INIT;
    $out .= $ESC_CENTER . $ESC_BIG . ff_escpos_encode_text($header) . "\n" . $ESC_NORM;
    $out .= ff_escpos_encode_text("NETZWERK-DRUCKER TEST") . "\n\n";

    $out .= $ESC_LEFT;
    $out .= ff_escpos_encode_text("Druckziel:     " . $targetName) . "\n";
    $out .= ff_escpos_encode_text("IP-Adresse:    " . $ip . ":" . $port) . "\n";
    $out .= ff_escpos_encode_text("Verbindung:    RAW TCP Socket (Port " . $port . ")") . "\n";
    $out .= ff_escpos_encode_text("Zeitstempel:   " . $now) . "\n";
    $out .= str_repeat('-', 42) . "\n";

    $out .= $ESC_CENTER . $ESC_BOLD_ON . ff_escpos_encode_text("STATUS: VERBINDUNG ERFOLGREICH!") . "\n" . $ESC_BOLD_OFF;
    $out .= ff_escpos_encode_text("Direkter Socket-Druck aus PHP ist aktiv.") . "\n";
    $out .= str_repeat('-', 42) . "\n\n";

    $out .= $ESC_CENTER . ff_escpos_encode_text("FeuerwehrBestellsystem POS") . "\n";
    $out .= $ESC_CUT;

    return $out;
}

/**
 * Erzeugt ESC/POS Binärdaten aus einem tisch-Payload (z. B. Kellner-Bon).
 */
function ff_escpos_build_kellner_bon(array $tisch, string $headerText = '', string $footerText = ''): string
{
    $ESC_INIT   = "\x1B\x40";
    $ESC_BIG    = "\x1B\x21\x30";
    $ESC_NORM   = "\x1B\x21\x00";
    $ESC_BOLD_ON  = "\x1B\x45\x01";
    $ESC_BOLD_OFF = "\x1B\x45\x00";
    $ESC_CENTER = "\x1B\x61\x01";
    $ESC_LEFT   = "\x1B\x61\x00";
    $ESC_CUT    = "\x1B\x64\x03\x1D\x56\x01";

    $out = $ESC_INIT;

    // Optionaler Header
    if (trim($headerText) !== '') {
        $lines = preg_split('/\r\n|\r|\n/', trim($headerText));
        if (!empty($lines)) {
            $out .= $ESC_CENTER . $ESC_BIG . ff_escpos_encode_text($lines[0]) . "\n" . $ESC_NORM;
            for ($i = 1; $i < count($lines); $i++) {
                $out .= ff_escpos_encode_text($lines[$i]) . "\n";
            }
            $out .= "\n";
        }
    }

    // Tisch & Bon-Kopf
    $tnr = (int) ($tisch['tischnummer'] ?? 0);
    $tname = trim((string) ($tisch['tischname'] ?? ''));
    if ($tname === '') {
        $tname = $tnr === 999999 ? 'Direktverkauf' : ('Tisch ' . $tnr);
    }

    $out .= $ESC_LEFT;
    $out .= $ESC_BIG . ff_escpos_encode_text("Tisch: " . $tname) . "\n" . $ESC_NORM;

    $bonNr = (int) ($tisch['bon_nr'] ?? ($tisch['_bon_nr'] ?? 0));
    if ($bonNr > 0) {
        $out .= $ESC_BOLD_ON . ff_escpos_encode_text("Bon-Nr.: " . $bonNr) . "\n" . $ESC_BOLD_OFF;
    }

    $kellner = trim((string) ($tisch['kellner'] ?? ''));
    if ($kellner !== '') {
        $out .= ff_escpos_encode_text("KellnerIn: " . $kellner) . "\n";
    }

    $out .= ff_escpos_encode_text("Zeit:      " . date('d.m.Y H:i')) . " Uhr\n";
    $out .= str_repeat('-', 42) . "\n";

    // Positionen
    $positionen = is_array($tisch['positionen'] ?? null) ? $tisch['positionen'] : [];
    $total = 0.0;

    foreach ($positionen as $pos) {
        $name = trim((string) ($pos['name'] ?? ($pos['Positionsname'] ?? '')));
        $anz = max(1, (int) ($pos['anzahl'] ?? 1));
        $betrag = (float) ($pos['gesamtpreis'] ?? ($pos['betrag'] ?? 0.0));
        $einzel = isset($pos['einzelpreis']) ? (float) $pos['einzelpreis'] : ($betrag / $anz);
        $total += $betrag;

        // Zeile formatieren: "2x Schnitzel mit Salat       13.00   26.00"
        $prefix = $anz . 'x ';
        $namePart = mb_strimwidth($prefix . $name, 0, 24, '', 'UTF-8');
        $line = sprintf("%-24s %6.2f %7.2f\n", $namePart, $einzel, $betrag);
        $out .= ff_escpos_encode_text($line);

        $zi = trim((string) ($pos['zusatzinfo'] ?? ($pos['Zusatzinfo'] ?? '')));
        if ($zi !== '') {
            $out .= ff_escpos_encode_text("   -> " . $zi . "\n");
        }
    }

    $out .= str_repeat('-', 42) . "\n";
    $out .= $ESC_BIG . sprintf("SUMME: %8.2f EUR\n", $total) . $ESC_NORM;

    if (trim($footerText) !== '') {
        $out .= "\n" . $ESC_CENTER . ff_escpos_encode_text(trim($footerText)) . "\n" . $ESC_LEFT;
    }

    $out .= $ESC_CUT;
    return $out;
}

/**
 * Versucht, einen anstehenden Druckjob direkt via Socket an einen Netzwerkdrucker zu senden.
 *
 * Falls der Drucker auf 'network' konfiguriert ist:
 * - Bei Erfolg: Job wird in printer_jobs auf 'done' gesetzt.
 * - Bei Fehler: Job bleibt auf 'pending' (mit Fehlermeldung & Retry-Counter) als Fallback für Python-Client.
 *
 * @return array{attempted: bool, ok: bool, message: string}
 */
function ff_socket_printer_dispatch_job(mysqli $conn, int $jobId): array
{
    if ($jobId <= 0) {
        return ['attempted' => false, 'ok' => false, 'message' => 'Ungültige Job-ID.'];
    }

    ff_socket_printer_ensure_schema($conn);

    $jRes = mysqli_query($conn, "SELECT * FROM printer_jobs WHERE id = {$jobId} LIMIT 1");
    if (!$jRes || mysqli_num_rows($jRes) === 0) {
        return ['attempted' => false, 'ok' => false, 'message' => 'Job nicht gefunden.'];
    }
    $job = mysqli_fetch_assoc($jRes);
    mysqli_free_result($jRes);

    $printerKey = (string) ($job['printer'] ?? '');
    // Extrahiere target ID aus 'target_11'
    if (!preg_match('/^target_(\d+)$/', $printerKey, $m)) {
        return ['attempted' => false, 'ok' => false, 'message' => 'Kein target_X Drucker.'];
    }
    $targetId = (int) $m[1];

    $ptRes = mysqli_query($conn, "SELECT * FROM print_targets WHERE print_target = {$targetId} LIMIT 1");
    if (!$ptRes || mysqli_num_rows($ptRes) === 0) {
        return ['attempted' => false, 'ok' => false, 'message' => 'Druckziel nicht gefunden.'];
    }
    $target = mysqli_fetch_assoc($ptRes);
    mysqli_free_result($ptRes);

    $connType = (string) ($target['connection_type'] ?? 'client');
    $ip = trim((string) ($target['ip_address'] ?? ''));
    $port = (int) ($target['port'] ?? 9100) ?: 9100;

    // Nur ausführen, wenn als Netzwerkdrucker konfiguriert
    if ($connType !== 'network' || $ip === '') {
        return [
            'attempted' => false,
            'ok' => false,
            'message' => 'Druckziel ist nicht für Socket-Druck konfiguriert (Verbindung: ' . $connType . ').',
        ];
    }

    $type = (string) ($job['type'] ?? '');
    $payloadRaw = (string) ($job['payload'] ?? '');
    $payloadData = json_decode($payloadRaw, true) ?: [];

    $escData = '';

    if ($type === 'rechnung_thermo') {
        // Rechnungstext enthält bereits ESC/POS-Formatierung
        $text = (string) ($payloadData['text'] ?? '');
        $ESC_CUT = "\x1B\x64\x03\x1D\x56\x01";
        $escData = "\x1B\x40" . ff_escpos_encode_text($text) . $ESC_CUT;
    } elseif ($type === 'kellner_bon' || $type === 'abholbon') {
        $header = (string) settings_get($conn, 'thermo_bon_header', '');
        $footer = (string) settings_get($conn, 'thermo_bon_footer', '');
        $escData = ff_escpos_build_kellner_bon($payloadData, $header, $footer);
    } else {
        // Generischer Fallback
        $text = (string) ($payloadData['text'] ?? $payloadRaw);
        $escData = "\x1B\x40" . ff_escpos_encode_text($text) . "\x1B\x64\x03\x1D\x56\x01";
    }

    // Direkt an Socket senden
    $res = ff_socket_print_raw($ip, $port, $escData, 2.0);

    if ($res['ok']) {
        $upd = mysqli_query(
            $conn,
            "UPDATE printer_jobs SET status = 'done', reserved_by = 'socket_printer', reserved_at = NOW(), attempts = attempts + 1 WHERE id = {$jobId}"
        );
        return [
            'attempted' => true,
            'ok' => true,
            'message' => "Socket-Druck erfolgreich an {$ip}:{$port} ({$res['duration_ms']} ms).",
        ];
    }

    // Bei Fehler: Job bleibt 'pending' für Python-Client Fallback
    $errEsc = mysqli_real_escape_string($conn, (string) ($res['error'] ?? 'Socket-Verbindungsfehler'));
    mysqli_query(
        $conn,
        "UPDATE printer_jobs SET attempts = attempts + 1, error = 'Socket [{$ip}:{$port}]: {$errEsc}' WHERE id = {$jobId}"
    );

    return [
        'attempted' => true,
        'ok' => false,
        'message' => "Socket-Druck fehlgeschlagen ({$res['error']}). Job bleibt in Queue für Fallback-Client.",
    ];
}
