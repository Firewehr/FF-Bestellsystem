<?php
/**
 * Leichtgewicht: offene Positionen + letzte Änderung (Vorab-Check vor Küche/Schank-Reload).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_kueche_list_data.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $k = 0;
    $s = 0;
    $open = 0;
    $last = null;
    $stationRev = null;
    $printTarget = isset($_GET['print_target']) ? (int) $_GET['print_target'] : 0;

    if ($printTarget > 0) {
        $open = ff_druckziel_waiting_count($conn, $printTarget);
        $stationRev = ff_druckziel_station_revision($conn, $printTarget);
    } else {
        $qk = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM bestellungen b INNER JOIN positionen p ON b.position = p.rowid '
            . 'WHERE p.type = 1 AND b.delete = 0 AND b.kueche = 0');
        if ($qk && ($rk = mysqli_fetch_assoc($qk))) {
            $k = (int) $rk['c'];
        }

        $qs = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM bestellungen b INNER JOIN positionen p ON b.position = p.rowid '
            . 'WHERE p.type = 2 AND b.delete = 0 AND b.kueche = 0');
        if ($qs && ($rs = mysqli_fetch_assoc($qs))) {
            $s = (int) $rs['c'];
        }
    }

    $ql = mysqli_query($conn, 'SELECT MAX(zeitstempel) AS m FROM bestellungen');
    if ($ql && ($rl = mysqli_fetch_assoc($ql)) && !empty($rl['m'])) {
        $last = $rl['m'];
    }

    // Höchste vergebene Bestellnummer: ändert sich bei jeder neuen abgeschickten Runde
    // und erzwingt damit einen vollständigen Reload der Stationsansicht (neue Runde sofort sichtbar).
    $lastOrder = 0;
    $qo = mysqli_query($conn, 'SELECT MAX(order_nr) AS o FROM bestellungen WHERE `delete` = 0');
    if ($qo && ($ro = mysqli_fetch_assoc($qo)) && $ro['o'] !== null) {
        $lastOrder = (int) $ro['o'];
    }

    $payload = [
        'kueche_open' => $k,
        'schank_open' => $s,
        'open' => $printTarget > 0 ? $open : 0,
        'print_target' => $printTarget > 0 ? $printTarget : null,
        'last' => $last,
        'last_order' => $lastOrder,
    ];
    if ($stationRev !== null) {
        $payload['station_rev'] = $stationRev;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['kueche_open' => 0, 'schank_open' => 0, 'open' => 0, 'last' => null, 'last_order' => 0], JSON_UNESCAPED_UNICODE);
}
