<?php
/**
 * Admin: Vorschau & Ausführung „offene Posten schließen“ (Schreibaus, kein DELETE).
 * Nur Administrator (1) und Super-Admin (2).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_schreibaus.php';

if (!isset($_SESSION['login']) || (int)($_SESSION['admin'] ?? 0) < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung.']);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');
ff_schreibaus_ensure_column($conn);

$paymentMode = ff_aktiver_payment_mode($conn);
$openCond = ff_schreibaus_open_sql_condition($paymentMode);

$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

function ff_abrechnung_umsatz_heute(mysqli $conn): float {
    $sql = "SELECT COALESCE(SUM(COALESCE(b.betrag, p.Betrag)), 0) AS s
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            WHERE b.`delete`=0
              AND IFNULL(b.schreibaus,0)=0
              AND IFNULL(b.is_gratis,0)=0
              AND b.timestampBezahlung IS NOT NULL
              AND b.timestampBezahlung <> '0000-00-00 00:00:00'
              AND DATE(b.timestampBezahlung) = CURDATE()";
    $res = mysqli_query($conn, $sql);
    if ($res && ($r = mysqli_fetch_assoc($res))) {
        return (float)$r['s'];
    }
    return 0.0;
}

/**
 * @return array{cnt:int,sum:float,ids:int[]}
 */
function ff_schreibaus_collect(mysqli $conn, string $openCond, string $scope, int $tableId, bool $includeDirekt): array {
    $tischFilter = '';
    if ($scope === 'table' && $tableId > 0) {
        $tischFilter = ' AND b.tischnummer = ' . $tableId;
    } elseif ($scope === 'all_tables' && !$includeDirekt) {
        $tischFilter = ' AND b.tischnummer <> 999999';
    }
    $sql = "SELECT b.rowid, COALESCE(b.betrag, p.Betrag) AS betrag
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            WHERE {$openCond}{$tischFilter}";
    $res = mysqli_query($conn, $sql);
    $ids = [];
    $sum = 0.0;
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $ids[] = (int)$r['rowid'];
            $sum += (float)$r['betrag'];
        }
    }
    return ['cnt' => count($ids), 'sum' => $sum, 'ids' => $ids];
}

if ($action === 'preview') {
    $scope = isset($_GET['scope']) ? (string)$_GET['scope'] : 'all_tables';
    if (!in_array($scope, ['all_tables', 'all', 'table'], true)) {
        $scope = 'all_tables';
    }
    $tableId = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
    $includeDirekt = isset($_GET['include_direkt']) && (string)$_GET['include_direkt'] === '1';

    $col = ff_schreibaus_collect($conn, $openCond, $scope, $tableId, $includeDirekt);
    $umsatz = ff_abrechnung_umsatz_heute($conn);
    $pct = null;
    if ($umsatz > 0.00001) {
        $pct = round(($col['sum'] / $umsatz) * 100, 2);
    } elseif ($col['sum'] > 0) {
        $pct = null;
    } else {
        $pct = 0.0;
    }

    $scopeLabel = 'Alle Tische (ohne Direktverkauf)';
    if ($scope === 'all') {
        $scopeLabel = 'Alle inkl. Direktverkauf (999999)';
    } elseif ($scope === 'table' && $tableId > 0) {
        $scopeLabel = 'Nur Tisch #' . $tableId;
    }

    echo json_encode([
        'ok' => true,
        'payment_mode' => $paymentMode,
        'scope' => $scope,
        'scope_label' => $scopeLabel,
        'open_count' => $col['cnt'],
        'open_sum' => round($col['sum'], 2),
        'umsatz_heute' => round($umsatz, 2),
        'pct_vom_umsatz_heute' => $pct,
        'hint' => $paymentMode === 'after'
            ? 'After-Modus: betroffen sind gelieferte/küchenfertige Zeilen ohne Zahlung.'
            : 'Sofort-Modus: betroffen sind abgeschickte, aber noch nicht bezahlte Zeilen.',
    ], JSON_UNESCAPED_UNICODE);
    mysqli_close($conn);
    exit;
}

if ($action === 'execute') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST erforderlich.']);
        exit;
    }
    $scope = isset($_POST['scope']) ? (string)$_POST['scope'] : 'all_tables';
    if (!in_array($scope, ['all_tables', 'all', 'table'], true)) {
        $scope = 'all_tables';
    }
    $tableId = isset($_POST['table_id']) ? (int)$_POST['table_id'] : 0;
    $includeDirekt = isset($_POST['include_direkt']) && (string)$_POST['include_direkt'] === '1';
    $phrase = isset($_POST['confirm_phrase']) ? trim((string)$_POST['confirm_phrase']) : '';
    if (strtoupper($phrase) !== 'SCHLIESSEN') {
        echo json_encode(['ok' => false, 'error' => 'Bestätigung: Bitte genau SCHLIESSEN eingeben.']);
        mysqli_close($conn);
        exit;
    }

    $col = ff_schreibaus_collect($conn, $openCond, $scope, $tableId, $includeDirekt);
    if ($col['cnt'] === 0) {
        echo json_encode(['ok' => true, 'affected' => 0, 'sum' => 0.0, 'message' => 'Keine passenden Zeilen.']);
        mysqli_close($conn);
        exit;
    }

    $adminUser = isset($_SESSION['user']['username']) ? (string)$_SESSION['user']['username'] : 'admin';
    $kz = 'SCHREIBAUS:' . $adminUser;
    if (strlen($kz) > 200) {
        $kz = substr($kz, 0, 200);
    }
    $kzEsc = mysqli_real_escape_string($conn, $kz);

    $ids = $col['ids'];
    $in = implode(',', array_map('intval', $ids));
    $sql = "UPDATE bestellungen b
            SET b.timestampBezahlung = CURRENT_TIMESTAMP,
                b.kellnerZahlung = '{$kzEsc}',
                b.schreibaus = 1
            WHERE b.rowid IN ({$in})
              AND b.`delete` = 0
              AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung='0000-00-00 00:00:00')
              AND IFNULL(b.schreibaus,0) = 0";

    if (!mysqli_query($conn, $sql)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
        mysqli_close($conn);
        exit;
    }

    $aff = mysqli_affected_rows($conn);
    echo json_encode([
        'ok' => true,
        'affected' => (int)$aff,
        'sum' => round($col['sum'], 2),
        'payment_mode' => $paymentMode,
    ], JSON_UNESCAPED_UNICODE);
    mysqli_close($conn);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unbekannte action.']);
