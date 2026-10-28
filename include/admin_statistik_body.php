<?php
/**
 * Statistik-Bereich: nur HTML für .card-body (ohne äußere Card).
 * Eigenständige Helfer, damit admin_statistik_content.php ohne admin_page.php auskommt.
 *
 * Optionaler Filter $filterUser: alle Tabellen auf einen Benutzer bezogen
 * (Kasse: kellnerZahlung, Aufnahme: kellner, siehe Hinweise im UI).
 */
declare(strict_types=1);

require_once __DIR__ . '/user_landing.php';
require_once __DIR__ . '/ff_admin_ui_helpers.php';

function ff_stat_out(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function ff_stat_format_eur($amount): string {
    $amount = (float)$amount;
    if (class_exists('NumberFormatter')) {
        $fmt = new \NumberFormatter('de_AT', \NumberFormatter::CURRENCY);
        return preg_replace('/\bEUR\b/u', '€', (string)$fmt->formatCurrency($amount, 'EUR')) ?: (string)$fmt->formatCurrency($amount, 'EUR');
    }
    return number_format($amount, 2, ',', '.') . ' €';
}

function ff_stat_format_duration_seconds($seconds): string {
    $s = (int)round((float)$seconds);
    if ($s < 0) {
        $s = 0;
    }
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $sec);
}

/** Eine Tabellen-/CSV-Zeile: „Anzeigename (login)“, sonst Login. */
function ff_stat_kellner_plain_label(mysqli $conn, ?string $loginRaw): string {
    $login = trim((string) $loginRaw);
    if ($login === '') {
        return '';
    }
    $dn = ff_user_display_label($conn, $login);
    if ($dn === '' || strcasecmp($dn, $login) === 0) {
        return $login;
    }

    return $dn . ' (' . $login . ')';
}

/** HTML für Statistik-Spalten (Login + gedämpfter Login wenn abweichend). */
function ff_stat_kellner_td_html(mysqli $conn, ?string $loginRaw): string {
    $login = trim((string) $loginRaw);
    if ($login === '') {
        return '–';
    }
    $dn = ff_user_display_label($conn, $login);
    if ($dn === '' || strcasecmp($dn, $login) === 0) {
        return ff_stat_out($login);
    }

    return ff_stat_out($dn) . ' <span class="text-muted small">(' . ff_stat_out($login) . ')</span>';
}

/** Text im Kellner-Dropdown: „Maria M. · maria“. */
function ff_stat_username_select_label(mysqli $conn, string $login): string {
    $login = trim($login);
    if ($login === '') {
        return '';
    }
    $dn = ff_user_display_label($conn, $login);
    if ($dn === '' || strcasecmp($dn, $login) === 0) {
        return $login;
    }

    return $dn . ' · ' . $login;
}

/** Benutzernamen aus users + tatsächlich vorkommende kellner/kellnerZahlung */
function ff_admin_statistik_usernames(mysqli $conn): array {
    $sql = "SELECT u FROM (
        SELECT DISTINCT TRIM(kellnerZahlung) AS u FROM bestellungen
            WHERE kellnerZahlung IS NOT NULL AND TRIM(kellnerZahlung) <> ''
        UNION
        SELECT DISTINCT TRIM(kellner) AS u FROM bestellungen
            WHERE kellner IS NOT NULL AND TRIM(kellner) <> ''
        UNION
        SELECT username AS u FROM users
            WHERE username IS NOT NULL AND TRIM(username) <> ''
    ) t WHERE u IS NOT NULL AND TRIM(u) <> '' ORDER BY u";
    $names = [];
    $res = @mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $u = trim((string)($row['u'] ?? ''));
            if ($u !== '') {
                $names[] = $u;
            }
        }
    }
    $names = array_values(array_unique($names));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

/**
 * @return array{kEsc: string, fKasse: string, fAufnahme: string, fBeidesB: string, fBeidesPlain: string}
 */
function ff_admin_statistik_filter_sql(mysqli $conn, ?string $filterUser): array {
    $empty = [
        'kEsc' => '',
        'fKasse' => '',
        'fAufnahme' => '',
        'fBeidesB' => '',
        'fBeidesPlain' => '',
    ];
    if ($filterUser === null || trim($filterUser) === '') {
        return $empty;
    }
    $kEsc = mysqli_real_escape_string($conn, trim($filterUser));
    return [
        'kEsc' => $kEsc,
        'fKasse' => " AND bestellungen.kellnerZahlung = '{$kEsc}' ",
        'fAufnahme' => " AND bestellungen.kellner = '{$kEsc}' ",
        'fBeidesB' => " AND (b.kellnerZahlung = '{$kEsc}' OR b.kellner = '{$kEsc}') ",
        'fBeidesPlain' => " AND (bestellungen.kellnerZahlung = '{$kEsc}' OR bestellungen.kellner = '{$kEsc}') ",
    ];
}

