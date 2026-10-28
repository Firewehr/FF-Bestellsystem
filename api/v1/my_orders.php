<?php
declare(strict_types=1);

/**
 * Liefert alle noch nicht ausgelieferten Bestellungen des aktuell angemeldeten Kellners.
 * Signalisiert durch `is_ready`, ob Speisen oder Getränke an Küche/Schank abholbereit sind.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/bestellung_batch_key_sql.php';
require_once dirname(__DIR__, 2) . '/include/menu_list_helpers.php';
require_once dirname(__DIR__, 2) . '/include/ff_schreibaus.php';

$user = api_require_auth();

$username = (string)$user['username'];
$usernameEsc = mysqli_real_escape_string($conn, $username);

$paymentMode = ff_aktiver_payment_mode($conn);
$batchKeyExpr = ff_sql_bestellung_batch_key('b');

$statusFilter = ' AND b.ausgeliefert = 0 ';
if ($paymentMode === 'instant') {
    $statusFilter .= ' AND b.bestellt = 1 ';
}

$kellnerFilter = " AND (b.kellner = '{$usernameEsc}' OR b.kellnerZahlung = '{$usernameEsc}') ";

$sql = "SELECT 
    b.tischnummer, 
    MAX(t.tischname) AS tischname, 
    MAX(b.order_nr) AS order_nr, 
    {$batchKeyExpr} AS batch_key, 
    COUNT(*) AS cnt, 
    COALESCE(SUM(COALESCE(b.betrag, p.Betrag)), 0) AS total_amount,
    MIN(b.zeitstempel) AS created_at,
    MAX(CASE WHEN b.kueche = 1 AND b.zeitKueche IS NOT NULL AND b.zeitKueche NOT IN ('0000-00-00 00:00:00','1970-01-01 00:00:00') THEN 1 ELSE 0 END) AS is_ready,
    MAX(CASE WHEN b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung NOT IN ('0000-00-00 00:00:00','1970-01-01 00:00:00') THEN 1 ELSE 0 END) AS is_paid
FROM bestellungen b
JOIN positionen p ON b.position = p.rowid
JOIN tische t ON t.tischnummer = b.tischnummer
WHERE b.`delete` = 0 {$statusFilter} {$kellnerFilter}
GROUP BY b.tischnummer, {$batchKeyExpr}
ORDER BY is_ready DESC, MIN(b.zeitstempel) ASC
LIMIT 50";

$res = mysqli_query($conn, $sql);
$orders = [];
$readyCount = 0;

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $isReady = ((int)$row['is_ready'] === 1);
        if ($isReady) {
            $readyCount++;
        }

        $orders[] = [
            'tischnummer' => (int)$row['tischnummer'],
            'tischname' => (string)($row['tischname'] ?: 'Tisch ' . $row['tischnummer']),
            'order_nr' => (int)($row['order_nr'] ?? 0),
            'batch_key' => (string)$row['batch_key'],
            'item_count' => (int)$row['cnt'],
            'total_amount' => round((float)$row['total_amount'], 2),
            'created_at' => (string)$row['created_at'],
            'is_ready' => $isReady,
            'is_paid' => ((int)$row['is_paid'] === 1),
            'status_label' => $isReady ? 'Bereit zum Abholen (Küche/Schank)' : 'In Zubereitung',
        ];
    }
    mysqli_free_result($res);
}

api_response([
    'ok' => true,
    'total_open' => count($orders),
    'ready_count' => $readyCount,
    'orders' => $orders,
]);
