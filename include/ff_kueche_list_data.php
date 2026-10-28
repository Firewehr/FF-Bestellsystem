<?php
/**
 * Küche/Schank: offene Zeilen laden und in Bestellungsrunden gruppieren (PHP statt fehleranfälligem GROUP BY batch_key).
 */

function ff_kueche_zeit_noch_offen(?string $zeitKueche): bool
{
    $z = trim((string) $zeitKueche);

    return $z === '' || $z === '0000-00-00 00:00:00' || $z === '1970-01-01 00:00:00';
}

/**
 * @return array<int, array<string, mixed>>
 */
function ff_kueche_load_station_lines(mysqli $conn, int $positionType = 1, int $printTarget = 0): array
{
    require_once __DIR__ . '/bestellung_batch_key_sql.php';
    require_once __DIR__ . '/ff_position_kassa_helpers.php';
    require_once __DIR__ . '/ff_rezepturen_schema.php';
    ff_rezepturen_ensure_schema($conn);
    $batchKeySql = ff_sql_bestellung_batch_key('b');
    $printTarget = (int) $printTarget;
    $whereScope = 'b.`delete` = 0 AND b.ausgeliefert = 0 ';
    if ($printTarget > 0) {
        $whereScope .= 'AND (COALESCE(b.print_target, p.print_target) = ' . $printTarget . ') ';
        // Druckziel: alle nicht ausgelieferten Zeilen (offen/fertig in Station über zeitKueche in der Anzeige)
    } else {
        $positionType = $positionType === 2 ? 2 : 1;
        $whereScope .= 'AND p.type = ' . $positionType . ' ';
        $whereScope .= "AND (b.kueche = 0 OR (b.kueche = 1 AND b.zeitKueche IS NOT NULL AND b.zeitKueche NOT IN ('0000-00-00 00:00:00', '1970-01-01 00:00:00'))) ";
    }

    $sql = "SELECT b.rowid, b.tischnummer, b.bestellt, b.kellner, b.zeitstempel, b.zeitKueche, "
        . "CONCAT_WS(', ', NULLIF(b.Zusatzinfo, ''), NULLIF((SELECT GROUP_CONCAT("
        . "CASE WHEN rc.selected = 1 THEN CONCAT('extra ', rc.name_snapshot) ELSE CONCAT('ohne ', rc.name_snapshot) END "
        . "ORDER BY rc.bestandteil_id SEPARATOR ', ') "
        . "FROM bestellung_rezeptur_komponenten rc "
        . "WHERE rc.bestellung_rowid = b.rowid AND rc.optional_enabled = 1 "
        . "AND rc.selected <> rc.default_selected), '')) AS Zusatzinfo, "
        . "b.kueche, b.ausgeliefert, b.print, b.print_status, b.order_nr, b.bon_id, b.position, b.timestampBezahlung, "
        . "p.Positionsname, "
        . "{$batchKeySql} AS batch_key, "
        . "COALESCE(t.tischname, IF(b.tischnummer = 999999, 'Direktverkauf', '')) AS tischname "
        . "FROM bestellungen b "
        . "JOIN positionen p ON b.position = p.rowid "
        . "LEFT JOIN position_subcategories s ON s.id = p.subcategory_id "
        . "LEFT JOIN tische t ON t.tischnummer = b.tischnummer "
        . 'WHERE ' . $whereScope
        . ff_position_sql_station_visible('p', 's')
        . 'ORDER BY b.zeitstempel ASC';

    $res = mysqli_query($conn, $sql);
    if ($res === false) {
        return ['__error' => mysqli_error($conn)];
    }

    $lines = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $lines[] = $row;
    }

    return $lines;
}

/**
 * @param array<int, array<string, mixed>> $lines
 * @return list<array<string, mixed>>
 */
function ff_station_waiting_count_by_type(mysqli $conn, int $positionType): int
{
    require_once __DIR__ . '/ff_position_kassa_helpers.php';
    $positionType = $positionType === 2 ? 2 : 1;
    $res = mysqli_query(
        $conn,
        'SELECT COUNT(*) AS anzahl FROM bestellungen b JOIN positionen p ON b.position = p.rowid '
        . 'WHERE p.type = ' . $positionType . ' AND b.`delete` = 0 AND b.kueche = 0'
        . ff_position_sql_station_visible('p')
    );
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        return (int) ($row['anzahl'] ?? 0);
    }

    return 0;
}

