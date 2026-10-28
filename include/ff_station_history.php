<?php
declare(strict_types=1);

/**
 * Gemeinsame, gruppierte Stations-Historie (Küche / Schank / Druckziel).
 * Gruppiert nach logischer Bestellrunde (order_nr bzw. Tisch+Zeitstempel),
 * zeigt aggregierte Positionen mit Anzahl und Beträgen sowie Summen.
 */

if (!function_exists('ff_station_history_eur')) {
    function ff_station_history_eur(float $v): string
    {
        return number_format($v, 2, ',', '.') . ' €';
    }
}

require_once __DIR__ . '/ff_hist_navigation.php';

/**
 * @param string $whereExtra zusätzliche WHERE-Bedingung (mit führendem " AND ..."), z. B. Typ- oder Druckziel-Filter
 */
function ff_station_history_render(
    mysqli $conn,
    string $whereExtra,
    string $title,
    string $backOnclick,
    string $introText = 'Letzte Bestellungen, nach Bestellung gruppiert.',
    string $histFrom = 'station',
    int $histPrintTarget = 0
): void {
    $histFrom = ff_hist_nav_from_context($histFrom);
    if ($histFrom === '') {
        $histFrom = 'station';
    }
    $histExtraOpts = $histFrom === 'druckziel' && $histPrintTarget > 0
        ? ['pt' => $histPrintTarget]
        : [];
    $sql = 'SELECT tische.tischname, bestellungen.zeitKueche, bestellungen.position, bestellungen.Zusatzinfo, '
        . 'bestellungen.tischnummer, bestellungen.zeitstempel, bestellungen.timestampBestellung, bestellungen.order_nr, '
        . 'bestellungen.bon_id, '
        . 'COALESCE(bestellungen.betrag, positionen.Betrag) AS betrag_zeile, '
        . 'positionen.Positionsname, positionen.type '
        . 'FROM bestellungen, positionen, tische '
        . 'WHERE tische.tischnummer=bestellungen.tischnummer AND bestellungen.position=positionen.rowid AND bestellungen.delete=0 '
        . $whereExtra
        . ' ORDER BY bestellungen.zeitstempel DESC, bestellungen.order_nr DESC, bestellungen.rowid DESC LIMIT 400';
    $res = mysqli_query($conn, $sql);

    $groups = [];
    $groupOrder = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $onr = (int) ($row['order_nr'] ?? 0);
            $tnr = (int) ($row['tischnummer'] ?? 0);
            $bestelltTs = (string) ($row['timestampBestellung'] ?? '');
            if ($bestelltTs === '' || $bestelltTs === '0000-00-00 00:00:00' || $bestelltTs === '1970-01-01 00:00:00') {
                $bestelltTs = (string) ($row['zeitstempel'] ?? '');
            }
            $gkey = $onr > 0 ? ('o' . $onr . '_t' . $tnr) : ('t' . $tnr . '_' . $bestelltTs);
            if (!isset($groups[$gkey])) {
                $groups[$gkey] = [
                    'tischname' => (string) ($row['tischname'] ?? ''),
                    'tischnummer' => $tnr,
                    'order_nr' => $onr,
                    'bon_id' => '',
                    'bestellt' => $bestelltTs,
                    'fertig' => '',
                    'positionen' => [],
                    'summe' => 0.0,
                    'anzahl' => 0,
                ];
                $groupOrder[] = $gkey;
            }
            $bonRow = trim((string) ($row['bon_id'] ?? ''));
            if ($bonRow !== '' && $groups[$gkey]['bon_id'] === '') {
                $groups[$gkey]['bon_id'] = $bonRow;
            }
            // Anzeigezeit: früheste Bestellzeit der Runde (nicht die neueste Zeile).
            if ($bestelltTs !== '' && ($groups[$gkey]['bestellt'] === '' || $bestelltTs < $groups[$gkey]['bestellt'])) {
                $groups[$gkey]['bestellt'] = $bestelltTs;
            }
            $zk = (string) ($row['zeitKueche'] ?? '');
            if ($zk !== '' && $zk !== '0000-00-00 00:00:00' && $zk > $groups[$gkey]['fertig']) {
                $groups[$gkey]['fertig'] = $zk;
            }
            $name = (string) ($row['Positionsname'] ?? '');
            $zusatz = trim((string) ($row['Zusatzinfo'] ?? ''));
            $pkey = $name . "\x1e" . $zusatz;
            if (!isset($groups[$gkey]['positionen'][$pkey])) {
                $groups[$gkey]['positionen'][$pkey] = ['name' => $name, 'zusatz' => $zusatz, 'anzahl' => 0, 'summe' => 0.0];
            }
            $betrag = (float) ($row['betrag_zeile'] ?? 0);
            $groups[$gkey]['positionen'][$pkey]['anzahl']++;
            $groups[$gkey]['positionen'][$pkey]['summe'] += $betrag;
            $groups[$gkey]['summe'] += $betrag;
            $groups[$gkey]['anzahl']++;
        }
    }

    // Neueste Bestellrunde zuerst (explizit, unabhängig von Zeilenreihenfolge).
    usort($groupOrder, static function (string $ka, string $kb) use ($groups): int {
        $ta = strtotime((string) ($groups[$ka]['bestellt'] ?? '')) ?: 0;
        $tb = strtotime((string) ($groups[$kb]['bestellt'] ?? '')) ?: 0;
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }
        $oa = (int) ($groups[$ka]['order_nr'] ?? 0);
        $ob = (int) ($groups[$kb]['order_nr'] ?? 0);

        return $ob <=> $oa;
    });

    $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    if ($introText === 'Letzte Bestellungen, nach Bestellung gruppiert.') {
        $introText = 'Neueste Bestellungen zuerst, nach Bestellung gruppiert.';
    }
    ?>
