<?php
/**
 * Bestellung abschließen: Alle Positionen als ausgeliefert markieren und druckbereit setzen.
 * Wird angezeigt, wenn alle Positionen einer Bestellung fertig sind (rechts) –
 * oder (Einstellung station_one_click_abschliessen) auch bei noch offenen Positionen
 * (dann werden offene mit als fertig markiert).
 * Bon/Thermodruck soll erst hier passieren (nicht bereits bei "Gesamt fertig"),
 * außer Teillieferung hat einzelne Positionen schon gedruckt (print_status=1 → kein erneutes Drucken).
 */
require_once('auth.php');
require_once('include/db.php');

header('Content-Type: application/json; charset=utf-8');

$tischnummer = intval($_POST['tischnummer'] ?? 0);
$PositionsListe = $_POST['listePositionen'] ?? $_REQUEST['listePositionen'] ?? [];
if (!is_array($PositionsListe)) {
    $PositionsListe = [];
}

// mit_druck=1: Thermo-Bon an das Stations-Druckziel ausgeben ("Bestellung abschließen").
// mit_druck=0: nur abschließen, kein Druck ("Abholbon abschließen").
$mitDruck = (int) ($_POST['mit_druck'] ?? 0) === 1;

if ($tischnummer <= 0 || !is_array($PositionsListe) || empty($PositionsListe)) {
    echo json_encode(['ok' => false, 'error' => 'fehlende_daten']);
    exit;
}

$timestamp = date('Y-m-d H:i:s');
$printed = 0;
$closed = 0;

foreach ($PositionsListe as $row) {
    $rowid = intval($row);
    if ($rowid <= 0) {
        continue;
    }

    // Offene Positionen (Ein-Klick) ebenfalls als fertig markieren
    @mysqli_query(
        $conn,
        "UPDATE bestellungen SET "
        . "kueche=1, "
        . "zeitKueche=IF(zeitKueche IS NULL OR zeitKueche IN ('0000-00-00 00:00:00','1970-01-01 00:00:00'), current_timestamp, zeitKueche) "
        . "WHERE rowid=" . $rowid
    );

    if ($mitDruck) {
        // Bereits als Teillieferung gedruckt? → nur ausliefern, nicht erneut in die Druck-Queue
        $chk = mysqli_query($conn, 'SELECT COALESCE(print_status,0) AS ps FROM bestellungen WHERE rowid=' . $rowid . ' LIMIT 1');
        $alreadyPrinted = false;
        if ($chk && ($cr = mysqli_fetch_assoc($chk))) {
            $alreadyPrinted = ((int) ($cr['ps'] ?? 0) === 1);
        }

        if ($alreadyPrinted) {
            $sql = "UPDATE bestellungen SET "
                . "ausgeliefert=1, "
                . "timestampAuslieferung=current_timestamp, "
                . "bestellt=1, "
                . "kueche=1 "
                . "WHERE rowid=" . $rowid;
            mysqli_query($conn, $sql);
        } else {
            // bestellt=1 + kueche=1 + print=2 + print_status=0 → Thermo-Client (print_target.php).
            $sql = "UPDATE bestellungen SET "
                . "ausgeliefert=1, "
                . "timestampAuslieferung=current_timestamp, "
                . "bestellt=1, "
                . "kueche=1, "
                . "print=2, "
                . "print_status=0 "
                . "WHERE rowid=" . $rowid;
            mysqli_query($conn, $sql);
            @mysqli_query($conn, "INSERT INTO print (bestellungID, timestamp) VALUES (" . $rowid . ",'" . mysqli_real_escape_string($conn, $timestamp) . "')");
            $printed++;
        }
    } else {
        $sql = "UPDATE bestellungen SET "
            . "ausgeliefert=1, "
            . "timestampAuslieferung=current_timestamp "
            . "WHERE rowid=" . $rowid;
        mysqli_query($conn, $sql);
    }
    $closed++;
}

echo json_encode([
    'ok' => true,
    'mit_druck' => $mitDruck ? 1 : 0,
    'closed' => $closed,
    'queued_print' => $printed,
]);