function ff_druckziel_waiting_count(mysqli $conn, int $printTarget): int
{
    require_once __DIR__ . '/ff_position_kassa_helpers.php';
    $printTarget = (int) $printTarget;
    if ($printTarget <= 0) {
        return 0;
    }
    $res = mysqli_query(
        $conn,
        'SELECT COUNT(*) AS anzahl FROM bestellungen b JOIN positionen p ON b.position = p.rowid '
        . 'LEFT JOIN position_subcategories s ON s.id = p.subcategory_id '
        . 'WHERE b.`delete` = 0 AND b.ausgeliefert = 0 '
        . 'AND (COALESCE(b.print_target, p.print_target) = ' . $printTarget . ')'
        . ff_position_sql_station_visible('p', 's')
    );
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        return (int) ($row['anzahl'] ?? 0);
    }

    return 0;
}

/**
 * Änderungs-Token für Druckziel-Polling (Farbe DV, Fertig, Bezahlung, Druckstatus, Zusatzinfos).
 */
function ff_druckziel_station_revision(mysqli $conn, int $printTarget): string
{
    require_once __DIR__ . '/ff_position_kassa_helpers.php';
    require_once __DIR__ . '/ff_rezepturen_schema.php';
    ff_rezepturen_ensure_schema($conn);
    $pt = (int) $printTarget;
    if ($pt <= 0) {
        return '0';
    }
    $res = mysqli_query(
        $conn,
        'SELECT COUNT(*) AS total, '
        . 'SUM(CASE WHEN b.tischnummer = 999999 AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung IN (\'0000-00-00 00:00:00\', \'1970-01-01 00:00:00\')) THEN 1 ELSE 0 END) AS dv_unpaid, '
        . 'SUM(CASE WHEN b.kueche = 0 OR b.zeitKueche IS NULL OR b.zeitKueche IN (\'0000-00-00 00:00:00\', \'1970-01-01 00:00:00\') THEN 1 ELSE 0 END) AS open_lines, '
        . 'COALESCE(MAX(b.timestampBezahlung), \'\') AS last_bez, '
        . 'COALESCE(MAX(b.zeitKueche), \'\') AS last_kueche, '
        . 'COALESCE(MAX(b.timestampAuslieferung), \'\') AS last_aus, '
        . 'COALESCE(BIT_XOR(CRC32(CONCAT(b.rowid, \'|\', COALESCE(b.Zusatzinfo, \'\'), \'|\', COALESCE(b.print_status, 0), \'|\', '
        . 'COALESCE((SELECT GROUP_CONCAT(CONCAT(rc.bestandteil_id, \':\', rc.selected) ORDER BY rc.bestandteil_id SEPARATOR \',\') '
        . 'FROM bestellung_rezeptur_komponenten rc WHERE rc.bestellung_rowid = b.rowid), \'\')))), 0) AS zi_fingerprint '
        . 'FROM bestellungen b JOIN positionen p ON b.position = p.rowid '
        . 'LEFT JOIN position_subcategories s ON s.id = p.subcategory_id '
        . 'WHERE b.`delete` = 0 AND b.ausgeliefert = 0 '
        . 'AND (COALESCE(b.print_target, p.print_target) = ' . $pt . ')'
        . ff_position_sql_station_visible('p', 's')
    );
    if (!$res || !($row = mysqli_fetch_assoc($res))) {
        return '0';
    }

    return implode(':', [
        (int) ($row['total'] ?? 0),
        (int) ($row['dv_unpaid'] ?? 0),
        (int) ($row['open_lines'] ?? 0),
        (string) ($row['last_bez'] ?? ''),
        (string) ($row['last_kueche'] ?? ''),
        (string) ($row['last_aus'] ?? ''),
        (string) ($row['zi_fingerprint'] ?? '0'),
    ]);
}

/** Bezahlt (Direktverkauf: grüner Streifen am Druckziel). */
function ff_bestellung_line_is_paid(array $line): bool
{
    $tb = trim((string) ($line['timestampBezahlung'] ?? ''));

    return $tb !== '' && $tb !== '0000-00-00 00:00:00' && $tb !== '1970-01-01 00:00:00';
}

/**
 * Bestellungsrunden gruppieren.
 *
 * @param 'all'|'kueche_open' $visible all = jede Runde mit Zeilen; kueche_open = mind. eine Zeile mit kueche=0
 */
