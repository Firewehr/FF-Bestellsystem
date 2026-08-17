<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';
require_once __DIR__ . '/../include/ff_favicon_helpers.php';
?>
<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stammdaten – Speisekarte &amp; Tische</title>
    <?php echo ff_favicon_link_tags(null, '../'); ?>
    <link href="../assets/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="../style.css" rel="stylesheet">
    <link href="../admin.css" rel="stylesheet">
    </head>
<body class="admin-page manage-stammdaten">

<nav class="navbar navbar-expand-lg admin-navbar sticky-top shadow-sm">
    <div class="container-fluid">
        <a class="btn btn-link text-white text-decoration-none px-2 fw-semibold" href="../index.php">← Zurück</a>
        <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false" aria-label="Navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav flex-wrap ms-lg-auto mb-2 mb-lg-0 column-gap-1 py-2 py-lg-0">
                <li class="nav-item"><a class="nav-link fw-semibold" href="#" data-nav="pos" onclick="loadPositionsErweitert(); return false;">Alle Positionen (erweitert)</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-nav="speisen" onclick="loadSpeisen(); return false;" title="Nur Speisen (type 1)">Speisen · Kurzliste</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-nav="getraenke" onclick="loadGetraenke(); return false;" title="Nur Getränke (type 2)">Getränke · Kurzliste</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-nav="beilagen" onclick="loadBeilagenZusatzinfos(); return false;">Beilagen / Zusatzinfos</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-nav="apidevice" onclick="loadApiDeviceConfig(); return false;">API Device</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-nav="sub" onclick="loadSubcategories(); return false;">Unterkategorien</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-nav="tische" onclick="loadTische(); return false;">Tische</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-nav="lock" onclick="loadSperren(); return false;">Sperren</a></li>
            </ul>
                        </div>
            </div>
        </nav>

<div class="admin-content">
    <div class="card border-0 shadow-sm mb-3 manage-intro-card">
        <div class="card-body py-3">
            <p class="small text-muted mb-2 mb-md-0">
                Zentral: <strong>Alle Positionen (erweitert)</strong> (Typ, Preis, EK, Druckziel, Kacheln, Unterkategorien).
                <strong>Beilagen / Zusatzinfos</strong> für den Hinweis-Dialog beim Bestellen.
                <strong>Kurzlisten</strong> Speisen/Getränke nutzen dieselbe Logik, nur gefiltert.
            </p>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <a href="../admin.php" class="btn btn-sm btn-outline-primary">Haupt-Admin (admin.php)</a>
                <a href="../index.php" class="btn btn-sm btn-outline-secondary">Startseite</a>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border mb-4">
        <div class="card-body p-0" id="manageMainCardBody">
            <div id="mainContent" class="p-3 p-md-4">
                <div class="text-center text-muted py-5">
                    <div class="spinner-border spinner-border-sm text-secondary mb-2" role="status" aria-hidden="true"></div>
                    <p class="mb-0 small">Lade …</p>
                </div>
            </div>
        </div>
    </div>
        </div>

<script src="../assets/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
<script>
function $(id) { return document.getElementById(id); }

function manageNavActive(key) {
    document.querySelectorAll('[data-nav]').forEach(function(a) {
        a.classList.toggle('active', a.getAttribute('data-nav') === key);
    });
}

function loadContent(url, callback) {
    fetch(url, { cache: 'no-store', credentials: 'same-origin' })
        .then(function(r) { return r.text(); })
        .then(function(html) {
            $('mainContent').innerHTML = html;
            if (callback) callback();
        })
        .catch(function() {
            $('mainContent').innerHTML = '<div class="alert alert-danger m-0" role="alert">Inhalt konnte nicht geladen werden. Bitte Seite neu laden oder erneut anmelden.</div>';
        });
}

function fetchGet(url) {
    return fetch(url, { cache: 'no-store', credentials: 'same-origin' });
}

function fetchPost(url, data) {
    var body = new URLSearchParams(data).toString();
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
        cache: 'no-store',
        credentials: 'same-origin'
    });
}

