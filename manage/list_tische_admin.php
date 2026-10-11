<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';
try {
    include __DIR__ . '/../include/db.php';
    include __DIR__ . '/../include/settings.php';
    require_once __DIR__ . '/../include/menu_list_helpers.php';
    require_once __DIR__ . '/../include/ff_schreibaus.php';

    $chkEh = @mysqli_query($conn, "SHOW COLUMNS FROM tische LIKE 'is_ehrengast'");
    if ($chkEh && mysqli_num_rows($chkEh) === 0) {
        @mysqli_query($conn, 'ALTER TABLE tische ADD COLUMN is_ehrengast TINYINT(1) NOT NULL DEFAULT 0');
    }
    $chkSm = @mysqli_query($conn, "SHOW COLUMNS FROM tische LIKE 'is_sammelrechnung'");
    if ($chkSm && mysqli_num_rows($chkSm) === 0) {
        @mysqli_query($conn, 'ALTER TABLE tische ADD COLUMN is_sammelrechnung TINYINT(1) NOT NULL DEFAULT 0');
    }

    $paymentMode = ff_aktiver_payment_mode($conn);
    $openCond = ff_schreibaus_open_sql_condition($paymentMode);

    $result = mysqli_query($conn, 'SELECT * FROM tische WHERE x>0 AND y>0 ORDER BY y,x');
    $tables = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $tables[] = $row;
        }
    }

    $ids = [];
    foreach ($tables as $t) {
        $ids[] = (int)$t['tischnummer'];
    }
    $open = [];
    foreach ($ids as $id) {
        $open[$id] = false;
    }
    if (count($ids) > 0) {
        $in = implode(',', $ids);
        $q = 'SELECT b.tischnummer, COUNT(*) AS cnt FROM bestellungen b
            INNER JOIN positionen p ON p.rowid = b.position
            WHERE b.tischnummer IN (' . $in . ') AND ' . $openCond . ' GROUP BY b.tischnummer';
        $r2 = mysqli_query($conn, $q);
        if ($r2) {
            while ($o = mysqli_fetch_assoc($r2)) {
                $open[(int)$o['tischnummer']] = ((int)$o['cnt'] > 0);
            }
        }
    }

    $byCell = [];
    $maxX = 0;
    $maxY = 0;
    foreach ($tables as $t) {
        $x = (int)$t['x'];
        $y = (int)$t['y'];
        $maxX = max($maxX, $x);
        $maxY = max($maxY, $y);
        $byCell[$x . '_' . $y] = $t;
    }
    $gridW = max(6, $maxX + 1);
    $gridH = max(4, $maxY + 1);

    $tileStyle = function ($t, $hasOpen) {
        $eh = (int)($t['is_ehrengast'] ?? 0);
        $sm = (int)($t['is_sammelrechnung'] ?? 0);
        if ($eh === 1) {
            return ['bg' => $hasOpen ? '#fae8ff' : '#ede9fe', 'cls' => 'tisch-draggable--ehren'];
        }
        if ($sm === 1) {
            return ['bg' => $hasOpen ? '#fef3c7' : '#dbeafe', 'cls' => 'tisch-draggable--sammel'];
        }
        if ($hasOpen) {
            return ['bg' => '#F5F599', 'cls' => ''];
        }
        return ['bg' => '#bbf7d0', 'cls' => ''];
    };

    mysqli_close($conn);
} catch (Exception $e) {
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    return;
}
?>

<div class="manage-fragment-header mb-4 pb-3 border-bottom">
    <h4 class="text-primary fw-semibold mb-1">Tische</h4>
    <p class="small text-muted mb-0">Raster mit Drag&nbsp;&amp;&nbsp;Drop; <strong>einfach in ein leeres weißes Feld klicken</strong>, um dort einen neuen Tisch anzulegen.</p>
</div>

<p class="small text-muted mb-3">
    <strong>Neuen Tisch anlegen:</strong> Klicken Sie auf ein leeres weißes Feld im Raster. Der Tisch wird dann automatisch erstellt.
</p>

<p class="tisch-admin-legend text-muted mb-2">
    <strong>Raster:</strong> Tische per Drag&nbsp;&amp;&nbsp;Drop verschieben; Name anklicken zum Umbenennen.
    Unter dem Namen: <strong>SR</strong> = Sammelrechnung, <strong>EG</strong> = Ehrengast (Klick, schließen sich aus).
    <span class="d-inline-block ms-2"><span class="badge" style="background:#bbf7d0;color:#111;">frei</span></span>
    <span class="d-inline-block ms-1"><span class="badge" style="background:#F5F599;color:#111;">offene Bestellung</span></span>
    <span class="d-inline-block ms-1"><span class="badge" style="background:#ede9fe;color:#111;">Ehrengast</span></span>
    <span class="d-inline-block ms-1"><span class="badge" style="background:#dbeafe;color:#111;">Sammelrechnung</span></span>
</p>

