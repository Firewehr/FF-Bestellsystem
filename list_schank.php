<?php
/** Legacy-URL: gleiche Ansicht wie Druckziel Schank (12). */
$_GET['print_target'] = 12;
if (!isset($_GET['name']) || trim((string) $_GET['name']) === '') {
    $_GET['name'] = 'Schank';
}
require_once __DIR__ . '/list_druckziel.php';
