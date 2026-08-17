<?php
/**
 * Markiert ausgewählte Bestellzeilen als bezahlt (timestampBezahlung, kellnerZahlung).
 * JSON-Antwort für zuverlässige Auswertung im Frontend.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';

header('Content-Type: application/json; charset=utf-8');

$raw = $_POST['listePositionen'] ?? $_REQUEST['listePositionen'] ?? null;
if (!is_array($raw)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'listePositionen_fehlt']);
    exit;
}

$ids = [];
foreach ($raw as $row) {
    $n = (int)$row;
    if ($n > 0) {
        $ids[$n] = true;
    }
}
$ids = array_keys($ids);

if ($ids === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'keine_gueltigen_ids']);
    exit;
}

$kellner = isset($_SESSION['user']['username']) ? (string)$_SESSION['user']['username'] : '';
$kellnerEsc = mysqli_real_escape_string($conn, $kellner);

// Session früh freigeben: kürzere Sperre bei parallelen Requests (Küche-Poll, Status, …)
session_write_close();

$in = implode(',', $ids);

// Schutz: Sammelrechnungs-Tische dürfen NUR über den separaten Button
// "Sammelrechnung erstellen" (SammelrechnungBezahlt.php) abgerechnet werden,
// sonst bleibt is_sammelrechnung gesetzt und der Tisch hängt.
$sammelCheck = @mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM bestellungen b
       JOIN tische t ON t.tischnummer = b.tischnummer
      WHERE b.`delete` = 0 AND b.rowid IN (" . $in . ") AND COALESCE(t.is_sammelrechnung, 0) = 1"
);
if ($sammelCheck) {
    $scRow = mysqli_fetch_assoc($sammelCheck);
    mysqli_free_result($sammelCheck);
    if ((int)($scRow['c'] ?? 0) > 0) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error' => 'sammelrechnung_tisch',
            'message' => 'Dieser Tisch ist als Sammelrechnung markiert. Bitte über "Sammelrechnung erstellen" abrechnen.'
        ], JSON_UNESCAPED_UNICODE);
        mysqli_close($conn);
        exit;
    }
}

$sql = "UPDATE `bestellungen` SET `timestampBezahlung` = CURRENT_TIMESTAMP, `kellnerZahlung` = '" . $kellnerEsc . "' "
    . "WHERE `delete` = 0 AND `rowid` IN (" . $in . ")";

if (!mysqli_query($conn, $sql)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    mysqli_close($conn);
    exit;
}

$affected = mysqli_affected_rows($conn);
mysqli_close($conn);

echo json_encode(['ok' => true, 'affected' => $affected], JSON_UNESCAPED_UNICODE);
