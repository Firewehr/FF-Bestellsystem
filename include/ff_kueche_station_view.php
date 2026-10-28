<?php
/**
 * Gemeinsame Küchen-/Druckziel-Listenansicht (eine Quelle für list_kueche + list_druckziel).
 */

require_once __DIR__ . '/ff_kueche_list_data.php';
require_once __DIR__ . '/bestellung_batch_key_sql.php';

/**
 * Kontext für die Stationsliste laden (Zeilen, Runden, Zähler, Sidebar-Farbe).
 *
 * @param array{
 *   mode: 'kueche'|'schank'|'druckziel',
 *   print_target?: int,
 *   position_type?: int,
 *   payment_mode?: string
 * } $opts
 * @return array<string, mixed>
 */
function ff_station_build_context(mysqli $conn, array $opts): array
{
    $mode = (string) ($opts['mode'] ?? 'druckziel');
    $printTarget = (int) ($opts['print_target'] ?? 0);
    $positionType = (int) ($opts['position_type'] ?? 1);
    $paymentMode = (string) ($opts['payment_mode'] ?? 'after');

    $druckzielMode = $mode === 'druckziel';
    $defaultPrintTarget = $mode === 'schank' ? 12 : ($printTarget > 0 ? $printTarget : 11);

    if ($druckzielMode) {
        $lines = ff_kueche_load_station_lines($conn, 0, $printTarget);
        $wartende = ff_druckziel_waiting_count($conn, $printTarget);
        $summaryPack = isset($lines['__error']) ? ['items' => [], 'mode' => 'open'] : ff_druckziel_position_summary($lines);
        $batches = isset($lines['__error']) ? [] : ff_station_group_batches($lines, 'all');
    } elseif ($mode === 'schank') {
        $lines = ff_kueche_load_station_lines($conn, 2);
        $wartende = ff_station_waiting_count_by_type($conn, 2);
        $summaryPack = [
            'items' => isset($lines['__error']) ? [] : ff_kueche_position_summary($lines),
            'mode' => 'open',
        ];
        $batches = isset($lines['__error']) ? [] : ff_station_group_batches($lines, 'all');
    } else {
        $lines = ff_kueche_load_station_lines($conn, $positionType);
        $wartende = ff_station_waiting_count_by_type($conn, $positionType);
        $summaryPack = [
            'items' => isset($lines['__error']) ? [] : ff_kueche_position_summary($lines),
            'mode' => 'open',
        ];
        $batches = isset($lines['__error']) ? [] : ff_station_group_batches($lines, 'kueche_open');
    }

    $error = isset($lines['__error']) ? (string) $lines['__error'] : '';
    if ($error !== '') {
        $lines = [];
    }

    if ($wartende <= 30) {
        $sidebarBg = '#f1f5f9';
    } elseif ($wartende < 80) {
        $sidebarBg = '#f59e0b';
    } else {
        $sidebarBg = '#dc2626';
    }

    return [
        'mode' => $mode,
        'druckziel_mode' => $druckzielMode,
        'print_target' => $printTarget,
        'position_type' => $positionType,
        'default_print_target' => $defaultPrintTarget,
        'payment_mode' => $paymentMode,
        'lines' => $lines,
        'batches' => $batches,
        'summary' => $summaryPack['items'] ?? [],
        'summary_mode' => (string) ($summaryPack['mode'] ?? 'open'),
        'wartende' => $wartende,
        'sidebar_bg' => $sidebarBg,
        'error' => $error,
        'listen_nr' => 0,
    ];
}

/** Schlüssel zum Verknüpfen von Summenliste und Positionen (Kleinschreibung). */
function ff_station_pos_key(string $name): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

/** Zone aus dem Tischnamen (Text vor dem ersten „-“, z. B. „Z-A2“ → „Z“). */
function ff_station_tisch_zone(string $tischname): string
{
    $tischname = trim($tischname);
    if ($tischname === '' || strpos($tischname, '-') === false) {
        return '';
    }

    return strtoupper(trim(explode('-', $tischname, 2)[0]));
}

/** Wartezeit kurz: „12 Min“ bzw. „1 Std 05 Min“. */
function ff_station_wartezeit_kurz(int $sec): string
{
    $min = (int) floor(max(0, $sec) / 60);
    if ($min < 60) {
        return $min . ' Min';
    }

    return intdiv($min, 60) . ' Std ' . sprintf('%02d', $min % 60) . ' Min';
}