/** API im Webroot (über manage/) */
function apiGet(path) {
    return fetch('../' + path.replace(/^\//, ''), { cache: 'no-store', credentials: 'same-origin' });
}
function apiPost(path, data) {
    var body = new URLSearchParams(data).toString();
    return fetch('../' + path.replace(/^\//, ''), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
        cache: 'no-store',
        credentials: 'same-origin'
    });
}

window._managePosReload = null;

function manageReloadPositionsList() {
    if (typeof window._managePosReload === 'function') {
        window._managePosReload();
    }
}

function loadTische() {
    manageNavActive('tische');
    window._managePosReload = loadTische;
    loadContent('list_tische_admin.php', function() { ffInitTischAdminGrid(); ffInitTischFlagsCheckboxes(); });
}

/** Sammelrechnung ↔ Ehrengast: nur eine Checkbox pro Tisch (manage-Tabelle). */
function ffInitTischFlagsCheckboxes() {
    var table = document.getElementById('tischTable');
    if (!table || table.getAttribute('data-ff-flags-bound') === '1') {
        return;
    }
    table.setAttribute('data-ff-flags-bound', '1');
    table.addEventListener('change', function(ev) {
        var t = ev.target;
        if (!t || t.type !== 'checkbox') return;
        var id = t.id || '';
        var m = id.match(/^sr_(\d+)$/);
        if (m && t.checked) {
            var eg = document.getElementById('eg_' + m[1]);
            if (eg) eg.checked = false;
            return;
        }
        m = id.match(/^eg_(\d+)$/);
        if (m && t.checked) {
            var sr = document.getElementById('sr_' + m[1]);
            if (sr) sr.checked = false;
        }
    });
}

function loadSpeisen() {
    manageNavActive('speisen');
    window._managePosReload = loadSpeisen;
    loadContent('list_speisekarte_admin.php', function() { ffInitPrintTargetKassaRows(); });
}

function loadGetraenke() {
    manageNavActive('getraenke');
    window._managePosReload = loadGetraenke;
    loadContent('list_getraenkekarte_admin.php', function() { ffInitPrintTargetKassaRows(); });
}

function ffBeilagenEsc(s) {
    var div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
}

function ffInitBeilagenZusatzinfosAdmin() {
    var pos = $('beilagePosition');
    var tbody = $('beilagenTbody');
    var ftbody = $('beilagenFreetextTbody');
    if (!pos || !tbody) return;

    function load() {
        var pid = parseInt(pos.value, 10) || 0;
        if (ftbody) {
            if (pid <= 0) {
                ftbody.innerHTML = '<tr><td colspan="3" class="text-muted">Bitte eine Position wählen.</td></tr>';
            } else {
                ftbody.innerHTML = '<tr><td colspan="3" class="text-muted">Laden …</td></tr>';
            }
        }
        if (pid <= 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-muted">Bitte eine Speisekarten-Position wählen.</td></tr>';
            return;
        }
        tbody.innerHTML = '<tr><td colspan="3" class="text-muted">Laden …</td></tr>';
        apiGet('beilagen_admin_api.php?position=' + encodeURIComponent(pid))
            .then(function(r) {
                return r.text().then(function(t) {
                    try {
                        return JSON.parse(t);
                    } catch (e) {
                        return { ok: false, _raw: t, _status: r.status };
                    }
                });
            })
            .then(function(d) {
                if (ftbody) {
                    ftbody.innerHTML = '';
                    if (!d || !d.ok) {
                        ftbody.innerHTML = '<tr><td colspan="3" class="text-muted">—</td></tr>';
                    } else if (!d.freetext_stats || !d.freetext_stats.length) {
                        ftbody.innerHTML = '<tr><td colspan="3" class="text-muted">Keine Freitext-Anteile außerhalb der Beilagen für diese Position.</td></tr>';
                    } else {
                        d.freetext_stats.forEach(function(st) {
                            var tr = document.createElement('tr');
                            var t = String(st.text || '');
                            var c = parseInt(st.count, 10) || 0;
                            var tEsc = ffBeilagenEsc(t);
                            tr.innerHTML = '<td><code class="small user-select-all">' + tEsc + '</code></td><td class="text-end">' + c + '</td><td><button type="button" class="btn btn-sm btn-outline-primary ff-beilage-useft">Übernehmen</button></td>';
                            tr.querySelector('.ff-beilage-useft').onclick = function() {
                                var ne = $('beilageName');
                                if (ne) ne.value = t;
                                var be = $('beilageBetrag');
                                if (be) be.value = '';
                                if (ne) ne.focus();
                            };
                            ftbody.appendChild(tr);
                                    });
                                }
                            }
                tbody.innerHTML = '';
                if (!d || !d.ok) {
                    var hint = (d && d.error) ? d.error : ((d && d._status === 403) ? 'Nicht angemeldet / keine Admin-Rechte (API).' : 'Fehler beim Laden.');
                    tbody.innerHTML = '<tr><td colspan="3" class="text-danger">' + ffBeilagenEsc(hint) + '</td></tr>';
                    return;
                }
                if (!d.rows || !d.rows.length) {
                    tbody.innerHTML = '<tr><td colspan="3" class="text-muted">Keine Beilagen für diese Position.</td></tr>';
                    return;
                }
                d.rows.forEach(function(row) {
                    var tr = document.createElement('tr');
                    var betNum = Number(row.betrag) || 0;
                    var betVal = betNum.toFixed(2);
                    var betDisplay = betNum.toLocaleString('de-AT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    var rid = row.rowid;
                    tr.innerHTML = '<td>' + ffBeilagenEsc(row.name) + '</td>'
                        + '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm ff-beilage-bet" data-rowid="' + rid + '" data-prev="' + betVal + '" value="' + betVal + '" title="Beim Verlassen speichern (' + betDisplay + ' €)"></td>'
                        + '<td><button type="button" class="btn btn-sm btn-outline-danger ff-beilage-del" data-rowid="' + rid + '">Löschen</button></td>';
                    var betInp = tr.querySelector('.ff-beilage-bet');
                    if (betInp) {
                        betInp.addEventListener('blur', function() {
                            var inp = betInp;
                            var prev = inp.getAttribute('data-prev') || '0';
                            var cur = String(inp.value || '0');
                            if (cur === prev) return;
                            apiPost('beilagen_admin_api.php', { action: 'update', rowid: String(rid), betrag: cur })
                                .then(function(r) { return r.json(); })
                                .then(function(x) {
                                    if (x && x.ok) {
                                        inp.setAttribute('data-prev', cur);
                                    } else {
                                        inp.value = prev;
                                        alert('Speichern fehlgeschlagen.');
                                    }
                                })
                                .catch(function() {
                                    inp.value = prev;
                                    alert('Netzwerkfehler.');
                                });
                        });
                    }
                    tr.querySelector('.ff-beilage-del').onclick = function() {
                        if (!confirm('Eintrag wirklich löschen?')) return;
                        apiPost('beilagen_admin_api.php', { action: 'delete', rowid: String(rid) })
                            .then(function(r) { return r.json(); })
                            .then(function(x) {
                                if (x && x.ok) load();
                                else alert('Fehler');
                            })
                            .catch(function() { alert('Fehler'); });
                    };
                    tbody.appendChild(tr);
                });
            })
            .catch(function() {
                tbody.innerHTML = '<tr><td colspan="3" class="text-danger">Netzwerkfehler (fetch).</td></tr>';
                if (ftbody) ftbody.innerHTML = '<tr><td colspan="3" class="text-muted">—</td></tr>';
            });
    }

    pos.addEventListener('change', load);
    var addBtn = $('beilageAddBtn');
    if (addBtn) {
        addBtn.onclick = function() {
            var nameEl = $('beilageName');
            var betEl = $('beilageBetrag');
            var name = nameEl ? String(nameEl.value).trim() : '';
            var bet = betEl && betEl.value !== '' ? betEl.value : '0';
            if (parseInt(pos.value, 10) <= 0) {
                alert('Bitte eine gültige Position wählen.');
                return;
            }
            if (!name) {
                alert('Bezeichnung eingeben');
                return;
            }
            apiPost('beilagen_admin_api.php', { action: 'add', position: pos.value, name: name, betrag: bet })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d && d.ok) {
                        if (nameEl) nameEl.value = '';
                        if (betEl) betEl.value = '';
                        load();
                    } else {
                        alert('Fehler: ' + (d && d.error ? d.error : ''));
                    }
                })
                .catch(function() { alert('Fehler'); });
        };
    }
    if (parseInt(pos.value, 10) > 0) load();
}

function loadBeilagenZusatzinfos() {
    manageNavActive('beilagen');
    window._managePosReload = null;
    loadContent('beilagen_zusatzinfos_admin.php', function() { ffInitBeilagenZusatzinfosAdmin(); });
}

function ffApiDeviceCfgEsc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

window._ffApiDevicePrintTargets = [];

function ffApiDeviceCfgTargetOptions(selected) {
    var opts = window._ffApiDevicePrintTargets || [];
    if (!opts.length) {
        opts = [{ print_target: 11, name: 'Küche' }, { print_target: 12, name: 'Schank' }];
    }
    var html = '<option value="0"' + ((parseInt(selected, 10) || 0) === 0 ? ' selected' : '') + '>Alle Targets</option>';
    opts.forEach(function(o) {
        var pt = parseInt(o.print_target, 10) || 0;
        if (!pt) return;
        var lbl = ffApiDeviceCfgEsc((o.name || ('Target ' + pt)) + ' (' + pt + ')');
        html += '<option value="' + pt + '"' + (pt === (parseInt(selected, 10) || 0) ? ' selected' : '') + '>' + lbl + '</option>';
    });
    return html;
}

