<?php
/**
 * Rendert eine vollständige HTML-Seite zur Offline-Notfall-Dokumentation
 * (alle Druckziele wie in der Live-Ansicht, plus unbezahlte Positionen).
 */
declare(strict_types=1);

/**
 * @return string vollständiges HTML-Dokument
 */
function ff_render_offline_snapshot_html(mysqli $conn): string
{
    require_once __DIR__ . '/bestellung_batch_key_sql.php';
    require_once __DIR__ . '/ff_position_kassa_helpers.php';
    $kassaStationSql = ff_position_sql_kellner_visible_no_subjoin('positionen');
    $kassaStationSqlPo = ff_position_sql_kellner_visible('po', 'sc_po');
    $joinSubPo = ' LEFT JOIN position_subcategories sc_po ON sc_po.id = po.subcategory_id ';

    $h = static function ($s): string {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    };

    $festName = 'Fest';
    $fr = @mysqli_query($conn, 'SELECT name FROM feste WHERE aktiv=1 LIMIT 1');
    if ($fr && ($r = mysqli_fetch_assoc($fr)) && !empty($r['name'])) {
        $festName = (string) $r['name'];
    }

    $paymentMode = 'after';
    $fres = @mysqli_query($conn, 'SELECT payment_mode FROM feste WHERE aktiv=1 LIMIT 1');
    if ($fres && ($frow = mysqli_fetch_assoc($fres))) {
        $paymentMode = $frow['payment_mode'] ?: 'after';
    }
    $kuecheFilterUnpaid = ($paymentMode === 'after') ? ' AND b.kueche=1 ' : '';

    @mysqli_query($conn, "SHOW COLUMNS FROM positionen LIKE 'print_target'");
    @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'print_target'");

    $targets = [];
    $ptRes = @mysqli_query($conn, 'SELECT print_target, name FROM print_targets WHERE active=1 ORDER BY sort_order, name, print_target');
    if ($ptRes) {
        while ($pt = mysqli_fetch_assoc($ptRes)) {
            $targets[] = $pt;
        }
    }
    if (count($targets) === 0) {
        $targets = [['print_target' => 11, 'name' => 'Küche'], ['print_target' => 12, 'name' => 'Schank']];
    }

    $generated = date('d.m.Y H:i:s');
    $batchKeyExpr = ff_sql_bestellung_batch_key('bestellungen');

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offline-Sicherung – <?php echo $h($festName); ?></title>
    <style>
        :root { --b: #1e293b; --m: #64748b; --a: #0f766e; }
        body { font-family: system-ui, Segoe UI, Roboto, sans-serif; color: var(--b); line-height: 1.45; max-width: 52rem; margin: 0 auto; padding: 1rem 1.25rem 3rem; }
        h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
        .banner { background: #ecfdf5; border: 1px solid #6ee7b7; border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1.25rem; font-size: .9rem; }
        .meta { color: var(--m); font-size: .88rem; margin-bottom: 1.5rem; }
        .toc { background: #f8fafc; border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1.5rem; }
        .toc a { color: var(--a); }
        section.snap-sec { border-top: 2px solid #e2e8f0; padding-top: 1.25rem; margin-top: 1.25rem; }
        h2 { font-size: 1.2rem; margin: 0 0 .75rem; }
        h2 .sub { font-weight: 400; color: var(--m); font-size: .85rem; }
        .snap-batch { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1rem; }
        h3 { font-size: 1.05rem; margin: 0 0 .35rem; }
        h4 { font-size: .9rem; margin: .75rem 0 .35rem; color: #334155; }
        .kellner { margin: 0 0 .5rem; font-size: .9rem; color: var(--m); }
        .snap-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 640px) { .snap-cols { grid-template-columns: 1fr; } }
        ul { margin: .25rem 0 0; padding-left: 1.2rem; }
        li { margin-bottom: .2rem; }
        .zi { color: #475569; font-size: .9rem; }
        .muted { color: var(--m); }
        .err { color: #b91c1c; }
        ul.snap-sum { columns: 2; }
        @media print { body { max-width: none; } .banner { break-inside: avoid; } .snap-batch { break-inside: avoid; } }
    </style>
</head>
<body>
    <h1>Offline-Sicherung – <?php echo $h($festName); ?></h1>
    <p class="meta">Erstellt: <strong><?php echo $h($generated); ?></strong> · Nur Dokumentation bei Ausfall von Internet, Server oder Datenbank.</p>
    <div class="banner">
        <strong>So nutzt du die Datei offline:</strong> Einmal herunterladen und z. B. auf dem Handy/Tablet im Download-Ordner öffnen
        (funktioniert ohne Netz). Zusätzlich kannst du im Browser <em>„Seite speichern unter …“</em> wählen.
        Nach dem Fest am besten erneut eine Sicherung ziehen.
    </div>

    <nav class="toc" aria-label="Inhalt">
        <strong>Inhalt</strong>
        <ul>
            <?php foreach ($targets as $t) { ?>
                <li><a href="#dz-<?php echo (int) $t['print_target']; ?>"><?php echo $h($t['name']); ?></a></li>
            <?php } ?>
            <li><a href="#unbezahlt">Noch zu zahlende Positionen</a></li>
        </ul>
    </nav>

    <?php
    foreach ($targets as $ptRow) {
        $print_target = (int) $ptRow['print_target'];
        $targetName = (string) ($ptRow['name'] ?? ('Druckziel ' . $print_target));
        $ptFilter = ' AND (COALESCE(bestellungen.print_target, positionen.print_target) = ' . $print_target . ') ';

        echo '<section class="snap-sec" id="dz-' . $print_target . '">';
        echo '<h2>' . $h($targetName) . ' <span class="sub">(Druckziel ' . $print_target . ')</span></h2>';

        $sql = 'SELECT positionen.type, tische.tischname, bestellungen.tischnummer, bestellungen.bestellt, '
            . "{$batchKeyExpr} AS batch_key, COUNT(*) AS cnt, MAX(bestellungen.kellner) AS kellner, "
            . 'GROUP_CONCAT(DISTINCT bestellungen.order_nr ORDER BY bestellungen.order_nr) AS order_nrs, '
            . 'MIN(bestellungen.zeitstempel) AS first_ts '
            . 'FROM bestellungen '
            . 'JOIN positionen ON bestellungen.position=positionen.rowid '
            . 'JOIN tische ON tische.tischnummer=bestellungen.tischnummer '
            . 'WHERE bestellungen.delete=0 AND bestellungen.ausgeliefert=0 ' . $ptFilter . $kassaStationSql
            . "GROUP BY bestellungen.tischnummer, {$batchKeyExpr} ORDER BY MIN(bestellungen.zeitstempel) ASC LIMIT 100";

        $result = mysqli_query($conn, $sql);
        if (!$result) {
            echo '<p class="err">Datenbankfehler: ' . $h((string) mysqli_error($conn)) . '</p></section>';
            continue;
        }

        $any = false;
        while ($row = mysqli_fetch_assoc($result)) {
            $any = true;
            $batch_key = (string) ($row['batch_key'] ?? '');
            $batch_key_esc = mysqli_real_escape_string($conn, $batch_key);
            $tischnummer = (int) $row['tischnummer'];
            $ts = !empty($row['first_ts']) ? strtotime((string) $row['first_ts']) : false;
            $timeLabel = $ts ? date('d.m. H:i', $ts) : '';

            echo '<div class="snap-batch">';

            if ($tischnummer === 999999) {
                $bonQuery = 'SELECT b.bon_id FROM bestellungen b INNER JOIN positionen po ON b.position = po.rowid '
                    . $joinSubPo
                    . 'WHERE b.tischnummer=999999 AND b.delete=0 AND b.ausgeliefert=0 '
                    . 'AND (COALESCE(b.print_target, po.print_target) = ' . $print_target . ') ' . $kassaStationSqlPo
                    . "AND " . ff_sql_bestellung_batch_key('b') . "='" . $batch_key_esc . "' "
                    . 'AND b.bon_id IS NOT NULL AND CHAR_LENGTH(TRIM(b.bon_id)) > 0 LIMIT 1';
                $bonRes = mysqli_query($conn, $bonQuery);
                $bonId = '';
                if ($bonRes && ($bonRow = mysqli_fetch_assoc($bonRes))) {
                    $bonId = (string) $bonRow['bon_id'];
                }
                if ($bonId !== '') {
                    echo '<h3>🎫 Bon #' . $h($bonId);
                } else {
                    echo '<h3>🛒 Direktverkauf';
                }
            } else {
                $ordLab = !empty($row['order_nrs']) ? ' <span class="muted">(Best.-Nr. ' . $h($row['order_nrs']) . ')</span>' : '';
                echo '<h3>Tisch: ' . $h($row['tischname']) . $ordLab;
            }
            if ($timeLabel !== '') {
                echo ' <span class="muted">· ' . $h($timeLabel) . '</span>';
            }
            echo '</h3>';
            echo '<p class="kellner">KellnerIn: ' . $h($row['kellner']) . '</p>';

            echo '<div class="snap-cols"><div class="snap-open"><h4>Noch offen (wartet auf Station)</h4><ul>';
            $sql2 = 'SELECT COUNT(*) AS anzahl, bestellungen.Zusatzinfo, positionen.Positionsname '
                . 'FROM bestellungen, positionen '
                . 'WHERE bestellungen.position=positionen.rowid ' . $ptFilter . $kassaStationSql
                . "AND bestellungen.zeitKueche='0000-00-00 00:00:00' AND bestellungen.ausgeliefert=0 AND bestellungen.delete=0 "
                . 'AND bestellungen.tischnummer=' . $tischnummer . " AND {$batchKeyExpr}='" . $batch_key_esc . "' "
                . 'GROUP BY bestellungen.Zusatzinfo, positionen.Positionsname ORDER BY positionen.Positionsname ASC';
            $r2 = mysqli_query($conn, $sql2);
            $openLines = 0;
            if ($r2) {
                while ($row2 = mysqli_fetch_assoc($r2)) {
                    $openLines++;
                    $zi = trim((string) ($row2['Zusatzinfo'] ?? ''));
                    echo '<li><strong>' . (int) $row2['anzahl'] . '×</strong> ' . $h($row2['Positionsname']);
                    if ($zi !== '') {
                        echo ' <span class="zi">(' . $h($zi) . ')</span>';
                    }
                    echo '</li>';
                }
            }
            if ($openLines === 0) {
                echo '<li class="muted">— keine —</li>';
            }
            echo '</ul></div>';

            echo '<div class="snap-done"><h4>Bestätigt (Station), noch nicht ausgeliefert</h4><ul>';
            $qDone = 'SELECT COUNT(*) AS anzahl, bestellungen.Zusatzinfo, positionen.Positionsname '
                . 'FROM bestellungen, positionen '
                . 'WHERE bestellungen.position=positionen.rowid ' . $ptFilter . $kassaStationSql
                . 'AND bestellungen.ausgeliefert=0 AND bestellungen.kueche=1 AND bestellungen.delete=0 AND bestellungen.zeitKueche!=\'0000-00-00 00:00:00\' '
                . 'AND bestellungen.tischnummer=' . $tischnummer . " AND {$batchKeyExpr}='" . $batch_key_esc . "' "
                . 'GROUP BY bestellungen.Zusatzinfo, positionen.Positionsname ORDER BY positionen.Positionsname ASC';
            $r3 = mysqli_query($conn, $qDone);
            $doneLines = 0;
            if ($r3) {
                while ($row3 = mysqli_fetch_assoc($r3)) {
                    $doneLines++;
                    $zi = trim((string) ($row3['Zusatzinfo'] ?? ''));
                    echo '<li><strong>' . (int) $row3['anzahl'] . '×</strong> ' . $h($row3['Positionsname']);
                    if ($zi !== '') {
                        echo ' <span class="zi">(' . $h($zi) . ')</span>';
                    }
                    echo '</li>';
                }
            }
            if ($doneLines === 0) {
                echo '<li class="muted">— keine —</li>';
            }
            echo '</ul></div></div>';
            echo '</div>';
        }

        if (!$any) {
            echo '<p class="muted">Keine offenen Lieferungen für dieses Druckziel.</p>';
        }

        echo '<h4>Übersicht: noch offen (kueche=0) nach Artikel</h4><ul class="snap-sum">';
        $sql6 = 'SELECT positionen.Positionsname, COUNT(*) AS anzahl '
            . 'FROM positionen, bestellungen WHERE bestellungen.position=positionen.rowid AND bestellungen.kueche=0 AND bestellungen.delete=0 AND bestellungen.ausgeliefert=0 '
            . 'AND (COALESCE(bestellungen.print_target, positionen.print_target) = ' . $print_target . ') '
            . $kassaStationSql
            . ' GROUP BY bestellungen.position ORDER BY anzahl DESC';
        $q6 = mysqli_query($conn, $sql6);
        if ($q6 && mysqli_num_rows($q6) > 0) {
            while ($row6 = mysqli_fetch_assoc($q6)) {
                echo '<li>' . (int) $row6['anzahl'] . '× ' . $h($row6['Positionsname']) . '</li>';
            }
        } else {
            echo '<li class="muted">—</li>';
        }
        echo '</ul>';

        echo '</section>';
    }
    ?>

    <section class="snap-sec" id="unbezahlt">
        <h2>Noch zu zahlende Positionen</h2>
        <p class="muted" style="margin-top:0">Gemäß Zahlungsmodus (<?php echo $h($paymentMode === 'instant' ? 'sofort' : 'am Ende'); ?>): nur relevante Zeilen.</p>
        <?php
        $sqlU = 'SELECT t.tischname, b.tischnummer, p.Positionsname, b.betrag, b.Zusatzinfo, b.zeitstempel '
            . 'FROM bestellungen b '
            . 'JOIN positionen p ON p.rowid = b.position '
            . 'JOIN tische t ON t.tischnummer = b.tischnummer '
            . 'WHERE b.delete=0 AND b.bestellt=1 AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung=\'0000-00-00 00:00:00\') '
            . $kuecheFilterUnpaid
            . 'ORDER BY t.tischnummer ASC, b.zeitstempel ASC LIMIT 400';
        $ru = mysqli_query($conn, $sqlU);
        if ($ru && mysqli_num_rows($ru) > 0) {
            $curT = null;
            while ($u = mysqli_fetch_assoc($ru)) {
                $tn = (int) $u['tischnummer'];
                if ($curT !== $tn) {
                    if ($curT !== null) {
                        echo '</ul>';
                    }
                    $curT = $tn;
                    echo '<h3>' . $h($u['tischname']) . ' <span class="muted">(Tisch ' . $tn . ')</span></h3><ul>';
                }
                $zi = trim((string) ($u['Zusatzinfo'] ?? ''));
                $bet = number_format((float) ($u['betrag'] ?? 0), 2, ',', '.');
                echo '<li>' . $h($u['Positionsname']) . ' — <strong>' . $h($bet) . ' €</strong>';
                if ($zi !== '') {
                    echo ' <span class="zi">(' . $h($zi) . ')</span>';
                }
                echo '</li>';
            }
            if ($curT !== null) {
                echo '</ul>';
            }
        } else {
            echo '<p class="muted">Keine unbezahlten Positionen (oder keine passenden zur aktuellen Logik).</p>';
        }
        ?>
    </section>

    <p class="meta" style="margin-top:2rem;">Ende der Sicherung · <?php echo $h($festName); ?> · <?php echo $h($generated); ?></p>
</body>
</html>
    <?php

    return (string) ob_get_clean();
}