function ff_station_teillieferung_enabled(mysqli $conn): bool
{
    static $on = null;
    if ($on === null) {
        require_once __DIR__ . '/settings.php';
        $on = setting_get($conn, 'station_teillieferung_druck', '0') === '1';
    }
    return $on;
}

function ff_station_one_click_abschliessen_enabled(mysqli $conn): bool
{
    static $on = null;
    if ($on === null) {
        require_once __DIR__ . '/settings.php';
        $on = setting_get($conn, 'station_one_click_abschliessen', '0') === '1';
    }
    return $on;
}

/**
 * Offene Positionen je Gericht, aufgeschlüsselt nach Tisch und Extrawunsch (für die Summenliste).
 *
 * @param array<string, mixed> $ctx
 * @return array<string, array{tische: array<string,int>, extras: array<string,int>}>
 */
function ff_station_open_breakdown(array $ctx): array
{
    $druckzielMode = (string) ($ctx['mode'] ?? '') === 'druckziel';
    $out = [];
    foreach (($ctx['batches'] ?? []) as $batch) {
        $tisch = (int) ($batch['tischnummer'] ?? 0) === 999999 ? 'Direktverkauf' : (string) ($batch['tischname'] ?? '');
        $derived = ff_kueche_batch_derive($batch['lines'] ?? [], $druckzielMode);
        foreach (($derived['open'] ?? []) as $r) {
            $name = (string) ($r['Positionsname'] ?? '');
            if ($name === '') {
                continue;
            }
            $anz = max(1, (int) ($r['anzahl'] ?? 1));
            $out[$name]['tische'][$tisch] = ($out[$name]['tische'][$tisch] ?? 0) + $anz;
            $extra = trim((string) ($r['Zusatzinfo'] ?? ''));
            if ($extra !== '') {
                $out[$name]['extras'][$extra] = ($out[$name]['extras'][$extra] ?? 0) + $anz;
            }
        }
    }

    return $out;
}

/** @return array{bg: string, color: string} */
function ff_station_qty_colors(int $anz): array
{
    if ($anz <= 10) {
        return ['bg' => '#475569', 'color' => '#FFFFFF'];
    }
    if ($anz < 25) {
        return ['bg' => '#f59e0b', 'color' => '#FFFFFF'];
    }

    return ['bg' => '#dc2626', 'color' => '#FFFFFF'];
}

/**
 * Übersicht oben in der Bestellliste (volle Breite, vor den Tischkarten).
 *
 * @param list<array{Positionsname: string, anzahl: int}> $summary
 */
