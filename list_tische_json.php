<?php
/**
 * Gibt alle Tische als JSON zurück (für Tisch-Auswahl beim Verschieben).
 */
require_once('auth.php');
require_once('include/db.php');
header('Content-Type: application/json; charset=utf-8');

$tische = [];
$result = mysqli_query($conn, "SELECT tischnummer, tischname FROM tische ORDER BY tischname, tischnummer");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $tische[] = [
            'tischnummer' => (int)$row['tischnummer'],
            'tischname' => $row['tischname']
        ];
    }
}

echo json_encode(['ok' => true, 'tische' => $tische]);