function ffApiDeviceCfgMatchModeOptions(selected) {
    var v = String(selected || 'contains').toLowerCase();
    if (v !== 'exact') v = 'contains';
    return '<option value="contains"' + (v === 'contains' ? ' selected' : '') + '>enthält</option>'
        + '<option value="exact"' + (v === 'exact' ? ' selected' : '') + '>gleich</option>';
}

function ffApiDeviceCfgRenderRows(rows) {
    var tb = $('apiDeviceCfgTbody');
    if (!tb) return;
    tb.innerHTML = '';
    if (!rows || !rows.length) {
        tb.innerHTML = '<tr><td colspan="6" class="text-muted">Keine Einträge.</td></tr>';
        return;
    }
    rows.forEach(function(r) {
        var tr = document.createElement('tr');
        var key = ffApiDeviceCfgEsc(r.key || '');
        var needle = ffApiDeviceCfgEsc(r.needle || '');
        var matchMode = (String(r.match_mode || 'contains').toLowerCase() === 'exact') ? 'exact' : 'contains';
        var printTarget = parseInt(r.print_target, 10) || 0;
        var enabled = !!(r.enabled === 1 || r.enabled === true || String(r.enabled) === '1');
        tr.innerHTML =
            '<td><input type="text" class="form-control form-control-sm ff-ad-key" value="' + key + '" placeholder="z. B. grillhuhn"></td>' +
            '<td><input type="text" class="form-control form-control-sm ff-ad-needle" value="' + needle + '" placeholder="z. B. grillhuhn"></td>' +
            '<td><select class="form-select form-select-sm ff-ad-match-mode">' + ffApiDeviceCfgMatchModeOptions(matchMode) + '</select></td>' +
            '<td><select class="form-select form-select-sm ff-ad-print-target">' + ffApiDeviceCfgTargetOptions(printTarget) + '</select></td>' +
            '<td><div class="form-check"><input type="checkbox" class="form-check-input ff-ad-enabled" ' + (enabled ? 'checked' : '') + '></div></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger ff-ad-del">Entfernen</button></td>';
        tr.querySelector('.ff-ad-del').onclick = function() {
            tr.remove();
            if (tb.querySelectorAll('tr').length === 0) {
                ffApiDeviceCfgAddRow();
            }
        };
        tb.appendChild(tr);
    });
}

function ffApiDeviceCfgAddRow() {
    var tb = $('apiDeviceCfgTbody');
    if (!tb) return;
    if (tb.children.length === 1 && /Keine Einträge/.test(tb.textContent || '')) tb.innerHTML = '';
    ffApiDeviceCfgRenderRows([].slice.call(tb.querySelectorAll('tr')).map(function(tr) {
        return {
            key: (tr.querySelector('.ff-ad-key') || {}).value || '',
            needle: (tr.querySelector('.ff-ad-needle') || {}).value || '',
            match_mode: (tr.querySelector('.ff-ad-match-mode') || {}).value || 'contains',
            print_target: parseInt((tr.querySelector('.ff-ad-print-target') || {}).value || '11', 10) || 11,
            enabled: !!((tr.querySelector('.ff-ad-enabled') || {}).checked)
        };
    }).concat([{ key: '', needle: '', match_mode: 'contains', print_target: 11, enabled: true }]));
}

function ffApiDeviceCfgLoad() {
    var status = $('apiDeviceCfgStatus');
    if (status) status.textContent = 'Lade ...';
    apiGet('api_device_admin_api.php')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.ok) {
                if (status) status.textContent = 'Fehler beim Laden.';
                return;
            }
            window._ffApiDevicePrintTargets = Array.isArray(d.print_targets) ? d.print_targets : [];
            ffApiDeviceCfgRenderRows(d.rows || []);
            if (status) {
                status.textContent = d.printer_token_set
                    ? 'Drucker-Token ist gesetzt.'
                    : 'Achtung: printer_token ist leer (API aktuell ungeschützt).';
            }
        })
        .catch(function() {
            if (status) status.textContent = 'Netzwerkfehler beim Laden.';
        });
}

function ffApiDeviceCfgSave() {
    var status = $('apiDeviceCfgStatus');
    var rows = [];
    var tb = $('apiDeviceCfgTbody');
    if (!tb) return;
    tb.querySelectorAll('tr').forEach(function(tr) {
        var keyEl = tr.querySelector('.ff-ad-key');
        var needleEl = tr.querySelector('.ff-ad-needle');
        var enEl = tr.querySelector('.ff-ad-enabled');
        if (!keyEl || !needleEl || !enEl) return;
        rows.push({
            key: String(keyEl.value || ''),
            needle: String(needleEl.value || ''),
            match_mode: String((tr.querySelector('.ff-ad-match-mode') || {}).value || 'contains'),
            print_target: parseInt((tr.querySelector('.ff-ad-print-target') || {}).value || '11', 10) || 11,
            enabled: enEl.checked ? 1 : 0
        });
    });
    if (status) status.textContent = 'Speichere ...';
    apiPost('api_device_admin_api.php', { rows_json: JSON.stringify(rows) })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.ok) {
                if (status) status.textContent = 'Speichern fehlgeschlagen: ' + ((d && d.error) ? d.error : 'unbekannt');
                return;
            }
            ffApiDeviceCfgRenderRows(d.rows || []);
            if (status) status.textContent = 'Gespeichert.';
        })
        .catch(function() {
            if (status) status.textContent = 'Netzwerkfehler beim Speichern.';
        });
}

function loadApiDeviceConfig() {
    manageNavActive('apidevice');
    window._managePosReload = null;
    loadContent('api_device_config_admin.php', function() { ffApiDeviceCfgLoad(); });
}

/** Reihenfolge in „Alle Positionen (erweitert)“ per Drag & Drop (je eigener tbody Speisen/Getränke). */
function ffSerializePositionsOrder(table) {
    var speisen = [];
    var getraenke = [];
    if (!table) return { speisen: speisen, getraenke: getraenke };
    table.querySelectorAll('tbody.ff-manage-pos-tbody').forEach(function(tb) {
        var t = tb.getAttribute('data-type');
        tb.querySelectorAll('tr.ff-manage-pos-row').forEach(function(tr) {
            var id = parseInt(tr.getAttribute('data-rowid'), 10);
            if (!id) return;
            if (t === '1') speisen.push(id);
            else if (t === '2') getraenke.push(id);
        });
    });
    return { speisen: speisen, getraenke: getraenke };
}

function ffGetDragAfterElement(tbody, y, dragging) {
    var same = [];
    var nodes = tbody.querySelectorAll('.ff-manage-pos-row');
    for (var i = 0; i < nodes.length; i++) {
        if (nodes[i] === dragging) continue;
        same.push(nodes[i]);
    }
    var closest = { offset: Number.NEGATIVE_INFINITY, element: null };
    for (var j = 0; j < same.length; j++) {
        var child = same[j];
        var box = child.getBoundingClientRect();
        var offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
            closest = { offset: offset, element: child };
        }
    }
    return closest.element;
}