function ff_station_render_inline_summary(array $summary, string $titleHtml): void
{
    if ($summary === []) {
        return;
    }
    echo '<div class="kueche-inline-summary mb-3 p-3 border rounded bg-white">';
    echo '<div class="fw-bold mb-2">' . $titleHtml . '</div><div class="d-flex flex-wrap gap-2">';
    foreach ($summary as $s) {
        $anzS = (int) ($s['anzahl'] ?? 0);
        $c = ff_station_qty_colors($anzS);
        echo '<span class="badge fs-6" style="background:' . $c['bg'] . ';color:' . $c['color'] . ';">' . $anzS . '× '
            . htmlspecialchars((string) ($s['Positionsname'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>';
    }
    echo '</div></div>';
}

function ff_station_bon_id_for_batch(mysqli $conn, string $batchKeyEsc, array $ctx): string
{
    require_once __DIR__ . '/bestellung_batch_key_sql.php';
    $bkExpr = ff_sql_bestellung_batch_key('b');
    if ($ctx['druckziel_mode']) {
        $pt = (int) ($ctx['print_target'] ?? 0);
        $sql = "SELECT b.bon_id FROM bestellungen b INNER JOIN positionen po ON b.position = po.rowid "
            . "WHERE b.tischnummer=999999 AND b.delete=0 AND b.ausgeliefert=0 "
            . "AND (COALESCE(b.print_target, po.print_target) = {$pt}) "
            . "AND {$bkExpr}='{$batchKeyEsc}' "
            . "AND b.bon_id IS NOT NULL AND CHAR_LENGTH(TRIM(b.bon_id)) > 0 LIMIT 1";
    } else {
        $ptype = (int) ($ctx['position_type'] ?? 1);
        $sql = "SELECT b.bon_id FROM bestellungen b JOIN positionen p ON b.position=p.rowid "
            . "WHERE b.tischnummer=999999 AND b.`delete`=0 AND p.type={$ptype} "
            . "AND b.bon_id IS NOT NULL AND CHAR_LENGTH(TRIM(b.bon_id)) > 0 "
            . "AND {$bkExpr}='{$batchKeyEsc}' ORDER BY b.zeitstempel DESC LIMIT 1";
    }
    $bonRes = mysqli_query($conn, $sql);
    if ($bonRes && ($bonRow = mysqli_fetch_assoc($bonRes))) {
        return trim((string) ($bonRow['bon_id'] ?? ''));
    }

    return '';
}

/**
 * Tischkarten + Bestellungsrunden rendern. Erhöht listen_nr im Kontext.
 *
 * @param array<string, mixed> $ctx (wird listen_nr setzen)
 */
function ff_station_render_batches(mysqli $conn, array &$ctx): void
{
    $stationMode = (string) ($ctx['mode'] ?? 'kueche');
    $druckzielMode = $stationMode === 'druckziel';
    $printTarget = (int) ($ctx['default_print_target'] ?? 11);
    $paymentMode = (string) ($ctx['payment_mode'] ?? 'after');
    $fnFertig = $stationMode === 'schank' ? 'SchankFertig' : 'kuecheFertig';
    $fnGesamt = $stationMode === 'schank' ? 'schankGesamtFertig' : 'kuecheGesamtFertig';
    $fnForce = $stationMode === 'schank' ? 'bestellungSAAbschicken' : 'bestellungKUAbschicken';
    $batches = $ctx['batches'] ?? [];
    $listenNr = 0;
    $counter = 0;
    $groupOpen = false;
    $groupTisch = null;
    $groupBestellt = null;

    foreach ($batches as $batch) {
        if ($listenNr >= 50) {
            break;
        }
        $listenNr++;
        $row = [
            'tischnummer' => (int) ($batch['tischnummer'] ?? 0),
            'bestellt' => (int) ($batch['bestellt'] ?? 0),
            'kellner' => (string) ($batch['kellner'] ?? ''),
            'tischname' => (string) ($batch['tischname'] ?? ''),
            'order_nrs' => implode(', ', array_map('strval', array_keys($batch['order_nrs'] ?? []))),
        ];
        $batch_key = (string) ($batch['batch_key'] ?? '');
        $batch_key_esc = mysqli_real_escape_string($conn, $batch_key);
        $batchLines = $batch['lines'] ?? [];
        $derived = ff_kueche_batch_derive($batchLines, $druckzielMode);
        $printedLines = 0;
        foreach ($batchLines as $line) {
            if ((int) ($line['print_status'] ?? 0) === 1) {
                $printedLines++;
            }
        }
        $curTisch = (int) $row['tischnummer'];
        $curBestellt = (int) $row['bestellt'];

        $sameGroup = $groupOpen
            && $curTisch !== 999999
            && $groupTisch === $curTisch
            && $groupBestellt === $curBestellt;

        if (!$sameGroup) {
            if ($groupOpen) {
                echo '</div>';
            }
            echo '<div class="kueche-tisch-gruppe">';
            $groupOpen = true;
            $groupTisch = $curTisch;
            $groupBestellt = $curBestellt;

            $tischClass = ($curBestellt === 1) ? 'kueche-tisch-bestellt' : 'kueche-tisch-offen';
            echo '<div class="ui-block-a ' . $tischClass . '">';

            if ($curTisch === 999999) {
                $bonId = ff_station_bon_id_for_batch($conn, $batch_key_esc, $ctx);
                if ($bonId !== '') {
                    $bonEsc = htmlspecialchars($bonId, ENT_QUOTES, 'UTF-8');
                    echo '<div class="ff-kueche-dv-bon-wrap" role="group" aria-label="Direktverkauf Abholbon"><span class="ff-kueche-dv-bon-label">Direktverkauf · Abholbon</span><span class="ff-kueche-dv-bon-nr">Bon ' . $bonEsc . '</span></div>';
                    $tischname = 'Bon #' . $bonId;
                } else {
                    echo '<div class="ff-kueche-dv-bon-wrap ff-kueche-dv-bon-wrap--fallback" role="group" aria-label="Direktverkauf"><span class="ff-kueche-dv-bon-label">Direktverkauf</span><span class="ff-kueche-dv-bon-nr">ohne Bon-Nr.</span></div>';
                    $tischname = 'Direktverkauf';
                }
            } else {
                $orderNrLabel = $row['order_nrs'] !== ''
                    ? ' <span style="font-size:0.85rem; color:#fff; opacity:0.9;">(Best. Nr. ' . htmlspecialchars($row['order_nrs'], ENT_QUOTES, 'UTF-8') . ')</span>'
                    : '';
                $zone = ff_station_tisch_zone((string) ($row['tischname'] ?? ''));
                $zoneBadge = $zone !== ''
                    ? '<span class="ff-zone-badge" data-zone="' . htmlspecialchars($zone, ENT_QUOTES, 'UTF-8') . '" title="Zone ' . htmlspecialchars($zone, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($zone, ENT_QUOTES, 'UTF-8') . '</span>'
                    : '';
                echo '<h2 style="margin:0; font-size:1.8rem;">' . $zoneBadge . 'Tisch: ' . htmlspecialchars((string) ($row['tischname'] ?? ''), ENT_QUOTES, 'UTF-8') . $orderNrLabel . '</h2>';
                $tischname = $row['tischname'];
            }
            echo '</div><div class="ui-block-b"><p class="kueche-kellner" style="margin:0;">KellnerIn: '
                . htmlspecialchars(ff_user_display_label($conn, (string) ($row['kellner'] ?? '')), ENT_QUOTES, 'UTF-8') . '</p></div>';
        }

        $tischnummerselect = $row['tischnummer'];
        $listeIds = $derived['array_liste'] ?? [];
        $arrayListe = '[' . ($listeIds !== [] ? implode(',', array_map('intval', $listeIds)) : '') . ']';
        $openRows = $derived['open'] ?? [];
        $numRows2 = count($openRows);
        $timestamp = null;
        $openAnz = 0;
        foreach ($openRows as $or) {
            $openAnz += (int) ($or['anzahl'] ?? 0);
            if ($timestamp === null && !empty($or['zeitstempel'])) {
                $timestamp = strtotime((string) $or['zeitstempel']);
            }
        }
        $ageSec = $timestamp ? max(0, time() - (int) $timestamp) : -1;
        $waitClass = $ageSec >= 1200 ? ' ff-wait-late' : ($ageSec >= 600 ? ' ff-wait-warn' : ($ageSec >= 0 ? ' ff-wait-ok' : ''));
        $wrapZone = $curTisch === 999999 ? '' : ff_station_tisch_zone((string) ($row['tischname'] ?? ''));

        echo '<div class="kueche-bestellung-wrap' . $waitClass . '" data-zone="' . htmlspecialchars($wrapZone, ENT_QUOTES, 'UTF-8') . '" data-open-anz="' . $openAnz . '" data-age="' . $ageSec . '">';
        echo '<div class="ff-order-meta">';
        echo '<div class="kueche-bestellung-badge" title="Reihenfolge in der Liste">Nr. ' . (int) $listenNr . '</div>';
        if ($openAnz > 0) {
            echo '<span class="ff-order-count" title="Offene Positionen">' . $openAnz . ' Pos.</span>';
        }
        if ($printedLines > 0) {
            $batchLineCount = count($batchLines);
            $fullyPrinted = $printedLines === $batchLineCount;
            echo '<span class="ff-order-print-status' . ($fullyPrinted ? ' ff-order-print-status--done' : ' ff-order-print-status--partial') . '"'
                . ' title="' . ($fullyPrinted ? 'Der Bon wurde gedruckt.' : 'Ein Teil dieser Bestellung wurde bereits gedruckt.') . '">'
                . ($fullyPrinted ? '✓ Bon gedruckt' : 'Bon teilweise gedruckt (' . $printedLines . '/' . $batchLineCount . ')')
                . '</span>';
        }
        echo '<span class="kueche-wartend-zeit" title="Wartezeit seit Bestellung" data-age="' . $ageSec . '">'
            . ($ageSec >= 0 ? htmlspecialchars(ff_station_wartezeit_kurz($ageSec), ENT_QUOTES, 'UTF-8') : '–') . '</span>';
        echo '</div>';
        echo '<div class="kueche-order-row"><div class="kueche-unfertig ui-block-a">';

        foreach ($openRows as $row2) {
            $posName = (string) ($row2['Positionsname'] ?? '');
            echo '<div class="kueche-pos-row kueche-pos-row--open">';
            echo '<button type="button" class="kueche-btn-offen" data-pos="' . htmlspecialchars(ff_station_pos_key($posName), ENT_QUOTES, 'UTF-8')
                . '" aria-label="' . htmlspecialchars($posName . ' – offen, antippen zum Fertigstellen', ENT_QUOTES, 'UTF-8') . '"';
            if ($numRows2 == 1 && $row2['anzahl'] == 1 && $row2['bestellt'] == 1) {
                echo ' onclick="' . $fnGesamt . '(' . $arrayListe . ',' . (int) $tischnummerselect . ')"';
            } elseif ($numRows2 == 1 && $row2['anzahl'] == 1 && $row2['bestellt'] == 0) {
                echo ' onclick="' . $fnForce . '(' . (int) $tischnummerselect . ')"';
            } else {
                echo ' onclick="' . $fnFertig . '(' . (int) $row2['rowid'] . ')"';
            }
            echo '><span class="ff-pos-qty">' . (int) $row2['anzahl'] . '×</span> <span class="ff-pos-name">'
                . htmlspecialchars($posName, ENT_QUOTES, 'UTF-8') . '</span>';
            if (!empty($row2['Zusatzinfo'])) {
                echo '<span class="ff-pos-extra">' . htmlspecialchars((string) ($row2['Zusatzinfo'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>';
            }
            echo '</button>';
            echo '</div>';
        }
        if ($numRows2 === 0) {
            $hint = $druckzielMode
                ? 'In dieser Runde ist nichts mehr offen in der Station.'
                : ($stationMode === 'schank'
                    ? 'Keine offenen Getränke in dieser Runde (evtl. nur bereits „Fertig“ in Station).'
                    : 'Keine offenen Küchen-Positionen in dieser Runde (evtl. nur bereits „Fertig“ in Station).');
            echo '<p class="small text-muted mb-0">' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $doneRows = $derived['done'] ?? [];
        echo '</div>';
        if ($doneRows !== []) {
            echo '<div class="kueche-fertig ui-block-b">';
            foreach ($doneRows as $row2) {
                $undoCsv = htmlspecialchars((string) ($row2['undo_rowids'] ?? $row2['rowid'] ?? ''), ENT_QUOTES, 'UTF-8');
                $undoBtn = $undoCsv !== ''
                    ? '<button type="button" class="btn btn-sm btn-outline-secondary kueche-btn-wieder-offen" title="Wieder zu offen" data-rowids="' . $undoCsv . '">↩</button>'
                    : '';
                $doneClass = ($druckzielMode || (int) ($row2['kueche'] ?? 0) === 1)
                    ? 'kueche-btn-fertig kueche-btn-fertig--done'
                    : 'kueche-btn-fertig';
                echo '<div class="kueche-pos-row kueche-pos-row--done">';
                echo '<button type="button" class="' . $doneClass . '" disabled aria-disabled="true" aria-label="'
                    . htmlspecialchars((string) ($row2['Positionsname'] ?? '') . ' – fertig', ENT_QUOTES, 'UTF-8') . '">';
                echo '<span class="ff-pos-qty">' . (int) $row2['anzahl'] . '×</span> <span class="ff-pos-name">'
                    . htmlspecialchars((string) ($row2['Positionsname'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>';
                if (!empty($row2['Zusatzinfo'])) {
                    echo '<span class="ff-pos-extra">' . htmlspecialchars((string) ($row2['Zusatzinfo'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>';
                }
                echo '</button>' . $undoBtn;
                echo '</div>';
            }
            echo '</div>';
        }
        $printIds = $derived['print_rowids'] ?? [];
        if ($printIds === []) {
            foreach ($doneRows as $dr) {
                $csv = (string) ($dr['undo_rowids'] ?? $dr['rowid'] ?? '');
                foreach (explode(',', $csv) as $part) {
                    if ((int) $part > 0) {
                        $printIds[] = (int) $part;
                    }
                }
            }
        }
        $printListe = '[' . ($printIds !== [] ? implode(',', array_map('intval', $printIds)) : '') . ']';
        $rowidsAll = $derived['rowids_all'] ?? [];
        $abschliessenListe = '[' . ($rowidsAll !== [] ? implode(',', array_map('intval', $rowidsAll)) : '') . ']';
        $tischNr = (int) $tischnummerselect;
        $isDirektverkauf = ($tischNr === 999999);
        $gesamtListe = $arrayListe;
        if ($gesamtListe === '[]' && $rowidsAll !== []) {
            $gesamtListe = $abschliessenListe;
        }

        echo '<div class="kueche-buttons">';
        $teilErlaubt = ff_station_teillieferung_enabled($conn);
        if ($teilErlaubt && !$isDirektverkauf && $numRows2 > 0 && $printIds !== []) {
            echo '<input class="kueche-btn-fertig-aktion drucken" type="button" value="Teillieferung drucken"'
                . ' title="Druckt die bereits fertigen Positionen als Teillieferung."'
                . ' data-ff-teillieferung="1" data-rowids="' . htmlspecialchars(json_encode(array_values(array_unique($printIds))), ENT_QUOTES, 'UTF-8')
                . '" data-tisch="' . (int) $tischnummerselect . '" data-print-target="' . (int) $printTarget . '"/>';
        }
        if ($counter === 0) {
            echo '<script type="text/javascript">bestellungListe=' . $gesamtListe . '; bestellungTischnr="'
                . htmlspecialchars((string) ($tischname ?? ''), ENT_QUOTES, 'UTF-8') . '";</script>';
        }
        $counter++;
        // Direktverkauf: Farbe = bezahlt (grün) / unbezahlt (rot); bestellt-Feld bleibt für Thermodruck bei Abschließen.
        if ($numRows2 === 0 && $rowidsAll !== []) {
            if ($isDirektverkauf) {
                // DV: mit Druck = Stations-Bon (print_target); ohne Druck = nur ausgeliefert (Abholbon schon an Kasse).
                $rowidsJson = htmlspecialchars(json_encode(array_values(array_map('intval', $rowidsAll))), ENT_QUOTES, 'UTF-8');
                echo '<input class="kueche-btn-fertig-aktion abschliessen abschliessen--mit-druck" type="button" value="Bestellung abschließen"'
                    . ' title="Schließt ab und druckt den Bon am Druckziel der Position."'
                    . ' data-ff-abschliessen="1" data-rowids="' . $rowidsJson . '" data-tisch="' . $tischNr . '"/>';
                echo '<input class="kueche-btn-fertig-aktion abschliessen abschliessen--ohne-druck" type="button" value="Abholbon abschließen"'
                    . ' title="Nur abschließen – kein Stations-Bon (Abholbon wurde an der Kasse gedruckt)."'
                    . ' data-ff-abschliessen="0" data-rowids="' . $rowidsJson . '" data-tisch="' . $tischNr . '"/>';
                echo '<span class="small text-muted ms-1">(Abholbon = ohne Druck)</span>';
            } else {
                echo '<input class="kueche-btn-fertig-aktion abschliessen abschliessen--mit-druck" type="button" value="Bestellung abschließen"'
                    . ' data-ff-abschliessen="1" data-rowids="' . htmlspecialchars(json_encode(array_values(array_map('intval', $rowidsAll))), ENT_QUOTES, 'UTF-8') . '" data-tisch="' . $tischNr . '"/>';
            }
        } elseif (!$isDirektverkauf && (int) $row['bestellt'] === 1 && $numRows2 > 0 && $rowidsAll !== []
            && ff_station_one_click_abschliessen_enabled($conn)) {
            echo '<input class="kueche-btn-fertig-aktion abschliessen abschliessen--mit-druck" type="button" value="Bestellung abschließen"'
                . ' title="Markiert offene Positionen als fertig, schließt ab und druckt den Bon."'
                . ' data-ff-abschliessen="1" data-rowids="' . htmlspecialchars(json_encode(array_values(array_map('intval', $rowidsAll))), ENT_QUOTES, 'UTF-8') . '" data-tisch="' . $tischNr . '"/>';
        } elseif ($isDirektverkauf || (int) $row['bestellt'] === 1) {
            echo '<input class="kueche-btn-fertig-aktion gesamt" type="button" value="Alle Positionen fertig" title="Markiert alle offenen Positionen dieser Bestellung als fertig." onclick="' . $fnGesamt . '(' . $gesamtListe . ',' . $tischNr . ');"/>';
            if ($isDirektverkauf && $numRows2 > 0) {
                echo '<span class="small text-muted ms-1">(danach: Abholbon abschließen)</span>';
            }
        } elseif (!$isDirektverkauf) {
            echo '<input class="kueche-btn-fertig-aktion force" type="button" value="FORCE Bestellung" onclick="' . $fnForce . '(' . $tischNr . ');"/>';
        }
        echo '</div></div></div>';
    }
    if ($groupOpen) {
        echo '</div>';
    }

    $ctx['listen_nr'] = $listenNr;
}

/**
 * @param array<string, mixed> $ctx
 */
function ff_station_render_body(mysqli $conn, array $ctx, string $jsCounterVar, string $warnLabel, string $summaryTitleHtml = ''): void
{
    if ($ctx['error'] !== '') {
        echo '<p class="alert alert-danger m-2">' . htmlspecialchars($ctx['error'], ENT_QUOTES, 'UTF-8') . '</p>';

        return;
    }

    if ($summaryTitleHtml !== '') {
        ff_station_render_inline_summary($ctx['summary'] ?? [], $summaryTitleHtml);
    }
    ff_station_render_batches($conn, $ctx);

    $wartende = (int) ($ctx['wartende'] ?? 0);
    $listenNr = (int) ($ctx['listen_nr'] ?? 0);
    $jsExtra = '';
    if ($jsCounterVar === 'AnzahlOffeneBestellungenDruckziel') {
        $jsExtra = ';AnzahlBestellungenAktuell=' . $wartende;
    } elseif ($jsCounterVar === 'AnzahlOffeneBestellungenSchank') {
        $jsExtra = ';AnzahlBestellungenAktuell=' . $wartende . ';AnzahlGetraenkeWartendAktuell=' . $wartende;
    }
    echo '<script type="text/javascript">' . $jsCounterVar . '=' . $wartende . $jsExtra . ';</script>';

    if ($listenNr === 0 && $wartende > 0) {
        echo '<p class="alert alert-warning m-2">Es gibt ' . $wartende . ' ' . htmlspecialchars($warnLabel, ENT_QUOTES, 'UTF-8')
            . ', aber keine Tischkarte – Seite mit Strg+F5 neu laden.</p>';
    }
}

/**
 * @param list<array{Positionsname: string, anzahl: int}> $summary
 */
function ff_station_render_sidebar(int $wartende, array $summary, string $emptyText, bool $setAnzahlBestellungenAktuell = false, array $breakdown = []): void
{
    echo '<p class="kueche-wartend" data-waiting-count="' . $wartende . '">' . $wartende . ' Positionen wartend</p>';
    if ($setAnzahlBestellungenAktuell) {
        echo '<script type="text/javascript">AnzahlBestellungenAktuell=' . $wartende . ';</script>';
    }
    if ($summary === []) {
        echo '<p class="small text-muted mb-0">' . htmlspecialchars($emptyText, ENT_QUOTES, 'UTF-8') . '</p>';

        return;
    }
    foreach ($summary as $s) {
        $anz = (int) ($s['anzahl'] ?? 0);
        $c = ff_station_qty_colors($anz);
        $pnameRaw = (string) ($s['Positionsname'] ?? '');
        $pname = htmlspecialchars($pnameRaw, ENT_QUOTES, 'UTF-8');
        $posKey = htmlspecialchars(ff_station_pos_key($pnameRaw), ENT_QUOTES, 'UTF-8');
        $info = $breakdown[$pnameRaw] ?? [];
        $detail = '';
        foreach (($info['tische'] ?? []) as $tisch => $n) {
            $detail .= '<span class="ff-sum-tisch">' . htmlspecialchars((string) $tisch, ENT_QUOTES, 'UTF-8') . ($n > 1 ? ' ×' . $n : '') . '</span>';
        }
        $extraTxt = '';
        foreach (($info['extras'] ?? []) as $ex => $n) {
            $extraTxt .= '<span class="ff-sum-extra">' . $n . '× ' . htmlspecialchars((string) $ex, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        echo '<button type="button" class="ff-sum-item" data-pos="' . $posKey . '" style="background-color:' . $c['bg'] . '; color:' . $c['color'] . ';"'
            . ' title="Klicken: Positionen in den Bestellungen markieren">'
            . '<span class="ff-sum-head"><span class="ff-sum-qty">' . $anz . '×</span> <span class="ff-sum-name">' . $pname . '</span></span>'
            . ($detail !== '' ? '<span class="ff-sum-tische">' . $detail . '</span>' : '')
            . ($extraTxt !== '' ? '<span class="ff-sum-extras">' . $extraTxt . '</span>' : '')
            . '</button>';
    }
}
