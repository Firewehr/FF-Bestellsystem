<?php
/**
 * PDF-Rechnung generieren (A4 Format)
 * 
 * Parameter:
 *   - id: Rechnungs-ID (aus Tabelle rechnungen)
 * 
 * Verwendet keine externen Libraries - generiert HTML das als PDF gedruckt werden kann.
 */

require_once('auth.php');
require_once('include/db.php');
require_once('include/settings.php');
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_rechnung_items.php';

$rechnung_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($rechnung_id <= 0) {
    die('Fehler: Keine Rechnungs-ID angegeben');
}

// Rechnung laden
$stmt = mysqli_prepare($conn, "SELECT * FROM rechnungen WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $rechnung_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$rechnung = mysqli_fetch_assoc($result);

if (!$rechnung) {
    die('Fehler: Rechnung nicht gefunden');
}

ff_users_ensure_landing_columns($conn);

// Verkäuferdaten
$seller_name = setting_get($conn, 'seller_name', 'Freiwillige Feuerwehr');
$seller_address = setting_get($conn, 'seller_address', '');
$seller_uid = setting_get($conn, 'seller_uid', '');
$rechnung_festname = setting_get($conn, 'rechnung_festname', '');
$rechnung_logo = setting_get($conn, 'rechnung_logo', '');
$rechnung_footer_lines = ff_rechnung_footer_lines($conn);

// Logo als Base64 einbetten (für besseren PDF-Druck)
$logo_base64 = '';
if ($rechnung_logo && file_exists($rechnung_logo)) {
    $logo_data = file_get_contents($rechnung_logo);
    $logo_mime = 'image/png';
    $ext = pathinfo($rechnung_logo, PATHINFO_EXTENSION);
    if ($ext === 'jpg' || $ext === 'jpeg') $logo_mime = 'image/jpeg';
    if ($ext === 'gif') $logo_mime = 'image/gif';
    $logo_base64 = 'data:' . $logo_mime . ';base64,' . base64_encode($logo_data);
}

require_once __DIR__ . '/include/ff_rechnungen_ensure_columns.php';
ff_rechnungen_ensure_extra_columns($conn);

$positionen = [];
$gesamtsumme = 0.0;
$linesJson = trim((string)($rechnung['lines_json'] ?? ''));
if ($linesJson !== '') {
    $dec = json_decode($linesJson, true);
    if (is_array($dec)) {
        foreach ($dec as $line) {
            if (!is_array($line)) {
                continue;
            }
            $cnt = max(1, (int)($line['cnt'] ?? 1));
            $name = (string)($line['name'] ?? '');
            $unit = (float)($line['betrag'] ?? 0);
            $summe = $unit * $cnt;
            $positionen[] = [
                'Positionsname' => $name,
                'Kurzbezeichnung' => $name,
                'Betrag' => $unit,
                'anzahl' => $cnt,
                'summe' => $summe,
            ];
            $gesamtsumme += $summe;
        }
    }
}

// Positionen laden (DB-Verknüpfung), falls kein Snapshot
$sql = null;
if (count($positionen) === 0) {
    // Wie am Bon: nach Position + Zusatzinfo gruppieren, damit z. B. 5 Schnitzel mit
    // unterschiedlichen Zusatzpositionen als 5 Zeilen erscheinen, nicht zu einer kollabieren.
    if ((int)$rechnung['sammelrechnung_id'] > 0) {
        $sql = "SELECT p.Positionsname, p.Kurzbezeichnung, p.Betrag, COUNT(*) as anzahl,
                       SUM(COALESCE(NULLIF(b.betrag, 0), p.Betrag)) as summe,
                       b.Zusatzinfo,
                       t.tischnummer, t.tischname
                FROM bestellungen b
                JOIN positionen p ON p.rowid = b.position
                JOIN tische t ON t.tischnummer = b.tischnummer
                WHERE b.rechnung_id = ?
                GROUP BY b.tischnummer, t.tischname, p.rowid, p.Positionsname, p.Kurzbezeichnung, p.Betrag, b.Zusatzinfo
                ORDER BY b.tischnummer, p.Positionsname, b.Zusatzinfo";
    } else {
        $sql = "SELECT p.Positionsname, p.Kurzbezeichnung, p.Betrag, COUNT(*) as anzahl,
                       SUM(COALESCE(NULLIF(b.betrag, 0), p.Betrag)) as summe,
                       b.Zusatzinfo
                FROM bestellungen b
                JOIN positionen p ON p.rowid = b.position
                WHERE b.rechnung_id = ?
                GROUP BY p.rowid, p.Positionsname, p.Kurzbezeichnung, p.Betrag, b.Zusatzinfo
                ORDER BY p.Positionsname, b.Zusatzinfo";
    }
}

if (count($positionen) === 0 && $sql !== null) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $rechnung_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $an = max(1, (int) ($row['anzahl'] ?? 1));
        $row['Betrag'] = $an > 0 ? (float) $row['summe'] / $an : (float) ($row['Betrag'] ?? 0);
        $positionen[] = $row;
        $gesamtsumme += (float)$row['summe'];
    }
}

