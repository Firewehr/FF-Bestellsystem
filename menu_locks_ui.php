<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/ff_favicon_helpers.php';
header('Cache-Control: no-cache');

/** Nur Fragment für loadContent (Küche/Schank/Druckziel/manage) – ohne Layout */
$embed = isset($_GET['embed']) && $_GET['embed'] === '1';

$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$scriptDir = str_replace('\\', '/', (string)$scriptDir);
if ($scriptDir === '/' || $scriptDir === '.') {
    $scriptDir = '';
}
$menuLockApiUrl = rtrim($scriptDir, '/') . '/menu_lock_api.php';

ob_start();
?>
<div class="container-fluid py-3" id="menuLocksUiRoot" data-menu-lock-api="<?php echo htmlspecialchars($menuLockApiUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 pb-2 border-bottom">
        <button type="button" class="btn btn-secondary btn-sm" id="mlBtnClosePanel">Fertig</button>
        <span class="small text-muted">Liste aktualisiert erst wieder nach „Fertig“ (Auto-Reload pausiert).</span>
    </div>
    <h5 class="mb-3">Speisekarte: Positionen sperren</h5>
    <p class="text-muted small">Einzelne Positionen oder alle Speisen / alle Getränke sperren. Bei „alle Speisen“ können einzelne Positionen als Ausnahme weiter bestellbar bleiben (z. B. Hendl).</p>

    <div class="card mb-3 shadow-sm">
        <div class="card-header fw-semibold">Aktive Sperren</div>
        <div class="card-body p-2" id="menuLocksList">
            <p class="text-muted mb-0">Laden…</p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card h-100 shadow-sm">
                <div class="card-header fw-semibold">Eine Position sperren</div>
                <div class="card-body">
                    <label class="form-label">Position</label>
                    <select class="form-select form-select-sm mb-2" id="mlSinglePos"></select>
                    <label class="form-label">Dauer</label>
                    <select class="form-select form-select-sm mb-2" id="mlSingleDur">
                        <option value="-1">Bis manuell aufgehoben</option>
                        <option value="15">15 Minuten</option>
                        <option value="30">30 Minuten</option>
                        <option value="60">1 Stunde</option>
                        <option value="90">1,5 Stunden</option>
                        <option value="120">2 Stunden</option>
                        <option value="180">3 Stunden</option>
                    </select>
                    <label class="form-label">Info / Grund</label>
                    <select class="form-select form-select-sm mb-2 ml-reason-select" id="mlSingleReasonSel" data-target="mlSingleReason"></select>
                    <input type="text" class="form-control form-control-sm mb-2 d-none" id="mlSingleReason" placeholder="z. B. Küche überlastet">
                    <button type="button" class="btn btn-warning btn-sm" id="mlBtnSingle">Sperren</button>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 shadow-sm">
                <div class="card-header fw-semibold">Alle Speisen oder alle Getränke</div>
                <div class="card-body">
                    <label class="form-label">Kategorie</label>
                    <select class="form-select form-select-sm mb-2" id="mlTypeAll">
                        <option value="1">Alle Speisen (type 1)</option>
                        <option value="2">Alle Getränke (type 2)</option>
                    </select>
                    <label class="form-label">Dauer</label>
                    <select class="form-select form-select-sm mb-2" id="mlTypeDur">
                        <option value="-1">Bis manuell aufgehoben</option>
                        <option value="15">15 Minuten</option>
                        <option value="30">30 Minuten</option>
                        <option value="60">1 Stunde</option>
                        <option value="90">1,5 Stunden</option>
                        <option value="120">2 Stunden</option>
                        <option value="180">3 Stunden</option>
                    </select>
                    <label class="form-label">Info / Grund</label>
                    <select class="form-select form-select-sm mb-2 ml-reason-select" id="mlTypeReasonSel" data-target="mlTypeReason"></select>
                    <input type="text" class="form-control form-control-sm mb-2 d-none" id="mlTypeReason" placeholder="z. B. Küche Pause">
                    <label class="form-label mb-1">Ausnahmen – weiter bestellbar</label>
                    <p class="small text-muted mb-2">Zum Ankreuzen tippen (Touchscreen-tauglich, kein Strg).</p>
                    <div id="mlTypeExceptionsWrap" class="border rounded bg-white mb-2 ml-exceptions-scroll">
                        <div id="mlTypeExceptionsList"></div>
                    </div>
                    <button type="button" class="btn btn-warning btn-sm" id="mlBtnType">Gesamte Kategorie sperren</button>
                    <p class="small text-muted mt-2 mb-0">Ersetzt eine bestehende Sperre derselben Kategorie (Speisen/Getränke).</p>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">Mehrere Positionen gleichzeitig sperren</div>
                <div class="card-body">
                    <p class="small text-muted mb-2">Mehrere einzelne Positionen auswählen (z. B. wenn mehrere Sachen aus sind). Zum Ankreuzen tippen.</p>
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label">Dauer</label>
                            <select class="form-select form-select-sm" id="mlMultiDur">
                                <option value="-1">Bis manuell aufgehoben</option>
                                <option value="15">15 Minuten</option>
                                <option value="30">30 Minuten</option>
                                <option value="60">1 Stunde</option>
                                <option value="90">1,5 Stunden</option>
                                <option value="120">2 Stunden</option>
                                <option value="180">3 Stunden</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Info / Grund</label>
                            <select class="form-select form-select-sm ml-reason-select" id="mlMultiReasonSel" data-target="mlMultiReason"></select>
                            <input type="text" class="form-control form-control-sm mt-1 d-none" id="mlMultiReason" placeholder="Eigener Text">
                        </div>
                    </div>
                    <label class="form-label mb-1">Positionen auswählen</label>
                    <input type="text" class="form-control form-control-sm mb-2" id="mlMultiFilter" placeholder="Suchen…">
                    <div id="mlMultiWrap" class="border rounded bg-white mb-2 ml-exceptions-scroll">
                        <div id="mlMultiList"></div>
                    </div>
                    <button type="button" class="btn btn-warning btn-sm" id="mlBtnMulti">Ausgewählte sperren</button>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
