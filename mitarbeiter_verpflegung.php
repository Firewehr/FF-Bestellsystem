<?php
/**
 * Mitarbeiter-Verpflegung erfassen – für alle eingeloggten User (Küche, Schank, Kellner, …).
 */
require_once 'auth.php';
require_once 'include/db.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_position_stock_summary.php';

header('Content-Type: text/html; charset=UTF-8');

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mitarbeiter_bereiche (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(64) NOT NULL, sort_order INT(11) NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mitarbeiter_verpflegung (id INT(11) NOT NULL AUTO_INCREMENT, datum DATE NOT NULL, bereich_id INT(11) NOT NULL, position_id INT(11) NOT NULL, menge INT(11) NOT NULL DEFAULT 1, notiz VARCHAR(255) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(64) NULL, PRIMARY KEY (id), KEY idx_datum (datum), KEY idx_bereich (bereich_id), KEY idx_position (position_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$resB = mysqli_query($conn, 'SELECT id, name FROM mitarbeiter_bereiche ORDER BY sort_order, name');
$bereiche = [];
if ($resB) {
    while ($r = mysqli_fetch_assoc($resB)) {
        $bereiche[] = $r;
    }
}
if (count($bereiche) === 0) {
    mysqli_query($conn, "INSERT IGNORE INTO mitarbeiter_bereiche (name, sort_order) VALUES ('Küche',10),('Schank',20),('Kellner',30),('Komando',40),('Jugendfeuerwehr',50),('Sonstige',99)");
    $resB = mysqli_query($conn, 'SELECT id, name FROM mitarbeiter_bereiche ORDER BY sort_order, name');
    if ($resB) {
        while ($r = mysqli_fetch_assoc($resB)) {
            $bereiche[] = $r;
        }
    }
}

$positionen = ff_mv_list_positions($conn);

$ffOrderCounts = ff_position_batch_order_counts($conn);
$ffMvCounts = ff_position_batch_mv_counts($conn);

$mvListDatum = date('Y-m-d');
$mvFormDatum = date('Y-m-d');
$mvFormBereich = count($bereiche) > 0 ? (int) $bereiche[0]['id'] : 0;
$mvDatumCounts = ff_mv_batch_counts_for_datum($conn, $mvFormDatum, $mvFormBereich);
$mvIsAdmin = isset($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1;
$mvCurrentUser = (string) ($_SESSION['user']['username'] ?? '');

function mv_out($s): string
{
    return ff_mv_html_esc((string) $s);
}
?>
<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <a href="#indexPage" class="btn btn-outline-secondary btn-sm" onclick="<?php echo mv_out(ff_nav_home_onclick()); ?>">← Menü</a>
        <span class="navbar-brand mb-0">Mitarbeiter-Verpflegung</span>
    </div>
</nav>
<div class="app-content py-4" id="mvFormRoot"
     data-mv-save-url="mitarbeiter_verpflegung_save.php"
     data-mv-batch-url="mitarbeiter_verpflegung_save_batch.php"
     data-mv-minus-url="mitarbeiter_verpflegung_minus.php"
     data-mv-counts-url="mitarbeiter_verpflegung_counts_api.php"
     data-mv-stock-url="mitarbeiter_verpflegung_stock_api.php"
     data-mv-delete-url="mitarbeiter_verpflegung_delete.php"
     data-mv-list-url="mitarbeiter_verpflegung_list.php"
     data-mv-is-admin="<?php echo $mvIsAdmin ? '1' : '0'; ?>">
    <p class="text-muted small">Wie am Tisch: <strong>Kacheln antippen</strong> füllen den Warenkorb (mehrfach = mehr Stück). Dann <strong>„Verpflegung erfassen“</strong> — danach ist der Warenkorb leer und die Zähler auf den Kacheln sind wieder weg. <strong>Minus (–)</strong> nimmt 1 Stück aus dem Warenkorb.</p>

    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">Eintrag erfassen</h5>
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label" for="mvDatum">Datum</label>
                    <input type="date" id="mvDatum" class="form-control form-control-sm" value="<?php echo mv_out(date('Y-m-d')); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="mvBereich">Bereich</label>
                    <select id="mvBereich" class="form-select form-select-sm">
                        <?php foreach ($bereiche as $b): ?>
                        <option value="<?php echo (int) $b['id']; ?>"><?php echo mv_out($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="mvMenge" title="Anzahl pro Kachel-Tipp">Pro Tipp</label>
                    <input type="number" id="mvMenge" class="form-control form-control-sm" value="1" min="1" max="50">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="mvNotiz">Notiz (optional)</label>
                    <input type="text" id="mvNotiz" class="form-control form-control-sm" maxlength="255" placeholder="für diese Erfassung">
                </div>
                <div class="col-md-2 d-flex align-items-end gap-1">
                    <button type="button" id="mvSubmitBtn" class="btn btn-success flex-grow-1" disabled>Verpflegung erfassen</button>
                </div>
            </div>
            <div class="card border-primary border-opacity-25 bg-light mt-2 mb-2" id="mvCartCard">
                <div class="card-body py-2 px-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <strong class="small mb-0">Warenkorb</strong>
                        <button type="button" class="btn btn-link btn-sm p-0 text-danger" id="mvCartClearBtn" disabled>Leeren</button>
                    </div>
                    <div id="mvCartBody" class="small text-muted mt-1">Noch keine Positionen gewählt.</div>
                </div>
            </div>
            <div class="row g-2 mt-1">
                <div class="col-12">
                    <label class="form-label mb-1">Positionen</label>
                    <?php ff_mv_echo_position_picker($conn, $positionen, $ffOrderCounts, $ffMvCounts, []); ?>
                </div>
            </div>
            <div id="mvMsg" class="mt-2 small" aria-live="polite"></div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">Erfasste Verpflegung</h5>
            <div class="row g-2 mb-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="mvListDatum">Datum anzeigen</label>
                    <input type="date" id="mvListDatum" class="form-control form-control-sm" value="<?php echo mv_out($mvListDatum); ?>">
                </div>
                <div class="col-md-2">
                    <button type="button" id="mvListRefreshBtn" class="btn btn-outline-primary btn-sm w-100">Anzeigen</button>
                </div>
            </div>
            <p class="text-muted small mb-2">Löschen: eigene Einträge oder als Admin alle. Die Kapazität der Position steigt danach wieder.</p>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Datum</th><th>Bereich</th><th>Position</th><th>Menge</th>
                            <th>Erfasst um</th><th>Notiz</th><th>Erfasst von</th><th></th>
                        </tr>
                    </thead>
                    <tbody id="mvListTbody">
                        <?php echo ff_mv_render_list_rows($conn, $mvListDatum, $mvCurrentUser, $mvIsAdmin); ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-muted small">Bereiche anlegen/ändern und alle Einträge verwalten auch im <a href="admin.php#MitarbeiterVerpflegung">Admin → Mitarbeiter-Verpflegung</a>.</p>
</div>
<style>
.mv-pos-picker .nav-tabs .nav-link { white-space: nowrap; padding: .35rem .75rem; font-size: .9rem; }
.mv-pos-picker .mv-pos-wrap .btnMinus { display: inline-flex; }
</style>
<script>
(function() {
    var _mvInflight = {};
    /** Warenkorb (noch nicht gespeichert): position_id -> { count, label } */
    var _mvCart = {};

    function mvRoot() {
        return document.getElementById('mvFormRoot');
    }
    function mvBatchUrl() {
        var r = mvRoot();
        return (r && r.getAttribute('data-mv-batch-url')) || 'mitarbeiter_verpflegung_save_batch.php';
    }
    function mvSaveUrl() {
        var r = mvRoot();
        return (r && r.getAttribute('data-mv-save-url')) || 'mitarbeiter_verpflegung_save.php';
    }
    function mvMinusUrl() {
        var r = mvRoot();
        return (r && r.getAttribute('data-mv-minus-url')) || 'mitarbeiter_verpflegung_minus.php';
    }
    function mvCountsUrl() {
        var r = mvRoot();
        return (r && r.getAttribute('data-mv-counts-url')) || 'mitarbeiter_verpflegung_counts_api.php';
    }
    function mvDeleteUrl() {
        var r = mvRoot();
        return (r && r.getAttribute('data-mv-delete-url')) || 'mitarbeiter_verpflegung_delete.php';
    }
    function mvListUrl() {
        var r = mvRoot();
        return (r && r.getAttribute('data-mv-list-url')) || 'mitarbeiter_verpflegung_list.php';
    }
    function mvEsc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function mvFormCtx() {
        var datumEl = document.getElementById('mvDatum');
        var bereichEl = document.getElementById('mvBereich');
        return {
            datum: datumEl ? datumEl.value : '',
            bereich_id: bereichEl ? bereichEl.value : ''
        };
    }

    function mvCartCount(positionId) {
        var row = _mvCart[String(positionId)] || _mvCart[positionId];
        return row ? Math.max(0, parseInt(String(row.count), 10) || 0) : 0;
    }

    function mvClearCart() {
        _mvCart = {};
        document.querySelectorAll('.mv-pos-tile').forEach(function(btn) {
            var id = parseInt(btn.id.replace('mv-btn-pos-', ''), 10);
            if (id > 0) mvUpdateTile(id, 0);
        });
        mvRenderCart();
    }

    function mvRenderCart() {
        var body = document.getElementById('mvCartBody');
        var submitBtn = document.getElementById('mvSubmitBtn');
        var clearBtn = document.getElementById('mvCartClearBtn');
        var total = 0;
        var parts = [];
        Object.keys(_mvCart).forEach(function(k) {
            var row = _mvCart[k];
            var c = parseInt(String(row.count), 10) || 0;
            if (c <= 0) return;
            total += c;
            parts.push('<span class="badge bg-primary me-1 mb-1">' + mvEsc(row.label) + ' ' + c + '×</span>');
        });
        if (body) {
            body.innerHTML = total > 0
                ? parts.join(' ')
                : 'Noch keine Positionen gewählt.';
        }
        if (submitBtn) submitBtn.disabled = total <= 0;
        if (clearBtn) clearBtn.disabled = total <= 0;
    }

    window.mvUpdateTile = function(positionId, pickCnt, rest, max) {
        var btn = document.getElementById('mv-btn-pos-' + positionId);
        var cntEl = document.getElementById('mv-cnt-' + positionId);
        if (!btn || !cntEl) return;
        var cnt = Math.max(0, parseInt(String(pickCnt), 10) || 0);
        cntEl.setAttribute('data-cnt', String(cnt));
        cntEl.textContent = cnt > 0 ? (' (' + cnt + 'x)') : '';
        if (typeof rest === 'number') {
            btn.setAttribute('data-rest', String(rest));
        }
        if (typeof max === 'number' && max > 0) {
            btn.setAttribute('data-max', String(max));
        }
        var r = parseInt(btn.getAttribute('data-rest') || '999', 10);
        var m = parseInt(btn.getAttribute('data-max') || '0', 10);
        var soldOut = m > 0 && r <= 0;
        var low = m > 0 && r > 0 && r < 10;
        btn.classList.remove('pos-tile--selected', 'pos-tile--sold-out', 'pos-tile--low-stock');
        if (soldOut) {
            btn.disabled = true;
            btn.classList.add('pos-tile--sold-out');
        } else {
            btn.disabled = false;
            if (cnt > 0 && !low) btn.classList.add('pos-tile--selected');
            if (low) btn.classList.add('pos-tile--low-stock');
        }
    };

    function mvFlashMsg(html, isErr) {
        var msg = document.getElementById('mvMsg');
        if (!msg) return;
        msg.innerHTML = html;
        if (!isErr) {
            window.setTimeout(function() {
                if (msg.innerHTML === html) msg.innerHTML = '';
            }, 2200);
        }
    }

    window.mvAddPosition = function(positionId) {
        var pid = parseInt(String(positionId), 10) || 0;
        if (pid <= 0) return;
        var ctx = mvFormCtx();
        if (!ctx.datum || !ctx.bereich_id) {
            mvFlashMsg('<span class="text-danger">Datum und Bereich wählen.</span>', true);
            return;
        }
        var btn = document.getElementById('mv-btn-pos-' + pid);
        if (!btn || btn.disabled) return;
        var menge = parseInt(document.getElementById('mvMenge').value, 10) || 1;
        var max = parseInt(btn.getAttribute('data-max') || '0', 10);
        var rest = parseInt(btn.getAttribute('data-rest') || '999', 10);
        var inCart = mvCartCount(pid);
        if (max > 0 && inCart + menge > rest) {
            mvFlashMsg('<span class="text-danger">Nur noch ' + rest + ' verfügbar (inkl. Warenkorb).</span>', true);
            return;
        }
        var label = btn.getAttribute('data-label') || btn.textContent.split('(')[0].trim();
        if (!_mvCart[String(pid)]) {
            _mvCart[String(pid)] = { count: 0, label: label };
        }
        _mvCart[String(pid)].count += menge;
        mvUpdateTile(pid, _mvCart[String(pid)].count);
        mvRenderCart();
    };

    window.mvMinusPosition = function(ev, positionId) {
        if (ev && ev.stopPropagation) ev.stopPropagation();
        var pid = parseInt(String(positionId), 10) || 0;
        if (pid <= 0) return false;
        var key = String(pid);
        if (!_mvCart[key] || _mvCart[key].count <= 0) {
            return false;
        }
        _mvCart[key].count -= 1;
        if (_mvCart[key].count <= 0) {
            delete _mvCart[key];
        }
        mvUpdateTile(pid, mvCartCount(pid));
        mvRenderCart();
        return false;
    };

    window.mvSubmitCart = function() {
        var ctx = mvFormCtx();
        if (!ctx.datum || !ctx.bereich_id) {
            mvFlashMsg('<span class="text-danger">Datum und Bereich wählen.</span>', true);
            return;
        }
        var items = [];
        Object.keys(_mvCart).forEach(function(k) {
            var row = _mvCart[k];
            var c = parseInt(String(row.count), 10) || 0;
            if (c > 0) {
                items.push({ position_id: parseInt(k, 10), menge: c });
            }
        });
        if (items.length === 0) {
            mvFlashMsg('<span class="text-warning">Warenkorb ist leer.</span>', true);
            return;
        }
        var submitBtn = document.getElementById('mvSubmitBtn');
        if (submitBtn) submitBtn.disabled = true;
        mvFlashMsg('<span class="text-muted">Wird gespeichert…</span>', false);
        var notiz = (document.getElementById('mvNotiz').value || '').trim();
        var fd = new FormData();
        fd.append('datum', ctx.datum);
        fd.append('bereich_id', ctx.bereich_id);
        fd.append('notiz', notiz);
        fd.append('items', JSON.stringify(items));
        fetch(mvBatchUrl(), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) {
                return r.text().then(function(t) {
                    try { return JSON.parse(t); } catch (e) {
                        throw new Error(t && t.length < 200 ? t : 'Ungültige Server-Antwort');
                    }
                });
            })
            .then(function(res) {
                if (!res || !res.ok) {
                    var errTxt = (res && res.message) ? res.message : ((res && res.error) ? res.error : 'Fehler');
                    mvFlashMsg('<span class="text-danger">' + mvEsc(errTxt) + '</span>', true);
                    mvRenderCart();
                    return;
                }
                mvClearCart();
                if (res.rest_map) {
                    Object.keys(res.rest_map).forEach(function(k) {
                        var info = res.rest_map[k];
                        mvUpdateTile(parseInt(k, 10), 0, info.rest, info.max);
                    });
                }
                document.getElementById('mvNotiz').value = '';
                mvFlashMsg('<span class="text-success">Verpflegung erfasst (' + (res.saved || items.length) + ' Positionen).</span>', false);
                var listDatum = document.getElementById('mvListDatum');
                if (listDatum && listDatum.value === ctx.datum) {
                    window.mvMvReloadList();
                }
            })
            .catch(function(err) {
                mvFlashMsg('<span class="text-danger">Speichern fehlgeschlagen' + (err && err.message ? ': ' + mvEsc(err.message) : '') + '.</span>', true);
                mvRenderCart();
            });
    };

    window.mvMvRefreshTileCounts = function() {
        if (Object.keys(_mvCart).length > 0) {
            if (!confirm('Warenkorb verwerfen und Datum/Bereich wechseln?')) {
                return;
            }
            mvClearCart();
        }
    };

    function mvInitPosPicker() {
        var picker = document.getElementById('mvPosPicker');
        if (!picker || picker.getAttribute('data-mv-bound') === '1') return;
        picker.setAttribute('data-mv-bound', '1');
        picker.addEventListener('click', function(e) {
            var tab = e.target.closest('.mv-pos-tab');
            if (!tab) return;
            e.preventDefault();
            var key = tab.getAttribute('data-mv-tab');
            picker.querySelectorAll('.mv-pos-tab').forEach(function(t) {
                var on = t === tab;
                t.classList.toggle('active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            picker.querySelectorAll('.mv-pos-panel').forEach(function(p) {
                p.classList.toggle('d-none', p.getAttribute('data-mv-tab') !== key);
            });
        });
    }

    window.mvMvReloadList = function() {
        var tbody = document.getElementById('mvListTbody');
        var listDatum = document.getElementById('mvListDatum');
        if (!tbody || !listDatum) return;
        var d = listDatum.value || '';
        tbody.innerHTML = '<tr><td colspan="8" class="text-muted">Laden…</td></tr>';
        fetch(mvListUrl() + '?datum=' + encodeURIComponent(d), { credentials: 'same-origin' })
            .then(function(r) { return r.text(); })
            .then(function(html) { tbody.innerHTML = html; mvBindListButtons(); })
            .catch(function() { tbody.innerHTML = '<tr><td colspan="8" class="text-danger">Liste konnte nicht geladen werden.</td></tr>'; });
    };

    window.mvMvLoeschen = function(id) {
        if (!id || !confirm('Diesen Verpflegungseintrag wirklich löschen? Die Kapazität der Position wird wieder freigegeben.')) return;
        var fd = new FormData();
        fd.append('id', String(id));
        fetch(mvDeleteUrl(), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res && res.ok) {
                    var row = document.querySelector('#mvListTbody tr[data-mv-id="' + id + '"]');
                    if (row) row.remove();
                    if (!document.querySelector('#mvListTbody tr[data-mv-id]')) {
                        document.getElementById('mvListTbody').innerHTML = '<tr><td colspan="8" class="text-muted">Keine Einträge für dieses Datum.</td></tr>';
                    }
                    window.mvMvReloadList();
                } else {
                    alert((res && res.error) ? res.error : 'Löschen fehlgeschlagen');
                }
            })
            .catch(function() { alert('Löschen fehlgeschlagen'); });
    };

    function mvBindListButtons() {
        var tbody = document.getElementById('mvListTbody');
        if (!tbody || tbody.getAttribute('data-mv-list-bound') === '1') {
            return;
        }
        tbody.setAttribute('data-mv-list-bound', '1');
        tbody.addEventListener('click', function(e) {
            var btn = e.target.closest('.mv-del-btn');
            if (!btn) return;
            e.preventDefault();
            window.mvMvLoeschen(parseInt(btn.getAttribute('data-mv-id'), 10));
        });
    }

    function mvInitForm() {
        var listBtn = document.getElementById('mvListRefreshBtn');
        var listDatum = document.getElementById('mvListDatum');
        var datumEl = document.getElementById('mvDatum');
        var bereichEl = document.getElementById('mvBereich');
        var submitBtn = document.getElementById('mvSubmitBtn');
        var clearBtn = document.getElementById('mvCartClearBtn');
        mvInitPosPicker();
        mvRenderCart();
        if (submitBtn && !submitBtn.getAttribute('data-mv-bound')) {
            submitBtn.setAttribute('data-mv-bound', '1');
            submitBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.mvSubmitCart();
            });
        }
        if (clearBtn && !clearBtn.getAttribute('data-mv-bound')) {
            clearBtn.setAttribute('data-mv-bound', '1');
            clearBtn.addEventListener('click', function(e) {
                e.preventDefault();
                mvClearCart();
            });
        }
        if (datumEl && !datumEl.getAttribute('data-mv-bound')) {
            datumEl.setAttribute('data-mv-bound', '1');
            datumEl.addEventListener('change', window.mvMvRefreshTileCounts);
        }
        if (bereichEl && !bereichEl.getAttribute('data-mv-bound')) {
            bereichEl.setAttribute('data-mv-bound', '1');
            bereichEl.addEventListener('change', window.mvMvRefreshTileCounts);
        }
        if (listBtn && !listBtn.getAttribute('data-mv-bound')) {
            listBtn.setAttribute('data-mv-bound', '1');
            listBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.mvMvReloadList();
            });
        }
        if (listDatum && !listDatum.getAttribute('data-mv-bound')) {
            listDatum.setAttribute('data-mv-bound', '1');
            listDatum.addEventListener('change', window.mvMvReloadList);
        }
        mvBindListButtons();
    }
    mvInitForm();
})();
</script>