<div class="tisch-admin-grid-wrap">
    <div
        id="tischDragGrid"
        class="tisch-admin-grid"
        style="grid-template-columns: repeat(<?php echo (int)$gridW; ?>, minmax(72px, 1fr)); grid-template-rows: repeat(<?php echo (int)$gridH; ?>, minmax(52px, auto));"
    >
        <?php
        for ($gy = 1; $gy <= $gridH; $gy++) {
            for ($gx = 1; $gx <= $gridW; $gx++) {
                $key = $gx . '_' . $gy;
                $tbl = $byCell[$key] ?? null;
                $busy = $tbl !== null;
                $cellCls = 'tisch-admin-cell' . ($busy ? ' tisch-admin-cell--busy' : ' tisch-admin-cell--empty');
                $cellExtra = $busy ? '' : ' title="Klicken: Tisch hier anlegen" role="button" tabindex="0"';
                echo '<div class="' . htmlspecialchars($cellCls, ENT_QUOTES, 'UTF-8') . '" data-x="' . (int)$gx . '" data-y="' . (int)$gy . '"' . $cellExtra . '>';
                if ($tbl !== null) {
                    $tn = (int)$tbl['tischnummer'];
                    $hasOp = !empty($open[$tn]);
                    $st = $tileStyle($tbl, $hasOp);
                    $fc = trim((string)($tbl['color'] ?? ''));
                    if ($fc === '') {
                        $fc = '#000000';
                    }
                    if (strpos($fc, '#') !== 0) {
                        $fc = '#' . $fc;
                    }
                    $nameJs = htmlspecialchars(json_encode($tbl['tischname'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    $nameHtml = htmlspecialchars($tbl['tischname'], ENT_QUOTES, 'UTF-8');
                    $btnCls = 'tisch-draggable ' . $st['cls'];
                    $srOn = ((int)($tbl['is_sammelrechnung'] ?? 0) === 1);
                    $egOn = ((int)($tbl['is_ehrengast'] ?? 0) === 1);
                    echo '<div class="tisch-grid-stack">';
                    echo '<button type="button" class="' . htmlspecialchars(trim($btnCls), ENT_QUOTES, 'UTF-8') . '" draggable="true" '
                        . 'data-tischnummer="' . $tn . '" data-x="' . (int)$gx . '" data-y="' . (int)$gy . '" '
                        . 'data-tischname="' . htmlspecialchars((string)$tbl['tischname'], ENT_QUOTES, 'UTF-8') . '" '
                        . 'data-color="' . htmlspecialchars($fc, ENT_QUOTES, 'UTF-8') . '" '
                        . 'data-sr="' . ((int)($tbl['is_sammelrechnung'] ?? 0)) . '" '
                        . 'data-eg="' . ((int)($tbl['is_ehrengast'] ?? 0)) . '" '
                        . 'onclick="event.preventDefault(); event.stopPropagation(); ffOpenTischEditModal(this);" '
                        . 'style="background:' . htmlspecialchars($st['bg'], ENT_QUOTES, 'UTF-8') . ';color:' . htmlspecialchars($fc, ENT_QUOTES, 'UTF-8') . ';">'
                        . $nameHtml . '</button>';
                    echo '<button type="button" class="tisch-delete-btn" data-tischnummer="' . $tn . '" title="Tisch löschen" aria-label="Tisch löschen" '
                        . 'onclick="event.preventDefault(); event.stopPropagation(); deleteTable(' . $tn . ');">&times;</button>';
                    echo '<div class="tisch-grid-flags" role="group" aria-label="Tisch-Typ">';
                    echo '<button type="button" class="tisch-flag-toggle' . ($srOn ? ' tisch-flag-toggle--on' : '') . '" '
                        . 'data-flag="sr" data-tischnummer="' . $tn . '" title="Sammelrechnung-Tisch">SR</button>';
                    echo '<button type="button" class="tisch-flag-toggle' . ($egOn ? ' tisch-flag-toggle--on' : '') . '" '
                        . 'data-flag="eg" data-tischnummer="' . $tn . '" title="Ehrengast-Tisch">EG</button>';
                    echo '</div></div>';
                }
                echo '</div>';
            }
        }
        ?>
    </div>
</div>

<div class="modal fade" id="ffTischEditModal" tabindex="-1" aria-labelledby="ffTischEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="ffTischEditModalLabel">Tisch bearbeiten</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ffTischEditId">
                <div class="mb-3">
                    <label class="form-label" for="ffTischEditName">Name</label>
                    <input type="text" class="form-control form-control-sm" id="ffTischEditName">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="ffTischEditX">x</label>
                        <input type="number" class="form-control form-control-sm" id="ffTischEditX" min="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="ffTischEditY">y</label>
                        <input type="number" class="form-control form-control-sm" id="ffTischEditY" min="1">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="ffTischEditColor">Farbe</label>
                    <input type="color" class="form-control form-control-color form-control-sm" id="ffTischEditColor" value="#000000">
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="ffTischEditSr">
                    <label class="form-check-label" for="ffTischEditSr">Sammelrechnung</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="ffTischEditEg">
                    <label class="form-check-label" for="ffTischEditEg">Ehrengast</label>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-danger btn-sm" id="ffTischEditDeleteBtn">Tisch löschen</button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                <button type="button" class="btn btn-primary btn-sm" id="ffTischEditSaveBtn">Speichern</button>
            </div>
        </div>
    </div>
</div>