function ff_station_group_batches(array $lines, string $visible = 'all'): array
{
    $map = [];
    foreach ($lines as $line) {
        if (isset($line['__error'])) {
            continue;
        }
        $tn = (int) ($line['tischnummer'] ?? 0);
        $bk = (string) ($line['batch_key'] ?? '');
        $key = $tn . "\x1e" . $bk;
        if (!isset($map[$key])) {
            $map[$key] = [
                'tischnummer' => $tn,
                'batch_key' => $bk,
                'bestellt' => 0,
                'kellner' => (string) ($line['kellner'] ?? ''),
                'tischname' => (string) ($line['tischname'] ?? ''),
                'order_nrs' => [],
                'lines' => [],
                'min_ts' => (string) ($line['zeitstempel'] ?? ''),
            ];
        }
        $g = &$map[$key];
        $g['bestellt'] = max($g['bestellt'], (int) ($line['bestellt'] ?? 0));
        if ($g['kellner'] === '' && !empty($line['kellner'])) {
            $g['kellner'] = (string) $line['kellner'];
        }
        $on = (int) ($line['order_nr'] ?? 0);
        if ($on > 0) {
            $g['order_nrs'][$on] = true;
        }
        $g['lines'][] = $line;
        $ts = (string) ($line['zeitstempel'] ?? '');
        if ($ts !== '' && ($g['min_ts'] === '' || $ts < $g['min_ts'])) {
            $g['min_ts'] = $ts;
        }
        unset($g);
    }

    if ($visible === 'kueche_open') {
        $batches = [];
        foreach ($map as $g) {
            foreach ($g['lines'] as $ln) {
                if ((int) ($ln['kueche'] ?? 0) === 0) {
                    $batches[] = $g;
                    break;
                }
            }
        }
    } else {
        $batches = array_values($map);
    }

    usort($batches, static function ($a, $b) {
        return strcmp((string) $a['min_ts'], (string) $b['min_ts']);
    });

    require_once __DIR__ . '/ff_table_display.php';
    foreach ($batches as &$batch) {
        if ((int) ($batch['tischnummer'] ?? 0) !== FF_TISCH_DIREKTVERKAUF) {
            continue;
        }
        $allPaid = true;
        foreach ($batch['lines'] ?? [] as $ln) {
            if (!ff_bestellung_line_is_paid($ln)) {
                $allPaid = false;
                break;
            }
        }
        // DV: grün = bezahlt (wie Tisch nach „Bestellung abschicken“), rot = noch offen/unbezahlt.
        $batch['bestellt'] = $allPaid ? 1 : 0;
    }
    unset($batch);

    return $batches;
}

/** @deprecated Nutze ff_station_group_batches($lines, 'all') */
function ff_druckziel_group_batches(array $lines): array
{
    return ff_station_group_batches($lines, 'all');
}

/** @deprecated Nutze ff_station_group_batches($lines, 'kueche_open') */
function ff_kueche_group_batches(array $lines): array
{
    return ff_station_group_batches($lines, 'kueche_open');
}

/**
 * @param array<int, array<string, mixed>> $batchLines
 * @return array{
 *   open: list<array<string, mixed>>,
 *   array_liste: list<int>,
 *   rowids_all: list<int>,
 *   done: list<array<string, mixed>>,
 *   print_rowids: list<int>
 * }
 */
