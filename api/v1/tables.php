<?php
declare(strict_types=1);

/**
 * Liefert die Liste aller Tische mit Status für die Kellner-App.
 * Enthält Tischnummer, Name, offene Positionen, eigene Bestellungen,
 * Abholbereitschaft, Wartezeiten und Tisch-Flags (Sammelrechnung, Ehrengast).
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/settings.php';
$user = api_require_auth();

$username = (string)$user['username'];
$usernameEsc = mysqli_real_escape_string($conn, $username);

$sumupEnabled = (setting_get($conn, 'sumup_open_app_button', '0') === '1');
$payCashModal = (setting_get($conn, 'pay_cash_modal', '0') === '1');
$sumupAffiliateKey = trim((string)setting_get($conn, 'sumup_affiliate_key', ''));
$sumupAppId = trim((string)setting_get($conn, 'sumup_app_id', 'at.ff.bestellsystem.web'));
if ($sumupAppId === '') {
    $sumupAppId = 'at.ff.bestellsystem.web';
}

$activeFest = null;
$festRes = mysqli_query($conn, 'SELECT id, name, payment_mode FROM feste WHERE aktiv = 1 LIMIT 1');
if ($festRes && ($fRow = mysqli_fetch_assoc($festRes))) {
    $activeFest = [
        'id' => (int)$fRow['id'],
        'name' => $fRow['name'],
        'payment_mode' => $fRow['payment_mode'] ?? 'after',
    ];
}

// Wartezeiten für Küche und Schank berechnen
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

$sql = "SELECT 
    t.tischnummer, 
    t.tischname, 
    t.color,
    COALESCE(t.x, 0) AS x,
    COALESCE(t.y, 0) AS y,
    COALESCE(t.is_sammelrechnung, 0) AS is_sammelrechnung,
    COALESCE(t.is_ehrengast, 0) AS is_ehrengast,
    COUNT(CASE WHEN b.bestellt = 1 AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung = '0000-00-00 00:00:00') AND b.`delete` = 0 THEN 1 END) AS open_items_count,
    COUNT(CASE WHEN b.bestellt = 1 AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung = '0000-00-00 00:00:00') AND b.`delete` = 0 AND (b.kellner = '{$usernameEsc}' OR b.kellnerZahlung = '{$usernameEsc}') THEN 1 END) AS my_open_items_count,
    COUNT(CASE WHEN b.`delete` = 0 AND b.ausgeliefert = 0 AND b.kueche = 1 AND b.zeitKueche IS NOT NULL AND b.zeitKueche NOT IN ('0000-00-00 00:00:00','1970-01-01 00:00:00') THEN 1 END) AS ready_items_count,
    COUNT(CASE WHEN (b.bestellt IS NULL OR b.bestellt = 0) AND b.`delete` = 0 THEN 1 END) AS unsent_items_count
FROM tische t
LEFT JOIN bestellungen b ON b.tischnummer = t.tischnummer
GROUP BY t.tischnummer
ORDER BY t.tischname ASC, t.tischnummer ASC";

$result = mysqli_query($conn, $sql);
if (!$result) {
    api_error('Konnte Tische nicht abfragen: ' . mysqli_error($conn), 'db_error', 500);
}

$bereichLabels = [
    'H' => 'Halle',
    'Z' => 'Zelt',
    'A' => 'Außenbereich',
];

$tables = [];
$bereicheFound = [];

while ($row = mysqli_fetch_assoc($result)) {
    $tischName = (string)($row['tischname'] ?: 'Tisch ' . $row['tischnummer']);
    $initial = strtoupper(substr(trim($tischName), 0, 1));
    if ($initial !== '') {
        $bName = $bereichLabels[$initial] ?? ('Bereich ' . $initial);
        $bereicheFound[$initial] = $bName;
    }

    $isEhren = (int)$row['is_ehrengast'] === 1;
    $isSammel = (int)$row['is_sammelrechnung'] === 1;
    $openCount = (int)$row['open_items_count'];
    $unsentCount = (int)$row['unsent_items_count'];
    $hasOpen = $openCount > 0;

    // Farb-Berechnung exakt wie im bestehenden System (list_tische.php)
    if ($isEhren) {
        $bgColor = $hasOpen ? '#fae8ff' : '#ede9fe';
    } elseif ($isSammel) {
        $bgColor = $hasOpen ? '#fef3c7' : '#dbeafe';
    } elseif ($hasOpen) {
        $bgColor = '#F5F599';
    } else {
        $bgColor = '#90ee90'; // LightGreen
    }

    $rawColor = trim((string)($row['color'] ?? ''));
    if ($rawColor !== '' && $rawColor[0] !== '#') {
        $fontColor = '#' . $rawColor;
    } elseif ($rawColor !== '') {
        $fontColor = $rawColor;
    } else {
        $fontColor = '#000000';
    }

    $tables[] = [
        'tischnummer' => (int)$row['tischnummer'],
        'tischname' => $tischName,
        'color' => $rawColor,
        'x' => (int)$row['x'],
        'y' => (int)$row['y'],
        'bereich' => $initial,
        'bg_color' => $bgColor,
        'text_color' => $fontColor,
        'is_sammelrechnung' => $isSammel,
        'is_ehrengast' => $isEhren,
        'open_items_count' => $openCount,
        'my_open_items_count' => (int)$row['my_open_items_count'],
        'ready_items_count' => (int)$row['ready_items_count'],
        'unsent_items_count' => $unsentCount,
        'has_unsent' => $unsentCount > 0,
    ];
}
mysqli_free_result($result);

// Bereiche formatieren (Reihenfolge H, Z, A, sonst alphabetisch)
$orderedBereiche = [];
foreach (['H', 'Z', 'A'] as $code) {
    if (isset($bereicheFound[$code])) {
        $orderedBereiche[] = ['id' => $code, 'name' => $bereicheFound[$code]];
        unset($bereicheFound[$code]);
    }
}
foreach ($bereicheFound as $code => $label) {
    $orderedBereiche[] = ['id' => $code, 'name' => $label];
}

api_response([
    'ok' => true,
    'fest' => $activeFest,
    'wait_times' => $waitTimes,
    'pay_cash_modal' => $payCashModal,
    'sumup' => [
        'enabled' => $sumupEnabled && !empty($sumupAffiliateKey),
        'affiliate_key' => $sumupAffiliateKey,
        'app_id' => $sumupAppId,
    ],
    'bereiche' => $orderedBereiche,
    'tables' => $tables,
]);
