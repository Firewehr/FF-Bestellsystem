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

    ff_mysqli_query($conn, "UPDATE settings SET v = LAST_INSERT_ID(v + 1) WHERE k = 'bon_nr_seq'");
    $nr = (int)mysqli_insert_id($conn);

    if ($nr <= 0) {
        setting_set($conn, 'bon_nr_seq', (string)$start);
        $nr = $start;
    }

    return $nr;
}


/**
 * Vergibt jedem Tisch-Bon im Payload eine eigene, fortlaufende Bon-Nr.
 *
 * Ein Payload kann mehrere Tische enthalten; der Druck-Client druckt pro Tisch
 * einen eigenen Bon. Früher teilten sich alle Tische einer Antwort dieselbe Nummer.
 * `$payload['bon_nr']` bleibt für ältere Clients erhalten (= Nummer des ersten Bons).
 *
 * @param array<string,mixed> $payload wird um tische[i]['bon_nr'] und bon_nr ergänzt
 * @return int erste vergebene Bon-Nr.
 */
function ff_bon_nr_assign_tische(mysqli $conn, array &$payload): int
{
    $first = 0;
    if (!empty($payload['tische']) && is_array($payload['tische'])) {
        foreach ($payload['tische'] as $k => $tisch) {
            if (!is_array($tisch)) {
                continue;
            }
            $nr = ff_next_bon_nr($conn);
            $payload['tische'][$k]['bon_nr'] = $nr;
            if ($first === 0) {
                $first = $nr;
            }
        }
    }
    if ($first === 0) {
        $first = ff_next_bon_nr($conn);
    }
    $payload['bon_nr'] = $first;
    return $first;
}