function ffPersistPositionsOrder(table, orderBefore) {
    var o = ffSerializePositionsOrder(table);
    var key = JSON.stringify(o);
    if (key === orderBefore) return;
    fetch('reorder_positionen_batch.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: key,
        cache: 'no-store',
        credentials: 'same-origin'
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d && d.ok) {
                loadPositionsErweitert();
            } else {
                alert('Reihenfolge speichern fehlgeschlagen.');
                loadPositionsErweitert();
            }
        })
        .catch(function() {
            alert('Netzwerkfehler beim Speichern der Reihenfolge.');
            loadPositionsErweitert();
        });
}

function ffInitManagePositionsErweitertTable() {
    var table = document.getElementById('mySpeisekarteManage');
    if (!table) return;
    var orderAtDragStart = '';
    var draggingRow = null;

    table.querySelectorAll('tbody.ff-manage-pos-tbody').forEach(function(tbody) {
        tbody.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (!draggingRow || draggingRow.closest('tbody') !== tbody) return;
            var after = ffGetDragAfterElement(tbody, e.clientY, draggingRow);
            if (after == null) {
                tbody.appendChild(draggingRow);
                                } else {
                tbody.insertBefore(draggingRow, after);
            }
        });
        tbody.addEventListener('drop', function(e) {
            e.preventDefault();
        });
    });

    // Nur die Griff-Zelle ist draggable (Browser unterstützen Zeilen-Drag in Tabellen unzuverlässig).
    table.querySelectorAll('td.ff-drag-handle').forEach(function(handle) {
        var tr = handle.closest('tr');
        if (!tr || !tr.classList.contains('ff-manage-pos-row')) return;
        handle.addEventListener('dragstart', function(e) {
            draggingRow = tr;
            orderAtDragStart = JSON.stringify(ffSerializePositionsOrder(table));
            tr.classList.add('ff-dragging');
            e.dataTransfer.effectAllowed = 'move';
            try {
                e.dataTransfer.setData('text/plain', tr.getAttribute('data-rowid') || '');
            } catch (err) {}
        });
        handle.addEventListener('dragend', function() {
            tr.classList.remove('ff-dragging');
            draggingRow = null;
            ffPersistPositionsOrder(table, orderAtDragStart);
        });
    });
}

function loadPositionsErweitert() {
    manageNavActive('pos');
    window._managePosReload = loadPositionsErweitert;
    loadContent('positions_erweitert.php', function() {
        if (typeof ffSyncPosSubcategoryOptions === 'function') ffSyncPosSubcategoryOptions();
        if (typeof ffSyncPosPrintTargetDefault === 'function') ffSyncPosPrintTargetDefault();
        ffInitManagePositionsErweitertTable();
        ffInitPrintTargetKassaRows();
    });
}

function loadSubcategories() {
    manageNavActive('sub');
    window._managePosReload = null;
    loadContent('subcategories_admin.php', function() { manageSubcategoryReload(); });
}

function loadSperren() {
    manageNavActive('lock');
    window._managePosReload = null;
    loadContent('../menu_locks_ui.php?embed=1');
}

function ffSaveNewTable(tischname, x, y) {
    tischname = String(tischname == null ? '' : tischname).trim();
    if (!tischname) return;
    x = String(parseInt(String(x), 10) || 1);
    y = String(parseInt(String(y), 10) || 1);
    var tnEl = $('tischname'), xEl = $('x'), yEl = $('y');
    if (tnEl) tnEl.value = tischname;
    if (xEl) xEl.value = x;
    if (yEl) yEl.value = y;
    fetchPost('../neuerTisch_save.php', { neuerTischName: tischname, neueTischX: x, neueTischY: y, neueTischFarbe: '#000000' })
        .then(function() { loadTische(); });
}

function manageApplyTischFlagsExclusive(isSammel, isEhren) {
    if (isSammel === 1) {
        return { is_sammelrechnung: 1, is_ehrengast: 0 };
    }
    if (isEhren === 1) {
        return { is_sammelrechnung: 0, is_ehrengast: 1 };
    }
    return { is_sammelrechnung: 0, is_ehrengast: 0 };
}

function managePostTischFlags(tischnummer, isSammel, isEhren, opts) {
    opts = opts || {};
    var flags = manageApplyTischFlagsExclusive(isSammel, isEhren);
    return apiPost('save_tisch_flags.php', {
        tischnummer: tischnummer,
        is_sammelrechnung: flags.is_sammelrechnung,
        is_ehrengast: flags.is_ehrengast
    }).then(function(r) {
        return r.text().then(function(t) {
            if (!r.ok) {
                throw new Error('Speichern fehlgeschlagen (HTTP ' + r.status + ').');
            }
            var j = null;
            try { j = JSON.parse(t); } catch (e) {
                throw new Error('Ungültige Server-Antwort.');
            }
            if (!j || !j.ok) {
                throw new Error((j && j.error) ? j.error : 'Speichern fehlgeschlagen.');
            }
            if (!opts.silent) {
                alert('Gespeichert');
            }
            loadTische();
        });
    });
}

function manageSaveTischFlags(tischnummer) {
    var sr = document.getElementById('sr_' + tischnummer);
    var eg = document.getElementById('eg_' + tischnummer);
    if (!sr || !eg) {
        alert('Tisch-Zeile nicht gefunden.');
        return;
    }
    var isSammel = sr.checked ? 1 : 0;
    var isEhren = eg.checked ? 1 : 0;
    if (isSammel === 1) {
        isEhren = 0;
        eg.checked = false;
    } else if (isEhren === 1) {
        isSammel = 0;
        sr.checked = false;
    }
    managePostTischFlags(tischnummer, isSammel, isEhren, { silent: true })
        .catch(function(err) { alert(err && err.message ? err.message : 'Fehler'); });
}

document.addEventListener('change', function(ev) {
    var t = ev.target;
    if (!t || !t.classList || !t.classList.contains('ff-tisch-flag')) return;
    var tid = parseInt(t.getAttribute('data-tid') || '0', 10);
    if (tid <= 0) return;
    var flag = t.getAttribute('data-flag');
    if (flag === 'sr' && t.checked) {
        var eg = document.getElementById('eg_' + tid);
        if (eg) eg.checked = false;
    } else if (flag === 'eg' && t.checked) {
        var sr = document.getElementById('sr_' + tid);
        if (sr) sr.checked = false;
    }
    manageSaveTischFlags(tid);
});

function manageToggleTischFlagFromGrid(btn) {
    if (!btn) return;
    var tn = parseInt(btn.getAttribute('data-tischnummer'), 10) || 0;
    var flag = btn.getAttribute('data-flag');
    if (tn <= 0 || (flag !== 'sr' && flag !== 'eg')) return;
    var isOn = btn.classList.contains('tisch-flag-toggle--on');
    var isSammel = 0;
    var isEhren = 0;
    if (flag === 'sr') {
        isSammel = isOn ? 0 : 1;
                                } else {
        isEhren = isOn ? 0 : 1;
    }
    managePostTischFlags(tn, isSammel, isEhren, { silent: true })
        .catch(function(err) { alert(err && err.message ? err.message : 'Fehler'); });
}

