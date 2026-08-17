<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_schreibaus.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nur für Administratoren.';
    exit;
}

if (!$conn) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Keine Datenbankverbindung.';
    exit;
}

ff_schreibaus_ensure_column($conn);
mysqli_set_charset($conn, 'utf8mb4');

$sql = "SELECT COUNT(*) as cnt, bestellungen.kellnerZahlung,
        SUM(COALESCE(NULLIF(bestellungen.betrag, 0), positionen.Betrag)) as summe
        FROM bestellungen, positionen
        WHERE bestellungen.position = positionen.rowid
          AND bestellungen.`delete` = 0
          AND IFNULL(bestellungen.is_gratis, 0) = 0
          AND IFNULL(bestellungen.schreibaus, 0) = 0
          AND bestellungen.timestampBezahlung != '0000-00-00 00:00:00'
        GROUP BY bestellungen.kellnerZahlung
        ORDER BY bestellungen.kellnerZahlung";

$result = mysqli_query($conn, $sql);

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Kellner Umsatz</title></head><body>';
echo '<table border="1" cellpadding="4"><thead><tr><th>Kellner (kellnerZahlung)</th><th>Anzahl</th><th>Summe</th></tr></thead><tbody>';
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $kz = htmlspecialchars((string) ($row['kellnerZahlung'] ?? ''), ENT_QUOTES, 'UTF-8');
        $cnt = (int) ($row['cnt'] ?? 0);
        $sum = number_format((float) ($row['summe'] ?? 0), 2, ',', '.');
        echo '<tr><td>' . $kz . '</td><td>' . $cnt . '</td><td>' . $sum . ' €</td></tr>';
    }
}
echo '</tbody></table></body></html>';