// Falls keine Positionen über rechnung_id gefunden, versuche über sammelrechnung_id
if (count($positionen) === 0 && (int)$rechnung['sammelrechnung_id'] > 0) {
    $sql = "SELECT p.Positionsname, p.Kurzbezeichnung, p.Betrag, COUNT(*) as anzahl,
                   SUM(COALESCE(NULLIF(b.betrag, 0), p.Betrag)) as summe,
                   b.Zusatzinfo,
                   t.tischnummer, t.tischname
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            JOIN tische t ON t.tischnummer = b.tischnummer
            WHERE b.sammelrechnung_id = ? AND b.delete = 0
            GROUP BY b.tischnummer, t.tischname, p.rowid, p.Positionsname, p.Kurzbezeichnung, p.Betrag, b.Zusatzinfo
            ORDER BY b.tischnummer, p.Positionsname, b.Zusatzinfo";
    $stmt = mysqli_prepare($conn, $sql);
    $sammel_id = (int)$rechnung['sammelrechnung_id'];
    mysqli_stmt_bind_param($stmt, 'i', $sammel_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $an = max(1, (int) ($row['anzahl'] ?? 1));
        $row['Betrag'] = $an > 0 ? (float) $row['summe'] / $an : (float) ($row['Betrag'] ?? 0);
        $positionen[] = $row;
        $gesamtsumme += (float)$row['summe'];
    }
}

// Falls immer noch keine Positionen, versuche über tischnummer
if (count($positionen) === 0 && (int)$rechnung['tischnummer'] > 0) {
    $sql = "SELECT p.Positionsname, p.Kurzbezeichnung, p.Betrag, COUNT(*) as anzahl,
                   SUM(COALESCE(NULLIF(b.betrag, 0), p.Betrag)) as summe,
                   b.Zusatzinfo
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            WHERE b.tischnummer = ? AND b.delete = 0
              AND b.timestampBezahlung <> '0000-00-00 00:00:00'
            GROUP BY p.rowid, p.Positionsname, p.Kurzbezeichnung, p.Betrag, b.Zusatzinfo
            ORDER BY p.Positionsname, b.Zusatzinfo";
    $stmt = mysqli_prepare($conn, $sql);
    $tisch = (int)$rechnung['tischnummer'];
    mysqli_stmt_bind_param($stmt, 'i', $tisch);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $an = max(1, (int) ($row['anzahl'] ?? 1));
        $row['Betrag'] = $an > 0 ? (float) $row['summe'] / $an : (float) ($row['Betrag'] ?? 0);
        $positionen[] = $row;
        $gesamtsumme += (float) $row['summe'];
    }
}

