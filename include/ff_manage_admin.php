<?php
/**
 * Aufrufe unter manage/: nur für angemeldete Benutzer mit Administratorrecht (admin >= 1).
 * Lädt auth.php (Session + Login-Pflicht).
 */
require_once __DIR__ . '/../auth.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Zugriff verweigert. Administratorrechte (admin) erforderlich.';
    exit;
}
