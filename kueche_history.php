<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_station_history.php';

mysqli_set_charset($conn, 'utf8mb4');

ff_station_history_render(
    $conn,
    ' AND positionen.type = 1 ',
    'Küche – Historie',
    ff_nav_home_onclick(),
    'Neueste Speisen-Bestellungen zuerst, nach Bestellung gruppiert.'
);
