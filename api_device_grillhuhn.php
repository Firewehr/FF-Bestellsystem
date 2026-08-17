<?php
/**
 * @deprecated Nutze api_device.php?token=…&action=speise_queue&filter=grillhuhn
 *             (token = printer_token, wie beim Druck-Client)
 *
 * Diese Datei leitet nur noch weiter, damit alte URLs weiter funktionieren.
 */
if (!isset($_GET['action'])) {
    $_GET['action'] = 'grillhuhn_queue';
}
if (empty($_GET['token']) && empty($_POST['token'])) {
    $t = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
    if ($t === '' && !empty($_SERVER['HTTP_X_API_KEY'])) {
        $t = trim((string)$_SERVER['HTTP_X_API_KEY']);
    }
    if (is_string($t) && $t !== '') {
        $_GET['token'] = $t;
    }
}
require __DIR__ . '/api_device.php';