function ffInitTischAdminGrid() {
    var root = document.getElementById('tischDragGrid');
    if (!root) return;
    var dragTn = null, dragFromX = null, dragFromY = null;
    function clearOver() {
        root.querySelectorAll('.tisch-admin-cell--over').forEach(function(c) { c.classList.remove('tisch-admin-cell--over'); });
    }
    function ffTryAddTableOnEmptyCell(cell) {
        if (!cell || !root.contains(cell)) return;
        if (cell.classList.contains('tisch-admin-cell--busy')) return;
        var tx = cell.getAttribute('data-x'), ty = cell.getAttribute('data-y');
        if (tx === null || ty === null) return;
        var name = window.prompt('Tischname für Spalte ' + tx + ', Zeile ' + ty + ':', '');
        if (name === null) return;
        ffSaveNewTable(name, tx, ty);
    }
    root.addEventListener('click', function(e) {
        if (e.button !== 0) return;
        var flagBtn = e.target.closest('.tisch-flag-toggle');
        if (flagBtn && root.contains(flagBtn)) {
            e.preventDefault();
            e.stopPropagation();
            manageToggleTischFlagFromGrid(flagBtn);
            return;
        }
        if (e.target.closest('.tisch-draggable')) return;
        var cell = e.target.closest('.tisch-admin-cell');
        ffTryAddTableOnEmptyCell(cell);
    });
    root.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var cell = e.target.closest('.tisch-admin-cell');
        if (!cell || !cell.classList.contains('tisch-admin-cell--empty')) return;
        e.preventDefault();
        ffTryAddTableOnEmptyCell(cell);
    });
    root.addEventListener('dragstart', function(e) {
        var btn = e.target.closest('.tisch-draggable');
        if (!btn || !root.contains(btn)) return;
        dragTn = btn.getAttribute('data-tischnummer');
        dragFromX = btn.getAttribute('data-x');
        dragFromY = btn.getAttribute('data-y');
        e.dataTransfer.effectAllowed = 'move';
        if (e.dataTransfer.setData) e.dataTransfer.setData('text/plain', dragTn || '');
        btn.classList.add('dragging');
    });
    root.addEventListener('dragend', function(e) {
        var btn = e.target.closest('.tisch-draggable');
        if (btn) btn.classList.remove('dragging');
        clearOver();
    });
    root.addEventListener('dragover', function(e) {
        var cell = e.target.closest('.tisch-admin-cell');
        if (!cell || !root.contains(cell)) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        clearOver();
        cell.classList.add('tisch-admin-cell--over');
    });
    root.addEventListener('drop', function(e) {
        var cell = e.target.closest('.tisch-admin-cell');
        if (!cell || !root.contains(cell)) return;
        e.preventDefault();
        clearOver();
        var tx = cell.getAttribute('data-x'), ty = cell.getAttribute('data-y');
        if (!dragTn || tx === null || ty === null) return;
        if (String(dragFromX) === String(tx) && String(dragFromY) === String(ty)) return;
        fetchGet('update_xy.php?tischnummer=' + encodeURIComponent(dragTn) + '&x=' + encodeURIComponent(tx) + '&y=' + encodeURIComponent(ty))
            .then(function() { loadTische(); });
    });
}

function updateTischname(tischname, tischnummer) {
    tischname = prompt('Neuer Tischname:', tischname);
    if (tischname !== null) {
        fetchGet('update_tischname.php?tischname=' + encodeURIComponent(tischname) + '&tischnummer=' + encodeURIComponent(tischnummer))
            .then(function() { loadTische(); });
    }
}

function updateXY(x, y, tischnummer) {
    x = prompt('x neu (Spalte):', x);
    y = prompt('y neu (Zeile):', y);
    if (x > 0 && y > 0) {
        fetchGet('update_xy.php?x=' + encodeURIComponent(x) + '&y=' + encodeURIComponent(y) + '&tischnummer=' + encodeURIComponent(tischnummer))
            .then(function() { loadTische(); });
    }
}

function editPositionsname(rowid, Positionsname) {
    Positionsname = prompt('Positionsname neu:', Positionsname);
    if (Positionsname !== null) {
        fetchGet('update_positionsname.php?rowid=' + encodeURIComponent(rowid) + '&Positionsname=' + encodeURIComponent(Positionsname))
            .then(function() { manageReloadPositionsList(); });
    }
}

function editKurzbezeichnung(rowid, Kurzbezeichnung) {
    Kurzbezeichnung = prompt('Kurzbezeichnung neu:', Kurzbezeichnung);
    if (Kurzbezeichnung !== null) {
        fetchGet('update_kurzbezeichnung.php?rowid=' + encodeURIComponent(rowid) + '&Kurzbezeichnung=' + encodeURIComponent(Kurzbezeichnung))
            .then(function() { manageReloadPositionsList(); });
    }
}

function deleteTable(tischnummer) {
    if (confirm('Wirklich löschen?')) {
        fetchGet('delete_table.php?tischnummer=' + encodeURIComponent(tischnummer)).then(function() { loadTische(); });
    }
}

function deleteMeal(rowid) {
    if (confirm('Wirklich löschen?')) {
        fetchGet('delete_meal.php?rowid=' + encodeURIComponent(rowid)).then(function() { manageReloadPositionsList(); });
    }
}

function editBetrag(rowid, Betrag) {
    Betrag = prompt('VK / Verkaufspreis neu (Komma oder Punkt erlaubt):', Betrag);
    if (Betrag === null || String(Betrag).trim() === '') return;
    var num = parseFloat(String(Betrag).replace(',', '.'));
    if (isNaN(num) || num < 0) {
        alert('Ungültiger Betrag.');
        return;
    }
    fetchGet('update_betrag.php?rowid=' + encodeURIComponent(rowid) + '&Betrag=' + encodeURIComponent(Betrag))
        .then(function() { manageReloadPositionsList(); });
}

function editReihenfolge(rowid, reihenfolge) {
    reihenfolge = prompt('Reihenfolge neu:', reihenfolge);
    if (reihenfolge === null || String(reihenfolge).trim() === '') return;
    var n = parseInt(String(reihenfolge).replace(/\s+/g, ''), 10);
    if (isNaN(n) || n < 0) {
        alert('Ungültige Reihenfolge.');
        return;
    }
    fetchGet('update_reihenfolge.php?rowid=' + encodeURIComponent(rowid) + '&reihenfolge=' + encodeURIComponent(n))
        .then(function() { manageReloadPositionsList(); });
}

