<?php
/**
 * include/ff_preflight.php
 *
 * Fest-Vorbereitungsassistent („Pre-Flight Checklist“) vor dem Festbeginn.
 * Prüft automatisch die 4 kritischen Betriebsfaktoren:
 * 1. Test-Bestellungen und Queues gelöscht
 * 2. Alle Drucker online & Testbon erfolgreich gedruckt
 * 3. Rechnungsnummernkreis korrekt gesetzt
 * 4. Offline-Backup eingerichtet
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ff_socket_printer.php';

function ff_preflight_check(mysqli $conn): array
{
    ff_socket_printer_ensure_schema($conn);

    $currentFestId = (int) settings_get($conn, 'current_fest_id', '1');
    $overall = 'ok';

    // -------------------------------------------------------------
    // Check 1: Test-Bestellungen & Queues bereinigt
    // -------------------------------------------------------------
    $orderCount = 0;
    $unpaidCount = 0;
    $res = mysqli_query($conn, 'SELECT COUNT(*) AS c, SUM(CASE WHEN timestampBezahlung IS NULL OR timestampBezahlung = "0000-00-00 00:00:00" THEN 1 ELSE 0 END) AS unpaid FROM bestellungen WHERE `delete` = 0');
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $orderCount = (int) ($row['c'] ?? 0);
        $unpaidCount = (int) ($row['unpaid'] ?? 0);
    }

    $pendingPrintJobs = 0;
    $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM printer_jobs WHERE status IN ('pending', 'reserved')");
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $pendingPrintJobs = (int) ($row['c'] ?? 0);
    }

    $pendingKitchenPrints = 0;
    $res = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM print');
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $pendingKitchenPrints = (int) ($row['c'] ?? 0);
    }

    $checkOrdersStatus = 'ok';
    $checkOrdersBadge = 'Bereit';
    $checkOrdersDetails = 'Datenbank ist sauber (keine aktiven Test-Verkäufe oder hängenden Queues).';

    if ($unpaidCount > 0 || $pendingPrintJobs > 0 || $pendingKitchenPrints > 0) {
        $checkOrdersStatus = 'warning';
        $checkOrdersBadge = 'Testdaten vorhanden';
        $parts = [];
        if ($orderCount > 0) {
            $parts[] = "{$orderCount} Bestellungen ({$unpaidCount} offen)";
        }
        if ($pendingPrintJobs > 0) {
            $parts[] = "{$pendingPrintJobs} hängende Thermo-Jobs";
        }
        if ($pendingKitchenPrints > 0) {
            $parts[] = "{$pendingKitchenPrints} Positionen in Küchen-Queue";
        }
        $checkOrdersDetails = 'Achtung vor Fest-Start: ' . implode(', ', $parts) . '. Bitte über „Fest-Start vorbereiten“ bereinigen.';
        $overall = 'warning';
    }

    // -------------------------------------------------------------
    // Check 2: Alle Drucker online & Testbon
    // -------------------------------------------------------------
    $ptRes = mysqli_query($conn, 'SELECT print_target, name, active, connection_type, ip_address, port FROM print_targets WHERE active = 1 ORDER BY sort_order, name');
    $targets = [];
    $allPrintersOk = true;
    $offlineCount = 0;

    if ($ptRes) {
        while ($pt = mysqli_fetch_assoc($ptRes)) {
            $pid = (int) $pt['print_target'];
            $name = (string) ($pt['name'] ?? ('Druckziel ' . $pid));
            $connType = (string) ($pt['connection_type'] ?? 'client');
            $ip = trim((string) ($pt['ip_address'] ?? ''));
            $port = (int) ($pt['port'] ?? 9100) ?: 9100;

            $printerInfo = [
                'id' => $pid,
                'name' => $name,
                'type' => $connType,
                'ip' => $ip,
                'port' => $port,
                'online' => false,
                'latency_ms' => null,
                'status_text' => 'Unbekannt',
            ];

            if ($connType === 'network') {
                if ($ip === '') {
                    $printerInfo['status_text'] = 'Keine IP hinterlegt';
                    $allPrintersOk = false;
                    $offlineCount++;
                } else {
                    // Schneller TCP-Ping auf Port 9100
                    $testRes = ff_socket_print_raw($ip, $port, "\x1B\x40", 1.2);
                    if ($testRes['ok']) {
                        $printerInfo['online'] = true;
                        $printerInfo['latency_ms'] = $testRes['duration_ms'];
                        $printerInfo['status_text'] = "Online ({$testRes['duration_ms']} ms)";
                    } else {
                        $printerInfo['status_text'] = 'Offline (' . ($testRes['error'] ?? 'nicht erreichbar') . ')';
                        $allPrintersOk = false;
                        $offlineCount++;
                    }
                }
            } else {
                // Client-Drucker: Prüfe ob innerhalb der letzten 60 Sekunden Heartbeat kam
                $serviceKey = 'target_' . $pid;
                $hbKey = 'printer_heartbeat_' . $serviceKey;
                $lastHb = (string) settings_get($conn, $hbKey, '');
                $lastSeenSec = $lastHb !== '' ? (time() - strtotime($lastHb)) : 999999;

                if ($lastSeenSec <= 90) {
                    $printerInfo['online'] = true;
                    $printerInfo['status_text'] = "Client aktiv (vor {$lastSeenSec}s)";
                } else {
                    $printerInfo['status_text'] = $lastHb !== '' ? "Client inaktiv (letzter Kontakt vor " . round($lastSeenSec / 60) . " Min.)" : "Kein Client verbunden";
                    $allPrintersOk = false;
                    $offlineCount++;
                }
            }
            $targets[] = $printerInfo;
        }
        mysqli_free_result($ptRes);
    }

    $checkPrintersStatus = 'ok';
    $checkPrintersBadge = 'Alle online';
    $checkPrintersDetails = count($targets) > 0 ? (count($targets) . ' aktive Drucker sind betriebsbereit.') : 'Keine Druckziele angelegt.';

    if (empty($targets)) {
        $checkPrintersStatus = 'warning';
        $checkPrintersBadge = 'Keine Drucker';
        $checkPrintersDetails = 'Es sind noch keine aktiven Druckziele konfiguriert.';
        $overall = 'warning';
    } elseif (!$allPrintersOk) {
        $checkPrintersStatus = 'warning';
        $checkPrintersBadge = "{$offlineCount} Drucker offline";
        $checkPrintersDetails = "{$offlineCount} von " . count($targets) . " Druckern antworten nicht. Bitte Verbindungen prüfen oder Testbon senden.";
        $overall = 'warning';
    }

    // -------------------------------------------------------------
    // Check 3: Rechnungsnummernkreis korrekt gesetzt
    // -------------------------------------------------------------
    $globalPrefix = trim((string) settings_get($conn, 'rechnung_prefix', ''));
    $nextSeq = (int) settings_get($conn, 'rechnung_next_seq', '1');

    // Fest-spezifisches Präfix prüfen
    $festPrefix = '';
    $fRes = mysqli_query($conn, "SELECT rechnung_prefix, name FROM feste WHERE id = {$currentFestId} LIMIT 1");
    if ($fRes && ($fRow = mysqli_fetch_assoc($fRes))) {
        $festPrefix = trim((string) ($fRow['rechnung_prefix'] ?? ''));
    }

    $activePrefix = $festPrefix !== '' ? $festPrefix : $globalPrefix;

    $checkSeqStatus = 'ok';
    $checkSeqBadge = 'Konfiguriert';
    $checkSeqDetails = "Rechnungspräfix ist gesetzt ('{$activePrefix}'), nächste Belegnummer: {$nextSeq}.";

    if ($activePrefix === '') {
        $checkSeqStatus = 'error';
        $checkSeqBadge = 'Präfix fehlt';
        $checkSeqDetails = 'Achtung: Es ist kein Rechnungspräfix konfiguriert (wichtig für die Finanzamts- und Belegkonformität).';
        $overall = 'error';
    } elseif ($nextSeq <= 0) {
        $checkSeqStatus = 'warning';
        $checkSeqBadge = 'Zähler prüfen';
        $checkSeqDetails = "Rechnungszähler steht auf {$nextSeq}. Empfohlen: Start bei 1.";
        $overall = 'warning';
    }

    // -------------------------------------------------------------
    // Check 4: Offline-Backup eingerichtet
    // -------------------------------------------------------------
    $offlineToken = trim((string) settings_get($conn, 'offline_backup_token', ''));
    $backupDir = dirname(__DIR__) . '/backups';
    $backupFiles = is_dir($backupDir) ? (glob($backupDir . '/*.{sql,json,gz,zip}', GLOB_BRACE) ?: []) : [];
    $backupCount = count($backupFiles);

    $checkBackupStatus = 'ok';
    $checkBackupBadge = 'Aktiv';
    $checkBackupDetails = "Offline-Sicherungs-Token ist hinterlegt ({$backupCount} lokale Snapshots im Archiv).";

    if ($offlineToken === '') {
        $checkBackupStatus = 'warning';
        $checkBackupBadge = 'Kein Token';
        $checkBackupDetails = 'Kein Offline-Backup-Token hinterlegt. Das automatische Ausfall-Backup via Skript ist nicht autorisiert.';
        $overall = 'warning';
    }

    return [
        'ok' => true,
        'overall' => $overall,
        'fest_id' => $currentFestId,
        'checked_at' => date('d.m.Y H:i:s'),
        'checks' => [
            'orders' => [
                'id' => 'orders',
                'title' => 'Test-Bestellungen & Queues bereinigt',
                'status' => $checkOrdersStatus,
                'badge' => $checkOrdersBadge,
                'details' => $checkOrdersDetails,
                'order_count' => $orderCount,
                'unpaid_count' => $unpaidCount,
                'pending_print_jobs' => $pendingPrintJobs,
                'action_page' => 'stammdaten-abrechnung',
                'action_label' => 'Zu Fest-Start vorbereiten',
            ],
            'printers' => [
                'id' => 'printers',
                'title' => 'Alle Drucker online & Testbon',
                'status' => $checkPrintersStatus,
                'badge' => $checkPrintersBadge,
                'details' => $checkPrintersDetails,
                'targets' => $targets,
                'action_page' => 'stammdaten-druckziele',
                'action_label' => 'Druckziele & Testbon',
            ],
            'invoice_seq' => [
                'id' => 'invoice_seq',
                'title' => 'Rechnungsnummernkreis',
                'status' => $checkSeqStatus,
                'badge' => $checkSeqBadge,
                'details' => $checkSeqDetails,
                'prefix' => $activePrefix,
                'next_seq' => $nextSeq,
                'action_page' => 'stammdaten-nummern',
                'action_label' => 'Nummernkreis einstellen',
            ],
            'offline_backup' => [
                'id' => 'offline_backup',
                'title' => 'Offline-Backup eingerichtet',
                'status' => $checkBackupStatus,
                'badge' => $checkBackupBadge,
                'details' => $checkBackupDetails,
                'token_configured' => ($offlineToken !== ''),
                'snapshot_count' => $backupCount,
                'action_page' => 'stammdaten-rechnungsdaten',
                'action_label' => 'Backup-Token hinterlegen',
            ],
        ],
    ];
}
