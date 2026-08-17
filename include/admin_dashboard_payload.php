<?php
/**
 * Dashboard-KPIs als Array (gleiche Struktur wie admin_dashboard_api.php → JSON).
 * Wird von admin_page.php (über admin.php, Inline-Bootstrap) und admin_dashboard_api.php genutzt.
 *
 * Mit kurzem Datei-Cache (3 s): schnelle Klicks innerhalb der Admin-Seite und
 * das automatische Polling treffen den Cache, statt die KPI-Aggregate erneut zu rechnen.
 *
 * @return array<string,mixed>
 */
function ff_admin_dashboard_payload(mysqli $conn): array {
    require_once __DIR__ . '/settings.php';
    require_once __DIR__ . '/ff_schreibaus.php';
    require_once __DIR__ . '/ff_finance_bereich_helpers.php';
    require_once __DIR__ . '/ff_print_target_labels.php';
    require_once __DIR__ . '/ff_position_stock_summary.php';

    static $memo = null;
    static $memoExpires = 0;
    $cacheTtl = 3;
    $now = time();
    if ($memo !== null && $memoExpires > $now) {
        return $memo;
    }

    $cacheDir = __DIR__ . '/.cache';
    $cacheFile = $cacheDir . '/admin_dashboard_payload.json';
    if (is_file($cacheFile)) {
        $mtime = @filemtime($cacheFile) ?: 0;
        if ($mtime && ($now - $mtime) < $cacheTtl) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && !empty($decoded['ok'])) {
                    $memo = $decoded;
                    $memoExpires = $mtime + $cacheTtl;
                    return $decoded;
                }
            }
        }
    }

    ff_schreibaus_ensure_column($conn);
    mysqli_set_charset($conn, 'utf8mb4');

    $today = date('Y-m-d');
    $todayEsc = mysqli_real_escape_string($conn, $today);

    $base = ff_finance_order_paid_base_sql('b');
    $festFilterSql = ff_finance_fest_filter_sql($conn, 'b');
    $amtExpr = ff_finance_order_amount_expr('b', 'p');

    $festId = (int) setting_get($conn, 'current_fest_id', '0');
    if ($festId <= 0) {
        $fAct = @mysqli_query($conn, 'SELECT id FROM feste WHERE aktiv=1 LIMIT 1');
        if ($fAct && ($rAct = mysqli_fetch_assoc($fAct))) {
            $festId = (int) $rAct['id'];
        }
    }
    $hasFestIdCol = $festFilterSql !== '';

    $festName = '';
    if ($festId > 0) {
        $fr = @mysqli_query($conn, 'SELECT name FROM feste WHERE id=' . (int) $festId . ' LIMIT 1');
        if ($fr && ($frow = mysqli_fetch_assoc($fr))) {
            $festName = (string) ($frow['name'] ?? '');
        }
    }

    $sqlHeute = "SELECT COALESCE(SUM({$amtExpr}),0) AS s, COUNT(*) AS c FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position WHERE {$base}{$festFilterSql} AND DATE(b.timestampBezahlung) = '{$todayEsc}'";
    $sqlGes = "SELECT COALESCE(SUM({$amtExpr}),0) AS s, COUNT(*) AS c FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position WHERE {$base}{$festFilterSql}";
    $sqlKellner = "SELECT COALESCE(SUM({$amtExpr}),0) AS s, COUNT(*) AS c FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position WHERE {$base}{$festFilterSql}
        AND b.kellnerZahlung IS NOT NULL AND TRIM(b.kellnerZahlung) <> ''";

    $heute = ['sum' => 0.0, 'count' => 0];
    $gesamt = ['sum' => 0.0, 'count' => 0];
    $kellnerGes = ['sum' => 0.0, 'count' => 0];

    $r1 = mysqli_query($conn, $sqlHeute);
    if ($r1 && ($row = mysqli_fetch_assoc($r1))) {
        $heute = ['sum' => (float) $row['s'], 'count' => (int) $row['c']];
    }
    $r2 = mysqli_query($conn, $sqlGes);
    if ($r2 && ($row = mysqli_fetch_assoc($r2))) {
        $gesamt = ['sum' => (float) $row['s'], 'count' => (int) $row['c']];
    }
    $r3 = mysqli_query($conn, $sqlKellner);
    if ($r3 && ($row = mysqli_fetch_assoc($r3))) {
        $kellnerGes = ['sum' => (float) $row['s'], 'count' => (int) $row['c']];
    }

    ff_finance_ensure_schema($conn);
    $revSummary = ff_finance_revenue_summary($conn, null);
    $bereicheUmsatz = $revSummary['bereiche_umsatz'];
    $umsatzBereicheSumme = (float) $revSummary['umsatz_bereiche_summe'];
    $umsatzGesamtKombiniert = (float) $revSummary['umsatz_gesamt_kombiniert'];
    $verkaufUnzugeordnet = (float) $revSummary['verkauf_unzugeordnet'];
    $verkaufKellnerDirekt = (float) ($revSummary['verkauf_kellner_direkt'] ?? 0);
    $verkaufKellnerAnteil = (float) ($revSummary['verkauf_kellner_anteil'] ?? 0);
    $verkaufDirektAnteil = (float) ($revSummary['verkauf_direktverkauf_anteil'] ?? 0);
    $verkaufEchtUnzugeordnet = (float) ($revSummary['verkauf_echt_unzugeordnet'] ?? 0);

    $hinweis = null;
    if ($hasFestIdCol && $festId > 0) {
        // ok
    } elseif ($hasFestIdCol && $festId <= 0) {
        $hinweis = 'Kein aktuelles Fest gewählt – Umsätze beziehen sich auf alle bezahlten Zeilen in der Datenbank.';
    } elseif (!$hasFestIdCol) {
        $hinweis = 'Spalte fest_id fehlt in bestellungen – Auswertung ohne Fest-Filter (gesamte Historie).';
    }

    $printerTargets = ff_print_targets_active_list($conn);
    $warnAfter = (int) setting_get($conn, 'printer_warn_after_sec', '60');
    $nowTs = time();
    $printerStatus = [];
    $printerServiceLabels = [];
    foreach ($printerTargets as $pt) {
        $id = (int) $pt['print_target'];
        $svc = 'target_' . $id;
        $hb = ff_printer_heartbeat_read($conn, $id, $warnAfter, $nowTs);
        $displayName = (string) ($pt['name'] ?? ff_print_target_display_name($conn, $id));
        $printerServiceLabels[$svc] = $displayName;
        $printerStatus[$svc] = [
            'print_target' => $id,
            'state' => $hb['state'],
            'last_seen' => $hb['last_seen'],
            'age_sec' => $hb['age_sec'],
            'host' => $hb['host'],
            'display_name' => $displayName,
        ];
    }

    $staleServices = [];
    foreach ($printerStatus as $svc => $row) {
        if (($row['state'] ?? '') === 'stale') {
            $staleServices[] = $svc;
        }
    }

    $jobsByStatus = ['pending' => 0, 'reserved' => 0, 'done' => 0, 'error' => 0];
    $stuckReserved = 0;
    $stuckMinutes = (int) setting_get($conn, 'printer_job_stuck_reserved_min', '10');
    if ($stuckMinutes < 2) {
        $stuckMinutes = 10;
    }
    try {
        $pjChk = mysqli_query($conn, "SHOW TABLES LIKE 'printer_jobs'");
        if ($pjChk && mysqli_num_rows($pjChk) > 0) {
            mysqli_free_result($pjChk);
            $rj = mysqli_query($conn, 'SELECT status, COUNT(*) AS c FROM printer_jobs GROUP BY status');
            if ($rj) {
                while ($jr = mysqli_fetch_assoc($rj)) {
                    $st = (string) ($jr['status'] ?? '');
                    if (isset($jobsByStatus[$st])) {
                        $jobsByStatus[$st] = (int) $jr['c'];
                    }
                }
            }
            $sqStuck = 'SELECT COUNT(*) AS c FROM printer_jobs WHERE status = \'reserved\'
    AND reserved_at IS NOT NULL
    AND reserved_at < DATE_SUB(NOW(), INTERVAL ' . (int) $stuckMinutes . ' MINUTE)';
            $rsStuck = mysqli_query($conn, $sqStuck);
            if ($rsStuck && ($stRow = mysqli_fetch_assoc($rsStuck))) {
                $stuckReserved = (int) ($stRow['c'] ?? 0);
            }
        } elseif ($pjChk) {
            mysqli_free_result($pjChk);
        }
    } catch (Throwable $e) {
        // Tabelle printer_jobs fehlt oder Strict-Mode — KPIs ohne Job-Zähler
    }

    $printIssues = [];
    if (count($staleServices) > 0) {
        $printIssues[] = 'stale_heartbeat';
    }
    if (($jobsByStatus['error'] ?? 0) > 0) {
        $printIssues[] = 'job_errors';
    }
    if ($stuckReserved > 0) {
        $printIssues[] = 'stuck_reserved';
    }
    $printHasIssues = count($printIssues) > 0;

    $positionStock = ff_position_stock_limited_list($conn);

    $payload = [
        'ok' => true,
        'fest_id' => $festId,
        'fest_name' => $festName,
        'datum_heute' => $today,
        'umsatz_heute' => round($heute['sum'], 2),
        'zeilen_heute' => $heute['count'],
        'umsatz_gesamt' => round($gesamt['sum'], 2),
        'verkauf_unzugeordnet' => $verkaufUnzugeordnet,
        'verkauf_kellner_direkt' => $verkaufKellnerDirekt,
        'verkauf_kellner_anteil' => $verkaufKellnerAnteil,
        'verkauf_direktverkauf_anteil' => $verkaufDirektAnteil,
        'verkauf_echt_unzugeordnet' => $verkaufEchtUnzugeordnet,
        'verkauf_gesamt' => (float) ($revSummary['verkauf_gesamt'] ?? round($gesamt['sum'], 2)),
        'umsatz_bereiche_summe' => $umsatzBereicheSumme,
        'umsatz_gesamt_kombiniert' => $umsatzGesamtKombiniert,
        'umsatz_kellner' => round($kellnerGes['sum'], 2),
        'zeilen_gesamt' => $gesamt['count'],
        'zeilen_kellner' => $kellnerGes['count'],
        'bereiche_umsatz' => $bereicheUmsatz,
        'hinweis' => $hinweis,
        'printer_warn_after_sec' => $warnAfter,
        'printer_job_stuck_reserved_min' => $stuckMinutes,
        'printer_services' => $printerStatus,
        'printer_service_labels' => $printerServiceLabels,
        'printer_stale_services' => $staleServices,
        'printer_jobs_by_status' => $jobsByStatus,
        'printer_jobs_stuck_reserved' => $stuckReserved,
        'printer_has_issues' => $printHasIssues,
        'printer_issue_codes' => $printIssues,
        'position_stock' => $positionStock,
    ];

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($encoded !== false) {
        $tmp = $cacheFile . '.tmp';
        if (@file_put_contents($tmp, $encoded, LOCK_EX) !== false) {
            @rename($tmp, $cacheFile);
        }
    }
    $memo = $payload;
    $memoExpires = $now + $cacheTtl;

    return $payload;
}
