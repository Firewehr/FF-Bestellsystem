<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
require_once __DIR__ . '/include/ff_bestellung_storno.php';
require_once __DIR__ . '/include/menu_list_helpers.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_finance_bereich_helpers.php';
require_once __DIR__ . '/include/ff_hist_navigation.php';

ff_direktverkauf_require($conn, false);

$isAdmin = !empty($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1;
$Tischnummer = 999999;
$bonFilter = trim((string) ($_GET['bon_id'] ?? ''));
if ($bonFilter !== '') {
    $bonNorm = ff_direktverkauf_normalize_bon_id($bonFilter);
    $bonFilter = $bonNorm !== '' ? $bonNorm : $bonFilter;
}
$kellnerFilter = trim((string) ($_GET['kellner'] ?? ''));
$scopeSql = ff_direktverkauf_history_scope_sql($conn, $isAdmin, 'b', $kellnerFilter);
$bonSql = $bonFilter !== '' ? ff_direktverkauf_bon_filter_sql($conn, $bonFilter, 'b') : '';
$limit = 250;

$kellnerList = [];
if ($isAdmin) {
    $resK = mysqli_query(
        $conn,
        "SELECT DISTINCT COALESCE(NULLIF(TRIM(b.kellnerZahlung), ''), TRIM(b.kellner)) AS k
            FROM bestellungen b
            WHERE b.tischnummer = {$Tischnummer}
            AND (TRIM(b.kellner) <> '' OR TRIM(b.kellnerZahlung) <> '')
            ORDER BY k"
    );
    while ($resK && ($rk = mysqli_fetch_assoc($resK))) {
        $k = trim((string) ($rk['k'] ?? ''));
        if ($k !== '') {
            $kellnerList[] = $k;
        }
    }
}

$amt = 'COALESCE(NULLIF(b.betrag, 0), p.Betrag)';
$sql = "SELECT b.rowid, b.zeitstempel, b.timestampBezahlung, b.`delete`, b.ausgeliefert,
        b.settlement_id, b.bon_id, b.Zusatzinfo, b.kellner, b.kellnerZahlung,
        p.Positionsname, p.type AS pos_type, {$amt} AS line_amt
    FROM bestellungen b
    JOIN positionen p ON p.rowid = b.position
    WHERE b.tischnummer = {$Tischnummer}{$scopeSql}{$bonSql}
    ORDER BY b.bon_id DESC, b.rowid DESC
    LIMIT {$limit}";

$rows = [];
$sqlError = '';
$res = mysqli_query($conn, $sql);
if (!$res) {
    $sqlError = mysqli_error($conn);
} else {
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
}

/** Nach Bon gruppieren (neueste Bon zuerst). */
$bonGroups = [];
$bonGroupOrder = [];
foreach ($rows as $r) {
    $bid = trim((string) ($r['bon_id'] ?? ''));
    $gk = $bid !== '' ? $bid : ('_einzel_' . (int) ($r['rowid'] ?? 0));
    if (!isset($bonGroups[$gk])) {
        $bonGroups[$gk] = [];
        $bonGroupOrder[] = $gk;
    }
    $bonGroups[$gk][] = $r;
}

function ff_dv_hist_eur(float $amount): string
{
    return number_format($amount, 2, ',', '.') . ' €';
}

function ff_dv_hist_typ_label(int $type): string
{
    if ($type === 1) {
        return 'Speise';
    }
    if ($type === 2) {
        return 'Getränk';
    }

    return '—';
}

function ff_dv_hist_status_badge(array $r): string
{
    if ((int) ($r['delete'] ?? 0) === 1) {
        return '<span class="badge bg-secondary">Storniert</span>';
    }
    $bez = trim((string) ($r['timestampBezahlung'] ?? ''));
    if ($bez === '' || $bez === '0000-00-00 00:00:00') {
        return '<span class="badge bg-warning text-dark">Offen</span>';
    }
    if ((int) ($r['settlement_id'] ?? 0) > 0) {
        return '<span class="badge bg-info text-dark">Abgerechnet</span>';
    }

    return '<span class="badge bg-success">Bezahlt</span>';
}

function ff_dv_hist_storno_btn(array $r, bool $isAdmin): string
{
    if ((int) ($r['delete'] ?? 0) === 1) {
        return '';
    }
    $rid = (int) ($r['rowid'] ?? 0);
    if ($rid <= 0) {
        return '';
    }
    $isPaid = ff_bestellung_row_is_paid($r);
    $stillOpen = !ff_bestellung_row_is_delivered($r);

    if ($isPaid && !$isAdmin) {
        return '<span class="small text-muted">Admin</span>';
    }
    if (!$isPaid && !$isAdmin) {
        return '<button type="button" class="btn btn-sm btn-outline-danger" onclick="ffDvHistRemoveOpen(' . $rid . '); return false;">Entfernen</button>';
    }

    if ($isPaid && $stillOpen) {
        return '<button type="button" class="btn btn-sm btn-outline-danger" onclick="ffDvHistStorno(' . $rid . ', true, true); return false;">Stornieren</button>';
    }
    if ($isPaid) {
        return '<button type="button" class="btn btn-sm btn-outline-warning" onclick="ffDvHistStorno(' . $rid . ', true, false); return false;">Zahlung stornieren</button>';
    }

    return '<button type="button" class="btn btn-sm btn-outline-danger" onclick="ffDvHistStorno(' . $rid . ', false, false); return false;">Stornieren</button>';
}

function ff_dv_hist_kellner_label(mysqli $conn, array $r): string
{
    $kz = trim((string) ($r['kellnerZahlung'] ?? ''));
    $k = $kz !== '' ? $kz : trim((string) ($r['kellner'] ?? ''));
    if ($k === '') {
        return '—';
    }

    return ff_finance_kellner_label($conn, $k);
}

?>
<nav class="navbar app-navbar sticky-top mb-3">
    <div class="container-fluid">
        <a href="#Direktverkauf" class="btn btn-outline-secondary btn-sm" onclick="Direktverkauf(); return false;">← Direktverkauf</a>
        <span class="navbar-brand mb-0">Historie Direktverkauf</span>
    </div>
</nav>

<div class="px-2 pb-4">
    <?php if ($isAdmin): ?>
    <div class="alert alert-info py-2 small mb-3">
        <strong>Admin:</strong> Hier siehst du <em>alle</em> Kassa-Mitarbeiter (nicht nur deinen eigenen Warenkorb).
        Bezahlte Zeilen kannst du stornieren. Ausführlicher Filter:
        <a href="bestell_history.php?table=<?php echo $Tischnummer; ?>&amp;abrechnung=alle" class="alert-link">Bestell-History → Direktverkauf</a>.
    </div>
    <form method="get" class="row g-2 align-items-end mb-3 bg-light border rounded p-2" id="ffDvHistFilterForm" onsubmit="return (typeof ffDvHistFilterSubmit === 'function' ? ffDvHistFilterSubmit(event) : true);">
        <div class="col-md-4">
            <label class="form-label small mb-0">Kassa-Mitarbeiter</label>
            <select name="kellner" class="form-select form-select-sm">
                <option value="">— alle —</option>
                <?php foreach ($kellnerList as $k):
                    $kLbl = ff_finance_kellner_label($conn, $k);
                    ?>
                <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $kellnerFilter === $k ? ' selected' : ''; ?>><?php echo htmlspecialchars($kLbl !== $k ? $kLbl . ' (' . $k . ')' : $k, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-0">Bon-Nr. (optional)</label>
            <input type="text" name="bon_id" class="form-control form-control-sm" value="<?php echo htmlspecialchars($bonFilter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="z. B. 28-004 oder #28-004">
        </div>
        <div class="col-md-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filtern</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="DirektHistory(); return false;">Zurücksetzen</button>
        </div>
    </form>
    <?php else: ?>
    <p class="small text-muted mb-2">
        Alle Direktverkauf-Bons (wie Tisch-Historie am Tisch)<?php echo $bonFilter !== '' ? ' · Filter Bon #' . htmlspecialchars($bonFilter, ENT_QUOTES, 'UTF-8') : ''; ?>.
        Bezahlte Zeilen: <strong>Storno nur Admin</strong>; offene Positionen kannst du entfernen.
    </p>
    <form method="get" class="row g-2 align-items-end mb-3" id="ffDvHistFilterForm" onsubmit="return (typeof ffDvHistFilterSubmit === 'function' ? ffDvHistFilterSubmit(event) : true);">
        <div class="col-auto">
            <label class="form-label small mb-0">Bon-Nr. (optional)</label>
            <input type="text" name="bon_id" class="form-control form-control-sm" value="<?php echo htmlspecialchars($bonFilter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="z. B. 28-004">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-outline-primary">Filtern</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="DirektHistory(); return false;">Alle</button>
        </div>
    </form>
    <?php endif; ?>

    <?php if (!empty($sqlError)): ?>
        <div class="alert alert-danger mb-0">Datenbankfehler beim Laden der Historie.</div>
    <?php elseif ($rows === []): ?>
        <div class="alert alert-light border mb-0">Keine Einträge<?php
            if ($kellnerFilter !== '') {
                echo ' für Kassa-Mitarbeiter <strong>' . htmlspecialchars(ff_finance_kellner_label($conn, $kellnerFilter), ENT_QUOTES, 'UTF-8') . '</strong>';
            }
            if ($bonFilter !== '') {
                echo ($kellnerFilter !== '' ? ' und' : ' für') . ' Bon <strong>' . htmlspecialchars($bonFilter, ENT_QUOTES, 'UTF-8') . '</strong>';
            }
        ?>.</div>
    <?php else: ?>
        <p class="small text-muted mb-2"><?php echo count($bonGroupOrder); ?> Bon(s) · <?php echo count($rows); ?> Position(en)</p>
        <div class="d-flex flex-column gap-3">
        <?php foreach ($bonGroupOrder as $gk):
            $groupRows = $bonGroups[$gk];
            $isBonGroup = strpos($gk, '_einzel_') !== 0;
            $bonId = $isBonGroup ? $gk : '';
            $grpSum = 0.0;
            $grpKel = '';
            foreach ($groupRows as $gr) {
                if ((int) ($gr['delete'] ?? 0) === 0) {
                    $grpSum += (float) ($gr['line_amt'] ?? 0);
                }
                if ($grpKel === '') {
                    $grpKel = ff_dv_hist_kellner_label($conn, $gr);
                }
            }
            $detailActs = $isBonGroup
                ? ff_hist_detail_actions_html($Tischnummer, 0, $bonId, 'dv')
                : '';
            ?>
            <div class="border rounded-3 bg-white overflow-hidden">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 bg-light border-bottom">
                    <?php if ($isBonGroup): ?>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php if ($detailActs !== ''): ?>
                        <?php echo $detailActs; ?>
                        <?php else: ?>
                        <span class="badge bg-dark fs-6">Bon #<?php echo htmlspecialchars($bonId, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <span class="badge bg-secondary"><?php echo count($groupRows); ?> Pos.</span>
                        <span class="small text-muted">Summe: <strong><?php echo ff_dv_hist_eur($grpSum); ?></strong></span>
                        <?php if ($grpKel !== '—'): ?>
                        <span class="small text-muted">Kassa: <?php echo htmlspecialchars($grpKel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span class="fw-semibold small text-muted">Ohne Bon-Nr.</span>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Zeit</th>
                                <?php if ($isAdmin && !$isBonGroup): ?><th>Kassa</th><?php endif; ?>
                                <?php if (!$isBonGroup): ?><th>Bon</th><?php endif; ?>
                                <th>Art</th>
                                <th>Position</th>
                                <th class="text-end">Betrag</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($groupRows as $r):
                            $rid = (int) ($r['rowid'] ?? 0);
                            $amtVal = (float) ($r['line_amt'] ?? 0);
                            $trCls = (int) ($r['delete'] ?? 0) === 1 ? 'table-secondary text-muted' : '';
                            $typ = (int) ($r['pos_type'] ?? -1);
                            ?>
                            <tr class="<?php echo $trCls; ?>">
                                <td><?php echo $rid; ?></td>
                                <td class="text-nowrap small"><?php echo htmlspecialchars(substr((string) ($r['zeitstempel'] ?? ''), 0, 16), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php if ($isAdmin && !$isBonGroup): ?>
                                <td class="small"><?php echo htmlspecialchars(ff_dv_hist_kellner_label($conn, $r), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endif; ?>
                                <?php if (!$isBonGroup): ?>
                                <td class="small"><?php echo htmlspecialchars(trim((string) ($r['bon_id'] ?? '')) ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endif; ?>
                                <td><span class="badge <?php echo $typ === 1 ? 'bg-success' : 'bg-danger'; ?>"><?php echo ff_dv_hist_typ_label($typ); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($r['Positionsname'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (trim((string) ($r['Zusatzinfo'] ?? '')) !== ''): ?>
                                        <span class="small text-muted d-block"><?php echo htmlspecialchars((string) $r['Zusatzinfo'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?php echo ff_dv_hist_eur($amtVal); ?></td>
                                <td><?php echo ff_dv_hist_status_badge($r); ?></td>
                                <td class="text-nowrap"><?php echo ff_dv_hist_storno_btn($r, $isAdmin); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