// Gesamtsumme aus Rechnung nehmen falls vorhanden
if ((float)$rechnung['total'] > 0) {
    $gesamtsumme = (float)$rechnung['total'];
}

// Datum formatieren
$datum = date('d.m.Y', strtotime($rechnung['created_at']));

// KellnerIn: Anzeigename aus users (display_name), Fallback Login (created_by)
$kellnerAnzeige = ff_user_display_label($conn, (string)($rechnung['created_by'] ?? ''));

// Ist Firmenrechnung?
$is_firma = (int)$rechnung['is_firma'] === 1;

function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function formatEUR($amount) {
    return number_format((float)$amount, 2, ',', '.') . ' €';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Rechnung <?php echo esc($rechnung['rechnungsnummer']); ?></title>
    <style>
        @page {
            size: A4;
            margin: 20mm;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20mm;
            background: #fff;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #b91c1c;
        }
        
        .seller {
            max-width: 55%;
        }
        
        .seller-logo {
            margin-bottom: 10px;
        }
        
        .seller-logo img {
            max-height: 70px;
            max-width: 200px;
        }
        
        .seller h1 {
            margin: 0 0 5px 0;
            font-size: 18pt;
            color: #b91c1c;
        }
        
        .seller .festname {
            margin: 0 0 10px 0;
            font-size: 12pt;
            color: #c41e3a;
            font-weight: 600;
        }
        
        .seller p {
            margin: 3px 0;
            color: #666;
        }
        
        .invoice-meta {
            text-align: right;
        }
        
        .invoice-meta h2 {
            margin: 0 0 8px 0;
            font-size: 24pt;
            color: #333;
        }
        
        .invoice-meta .rechnungsnummer-big {
            margin: 0 0 14px 0;
            font-size: 15pt;
            font-weight: 700;
            color: #1a1a1a;
            letter-spacing: 0.03em;
        }
        
        .invoice-meta table {
            margin-left: auto;
        }
        
        .invoice-meta td {
            padding: 3px 0;
        }
        
        .invoice-meta td:first-child {
            text-align: right;
            padding-right: 15px;
            color: #666;
        }
        
        .invoice-meta td:last-child {
            font-weight: 500;
        }
        
        .recipient {
            margin: 30px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .recipient h3 {
            margin: 0 0 10px 0;
            font-size: 10pt;
            color: #666;
            text-transform: uppercase;
        }
        
        .recipient p {
            margin: 3px 0;
        }
        
        .positions {
            margin: 30px 0;
        }
        
        .positions table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .positions th {
            background: #b91c1c;
            color: #fff;
            padding: 12px 10px;
            text-align: left;
            font-weight: 500;
        }
        
        .positions th:nth-child(1) { width: 8%; text-align: center; }
        .positions th:nth-child(2) { width: 52%; }
        .positions th:nth-child(3) { width: 20%; text-align: right; }
        .positions th:nth-child(4) { width: 20%; text-align: right; }
        
        .positions td {
            padding: 10px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .positions td:nth-child(1) { text-align: center; }
        .positions td:nth-child(3),
        .positions td:nth-child(4) { text-align: right; }
        
        .positions tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .positions .tisch-header {
            background: #fce8e8;
            font-weight: 600;
            color: #b91c1c;
        }
        
        .positions .tisch-header td {
            padding: 8px 10px;
            border-bottom: 2px solid #b91c1c;
        }
        
        .total-section {
            margin-top: 30px;
            display: flex;
            justify-content: flex-end;
        }
        
        .total-box {
            width: 300px;
            border: 2px solid #b91c1c;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .total-box .row {
            display: flex;
            justify-content: space-between;
            padding: 10px 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .total-box .row:last-child {
            border-bottom: none;
        }
        
        .total-box .grand-total {
            background: #b91c1c;
            color: #fff;
            font-size: 14pt;
            font-weight: 600;
        }
        
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            text-align: center;
            color: #999;
            font-size: 9pt;
        }
        
        .no-print {
            margin-bottom: 20px;
            padding: 15px;
            background: #fce8e8;
            border-radius: 5px;
            text-align: center;
        }
        
        .no-print button {
            padding: 10px 30px;
            font-size: 12pt;
            background: #b91c1c;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 0 10px;
        }
        
        .no-print button:hover {
            background: #7f1d1d;
        }
        
        .no-print button.secondary {
            background: #666;
        }
        
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print();">🖨️ Drucken / Als PDF speichern</button>
    <button class="secondary" onclick="window.close();">Schließen</button>
</div>

<div class="header">
    <div class="seller">
        <?php if ($logo_base64): ?>
        <div class="seller-logo">
            <img src="<?php echo $logo_base64; ?>" alt="Logo">
        </div>
        <?php endif; ?>
        <h1><?php echo esc($seller_name); ?></h1>
        <?php if ($rechnung_festname): ?>
        <p class="festname"><?php echo esc($rechnung_festname); ?></p>
        <?php endif; ?>
        <?php 
        $addr_lines = explode("\n", $seller_address);
        foreach ($addr_lines as $line): 
            $line = trim($line);
            if ($line):
        ?>
        <p><?php echo esc($line); ?></p>
        <?php 
            endif;
        endforeach; 
        ?>
        <?php if ($seller_uid): ?>
        <p>UID: <?php echo esc($seller_uid); ?></p>
        <?php endif; ?>
    </div>
    
    <div class="invoice-meta">
        <h2>RECHNUNG</h2>
        <p class="rechnungsnummer-big"><?php echo esc($rechnung['rechnungsnummer']); ?></p>
        <?php if (!empty($rechnung['is_proforma'])): ?>
        <p style="margin:0 0 10px 0;font-size:11pt;color:#b45309;font-weight:600;">Kostenübersicht – noch nicht bezahlt</p>
        <?php endif; ?>
        <table>
            <tr>
                <td>Rechnungsnummer:</td>
                <td><?php echo esc($rechnung['rechnungsnummer']); ?></td>
            </tr>
            <tr>
                <td>Datum:</td>
                <td><?php echo $datum; ?></td>
            </tr>
            <?php if ($kellnerAnzeige !== ''): ?>
            <tr>
                <td>KellnerIn:</td>
                <td><?php echo esc($kellnerAnzeige); ?></td>
            </tr>
            <?php endif; ?>
            <?php
            // Mehrere Best.-Nrn. möglich, wenn am Tisch zwischendurch nachbestellt wurde
            // und alles auf eine Rechnung kommt. Erst aus den verknüpften Bestellungen
            // sammeln, sonst Fallback auf rechnungen.order_nr.
            $pdf_onr_list = [];
            $pdf_rid = (int)($rechnung['id'] ?? 0);
            if ($pdf_rid > 0) {
                $stOnr = mysqli_prepare($conn, 'SELECT DISTINCT order_nr FROM bestellungen WHERE rechnung_id = ? AND `delete`=0 AND order_nr IS NOT NULL AND order_nr > 0 ORDER BY order_nr ASC');
                if ($stOnr) {
                    mysqli_stmt_bind_param($stOnr, 'i', $pdf_rid);
                    mysqli_stmt_execute($stOnr);
                    $resOnr = mysqli_stmt_get_result($stOnr);
                    while ($rOnr = mysqli_fetch_assoc($resOnr)) {
                        $pdf_onr_list[] = (int)$rOnr['order_nr'];
                    }
                    mysqli_stmt_close($stOnr);
                }
            }
            if (count($pdf_onr_list) === 0) {
                $singleOnr = isset($rechnung['order_nr']) ? (int)$rechnung['order_nr'] : 0;
                if ($singleOnr > 0) {
                    $pdf_onr_list[] = $singleOnr;
                }
            }
            if (count($pdf_onr_list) > 0):
                $pdf_onr_label = (count($pdf_onr_list) > 1) ? 'Bestell-Nrn.:' : 'Bestell-Nr.:';
                $pdf_onr_text = implode(', ', array_map('strval', $pdf_onr_list));
            ?>
            <tr>
                <td><?php echo $pdf_onr_label; ?></td>
                <td><?php echo esc($pdf_onr_text); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ((int)$rechnung['sammelrechnung_id'] > 0): ?>
            <tr>
                <td>Sammelrechnung:</td>
                <td>#<?php echo (int)$rechnung['sammelrechnung_id']; ?></td>
            </tr>
            <?php elseif ((int)$rechnung['tischnummer'] > 0): ?>
            <tr>
                <td>Tisch:</td>
                <td><?php echo (int)$rechnung['tischnummer']; ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php if ($is_firma && $rechnung['empfaenger_name']): ?>
<div class="recipient">
    <h3>Rechnungsempfänger</h3>
    <p><strong><?php echo esc($rechnung['empfaenger_name']); ?></strong></p>
    <?php if ($rechnung['empfaenger_strasse']): ?>
    <p><?php echo esc($rechnung['empfaenger_strasse']); ?></p>
    <?php endif; ?>
    <?php if ($rechnung['empfaenger_plz'] || $rechnung['empfaenger_ort']): ?>
    <p><?php echo esc(trim($rechnung['empfaenger_plz'] . ' ' . $rechnung['empfaenger_ort'])); ?></p>
    <?php endif; ?>
    <?php if ($rechnung['empfaenger_uid']): ?>
    <p>UID: <?php echo esc($rechnung['empfaenger_uid']); ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="positions">
    <table>
        <thead>
            <tr>
                <th>Anz.</th>
                <th>Bezeichnung</th>
                <th>Einzelpreis</th>
                <th>Gesamt</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $current_tisch_nr = -1;
            $is_sammel_layout = (int) $rechnung['sammelrechnung_id'] > 0;
            $has_tisch = $is_sammel_layout && count($positionen) > 0 && isset($positionen[0]['tischnummer']);

            foreach ($positionen as $pos):
                if ($has_tisch && (int) ($pos['tischnummer'] ?? 0) !== $current_tisch_nr):
                    $current_tisch_nr = (int) ($pos['tischnummer'] ?? 0);
                    $tnm = trim((string) ($pos['tischname'] ?? ''));
                    $hdr = 'Tisch ' . $current_tisch_nr . ($tnm !== '' ? ' – ' . $tnm : '');
            ?>
            <tr class="tisch-header">
                <td colspan="4"><?php echo esc($hdr); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td><?php echo (int)$pos['anzahl']; ?>x</td>
                <td>
                    <?php echo esc($pos['Positionsname']); ?>
                    <?php $ziPos = trim((string)($pos['Zusatzinfo'] ?? '')); if ($ziPos !== ''): ?>
                        <div style="font-size:9pt; color:#555; margin-top:2px;">&rarr; <?php echo esc($ziPos); ?></div>
                    <?php endif; ?>
                </td>
                <td><?php echo formatEUR($pos['Betrag']); ?></td>
                <td><?php echo formatEUR($pos['summe']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="total-section">
    <div class="total-box">
        <div class="row">
            <span>Zwischensumme:</span>
            <span><?php echo formatEUR($gesamtsumme); ?></span>
        </div>
        <div class="row">
            <span>MwSt. (0%):</span>
            <span>0,00 €</span>
        </div>
        <div class="row grand-total">
            <span>Gesamtbetrag:</span>
            <span><?php echo formatEUR($gesamtsumme); ?></span>
        </div>
    </div>
</div>

<div class="footer">
    <?php foreach ($rechnung_footer_lines as $footerLine): ?>
    <p><?php echo esc($footerLine); ?></p>
    <?php endforeach; ?>
</div>

</body>
</html>
