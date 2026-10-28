<?php
/**
 * Abwärtskompatibilität: Funktionen liegen in db.php (wird ohnehin fast überall geladen).
 * Falls noch ein require auf diese Datei existiert, reicht ein include.
 */
require_once __DIR__ . '/db.php';
