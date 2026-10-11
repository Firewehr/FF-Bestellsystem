<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/ff_rezepturen_schema.php';

mysqli_set_charset($conn, 'utf8mb4');
if (function_exists('ff_rezepturen_ensure_schema')) {
    ff_rezepturen_ensure_schema($conn);
}

$positions = [];
$result = mysqli_query($conn, 'SELECT rowid, Positionsname, Kurzbezeichnung, type, maxBestellbar FROM positionen ORDER BY type, reihenfolge, Positionsname');
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $positions[] = $row;
    }
    @mysqli_free_result($result);
}

$hasBestandteileTable = function_exists('ff_rezepturen_table_exists') && ff_rezepturen_table_exists($conn, 'bestandteile');
$hasPositionRezepturenTable = function_exists('ff_rezepturen_table_exists') && ff_rezepturen_table_exists($conn, 'position_rezepturen');

$bestandteile = [];
if ($hasBestandteileTable) {
    $resultBestandteile = mysqli_query(
        $conn,
        'SELECT b.id, b.name, b.einheit, b.kapazitaet, b.aktiv, b.sort_order,
         COALESCE(GROUP_CONCAT(a.name ORDER BY a.sort_order, a.name SEPARATOR ", "), "") AS allergene
         FROM bestandteile b
         LEFT JOIN bestandteil_allergene ba ON ba.bestandteil_id = b.id
         LEFT JOIN allergene a ON a.id = ba.allergen_id
         GROUP BY b.id, b.name, b.einheit, b.kapazitaet, b.aktiv, b.sort_order
         ORDER BY b.aktiv DESC, b.sort_order, b.name'
    );
    if ($resultBestandteile) {
        while ($row = mysqli_fetch_assoc($resultBestandteile)) {
            $bestandteile[] = $row;
        }
        @mysqli_free_result($resultBestandteile);
    }
}

$positionRecipeGroups = [];
if ($hasPositionRezepturenTable) {
    $recipeGroupQuery = mysqli_query(
        $conn,
        'SELECT pr.position_id, pr.bestandteil_id, pr.menge, pr.reihenfolge, b.name, b.einheit
         FROM position_rezepturen pr
         LEFT JOIN bestandteile b ON b.id = pr.bestandteil_id
         ORDER BY pr.position_id, pr.reihenfolge, pr.id'
    );
    if ($recipeGroupQuery) {
        while ($row = mysqli_fetch_assoc($recipeGroupQuery)) {
            $pid = (int)($row['position_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $positionRecipeGroups[$pid][] = [
                'bestandteil_id' => (int)($row['bestandteil_id'] ?? 0),
                'name' => (string)($row['name'] ?? 'Unbekannt'),
                'einheit' => (string)($row['einheit'] ?? 'Stk'),
                'menge' => (string)($row['menge'] ?? '1'),
                'reihenfolge' => (int)($row['reihenfolge'] ?? 0),
            ];
        }
        @mysqli_free_result($recipeGroupQuery);
    }
}

$selectedPositionId = isset($_GET['position_id']) ? (int)$_GET['position_id'] : 0;
if ($selectedPositionId <= 0 && $positions !== []) {
    $selectedPositionId = (int)$positions[0]['rowid'];
}

$view = isset($_GET['view']) ? strtolower((string)$_GET['view']) : 'rezepturen';
$showBestandteileOnly = $view === 'bestandteile';
$showRezepturenOnly = $view === 'rezepturen';

$selectedPositionName = '';
$recipeRows = [];
if ($selectedPositionId > 0) {
    foreach ($positions as $position) {
        if ((int)$position['rowid'] === $selectedPositionId) {
            $selectedPositionName = (string)$position['Positionsname'];
            break;
        }
    }

    if ($hasPositionRezepturenTable) {
        $stmt = mysqli_prepare($conn, 'SELECT id, position_id, bestandteil_id, menge, optional_enabled, default_selected, option_group, reihenfolge, fest_id FROM position_rezepturen WHERE position_id = ? ORDER BY reihenfolge, id');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $selectedPositionId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $recipeRows[] = $row;
                }
                @mysqli_free_result($res);
            }
            mysqli_stmt_close($stmt);
        }
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
<p id="rezepturStatus" class="manage-rezeptur-status small text-muted" role="status" aria-live="polite">Bereit. Bestandteile werden automatisch gespeichert; Rezepturen erst mit „Speichern“.</p>

