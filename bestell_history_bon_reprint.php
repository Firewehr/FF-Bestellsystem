<?php
/**
 * Bon nachdrucken (verlorener Bon) aus der Bestell-History.
 * Reiht den Kellner-Bon der gesamten Bestellung (über rowid ermittelt) erneut in
 * printer_jobs ein – getrennt nach Druckziel der Positionen. Ignoriert den
 * Druck-Status, damit auch bereits gedruckte Bons reproduziert werden können.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/kueche_thermal_lib.php';
require_once __DIR__ . '/include/bon_nr_helper.php';
require_once __DIR__ . '/include/ff_user_permissions.php';
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';

if (empty($_SESSION['login'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

$rowid = (int) ($_POST['rowid'] ?? $_GET['rowid'] ?? 0);
if ($rowid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'rowid_fehlt'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Bestellung (order_nr / bon_id / tischnummer) der Zeile ermitteln.
$st = mysqli_prepare($conn, 'SELECT tischnummer, order_nr, bon_id FROM bestellungen WHERE rowid = ? LIMIT 1');
if (!$st) {
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($st, 'i', $rowid);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$base = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($st);

if (!$base) {
    echo json_encode(['ok' => false, 'error' => 'nicht_gefunden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tischnummer = (int) ($base['tischnummer'] ?? 0);
$orderNr = (int) ($base['order_nr'] ?? 0);
$bonId = trim((string) ($base['bon_id'] ?? ''));

// Alle rowids der gesamten Bestellung sammeln.
$where = 'tischnummer = ' . $tischnummer . ' AND `delete` = 0';
if ($tischnummer === 999999 && $bonId !== '') {
    $where .= " AND bon_id = '" . mysqli_real_escape_string($conn, $bonId) . "'";
} elseif ($orderNr > 0) {
    $where .= ' AND order_nr = ' . $orderNr;
} else {
    $where = 'rowid = ' . $rowid;
}

$rowids = [];
$rq = mysqli_query($conn, "SELECT rowid FROM bestellungen WHERE {$where}");
if ($rq) {
    while ($r = mysqli_fetch_assoc($rq)) {
        $id = (int) $r['rowid'];
        if ($id > 0) {
            $rowids[] = $id;
        }
    }
}
$rowids = array_values(array_unique($rowids));
if ($rowids === []) {
    echo json_encode(['ok' => false, 'error' => 'keine_zeilen'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Druckziele der beteiligten Positionen bestimmen.
$in = implode(',', array_map('intval', $rowids));
$targets = [];
$tq = mysqli_query(
    $conn,
    "SELECT DISTINCT COALESCE(b.print_target, p.print_target, 11) AS pt
       FROM bestellungen b JOIN positionen p ON p.rowid = b.position
      WHERE b.rowid IN ({$in})"
);
if ($tq) {
    while ($r = mysqli_fetch_assoc($tq)) {
        $pt = (int) $r['pt'];
        if ($pt > 0) {
            $targets[] = $pt;
        }
    }
}
if ($targets === []) {
    $targets = [11];
}
$targets = array_values(array_unique(array_map('intval', $targets)));

// Zugriff je Ziel:
// - Admin: alle Ziele
// - Direktverkauf-Bon (999999): Nutzer mit Direktverkauf-Recht dürfen den Bon nachdrucken
// - Sonst: nur Druckziele, die dem Nutzer zugewiesen sind
$isAdmin = !empty($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1;
$canDirekt = ff_direktverkauf_user_can($conn);
$allowedTargets = [];
foreach ($targets as $pt) {
    if ($pt <= 0) {
        continue;
    }
    if ($isAdmin) {
        $allowedTargets[] = $pt;
        continue;
    }
    if ($tischnummer === 999999 && $canDirekt) {
        $allowedTargets[] = $pt;
        continue;
    }
    if (ff_user_can_print_target($conn, $pt)) {
        $allowedTargets[] = $pt;
    }
}
$allowedTargets = array_values(array_unique($allowedTargets));
if ($allowedTargets === []) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'Kein Nachdruck-Recht für dieses Druckziel.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$enqueued = 0;
$errors = [];
foreach ($allowedTargets as $pt) {
    $built = ff_kueche_thermal_build_tische_for_rowids($conn, $pt, $rowids, true);
    if ($built === null) {
        continue;
    }
    [$payload, $bestellungIds] = $built;
    $payload['bon_nr'] = ff_next_bon_nr($conn);
    $payload['reprint'] = true;

    $printerKey = 'target_' . $pt;
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        $errors[] = 'payload_' . $pt;
        continue;
    }
    $metaJson = json_encode(['rowids' => array_values(array_unique($bestellungIds)), 'reprint' => true], JSON_UNESCAPED_UNICODE);
    $escPayload = mysqli_real_escape_string($conn, $payloadJson);
    $escMeta = mysqli_real_escape_string($conn, (string) $metaJson);
    $ins = "INSERT INTO printer_jobs (printer, type, payload, meta, status, attempts, reserved_at, reserved_by, created_at)
            VALUES ('" . mysqli_real_escape_string($conn, $printerKey) . "', 'kellner_bon', '" . $escPayload . "', '" . $escMeta . "', 'pending', 0, NULL, NULL, NOW())";
    if (mysqli_query($conn, $ins)) {
        $enqueued++;
    } else {
        $errors[] = mysqli_error($conn);
    }
}

if ($enqueued < 1) {
    echo json_encode(['ok' => false, 'error' => 'kein_bon', 'details' => $errors], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Bon-Nachdruck in Warteschlange (' . $enqueued . ' Druckziel(e)).',
    'targets' => $allowedTargets,
], JSON_UNESCAPED_UNICODE);