/**
 * @return array{from_date:string,from_time:string,to_date:string,to_time:string,sql:string}
 */
function ff_admin_statistik_datetime_filter(
    mysqli $conn,
    string $columnExpr,
    ?string $dateFrom = null,
    ?string $timeFrom = null,
    ?string $dateTo = null,
    ?string $timeTo = null
): array {
    $fromDate = trim((string)($dateFrom ?? ''));
    $toDate = trim((string)($dateTo ?? ''));
    $fromTime = trim((string)($timeFrom ?? ''));
    $toTime = trim((string)($timeTo ?? ''));

    if ($fromDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        $fromDate = '';
    }
    if ($toDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
        $toDate = '';
    }
    if ($fromTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $fromTime)) {
        $fromTime = '';
    }
    if ($toTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $toTime)) {
        $toTime = '';
    }

    // Nur Zeit ohne Datum ist nicht sinnvoll -> ignorieren.
    if ($fromDate === '') {
        $fromTime = '';
    }
    if ($toDate === '') {
        $toTime = '';
    }

    $fromDt = '';
    $toDt = '';
    if ($fromDate !== '') {
        $fromDt = $fromDate . ' ' . ($fromTime !== '' ? ($fromTime . ':00') : '00:00:00');
    }
    if ($toDate !== '') {
        $toDt = $toDate . ' ' . ($toTime !== '' ? ($toTime . ':59') : '23:59:59');
    }

    if ($fromDt !== '' && $toDt !== '' && strcmp($fromDt, $toDt) > 0) {
        $tmp = $fromDt;
        $fromDt = $toDt;
        $toDt = $tmp;
    }

    $sql = '';
    if ($fromDt !== '') {
        $fEsc = mysqli_real_escape_string($conn, $fromDt);
        $sql .= " AND {$columnExpr} >= '{$fEsc}'";
    }
    if ($toDt !== '') {
        $tEsc = mysqli_real_escape_string($conn, $toDt);
        $sql .= " AND {$columnExpr} <= '{$tEsc}'";
    }

    return [
        'from_date' => $fromDate,
        'from_time' => $fromTime,
        'to_date' => $toDate,
        'to_time' => $toTime,
        'sql' => $sql,
    ];
}

/**
 * @return array{from_date:string,from_time:string,to_date:string,to_time:string,sql:string}
 */
function ff_admin_statistik_paid_date_filter(
    mysqli $conn,
    ?string $dateFrom = null,
    ?string $timeFrom = null,
    ?string $dateTo = null,
    ?string $timeTo = null
): array {
    return ff_admin_statistik_datetime_filter($conn, 'bestellungen.timestampBezahlung', $dateFrom, $timeFrom, $dateTo, $timeTo);
}

/**
 * @return array{from_date:string,from_time:string,to_date:string,to_time:string,sql:string}
 */
function ff_admin_statistik_capture_date_filter(
    mysqli $conn,
    ?string $dateFrom = null,
    ?string $timeFrom = null,
    ?string $dateTo = null,
    ?string $timeTo = null
): array {
    return ff_admin_statistik_datetime_filter($conn, 'bestellungen.zeitstempel', $dateFrom, $timeFrom, $dateTo, $timeTo);
}

