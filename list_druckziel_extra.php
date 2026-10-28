<?php
/**
 * Gastro-Monitor für Druckziel mit AJAX-Auto-Refresh (Max. 25 offene Positionen, Wartezeit per SQL, automatische Spalten)
 * Aufruf: diese_datei.php?print_target=11
 */
require_once __DIR__ . '/auth.php';
include_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_kueche_station_view.php';
require_once __DIR__ . '/include/ff_station_list_shell.php';
require_once __DIR__ . '/include/ff_print_target_labels.php';

$print_target = isset($_GET['print_target']) ? (int) $_GET['print_target'] : 0;

// AJAX Endpoint: Wenn ?ajax=1 übergeben wird, geben wir JSON mit Gesamtanzahl und HTML zurück
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');

    $stationCtx = ff_station_build_context($conn, [
        'mode' => 'druckziel',
        'print_target' => $print_target,
        'payment_mode' => 'after',
    ]);
    
    $totalCount = 0;
    if (isset($stationCtx['wartende'])) {
        $totalCount = (int) $stationCtx['wartende'];
    } elseif (!empty($stationCtx['summary'])) {
        foreach ($stationCtx['summary'] as $item) {
            if (is_array($item) && isset($item['anzahl'])) {
                $totalCount += (int) $item['anzahl'];
            } else {
                $totalCount++;
            }
        }
    }

    // Hilfs-Array für die exakte Ermittlung des Erstellungszeitpunkts pro Positionsnamen/Bestellung aus der DB
    // Wir suchen in positionen + bestellungen nach dem ältesten offenen Zeitpunkt für diesen Artikel
    $timeMap = [];
    $posCols = [];
    $resCols = @mysqli_query($conn, "SHOW COLUMNS FROM positionen");
    if ($resCols) { while ($r = mysqli_fetch_assoc($resCols)) { $posCols[] = $r['Field']; } }

    $bestCols = [];
    $resBestCols = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen");
    if ($resBestCols) { while ($r = mysqli_fetch_assoc($resBestCols)) { $bestCols[] = $r['Field']; } }

    $fkCol = '';
    foreach (['bestellung_id', 'bestellungen_id', 'order_id'] as $c) {
        if (in_array($c, $posCols)) { $fkCol = $c; break; }
    }
    
    $timeCol = '';
    foreach (['zeit', 'created_at', 'timestamp', 'datum'] as $c) {
        if (in_array($c, $bestCols)) { $timeCol = $c; break; }
    }
    
    $nameCol = '';
    foreach (['name', 'artikel_name', 'titel'] as $c) {
        if (in_array($c, $posCols)) { $nameCol = $c; break; }
    }

    if ($fkCol && $timeCol && $nameCol) {
        $statusCheck = in_array('status', $posCols) ? "AND (p.status = 0 OR p.status IS NULL)" : "";
        $timeQuery = "SELECT p.{$nameCol} as item_name, MIN(b.{$timeCol}) as min_time 
                      FROM positionen p 
                      JOIN bestellungen b ON p.{$fkCol} = b.id 
                      WHERE p.print_target = {$print_target} {$statusCheck} 
                      GROUP BY p.{$nameCol}";
        
        $tRes = @mysqli_query($conn, $timeQuery);
        if ($tRes) {
            while ($tRow = mysqli_fetch_assoc($tRes)) {
                $timeMap[trim($tRow['item_name'])] = $tRow['min_time'];
            }
        }
    }

    $hasItems = false;
    $displayedCount = 0;
    $html = '';
    
    if (!empty($stationCtx['summary'])) {
        foreach ($stationCtx['summary'] as $item) {
            if ($displayedCount >= 25) {
                break; // Maximal 25 Positionen anzeigen
            }
            
            $text = '';
            $anzahl = 1;
            if (is_array($item)) {
                $text = $item['name'] ?? $item[0] ?? implode(', ', $item);
                $anzahl = isset($item['anzahl']) ? (int) $item['anzahl'] : ($item['count'] ?? 1);
            } else {
                $text = (string) $item;
            }
            
            // Entfernt jeglichen Inhalt nach einem Komma (inkl. Komma selbst)
            $cleanText = trim(preg_replace('/,\s*\d+.*$/', '', $text));
            
            if ($cleanText !== '') {
                $displayLabel = ($anzahl > 1) ? $anzahl . ' x ' . $cleanText : $cleanText;
                
                // Wartezeit ermitteln
                $timeHtml = '';
                if (isset($timeMap[$cleanText])) {
                    $diffMins = max(0, round((time() - strtotime($timeMap[$cleanText])) / 60));
                    $timeHtml = '<div class="item-time">vor ' . $diffMins . ' Min.</div>';
                }

                $html .= '<div class="item-card">';
                $html .= '<div class="item-row">' . htmlspecialchars($displayLabel, ENT_QUOTES, 'UTF-8') . '</div>';
                $html .= $timeHtml;
                $html .= '</div>';

                $hasItems = true;
                $displayedCount++;
            }
        }
    }
    
    if (!$hasItems) {
        $html = '<div class="empty">Keine offenen Positionen</div>';
    }

    echo json_encode([
        'total' => $totalCount,
        'html' => $html
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Küche Monitor - Positionen (Live)</title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            background-color: #000000;
            color: #ffffff;
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .header-bar {
            width: 95%;
            max-width: 1800px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #111111;
            border: 3px solid #333333;
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-sizing: border-box;
        }
        .header-title {
            font-size: 3.5vh;
            font-weight: bold;
            color: #38bdf8;
            text-transform: uppercase;
        }
        .header-counter {
            font-size: 3.5vh;
            font-weight: bold;
            background: #ef4444;
            color: #ffffff;
            padding: 8px 20px;
            border-radius: 8px;
        }
        .items-container {
            width: 95%;
            max-width: 1800px;
            margin-top: 10px;
            column-count: 2;
            column-gap: 20px;
        }
        .item-card {
            break-inside: avoid;
            margin-bottom: 15px;
            background: #111111;
            border: 3px solid #333333;
            width: 100%;
            padding: 20px;
            border-radius: 10px;
            box-sizing: border-box;
            box-shadow: 0 4px 6px rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .item-row {
            font-size: 4.5vh;
            font-weight: bold;
            color: #ffffff;
            text-align: center;
            width: 100%;
        }
        .item-time {
            font-size: 2.2vh;
            color: #facc15; /* Gelb/Gold für gute Lesbarkeit der Wartezeit */
            margin-top: 8px;
            font-weight: bold;
        }
        .empty {
            column-count: 1;
            font-size: 6vh;
            color: #777777;
            margin-top: 25vh;
            text-align: center;
            width: 100%;
        }
    </style>
</head>
<body>

    <div class="header-bar">
        <div class="header-title">Wartende Positionen</div>
        <div class="header-counter" id="totalCounter">0 offen</div>
    </div>

    <div class="items-container" id="itemsContainer">
        <!-- Inhalt wird beim Start per JavaScript geladen -->
    </div>

    <script>
    function refreshContent() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', window.location.pathname + '?print_target=<?php echo $print_target; ?>&ajax=1', true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    document.getElementById('totalCounter').textContent = response.total + ' offen';
                    document.getElementById('itemsContainer').innerHTML = response.html;
                } catch (e) {
                    document.getElementById('itemsContainer').innerHTML = xhr.responseText;
                }
            }
        };
        xhr.send();
    }

    refreshContent();
    setInterval(refreshContent, 5000);
    </script>

</body>
</html>
