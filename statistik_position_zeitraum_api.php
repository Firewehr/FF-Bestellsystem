<?php
/**
 * Statistik: Position nach Zeitraum (JSON).
 * Parameter: von, bis, uhrzeit_von, uhrzeit_bis, position_id (0 = alle), inkl_gast (1/0), inkl_mitarbeiter (1/0), kellner_filter
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    echo json_encode(['error' => 'Keine Berechtigung'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/include/ff_statistik_position_data.php';

$params = [
    'von' => isset($_GET['von']) ? (string) $_GET['von'] : '',
    'bis' => isset($_GET['bis']) ? (string) $_GET['bis'] : '',
    'uhrzeit_von' => isset($_GET['uhrzeit_von']) ? (string) $_GET['uhrzeit_von'] : '',
    'uhrzeit_bis' => isset($_GET['uhrzeit_bis']) ? (string) $_GET['uhrzeit_bis'] : '',
    'position_id' => isset($_GET['position_id']) ? (int) $_GET['position_id'] : 0,
    'inkl_gast' => isset($_GET['inkl_gast']) ? (int) $_GET['inkl_gast'] : 1,
    'inkl_mitarbeiter' => isset($_GET['inkl_mitarbeiter']) ? (int) $_GET['inkl_mitarbeiter'] : 1,
    'kellner_filter' => isset($_GET['kellner_filter']) ? (string) $_GET['kellner_filter'] : '',
];

$d = ff_statistik_position_data($conn, $params);
if (isset($d['error'])) {
    echo json_encode(['error' => $d['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($d, JSON_UNESCAPED_UNICODE);
