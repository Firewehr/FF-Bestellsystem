<?php
declare(strict_types=1);

/**
 * Liefert die Bestellhistorie eines Tisches, gruppiert nach Runden/Bestellnummern.
 * Unterstützt Storno- und Verschiebe-Berechtigungsprüfungen für Kellner und Admins.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/ff_bestellung_verschieben.php';
require_once dirname(__DIR__, 2) . '/include/ff_bestellung_storno.php';
require_once dirname(__DIR__, 2) . '/include/menu_list_helpers.php';
require_once dirname(__DIR__, 2) . '/include/ff_schreibaus.php';

$user = api_require_auth();

$tischnummer = isset($_GET['table']) ? (int)$_GET['table'] : (isset($_GET['table_id']) ? (int)$_GET['table_id'] : (int)($_GET['tischnummer'] ?? 0));
if ($tischnummer <= 0) {
    api_error('Tischnummer erforderlich.', 'missing_table', 400);
}

$isAdmin = !empty($user['admin']) && (int)$user['admin'] >= 1;
$currentUser = (string)$user['username'];
$paymentMode = ff_aktiver_payment_mode($conn);

// Tisch-Name holen
$tStmt = mysqli_prepare($conn, 'SELECT tischname, is_ehrengast, is_sammelrechnung FROM tische WHERE tischnummer = ? LIMIT 1');
mysqli_stmt_bind_param($tStmt, 'i', $tischnummer);
mysqli_stmt_execute($tStmt);
$tRes = mysqli_stmt_get_result($tStmt);
$tRow = $tRes ? mysqli_fetch_assoc($tRes) : null;
mysqli_stmt_close($tStmt);

if (!$tRow) {
    api_error("Tisch {$tischnummer} existiert nicht.", 'table_not_found', 404);
}

// 70 aktive + 30 stornierte Positionen laden (wie Original list_Bestellungen.php)
$histSql = "SELECT b.rowid, b.tischnummer, b.position,
                   CONCAT_WS(', ', NULLIF(b.Zusatzinfo, ''), NULLIF((SELECT GROUP_CONCAT(CASE WHEN rc.selected = 1 THEN CONCAT('extra ', rc.name_snapshot) ELSE CONCAT('ohne ', rc.name_snapshot) END ORDER BY rc.bestandteil_id SEPARATOR ', ') FROM bestellung_rezeptur_komponenten rc WHERE rc.bestellung_rowid = b.rowid AND rc.optional_enabled = 1 AND rc.selected <> rc.default_selected), '')) AS Zusatzinfo,
                   b.kueche, b.zeitKueche, b.bestellt,
                   b.ausgeliefert, b.timestampAuslieferung, b.timestampBestellung, b.timestampBezahlung,
                   b.kellner, b.kellnerZahlung, b.order_nr, b.bon_id, b.delete, b.zeitstempel,
                   COALESCE(b.betrag, p.Betrag) AS betrag,
                   COALESCE(p.Positionsname, '(Position gelöscht)') AS Positionsname,
                   COALESCE(p.Kurzbezeichnung, p.Positionsname) AS Kurzbezeichnung,
                   COALESCE(p.type, 1) AS pos_type
            FROM bestellungen b
            LEFT JOIN positionen p ON p.rowid = b.position
            WHERE b.tischnummer = {$tischnummer}";

$rowsById = [];
$merge = static function ($res) use (&$rowsById): void {
    if (!$res) return;
    while ($r = mysqli_fetch_assoc($res)) {
        $rowsById[(int)($r['rowid'] ?? 0)] = $r;
    }
};

$merge(mysqli_query($conn, $histSql . " AND b.`delete` = 0 ORDER BY b.zeitstempel DESC LIMIT 70"));
$merge(mysqli_query($conn, $histSql . " AND b.`delete` = 1 ORDER BY b.zeitstempel DESC LIMIT 30"));

$rows = array_values($rowsById);
usort($rows, static function (array $a, array $b): int {
    $ta = strtotime((string)($a['zeitstempel'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['zeitstempel'] ?? '')) ?: 0;
    return $tb <=> $ta;
});

// Gruppierung nach Bestellungsrunde (batch_key / order_nr)
$groups = [];
$groupOrder = [];

foreach ($rows as $r) {
    $bk = ff_verschieben_batch_key($r);
    if (!isset($groups[$bk])) {
        $groups[$bk] = [];
        $groupOrder[] = $bk;
    }
    $groups[$bk][] = $r;
}

$rounds = [];

foreach ($groupOrder as $bk) {
    $groupRows = $groups[$bk];
    $first = $groupRows[0];
    $orderNr = (int)($first['order_nr'] ?? 0);
    $bonId = trim((string)($first['bon_id'] ?? ''));
    $timestamp = trim((string)($first['timestampBestellung'] ?: $first['zeitstempel']));

    $roundSum = 0.0;
    $liveCount = 0;
    $paidCount = 0;
    $delivCount = 0;
    $readyCount = 0;
    $items = [];
    $movableIds = [];

    foreach ($groupRows as $gr) {
        $rId = (int)$gr['rowid'];
        $isDel = (int)($gr['delete'] ?? 0) === 1;
        $bVal = (float)$gr['betrag'];
        $isPaid = (!empty($gr['timestampBezahlung']) && !in_array($gr['timestampBezahlung'], ['0000-00-00 00:00:00', '1970-01-01 00:00:00'], true));
        $isDelivered = (int)($gr['ausgeliefert'] ?? 0) === 1;
        $isReady = ((int)($gr['kueche'] ?? 0) === 1 && !empty($gr['zeitKueche']) && !in_array($gr['zeitKueche'], ['0000-00-00 00:00:00', '1970-01-01 00:00:00'], true));

        if (!$isDel) {
            $roundSum += $bVal;
            $liveCount++;
            if ($isPaid) $paidCount++;
            if ($isDelivered) $delivCount++;
            if ($isReady) $readyCount++;
        }

        $canMoveThis = ff_verschieben_can_move_row($gr, $isAdmin, $currentUser, $paymentMode);
        if ($canMoveThis) {
            $movableIds[] = $rId;
        }

        // Storno-Berechtigung: Admin darf alles; Kellner nur unbezahlte
        $canCancelThis = !$isDel && ($isAdmin || !$isPaid);

        $items[] = [
            'rowid' => $rId,
            'position_id' => (int)$gr['position'],
            'name' => (string)$gr['Positionsname'],
            'short_name' => (string)$gr['Kurzbezeichnung'],
            'price' => $bVal,
            'zusatzinfo' => (string)($gr['Zusatzinfo'] ?? ''),
            'is_paid' => $isPaid,
            'is_ready' => $isReady,
            'is_delivered' => $isDelivered,
            'is_cancelled' => $isDel,
            'can_cancel' => $canCancelThis,
            'can_move' => $canMoveThis,
        ];
    }

    $stornoWhole = ff_storno_group_whole_order_allowed($groupRows, $isAdmin);
    $canCancelWhole = (bool)$stornoWhole['can'];
    $canMoveWhole = count($movableIds) > 0;

    $payStatus = 'open';
    if ($liveCount === 0) {
        $payStatus = 'cancelled';
    } elseif ($paidCount === $liveCount) {
        $payStatus = 'paid';
    } elseif ($paidCount > 0) {
        $payStatus = 'partial';
    }

    $delivStatus = 'open';
    if ($delivCount === $liveCount && $liveCount > 0) {
        $delivStatus = 'delivered';
    } elseif ($readyCount > 0) {
        $delivStatus = 'ready';
    }

    $rounds[] = [
        'order_nr' => $orderNr,
        'bon_id' => $bonId,
        'batch_key' => (string)$bk,
        'timestamp' => $timestamp,
        'kellner' => (string)($first['kellner'] ?? ''),
        'kellner_zahlung' => (string)($first['kellnerZahlung'] ?? ''),
        'total_amount' => round($roundSum, 2),
        'live_count' => $liveCount,
        'paid_count' => $paidCount,
        'is_cancelled' => ($liveCount === 0),
        'status_payment' => $payStatus,
        'status_delivery' => $delivStatus,
        'can_cancel' => $canCancelWhole,
        'can_move' => $canMoveWhole,
        'movable_rowids' => $movableIds,
        'items' => $items,
    ];
}

api_response([
    'ok' => true,
    'table' => [
        'tischnummer' => $tischnummer,
        'tischname' => (string)($tRow['tischname'] ?: 'Tisch ' . $tischnummer),
        'is_ehrengast' => (int)$tRow['is_ehrengast'] === 1,
        'is_sammelrechnung' => (int)$tRow['is_sammelrechnung'] === 1,
    ],
    'is_admin' => $isAdmin,
    'rounds_count' => count($rounds),
    'rounds' => $rounds,
]);