function manageSavePosField(el) {
    if (!el || !el.getAttribute) return;
    var rowid = parseInt(el.getAttribute('data-rowid') || '0', 10);
    var field = el.getAttribute('data-field') || '';
    var prev = el.getAttribute('data-prev');
    if (prev === null || prev === undefined) prev = '';
    var val = String(el.value || '').trim();
    if (rowid <= 0 || !field) return;
    if (String(prev) === val) return;

    if (field === 'Betrag') {
        var num = parseFloat(val.replace(',', '.'));
        if (isNaN(num) || num < 0) {
            alert('Ungültiger Betrag.');
            el.value = prev;
            return;
        }
        el.classList.add('opacity-50');
        fetchGet('update_betrag.php?rowid=' + encodeURIComponent(rowid) + '&Betrag=' + encodeURIComponent(val))
            .then(function() {
                el.setAttribute('data-prev', val);
                el.classList.remove('opacity-50');
            })
            .catch(function() {
                el.value = prev;
                el.classList.remove('opacity-50');
                alert('Speichern fehlgeschlagen.');
            });
        return;
    }
    if (field === 'reihenfolge') {
        var n = parseInt(val.replace(/\s+/g, ''), 10);
        if (isNaN(n) || n < 0) {
            alert('Ungültige Reihenfolge.');
            el.value = prev;
            return;
        }
        el.classList.add('opacity-50');
        fetchGet('update_reihenfolge.php?rowid=' + encodeURIComponent(rowid) + '&reihenfolge=' + encodeURIComponent(n))
            .then(function() {
                el.setAttribute('data-prev', String(n));
                el.value = String(n);
                el.classList.remove('opacity-50');
            })
            .catch(function() {
                el.value = prev;
                el.classList.remove('opacity-50');
                alert('Speichern fehlgeschlagen.');
            });
        return;
    }
    if (field === 'Positionsname') {
        if (val === '') {
            alert('Name darf nicht leer sein.');
            el.value = prev;
            return;
        }
        el.classList.add('opacity-50');
        fetchGet('update_positionsname.php?rowid=' + encodeURIComponent(rowid) + '&Positionsname=' + encodeURIComponent(val))
            .then(function() {
                el.setAttribute('data-prev', val);
                el.classList.remove('opacity-50');
            })
            .catch(function() {
                el.value = prev;
                el.classList.remove('opacity-50');
                alert('Speichern fehlgeschlagen.');
            });
        return;
    }
    if (field === 'Kurzbezeichnung') {
        el.classList.add('opacity-50');
        fetchGet('update_kurzbezeichnung.php?rowid=' + encodeURIComponent(rowid) + '&Kurzbezeichnung=' + encodeURIComponent(val))
            .then(function() {
                el.setAttribute('data-prev', val);
                el.classList.remove('opacity-50');
            })
            .catch(function() {
                el.value = prev;
                el.classList.remove('opacity-50');
                alert('Speichern fehlgeschlagen.');
            });
    }
}

document.addEventListener('focusout', function(ev) {
    var t = ev.target;
    if (!t || !t.classList || !t.classList.contains('ff-pos-field')) return;
    manageSavePosField(t);
});
document.addEventListener('keydown', function(ev) {
    var t = ev.target;
    if (!t || !t.classList || !t.classList.contains('ff-pos-field')) return;
    if (ev.key === 'Enter') {
        ev.preventDefault();
        t.blur();
    }
});

                            function addTable() {
    var tn = $('tischname'), xEl = $('x'), yEl = $('y');
    if (!tn) return;
    ffSaveNewTable(tn.value, xEl ? xEl.value : 1, yEl ? yEl.value : 1);
}

function addMeal() {
    var positionsname = $('positionsname').value, kurzbezeichnung = $('kurzbezeichnung').value, type = $('type').value;
    var betrag = $('betrag').value, kapazitaet = $('kapazitaet').value, print_target = $('print_target').value;
    var sk = ($('selbstkosten_neu') && $('selbstkosten_neu').value !== '') ? $('selbstkosten_neu').value : '0';
    if (positionsname !== '') {
        var params = 'Positionsname=' + encodeURIComponent(positionsname) + '&Kurzbezeichnung=' + encodeURIComponent(kurzbezeichnung) +
            '&Betrag=' + encodeURIComponent(betrag) + '&type=' + encodeURIComponent(type) + '&Kapazitaet=' + encodeURIComponent(kapazitaet) +
            '&print_target=' + encodeURIComponent(print_target) + '&Selbstkosten=' + encodeURIComponent(sk);
        fetchGet('new_meal.php?' + params)
            .then(function(r) { return r.text().then(function(t) { return { ok: r.ok, t: t }; }); })
            .then(function(x) {
                if (x.ok && String(x.t).toLowerCase().indexOf('erfolgreich') !== -1) {
                    manageReloadPositionsList();
                } else {
                    alert('Anlegen fehlgeschlagen: ' + (x.t || 'Serverfehler'));
                }
            })
            .catch(function() { alert('Anlegen fehlgeschlagen (Netzwerk).'); });
    }
}

                            function farbeSpeichern(tischnummer) {
    var color = $('html5colorpicker' + tischnummer).value;
    fetchPost('update_color.php', { color: color, tischnummer: tischnummer }).then(function() { loadTische(); });
}

                            function farbeSpeiseSpeichern(rowid) {
    var color = $('html5colorpickerM' + rowid).value;
    fetchPost('update_color_meal.php', { color: color, rowid: rowid }).then(function() { manageReloadPositionsList(); });
}

function updateType(rowid, type) {
    fetchGet('update_type.php?rowid=' + encodeURIComponent(rowid) + '&type=' + encodeURIComponent(type))
        .then(function() { manageReloadPositionsList(); });
}

function updatePrintTarget(rowid, print_target) {
    fetchGet('update_print_target.php?rowid=' + encodeURIComponent(rowid) + '&print_target=' + encodeURIComponent(print_target))
        .then(function() { manageReloadPositionsList(); });
}

/** Druckziel bei „Nur Kasse“: ausgegraut, Anzeige „—“ (Wert bleibt in DB für späteres Abwählen). */
window.ffSyncPrintTargetForKassa = function(printSelect, kassaChecked) {
    if (!printSelect) return;
    var cell = printSelect.closest('.ff-print-target-cell');
    if (!cell) return;
    var dash = cell.querySelector('.ff-pt-kassa-dash');
    if (kassaChecked) {
        if (!printSelect.dataset.ffPtOptions) {
            printSelect.dataset.ffPtOptions = printSelect.innerHTML;
            printSelect.dataset.ffPtValue = printSelect.value;
        }
        printSelect.style.display = 'none';
        printSelect.disabled = true;
        if (!dash) {
            dash = document.createElement('span');
            dash.className = 'ff-pt-kassa-dash form-control form-control-sm bg-light text-muted';
            dash.textContent = '—';
            dash.setAttribute('aria-hidden', 'true');
            cell.appendChild(dash);
        }
        dash.style.display = 'block';
    } else {
        if (printSelect.dataset.ffPtOptions) {
            printSelect.innerHTML = printSelect.dataset.ffPtOptions;
            printSelect.value = printSelect.dataset.ffPtValue || printSelect.value;
        }
        printSelect.style.display = '';
        printSelect.disabled = false;
        if (dash) dash.style.display = 'none';
    }
};

