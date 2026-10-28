<?php
/**
 * Kellner-Bon im Browser (Druckdialog). Wird vom Button „Drucken“ in Küche/Schank/Druckziel geöffnet.
 * Gleiche Artikel (Position + Zusatzinfo) werden zu einer Zeile mit Menge, Einzel- und Gesamtpreis zusammengefasst.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/bon_nr_helper.php';
require_once __DIR__ . '/include/user_landing.php';

header('Content-Type: text/html; charset=utf-8');

$liste = [];
if (isset($_POST['listePositionen']) && is_array($_POST['listePositionen'])) {
    foreach ($_POST['listePositionen'] as $v) {
        $id = (int)$v;
        if ($id > 0) {
            $liste[] = $id;
        }
    }
}
$liste = array_values(array_unique($liste));

$tischnummer = isset($_POST['tischnummer']) ? (int)$_POST['tischnummer'] : 0;

if ($liste === [] || $tischnummer <= 0) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Bon</title></head><body><p>Keine Positionen zum Drucken.</p><script>window.close();</script></body></html>';
    exit;
}

$ids = implode(',', $liste);
$sql = "SELECT b.rowid, b.tischnummer, b.kellner, b.zeitstempel, b.zeitKueche, b.Zusatzinfo,
        COALESCE(b.betrag, p.Betrag) AS betrag_zeile, b.order_nr,
        p.rowid AS position_id, p.Positionsname, p.Betrag AS betrag_katalog, t.tischname,
        r.rechnungsnummer AS rechnungsnummer
        FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position
        JOIN tische t ON t.tischnummer = b.tischnummer
        LEFT JOIN rechnungen r ON r.id = b.rechnung_id
        WHERE b.`delete` = 0 AND b.tischnummer = " . (int)$tischnummer . "
          AND b.rowid IN (" . $ids . ")
        ORDER BY p.Positionsname ASC, b.rowid ASC";

$res = mysqli_query($conn, $sql);
if (!$res) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><p>Datenbankfehler.</p></body></html>';
    exit;
}

$rows = [];
while ($r = mysqli_fetch_assoc($res)) {
    $rows[] = $r;
}

if ($rows === []) {
    mysqli_close($conn);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><p>Keine Daten zu diesen Positionen (Tisch stimmt nicht?).</p></body></html>';
    exit;
}

ff_users_ensure_landing_columns($conn);

// Bon-Nr vergeben
$bonNr = ff_next_bon_nr($conn);

// Bestellnummer(n) aus den Zeilen sammeln
$orderNrs = [];
foreach ($rows as $r) {
    $onr = isset($r['order_nr']) ? (int)$r['order_nr'] : 0;
    if ($onr > 0) $orderNrs[$onr] = true;
}
$orderNrs = array_keys($orderNrs);
sort($orderNrs, SORT_NUMERIC);
$orderNrText = $orderNrs !== [] ? implode(', ', $orderNrs) : '-';

$rechnungsNums = [];
foreach ($rows as $r) {
    $rn = trim((string)($r['rechnungsnummer'] ?? ''));
    if ($rn !== '') {
        $rechnungsNums[$rn] = true;
    }
}
$rechnungsNums = array_keys($rechnungsNums);
sort($rechnungsNums);
$rechnungText = $rechnungsNums !== [] ? implode(', ', $rechnungsNums) : '';

$tname = $rows[0]['tischname'] ?? '';
$kellner = htmlspecialchars(ff_user_display_label($conn, (string)($rows[0]['kellner'] ?? '')), ENT_QUOTES, 'UTF-8');

mysqli_close($conn);

$bonDatum = date('d.m.Y');
$bonDruckzeit = date('H:i');

/** Früheste Bestellzeit / späteste Küchen-Fertigzeit für Bon-Kopf (wie Thermo-Client). */
$bestelltMinTs = null;
$fertigMaxTs = null;
foreach ($rows as $r) {
    $zs = (string)($r['zeitstempel'] ?? '');
    if ($zs !== '' && $zs !== '0000-00-00 00:00:00') {
        if ($bestelltMinTs === null || $zs < $bestelltMinTs) {
            $bestelltMinTs = $zs;
        }
    }
    $zk = (string)($r['zeitKueche'] ?? '');
    if ($zk !== '' && $zk !== '0000-00-00 00:00:00') {
        if ($fertigMaxTs === null || $zk > $fertigMaxTs) {
            $fertigMaxTs = $zk;
        }
    }
}
$bonBestelltHm = '';
if ($bestelltMinTs) {
    $tB = strtotime($bestelltMinTs);
    $bonBestelltHm = $tB ? date('H:i', $tB) : '';
}
$agg = [];
foreach ($rows as $r) {
    $zKey = trim((string)($r['Zusatzinfo'] ?? ''));
    $pid = (int)($r['position_id'] ?? 0);
    $key = $pid . "\x1e" . $zKey;
    $zeile = isset($r['betrag_zeile']) && $r['betrag_zeile'] !== null && $r['betrag_zeile'] !== ''
        ? (float)$r['betrag_zeile']
        : (float)($r['betrag_katalog'] ?? 0);

    if (!isset($agg[$key])) {
        $agg[$key] = [
            'anzahl' => 0,
            'summe' => 0.0,
            'name' => (string)($r['Positionsname'] ?? ''),
            'zusatzinfo' => $r['Zusatzinfo'],
        ];
    }
    $agg[$key]['anzahl']++;
    $agg[$key]['summe'] += $zeile;
}

