<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';
?>
<?php
try {
    include __DIR__ . '/../include/db.php';

    $helpersPath = __DIR__ . '/../include/menu_tile_helpers.php';
    if (is_readable($helpersPath)) {
        require_once $helpersPath;
        ff_menu_ensure_schema($conn);
    }
    require_once __DIR__ . '/../include/ff_position_kassa_helpers.php';

    $hasSelbstkosten = false;
    $chkSk = @mysqli_query($conn, "SHOW COLUMNS FROM positionen LIKE 'selbstkosten'");
    if ($chkSk && mysqli_num_rows($chkSk) > 0) {
        $hasSelbstkosten = true;
    }

    $printTargets = [];
    $sqlPT = 'SELECT print_target, name FROM print_targets WHERE active=1 ORDER BY sort_order, name';
    $resPT = mysqli_query($conn, $sqlPT);
    if ($resPT) {
        while ($pt = mysqli_fetch_assoc($resPT)) {
            $printTargets[] = $pt;
        }
    }

    $defaultNewPt = 12;
    $found12 = false;
    foreach ($printTargets as $pt) {
        if ((int)$pt['print_target'] === 12) {
            $found12 = true;
            break;
        }
    }
    if (!$found12 && count($printTargets) > 0) {
        $defaultNewPt = (int)$printTargets[0]['print_target'];
    }

    $sql = 'SELECT * FROM positionen WHERE type=2 ORDER BY reihenfolge ASC, Positionsname';
    $result = mysqli_query($conn, $sql);
    ?>
    <div class="manage-fragment-header mb-4 pb-3 border-bottom">
        <h4 class="text-primary fw-semibold mb-1">Getränke · Kurzliste</h4>
        <p class="small text-muted mb-0">Schnellpflege wie bei Speisen, nur für Getränke (Typ 2).</p>
    </div>

    <form class="card border-0 bg-light mb-4" onsubmit="addMeal(); return false;">
        <div class="card-body py-3">
            <h6 class="fw-semibold mb-3">Neue Position</h6>
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="positionsname">Positionsname</label>
                    <input type="text" id="positionsname" class="form-control form-control-sm" autocomplete="off">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="kurzbezeichnung">Kurzbezeichnung</label>
                    <input type="text" id="kurzbezeichnung" class="form-control form-control-sm" autocomplete="off">
                </div>
                <input type="hidden" id="type" value="2">
                <div class="col-md-2">
                    <label class="form-label" for="print_target">Druckziel</label>
                    <select name="print_target" id="print_target" class="form-select form-select-sm">
                        <?php
                        if (count($printTargets) > 0) {
                            foreach ($printTargets as $pt) {
                                $pid = (int)$pt['print_target'];
                                $sel = ($pid === $defaultNewPt) ? 'selected' : '';
                                echo '<option value="' . $pid . '" ' . $sel . '>' . htmlspecialchars($pt['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                            }
                        } else {
                            echo '<option value="12" selected>Schank</option>';
                            echo '<option value="11">Küche</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="betrag">Betrag</label>
                    <input type="text" id="betrag" class="form-control form-control-sm" placeholder="0.00">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="selbstkosten_neu">EK (optional)</label>
                    <input type="text" id="selbstkosten_neu" class="form-control form-control-sm" placeholder="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="kapazitaet">Kapazität</label>
                    <input type="text" id="kapazitaet" class="form-control form-control-sm" value="-1" placeholder="-1 = ∞">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm">Anlegen</button>
                </div>
            </div>
        </div>
    </form>

    <h6 class="fw-semibold mb-2">Bestehende Getränke</h6>
    <div class="table-responsive border rounded">
    <table class="table table-sm table-hover align-middle mb-0" id="tischTable">
        <thead class="table-light">
            <tr>
                <th>Reihenfolge</th>
                <th>Positionsname</th>
                <th>Kurzbezeichnung</th>
                <th>Betrag</th>
                <th>EK</th>
                <th>Typ</th>
                <th>Druckziel</th>
                <th title="Nur Direktverkauf/Kasse">Nur Kasse</th>
                <th>Farbe</th>
                <th>Aktion</th>
            </tr>
        </thead>
        <tbody>
        <?php
        if ($result && mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                $rowid = (int)$row['rowid'];
                $reihenfolge = $row['reihenfolge'];
                $betrag = $row['Betrag'];
                $color = $row['color'];
                $ek = $hasSelbstkosten ? (float)($row['selbstkosten'] ?? 0) : 0;

                $positionsname_utf8 = (string)($row['Positionsname'] ?? '');
                $kurz_utf8 = (string)($row['Kurzbezeichnung'] ?? '');

                $positionsname_js = htmlspecialchars($positionsname_utf8, ENT_QUOTES, 'UTF-8');
                $kurz_js = htmlspecialchars($kurz_utf8, ENT_QUOTES, 'UTF-8');
                $reihenfolge_js = htmlspecialchars((string)$reihenfolge, ENT_QUOTES, 'UTF-8');
                $betrag_js = htmlspecialchars((string)$betrag, ENT_QUOTES, 'UTF-8');

                echo '<tr>';

                echo '<td>';
                echo '<input type="number" class="form-control form-control-sm ff-pos-field" style="width:4.5rem" min="0" '
                    . 'data-rowid="' . $rowid . '" data-field="reihenfolge" data-prev="' . htmlspecialchars((string)$reihenfolge, ENT_QUOTES, 'UTF-8') . '" '
                    . 'value="' . htmlspecialchars((string)$reihenfolge, ENT_QUOTES, 'UTF-8') . '" title="Beim Verlassen speichern">';
                echo '</td>';

                echo '<td>';
                echo '<input type="text" class="form-control form-control-sm ff-pos-field" '
                    . 'data-rowid="' . $rowid . '" data-field="Positionsname" data-prev="' . $positionsname_js . '" '
                    . 'value="' . $positionsname_js . '" title="Beim Verlassen speichern">';
                echo '</td>';

                echo '<td>';
                echo '<input type="text" class="form-control form-control-sm ff-pos-field" '
                    . 'data-rowid="' . $rowid . '" data-field="Kurzbezeichnung" data-prev="' . $kurz_js . '" '
                    . 'value="' . $kurz_js . '" title="Beim Verlassen speichern">';
                echo '</td>';

                echo '<td>';
                echo '<input type="text" class="form-control form-control-sm ff-pos-field" style="width:5.5rem" '
                    . 'data-rowid="' . $rowid . '" data-field="Betrag" data-prev="' . $betrag_js . '" '
                    . 'value="' . $betrag_js . '" title="Beim Verlassen speichern">';
                echo '</td>';

                echo '<td>';
                if ($hasSelbstkosten) {
                    echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="manageUpdateSelbstkosten(' . $rowid . ',' . $ek . ')">' . number_format($ek, 2, ',', '.') . ' €</button>';
                } else {
                    echo '<span class="text-muted">—</span>';
                }
                echo '</td>';

                echo '<td>';
                echo '<select class="form-select form-select-sm" onchange="updateType(' . $rowid . ', this.value)">';
                echo '<option value="1" ' . (((int)$row['type'] === 1) ? 'selected' : '') . '>Speise</option>';
                echo '<option value="2" ' . (((int)$row['type'] === 2) ? 'selected' : '') . '>Getränk</option>';
                echo '</select>';
                echo '</td>';

                $kassaOnly = ff_position_is_kassa_only($row);
                $curPtG = isset($row['print_target']) ? (int) $row['print_target'] : 12;
                echo '<td class="ff-print-target-cell">';
                echo '<select class="form-select form-select-sm ff-pos-print-target" data-rowid="' . $rowid . '" onchange="updatePrintTarget(' . $rowid . ', this.value)">';
                echo ff_manage_print_target_select_options($conn, $curPtG);
                echo '</select>';
                echo '</td>';

                echo '<td class="text-center">';
                echo '<input type="checkbox" class="form-check-input" onchange="updateKassaOnly(' . $rowid . ', this.checked)"' . ($kassaOnly ? ' checked' : '') . ' title="Nur Direktverkauf"></td>';

                echo '<td><input type="color" class="form-control form-control-color form-control-sm" onchange="farbeSpeiseSpeichern(' . $rowid . ')" id="html5colorpickerM' . $rowid . '" value="' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '" title="Kachelfarbe"></td>';

                echo '<td>';
                echo ' <button type="button" class="btn btn-sm btn-outline-danger py-0" onclick="deleteMeal(' . $rowid . ')">Löschen</button>';
                echo '</td>';

                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="10" class="text-muted">Keine Einträge.</td></tr>';
        }

        mysqli_close($conn);
        ?>
        </tbody>
    </table>
    </div>

<?php
} catch (Exception $e) {
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
?>
