<?php
/**
 * Gemeinsame Berechnung für Positions-Statistik (JSON-API + CSV-Export).
 *
 * @param array{
 *   von?: string,
 *   bis?: string,
 *   uhrzeit_von?: string,
 *   uhrzeit_bis?: string,
 *   position_id?: int,
 *   inkl_gast?: int,
 *   inkl_mitarbeiter?: int,
 *   kellner_filter?: string
 * } $in
 * @return array{error?: string, ...}
 */
declare(strict_types=1);

/**
 * Viertelstunden-Index innerhalb eines Tages: 0 = 00:00 … 95 = 23:45.
 */
function ff_statistik_position_bucket_from_ts(int $ts): int
{
    $h = (int) date('G', $ts);
    $m = (int) date('i', $ts);

    return $h * 4 + intdiv($m, 15);
}

function ff_statistik_position_bucket_label(int $bq): string
{
    $h = intdiv($bq, 4);
    $m = ($bq % 4) * 15;

    return sprintf('%02d:%02d', $h, $m);
}

/**
 * Inklusive Viertelstunden-Indizes [start, end] für Tagesgrafik (Filter optional).
 *
 * @return array{0: int, 1: int}
 */
function ff_statistik_position_quarter_range(?string $uhrzeit_von, ?string $uhrzeit_bis): array
{
    $start = 0;
    $end = 95;
    $uv = $uhrzeit_von !== null ? trim($uhrzeit_von) : '';
    $ub = $uhrzeit_bis !== null ? trim($uhrzeit_bis) : '';
    if ($uv !== '' && preg_match('/^(\d{1,2}):(\d{2})/', $uv, $m)) {
        $smin = min(1439, max(0, (int) $m[1] * 60 + (int) $m[2]));
        $start = intdiv($smin, 15);
    }
    if ($ub !== '' && preg_match('/^(\d{1,2}):(\d{2})/', $ub, $m)) {
        $emin = min(1439, max(0, (int) $m[1] * 60 + (int) $m[2]));
        $end = intdiv($emin, 15);
    }
    if ($start > $end) {
        $t = $start;
        $start = $end;
        $end = $t;
    }

    return [$start, $end];
}

/**
 * @return array<string, mixed>|array{error: string}
 */
