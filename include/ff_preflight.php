<?php
/**
 * include/ff_preflight.php
 *
 * Fest-Vorbereitungsassistent („Pre-Flight Checklist“) vor dem Festbeginn.
 * Prüft automatisch die 4 kritischen Betriebsfaktoren:
 * 1. Test-Bestellungen und Queues gelöscht
 * 2. Alle Drucker online & Testbon erfolgreich gedruckt
 * 3. Rechnungsnummernkreis korrekt gesetzt
 * 4. Offline-Backup eingerichtet (Token + Datenbank-Backup höchstens 30 Minuten alt)
 * 5. Keine liegengebliebenen Bons (druckbereit, aber seit über 30 Minuten nicht gedruckt)
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ff_socket_printer.php';
require_once __DIR__ . '/ff_auto_print.php';

/**
 * Alter des jüngsten Datenbank-Backups (backups/db_*.sql.gz aus tools/db_backup.php).
 *
 * @return array{status: string, badge: string, details: string, age_min: ?int, file: ?string}
 */
function ff_preflight_db_backup_status(string $backupDir, int $now, int $maxAgeMin = 30): array
{
    $newest = null;
    $newestTs = 0;
    foreach (glob(rtrim($backupDir, '/') . '/db_*.sql.gz') ?: [] as $f) {
        $ts = (int) @filemtime($f);
        if ($ts > $newestTs) {
            $newestTs = $ts;
            $newest = $f;
        }
    }
    if ($newest === null) {
        return [
            'status' => 'warning',
            'badge' => 'Kein DB-Backup',
            'details' => 'Kein Datenbank-Backup gefunden. Cronjob für tools/db_backup.php einrichten (Vorlage: tools/cron/ff-db-backup.cron).',
            'age_min' => null,
            'file' => null,
        ];
    }
    $ageMin = (int) floor(max(0, $now - $newestTs) / 60);
    $file = basename($newest);
    if ($ageMin > $maxAgeMin) {
        return [
            'status' => 'warning',
            'badge' => 'DB-Backup veraltet',
            'details' => "Letztes Datenbank-Backup ist {$ageMin} Minuten alt ({$file}). Läuft der Cronjob für tools/db_backup.php?",
            'age_min' => $ageMin,
            'file' => $file,
        ];
    }
    return [
        'status' => 'ok',
        'badge' => 'Aktiv',
        'details' => "Letztes Datenbank-Backup vor {$ageMin} Minuten ({$file}).",
        'age_min' => $ageMin,
        'file' => $file,
    ];
}

/** Ab diesem Alter gilt ein ungedruckter Bon als liegengeblieben (Minuten). */
const FF_PREFLIGHT_PRINT_STALE_MIN = 30;

/**
 * Druckbereite, noch nicht gedruckte Zeilen je Druckziel (genau die, die print_target.php
 * beim nächsten Abruf drucken würde). Liegen sie länger als $staleMin Minuten, läuft für das
 * Ziel offenbar kein Druck-Client – beim nächsten Start kämen sie als alte Bons heraus.
 *
 * @return array{total_rows:int, stale_rows:int, stale_orders:int, oldest:?string, targets:list<array{print_target:int, name:string, rows:int, stale_rows:int, stale_orders:int, oldest:?string}>}
 */
function ff_preflight_print_backlog(mysqli $conn, int $staleMin = FF_PREFLIGHT_PRINT_STALE_MIN): array
{
    $pendingCond = ff_print_pending_cond_sql(ff_auto_print_ensure_schema($conn));
    $readyExpr = ff_print_ready_at_expr();
    $out = ['total_rows' => 0, 'stale_rows' => 0, 'stale_orders' => 0, 'oldest' => null, 'targets' => []];
    $res = ff_db_query(
        $conn,
        "SELECT x.pt, COALESCE(MAX(t.name), '') AS name, COUNT(*) AS cnt,
                SUM(x.stale) AS stale_cnt,
                COUNT(DISTINCT CASE WHEN x.stale = 1 THEN CONCAT(x.tischnummer, '/', COALESCE(x.order_nr, x.zeitstempel)) END) AS stale_orders,
                MIN(x.ready_at) AS oldest
           FROM (
                SELECT COALESCE(b.print_target, p.print_target, 11) AS pt, b.tischnummer, b.order_nr, b.zeitstempel,
                       {$readyExpr} AS ready_at,
                       ({$readyExpr} < NOW() - INTERVAL ? MINUTE) AS stale
                  FROM bestellungen b
                  JOIN positionen p ON p.rowid = b.position
                 WHERE {$pendingCond}
           ) x
           LEFT JOIN print_targets t ON t.print_target = x.pt
          GROUP BY x.pt
          ORDER BY x.pt",
        'i',
        [$staleMin]
    );
    if (!$res) {
        return $out;
    }
    while ($row = ff_db_row_text(mysqli_fetch_assoc($res))) {
        $pt = (int) $row['pt'];
        $target = [
            'print_target' => $pt,
            'name' => $row['name'] !== '' ? (string) $row['name'] : ('Druckziel ' . $pt),
            'rows' => (int) $row['cnt'],
            'stale_rows' => (int) $row['stale_cnt'],
            'stale_orders' => (int) $row['stale_orders'],
            'oldest' => $row['oldest'] !== null ? (string) $row['oldest'] : null,
        ];
        $out['targets'][] = $target;
        $out['total_rows'] += $target['rows'];
        $out['stale_rows'] += $target['stale_rows'];
        $out['stale_orders'] += $target['stale_orders'];
        if ($target['oldest'] !== null && ($out['oldest'] === null || $target['oldest'] < $out['oldest'])) {
            $out['oldest'] = $target['oldest'];
        }
    }
    mysqli_free_result($res);
    return $out;
}