function ff_admin_render_statistik_inner(
    mysqli $conn,
    ?string $filterUser = null,
    ?string $dateFrom = null,
    ?string $dateTo = null,
    ?string $timeFrom = null,
    ?string $timeTo = null
): void {
    ff_users_ensure_landing_columns($conn);
    $F = ff_admin_statistik_filter_sql($conn, $filterUser);
    $hasF = $F['kEsc'] !== '';
    $paidDate = ff_admin_statistik_paid_date_filter($conn, $dateFrom, $timeFrom, $dateTo, $timeTo);
    $captureDate = ff_admin_statistik_capture_date_filter($conn, $dateFrom, $timeFrom, $dateTo, $timeTo);
    $paidDateB = ff_admin_statistik_datetime_filter($conn, 'b.timestampBezahlung', $dateFrom, $timeFrom, $dateTo, $timeTo);
    $hasDateFilter = ($paidDate['sql'] !== '');

    try {
        echo '<div class="d-flex align-items-center gap-2 mb-3">';
        echo '<span class="text-muted small">Statistik</span>';
        echo ff_admin_info_btn('statHintOverview', 'Hinweise zur Statistik');
        if ($hasF) {
            echo '<span class="badge bg-info text-dark">Benutzerfilter aktiv</span>';
        }
        if ($hasDateFilter) {
            echo '<span class="badge bg-secondary">Zeitraum aktiv</span>';
        }
        echo '</div>';
        $overviewHtml = '<p class="mb-2">Ergänzt das <strong>Dashboard</strong> um Verläufe, Positionsfilter und Diagramme.</p>';
        if ($hasF) {
            $overviewHtml .= '<p class="mb-2"><strong>Benutzerfilter:</strong> ';
            $overviewHtml .= ff_stat_kellner_td_html($conn, $filterUser ?? '');
            $overviewHtml .= ' — <em>Abgerechnet</em> = <code>kellnerZahlung</code>; <em>Aufgenommen</em> = <code>kellner</code>; '
                . 'weitere Tabellen: Kasse <strong>oder</strong> Aufnahme.</p>';
        }
        if ($hasDateFilter) {
            $overviewHtml .= '<p class="mb-0"><strong>Zeitraum:</strong> Bezahlte Umsätze nach <code>timestampBezahlung</code>; '
                . 'Aufnahme/Wartezeiten/Position (Gast) nach <code>zeitstempel</code>. Leer = ganze Historie.</p>';
        }
        if (!$hasF && !$hasDateFilter) {
            $overviewHtml .= '<p class="mb-0">Filter oben und Datumsfelder unten einschränken die Tabellen.</p>';
        }
        ff_admin_info_panel('statHintOverview', $overviewHtml);

        $qrOrderSql = 'SELECT
                CASE WHEN bestellungen.kellner LIKE \'QR-Tisch-%\'
                    THEN \'QR-Code\'
                    ELSE \'Ohne QR-Code\'
                END AS bestellart,
                COUNT(*) AS anzahl
            FROM bestellungen
            WHERE bestellungen.`delete` = 0 '
            . ($hasF ? $F['fBeidesPlain'] : '')
            . $captureDate['sql']
            . ' GROUP BY bestellart';
        $qrOrderResult = mysqli_query($conn, $qrOrderSql);
        $qrOrderCounts = ['QR-Code' => 0, 'Ohne QR-Code' => 0];
        if ($qrOrderResult) {
            while ($qrOrderRow = mysqli_fetch_assoc($qrOrderResult)) {
                $orderType = (string)($qrOrderRow['bestellart'] ?? '');
                if (isset($qrOrderCounts[$orderType])) {
                    $qrOrderCounts[$orderType] = (int)$qrOrderRow['anzahl'];
                }
            }
        }
        $qrOrderTotal =
            $qrOrderCounts['QR-Code'] +
            $qrOrderCounts['Ohne QR-Code'];
        $qrOrderQrPercent = $qrOrderTotal > 0
            ? ($qrOrderCounts['QR-Code'] * 100 / $qrOrderTotal)
            : 0;
        $qrOrderClassicPercent = $qrOrderTotal > 0
            ? ($qrOrderCounts['Ohne QR-Code'] * 100 / $qrOrderTotal)
            : 0;
        echo '<div class="ff-stat-tabs" id="ffStatTabs" role="tablist">';
        echo '<button type="button" class="ff-stat-tab" data-stat-tab="uebersicht" onclick="ffStatShowTab(\'uebersicht\')">Übersicht</button>';
        echo '<button type="button" class="ff-stat-tab" data-stat-tab="kellner" onclick="ffStatShowTab(\'kellner\')">Kellner</button>';
        echo '<button type="button" class="ff-stat-tab" data-stat-tab="tische" onclick="ffStatShowTab(\'tische\')">Umsatz je Tisch</button>';
        echo '<button type="button" class="ff-stat-tab" data-stat-tab="wartezeit" onclick="ffStatShowTab(\'wartezeit\')">Wartezeit</button>';
        echo '<button type="button" class="ff-stat-tab" data-stat-tab="haeufigkeit" onclick="ffStatShowTab(\'haeufigkeit\')">Häufigkeit</button>';
        echo '<button type="button" class="ff-stat-tab" data-stat-tab="offen" onclick="ffStatShowTab(\'offen\')">Offene Bestellungen</button>';
        echo '<button type="button" class="ff-stat-tab" data-stat-tab="position" onclick="ffStatShowTab(\'position\')">Position nach Zeitraum</button>';
        echo '</div>';
        echo '<div class="ff-stat-common">';
        echo '<div class="ff-stat-filter">';
        echo '<div class="ff-stat-field"><label class="form-label form-label-sm small mb-1">Von Datum</label><input type="date" id="ffStatVon" class="form-control form-control-sm" value="' . ff_stat_out($paidDate['from_date']) . '"></div>';
        echo '<div class="ff-stat-field"><label class="form-label form-label-sm small mb-1">Von Uhrzeit (optional)</label><input type="time" id="ffStatVonZeit" class="form-control form-control-sm" value="' . ff_stat_out($paidDate['from_time']) . '"></div>';
        echo '<div class="ff-stat-field"><label class="form-label form-label-sm small mb-1">Bis Datum</label><input type="date" id="ffStatBis" class="form-control form-control-sm" value="' . ff_stat_out($paidDate['to_date']) . '"></div>';
        echo '<div class="ff-stat-field"><label class="form-label form-label-sm small mb-1">Bis Uhrzeit (optional)</label><input type="time" id="ffStatBisZeit" class="form-control form-control-sm" value="' . ff_stat_out($paidDate['to_time']) . '"></div>';
        echo '<div class="ff-stat-applywrap"><button type="button" class="btn btn-dark btn-sm ff-stat-apply" onclick="ffReloadStatistikBody();">Filter anwenden</button></div>';
        echo '</div>'; echo '<details class="ff-stat-exports"><summary>Exporte (CSV) ' . ff_admin_info_btn('statHintExports', 'Hinweis: Exporte & Zeitraum') . '</summary><div class="d-flex flex-wrap gap-2 mt-2">';
        echo '<div><button type="button" class="btn btn-outline-success btn-sm" onclick="ffStatExportKellnerCsv();">Excel-Export verrechnet (CSV)</button></div>';
        echo '<div><button type="button" class="btn btn-outline-success btn-sm" onclick="ffStatExportKellnerAufgenommenCsv();">Excel-Export Aufnahme (CSV)</button></div>';
        echo '<div><button type="button" class="btn btn-outline-success btn-sm" onclick="ffStatExportDetailCsv();">Excel-Export Einzelzeilen (CSV)</button></div>';
        echo '</div></details>';
        ff_admin_info_panel(
            'statHintExports',
            '<p class="mb-2"><strong>Einzelzeilen-CSV:</strong> jede Gastbestellung und Mitarbeiter-Verpflegung mit Artikel, Preis, Menge – in Excel filterbar (z.&nbsp;B. Tisch 999999 = Direktverkauf).</p>'
            . '<p class="mb-2"><strong>Zeitraum</strong> (Von/Bis) gilt für alle Tabellen und „Position nach Zeitraum“. Leer = gesamte Historie.</p>'
            . '<p class="mb-0"><em>Abgerechnet</em> / Umsatz pro Tisch: <code>timestampBezahlung</code>. '
            . '<em>Aufgenommen</em> (Kellner): nur Zeilen mit <code>ausgeliefert=1</code> (nach Auslieferung durch Küche/Schank). '
            . 'Wartezeiten, Häufigkeit: <code>zeitstempel</code>.</p>'
        );
        echo '</div>';
        echo '<div class="ff-stat-sec" data-stat-sec="uebersicht">';
        echo '<div class="card border-secondary mb-3">';
        echo '<div class="card-body">';
        echo '<h5 class="card-title">Bestellzeilen mit und ohne QR-Code</h5>';
        echo '<div class="row align-items-center g-3">';
        echo '<div class="col-md-5"><canvas id="qrOrderTypeChart" height="220"'
            . ' data-qr-count="' . $qrOrderCounts['QR-Code'] . '"'
            . ' data-classic-count="' . $qrOrderCounts['Ohne QR-Code'] . '"></canvas></div>';
        echo '<div class="col-md-7 small">';
        echo '<div><strong>QR-Code:</strong> ' . $qrOrderCounts['QR-Code']
            . ' (' . number_format($qrOrderQrPercent, 1, ',', '.') . ' %)</div>';
        echo '<div><strong>Ohne QR-Code:</strong> ' . $qrOrderCounts['Ohne QR-Code']
            . ' (' . number_format($qrOrderClassicPercent, 1, ',', '.') . ' %)</div>';
        echo '</div></div></div></div>';

        if ($hasF) {
            $qAll = 'SELECT COUNT(*) as cnt FROM bestellungen WHERE `delete` = 0 ' . $F['fBeidesPlain'] . $captureDate['sql'];
            $qDel = 'SELECT COUNT(*) as cnt FROM bestellungen WHERE `delete` = 1 ' . $F['fBeidesPlain'];
        } else {
            $qAll = 'SELECT COUNT(*) as cnt FROM bestellungen WHERE `delete` = 0' . $captureDate['sql'];
            $qDel = 'SELECT COUNT(*) as cnt FROM bestellungen WHERE `delete` = 1';
        }
        $sql4 = mysqli_query($conn, $qAll);
        $cntAll = 0;
        while ($row4 = mysqli_fetch_assoc($sql4)) {
            $cntAll = (int)$row4['cnt'];
        }
        $sql5 = mysqli_query($conn, $qDel);
        $cntDel = 0;
        while ($row = mysqli_fetch_assoc($sql5)) {
            $cntDel = (int)$row['cnt'];
        }

        $sumLabel = $hasF ? 'Bestellzeilen (mit diesem Benutzer)' : 'Bestellzeilen (nicht storniert)';
        if ($hasDateFilter) {
            $sumLabel .= ', Zeitraum gefiltert';
        }
        echo '<p class="mb-3"><strong>' . ff_stat_out($sumLabel) . ':</strong> ' . $cntAll . ' &nbsp;|&nbsp; <strong>Storniert (ohne Zeitraum):</strong> ' . $cntDel . '</p>';
        echo '</div>';
        echo '<div class="ff-stat-sec" data-stat-sec="kellner">';
        echo '<div class="d-flex align-items-center gap-2 mt-3 mb-2" id="ffStatKellnerAbgerechnet">';
        echo '<h5 class="mb-0">Kellner abgerechnete Positionen</h5>';
        echo ff_admin_info_btn('statHintKellner', 'Hinweis: Kellner-Tabellen');
        echo '</div>';
        ff_admin_info_panel(
            'statHintKellner',
            '<p class="mb-2">Abgerechnet: Umsätze nach <code>kellnerZahlung</code> (Kasse). Bei Sammelrechnung vor Bezahlen Kellner wählen.</p>'
            . '<p class="mb-0">Aufgenommen: wer die Bestellung erfasst hat (<code>kellner</code>).</p>'
        );
        $fAb = $hasF ? $F['fKasse'] : '';
        $sql = 'SELECT COUNT(*) as cnt, bestellungen.kellnerZahlung, SUM(COALESCE(NULLIF(bestellungen.betrag, 0), positionen.Betrag)) as summe FROM bestellungen, positionen WHERE bestellungen.position=positionen.rowid AND bestellungen.`delete`=0 AND bestellungen.timestampBezahlung!=\'0000-00-00 00:00:00\' AND IFNULL(bestellungen.is_gratis,0)=0 AND IFNULL(bestellungen.schreibaus,0)=0 ' . $fAb . $paidDate['sql'] . ' GROUP BY bestellungen.kellnerZahlung ORDER BY bestellungen.kellnerZahlung';
        $result = mysqli_query($conn, $sql);
        echo '<div class="table-responsive mb-3"><table class="table table-sm table-hover"><thead><tr><th>Kellner <span class="text-muted fw-normal small">(Anzeige · Login)</span></th><th>Anzahl</th><th>Betrag</th></tr></thead><tbody>';
        $summe = 0.0;
        while ($row = mysqli_fetch_assoc($result)) {
            echo '<tr><td>' . ff_stat_kellner_td_html($conn, (string) ($row['kellnerZahlung'] ?? '')) . '</td><td>' . (int) $row['cnt'] . '</td><td class="text-end">' . ff_stat_format_eur($row['summe']) . '</td></tr>';
            $summe += (float)$row['summe'];
        }
        echo '<tr class="table-light"><td><strong>Summe</strong></td><td></td><td class="text-end"><strong>' . ff_stat_format_eur($summe) . '</strong></td></tr></tbody></table></div>';

        echo '<h5 class="mt-3">Kellner aufgenommene Positionen (ausgeliefert)</h5>';
        echo '<p class="small text-muted mb-2">Gezählt werden Positionen, die von Küche/Schank als <strong>ausgeliefert</strong> markiert wurden — nicht nur „an Küche gesendet“.</p>';
        $fAuf = $hasF ? $F['fAufnahme'] : '';
        $sql = 'SELECT kellner, COUNT(*) as anzahl FROM bestellungen JOIN positionen ON bestellungen.position=positionen.rowid WHERE bestellungen.ausgeliefert=1 AND bestellungen.`delete`=0 ' . $fAuf . $captureDate['sql'] . ' GROUP BY kellner';
        $result = mysqli_query($conn, $sql);
        echo '<div class="table-responsive mb-3"><table class="table table-sm table-hover"><thead><tr><th>Kellner <span class="text-muted fw-normal small">(Anzeige · Login)</span></th><th>Anzahl</th></tr></thead><tbody>';
        while ($row = mysqli_fetch_assoc($result)) {
            echo '<tr><td>' . ff_stat_kellner_td_html($conn, (string) ($row['kellner'] ?? '')) . '</td><td class="text-end">' . (int) $row['anzahl'] . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '</div>';
        echo '<div class="ff-stat-sec" data-stat-sec="tische">';
        echo '<h5 class="mt-3">Bezahlter Umsatz pro Tisch</h5>';
        echo '<p class="small text-muted mb-2">Summe aus <code>bestellungen.betrag</code> bzw. Positionspreis, nur <strong>bezahlte</strong> Zeilen (ohne Gratis/Schreibaus).</p>';
        $fB = $hasF ? $F['fBeidesB'] : '';
        $sql = 'SELECT SUM(COALESCE(NULLIF(b.betrag, 0), p.Betrag)) AS summe, t.tischname
                FROM bestellungen b
                JOIN positionen p ON p.rowid = b.position
                JOIN tische t ON t.tischnummer = b.tischnummer
                WHERE b.`delete` = 0
                  AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung <> \'0000-00-00 00:00:00\'
                  AND IFNULL(b.is_gratis, 0) = 0 AND IFNULL(b.schreibaus, 0) = 0
                  ' . $fB . $paidDateB['sql'] . '
                GROUP BY b.tischnummer, t.tischname
                ORDER BY t.tischname ASC';
        $result = mysqli_query($conn, $sql);
        echo '<div class="table-responsive mb-3"><table class="table table-sm table-hover"><thead><tr><th>Tisch</th><th>Umsatz</th></tr></thead><tbody>';
        while ($row = mysqli_fetch_assoc($result)) {
            echo '<tr><td>' . ff_stat_out((string)$row['tischname']) . '</td><td class="text-end">' . ff_stat_format_eur($row['summe']) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '</div>';
        echo '<div class="ff-stat-sec" data-stat-sec="wartezeit">';
        echo '<h5 class="mt-3">Wartezeit je Position</h5>';
        $fW = $hasF ? $F['fBeidesPlain'] : '';
        $sql = 'SELECT positionen.Positionsname, AVG(TIMESTAMPDIFF(SECOND, bestellungen.zeitstempel, zeitKueche)) AS avgsec, MAX(TIMESTAMPDIFF(SECOND, bestellungen.zeitstempel, zeitKueche)) AS maxsec FROM bestellungen JOIN positionen ON positionen.rowid = bestellungen.position WHERE bestellungen.`delete`=0 AND bestellungen.`kueche`=1 AND bestellungen.zeitKueche!=\'0000-00-00 00:00:00\' ' . $fW . $captureDate['sql'] . ' GROUP BY bestellungen.position ORDER BY avgsec DESC';
        $result = mysqli_query($conn, $sql);
        echo '<div class="table-responsive mb-3"><table class="table table-sm table-hover"><thead><tr><th>Position</th><th>Ø Wartezeit (hh:mm:ss)</th><th>Max (hh:mm:ss)</th></tr></thead><tbody>';
        while ($row = mysqli_fetch_assoc($result)) {
            echo '<tr><td>' . ff_stat_out((string)$row['Positionsname']) . '</td><td>' . ff_stat_out(ff_stat_format_duration_seconds($row['avgsec'] ?? 0)) . '</td><td>' . ff_stat_out(ff_stat_format_duration_seconds($row['maxsec'] ?? 0)) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '</div>';
        echo '<div class="ff-stat-sec" data-stat-sec="haeufigkeit">';
        echo '<h5 class="mt-3">Speisen – Bestellhäufigkeit</h5>';
        $sql = 'SELECT positionen.Positionsname, COUNT(*) as cnt FROM bestellungen, positionen WHERE positionen.rowid = bestellungen.position AND bestellungen.`delete`=0 ' . $fW . $captureDate['sql'] . ' GROUP BY bestellungen.position ORDER BY cnt DESC';
        $result = mysqli_query($conn, $sql);
        echo '<div class="table-responsive mb-3"><table class="table table-sm table-hover"><thead><tr><th>Name</th><th>x Bestellt</th></tr></thead><tbody>';
        while ($row5 = mysqli_fetch_assoc($result)) {
            echo '<tr><td>' . ff_stat_out((string)$row5['Positionsname']) . '</td><td class="text-end">' . (int)$row5['cnt'] . '</td></tr>';
        }
        echo '</tbody></table></div>';

        $qWart = 'SELECT COUNT(*) as cnt FROM bestellungen WHERE `delete`=0 AND `kueche`=0 AND zeitKueche=\'0000-00-00 00:00:00\' ' . $fW . $captureDate['sql'];
        $result = mysqli_query($conn, $qWart);
        $wartend = 0;
        while ($row3 = mysqli_fetch_assoc($result)) {
            $wartend = (int)$row3['cnt'];
        }
        echo '</div>';
        echo '<div class="ff-stat-sec" data-stat-sec="offen">';
        echo '<h5 class="mt-3">Aktuell offene Bestellungen</h5>';
        if ($hasDateFilter) {
            echo '<p class="small text-muted">Nur noch <strong>nicht küchenbestätigte</strong> Bestellungen mit <code>zeitstempel</code> im gewählten Zeitraum.</p>';
        }
        echo '<p><strong>' . $wartend . ' Positionen wartend</strong> (Küche/Schank)</p>';
        $sql = 'SELECT TIMEDIFF(now(),zeitstempel) as zeit, FLOOR(UNIX_TIMESTAMP(zeitstempel)/120) AS t, COUNT(*) as cnt FROM bestellungen WHERE `delete`=0 AND `kueche`=0 AND zeitKueche=\'0000-00-00 00:00:00\' ' . $fW . $captureDate['sql'] . ' GROUP BY t ORDER BY t DESC LIMIT 10';
        $result = mysqli_query($conn, $sql);
        echo '<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Zeit</th><th>Wartezeit</th></tr></thead><tbody>';
        while ($row4 = mysqli_fetch_assoc($result)) {
            echo '<tr><td>' . date('H:i', ((int)$row4['t']) * 120) . '</td><td>' . ff_stat_out((string)$row4['zeit']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '</div>';

        $resPos = mysqli_query($conn, 'SELECT rowid, Positionsname, type FROM positionen ORDER BY type, Positionsname');
        $posList = [];
        if ($resPos) {
            while ($r = mysqli_fetch_assoc($resPos)) {
                $posList[] = $r;
            }
        }
        echo '<div class="ff-stat-sec" data-stat-sec="position">';
        echo '<div class="d-flex align-items-center gap-2 mt-3 mb-2">';
        echo '<h5 class="mb-0">Position nach Zeitraum (mit Grafik)</h5>';
        echo ff_admin_info_btn('statHintPosition', 'Hinweis: Positions-Statistik');
        echo '</div>';
        ff_admin_info_panel(
            'statHintPosition',
            '<p class="mb-0">Stückanzahl: alle <strong>bestellten</strong> Gästezeilen (inkl. Gratis, Schreibaus) + Mitarbeiter-Verpflegung. '
            . 'Ein Tag: 15-Minuten-Grafik; mehrere Tage: pro Tag. CSV mit Spalten Gratis/Schreibaus zum Filtern.</p>'
            . '<p class="mb-0 mt-2">Zusätzlich: Ø-Wartezeit „Bestellung → Küche fertig“ je Zeitabschnitt (gestrichelte Linie, rechte Achse) '
            . 'sowie ein Streudiagramm mit der Wartezeit jeder einzelnen Bestellung. Punkte/Linienpunkte über der Schwelle (Standard 15 Min) '
            . 'werden rot markiert, um kritische Zeiträume mit langen Küchenzeiten hervorzuheben.</p>'
        );
        echo '<div class="row g-2 mb-2 align-items-end">';
        echo '<div class="col-md-2"><label class="form-label">Von Datum</label><input type="date" id="posStatVon" class="form-control form-control-sm" value="' . ff_stat_out($captureDate['from_date']) . '"></div>';
        echo '<div class="col-md-2"><label class="form-label">Bis Datum</label><input type="date" id="posStatBis" class="form-control form-control-sm" value="' . ff_stat_out($captureDate['to_date']) . '"></div>';
        echo '<div class="col-md-2"><label class="form-label">Uhrzeit von (optional)</label><input type="time" id="posStatUhrVon" class="form-control form-control-sm" value="' . ff_stat_out($captureDate['from_time']) . '"></div>';
        echo '<div class="col-md-2"><label class="form-label">Uhrzeit bis (optional)</label><input type="time" id="posStatUhrBis" class="form-control form-control-sm" value="' . ff_stat_out($captureDate['to_time']) . '"></div>';
        echo '<div class="col-md-3"><label class="form-label">Position</label><select id="posStatPosition" class="form-select form-select-sm"><option value="">Alle Positionen (Gesamtzahl)</option>';
        foreach ($posList as $p) {
            echo '<option value="' . (int)$p['rowid'] . '">' . ff_stat_out((string)$p['Positionsname']) . ' (' . (((int)$p['type'] === 2) ? 'Getränk' : 'Speise') . ')</option>';
        }
        echo '</select></div>';
        echo '<div class="col-md-1"><button type="button" class="btn btn-primary btn-sm" onclick="posStatAnzeigen();">Anzeigen</button></div>';
        echo '</div>';
        echo '<div class="row g-2 mb-3">';
        echo '<div class="col-auto"><label class="form-check"><input type="checkbox" class="form-check-input" id="posStatInklGast" checked> <span class="form-check-label">Gäste einbeziehen</span></label></div>';
        echo '<div class="col-auto"><label class="form-check"><input type="checkbox" class="form-check-input" id="posStatInklMitarbeiter" checked> <span class="form-check-label">Mitarbeiter einbeziehen</span></label></div>';
        echo '</div>';
        echo '<div id="posStatErgebnis" class="mb-3" style="display:none;">';
        echo '<p class="mb-2"><strong id="posStatTitel"></strong></p>';
        echo '<div class="table-responsive mb-3"><table class="table table-sm table-hover"><thead id="posStatThead"><tr><th>Datum</th><th>Uhrzeit</th><th>Art</th><th>Menge</th><th>Tisch / Bereich</th><th>Notiz</th></tr></thead><tbody id="posStatTbody"></tbody></table></div>';
        echo '<div class="mb-2"><canvas id="posStatChart" width="400" height="200"></canvas></div>';
        echo '<div id="posStatWaitWrap" class="mb-2" style="display:none;">';
        echo '<h6 class="mt-3">Wartezeit je Bestellung (Bestellung → Küche fertig)</h6>';
        echo '<p class="small text-muted mb-1">Jeder Punkt = eine Bestellung, chronologisch sortiert. Rot = Wartezeit über der kritischen Schwelle.</p>';
        echo '<canvas id="posStatWaitChart" width="400" height="160"></canvas>';
        echo '<h6 class="mt-3">Zeitstrahl: Bearbeitungsdauer je Bestellung</h6>';
        echo '<p class="small text-muted mb-1">Jede Linie reicht von Bestellzeit bis Küche-fertig-Zeit. Farbe = Druckziel (z.B. Küche/Schank); rot umrandet = längste Bearbeitungsdauer im Zeitraum.</p>';
        echo '<div id="posStatWaitTimelineLegend" class="d-flex flex-wrap gap-3 small mb-1"></div>';
        echo '<div style="height:420px;"><canvas id="posStatWaitTimelineChart"></canvas></div>';
        echo '</div>';
        echo '<div class="d-flex flex-wrap gap-2 align-items-center">';
        echo '<button type="button" class="btn btn-outline-success btn-sm" onclick="posStatExportCsv();">Excel / CSV exportieren</button>';
        echo ff_admin_info_btn('statHintPosCsv', 'Hinweis: Positions-CSV');
        echo '</div>';
        echo '</div>';
        ff_admin_info_panel('statHintPosCsv', '<p class="mb-0">CSV: Aggregat (Stück) + Einzelzeilen inkl. Gratis/Schreibaus und Mitarbeiter-Mengen.</p>');
        echo '</div>';
    } catch (Throwable $e) {
        echo '<div class="alert alert-danger">' . ff_stat_out($e->getMessage()) . '</div>';
    }
}

function ff_admin_render_statistik_body(
    mysqli $conn,
    ?string $filterUser = null,
    bool $includeChrome = true,
    ?string $dateFrom = null,
    ?string $dateTo = null,
    ?string $timeFrom = null,
    ?string $timeTo = null
): void {
    if ($includeChrome) {
        ff_users_ensure_landing_columns($conn);
        $names = ff_admin_statistik_usernames($conn);
        usort($names, function (string $a, string $b) use ($conn): int {
            return strcasecmp(ff_stat_username_select_label($conn, $a), ff_stat_username_select_label($conn, $b));
        });
        echo '<div class="row g-2 mb-3 align-items-end border-bottom pb-3">';
        echo '<div class="col-md-5 col-lg-4">';
        echo '<label class="form-label small mb-0" for="ffStatUserFilter">Statistik auf einen Benutzer einschränken</label>';
        echo '<div class="d-flex flex-wrap gap-2 align-items-center">';
        echo '<select id="ffStatUserFilter" class="form-select form-select-sm flex-grow-1">';
        echo '<option value="">Alle Benutzer / Kellner</option>';
        foreach ($names as $u) {
            $sel = ($filterUser !== null && $filterUser !== '' && $filterUser === $u) ? ' selected' : '';
            $lab = ff_stat_username_select_label($conn, $u);
            echo '<option value="' . ff_stat_out($u) . '"' . $sel . '>' . ff_stat_out($lab) . '</option>';
        }
        echo '</select>';
        echo '<button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" onclick="ffStatClearUserFilter();" title="Benutzerfilter entfernen">Alle</button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="col-md-7 col-lg-8 d-flex align-items-center gap-2">';
        echo '<span class="small text-muted mb-0">Benutzerfilter</span>';
        echo ff_admin_info_btn('statHintUserFilter', 'Hinweis: Benutzerfilter');
        echo '</div></div>';
        ff_admin_info_panel(
            'statHintUserFilter',
            '<p class="mb-0"><strong>Wert = Loginname</strong>; Anzeige = Display-Name. Leere Auswahl → alle Benutzer. Änderung lädt die Statistik neu.</p>'
        );
        echo '<div id="ffStatistikDynamicBody">';
        ff_admin_render_statistik_inner($conn, $filterUser, $dateFrom, $dateTo, $timeFrom, $timeTo);
        echo '</div>';
    } else {
        ff_admin_render_statistik_inner($conn, $filterUser, $dateFrom, $dateTo, $timeFrom, $timeTo);
    }
}
