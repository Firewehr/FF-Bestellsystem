<?php
/**
 * Abholbon für Direktverkauf generieren
 * Gibt HTML zurück, das als Bon gedruckt werden kann
 * 
 * Parameter:
 *   - bon_id: Die Bon-ID
 *   - preview: 1 = nur Vorschau, sonst in Druck-Queue
 */
require_once('auth.php');
require_once('include/db.php');
require_once('include/settings.php');

$bon_id = isset($_GET['bon_id']) ? trim($_GET['bon_id']) : '';
$preview = isset($_GET['preview']) ? (int)$_GET['preview'] : 0;

if ($bon_id === '') {
    die('Fehler: Keine Bon-ID');
}

// Spalte bon_id anlegen falls nicht vorhanden
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'bon_id'");
if ($chk && mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, "ALTER TABLE bestellungen ADD COLUMN bon_id VARCHAR(10) NULL DEFAULT NULL");
}

// Positionen für diese Bon-ID laden (bezahlt und nicht gelöscht)
$sql = "SELECT p.Positionsname, p.Kurzbezeichnung, p.Betrag, pt.name as druckziel_name,
               TRIM(COALESCE(b.Zusatzinfo, '')) AS zusatzinfo,
               COUNT(*) as anzahl, SUM(COALESCE(NULLIF(b.betrag, 0), p.Betrag)) as summe
        FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position
        LEFT JOIN print_targets pt ON pt.print_target = COALESCE(b.print_target, p.print_target)
        WHERE b.bon_id = ? AND b.delete = 0
        GROUP BY p.rowid, p.Positionsname, p.Kurzbezeichnung, p.Betrag,
            TRIM(COALESCE(b.Zusatzinfo, '')), pt.print_target, pt.name, pt.sort_order
        ORDER BY pt.sort_order, p.Positionsname, zusatzinfo";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 's', $bon_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$positionen = [];
$gesamtsumme = 0;
while ($row = mysqli_fetch_assoc($result)) {
    $positionen[] = $row;
    $gesamtsumme += (float)$row['summe'];
}

if (count($positionen) === 0) {
    die('Keine Positionen für Bon #' . htmlspecialchars($bon_id));
}

// Feuerwehr-Name laden
$ff_name = setting_get($conn, 'seller_name', 'Freiwillige Feuerwehr');
$fest_name = setting_get($conn, 'rechnung_festname', '');

function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Abholbon #<?php echo esc($bon_id); ?></title>
    <style>
        @page {
            size: 80mm auto;
            margin: 2mm;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12pt;
            line-height: 1.3;
            width: 76mm;
            padding: 3mm;
            background: #fff;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px dashed #000;
            padding-bottom: 3mm;
            margin-bottom: 3mm;
        }
        
        .header h1 {
            font-size: 14pt;
            margin-bottom: 2mm;
        }
        
        .header .festname {
            font-size: 11pt;
        }
        
        .bon-id {
            text-align: center;
            font-size: 28pt;
            font-weight: bold;
            padding: 5mm 0;
            border: 3px solid #000;
            margin: 3mm 0;
        }
        
        .bon-id-label {
            text-align: center;
            font-size: 11pt;
            margin-bottom: 3mm;
        }
        
        .positions {
            margin: 3mm 0;
        }
        
        .position-row {
            display: flex;
            justify-content: space-between;
            padding: 1mm 0;
            border-bottom: 1px dotted #999;
        }
        
        .position-name {
            flex: 1;
        }
        
        .position-anzahl {
            width: 30px;
            text-align: right;
            font-weight: bold;
        }
        
        .druckziel-header {
            font-weight: bold;
            background: #eee;
            padding: 2mm;
            margin-top: 3mm;
            text-transform: uppercase;
            font-size: 10pt;
        }
        
        .total {
            text-align: right;
            font-size: 14pt;
            font-weight: bold;
            border-top: 2px solid #000;
            padding-top: 3mm;
            margin-top: 3mm;
        }
        
        .footer {
            text-align: center;
            margin-top: 5mm;
            padding-top: 3mm;
            border-top: 2px dashed #000;
            font-size: 11pt;
        }
        
        .abholung-hinweis {
            text-align: center;
            font-size: 10pt;
            margin-top: 3mm;
            padding: 2mm;
            border: 1px solid #000;
        }
        
        .no-print {
            margin-bottom: 10px;
            text-align: center;
        }
        
        .no-print button {
            padding: 8px 20px;
            font-size: 14pt;
            cursor: pointer;
            margin: 5px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                width: auto;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print();">🖨️ Bon drucken</button>
    <button onclick="window.close();">Schließen</button>
</div>

<div class="header">
    <h1><?php echo esc($ff_name); ?></h1>
    <?php if ($fest_name): ?>
    <p class="festname"><?php echo esc($fest_name); ?></p>
    <?php endif; ?>
</div>

<p class="bon-id-label">ABHOLNUMMER</p>
<div class="bon-id">#<?php echo esc($bon_id); ?></div>

<div class="positions">
    <?php 
    $current_druckziel = null;
    foreach ($positionen as $pos): 
        if ($pos['druckziel_name'] !== $current_druckziel):
            $current_druckziel = $pos['druckziel_name'];
    ?>
    <div class="druckziel-header">📍 <?php echo esc($current_druckziel ?: 'Allgemein'); ?></div>
    <?php endif; ?>
    <div class="position-row">
        <span class="position-name"><?php echo esc($pos['Positionsname']); ?>
        <?php if (trim((string)($pos['zusatzinfo'] ?? '')) !== ''): ?>
        <br><span style="font-size:10pt;">(<?php echo esc($pos['zusatzinfo']); ?>)</span>
        <?php endif; ?>
        </span>
        <span class="position-anzahl"><?php echo (int)$pos['anzahl']; ?>x</span>
    </div>
    <?php endforeach; ?>
</div>

<div class="total">
    Gesamt: <?php echo number_format($gesamtsumme, 2, ',', '.'); ?> €
</div>

<div class="abholung-hinweis">
    <strong>Bitte bei der jeweiligen Station<br>mit Bon-Nummer abholen!</strong>
</div>

<div class="footer">
    <p>Datum (Beleg): <?php echo date('d.m.Y'); ?></p>
    <p>Druckzeit: <?php echo date('H:i'); ?> Uhr</p>
    <p>Vielen Dank!</p>
</div>

</body>
</html>