window.ffInitPrintTargetKassaRows = function() {
    var main = $('mainContent');
    if (!main) return;
    main.querySelectorAll('tr').forEach(function(tr) {
        var cb = tr.querySelector('input[onchange*="updateKassaOnly"]');
        var sel = tr.querySelector('.ff-pos-print-target');
        if (cb && sel) {
            window.ffSyncPrintTargetForKassa(sel, cb.checked);
        }
    });
    var newCb = $('pos_new_kassa_only');
    var newSel = $('pos_print_target');
    if (newCb && newSel) {
        window.ffSyncPrintTargetForKassa(newSel, newCb.checked);
    }
};

function updateKassaOnly(rowid, checked) {
    var sel = document.querySelector('.ff-pos-print-target[data-rowid="' + rowid + '"]');
    if (sel) window.ffSyncPrintTargetForKassa(sel, checked);
    fetchGet('update_kassa_only.php?rowid=' + encodeURIComponent(rowid) + '&kassa_only=' + (checked ? '1' : '0'))
        .then(function() { manageReloadPositionsList(); });
}

function ffSyncPosKassaFromSubcategory() {
    var sub = $('posSubcategory'), cb = $('pos_new_kassa_only');
    if (!sub || !cb || !sub.selectedOptions.length) return;
    if (sub.selectedOptions[0].getAttribute('data-kassa-only') === '1') {
        cb.checked = true;
    }
    var sel = $('pos_print_target');
    if (sel) window.ffSyncPrintTargetForKassa(sel, cb.checked);
}

function savePositionMetaErr(e) {
    var m = {
        forbidden: 'Keine Berechtigung.',
        bad_rowid: 'Ungültige Position.',
        position_not_found: 'Position nicht gefunden.',
        bad_subcategory: 'Unterkategorie passt nicht zum Typ (Speise/Getränk).',
        bad_tile_bg: 'Kachelfarbe ungültig oder zu knalliges Rot/Orange.',
        bad_color: 'Schriftfarbe ungültig (#RRGGBB).',
        update_failed: 'Speichern fehlgeschlagen.'
    };
    return m[e] || e || 'Fehler';
}

function ffSyncPosPrintTargetDefault() {
    var sel = $('pos_print_target'), cat = $('produktkategorie');
    var kcb = $('pos_new_kassa_only');
    if (!sel || !cat) return;
    if (kcb && kcb.checked) return;
    var want = parseInt(cat.value, 10) === 2 ? 12 : 11;
    var found = false;
    for (var i = 0; i < sel.options.length; i++) {
        if (parseInt(sel.options[i].value, 10) === want) {
            sel.selectedIndex = i;
            found = true;
            break;
        }
    }
    if (!found && sel.options.length) sel.selectedIndex = 0;
}

function ffSyncPosSubcategoryOptions() {
    var sel = $('posSubcategory');
    if (!sel) return;
    var t = parseInt($('produktkategorie') && $('produktkategorie').value ? $('produktkategorie').value : '1', 10);
    var keep = 0;
    for (var i = 0; i < sel.options.length; i++) {
        var opt = sel.options[i];
        var ot = parseInt(opt.getAttribute('data-type') || '0', 10);
        if (ot === 0) { opt.hidden = false; continue; }
        opt.hidden = (ot !== t);
        if (!opt.hidden && opt.selected) keep = 1;
    }
    if (!keep && sel.selectedOptions.length && sel.selectedOptions[0].hidden) sel.value = '0';
}

function ffPmTileDefSync(rowid) {
    var def = $('pm_tile_def_' + rowid), tile = $('pm_tile_' + rowid);
    if (!def || !tile) return;
    tile.disabled = def.checked;
}

function manageApplySubcategoryRowsToSelects(rows) {
    var sel = $('posSubcategory');
    if (sel && rows) {
        sel.innerHTML = '<option value="0" data-type="0">— keine —</option>';
        rows.forEach(function(r) {
            var opt = document.createElement('option');
            opt.value = String(r.id);
            opt.setAttribute('data-type', String(r.type));
            opt.setAttribute('data-kassa-only', String(parseInt(r.kassa_only, 10) === 1 ? 1 : 0));
            opt.textContent = (parseInt(r.type, 10) === 2 ? '[G] ' : '[S] ') + String(r.name) + (parseInt(r.kassa_only, 10) === 1 ? ' (Kasse)' : '');
            sel.appendChild(opt);
        });
        ffSyncPosSubcategoryOptions();
    }
    document.querySelectorAll('select.ff-pm-sub').forEach(function(pmSel) {
        var pt = parseInt(pmSel.getAttribute('data-pos-type') || '0', 10);
        var prev = pmSel.value;
        pmSel.innerHTML = '';
        var o0 = document.createElement('option');
        o0.value = '0';
        o0.textContent = '— keine —';
        pmSel.appendChild(o0);
        (rows || []).forEach(function(r) {
            if (parseInt(r.type, 10) !== pt) return;
            var o = document.createElement('option');
            o.value = String(r.id);
            o.textContent = String(r.name);
            pmSel.appendChild(o);
        });
        pmSel.value = prev;
        if (pmSel.value !== prev) pmSel.value = '0';
    });
}

function manageSavePositionMeta(rowid) {
    var sub = $('pm_sub_' + rowid), tile = $('pm_tile_' + rowid), col = $('pm_color_' + rowid), def = $('pm_tile_def_' + rowid);
    if (!sub || !tile || !col) return;
    var body = {
        rowid: String(rowid),
        subcategory_id: sub.value,
        color: col.value,
        tile_use_default: (def && def.checked) ? '1' : '0',
        tile_bg: (def && def.checked) ? '' : tile.value
    };
    apiPost('position_meta_save.php', body)
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
        .then(function(x) {
            if (x.j && x.j.ok) alert('Gespeichert.');
            else alert('Fehler: ' + savePositionMetaErr(x.j && x.j.error) + (x.j && x.j.detail ? ' ' + x.j.detail : ''));
        })
        .catch(function() { alert('Fehler (Netzwerk).'); });
}