<?php if (!$showRezepturenOnly): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">Bestandteile <button type="button" class="btn btn-primary btn-sm" onclick="ffOpenNewBestandteilModal()">+ Neuer Bestandteil</button></div>
    <div class="card-body">
        <div id="ffNewBestandteilModal" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.5);overflow:auto;padding:1rem" onclick="if(event.target===this)ffCloseNewBestandteilModal()">
            <div class="bg-white rounded shadow mx-auto position-relative" style="max-width:520px;margin-top:5vh">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" aria-label="Schließen" onclick="ffCloseNewBestandteilModal()"></button>
                <form class="p-4" onsubmit="ffRezepturAddBestandteil(); return false;">
                    <h6 class="fw-semibold mb-3">Neuer Bestandteil</h6>
                    <div class="row g-3">
                <div class="col-12"><label class="form-label">Name</label><input type="text" id="rezepturNewBestandteilName" class="form-control" placeholder="z. B. Schnitzelstück" autocomplete="off"></div>
                <div class="col-12"><label class="form-label">Einheit</label><input type="text" id="rezepturNewBestandteilEinheit" class="form-control" value="Stk" maxlength="30" list="rezepturEinheitenList" autocomplete="off">
                        <datalist id="rezepturEinheitenList">
                            <?php
                            $einheitenVorschlag = [];
                            foreach (array_merge(['Stk', 'l', 'cl', 'dl', 'ml', 'kg', 'g', 'Portion'], array_column($bestandteile, 'einheit')) as $eh) {
                                $eh = trim((string)$eh);
                                if ($eh !== '' && !in_array($eh, $einheitenVorschlag, true)) {
                                    $einheitenVorschlag[] = $eh;
                                }
                            }
                            foreach ($einheitenVorschlag as $eh) {
                                echo '<option value="' . rez_h($eh) . '"></option>';
                            }
                            ?>
                        </datalist></div>
                <div class="col-12"><label class="form-label">Sortierung</label><input type="number" id="rezepturNewBestandteilSort" class="form-control" value="0"></div>
                <div class="col-12"><label class="form-label">Kapazität</label><input type="number" id="rezepturNewBestandteilKapazitaet" class="form-control" value="-1" title="-1 = unbegrenzt"></div>
                <div class="col-12"><label class="form-label">Allergene</label><input type="text" id="rezepturNewBestandteilAllergene" class="form-control" placeholder="z. B. Gluten, Milch"></div>
                        <div class="col-12 text-end"><button type="submit" class="btn btn-primary">Anlegen</button></div>
                    </div>
                </form>
            </div>
        </div>

        <div class="manage-bestandteile-tools d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <label class="visually-hidden" for="bestandteileFilter">Bestandteile suchen</label>
            <input type="search" id="bestandteileFilter" class="form-control form-control-sm manage-filter-input" placeholder="Bestandteile suchen …" oninput="ffFilterBestandteileRows()">
            <span id="bestandteileCount" class="small text-muted" aria-live="polite"></span>
        </div>
        <p class="small text-muted mb-2">Änderungen werden beim Verlassen eines Feldes oder kurz nach der Eingabe automatisch gespeichert.</p>

        <div class="table-responsive border rounded">
            <table id="bestandteileTable" class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Einheit</th>
                        <th>Kapazität</th>
                        <th>Sort</th>
                        <th>Allergene</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($bestandteile === []): ?>
                        <tr><td colspan="5" class="text-muted">Noch keine Bestandteile angelegt.</td></tr>
                    <?php else: ?>
                        <?php foreach ($bestandteile as $bestandteil): ?>
                            <tr data-bestandteil-id="<?php echo (int)$bestandteil['id']; ?>">
                                <td><input type="text" class="form-control form-control-sm ff-bestandteil-name" value="<?php echo rez_h((string)$bestandteil['name']); ?>"></td>
                                <td><input type="text" class="form-control form-control-sm ff-bestandteil-einheit" value="<?php echo rez_h((string)($bestandteil['einheit'] ?? 'Stk')); ?>" maxlength="30"></td>
                                <td><input type="number" class="form-control form-control-sm ff-bestandteil-kapazitaet" value="<?php echo (int)($bestandteil['kapazitaet'] ?? -1); ?>" title="-1 = unbegrenzt"></td>
                                <td><input type="number" class="form-control form-control-sm ff-bestandteil-sort" value="<?php echo (int)($bestandteil['sort_order'] ?? 0); ?>"></td>
                                <td><input type="text" class="form-control form-control-sm ff-bestandteil-allergene" value="<?php echo rez_h((string)($bestandteil['allergene'] ?? '')); ?>" placeholder="z. B. Gluten, Milch"></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p id="bestandteileNoResults" class="small text-muted mt-2 mb-0" hidden>Keine passenden Bestandteile gefunden.</p>
    </div>
</div>
<?php endif; ?>