/**
 * Markiert liegengebliebene Bons (älter als $staleMin Minuten) als gedruckt, damit ein
 * Druck-Client sie nicht nachträglich ausdruckt. Bestellungen, Umsätze und Statistik bleiben
 * unverändert. Läuft in der Transaktion des Aufrufers.
 *
 * @return array{rows:int, print_targets:list<int>}
 */
function ff_preflight_print_backlog_clear(mysqli $conn, int $staleMin = FF_PREFLIGHT_PRINT_STALE_MIN): array
{
    $pendingCond = ff_print_pending_cond_sql(ff_auto_print_ensure_schema($conn));
    $readyExpr = ff_print_ready_at_expr();
    $ids = [];
    $targets = [];
    $res = ff_db_query(
        $conn,
        "SELECT b.rowid, COALESCE(b.print_target, p.print_target, 11) AS pt
           FROM bestellungen b
           JOIN positionen p ON p.rowid = b.position
          WHERE {$pendingCond} AND {$readyExpr} < NOW() - INTERVAL ? MINUTE
          FOR UPDATE",
        'i',
        [$staleMin]
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $ids[] = (int) $row['rowid'];
            $targets[(int) $row['pt']] = true;
        }
        mysqli_free_result($res);
    }
    if ($ids === []) {
        return ['rows' => 0, 'print_targets' => []];
    }
    $rows = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        [$inIds, $idT, $idV] = ff_db_in_ints($chunk);
        ff_db_query($conn, "UPDATE bestellungen SET print_status = 1 WHERE print_status = 0 AND rowid IN ($inIds)", $idT, $idV);
        $rows += max(0, ff_db_affected_rows());
    }
    return ['rows' => $rows, 'print_targets' => array_keys($targets)];
}

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
    $fRes = ff_db_query($conn, 'SELECT rechnung_prefix, name FROM feste WHERE id = ? LIMIT 1', 'i', [(int)$currentFestId]);
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
        $overall = $overall === 'error' ? 'error' : 'warning';
    }

    // Datenbank-Backup (tools/db_backup.php per Cron): jüngster Stand darf höchstens 30 Minuten alt sein.
    $dbBackup = ff_preflight_db_backup_status($backupDir, time());
    if ($dbBackup['status'] !== 'ok') {
        $checkBackupDetails = $dbBackup['details'] . ($offlineToken === '' ? ' ' . $checkBackupDetails : '');
        if ($checkBackupStatus === 'ok') {
            $checkBackupStatus = 'warning';
            $checkBackupBadge = $dbBackup['badge'];
        }
        $overall = $overall === 'error' ? 'error' : 'warning';
    } else {
        $checkBackupDetails .= ' ' . $dbBackup['details'];
    }

    // -------------------------------------------------------------
    // Check 5: Liegengebliebene Bons
    // -------------------------------------------------------------
    $backlog = ff_preflight_print_backlog($conn);
    $staleMin = FF_PREFLIGHT_PRINT_STALE_MIN;
    $checkBacklogStatus = 'ok';
    $checkBacklogBadge = 'Keine';
    $checkBacklogDetails = 'Keine ungedruckten Bons, die älter als ' . $staleMin . ' Minuten sind.';
    if ($backlog['total_rows'] > $backlog['stale_rows']) {
        $checkBacklogDetails .= ' ' . ($backlog['total_rows'] - $backlog['stale_rows']) . ' Artikel warten gerade auf den Druck.';
    }
    if ($backlog['stale_rows'] > 0) {
        $parts = [];
        foreach ($backlog['targets'] as $t) {
            if ($t['stale_rows'] > 0) {
                $parts[] = $t['name'] . ': ' . $t['stale_orders'] . ' Bestellung(en), ' . $t['stale_rows'] . ' Artikel';
            }
        }
        $oldestTs = $backlog['oldest'] !== null ? strtotime($backlog['oldest']) : false;
        $checkBacklogStatus = 'warning';
        $checkBacklogBadge = $backlog['stale_orders'] === 1 ? '1 alter Bon' : ($backlog['stale_orders'] . ' alte Bons');
        $checkBacklogDetails = 'Druckbereit, aber seit über ' . $staleMin . ' Minuten nicht gedruckt (' . implode('; ', $parts) . ')'
            . ($oldestTs ? ', älteste vom ' . date('d.m.Y H:i', $oldestTs) . ' Uhr' : '') . '. '
            . 'Startet ein Druck-Client, druckt er sie alle aus. Mit „Alte Bons verwerfen“ werden sie als gedruckt markiert; Bestellungen und Umsätze bleiben unverändert.';
        $overall = $overall === 'error' ? 'error' : 'warning';
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
                'db_backup_age_min' => $dbBackup['age_min'],
                'db_backup_file' => $dbBackup['file'],
                'action_page' => 'stammdaten-rechnungsdaten',
                'action_label' => 'Backup-Token hinterlegen',
            ],
            'print_backlog' => [
                'id' => 'print_backlog',
                'title' => 'Keine liegengebliebenen Bons',
                'status' => $checkBacklogStatus,
                'badge' => $checkBacklogBadge,
                'details' => $checkBacklogDetails,
                'stale_min' => $staleMin,
                'stale_rows' => $backlog['stale_rows'],
                'stale_orders' => $backlog['stale_orders'],
                'pending_rows' => $backlog['total_rows'],
                'targets' => $backlog['targets'],
            ],
        ],
    ];
}
