<?php
/**
 * Payload für Thermo-Kellner-Bon (printer_jobs), Inhalt wie Abholbon nach Bezahlung.
 *
 * @return array{0: array<string,mixed>, 1: list<int>}|null
 */
require_once __DIR__ . '/user_landing.php';
require_once __DIR__ . '/ff_position_kassa_helpers.php';

/** Druckziel-Name für Thermo-Abholbon (Client: „Positionsname - (Schank)“). */
function ff_thermal_bon_druckziel_label(string $druckzielName): ?string
{
    $dn = trim($druckzielName);

    return $dn !== '' ? $dn : null;
}

function ff_direktverkauf_abholbon_build_thermal_payload(mysqli $conn, string $bonId, string $kellnerFilterSql): ?array
{
    ff_users_ensure_landing_columns($conn);
    $bonId = trim($bonId);
    if ($bonId === '' || strlen($bonId) > 32) {
        return null;
    }

    ff_position_kassa_ensure_schema($conn);
    $sqlAgg = 'SELECT p.Positionsname, p.Kurzbezeichnung, p.Betrag,
            TRIM(COALESCE(b.Zusatzinfo, \'\')) AS zusatzinfo,
            COUNT(*) AS anzahl,
            SUM(COALESCE(NULLIF(b.betrag, 0), p.Betrag)) AS summe,
            COALESCE(pt.name, \'\') AS druckziel_name
        FROM bestellungen b
        JOIN positionen p ON p.rowid = b.position
        LEFT JOIN print_targets pt ON pt.print_target = COALESCE(b.print_target, p.print_target)
        WHERE b.bon_id = ?
          AND b.delete = 0
          AND b.tischnummer = 999999
          AND b.timestampBezahlung IS NOT NULL
          AND b.timestampBezahlung <> \'0000-00-00 00:00:00\'
          AND COALESCE(p.kassa_only, 0) = 0
          ' . $kellnerFilterSql . '
        GROUP BY p.rowid, p.Positionsname, p.Kurzbezeichnung, p.Betrag,
            TRIM(COALESCE(b.Zusatzinfo, \'\')), pt.print_target, pt.name, pt.sort_order
        ORDER BY pt.sort_order, p.Positionsname, zusatzinfo';

    $stmt = mysqli_prepare($conn, $sqlAgg);
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $bonId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $positionen = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $anz = max(1, (int)$row['anzahl']);
        $summe = round((float)$row['summe'], 2);
        $einzel = round($summe / $anz, 2);
        $pname = (string)$row['Positionsname'];
        $kurz = (string)($row['Kurzbezeichnung'] ?: $pname);
        $positionen[] = [
            'anzahl' => $anz,
            'name' => $pname,
            'kurz' => $kurz,
            'druckziel_suffix' => ff_thermal_bon_druckziel_label((string)($row['druckziel_name'] ?? '')),
            'einzelpreis' => $einzel,
            'gesamtpreis' => $summe,
            'betrag' => $summe,
            'zusatzinfo' => (string)($row['zusatzinfo'] ?? ''),
            'bestaetigt_um' => null,
        ];
    }
    mysqli_stmt_close($stmt);

    if ($positionen === []) {
        return null;
    }

    $sqlMeta = 'SELECT MIN(b.zeitstempel) AS bestellt_um, MIN(b.kellner) AS kellner
        FROM bestellungen b
        WHERE b.bon_id = ?
          AND b.delete = 0
          AND b.tischnummer = 999999
          AND b.timestampBezahlung IS NOT NULL
          AND b.timestampBezahlung <> \'0000-00-00 00:00:00\'
          ' . $kellnerFilterSql;
    $st2 = mysqli_prepare($conn, $sqlMeta);
    if (!$st2) {
        return null;
    }
    mysqli_stmt_bind_param($st2, 's', $bonId);
    mysqli_stmt_execute($st2);
    $metaRes = mysqli_stmt_get_result($st2);
    $meta = $metaRes ? mysqli_fetch_assoc($metaRes) : null;
    mysqli_stmt_close($st2);

    $kellnerRaw = $meta && isset($meta['kellner']) ? (string)$meta['kellner'] : '';
    $kellner = ff_user_display_label($conn, $kellnerRaw);
    $bestelltUm = $meta && isset($meta['bestellt_um']) ? (string)$meta['bestellt_um'] : '';

    $rowids = [];
    $sqlIds = 'SELECT b.rowid FROM bestellungen b WHERE b.bon_id = ?
          AND b.delete = 0 AND b.tischnummer = 999999
          AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung <> \'0000-00-00 00:00:00\'
          ' . $kellnerFilterSql . ' ORDER BY b.rowid';
    $st3 = mysqli_prepare($conn, $sqlIds);
    if ($st3) {
        mysqli_stmt_bind_param($st3, 's', $bonId);
        mysqli_stmt_execute($st3);
        $idr = mysqli_stmt_get_result($st3);
        while ($idr && ($rr = mysqli_fetch_assoc($idr))) {
            $rowids[] = (int)$rr['rowid'];
        }
        mysqli_stmt_close($st3);
    }

    $tisch = [
        'tischnummer' => 999999,
        'tischname' => 'Direktverkauf',
        /** Kunden-Abholnummer wie auf dem Browser-Bon (ABHOLNUMMER #…) – Thermo-Client druckt sie groß. */
        'abhol_bon_id' => $bonId,
        'kellner' => $kellner,
        'bestellt_um' => $bestelltUm,
        'order_nrs' => [],
        'positionen' => $positionen,
    ];

    $payload = [
        'tische' => [$tisch],
    ];

    return [$payload, $rowids];
}