/* Genug Innenabstand, damit Checkboxen nicht unter den Rahmen rutschen (kein Bootstrap form-check mit negativem Margin) */
.ml-exceptions-scroll {
  max-height: 280px;
  overflow-y: auto;
  overflow-x: hidden;
  -webkit-overflow-scrolling: touch;
  padding: 10px 12px 12px 16px;
  box-sizing: border-box;
}
#mlTypeExceptionsList { padding: 0; margin: 0; }
.ml-ex-item {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 3rem;
  padding: 6px 4px 6px 0;
  border-bottom: 1px solid #eee;
  touch-action: manipulation;
}
.ml-ex-item:last-child { border-bottom: 0; }
/* Großer Tap-Bereich (Touchscreen), Checkbox zentriert darin */
.ml-ex-hit {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 48px;
  min-height: 48px;
  margin: 0;
  padding: 0;
}
.ml-ex-input {
  width: 1.5rem;
  height: 1.5rem;
  margin: 0 !important;
  cursor: pointer;
  flex-shrink: 0;
  position: relative;
  z-index: 2;
  accent-color: #b91c1c;
}
.ml-ex-label {
  cursor: pointer;
  flex: 1;
  margin: 0;
  padding: 8px 0;
  font-size: 0.95rem;
  line-height: 1.3;
  user-select: none;
  -webkit-tap-highlight-color: transparent;
}
</style>
<script>
(function() {
    var root = document.getElementById('menuLocksUiRoot');
    if (!root) return;

    var apiBase = (root && root.getAttribute('data-menu-lock-api')) || 'menu_lock_api.php';
    var cachedPositions = [];

    var ML_REASONS = [
        { v: '', label: '(kein Grund)' },
        { v: 'Küche überlastet', label: 'Küche überlastet' },
        { v: 'Schank überlastet', label: 'Schank überlastet' },
        { v: 'Position vorübergehend aus', label: 'Position vorübergehend aus' },
        { v: 'Ausverkauft', label: 'Ausverkauft' },
        { v: 'Pause', label: 'Pause' },
        { v: '__custom__', label: 'Eigener Text …' }
    ];

    function populateReasonSelects() {
        var sels = root.querySelectorAll('.ml-reason-select');
        sels.forEach(function(sel) {
            if (sel.dataset.filled === '1') return;
            sel.dataset.filled = '1';
            ML_REASONS.forEach(function(r) {
                var o = document.createElement('option');
                o.value = r.v;
                o.textContent = r.label;
                sel.appendChild(o);
            });
            sel.addEventListener('change', function() {
                var input = document.getElementById(sel.getAttribute('data-target'));
                if (!input) return;
                if (sel.value === '__custom__') {
                    input.classList.remove('d-none');
                    input.focus();
                } else {
                    input.classList.add('d-none');
                }
            });
        });
    }

    function getReasonValue(selId, inputId) {
        var sel = document.getElementById(selId);
        var input = document.getElementById(inputId);
        if (!sel) return input ? input.value.trim() : '';
        if (sel.value === '__custom__') {
            return input ? input.value.trim() : '';
        }
        return sel.value;
    }

    function api(method, body) {
        return fetch(apiBase, {
            method: method,
            headers: body ? { 'Content-Type': 'application/json' } : {},
            body: body ? JSON.stringify(body) : undefined,
            credentials: 'same-origin'
        }).then(function(r) { return r.json(); });
    }

    function positionNameById(pid, positions) {
        var id = parseInt(pid, 10);
        if (!id) return '';
        var arr = positions || cachedPositions;
        for (var i = 0; i < arr.length; i++) {
            if (parseInt(arr[i].rowid, 10) === id) return String(arr[i].Positionsname || '');
        }
        return '';
    }

    function formatExceptionNames(csv, positions) {
        if (!csv) return '';
        var parts = String(csv).split(',');
        var labels = [];
        for (var i = 0; i < parts.length; i++) {
            var id = parseInt(parts[i].trim(), 10);
            if (!id) continue;
            var n = positionNameById(id, positions);
            labels.push(n ? escapeHtml(n) : ('ID&nbsp;' + id));
        }
        return labels.join(', ');
    }

    function refillExceptionCheckboxes() {
        var mt = parseInt(document.getElementById('mlTypeAll').value, 10);
        var list = document.getElementById('mlTypeExceptionsList');
        if (!list) return;
        list.innerHTML = '';
        cachedPositions.filter(function(p) { return p.type === mt; }).forEach(function(p) {
            var row = document.createElement('div');
            row.className = 'ml-ex-item';
            var hit = document.createElement('div');
            hit.className = 'ml-ex-hit';
            var inp = document.createElement('input');
            inp.type = 'checkbox';
            inp.className = 'ml-ex-input';
            inp.value = String(p.rowid);
            inp.id = 'mlEx_' + p.rowid;
            var lab = document.createElement('label');
            lab.className = 'ml-ex-label';
            lab.setAttribute('for', inp.id);
            lab.textContent = p.Positionsname || ('#' + p.rowid);
            hit.appendChild(inp);
            row.appendChild(hit);
            row.appendChild(lab);
            list.appendChild(row);
        });
    }

    function fillSelects(data) {
        cachedPositions = data.positions || [];
        var pos = cachedPositions;
        var sel = document.getElementById('mlSinglePos');
        if (sel) {
            sel.innerHTML = '';
            pos.forEach(function(p) {
                var o = document.createElement('option');
                o.value = p.rowid;
                o.textContent = (p.type === 1 ? '[Speise] ' : '[Getränk] ') + p.Positionsname;
                sel.appendChild(o);
            });
        }
        refillExceptionCheckboxes();
        refillMultiCheckboxes();
        populateReasonSelects();
    }

    function refillMultiCheckboxes() {
        var list = document.getElementById('mlMultiList');
        if (!list) return;
        var filterEl = document.getElementById('mlMultiFilter');
        var needle = filterEl ? filterEl.value.trim().toLowerCase() : '';
        list.innerHTML = '';
        cachedPositions.forEach(function(p) {
            var name = String(p.Positionsname || '');
            if (needle && name.toLowerCase().indexOf(needle) === -1) return;
            var row = document.createElement('div');
            row.className = 'ml-ex-item';
            var hit = document.createElement('div');
            hit.className = 'ml-ex-hit';
            var inp = document.createElement('input');
            inp.type = 'checkbox';
            inp.className = 'ml-ex-input ml-multi-input';
            inp.value = String(p.rowid);
            inp.id = 'mlMulti_' + p.rowid;
            var lab = document.createElement('label');
            lab.className = 'ml-ex-label';
            lab.setAttribute('for', inp.id);
            lab.textContent = (p.type === 1 ? '[Speise] ' : '[Getränk] ') + name;
            hit.appendChild(inp);
            row.appendChild(hit);
            row.appendChild(lab);
            list.appendChild(row);
        });
    }

    var multiFilterEl = document.getElementById('mlMultiFilter');
    if (multiFilterEl) {
        multiFilterEl.addEventListener('input', refillMultiCheckboxes);
    }

    var typeAllEl = document.getElementById('mlTypeAll');
    if (typeAllEl) {
        typeAllEl.addEventListener('change', refillExceptionCheckboxes);
    }

    function renderLocks(data) {
        var box = document.getElementById('menuLocksList');
        if (!box) return;
        var positions = data.positions || cachedPositions;
        var locks = data.locks || [];
        if (!locks.length) {
            box.innerHTML = '<p class="text-muted mb-0">Keine aktiven Sperren.</p>';
            return;
        }
        var html = '<ul class="list-group list-group-flush">';
        locks.forEach(function(l) {
            var titleHtml = '';
            if (l.scope === 'position') {
                var pnm = positionNameById(l.position_id, positions);
                titleHtml = '<strong>' + (pnm ? escapeHtml(pnm) : escapeHtml('Position #' + l.position_id)) + '</strong>';
            } else {
                titleHtml = '<strong>' + escapeHtml(l.menu_type == 1 ? 'Alle Speisen' : 'Alle Getränke') + '</strong>';
                if (l.exceptions) {
                    var exLabel = formatExceptionNames(l.exceptions, positions);
                    if (exLabel) {
                        titleHtml += ' <span class="text-success small">(Ausnahmen: ' + exLabel + ')</span>';
                    }
                }
            }
            var until = (!l.locked_until || l.locked_until === '0000-00-00 00:00:00')
                ? 'bis aufgehoben' : ('bis ' + l.locked_until.replace(' ', ' '));
            var reason = l.reason ? (' — ' + escapeHtml(l.reason)) : '';
            html += '<li class="list-group-item d-flex justify-content-between align-items-start flex-wrap gap-2">';
            html += '<div>' + titleHtml + '<br><small class="text-muted">' + until + reason + '</small><br><small>von ' + escapeHtml(l.created_by || '') + '</small></div>';
            html += '<button type="button" class="btn btn-sm btn-outline-danger mlClear" data-id="' + l.id + '">Aufheben</button>';
            html += '</li>';
        });
        html += '</ul>';
        box.innerHTML = html;
        box.querySelectorAll('.mlClear').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = parseInt(btn.getAttribute('data-id'), 10);
                if (!confirm('Sperre wirklich aufheben?')) return;
                api('POST', { action: 'clear', lock_id: id }).then(function(r) {
                    if (r.ok) refresh(); else alert(r.error || 'Fehler');
                });
            });
        });
    }

    function escapeHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function refresh() {
        api('GET').then(function(data) {
            if (!data.ok) return;
            fillSelects(data);
            renderLocks(data);
        }).catch(function() {
            document.getElementById('menuLocksList').innerHTML = '<p class="text-danger">Laden fehlgeschlagen.</p>';
        });
    }

    document.getElementById('mlBtnSingle').addEventListener('click', function() {
        var pid = parseInt(document.getElementById('mlSinglePos').value, 10);
        var min = parseInt(document.getElementById('mlSingleDur').value, 10);
        var reason = getReasonValue('mlSingleReasonSel', 'mlSingleReason');
        api('POST', { action: 'set_position', position_id: pid, minutes: min, reason: reason }).then(function(r) {
            if (r.ok) { refresh(); alert('Gesperrt.'); } else alert(r.error || 'Fehler');
        });
    });

    document.getElementById('mlBtnType').addEventListener('click', function() {
        var mt = parseInt(document.getElementById('mlTypeAll').value, 10);
        var min = parseInt(document.getElementById('mlTypeDur').value, 10);
        var reason = getReasonValue('mlTypeReasonSel', 'mlTypeReason');
        var exceptions = [];
        document.querySelectorAll('#mlTypeExceptionsList input[type="checkbox"]:checked').forEach(function(cb) {
            exceptions.push(parseInt(cb.value, 10));
        });
        if (!confirm('Alle ' + (mt === 1 ? 'Speisen' : 'Getränke') + ' sperren?')) return;
        api('POST', { action: 'set_type_all', menu_type: mt, minutes: min, reason: reason, exceptions: exceptions }).then(function(r) {
            if (r.ok) { refresh(); alert('Kategorie gesperrt.'); } else alert(r.error || 'Fehler');
        });
    });

    var multiBtn = document.getElementById('mlBtnMulti');
    if (multiBtn) {
        multiBtn.addEventListener('click', function() {
            var min = parseInt(document.getElementById('mlMultiDur').value, 10);
            var reason = getReasonValue('mlMultiReasonSel', 'mlMultiReason');
            var ids = [];
            document.querySelectorAll('#mlMultiList input.ml-multi-input:checked').forEach(function(cb) {
                ids.push(parseInt(cb.value, 10));
            });
            if (!ids.length) { alert('Bitte mindestens eine Position auswählen.'); return; }
            if (!confirm(ids.length + ' Position(en) sperren?')) return;
            api('POST', { action: 'set_positions', position_ids: ids, minutes: min, reason: reason }).then(function(r) {
                if (r.ok) { refresh(); alert((r.count || ids.length) + ' Position(en) gesperrt.'); } else alert(r.error || 'Fehler');
            });
        });
    }

    var closePanelBtn = document.getElementById('mlBtnClosePanel');
    if (closePanelBtn) {
        closePanelBtn.addEventListener('click', function() {
            window._pauseOperationsPoll = false;
            var r = document.getElementById('menuLocksUiRoot');
            var p = r && r.parentElement;
            if (p && p.id && p.id.indexOf('LockPanel') !== -1) {
                p.classList.add('d-none');
                p.innerHTML = '';
            }
            var page = typeof window.getActivePageId === 'function' ? window.getActivePageId() : '';
            if (page === 'Kuechenansicht' && typeof window.Kuechenansicht === 'function') window.Kuechenansicht();
            else if (page === 'Schankansicht' && typeof window.SchankAnsicht === 'function') window.SchankAnsicht();
            else if (page === 'DruckzielAnsicht' && window.currentDruckzielId && typeof window.DruckzielAnsicht === 'function') {
                window.DruckzielAnsicht(window.currentDruckzielId, '');
            }
        });
    }

    refresh();
})();
</script>
<?php
$menuLocksBody = ob_get_clean();

if ($embed) {
    echo $menuLocksBody;
    return;
}

// Vollständige Seite wie admin.php (Bootstrap + admin.css)
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Speisekarte sperren – Admin</title>
    <?php echo ff_favicon_link_tags(null); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <link href="admin.css" rel="stylesheet">
</head>
<body class="admin-page">
<nav class="navbar navbar-expand admin-navbar">
    <div class="container-fluid">
        <a href="admin.php" class="btn btn-link text-decoration-none px-2">&larr; Zurück zum Admin</a>
        <span class="navbar-brand mb-0 flex-grow-1 text-center">Speisekarte sperren</span>
        <a href="index.php" class="btn btn-link text-decoration-none px-2 small">Start</a>
    </div>
</nav>
<div class="admin-content">
    <?php echo $menuLocksBody; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
