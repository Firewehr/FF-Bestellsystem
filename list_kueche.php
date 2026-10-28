<?php
/** Legacy-URL: gleiche Ansicht wie Druckziel Küche (11). */
$_GET['print_target'] = 11;
if (!isset($_GET['name']) || trim((string) $_GET['name']) === '') {
    $_GET['name'] = 'Küche';
}
require_once __DIR__ . '/list_druckziel.php';