$lines = [];
$sumBon = 0.0;
foreach ($agg as $line) {
    $n = max(1, (int)$line['anzahl']);
    $summe = round((float)$line['summe'], 2);
    $sumBon += $summe;
    $einzel = round($summe / $n, 2);
    $lines[] = [
        'anzahl' => $n,
        'name' => $line['name'],
        'zusatzinfo' => $line['zusatzinfo'],
        'einzel' => $einzel,
        'gesamt' => $summe,
    ];
}

function ff_eur($v) {
    return number_format((float)$v, 2, ',', '');
}

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <title>Kellner-Bon</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 16px; font-size: 14px; }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        .meta { color: #444; margin-bottom: 12px; font-size: 13px; }
        table.bon { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.bon th, table.bon td { padding: 6px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
        table.bon th { font-weight: 600; text-align: left; }
        table.bon th.nr { width: 2.2rem; }
        table.bon th.art { }
        table.bon th.einzel, table.bon td.einzel { text-align: right; width: 5.5rem; white-space: nowrap; }
        table.bon th.gesamt, table.bon td.gesamt { text-align: right; width: 5.5rem; white-space: nowrap; }
        table.bon td.hinweis { font-size: 0.9em; color: #555; padding-top: 0; padding-left: 2.5rem; border-bottom: 1px solid #eee; }
        tfoot td { font-weight: 700; border-top: 2px solid #333; border-bottom: none; }
        tfoot td.gesamt { text-align: right; }
        @media print { body { margin: 8px; } }
    </style>
</head>

<body>
    <h1><?php echo (int)$tischnummer === 999999 ? 'Direktverkauf' : 'Tisch: ' . htmlspecialchars((string)$tname, ENT_QUOTES, 'UTF-8'); ?></h1>
    <div class="meta">
        <strong>Bestell-Nr. <?php echo htmlspecialchars($orderNrText, ENT_QUOTES, 'UTF-8'); ?></strong> &nbsp;|&nbsp;
        <strong>Bon Nr. <?php echo (int)$bonNr; ?></strong>
        <?php if ($rechnungText !== '') { ?>
        <br><strong>Rechnung</strong> <?php echo htmlspecialchars($rechnungText, ENT_QUOTES, 'UTF-8'); ?>
        <?php } ?>
        <br>
        KellnerIn: <?php echo $kellner; ?><br>
        <?php if ($bonBestelltHm !== '') { ?>
        Bestellt: <?php echo htmlspecialchars($bonBestelltHm, ENT_QUOTES, 'UTF-8'); ?> Uhr<br>
        <?php } ?>
        Datum (Beleg): <?php echo htmlspecialchars($bonDatum, ENT_QUOTES, 'UTF-8'); ?><br>
        Druckzeit: <?php echo htmlspecialchars($bonDruckzeit, ENT_QUOTES, 'UTF-8'); ?> Uhr
    </div>
    <table class="bon">
        <thead>
            <tr>
                <th class="nr"></th>
                <th class="art">Position</th>
                <th class="einzel">a. Stk</th>
                <th class="gesamt">Gesamt</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $L) { ?>
                <tr>
                    <td><?php echo (int)$L['anzahl']; ?>×</td>
                    <td><?php echo htmlspecialchars($L['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="einzel"><?php echo ff_eur($L['einzel']); ?> €</td>
                    <td class="gesamt"><?php echo ff_eur($L['gesamt']); ?> €</td>
                </tr>
                <?php if (trim((string)$L['zusatzinfo']) !== '') { ?>
                    <tr>
                        <td></td>
                        <td class="hinweis" colspan="3"><?php echo htmlspecialchars((string)$L['zusatzinfo'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php } ?>
            <?php } ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="text-align:right;">Summe</td>
                <td class="gesamt"><?php echo ff_eur($sumBon); ?> €</td>
            </tr>
        </tfoot>
    </table>
    <script>
        window.onload = function() { window.print(); };
    </script>
</body>

</html>