function manageProduktNeu() {
    ffSyncPosSubcategoryOptions();
    var subSel = $('posSubcategory'), subId = '0';
    if (subSel && subSel.selectedOptions.length) {
        var o = subSel.selectedOptions[0];
        var ot = parseInt(o.getAttribute('data-type') || '0', 10);
        var t = parseInt($('produktkategorie').value, 10);
        if (ot === 0 || ot === t) subId = subSel.value;
    }
    var tileBg = ($('pos_tile_bg') && $('pos_tile_bg').value) ? $('pos_tile_bg').value : '';
    var d = {
        Positionsname: $('Positionsname').value,
        type: $('produktkategorie').value,
        Betrag: $('Betrag').value,
        Kapazitaet: $('Kapazitaet').value,
        Selbstkosten: ($('Selbstkosten') && $('Selbstkosten').value !== '' ? $('Selbstkosten').value : '0'),
        subcategory_id: subId,
        tile_bg: tileBg,
        print_target: ($('pos_print_target') && $('pos_print_target').value) ? $('pos_print_target').value : '',
        kassa_only: ($('pos_new_kassa_only') && $('pos_new_kassa_only').checked) ? '1' : '0'
    };
    if (!d.Positionsname || !d.Betrag) { alert('Name und Preis erforderlich'); return; }
    apiPost('produktNeu.php', d)
        .then(function(r) { return r.text().then(function(t) { return { ok: r.ok, t: t }; }); })
        .then(function(x) {
            if (x.ok && String(x.t).indexOf('erfolgreich') !== -1) {
                loadPositionsErweitert();
            } else {
                alert('Fehler: ' + (x.t || 'Server'));
            }
        })
        .catch(function() { alert('Fehler'); });
}

function manageProduktLoeschen(rowid) {
    if (!confirm('Position wirklich löschen?')) return;
    apiPost('produkt_loeschen.php', { rowid: rowid })
        .then(function() { loadPositionsErweitert(); })
        .catch(function() { alert('Fehler'); });
}

function manageUpdateSelbstkosten(rowid, currentVal) {
    var v = prompt('EK / Selbstkosten neu in € (Komma oder Punkt erlaubt):', currentVal);
    if (v === null || String(v).trim() === '') return;
    var num = parseFloat(String(v).replace(',', '.'));
    if (isNaN(num) || num < 0) {
        alert('Ungültiger Betrag.');
        return;
    }
    apiPost('update_selbstkosten.php', { rowid: rowid, selbstkosten: num })
        .then(function() { manageReloadPositionsList(); })
        .catch(function() { alert('Fehler'); });
}

function manageUpdateKapazitaet(position, kapazitaet) {
    var k = prompt('Neue Kapazität:', kapazitaet);
    if (k === null) return;
    apiGet('update_kapazitaet.php?rowid=' + encodeURIComponent(position) + '&kapazitaet=' + encodeURIComponent(k))
        .then(function() { loadPositionsErweitert(); })
        .catch(function() { alert('Fehler'); });
}

function manageSubcategoryReload() {
    apiGet('subcategories_admin_api.php')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.ok) return;
            var tbody = $('subcategoriesTbodyManage');
            if (tbody) {
                tbody.innerHTML = '';
                if (!d.rows || !d.rows.length) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-muted">Noch keine Unterkategorien.</td></tr>';
                } else {
                    d.rows.forEach(function(r) {
                        var typ = parseInt(r.type, 10) === 2 ? 'Getränk' : 'Speise';
                        var id = parseInt(r.id, 10);
                        var pick = String(r.tile_bg || '').trim();
                        if (!/^#[0-9A-Fa-f]{6}$/i.test(pick)) {
                            pick = /^[0-9A-Fa-f]{6}$/i.test(pick) ? ('#' + pick) : '#ffffff';
                        }
                        var tr = document.createElement('tr');
                        var kassaChk = parseInt(r.kassa_only, 10) === 1 ? ' checked' : '';
                        tr.innerHTML = '<td>' + typ + '</td><td>' + String(r.name) + '</td><td><input type="number" class="form-control form-control-sm" id="subcat_sort_' + id + '" value="' + String(r.sort_order) + '"></td>' +
                            '<td><input type="color" class="form-control form-control-color form-control-sm" id="subcat_color_' + id + '" value="' + pick + '"></td>' +
                            '<td class="text-center"><input type="checkbox" class="form-check-input" id="subcat_kassa_' + id + '"' + kassaChk + ' title="Nur Direktverkauf"></td>' +
                            '<td><button type="button" class="btn btn-sm btn-outline-primary" data-sid="' + id + '">Speichern</button> <button type="button" class="btn btn-sm btn-outline-danger" data-did="' + id + '">Löschen</button></td>';
                        tr.querySelector('[data-sid]').onclick = function() { manageSubcategorySave(id); };
                        tr.querySelector('[data-did]').onclick = function() { manageSubcategoryDelete(id); };
                        tbody.appendChild(tr);
                    });
                }
            }
            manageApplySubcategoryRowsToSelects(d.rows || []);
        })
        .catch(function() { alert('Unterkategorien konnten nicht geladen werden.'); });
}

function manageSubcategorySave(id) {
    id = parseInt(id, 10);
    if (!id) return;
    var sortEl = $('subcat_sort_' + id), colEl = $('subcat_color_' + id), kassaEl = $('subcat_kassa_' + id);
    if (!sortEl || !colEl) return;
    apiPost('subcategories_admin_api.php', {
        action: 'update',
        id: String(id),
        sort_order: String(sortEl.value || '0'),
        tile_bg: colEl.value || '',
        kassa_only: (kassaEl && kassaEl.checked) ? '1' : '0'
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d && d.ok) { alert('Gespeichert.'); manageSubcategoryReload(); }
            else alert('Fehler: ' + (d && d.error === 'bad_tile_bg' ? savePositionMetaErr('bad_tile_bg') : (d && d.error ? d.error : '')));
        })
        .catch(function() { alert('Fehler (Netzwerk).'); });
}

function manageSubcategoryAdd() {
    var typ = $('subcat_new_type').value;
    var name = ($('subcat_new_name').value || '').trim();
    var sort = parseInt($('subcat_new_sort').value, 10) || 0;
    var col = $('subcat_new_color').value || '';
    if (!name) { alert('Name eingeben'); return; }
    var kassaOnly = ($('subcat_new_kassa_only') && $('subcat_new_kassa_only').checked) ? '1' : '0';
    apiPost('subcategories_admin_api.php', { action: 'add', type: typ, name: name, sort_order: String(sort), tile_bg: col, kassa_only: kassaOnly })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d && d.ok) {
                if ($('subcat_new_name')) $('subcat_new_name').value = '';
                manageSubcategoryReload();
                alert('Unterkategorie angelegt.');
            } else alert('Fehler: ' + (d && d.error ? d.error : ''));
        })
        .catch(function() { alert('Fehler'); });
}

function manageSubcategoryDelete(id) {
    if (!confirm('Unterkategorie löschen? Positionen werden auf „ohne Unterkategorie“ gesetzt.')) return;
    apiPost('subcategories_admin_api.php', { action: 'delete', id: String(id) })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d && d.ok) { manageSubcategoryReload(); alert('Unterkategorie gelöscht.'); }
            else alert('Fehler');
        })
        .catch(function() { alert('Fehler'); });
}

document.addEventListener('DOMContentLoaded', function() {
    var h = window.location.hash || '';
    if (h === '#beilagen') {
        loadBeilagenZusatzinfos();
    } else if (h === '#tische') {
        loadTische();
    } else {
        loadPositionsErweitert();
    }
});
        </script>
    </body>
</html>
