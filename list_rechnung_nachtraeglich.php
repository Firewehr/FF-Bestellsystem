<?php
require_once('auth.php');
ini_set('display_errors', '0');

$isPartial = isset($_GET['partial']) && $_GET['partial'] === '1';
$Tischnummer = (int) ($_GET['tischnummer'] ?? 0);

if (!$isPartial) {
    ?>
<a style="background-color:rgba(158, 158, 150,0.3)" href="javascript:void(0)" data-theme="b"
   onclick="if(typeof TischAnsichtHistory==='function'){TischAnsichtHistory();} return false;" class="ui-btn ui-icon-arrow-l ui-btn-icon-left">
    Zurück zur Historie
</a>
<h2 class="mt-2">Nachträgliche Rechnung – Positionen wählen</h2>
<p class="small text-muted">Alle <strong>bezahlten</strong> Posten ohne Rechnung (auch wenn Küche/Schank noch nicht fertig oder nicht ausgeliefert). Ankreuzen, dann „Weiter“.</p>
<div id="BestellungZahlen">
<?php
}

if ($Tischnummer <= 0) {
    echo '<div class="alert alert-warning">Ungültige Tischnummer.</div>';
    if (!$isPartial) {
        echo '</div>';
    }
    exit;
}

try {
    include_once __DIR__ . '/include/db.php';
    require_once __DIR__ . '/include/ff_rechnungen_ensure_columns.php';
    ff_bestellungen_ensure_rechnung_id_column($conn);
    ff_bestellungen_ensure_is_gratis_column($conn);

    $sql = 'SELECT b.rowid, b.Zusatzinfo, b.timestampBezahlung, b.order_nr,
            b.kueche, b.ausgeliefert, b.zeitKueche,
            COALESCE(NULLIF(b.betrag, 0), p.Betrag) AS betrag,
            p.Positionsname
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            WHERE b.`delete`=0 AND b.tischnummer=' . (int) $Tischnummer . '
              AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung<>\'0000-00-00 00:00:00\'
              AND (b.is_gratis IS NULL OR b.is_gratis=0)
              AND (b.rechnung_id IS NULL OR b.rechnung_id=0)
            ORDER BY b.zeitstempel ASC
            LIMIT 500';
    $res = mysqli_query($conn, $sql);
    $rows = [];
    $summe = 0.0;
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = $r;
            $summe += (float) ($r['betrag'] ?? 0);
        }
    }

    if (count($rows) === 0) {
        echo '<div class="alert alert-light border text-center text-muted my-3">'
            . 'Keine bezahlten Positionen ohne Rechnung an diesem Tisch.'
            . ' Bereits verrechnet? Admin → Rechnungen → PDF.'
            . '</div>';
        if (!$isPartial) {
            echo '</div>';
        }
        exit;
    }

    echo '<div class="d-flex flex-wrap gap-2 mb-2">';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="rnSelectAll">Alle auswählen</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="rnSelectNone">Alle abwählen</button>';
    echo '</div>';

    echo '<table class="table table-sm align-middle mb-2"><tbody id="rnPosTable">';
    foreach ($rows as $r) {
        $rid = (int) ($r['rowid'] ?? 0);
        $betrag = (float) ($r['betrag'] ?? 0);
        $name = (string) ($r['Positionsname'] ?? '');
        $zi = trim((string) ($r['Zusatzinfo'] ?? ''));
        $bez = trim((string) ($r['timestampBezahlung'] ?? ''));
        $bezLabel = '';
        if ($bez !== '' && $bez !== '0000-00-00 00:00:00') {
            $t = strtotime($bez);
            $bezLabel = $t ? date('d.m.Y H:i', $t) : '';
        }
        echo '<tr>';
        echo '<td style="width:36px;"><input type="checkbox" class="form-check-input rn-pos-cb" value="' . $rid . '" data-betrag="' . htmlspecialchars(number_format($betrag, 2, '.', ''), ENT_QUOTES, 'UTF-8') . '"></td>';
        echo '<td><strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>';
        if ($zi !== '') {
            echo '<br><small class="text-muted">' . htmlspecialchars($zi, ENT_QUOTES, 'UTF-8') . '</small>';
        }
        if ($bezLabel !== '') {
            echo '<br><small class="text-muted">bezahlt ' . htmlspecialchars($bezLabel, ENT_QUOTES, 'UTF-8') . '</small>';
        }
        $zk = trim((string) ($r['zeitKueche'] ?? ''));
        $kuecheFertig = ((int) ($r['kueche'] ?? 0) === 1) && ($zk !== '' && $zk !== '0000-00-00 00:00:00');
        $ausgeliefert = (int) ($r['ausgeliefert'] ?? 0) === 1;
        if (!$kuecheFertig) {
            echo '<br><small class="text-warning">Küche/Schank noch offen</small>';
        } elseif (!$ausgeliefert) {
            echo '<br><small class="text-warning">noch nicht ausgeliefert</small>';
        }
        echo '</td>';
        echo '<td class="text-end text-nowrap">' . number_format($betrag, 2, ',', '.') . ' €</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<p class="mb-3">Auswahl: <strong id="rnSelCnt">0</strong> · Summe: <strong id="rnSelSum">0,00 €</strong></p>';

    echo '<button type="button" class="btn btn-info w-100 mb-2" id="rnWeiterBtn" style="padding:12px;">Weiter → Rechnung &amp; Druck</button>';
    ?>
<script>
(function () {
    var tisch = <?= (int) $Tischnummer ?>;
    function fmt(n) { return n.toFixed(2).replace('.', ',') + ' €'; }
    function updateRnSum() {
        var cbs = document.querySelectorAll('.rn-pos-cb');
        var cnt = 0, sum = 0;
        cbs.forEach(function (cb) {
            if (cb.checked) {
                cnt++;
                sum += parseFloat(cb.getAttribute('data-betrag') || '0') || 0;
            }
        });
        var elC = document.getElementById('rnSelCnt');
        var elS = document.getElementById('rnSelSum');
        if (elC) elC.textContent = String(cnt);
        if (elS) elS.textContent = fmt(sum);
    }
    document.getElementById('rnSelectAll')?.addEventListener('click', function () {
        document.querySelectorAll('.rn-pos-cb').forEach(function (cb) { cb.checked = true; });
        updateRnSum();
    });
    document.getElementById('rnSelectNone')?.addEventListener('click', function () {
        document.querySelectorAll('.rn-pos-cb').forEach(function (cb) { cb.checked = false; });
        updateRnSum();
    });
    document.querySelectorAll('.rn-pos-cb').forEach(function (cb) {
        cb.addEventListener('change', updateRnSum);
    });
    document.getElementById('rnWeiterBtn')?.addEventListener('click', function () {
        var ids = [];
        document.querySelectorAll('.rn-pos-cb:checked').forEach(function (cb) {
            var id = parseInt(cb.value, 10);
            if (!isNaN(id) && id > 0) ids.push(id);
        });
        if (ids.length === 0) {
            alert('Bitte mindestens eine Position auswählen.');
            return;
        }
        var url = 'rechnung.php?tischnummer=' + encodeURIComponent(String(tisch))
            + '&basis=bezahlt&rowids=' + encodeURIComponent(ids.join(','));
        window.open(url, '_blank', 'noopener');
    });
    updateRnSum();
})();
</script>
    <?php
} catch (Throwable $e) {
    echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
}

if (!$isPartial) {
    echo '</div>';
}
