<?php
/**
 * Festabschluss: Gesamtumsatz + Umsatz je Position (getrennt nach gezahltem Einzelpreis und Zusatzinfo).
 * format=csv | html (HTML im Browser „Drucken → PDF“ speichern).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_favicon_helpers.php';
require_once __DIR__ . '/include/fest_report_export.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$fest_id = (int) ($_GET['id'] ?? 0);
$format = strtolower((string) ($_GET['format'] ?? 'html'));
if (!in_array($format, ['csv', 'html'], true)) {
    $format = 'html';
}

if ($fest_id <= 0) {
    http_response_code(400);
    echo 'missing id';
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

$chk = mysqli_query($conn, 'SELECT id, name, code, fest_datum, aktiv FROM feste WHERE id=' . $fest_id . ' LIMIT 1');
if (!$chk || !($fest = mysqli_fetch_assoc($chk))) {
    http_response_code(404);
    echo 'Fest nicht gefunden';
    exit;
}

$festGeschlossen = isset($_GET['geschlossen']) && (string) $_GET['geschlossen'] === '1';
$festIstAktiv = ((int) ($fest['aktiv'] ?? 0)) === 1;
if ($format === 'html' && $festIstAktiv) {
    if (empty($_SESSION['csrf_fest_abschluss'])) {
        $_SESSION['csrf_fest_abschluss'] = bin2hex(random_bytes(16));
    }
}

$where = ff_fest_paid_lines_where_sql($fest_id);

$sqlSum = "SELECT COALESCE(SUM(b.betrag),0) AS g FROM bestellungen b WHERE {$where}";
$gRes = mysqli_query($conn, $sqlSum);
$gesamt = 0.0;
if ($gRes && ($gr = mysqli_fetch_assoc($gRes))) {
    $gesamt = (float) ($gr['g'] ?? 0);
}

$sqlPos = "SELECT b.position AS position_id,
    MAX(p.Positionsname) AS positionsname,
    COALESCE(NULLIF(TRIM(b.Zusatzinfo), ''), '') AS zusatzinfo,
    b.betrag AS einzelpreis_gezahlt,
    COUNT(*) AS anzahl,
    SUM(b.betrag) AS umsatz_zeile
    FROM bestellungen b
    JOIN positionen p ON p.rowid = b.position
    WHERE {$where}
    GROUP BY b.position, COALESCE(NULLIF(TRIM(b.Zusatzinfo), ''), ''), b.betrag
    ORDER BY positionsname, einzelpreis_gezahlt, zusatzinfo";

$res = mysqli_query($conn, $sqlPos);
$zeilen = [];
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $zeilen[] = $r;
    }
}

$festDatumRaw = (string) ($fest['fest_datum'] ?? '');
$festDatumFilter = ($festDatumRaw !== '' && $festDatumRaw !== '0000-00-00') ? $festDatumRaw : null;
$kassenAbschluesse = ff_fest_kassen_closed_sessions($conn, $festDatumFilter);
$kassenUmsatzSumme = 0.0;
foreach ($kassenAbschluesse as $kr) {
    $kassenUmsatzSumme += (float) (($kr['session']['revenue_amount'] ?? 0));
}

$festTitel = htmlspecialchars((string) ($fest['name'] ?? ''), ENT_QUOTES, 'UTF-8');
$code = htmlspecialchars((string) ($fest['code'] ?? ''), ENT_QUOTES, 'UTF-8');
$fd = $fest['fest_datum'] ?? '';
$fdOut = $fd && $fd !== '0000-00-00' ? htmlspecialchars((string) $fd, ENT_QUOTES, 'UTF-8') : '—';

if ($format === 'csv') {
    $fh = ff_fest_csv_open_stream();
    ff_fest_csv_write_row($fh, ['Festabschluss', $fest['name'] ?? '', 'Code', $fest['code'] ?? '', 'Fest-Datum', $fdOut]);
    ff_fest_csv_write_row($fh, ['Gesamtumsatz Verkauf (bezahlt, netto Verkaufszeilen)', number_format($gesamt, 2, ',', ''), 'EUR', '', '', '']);
    ff_fest_csv_write_row($fh, ['Kassen-Umsatz Summe (abgeschlossene Sessions)', number_format($kassenUmsatzSumme, 2, ',', ''), 'EUR', '', '', '']);
    if ($festDatumFilter !== null) {
        ff_fest_csv_write_row($fh, ['Hinweis Kassen', 'Nur Abschlüsse ab Fest-Datum ' . $festDatumFilter, '', '', '', '']);
    } else {
        ff_fest_csv_write_row($fh, ['Hinweis Kassen', 'Alle abgeschlossenen Kassen (kein Fest-Datum gesetzt)', '', '', '', '']);
    }
    ff_fest_csv_write_row($fh, []);
    ff_fest_csv_write_row($fh, ['Kassen – Abschluss', 'Bereich', 'Wechselgeld', 'Tageslosung', 'Umsatz', 'Entnahmen', 'Zuzahlungen', 'Abgeschlossen von']);
    foreach ($kassenAbschluesse as $kr) {
        $s = $kr['session'];
        ff_fest_csv_write_row($fh, [
            (string) ($s['closed_at'] ?? ''),
            (string) ($s['bereich_name'] ?? ''),
            number_format((float) ($s['opening_amount'] ?? 0), 2, ',', ''),
            number_format((float) ($s['closing_amount'] ?? 0), 2, ',', ''),
            number_format((float) ($s['revenue_amount'] ?? 0), 2, ',', ''),
            number_format((float) $kr['entnahmen'], 2, ',', ''),
            number_format((float) $kr['zuzahlungen'], 2, ',', ''),
            (string) ($s['closed_by'] ?? ''),
        ]);
    }
    ff_fest_csv_write_row($fh, []);
    ff_fest_csv_write_row($fh, ['Positions-ID', 'Positionsname', 'Zusatzinfo', 'Einzelpreis gezählt', 'Anzahl', 'Umsatz Summe']);
    foreach ($zeilen as $z) {
        ff_fest_csv_write_row($fh, [
            (string) ($z['position_id'] ?? ''),
            (string) ($z['positionsname'] ?? ''),
            (string) ($z['zusatzinfo'] ?? ''),
            number_format((float) ($z['einzelpreis_gezahlt'] ?? 0), 2, ',', ''),
            (string) ($z['anzahl'] ?? '0'),
            number_format((float) ($z['umsatz_zeile'] ?? 0), 2, ',', ''),
        ]);
    }
    $csv = ff_fest_stream_to_string($fh);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="festabschluss_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($fest['code'] ?: 'fest')) . '_' . date('Ymd') . '.csv"');
    echo $csv;
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Festabschluss <?php echo $festTitel; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo ff_favicon_link_tags($conn); ?>
    <link href="assets/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <link href="admin.css" rel="stylesheet">
    <style>
        .fest-abschluss-gesamt {
            font-size: 1.1rem;
            font-weight: 700;
            padding: 0.75rem 0;
            border-top: 2px solid var(--bs-border-color);
            border-bottom: 2px solid var(--bs-border-color);
        }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .card { border: none !important; box-shadow: none !important; }
        }
    </style>
</head>
<body class="admin-page bg-light">
<div class="container py-3 py-md-4 fest-abschluss-page" style="max-width: 960px;">
    <nav class="no-print mb-3">
        <a class="btn btn-sm btn-outline-secondary" href="admin.php">← Admin</a>
        <a class="btn btn-sm btn-outline-primary" href="?id=<?php echo (int) $fest_id; ?>&format=csv">CSV laden</a>
        <button type="button" class="btn btn-sm btn-outline-dark" onclick="window.print()">Drucken / PDF</button>
    </nav>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h1 class="h4 card-title mb-2">Festabschluss – Verkauf</h1>
            <?php if ($festGeschlossen): ?>
            <div class="alert alert-success no-print py-2 small mb-3" role="alert"><strong>Fest wurde abgeschlossen.</strong> Es ist auf <strong>inaktiv</strong> gesetzt; solange kein anderes Fest aktiv geschaltet ist, sind <strong>keine neuen Tisch-Buchungen</strong> möglich (Direktverkauf bleibt nutzbar). CSV/HTML-Ansicht dieses Berichts bleibt verfügbar.</div>
            <?php endif; ?>
            <?php if (!$festIstAktiv): ?>
            <div class="alert alert-warning py-2 small mb-3" role="alert">Dieses Fest ist <strong>inaktiv</strong> (abgeschlossen). Es werden keine neuen Verkäufe diesem Fest zugeordnet, solange es nicht wieder aktiv geschaltet wird.</div>
            <?php endif; ?>
            <p class="text-muted small mb-1">Fest: <strong><?php echo $festTitel; ?></strong> (Code <?php echo $code; ?>, ID <?php echo (int) $fest_id; ?>) · Startdatum DB: <?php echo $fdOut; ?> · Status: <?php echo $festIstAktiv ? '<span class="badge text-bg-success">aktiv</span>' : '<span class="badge text-bg-secondary">inaktiv</span>'; ?></p>
            <p class="text-muted small mb-0">Verkauf: nur <strong>bezahlte</strong> Zeilen ohne Gratis/Schreibaus mit <code>fest_id</code>. Kassen: physische Kassen-Abschlüsse (Bar/Schank …), nicht festgebunden — siehe unten.</p>
        </div>
    </div>

    <p class="fest-abschluss-gesamt mb-2">Gesamtumsatz Verkauf: <?php echo number_format($gesamt, 2, ',', '.'); ?> €</p>
    <p class="fest-abschluss-gesamt mb-3">Kassen-Umsatz (Summe abgeschlossener Sessions): <?php echo number_format($kassenUmsatzSumme, 2, ',', '.'); ?> €
        <?php if ($festDatumFilter !== null): ?>
        <span class="text-muted small fw-normal">(nur Abschlüsse ab <?php echo htmlspecialchars($festDatumFilter, ENT_QUOTES, 'UTF-8'); ?>)</span>
        <?php else: ?>
        <span class="text-muted small fw-normal">(alle Kassen-Abschlüsse — kein Fest-Datum hinterlegt)</span>
        <?php endif; ?>
    </p>

    <?php if (count($kassenAbschluesse) > 0): ?>
    <div class="table-responsive card shadow-sm mb-3">
        <div class="card-header py-2"><strong>Kassen-Abschlüsse</strong></div>
        <table class="table table-sm table-striped mb-0">
            <thead class="table-light">
                <tr>
                    <th>Abschluss</th><th>Bereich</th><th class="text-end">Wechselgeld</th><th class="text-end">Tageslosung</th>
                    <th class="text-end">Umsatz</th><th class="text-end">Entnahmen</th><th class="text-end">Zuzahlungen</th><th>von</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($kassenAbschluesse as $kr):
                $s = $kr['session'];
                $closed = htmlspecialchars(substr((string) ($s['closed_at'] ?? ''), 0, 16), ENT_QUOTES, 'UTF-8');
                ?>
                <tr>
                    <td class="text-nowrap"><?php echo $closed; ?></td>
                    <td><?php echo htmlspecialchars((string) ($s['bereich_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-end"><?php echo number_format((float) ($s['opening_amount'] ?? 0), 2, ',', '.'); ?> €</td>
                    <td class="text-end"><?php echo number_format((float) ($s['closing_amount'] ?? 0), 2, ',', '.'); ?> €</td>
                    <td class="text-end fw-semibold"><?php echo number_format((float) ($s['revenue_amount'] ?? 0), 2, ',', '.'); ?> €</td>
                    <td class="text-end"><?php echo number_format((float) $kr['entnahmen'], 2, ',', '.'); ?> €</td>
                    <td class="text-end"><?php echo number_format((float) $kr['zuzahlungen'], 2, ',', '.'); ?> €</td>
                    <td class="small"><?php echo htmlspecialchars((string) ($s['closed_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="table-responsive card shadow-sm">
        <table class="table table-sm table-striped table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Positionsname</th>
                    <th>Zusatzinfo</th>
                    <th class="text-end">Einzelpreis (gez.)</th>
                    <th class="text-end">Anzahl</th>
                    <th class="text-end">Umsatz</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($zeilen as $z):
                $pn = htmlspecialchars((string) ($z['positionsname'] ?? ''), ENT_QUOTES, 'UTF-8');
                $zi = htmlspecialchars((string) ($z['zusatzinfo'] ?? ''), ENT_QUOTES, 'UTF-8');
                if ($zi === '') {
                    $zi = '—';
                }
                $ep = number_format((float) ($z['einzelpreis_gezahlt'] ?? 0), 2, ',', '.');
                $an = (int) ($z['anzahl'] ?? 0);
                $su = number_format((float) ($z['umsatz_zeile'] ?? 0), 2, ',', '.');
                ?>
                <tr>
                    <td><?php echo $pn; ?></td>
                    <td><?php echo $zi; ?></td>
                    <td class="text-end text-nowrap"><?php echo $ep; ?> €</td>
                    <td class="text-end"><?php echo $an; ?></td>
                    <td class="text-end text-nowrap"><?php echo $su; ?> €</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="text-muted small mt-3 mb-0">Unterschiedliche <strong>gezahlte Beträge</strong> (z. B. Aufpreis +0,50 €) erscheinen als eigene Zeile. Detaillierte Einzelbuchungen: WebApp Statistik oder Steuerpaket-ZIP.</p>

    <?php if ($festIstAktiv): ?>
    <div class="card border-secondary no-print mt-4">
        <div class="card-body">
            <h2 class="h6 card-title">Fest endgültig abschließen</h2>
            <p class="small text-muted mb-3">Setzt das Fest auf <strong>inaktiv</strong> und leert ggf. „aktuelles Fest“ in den Einstellungen, wenn es dieses war. Anschließend sind <strong>keine neuen Bestellungen auf Tischen</strong> mehr möglich, bis im Admin wieder ein Fest aktiv geschaltet wird.</p>
            <form method="post" action="fest_abschluss_schliessen.php" onsubmit="return confirm('Fest wirklich abschließen und Buchungen sperren?');">
                <input type="hidden" name="fest_id" value="<?php echo (int) $fest_id; ?>">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_fest_abschluss'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn btn-danger">Fest abschließen &amp; sperren</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<script src="assets/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>
