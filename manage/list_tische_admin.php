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
    <p class="small text-muted mb-0">Raster mit Drag&nbsp;&amp;&nbsp;Drop; <strong>leeres Feld anklicken</strong> legt dort einen Tisch an. Oben alternativ Name und Koordinaten eintragen.</p>
</div>

<div class="mb-3">
    <h5 class="mb-2 fw-semibold">Neuen Tisch anlegen</h5>
    <form class="row g-2 align-items-end" id="ffNeuerTischForm" onsubmit="event.preventDefault(); if (typeof addTable === 'function') { addTable(); } return false;">
        <div class="col-md-4">
            <label class="form-label mb-0" for="tischname">Tischname</label>
            <input type="text" class="form-control form-control-sm" id="tischname" name="tischname" autocomplete="off">
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label mb-0" for="x">Spalte (x)</label>
            <input type="number" class="form-control form-control-sm" id="x" name="x" min="1" value="1">
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label mb-0" for="y">Zeile (y)</label>
            <input type="number" class="form-control form-control-sm" id="y" name="y" min="1" value="1">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm">Anlegen</button>
        </div>
    </form>
</div>

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
                        . 'onclick="updateTischname(' . $nameJs . ',' . $tn . ')" '
                        . 'style="background:' . htmlspecialchars($st['bg'], ENT_QUOTES, 'UTF-8') . ';color:' . htmlspecialchars($fc, ENT_QUOTES, 'UTF-8') . ';">'
                        . $nameHtml . '</button>';
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

<h6 class="mt-4 mb-2 fw-semibold">Farbe, Sammelrechnung, Ehrengast &amp; Löschen</h6>
<p class="small text-muted mb-2">Sammelrechnung und Ehrengast schließen sich aus und werden beim Anklicken sofort gespeichert.</p>
<div class="table-responsive border rounded">
<table class="table table-sm table-hover align-middle mb-0" id="tischTable">
    <thead>
        <tr>
            <th>Name</th>
            <th>x</th>
            <th>y</th>
            <th>Sammelrechnung</th>
            <th>Ehrengast</th>
            <th>Farbe</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php
        foreach ($tables as $row) {
            $tid = (int)$row['tischnummer'];
            $cv = trim((string)($row['color'] ?? ''));
            if ($cv === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $cv)) {
                $cv = '#000000';
            }
            if (strpos($cv, '#') !== 0 && preg_match('/^[0-9A-Fa-f]{6}$/', $cv)) {
                $cv = '#' . $cv;
            }
            echo '<tr>';
            echo '<td><button type="button" class="btn btn-link btn-sm p-0 text-start" onclick="updateTischname('
                . htmlspecialchars(json_encode($row['tischname'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8')
                . ',' . $tid . ')">' . htmlspecialchars($row['tischname'], ENT_QUOTES, 'UTF-8') . '</button></td>';
            echo '<td>' . (int)$row['x'] . '</td>';
            echo '<td>' . (int)$row['y'] . '</td>';
            $sm = (int)($row['is_sammelrechnung'] ?? 0);
            $eh = (int)($row['is_ehrengast'] ?? 0);
            echo '<td><input type="checkbox" class="form-check-input ff-tisch-flag" data-tid="' . $tid . '" data-flag="sr" id="sr_' . $tid . '" ' . ($sm === 1 ? 'checked' : '') . ' aria-label="Sammelrechnung"></td>';
            echo '<td><input type="checkbox" class="form-check-input ff-tisch-flag" data-tid="' . $tid . '" data-flag="eg" id="eg_' . $tid . '" ' . ($eh === 1 ? 'checked' : '') . ' aria-label="Ehrengast"></td>';
            echo '<td><input type="color" class="form-control form-control-color form-control-sm" onchange="farbeSpeichern('
                . $tid . ')" id="html5colorpicker' . $tid . '" value="' . htmlspecialchars($cv, ENT_QUOTES, 'UTF-8') . '"></td>';
            echo '<td class="text-nowrap"><button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteTable(' . $tid . ')">löschen</button></td>';
            echo '</tr>';
        }
        if (count($tables) === 0) {
            echo '<tr><td colspan="7" class="text-muted">Keine Tische mit Koordinaten.</td></tr>';
        }
        ?>
    </tbody>
</table>
</div>
