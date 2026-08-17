<?php
/**
 * Logo-Upload für Rechnungen
 */
require_once('auth.php');
require_once('include/db.php');
require_once('include/settings.php');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung']);
    exit;
}

$logo_dir = __DIR__ . '/uploads';
$logo_path = 'uploads/rechnung_logo.png';

// Logo löschen
if (isset($_POST['delete']) && $_POST['delete']) {
    $current_logo = setting_get($conn, 'rechnung_logo', '');
    if ($current_logo && file_exists($current_logo)) {
        @unlink($current_logo);
    }
    setting_set($conn, 'rechnung_logo', '');
    echo json_encode(['ok' => true, 'message' => 'Logo gelöscht']);
    exit;
}

// Logo hochladen
if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Keine Datei oder Upload-Fehler']);
    exit;
}

$file = $_FILES['logo'];

// Typ prüfen
$allowed_types = ['image/png', 'image/jpeg', 'image/gif'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

if (!in_array($mime, $allowed_types)) {
    echo json_encode(['ok' => false, 'error' => 'Nur PNG, JPG oder GIF erlaubt']);
    exit;
}

// Größe prüfen (max 2 MB)
if ($file['size'] > 2 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'Datei zu groß (max. 2 MB)']);
    exit;
}

// Verzeichnis erstellen falls nicht vorhanden
if (!is_dir($logo_dir)) {
    if (!@mkdir($logo_dir, 0755, true)) {
        echo json_encode(['ok' => false, 'error' => 'Konnte Upload-Verzeichnis nicht erstellen']);
        exit;
    }
}

// Dateiendung bestimmen
$ext = 'png';
if ($mime === 'image/jpeg') $ext = 'jpg';
if ($mime === 'image/gif') $ext = 'gif';

$logo_path = 'uploads/rechnung_logo.' . $ext;
$full_path = __DIR__ . '/' . $logo_path;

// Alte Logos löschen
foreach (['png', 'jpg', 'gif'] as $old_ext) {
    $old_file = __DIR__ . '/uploads/rechnung_logo.' . $old_ext;
    if (file_exists($old_file)) {
        @unlink($old_file);
    }
}

// Datei verschieben
if (!move_uploaded_file($file['tmp_name'], $full_path)) {
    echo json_encode(['ok' => false, 'error' => 'Konnte Datei nicht speichern']);
    exit;
}

// In Settings speichern
setting_set($conn, 'rechnung_logo', $logo_path);

echo json_encode(['ok' => true, 'message' => 'Logo hochgeladen', 'path' => $logo_path]);
