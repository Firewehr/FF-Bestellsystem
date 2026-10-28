<?php
/**
 * Baut die gleiche JSON-Struktur wie print_target.php, aber nur für angegebene bestellungen.rowid.
 * @return array{0: array<string,mixed>, 1: list<int>}|null  [ $outputTopLevel, $bestellungIds ] oder null
 */
require_once __DIR__ . '/user_landing.php';
require_once __DIR__ . '/ff_table_display.php';

function ff_kueche_thermal_build_tische_for_rowids(mysqli $conn, int $print_target, array $rowids, bool $ignoreState = false): ?array
{
    ff_users_ensure_landing_columns($conn);
    ff_ensure_direktverkauf_tisch_row($conn);
    $rowids = array_values(array_unique(array_filter(array_map('intval', $rowids), static function ($n) {
        return $n > 0;
    })));
    if ($rowids === []) {
        return null;
    }
    $in = implode(',', $rowids);

    // Für Nachdruck (Bon verloren): Druck-Status-Filter ignorieren, damit auch
    // bereits gedruckte Zeilen erneut erzeugt werden können.
    $stateFilter = $ignoreState
        ? ''
        : "      AND b.bestellt = 1
      AND b.kueche = 1
      AND b.print = 2
      AND COALESCE(b.print_status, 0) = 0
";

    $sql = "
    SELECT 
        b.rowid AS bestellung_id,
        b.tischnummer,
        b.bon_id,
        b.kellner,
        b.zeitstempel AS bestellt_um,
        b.zeitKueche AS bestaetigt_um,
        b.Zusatzinfo,
        p.rowid AS position_id,
        p.Positionsname,
        p.Kurzbezeichnung,
        p.Betrag,
        COALESCE(b.betrag, p.Betrag) AS betrag_zeile,
        b.order_nr,
        b.rechnung_id,
        r.rechnungsnummer AS rechnungsnummer,
        " . ff_sql_bon_tischname_select('b', 't') . "
    FROM bestellungen b
    JOIN positionen p ON p.rowid = b.position
    LEFT JOIN tische t ON t.tischnummer = b.tischnummer
    LEFT JOIN rechnungen r ON r.id = b.rechnung_id
    WHERE b.`delete` = 0
" . $stateFilter . "      AND COALESCE(b.print_target, p.print_target, 11) = " . (int)$print_target . "
      AND b.rowid IN (" . $in . ")
    ORDER BY b.tischnummer ASC, b.zeitstempel ASC
    ";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return null;
    }

    $tische = [];
    $bestellungIds = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $tnr = (int)$row['tischnummer'];
        $bucketKey = ff_print_target_tisch_bucket_key($tnr, (string)($row['bon_id'] ?? ''));
        if (!isset($tische[$bucketKey])) {
            $tische[$bucketKey] = [
                'tischnummer' => $tnr,
                'tischname' => $row['tischname'],
                'kellner' => ff_user_display_label($conn, (string)($row['kellner'] ?? '')),
                'bestellt_um' => $row['bestellt_um'],
                'order_nrs' => [],
                '_agg' => [],
            ];
        }
        $zkRow = (string)($row['bestaetigt_um'] ?? '');
        if ($zkRow !== '' && $zkRow !== '0000-00-00 00:00:00') {
            $prevF = $tische[$bucketKey]['fertig_um'] ?? null;
            if ($prevF === null || $zkRow > $prevF) {
                $tische[$bucketKey]['fertig_um'] = $zkRow;
            }
        }
        $onr = isset($row['order_nr']) ? (int)$row['order_nr'] : 0;
        if ($onr > 0) {
            $tische[$bucketKey]['order_nrs'][$onr] = true;
        }
        $rnr = trim((string)($row['rechnungsnummer'] ?? ''));
        if ($rnr !== '') {
            $tische[$bucketKey]['rechnungsnummern'][$rnr] = true;
        }

        $zKey = trim((string)($row['Zusatzinfo'] ?? ''));
        $pid = (int)$row['position_id'];
        $aggKey = $pid . "\x1e" . $zKey;

        $zeile = isset($row['betrag_zeile']) && $row['betrag_zeile'] !== null && $row['betrag_zeile'] !== ''
            ? (float)$row['betrag_zeile']
            : (float)$row['Betrag'];

        if (!isset($tische[$bucketKey]['_agg'][$aggKey])) {
            $tische[$bucketKey]['_agg'][$aggKey] = [
                'anzahl' => 0,
                'summe' => 0.0,
                'name' => $row['Positionsname'],
                'kurz' => $row['Kurzbezeichnung'] ?: $row['Positionsname'],
                'zusatzinfo' => $row['Zusatzinfo'],
                'bestaetigt_um' => $row['bestaetigt_um'],
            ];
        }
        $tische[$bucketKey]['_agg'][$aggKey]['anzahl']++;
        $tische[$bucketKey]['_agg'][$aggKey]['summe'] += $zeile;
        $curB = (string)($tische[$bucketKey]['_agg'][$aggKey]['bestaetigt_um'] ?? '');
        $newB = (string)($row['bestaetigt_um'] ?? '');
        if ($newB > $curB) {
            $tische[$bucketKey]['_agg'][$aggKey]['bestaetigt_um'] = $row['bestaetigt_um'];
        }

        $bestellungIds[] = (int)$row['bestellung_id'];
    }

    if ($bestellungIds === []) {
        return null;
    }

    foreach ($tische as $bucketKey => $tischData) {
        $positionen = [];
        foreach ($tischData['_agg'] as $line) {
            $n = max(1, (int)$line['anzahl']);
            $summe = round((float)$line['summe'], 2);
            $einzel = round($summe / $n, 2);
            $positionen[] = [
                'anzahl' => $n,
                'name' => $line['name'],
                'kurz' => $line['kurz'],
                'einzelpreis' => $einzel,
                'gesamtpreis' => $summe,
                'betrag' => $summe,
                'zusatzinfo' => $line['zusatzinfo'],
                'bestaetigt_um' => $line['bestaetigt_um'],
            ];
        }
        $tische[$bucketKey]['positionen'] = $positionen;
        $onrs = array_keys($tische[$bucketKey]['order_nrs'] ?? []);
        sort($onrs, SORT_NUMERIC);
        $tische[$bucketKey]['order_nrs'] = $onrs;
        $rnrs = array_keys($tische[$bucketKey]['rechnungsnummern'] ?? []);
        sort($rnrs);
        $tische[$bucketKey]['rechnungsnummern'] = $rnrs;
        unset($tische[$bucketKey]['_agg']);
    }

    $payload = [
        'tische' => array_values($tische),
        'print_target' => $print_target,
    ];

    return [$payload, $bestellungIds];
}
