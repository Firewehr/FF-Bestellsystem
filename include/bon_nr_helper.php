<?php
/**
 * Fortlaufende Bon-Nr. vergeben.
 * Jeder gedruckte Bon (Browser oder Thermo) bekommt die nächste Nummer.
 * Startwert ist über Admin-Setting 'bon_nr_start' einstellbar.
 */
require_once __DIR__ . '/settings.php';

function ff_next_bon_nr(mysqli $conn): int
{
    $start = (int)setting_get($conn, 'bon_nr_start', '1');
    if ($start < 1) $start = 1;

    $current = (int)setting_get($conn, 'bon_nr_seq', '0');
    if ($current < $start) {
        setting_set($conn, 'bon_nr_seq', (string)($start - 1));
    }

    mysqli_query($conn, "UPDATE settings SET v = LAST_INSERT_ID(v + 1) WHERE k = 'bon_nr_seq'");
    $nr = (int)mysqli_insert_id($conn);

    if ($nr <= 0) {
        setting_set($conn, 'bon_nr_seq', (string)$start);
        $nr = $start;
    }

    return $nr;
}