<?php if (!$showBestandteileOnly): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-light fw-semibold">Positionen mit Bestandteilen</div>
    <div class="card-body">
        <?php if ($positions === []): ?>
            <div class="text-muted small">Keine Positionen vorhanden.</div>
        <?php else: ?>
            <div class="manage-rezeptur-tools mb-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <label class="visually-hidden" for="rezepturPositionFilter">Positionen und Bestandteile suchen</label>
                    <input type="search" id="rezepturPositionFilter" class="form-control form-control-sm manage-filter-input" placeholder="Position oder Bestandteil suchen …" oninput="ffFilterRezepturPositions()">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ffSetRezepturPositionsExpanded(true)">Alle aufklappen</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ffSetRezepturPositionsExpanded(false)">Alle zuklappen</button>
                </div>
                <p id="rezepturPositionCount" class="small text-muted mt-2 mb-0" aria-live="polite"></p>
            </div>
            <div id="rezepturPositionList">
            <?php foreach ($positions as $position): ?>
                <?php
                    $pid = (int)$position['rowid'];
                    $positionLabel = ((int)$position['type'] === 2 ? '[G] ' : '[S] ') . (string)$position['Positionsname'];
                    if (!empty($position['Kurzbezeichnung'])) {
                        $positionLabel .= ' · ' . (string)$position['Kurzbezeichnung'];
                    }
                    if ((int)$position['maxBestellbar'] > 0) {
                        $positionLabel .= ' (' . (int)$position['maxBestellbar'] . ')';
                    }
                    $assignedRows = $positionRecipeGroups[$pid] ?? [];
                    $recipeSearchText = $positionLabel;
                    foreach ($assignedRows as $assignedRow) {
                        $recipeSearchText .= ' ' . (string)$assignedRow['name'];
                    }
                ?>
                <details class="manage-rezeptur-position-block border rounded mb-2" data-search="<?php echo rez_h($recipeSearchText); ?>">
                    <summary class="manage-rezeptur-position-summary">
                        <span class="fw-semibold"><?php echo rez_h($positionLabel); ?></span>
                        <span class="badge text-bg-light"><?php echo count($assignedRows); ?> Bestandteile</span>
                    </summary>
                    <div class="manage-rezeptur-position-content">
                    <div class="manage-rezeptur-position-head d-flex justify-content-end align-items-center gap-2 mb-2">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" onclick="ffRezepturAddGroupRow(<?php echo $pid; ?>);">+ Komponente</button>
                            <button type="button" class="btn btn-primary" onclick="ffRezepturSaveGroup(<?php echo $pid; ?>, document.getElementById('rezepturGroupTable_<?php echo $pid; ?>'));
                            ">Speichern</button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table id="rezepturGroupTable_<?php echo $pid; ?>" data-position-id="<?php echo $pid; ?>" class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:32%">Komponente</th>
                                    <th style="width:12%">Menge</th>
                                    <th style="width:10%">Optional</th>
                                    <th style="width:10%">Standard</th>
                                    <th style="width:18%">Gruppe</th>
                                    <th style="width:10%">Sort</th>
                                    <th style="width:8%" title="Löschen" aria-label="Löschen">&#128465;</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($assignedRows === []): ?>
                                    <tr><td colspan="7" class="text-muted">Keine Bestandteile zugewiesen.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($assignedRows as $assignedRow): ?>
                                        <tr data-group-row="1">
                                            <td>
                                                <select class="form-select form-select-sm ff-rez-group-comp">
                                                    <option value="0">— Komponente wählen —</option>
                                                    <?php foreach ($bestandteile as $bestandteil): ?>
                                                        <?php
                                                            $bid = (int)$bestandteil['id'];
                                                            $label = (string)$bestandteil['name'];
                                                            $einheit = (string)($bestandteil['einheit'] ?? '');
                                                            $kap = (int)($bestandteil['kapazitaet'] ?? -1);
                                                            if ($einheit !== '') {
                                                                $label .= ' [' . $einheit . ']';
                                                            }
                                                            if ($kap >= 0) {
                                                                $label .= ' (Kap: ' . $kap . ')';
                                                            }
                                                        ?>
                                                        <option value="<?php echo $bid; ?>"<?php echo $bid === (int)($assignedRow['bestandteil_id'] ?? 0) ? ' selected' : ''; ?>><?php echo rez_h($label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td><input type="number" step="0.001" min="0.001" class="form-control form-control-sm ff-rez-group-qty" value="<?php echo rez_h((string)($assignedRow['menge'] ?? '1')); ?>"></td>
                                            <td class="text-center"><input type="checkbox" class="form-check-input ff-rez-group-optional"></td>
                                            <td class="text-center"><input type="checkbox" class="form-check-input ff-rez-group-default" checked></td>
                                            <td><input type="text" maxlength="64" class="form-control form-control-sm ff-rez-group-option" value="" placeholder="z. B. brotwahl"></td>
                                            <td><input type="number" step="1" class="form-control form-control-sm ff-rez-group-order" value="<?php echo (int)($assignedRow['reihenfolge'] ?? 0); ?>"></td>
                                            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger ff-rez-group-del ff-icon-action" title="Optionsgruppe löschen" aria-label="Optionsgruppe löschen">&#128465;</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    </div>
                </details>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php if (!$showBestandteileOnly): ?>
<div class="alert alert-info small mb-0">
    <strong>Hinweis:</strong> Bestandteile werden zuerst angelegt und mit einer Kapazität versehen. Danach werden sie in der Rezeptur einer Position mit der passenden Menge zugeordnet. Für Halbanteile nutze Dezimalwerte wie <code>0.500</code>.
    Die Zielposition bleibt in <code>positionen</code>; die Rezeptur-Komponenten kommen aus der eigenen Tabelle <code>bestandteile</code>.
    Optional + Standard erlaubt „mit/ohne“; gleiche Gruppe (z. B. <code>brotwahl</code>) bildet Entweder/Oder im Bestell-Dialog.
</div>
<?php endif; ?>
