<?php
require_once('auth.php');
require_once 'include/db.php';
require_once 'include/settings.php';
require_once __DIR__ . '/include/ff_rechnung_seq.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$bon_nr_start = max(1, (int)($_POST['bon_nr_start'] ?? 1));
$bon_nr_seq   = max(0, (int)($_POST['bon_nr_seq'] ?? 0));
$order_nr_seq = max(0, (int)($_POST['order_nr_seq'] ?? 0));

$prefix = trim((string)($_POST['rechnung_prefix'] ?? 'R'));
if ($prefix === '') {
    $prefix = 'R';
}
$prefix = mb_substr($prefix, 0, 16, 'UTF-8');
$rechnung_next_seq = (int)($_POST['rechnung_next_seq'] ?? 1);
if ($rechnung_next_seq < 1) {
    $rechnung_next_seq = 1;
}

settings_set($conn, 'bon_nr_start', (string)$bon_nr_start);
settings_set($conn, 'bon_nr_seq', (string)$bon_nr_seq);
settings_set($conn, 'order_nr_seq', (string)$order_nr_seq);
settings_set($conn, 'rechnung_prefix', $prefix);
settings_set($conn, FF_RECHNUNG_NEXT_KEY, (string)$rechnung_next_seq);

echo 'ok';
