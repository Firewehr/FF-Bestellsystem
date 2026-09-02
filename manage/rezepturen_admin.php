<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/ff_rezepturen_schema.php';

mysqli_set_charset($conn, 'utf8mb4');
ff_rezepturen_ensure_schema($conn);

$positions = [];
$result = mysqli_query($conn, 'SELECT rowid, Positionsname, Kurzbezeichnung, type, maxBestellbar FROM positionen ORDER BY type, reihenfolge, Positionsname');
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $positions[] = $row;
    }
}

$selectedPositionId = isset($_GET['position_id']) ? (int)$_GET['position_id'] : 0;
if ($selectedPositionId <= 0 && $positions !== []) {
    $selectedPositionId = (int)$positions[0]['rowid'];
}

$selectedPositionName = '';
$recipeRows = [];
if ($selectedPositionId > 0) {
    foreach ($positions as $position) {
        if ((int)$position['rowid'] === $selectedPositionId) {
            $selectedPositionName = (string)$position['Positionsname'];
            break;
        }
    }

    $stmt = mysqli_prepare($conn, 'SELECT id, position_id, bestandteil_position_id, menge, reihenfolge, fest_id FROM position_rezepturen WHERE position_id = ? ORDER BY reihenfolge, id');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $selectedPositionId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $recipeRows[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
}

function rez_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<div class="manage-fragment-header mb-4 pb-3 border-bottom">
    <h4 class="text-primary fw-semibold mb-1">Rezepturen</h4>
    <p class="small text-muted mb-0">Hier werden zusammengesetzte Positionen gepflegt, z. B. 0.5 + 0.5 für Radler oder 2 Schnitzel + 1 Salat.</p>
</div>

<div class="row g-2 mb-3 align-items-end">
    <div class="col-md-6">
        <label class="form-label">Zielposition</label>
        <select id="rezepturPosition" class="form-select form-select-sm" onchange="ffRezepturLoad(this.value);">
            <option value="0">— bitte wählen —</option>
            <?php foreach ($positions as $position): ?>
                <?php
                    $pid = (int)$position['rowid'];
                    $label = ((int)$position['type'] === 2 ? '[G] ' : '[S] ') . (string)$position['Positionsname'];
                    if (!empty($position['Kurzbezeichnung'])) {
                        $label .= ' · ' . (string)$position['Kurzbezeichnung'];
                    }
                    if ((int)$position['maxBestellbar'] > 0) {
                        $label .= ' (' . (int)$position['maxBestellbar'] . ')';
                    }
                ?>
                <option value="<?php echo $pid; ?>"<?php echo $pid === $selectedPositionId ? ' selected' : ''; ?>><?php echo rez_h($label); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="ffRezepturAddRow();">Zeile hinzufügen</button>
    </div>
    <div class="col-md-3">
        <button type="button" class="btn btn-primary btn-sm w-100" onclick="ffRezepturSave();">Speichern</button>
    </div>
</div>

<div id="rezepturStatus" class="small text-muted mb-3">
    <?php echo $selectedPositionId > 0 ? 'Bearbeite Rezeptur für ' . rez_h($selectedPositionName) : 'Bitte eine Position wählen.'; ?>
</div>

<div class="table-responsive border rounded mb-3">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th style="width:50%">Komponente</th>
                <th style="width:18%">Menge</th>
                <th style="width:12%">Reihenfolge</th>
                <th style="width:20%">Aktion</th>
            </tr>
        </thead>
        <tbody id="rezepturenTbody">
            <?php if ($recipeRows === []): ?>
                <tr><td colspan="4" class="text-muted">Noch keine Komponenten hinterlegt.</td></tr>
            <?php else: ?>
                <?php foreach ($recipeRows as $row): ?>
                    <tr data-rowid="<?php echo (int)$row['id']; ?>">
                        <td>
                            <select class="form-select form-select-sm ff-rez-comp">
                                <option value="0">— Komponente wählen —</option>
                                <?php foreach ($positions as $position): ?>
                                    <?php
                                        $pid = (int)$position['rowid'];
                                        $label = ((int)$position['type'] === 2 ? '[G] ' : '[S] ') . (string)$position['Positionsname'];
                                        if (!empty($position['Kurzbezeichnung'])) {
                                            $label .= ' · ' . (string)$position['Kurzbezeichnung'];
                                        }
                                    ?>
                                    <option value="<?php echo $pid; ?>"<?php echo $pid === (int)$row['bestandteil_position_id'] ? ' selected' : ''; ?>><?php echo rez_h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="number" step="0.001" min="0.001" class="form-control form-control-sm ff-rez-qty" value="<?php echo rez_h((string)$row['menge']); ?>"></td>
                        <td><input type="number" step="1" class="form-control form-control-sm ff-rez-order" value="<?php echo (int)$row['reihenfolge']; ?>"></td>
                        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger ff-rez-del">Löschen</button></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="alert alert-info small mb-0">
    <strong>Hinweis:</strong> Die Menge ist der Verbrauch pro 1x Zielposition. Für Halbanteile nutze Dezimalwerte wie <code>0.500</code>.
    Zielposition und Komponenten dürfen aus den vorhandenen Positionen gewählt werden, ohne am restlichen Bestellschema etwas zu ändern.
</div>
