<?php
require_once('auth.php');
require_once('include/db.php');
require_once __DIR__ . '/include/ff_finance_auth.php';
require_once __DIR__ . '/include/ff_finance_schema.php';

header('Content-Type: application/json; charset=utf-8');

ff_finance_require($conn);
ff_finance_ensure_schema($conn);

$typ = isset($_POST['typ']) && in_array($_POST['typ'], ['einnahme', 'ausgabe']) ? $_POST['typ'] : '';
$bezeichnung = trim($_POST['bezeichnung'] ?? '');
$betrag = isset($_POST['betrag']) ? (float)str_replace([',', ' '], ['.', ''], $_POST['betrag']) : 0;
$datum = !empty($_POST['datum']) ? mysqli_real_escape_string($conn, $_POST['datum']) : null;
$kategorie = trim($_POST['kategorie'] ?? '') ?: null;
$notiz = trim($_POST['notiz'] ?? '') ?: null;
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$bereichId = isset($_POST['bereich_id']) ? (int)$_POST['bereich_id'] : 0;
$bereichId = $bereichId > 0 ? $bereichId : null;

if ($typ === '' || $bezeichnung === '') {
    echo json_encode(['ok' => false, 'error' => 'Typ und Bezeichnung erforderlich']);
    exit;
}

$created_by = $_SESSION['user']['username'] ?? '';

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS buchungen (
    id INT(11) NOT NULL AUTO_INCREMENT,
    typ ENUM('einnahme','ausgabe') NOT NULL,
    bezeichnung VARCHAR(255) NOT NULL,
    betrag DECIMAL(10,2) NOT NULL,
    datum DATE NULL,
    kategorie VARCHAR(100) NULL,
    notiz TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(64) NULL,
    PRIMARY KEY (id), KEY idx_typ (typ), KEY idx_datum (datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($id > 0) {
    $stmt = mysqli_prepare($conn, 'UPDATE buchungen SET typ=?, bezeichnung=?, betrag=?, datum=?, kategorie=?, notiz=?, bereich_id=? WHERE id=?');
    $dt = $datum ?: null;
    mysqli_stmt_bind_param($stmt, 'ssdsssii', $typ, $bezeichnung, $betrag, $dt, $kategorie, $notiz, $bereichId, $id);
} else {
    $stmt = mysqli_prepare($conn, 'INSERT INTO buchungen (typ, bezeichnung, betrag, datum, kategorie, notiz, bereich_id, created_by) VALUES (?,?,?,?,?,?,?,?)');
    $dt = $datum ?: null;
    mysqli_stmt_bind_param($stmt, 'ssdsssis', $typ, $bezeichnung, $betrag, $dt, $kategorie, $notiz, $bereichId, $created_by);
}

if (mysqli_stmt_execute($stmt)) {
    echo json_encode(['ok' => true, 'id' => $id ?: mysqli_insert_id($conn)]);
} else {
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)]);
}