<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <a href="#" class="btn btn-outline-secondary btn-sm" onclick="<?php echo htmlspecialchars($backOnclick, ENT_QUOTES, 'UTF-8'); ?>">← Zurück</a>
        <span class="navbar-brand mb-0"><?php echo $titleEsc; ?></span>
    </div>
</nav>
<div class="app-content py-3">
    <p class="text-muted small mb-3"><?php echo htmlspecialchars($introText, ENT_QUOTES, 'UTF-8'); ?></p>
<?php if ($groupOrder === []): ?>
    <div class="alert alert-secondary">Keine Einträge in der Historie.</div>
<?php else: ?>
    <?php foreach ($groupOrder as $gkey): $g = $groups[$gkey]; ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <span>
                <strong><?php echo htmlspecialchars($g['tischname'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </span>
            <?php
            $detailActs = ff_hist_detail_actions_html(
                (int) ($g['tischnummer'] ?? 0),
                (int) ($g['order_nr'] ?? 0),
                (string) ($g['bon_id'] ?? ''),
                $histFrom,
                $histExtraOpts
            );
            if ($detailActs !== ''): ?>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <?php echo $detailActs; ?>
            </div>
            <?php endif; ?>
            <span class="text-muted small ms-auto">
                bestellt <?php echo htmlspecialchars((string) $g['bestellt'], ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($g['fertig'] !== ''): ?> · fertig <?php echo htmlspecialchars((string) $g['fertig'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
            </span>
        </div>
        <ul class="list-group list-group-flush">
            <?php foreach ($g['positionen'] as $p): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center py-1">
                <span><span class="badge bg-secondary me-2"><?php echo (int) $p['anzahl']; ?>×</span><?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?><?php if ($p['zusatz'] !== ''): ?> <span class="text-muted small">– <?php echo htmlspecialchars($p['zusatz'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></span>
                <span class="text-nowrap"><?php echo ff_station_history_eur((float) $p['summe']); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <div class="card-footer d-flex justify-content-between align-items-center py-2">
            <span class="text-muted small"><?php echo (int) $g['anzahl']; ?> Position(en)</span>
            <strong><?php echo ff_station_history_eur((float) $g['summe']); ?></strong>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>
    <?php
}
