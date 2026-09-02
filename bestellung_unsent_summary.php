<?php
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache');

$tischnummer = (int) ($_POST['tischnummer'] ?? $_GET['tischnummer'] ?? 0);
if ($tischnummer <= 0 || $tischnummer === 999999) {
    echo json_encode(['ok' => false, 'message' => 'invalid_tischnummer'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/beilage_helpers.php';

$sql = "
    SELECT p.rowid, p.Positionsname AS name, COALESCE(b.betrag, p.Betrag, 0) AS price,
           TRIM(COALESCE(b.Zusatzinfo, '')) AS zusatzinfo,
           COUNT(*) AS quantity, SUM(COALESCE(b.betrag, p.Betrag, 0)) AS total
    FROM bestellungen b
    INNER JOIN positionen p ON p.rowid = b.position
    WHERE b.tischnummer = ?
      AND b.delete = 0
      AND (b.bestellt IS NULL OR b.bestellt = 0)
    GROUP BY p.rowid, p.Positionsname, TRIM(COALESCE(b.Zusatzinfo, '')), COALESCE(b.betrag, p.Betrag, 0)
    ORDER BY p.Positionsname ASC, TRIM(COALESCE(b.Zusatzinfo, '')) ASC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['ok' => false, 'message' => 'summary_sql_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_stmt_bind_param($stmt, 'i', $tischnummer);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$lines = [];
$total = 0.0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $qty = (int) ($row['quantity'] ?? 0);
        $price = (float) ($row['price'] ?? 0);
        $lineTotal = (float) ($row['total'] ?? ($qty * $price));
        $hintText = trim((string) ($row['zusatzinfo'] ?? ''));
        $positionId = (int) ($row['rowid'] ?? 0);
        $hintHtml = $hintText !== '' ? ff_zusatzinfo_display_html($conn, $positionId, $hintText) : '';
        $total += $lineTotal;
        $lines[] = [
            'position_id' => $positionId,
            'name' => (string) ($row['name'] ?? 'Unbekannt'),
            'quantity' => $qty,
            'price' => round($price, 2),
            'total' => round($lineTotal, 2),
            'hint' => $hintText,
            'hint_html' => $hintHtml,
        ];
    }
}

mysqli_stmt_close($stmt);

echo json_encode([
    'ok' => true,
    'tischnummer' => $tischnummer,
    'lines' => $lines,
    'total' => round($total, 2),
], JSON_UNESCAPED_UNICODE);