function ff_kueche_batch_derive(array $batchLines, bool $druckzielMode = false): array
{
    $openMap = [];
    $arrayListe = [];
    $rowidsAll = [];
    $doneMap = [];
    $printRowids = [];

    foreach ($batchLines as $ln) {
        $rid = (int) ($ln['rowid'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        $rowidsAll[] = $rid;

        $print = (int) ($ln['print'] ?? 0);
        $isOpenLine = $druckzielMode
            ? ff_kueche_zeit_noch_offen($ln['zeitKueche'] ?? null)
            : ((int) ($ln['kueche'] ?? 0) === 0);

        if ($isOpenLine) {
            if ($print !== 1) {
                $arrayListe[] = $rid;
            }
            $zi = trim((string) ($ln['Zusatzinfo'] ?? ''));
            $pname = (string) ($ln['Positionsname'] ?? '');
            $aggKey = $pname . "\x1e" . $zi;
            if (!isset($openMap[$aggKey])) {
                $openMap[$aggKey] = [
                    'anzahl' => 0,
                    'rowid' => $rid,
                    'bestellt' => (int) ($ln['bestellt'] ?? 0),
                    'Positionsname' => $pname,
                    'Zusatzinfo' => $zi,
                    'zeitstempel' => (string) ($ln['zeitstempel'] ?? ''),
                ];
            }
            $openMap[$aggKey]['anzahl']++;
            if ((int) ($ln['tischnummer'] ?? 0) === 999999) {
                if (ff_bestellung_line_is_paid($ln)) {
                    $openMap[$aggKey]['bestellt'] = 1;
                }
            } elseif ((int) ($ln['bestellt'] ?? 0) === 1) {
                $openMap[$aggKey]['bestellt'] = 1;
            }
        }

        $isDoneLine = $druckzielMode
            ? !ff_kueche_zeit_noch_offen($ln['zeitKueche'] ?? null)
            : ((int) ($ln['kueche'] ?? 0) === 1 && !ff_kueche_zeit_noch_offen($ln['zeitKueche'] ?? null));
        if ($isDoneLine) {
            $zi = trim((string) ($ln['Zusatzinfo'] ?? ''));
            $pname = (string) ($ln['Positionsname'] ?? '');
            $pos = (int) ($ln['position'] ?? 0);
            $aggKey = $pos . "\x1e" . $zi;
            if (!isset($doneMap[$aggKey])) {
                $doneMap[$aggKey] = [
                    'anzahl' => 0,
                    'rowid' => $rid,
                    'undo_rowids' => [],
                    'kueche' => 1,
                    'Positionsname' => $pname,
                    'Zusatzinfo' => $zi,
                    'zeitstempel' => (string) ($ln['zeitstempel'] ?? ''),
                ];
            }
            $doneMap[$aggKey]['anzahl']++;
            $doneMap[$aggKey]['undo_rowids'][] = $rid;
            if ((int) ($ln['print'] ?? 0) === 2 && (int) ($ln['print_status'] ?? 0) === 0) {
                $printRowids[] = $rid;
            }
        }
    }

    $open = array_values($openMap);
    usort($open, static function ($a, $b) {
        return strcmp((string) $a['Positionsname'], (string) $b['Positionsname']);
    });

    $done = array_values($doneMap);
    foreach ($done as &$d) {
        $d['undo_rowids'] = implode(',', $d['undo_rowids']);
    }
    unset($d);

    return [
        'open' => $open,
        'array_liste' => $arrayListe,
        'rowids_all' => $rowidsAll,
        'done' => $done,
        'print_rowids' => $printRowids,
    ];
}

/**
 * Zusammenfassung für Sidebar / Kopfzeile: Artikel → Anzahl (kueche=0, nicht ausgeliefert).
 *
 * @param array<int, array<string, mixed>> $lines
 * @return list<array{Positionsname: string, anzahl: int}>
 */
function ff_kueche_position_summary(array $lines): array
{
    $sum = [];
    foreach ($lines as $line) {
        if (isset($line['__error'])) {
            continue;
        }
        if ((int) ($line['kueche'] ?? 0) !== 0) {
            continue;
        }
        $pname = (string) ($line['Positionsname'] ?? '');
        if ($pname === '') {
            continue;
        }
        if (!isset($sum[$pname])) {
            $sum[$pname] = 0;
        }
        $sum[$pname]++;
    }
    arsort($sum);
    $out = [];
    foreach ($sum as $name => $cnt) {
        $out[] = ['Positionsname' => $name, 'anzahl' => (int) $cnt];
    }

    return $out;
}

/**
 * Druckziel-Sidebar: zuerst noch offene Station (zeitKueche leer),
 * sonst Gesamtübersicht aller nicht ausgelieferten Zeilen (gleicher Filter wie Zähler).
 *
 * @return array{items: list<array{Positionsname: string, anzahl: int}>, mode: 'open'|'all'}
 */
function ff_druckziel_position_summary(array $lines): array
{
    $build = static function (array $src, bool $onlyOpenStation): array {
        $sum = [];
        foreach ($src as $line) {
            if (isset($line['__error'])) {
                continue;
            }
            if ($onlyOpenStation && !ff_kueche_zeit_noch_offen($line['zeitKueche'] ?? null)) {
                continue;
            }
            $pname = (string) ($line['Positionsname'] ?? '');
            if ($pname === '') {
                continue;
            }
            if (!isset($sum[$pname])) {
                $sum[$pname] = 0;
            }
            $sum[$pname]++;
        }
        arsort($sum);
        $out = [];
        foreach ($sum as $name => $cnt) {
            $out[] = ['Positionsname' => $name, 'anzahl' => (int) $cnt];
        }

        return $out;
    };

    $open = $build($lines, true);
    if ($open !== []) {
        return ['items' => $open, 'mode' => 'open'];
    }

    return ['items' => $build($lines, false), 'mode' => 'all'];
}
