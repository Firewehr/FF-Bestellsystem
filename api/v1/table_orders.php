<?php
declare(strict_types=1);

/**
 * Liefert alle offenen Bestellungen und Abrechnungsinformationen für einen Tisch.
 * Ermöglicht getrennte oder vollständige Abrechnung, Rückgeld-Kalkulation und Belegdruck.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/settings.php';
require_once dirname(__DIR__, 2) . '/include/menu_list_helpers.php';
require_once dirname(__DIR__, 2) . '/include/ff_schreibaus.php';
require_once dirname(__DIR__, 2) . '/include/ff_sammelrechnung_helpers.php';
require_once dirname(__DIR__, 2) . '/include/ff_rechnungen_ensure_columns.php';

$user = api_require_auth();

$tischnummer = isset($_GET['table']) ? (int)$_GET['table'] : (isset($_GET['table_id']) ? (int)$_GET['table_id'] : (int)($_GET['tischnummer'] ?? 0));
if ($tischnummer <= 0) {
    api_error('Tischnummer erforderlich.', 'missing_table', 400);
}

// 1. Tisch-Stammdaten abfragen
$tStmt = mysqli_prepare($conn, 'SELECT tischnummer, tischname, COALESCE(is_sammelrechnung,0) AS is_sammelrechnung, COALESCE(is_ehrengast,0) AS is_ehrengast, COALESCE(color,"") AS color FROM tische WHERE tischnummer = ? LIMIT 1');
if (!$tStmt) {
    api_error('Datenbankfehler: ' . mysqli_error($conn), 'db_error', 500);
}
mysqli_stmt_bind_param($tStmt, 'i', $tischnummer);
mysqli_stmt_execute($tStmt);
$tRes = mysqli_stmt_get_result($tStmt);
$table = $tRes ? mysqli_fetch_assoc($tRes) : null;
mysqli_stmt_close($tStmt);

if (!$table) {
    api_error("Tisch mit Nummer {$tischnummer} nicht gefunden.", 'table_not_found', 404);
}

$isSammel = (int)$table['is_sammelrechnung'] === 1;
$isEhren = (int)$table['is_ehrengast'] === 1;

// 2. Aktiver Zahlungsmodus des Fests
$paymentMode = ff_aktiver_payment_mode($conn);

// 3. Wartezeiten für Küche und Schank berechnen
$waitTimes = ['drink' => 0, 'food' => 0];
$wq = @mysqli_query(
    $conn,
    "SELECT print_target, UNIX_TIMESTAMP(MIN(zeitstempel)) AS oldest_unix
       FROM bestellungen
      WHERE `delete`=0
        AND kueche=0
        AND zeitKueche='0000-00-00 00:00:00'
        AND print_target IN (11,12)
      GROUP BY print_target"
);
if ($wq) {
    while ($wr = mysqli_fetch_assoc($wq)) {
        $ptId = (int)($wr['print_target'] ?? 0);
        $oldestUnix = (int)($wr['oldest_unix'] ?? 0);
        if ($oldestUnix > 0) {
            $mins = (int) round(max(0, time() - $oldestUnix) / 60);
            if ($mins <= 120) {
                if ($ptId === 12) $waitTimes['drink'] = $mins;
                if ($ptId === 11) $waitTimes['food'] = $mins;
            }
        }
    }
    @mysqli_free_result($wq);
}

// 4. SumUp & Kassa Einstellungen
$sumupEnabled = (setting_get($conn, 'sumup_open_app_button', '0') === '1');
$payCashModal = (setting_get($conn, 'pay_cash_modal', '0') === '1');
$sumupAffiliateKey = trim((string)setting_get($conn, 'sumup_affiliate_key', ''));
$sumupAppId = trim((string)setting_get($conn, 'sumup_app_id', 'at.ff.bestellsystem.web'));
if ($sumupAppId === '') {
    $sumupAppId = 'at.ff.bestellsystem.web';
}

// 5. Druckziele für Thermobondruck
$printTargets = [];
$ptRes = @mysqli_query($conn, 'SELECT print_target, name FROM print_targets WHERE active=1 ORDER BY sort_order, name');
if ($ptRes) {
    while ($ptRow = mysqli_fetch_assoc($ptRes)) {
        $printTargets[] = [
            'id' => (int)$ptRow['print_target'],
            'name' => (string)$ptRow['name'],
        ];
    }
    @mysqli_free_result($ptRes);
}

// 6. Offene unbezahlte Positionen abfragen
$openSql = "SELECT b.rowid, b.position, COALESCE(b.betrag, p.Betrag) AS betrag, 
                   CONCAT_WS(', ', NULLIF(b.Zusatzinfo, ''), NULLIF((SELECT GROUP_CONCAT(CASE WHEN rc.selected = 1 THEN CONCAT('extra ', rc.name_snapshot) ELSE CONCAT('ohne ', rc.name_snapshot) END ORDER BY rc.bestandteil_id SEPARATOR ', ') FROM bestellung_rezeptur_komponenten rc WHERE rc.bestellung_rowid = b.rowid AND rc.optional_enabled = 1 AND rc.selected <> rc.default_selected), '')) AS Zusatzinfo,
                   b.kueche, b.zeitKueche, b.order_nr, b.timestampBestellung, b.kellner,
                   COALESCE(p.Positionsname, '(Unbekannte Position)') AS Positionsname,
                   COALESCE(p.Kurzbezeichnung, p.Positionsname) AS Kurzbezeichnung,
                   COALESCE(p.type, 1) AS type
            FROM bestellungen b
            LEFT JOIN positionen p ON p.rowid = b.position
            WHERE b.tischnummer = ?
              AND b.`delete` = 0
              AND b.bestellt = 1
              AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung = '0000-00-00 00:00:00')
            ORDER BY b.timestampBestellung ASC, b.rowid ASC";

$openStmt = mysqli_prepare($conn, $openSql);
if (!$openStmt) {
    api_error('Datenbankfehler bei offenen Positionen: ' . mysqli_error($conn), 'db_error', 500);
}
mysqli_stmt_bind_param($openStmt, 'i', $tischnummer);
mysqli_stmt_execute($openStmt);
$oRes = mysqli_stmt_get_result($openStmt);

$items = [];
$groupedMap = [];
$totalAmount = 0.0;

while ($row = mysqli_fetch_assoc($oRes)) {
    $rId = (int)$row['rowid'];
    $pId = (int)$row['position'];
    $betrag = (float)$row['betrag'];
    if ($isEhren) {
        $betrag = 0.0;
    }
    $zusatz = trim((string)$row['Zusatzinfo']);
    $isKuecheReady = ((int)$row['kueche'] === 1 && !empty($row['zeitKueche']) && $row['zeitKueche'] !== '0000-00-00 00:00:00');
    $canPay = ($paymentMode === 'instant' || $isKuecheReady || $isEhren);

    $itemData = [
        'rowid' => $rId,
        'position_id' => $pId,
        'name' => (string)$row['Positionsname'],
        'short_name' => (string)$row['Kurzbezeichnung'],
        'price' => $betrag,
        'zusatzinfo' => $zusatz,
        'order_nr' => (int)$row['order_nr'],
        'kellner' => (string)$row['kellner'],
        'kueche_ready' => $isKuecheReady,
        'can_pay' => $canPay,
    ];
    $items[] = $itemData;

    $totalAmount += $betrag;

    // Aggregierte Gruppierung für Touch-Abrechnung (gleiche Position + Zusatzinfo + Betrag)
    $groupKey = $pId . '_' . number_format($betrag, 2, '.', '') . '_' . $zusatz;
    if (!isset($groupedMap[$groupKey])) {
        $groupedMap[$groupKey] = [
            'group_key' => $groupKey,
            'position_id' => $pId,
            'name' => (string)$row['Positionsname'],
            'short_name' => (string)$row['Kurzbezeichnung'],
            'single_price' => $betrag,
            'quantity' => 0,
            'total_price' => 0.0,
            'zusatzinfo' => $zusatz,
            'rowids' => [],
            'payable_rowids' => [],
        ];
    }
    $groupedMap[$groupKey]['quantity']++;
    $groupedMap[$groupKey]['total_price'] += $betrag;
    $groupedMap[$groupKey]['rowids'][] = $rId;
    if ($canPay) {
        $groupedMap[$groupKey]['payable_rowids'][] = $rId;
    }
}
mysqli_stmt_close($openStmt);

// 7. Bereits bezahlte Positionen ohne Rechnung (für "Nachträgliche Rechnung")
$pendingRechnung = ff_tisch_count_paid_without_rechnung($conn, $tischnummer);

api_response([
    'ok' => true,
    'table' => [
        'tischnummer' => $tischnummer,
        'tischname' => (string)($table['tischname'] ?: 'Tisch ' . $tischnummer),
        'is_sammelrechnung' => $isSammel,
        'is_ehrengast' => $isEhren,
        'color' => (string)$table['color'],
    ],
    'payment_mode' => $paymentMode,
    'wait_times' => $waitTimes,
    'pay_cash_modal' => $payCashModal,
    'sumup' => [
        'enabled' => $sumupEnabled && !empty($sumupAffiliateKey),
        'affiliate_key' => $sumupAffiliateKey,
        'app_id' => $sumupAppId,
    ],
    'print_targets' => $printTargets,
    'total_amount' => round($totalAmount, 2),
    'open_count' => count($items),
    'pending_rechnung_count' => $pendingRechnung,
    'items' => $items,
    'grouped' => array_values($groupedMap),
]);