function ff_statistik_position_data(mysqli $conn, array $in): array
{
    require_once __DIR__ . '/user_landing.php';
    require_once __DIR__ . '/admin_statistik_body.php';
    require_once __DIR__ . '/ff_finance_bereich_helpers.php';

    ff_users_ensure_landing_columns($conn);
    $amtExpr = ff_finance_order_amount_expr('b', 'p');
    $guestBase = "b.`delete` = 0 AND b.bestellt = 1";
    $eventTs = "COALESCE(NULLIF(b.timestampBezahlung, '0000-00-00 00:00:00'), b.zeitstempel)";
    // Schwelle (Minuten) ab der eine Bestellung -> Küche-fertig-Wartezeit als "kritisch" gilt.
    $waitThresholdMin = 15;

    $vonRaw = trim((string) ($in['von'] ?? ''));
    $bisRaw = trim((string) ($in['bis'] ?? ''));
    $uhrzeit_von = trim((string) ($in['uhrzeit_von'] ?? ''));
    $uhrzeit_bis = trim((string) ($in['uhrzeit_bis'] ?? ''));
    $position_id = (int) ($in['position_id'] ?? 0);
    $inkl_gast = (int) ($in['inkl_gast'] ?? 1) ? 1 : 0;
    $inkl_mitarbeiter = (int) ($in['inkl_mitarbeiter'] ?? 1) ? 1 : 0;
    $kellner_filter = trim((string) ($in['kellner_filter'] ?? ''));

    $kellnerCondB = '';
    $kellnerCondMv = '';
    if ($kellner_filter !== '') {
        $kf = mysqli_real_escape_string($conn, $kellner_filter);
        $kellnerCondB = " AND (b.kellnerZahlung = '{$kf}' OR b.kellner = '{$kf}') ";
        $kellnerCondMv = " AND IFNULL(v.created_by,'') = '{$kf}' ";
    }

    $noDateSpan = ($vonRaw === '' && $bisRaw === '');
    $vonEsc = '';
    $bisEsc = '';
    if (!$noDateSpan) {
        if ($vonRaw !== '' && $bisRaw === '') {
            $bisRaw = $vonRaw;
        }
        if ($bisRaw !== '' && $vonRaw === '') {
            $vonRaw = $bisRaw;
        }
        $vonEsc = mysqli_real_escape_string($conn, $vonRaw);
        $bisEsc = mysqli_real_escape_string($conn, $bisRaw);
    }

    $dateCondB = '';
    $dateCondMv = '';
    if (!$noDateSpan) {
        $dateCondB = " AND DATE({$eventTs}) >= '$vonEsc' AND DATE({$eventTs}) <= '$bisEsc' ";
        $dateCondMv = " AND v.datum >= '$vonEsc' AND v.datum <= '$bisEsc' ";
    }

    $position_name = 'Alle Positionen';
    if ($position_id > 0) {
        $resP = mysqli_query($conn, 'SELECT Positionsname FROM positionen WHERE rowid = ' . $position_id);
        if (!$resP || !($rowP = mysqli_fetch_assoc($resP))) {
            return ['error' => 'Position nicht gefunden'];
        }
        $position_name = (string) ($rowP['Positionsname'] ?? '');
    }

    $posCondB = $position_id > 0 ? ' AND b.position = ' . (int) $position_id : '';
    $posCondMv = $position_id > 0 ? ' AND v.position_id = ' . (int) $position_id : '';

    $timeCondB = '';
    $timeCondMv = '';
    if ($uhrzeit_von !== '') {
        $uv = mysqli_real_escape_string($conn, $uhrzeit_von);
        $timeCondB .= " AND TIME({$eventTs}) >= '$uv' ";
        $timeCondMv .= " AND TIME(v.created_at) >= '$uv' ";
    }
    if ($uhrzeit_bis !== '') {
        $ub = mysqli_real_escape_string($conn, $uhrzeit_bis);
        $timeCondB .= " AND TIME({$eventTs}) <= '$ub' ";
        $timeCondMv .= " AND TIME(v.created_at) <= '$ub' ";
    }

    $gast = [];
    if ($inkl_gast) {
        $sel = $position_id > 0
            ? "{$eventTs} AS event_ts, b.tischnummer, b.kellner, b.kellnerZahlung, b.is_gratis, COALESCE(b.schreibaus,0) AS schreibaus, {$amtExpr} AS line_betrag"
            : "{$eventTs} AS event_ts, b.tischnummer, b.kellner, b.kellnerZahlung, b.is_gratis, COALESCE(b.schreibaus,0) AS schreibaus, {$amtExpr} AS line_betrag, p.Positionsname AS position_name";
        $join = ' JOIN positionen p ON p.rowid = b.position ';
        $sqlB = "SELECT $sel
        FROM bestellungen b $join
        WHERE {$guestBase}
        $dateCondB
        $posCondB $timeCondB $kellnerCondB
        ORDER BY {$eventTs} ASC";
        $resB = mysqli_query($conn, $sqlB);
        if ($resB) {
            while ($row = mysqli_fetch_assoc($resB)) {
                $rawKellner = trim((string) ($row['kellner'] ?? ''));
                $kn = trim((string) ($row['kellnerZahlung'] ?? ''));
                if ($kn === '') {
                    $kn = trim((string) ($row['kellner'] ?? ''));
                }
                $ts = (string) ($row['event_ts'] ?? '');
                $gast[] = [
                    'datum' => $ts !== '' && $ts !== '0000-00-00 00:00:00' ? date('d.m.Y', strtotime($ts)) : '',
                    'zeit' => $ts !== '' && $ts !== '0000-00-00 00:00:00' ? date('H:i', strtotime($ts)) : '',
                    'tischnummer' => $row['tischnummer'],
                    'menge' => 1,
                    'betrag' => round((float) ($row['line_betrag'] ?? 0), 2),
                    'is_gratis' => (int) ($row['is_gratis'] ?? 0),
                    'schreibaus' => (int) ($row['schreibaus'] ?? 0),
                    'kellner' => $kn,
                    'bestellart' => strpos($rawKellner, 'QR-Tisch-') === 0 ? 'QR-Code' : 'Klassisch',
                    'kellner_label' => $kn !== '' ? ff_stat_kellner_plain_label($conn, $kn) : '',
                    'position_name' => $position_id > 0 ? null : ($row['position_name'] ?? ''),
                ];
            }
        }
    }

    $mitarbeiter = [];
    if ($inkl_mitarbeiter) {
        $tblMv = @mysqli_query($conn, "SHOW TABLES LIKE 'mitarbeiter_verpflegung'");
        if ($tblMv && mysqli_num_rows($tblMv) > 0) {
            $sel = $position_id > 0
                ? 'v.datum, v.created_at, v.menge, v.notiz, b.name AS bereich_name'
                : 'v.datum, v.created_at, v.menge, v.notiz, b.name AS bereich_name, p.Positionsname AS position_name';
            $joinPos = $position_id > 0 ? '' : ' JOIN positionen p ON p.rowid = v.position_id ';
            $sqlMv = "SELECT $sel
            FROM mitarbeiter_verpflegung v
            LEFT JOIN mitarbeiter_bereiche b ON b.id = v.bereich_id
            $joinPos
            WHERE 1=1 $dateCondMv
            $posCondMv $timeCondMv $kellnerCondMv
            ORDER BY v.created_at ASC";
            $resMv = @mysqli_query($conn, $sqlMv);
            if ($resMv) {
                while ($row = mysqli_fetch_assoc($resMv)) {
                    $mitarbeiter[] = [
                        'datum' => date('d.m.Y', strtotime($row['datum'])),
                        'zeit' => $row['created_at'] ? date('H:i', strtotime($row['created_at'])) : '–',
                        'menge' => (int) $row['menge'],
                        'bereich' => $row['bereich_name'] ?? '',
                        'notiz' => $row['notiz'] ?? '',
                        'position_name' => $position_id > 0 ? null : ($row['position_name'] ?? ''),
                    ];
                }
            }
        }
    }

    // Küchen-Wartezeit: Bestellung (zeitstempel) -> fertig in der Küche (zeitKueche).
    // Nur Gast-Bestellungen, für die die Küche den Abschluss dokumentiert hat.
    $waitRaw = [];
    if ($inkl_gast) {
        // Effektives Druckziel wie in ff_api_device_sql_effective_print_target(): b.print_target fällt auf positionen.print_target zurück.
        $effPt = "COALESCE(NULLIF(b.print_target, 0), NULLIF(p.print_target, 0), 0)";
        $selWait = "b.zeitstempel AS ts_order, b.zeitKueche AS ts_kueche, TIMESTAMPDIFF(SECOND, b.zeitstempel, b.zeitKueche) AS wartesekunden, pt.name AS druckziel_name"
            . ($position_id > 0 ? '' : ', p.Positionsname AS position_name');
        $sqlWait = "SELECT $selWait
        FROM bestellungen b JOIN positionen p ON p.rowid = b.position
        LEFT JOIN print_targets pt ON pt.print_target = ({$effPt})
        WHERE {$guestBase} AND b.`kueche` = 1 AND b.zeitKueche != '0000-00-00 00:00:00'
        $dateCondB
        $posCondB $timeCondB $kellnerCondB
        ORDER BY b.zeitstempel ASC";
        $resWait = mysqli_query($conn, $sqlWait);
        if ($resWait) {
            while ($row = mysqli_fetch_assoc($resWait)) {
                $sec = (int) ($row['wartesekunden'] ?? 0);
                if ($sec < 0) {
                    continue;
                }
                $tsOrder = (string) ($row['ts_order'] ?? '');
                $waitRaw[] = [
                    'ts' => $tsOrder,
                    'datum' => $tsOrder !== '' ? date('d.m.Y', strtotime($tsOrder)) : '',
                    'zeit' => $tsOrder !== '' ? date('H:i', strtotime($tsOrder)) : '',
                    'kuechezeit' => (string) ($row['ts_kueche'] ?? '') !== '' ? date('H:i', strtotime((string) $row['ts_kueche'])) : '',
                    'wartesekunden' => $sec,
                    'druckziel' => (string) ($row['druckziel_name'] ?? '') !== '' ? (string) $row['druckziel_name'] : 'Unbekannt',
                    'position_name' => $position_id > 0 ? null : ($row['position_name'] ?? ''),
                ];
            }
        }
    }

    // Chart-Daten
    $chartLabels = [];
    $chartGast = [];
    $chartMitarbeiter = [];
    $isOneDay = !$noDateSpan && ($vonRaw !== '' && $bisRaw !== '' && $vonRaw === $bisRaw);
    $chartResolutionMinutes = $isOneDay ? 15 : null;

    if ($noDateSpan) {
        $sumGast = count($gast);
        $sumMit = 0;
        foreach ($mitarbeiter as $mr) {
            $sumMit += (int) ($mr['menge'] ?? 0);
        }
        $chartLabels = ['Alle Zeiten'];
        $chartGast = [$sumGast];
        $chartMitarbeiter = [$sumMit];
        $chartGesamt = [$sumGast + $sumMit];

        $waitCount = count($waitRaw);
        $waitAvgSec = $waitCount > 0 ? array_sum(array_column($waitRaw, 'wartesekunden')) / $waitCount : null;
        $waitMaxSec = $waitCount > 0 ? max(array_column($waitRaw, 'wartesekunden')) : null;

        return [
            'position_name' => $position_name,
            'alle_positionen' => ($position_id <= 0),
            'von' => '',
            'bis' => '',
            'datum_offen' => true,
            'uhrzeit_von' => $uhrzeit_von,
            'uhrzeit_bis' => $uhrzeit_bis,
            'kellner_filter' => $kellner_filter,
            'kellner_filter_label' => $kellner_filter !== '' ? ff_stat_kellner_plain_label($conn, $kellner_filter) : '',
            'gast' => $gast,
            'mitarbeiter' => $mitarbeiter,
            'wartezeiten' => $waitRaw,
            'wartezeit_schwelle_min' => $waitThresholdMin,
            'chart_resolution_minutes' => null,
            'chart' => [
                'labels' => $chartLabels,
                'gast' => $chartGast,
                'mitarbeiter' => $chartMitarbeiter,
                'gesamt' => $chartGesamt,
                'wartezeit_avg_min' => [$waitAvgSec !== null ? round($waitAvgSec / 60, 1) : null],
                'wartezeit_max_min' => [$waitMaxSec !== null ? round($waitMaxSec / 60, 1) : null],
                'wartezeit_anzahl' => [$waitCount],
            ],
            'is_one_day' => false,
        ];
    }

    $waitSumSec = [];
    $waitCntArr = [];
    $waitMaxArr = [];

    if ($isOneDay) {
        [$qStart, $qEnd] = ff_statistik_position_quarter_range($uhrzeit_von, $uhrzeit_bis);
        $idxMap = [];
        $i = 0;
        for ($bq = $qStart; $bq <= $qEnd; $bq++) {
            $chartLabels[] = ff_statistik_position_bucket_label($bq);
            $chartGast[] = 0;
            $chartMitarbeiter[] = 0;
            $idxMap[$bq] = $i;
            ++$i;
        }
        $waitSumSec = array_fill(0, count($chartLabels), 0);
        $waitCntArr = array_fill(0, count($chartLabels), 0);
        $waitMaxArr = array_fill(0, count($chartLabels), 0);
        foreach ($gast as $r) {
            $ts = strtotime($r['datum'] . ' ' . $r['zeit']);
            if ($ts === false) {
                continue;
            }
            $bq = ff_statistik_position_bucket_from_ts($ts);
            if (isset($idxMap[$bq])) {
                ++$chartGast[$idxMap[$bq]];
            }
        }
        foreach ($mitarbeiter as $r) {
            if ($r['zeit'] === '–') {
                continue;
            }
            $ts = strtotime($r['datum'] . ' ' . $r['zeit']);
            if ($ts === false) {
                continue;
            }
            $bq = ff_statistik_position_bucket_from_ts($ts);
            if (isset($idxMap[$bq])) {
                $chartMitarbeiter[$idxMap[$bq]] += $r['menge'];
            }
        }
        foreach ($waitRaw as $wr) {
            $ts = strtotime($wr['ts']);
            if ($ts === false) {
                continue;
            }
            $bq = ff_statistik_position_bucket_from_ts($ts);
            if (isset($idxMap[$bq])) {
                $idx = $idxMap[$bq];
                $waitSumSec[$idx] += $wr['wartesekunden'];
                ++$waitCntArr[$idx];
                if ($wr['wartesekunden'] > $waitMaxArr[$idx]) {
                    $waitMaxArr[$idx] = $wr['wartesekunden'];
                }
            }
        }
    } else {
        $from = new DateTime($vonRaw);
        $to = new DateTime($bisRaw);
        $day = clone $from;
        while ($day <= $to) {
            $dStr = $day->format('Y-m-d');
            $chartLabels[] = $day->format('d.m.');
            $g = 0;
            $m = 0;
            foreach ($gast as $r) {
                $dt = DateTime::createFromFormat('d.m.Y', $r['datum']);
                $dr = $dt ? $dt->format('Y-m-d') : '';
                if ($dr === $dStr) {
                    ++$g;
                }
            }
            foreach ($mitarbeiter as $r) {
                $dt = DateTime::createFromFormat('d.m.Y', $r['datum']);
                $dr = $dt ? $dt->format('Y-m-d') : '';
                if ($dr === $dStr) {
                    $m += $r['menge'];
                }
            }
            $chartGast[] = $g;
            $chartMitarbeiter[] = $m;

            $wSumSec = 0;
            $wCnt = 0;
            $wMax = 0;
            foreach ($waitRaw as $wr) {
                if ($wr['datum'] === '') {
                    continue;
                }
                $dt = DateTime::createFromFormat('d.m.Y', $wr['datum']);
                $dr = $dt ? $dt->format('Y-m-d') : '';
                if ($dr === $dStr) {
                    $wSumSec += $wr['wartesekunden'];
                    ++$wCnt;
                    if ($wr['wartesekunden'] > $wMax) {
                        $wMax = $wr['wartesekunden'];
                    }
                }
            }
            $waitSumSec[] = $wSumSec;
            $waitCntArr[] = $wCnt;
            $waitMaxArr[] = $wMax;

            $day->modify('+1 day');
        }
    }

    $chartGesamt = [];
    $n = count($chartLabels);
    for ($i = 0; $i < $n; ++$i) {
        $chartGesamt[] = ($chartGast[$i] ?? 0) + ($chartMitarbeiter[$i] ?? 0);
    }

    $chartWaitAvgMin = [];
    $chartWaitMaxMin = [];
    for ($i = 0; $i < $n; ++$i) {
        $cnt = $waitCntArr[$i] ?? 0;
        $chartWaitAvgMin[] = $cnt > 0 ? round(($waitSumSec[$i] / $cnt) / 60, 1) : null;
        $chartWaitMaxMin[] = $cnt > 0 ? round($waitMaxArr[$i] / 60, 1) : null;
    }

    return [
        'position_name' => $position_name,
        'alle_positionen' => ($position_id <= 0),
        'von' => $vonRaw,
        'bis' => $bisRaw,
        'datum_offen' => false,
        'uhrzeit_von' => $uhrzeit_von,
        'uhrzeit_bis' => $uhrzeit_bis,
        'kellner_filter' => $kellner_filter,
        'kellner_filter_label' => $kellner_filter !== '' ? ff_stat_kellner_plain_label($conn, $kellner_filter) : '',
        'gast' => $gast,
        'mitarbeiter' => $mitarbeiter,
        'wartezeiten' => $waitRaw,
        'wartezeit_schwelle_min' => $waitThresholdMin,
        'chart_resolution_minutes' => $chartResolutionMinutes,
        'chart' => [
            'labels' => $chartLabels,
            'gast' => $chartGast,
            'mitarbeiter' => $chartMitarbeiter,
            'gesamt' => $chartGesamt,
            'wartezeit_avg_min' => $chartWaitAvgMin,
            'wartezeit_max_min' => $chartWaitMaxMin,
            'wartezeit_anzahl' => $waitCntArr,
        ],
        'is_one_day' => $isOneDay,
    ];
}
