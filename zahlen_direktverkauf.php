<?php
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/include/db.php';
    require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
    ff_direktverkauf_require($conn, false);
} elseif (!defined('FF_DV_FRAGMENT_CAPTURE')) {
    require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
    ff_direktverkauf_require($conn, false);
}
require_once __DIR__ . '/include/menu_list_helpers.php';

$partial = trim((string) ($_GET['partial'] ?? ''));
$kellnerFilter = ff_direktverkauf_kellner_filter_sql($conn, 'b');
$bonId = trim((string) ($_GET['bon_id'] ?? ''));
$bonSql = ff_direktverkauf_bon_filter_sql($conn, $bonId, 'b');
$Tischnummer = 999999;
$amt = 'COALESCE(NULLIF(b.betrag, 0), p.Betrag)';

function ff_dv_zahlen_eur(float $amount): string
{
    return number_format($amount, 2, ',', '.') . ' €';
}

$sql = "SELECT b.rowid AS rowidBestellung, b.position, p.Positionsname, p.type AS pos_type,
        {$amt} AS betrag, b.Zusatzinfo
    FROM bestellungen b
    JOIN positionen p ON p.rowid = b.position
    WHERE b.tischnummer = {$Tischnummer}
        {$kellnerFilter}
        {$bonSql}
        AND b.`delete` = 0
        AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung = '0000-00-00 00:00:00')
    ORDER BY p.type ASC, p.Positionsname ASC, b.rowid ASC";

$Summe = 0.0;
$lines = [];
$dvIds = [];
$res = mysqli_query($conn, $sql);
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $lines[] = $row;
    $Summe += (float) $row['betrag'];
    $dvIds[] = (int) $row['rowidBestellung'];
}
$count = count($lines);
$arrayListe = $count > 0 ? ('[' . implode(',', $dvIds) . ']') : '[]';
$summeFmt = ff_dv_zahlen_eur((float) $Summe);

if ($partial === 'cart') {
    if ($count === 0) {
        echo '<p class="text-muted small mb-0">Keine offenen Positionen auf diesem Bon.</p>';
        exit;
    }
    // Wie list_BestellungenZahlen (aggregiert): Position + Einzelpreis + Zusatzinfo
    $aggregated = [];
    foreach ($lines as $row) {
        $name = (string) ($row['Positionsname'] ?? '');
        $betrag = (float) ($row['betrag'] ?? 0);
        $ziRow = trim((string) ($row['Zusatzinfo'] ?? ''));
        $k = $name . '|' . number_format($betrag, 2, '.', '') . '|' . $ziRow;
        if (!isset($aggregated[$k])) {
            $aggregated[$k] = [
                'name' => $name,
                'anzahl' => 0,
                'summe' => 0.0,
                'preis' => $betrag,
                'rows' => [],
                'zusatzinfo' => $ziRow,
                'position' => (int) ($row['position'] ?? 0),
                'pos_type' => (int) ($row['pos_type'] ?? 0),
            ];
        }
        $aggregated[$k]['anzahl']++;
        $aggregated[$k]['summe'] += $betrag;
        $aggregated[$k]['rows'][] = (int) $row['rowidBestellung'];
    }
    echo '<div class="table-responsive"><table class="table table-sm table-striped mb-0 ff-dv-cart-table">';
    echo '<thead class="table-light"><tr><th>Position</th><th class="text-end">Summe</th><th class="text-end" style="width:2.5rem;"></th></tr></thead><tbody>';
    foreach ($aggregated as $data) {
        $posId = (int) $data['position'];
        $typ = (int) $data['pos_type'];
        $typLbl = $typ === 1 ? 'Speise' : 'Getränk';
        $typBadge = $typ === 1 ? 'bg-success' : 'bg-danger';
        $ziAgg = trim((string) ($data['zusatzinfo'] ?? ''));
        $jsonRows = htmlspecialchars(json_encode($data['rows'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        echo '<tr>';
        echo '<td class="align-middle">';
        echo '<span class="badge ' . $typBadge . ' me-1">' . $typLbl . '</span>';
        echo '<strong>' . (int) $data['anzahl'] . '×</strong> ';
        echo htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8');
        echo ' <span class="text-muted small">(' . htmlspecialchars(ff_dv_zahlen_eur((float) $data['preis']), ENT_QUOTES, 'UTF-8') . ')</span>';
        if ($ziAgg !== '') {
            echo '<br><span class="small text-muted">' . htmlspecialchars($ziAgg, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        echo '</td>';
        echo '<td class="text-end text-nowrap align-middle fw-semibold">';
        echo htmlspecialchars(ff_dv_zahlen_eur((float) $data['summe']), ENT_QUOTES, 'UTF-8');
        echo '</td>';
        echo '<td class="text-end align-middle">';
        echo '<button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Eine Portion entfernen"';
        echo ' onclick="ffDvWarenkorbMinusAgg(' . $jsonRows . ', ' . $posId . ', ' . $typ . '); return false;">−</button>';
        echo '</td></tr>';
    }
    echo '</tbody>';
    echo '<tfoot class="table-light"><tr>';
    echo '<td class="text-end fw-semibold py-2">Gesamt <span class="text-muted fw-normal">(' . (int) $count . ' Pos.)</span></td>';
    echo '<td class="text-end fw-bold fs-5 py-2 text-nowrap" id="ffDvCartGesamtText">';
    echo htmlspecialchars($summeFmt, ENT_QUOTES, 'UTF-8');
    echo '</td><td></td></tr></tfoot>';
    echo '</table></div>';
    exit;
}

echo '<div class="ff-dv-paybar-compact card border-0 shadow-sm mb-0">';
echo '<div class="card-body py-2 px-3">';
echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">';
echo '<div class="d-flex flex-wrap align-items-baseline gap-2">';
$bonLbl = $bonId !== '' ? ('Bon #' . $bonId) : 'Bon offen';
echo '<span class="text-muted small">' . htmlspecialchars($bonLbl, ENT_QUOTES, 'UTF-8') . '</span>';
echo '<span class="fs-4 fw-bold text-primary mb-0" id="ffDvPaySummeText">' . htmlspecialchars($summeFmt, ENT_QUOTES, 'UTF-8') . '</span>';
echo '<span class="small text-muted" id="ffDvPayCountText">' . ($count > 0
    ? '(' . (int) $count . ' Pos.)'
    : '(leer)') . '</span>';
echo '</div>';
echo '<div class="d-flex flex-wrap align-items-center gap-2 ff-dv-paybar-actions">';
if ($count > 0) {
    echo '<button type="button" class="btn btn-outline-primary btn-sm" id="ffDvCartOpenBtn" onclick="ffDvToggleCart(true); return false;">';
    echo 'Warenkorb <span class="badge bg-primary" id="ffDvCartBadge">' . (int) $count . '</span></button>';
    echo '<button type="button" class="btn btn-success ff-tap-fast" onclick="ffDvBezahlenConfirm(' . htmlspecialchars($arrayListe, ENT_QUOTES, 'UTF-8') . '); return false;">Bezahlen</button>';
} else {
    echo '<button type="button" class="btn btn-outline-secondary btn-sm" disabled>Warenkorb</button>';
    echo '<span class="btn btn-outline-secondary disabled" aria-disabled="true">Bezahlen</span>';
}
echo '</div></div>';
echo '<span id="ffDvPayIds" data-ids="' . htmlspecialchars($arrayListe, ENT_QUOTES, 'UTF-8') . '" class="d-none"></span>';
echo '</div></div>';
