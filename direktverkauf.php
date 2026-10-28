<?php
require_once('auth.php');
include_once('include/db.php');
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
ff_direktverkauf_require($conn, false);
require_once('include/settings.php');
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/menu_list_helpers.php';
require_once __DIR__ . '/include/ff_direktverkauf_bon_helpers.php';
require_once __DIR__ . '/include/ff_admin_ui_helpers.php';
require_once __DIR__ . '/include/dv_abholbon_target.php';
require_once __DIR__ . '/include/ff_print_target_labels.php';
header('Cache-Control: no-cache, must-revalidate');
$Tischnummer = 999999;
$dvPageBonId = ff_direktverkauf_resolve_page_bon_id($conn);
$dvBonDayPrefix = ff_direktverkauf_bon_today_day();
$dvBonTodayYmd = ff_direktverkauf_today_ymd();
$dvPaybarHtml = ff_direktverkauf_capture_fragment($conn, 'zahlen_direktverkauf.php', $dvPageBonId);
$dvGetraenkeHtml = ff_direktverkauf_capture_fragment($conn, 'listGetraenke_direktverkauf.php', $dvPageBonId);

ff_users_ensure_landing_columns($conn);
$dvThermoSavedPt = null;
$dvThermoHeadLabel = '';
$dvThermoBodyCollapsed = false;
$dvUname = trim((string) ($_SESSION['user']['username'] ?? ''));
if ($dvUname !== '') {
    $stDv = mysqli_prepare($conn, 'SELECT start_page, start_print_target, dv_abholbon_print_target FROM users WHERE username = ? LIMIT 1');
    if ($stDv) {
        mysqli_stmt_bind_param($stDv, 's', $dvUname);
        mysqli_stmt_execute($stDv);
        $resDv = mysqli_stmt_get_result($stDv);
        $rowDv = $resDv ? mysqli_fetch_assoc($resDv) : null;
        mysqli_stmt_close($stDv);
        if ($rowDv) {
            if (isset($rowDv['dv_abholbon_print_target']) && $rowDv['dv_abholbon_print_target'] !== null && $rowDv['dv_abholbon_print_target'] !== '') {
                $dvThermoSavedPt = (int) $rowDv['dv_abholbon_print_target'];
                $dvThermoBodyCollapsed = $dvThermoSavedPt > 0;
            }
            $spt = isset($rowDv['start_print_target']) ? (int) $rowDv['start_print_target'] : 0;
            $resolvedPt = ff_user_resolve_dv_abholbon_print_target(
                $conn,
                $dvThermoSavedPt,
                (string) ($rowDv['start_page'] ?? 'menu'),
                $spt > 0 ? $spt : null
            );
            $dvThermoHeadLabel = ff_print_target_display_name($conn, $resolvedPt);
        }
    }
}

// Prüfe ob bereits Bon-ID in Session existiert, sonst neue generieren
// Bon-ID wird pro "Warenkorb" verwendet und nach Bezahlung zurückgesetzt
?>
<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <a href="#indexPage" class="btn btn-outline-secondary btn-sm" onclick="<?php echo htmlspecialchars(ff_nav_home_onclick(), ENT_QUOTES, 'UTF-8'); ?>">← Menü</a>
        <span class="navbar-brand mb-0">Direktverkauf</span>
        <div>
            <span id="dvBonIdDisplay" class="badge bg-warning text-dark me-2" style="font-size:1.1rem;"></span>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="DirektHistory();">Historie</button>
        </div>
    </div>
</nav>

<div class="app-content py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <button type="button" id="ffDvResetBtn" class="btn btn-warning ff-tap-fast">Reset / Neuer Kunde</button>
        </div>
        <div class="text-end">
            <span class="text-muted">Aktuelle Bon-Nr.:</span>
            <strong id="dvBonIdLarge" class="fs-3 text-primary"></strong>
            <span class="small text-muted d-block" id="dvBonServerDateHint"
                  title="Neue Bons beginnen mit dem Kalendertag dieses Datums (Server Europe/Vienna)">
                Server-Tag: <?php echo htmlspecialchars($dvBonTodayYmd, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
    </div>

    <div class="card border-secondary mb-3" id="ffDvThermoBonCard">
        <div class="card-header py-2 px-3 d-flex flex-wrap align-items-center gap-2 bg-light">
            <div class="d-flex flex-wrap align-items-center gap-2 flex-grow-1 ff-dv-thermo-head<?php echo $dvThermoBodyCollapsed ? ' collapsed' : ''; ?>"
                 role="button" tabindex="0" data-bs-toggle="collapse" data-bs-target="#ffDvThermoBonBody"
                 aria-expanded="<?php echo $dvThermoBodyCollapsed ? 'false' : 'true'; ?>" aria-controls="ffDvThermoBonBody">
                <span class="small fw-semibold">Abholbon Thermodrucker</span>
                <span class="small text-muted" id="ffDvThermoHeadSummary"><?php
                    if ($dvThermoHeadLabel !== '') {
                        echo 'Aktuell: ' . htmlspecialchars($dvThermoHeadLabel, ENT_QUOTES, 'UTF-8');
                    }
                ?></span>
            </div>
            <?php echo ff_admin_info_btn('ffDvThermoHint', 'Hinweis Abholbon & PDF'); ?>
        </div>
        <div id="ffDvThermoBonBody" class="collapse<?php echo $dvThermoBodyCollapsed ? '' : ' show'; ?>">
            <div class="card-body py-2 px-3 border-top">
                <?php
                ff_admin_info_panel(
                    'ffDvThermoHint',
                    '<p class="mb-2">Nach <strong>Bezahlen</strong> wird der Abholbon an die gewählte Thermo-Warteschlange geschickt '
                    . '(Python-Print-Client: gleiche Druckziel-ID in der <code>config.ini</code>).</p>'
                    . '<p class="mb-0"><strong>PDF-Bon:</strong> Wenn angehakt, öffnet sich zusätzlich ein PDF im Browser. '
                    . 'Ohne Haken nur Thermodruck, kein PDF-Fenster.</p>'
                );
                ?>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                    <label class="small text-muted mb-0" for="ffDvBonThermoSelect">Drucker</label>
                    <select id="ffDvBonThermoSelect" class="form-select form-select-sm" style="max-width: 22rem;" disabled>
                        <option value="">Laden…</option>
                    </select>
                    <span class="small text-muted" id="ffDvBonThermoHint"></span>
                </div>
                <div class="form-check mt-2 mb-0">
                    <input class="form-check-input" type="checkbox" id="ffDvPdfBonAfterPay">
                    <label class="form-check-label small" for="ffDvPdfBonAfterPay"><strong>PDF-Bon</strong> nach Bezahlung im Browser</label>
                </div>
            </div>
        </div>
    </div>

    <div id="ffDvPaybar" class="ff-dv-paybar-sticky mb-2" data-ssr="1">
        <?php echo $dvPaybarHtml; ?>
    </div>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="ffDvCartOffcanvas" aria-labelledby="ffDvCartOffcanvasLabel">
        <div class="offcanvas-header border-bottom py-2">
            <h5 class="offcanvas-title fs-6" id="ffDvCartOffcanvasLabel">Warenkorb (aktueller Bon)</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Schließen"></button>
        </div>
        <div class="offcanvas-body pt-2" id="ffDvCartOffcanvasBody">
            <p class="text-muted small">Wird geladen …</p>
        </div>
        <div class="offcanvas-footer border-top p-3 d-none" id="ffDvCartOffcanvasFooter">
            <button type="button" class="btn btn-success w-100 ff-tap-fast" id="ffDvCartPayBtn">Bezahlen</button>
        </div>
    </div>

    <ul class="nav nav-tabs" id="TischAnzeigenDirektverkauf" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-getraenke-dv-btn" data-bs-toggle="tab" data-bs-target="#tabGetraenkeDirektverkauf" type="button">Getränke</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-speisen-dv-btn" data-bs-toggle="tab" data-bs-target="#tabSpeisenDirektverkauf" type="button">Speisen</button>
        </li>
    </ul>

    <div class="tab-content border border-top-0 p-2 ff-tab-swipe-area" id="DirektverkaufTabContent">
        <div class="tab-pane fade show active" id="tabGetraenkeDirektverkauf" role="tabpanel" data-load-url="listGetraenke_direktverkauf.php?tischnummer=<?php echo (int)$Tischnummer; ?>">
            <div class="tab-content-inner" data-loaded="1"><?php echo $dvGetraenkeHtml; ?></div>
        </div>
        <div class="tab-pane fade" id="tabSpeisenDirektverkauf" role="tabpanel" data-load-url="listSpeisen_direktverkauf.php?tischnummer=<?php echo (int)$Tischnummer; ?>">
            <div class="tab-content-inner"></div>
        </div>
    </div>
</div>

<script>
(function() {
    var serverBon = <?php echo json_encode($dvPageBonId, JSON_UNESCAPED_UNICODE); ?>;
    var serverBonDayPrefix = <?php echo (int) $dvBonDayPrefix; ?>;
    var serverBonTodayYmd = <?php echo json_encode($dvBonTodayYmd, JSON_UNESCAPED_UNICODE); ?>;

    function updateBonDisplay(id) {
        if (!id) return;
        var el1 = document.getElementById('dvBonIdDisplay');
        var el2 = document.getElementById('dvBonIdLarge');
        if (el1) el1.textContent = 'Bon #' + id;
        if (el2) el2.textContent = '#' + id;
        window.currentDirektverkaufBonId = id;
    }

    function dvBonMatchesToday(bonId) {
        var s = String(bonId || '').trim();
        var m = s.match(/^(\d{2})-\d{3}$/);
        if (!m) return false;
        return parseInt(m[1], 10) === serverBonDayPrefix;
    }

    if (serverBon) {
        localStorage.setItem('direktverkauf_bon_id', serverBon);
        updateBonDisplay(serverBon);
    } else {
        var stale = localStorage.getItem('direktverkauf_bon_id');
        if (stale && !dvBonMatchesToday(stale)) {
            localStorage.removeItem('direktverkauf_bon_id');
        }
    }

    window.resetDirektverkaufBonId = function() {
        localStorage.removeItem('direktverkauf_bon_id');
        return fetch('direktverkauf_bon.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.ok && data.bon_id) {
                    if (typeof data.day_prefix === 'number') {
                        serverBonDayPrefix = data.day_prefix;
                    }
                    if (data.today_ymd) {
                        serverBonTodayYmd = data.today_ymd;
                        var hintEl = document.getElementById('dvBonServerDateHint');
                        if (hintEl) {
                            hintEl.textContent = 'Server-Tag: ' + data.today_ymd;
                        }
                    }
                    localStorage.setItem('direktverkauf_bon_id', data.bon_id);
                    updateBonDisplay(data.bon_id);
                    if (typeof window.ffDvRefreshAll === 'function') {
                        window.ffDvRefreshAll();
                    }
                    return data.bon_id;
                }
                return '';
            });
    };
    
    // Globale Funktion um aktuelle Bon-ID zu bekommen (verwirft Vortags-Bon im Browser)
    window.getDirektverkaufBonId = function() {
        var id = localStorage.getItem('direktverkauf_bon_id') || '';
        if (id && !dvBonMatchesToday(id)) {
            localStorage.removeItem('direktverkauf_bon_id');
            if (typeof window.resetDirektverkaufBonId === 'function') {
                window.resetDirektverkaufBonId();
            }
            return window.currentDirektverkaufBonId || '';
        }
        return id;
    };

    /** Für BestellungBezahlt: PDF-Popup nach Bezahlung nur wenn angehakt (oder gespeichert). */
    window.ffDvWantsPdfBonAfterPay = function() {
        var el = document.getElementById('ffDvPdfBonAfterPay');
        if (el) return !!el.checked;
        return localStorage.getItem('dv_abholbon_pdf_browser') === '1';
    };

    (function initDvPdfBonPref() {
        var pdfCb = document.getElementById('ffDvPdfBonAfterPay');
        if (!pdfCb) return;
        pdfCb.checked = localStorage.getItem('dv_abholbon_pdf_browser') === '1';
        pdfCb.addEventListener('change', function() {
            localStorage.setItem('dv_abholbon_pdf_browser', this.checked ? '1' : '0');
        });
    })();

    var FF_DV_THERMO_COLLAPSED_LS = 'ff_dv_thermo_panel_collapsed';

    function ffDvThermoApplyPanelState(collapsed) {
        var bodyEl = document.getElementById('ffDvThermoBonBody');
        var headEl = document.querySelector('#ffDvThermoBonCard .ff-dv-thermo-head');
        if (!bodyEl || !headEl) return;
        if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
            var inst = bootstrap.Collapse.getOrCreateInstance(bodyEl, { toggle: false });
            if (collapsed) {
                inst.hide();
            } else {
                inst.show();
            }
        } else {
            bodyEl.classList.toggle('show', !collapsed);
        }
        headEl.classList.toggle('collapsed', !!collapsed);
        headEl.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    function ffDvThermoGetPanelCollapsedPref() {
        try {
            return localStorage.getItem(FF_DV_THERMO_COLLAPSED_LS);
        } catch (eLs) {
            return null;
        }
    }

    function ffDvThermoSetPanelCollapsedPref(collapsed) {
        try {
            localStorage.setItem(FF_DV_THERMO_COLLAPSED_LS, collapsed ? '1' : '0');
        } catch (eLs2) { /* ignore */ }
    }

    (function initDvThermoPanelMemory() {
        var bodyEl = document.getElementById('ffDvThermoBonBody');
        if (!bodyEl) return;
        var ls = ffDvThermoGetPanelCollapsedPref();
        if (ls === '1') {
            ffDvThermoApplyPanelState(true);
        } else if (ls === '0') {
            ffDvThermoApplyPanelState(false);
        }
        bodyEl.addEventListener('shown.bs.collapse', function() {
            ffDvThermoSetPanelCollapsedPref(false);
        });
        bodyEl.addEventListener('hidden.bs.collapse', function() {
            ffDvThermoSetPanelCollapsedPref(true);
        });
    })();

    function ffLoadDvBonThermoPrefs() {
        var sel = document.getElementById('ffDvBonThermoSelect');
        var hint = document.getElementById('ffDvBonThermoHint');
        fetch('user_dv_abholbon_prefs.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!sel) return;
                if (!d || !d.ok) {
                    sel.innerHTML = '<option value="">—</option>';
                    sel.disabled = true;
                    return;
                }
                sel.disabled = false;
                sel.innerHTML = '';
                var oAuto = document.createElement('option');
                oAuto.value = '';
                oAuto.textContent = 'Automatisch (je nach Startseite → derzeit ' + (d.auto_label || '?') + ')';
                sel.appendChild(oAuto);
                (d.targets || []).forEach(function(t) {
                    var o = document.createElement('option');
                    o.value = String(t.id);
                    o.textContent = t.name + ' (' + t.id + ')';
                    sel.appendChild(o);
                });
                if (d.saved_print_target == null || d.saved_print_target === '') {
                    sel.value = '';
                } else {
                    sel.value = String(d.saved_print_target);
                }
                if (hint) hint.textContent = d.hint || '';
                var headSum = document.getElementById('ffDvThermoHeadSummary');
                var bodyEl = document.getElementById('ffDvThermoBonBody');
                var headEl = document.querySelector('#ffDvThermoBonCard .ff-dv-thermo-head');
                if (headSum) {
                    var lbl = '';
                    if (d.saved_print_target != null && d.saved_print_target !== '') {
                        var opt = sel.querySelector('option[value="' + String(d.saved_print_target) + '"]');
                        lbl = opt ? opt.textContent : ('Druckziel ' + d.saved_print_target);
                    } else if (d.resolved_print_target) {
                        lbl = 'Automatisch → ' + (d.auto_label || ('ID ' + d.resolved_print_target));
                    }
                    headSum.textContent = lbl ? ('Aktuell: ' + lbl) : '';
                }
                var lsPanel = ffDvThermoGetPanelCollapsedPref();
                if (lsPanel === null && d.saved_print_target != null && d.saved_print_target !== '') {
                    ffDvThermoApplyPanelState(true);
                    ffDvThermoSetPanelCollapsedPref(true);
                }
            })
            .catch(function() {
                if (sel) {
                    sel.innerHTML = '<option value="">—</option>';
                    sel.disabled = false;
                }
            });
    }

    var ffDvThermoSel = document.getElementById('ffDvBonThermoSelect');
    if (ffDvThermoSel) {
        ffDvThermoSel.addEventListener('change', function() {
            var fd = new FormData();
            fd.append('dv_abholbon_print_target', this.value);
            fetch('save_user_dv_abholbon_target.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(j) {
                    if (j && j.ok) {
                        ffLoadDvBonThermoPrefs();
                    } else {
                        alert(j && j.error ? j.error : 'Speichern fehlgeschlagen');
                        ffLoadDvBonThermoPrefs();
                    }
                })
                .catch(function() {
                    alert('Netzwerkfehler');
                    ffLoadDvBonThermoPrefs();
                });
        });
    }
    if (typeof window.requestIdleCallback === 'function') {
        requestIdleCallback(function() { ffLoadDvBonThermoPrefs(); }, { timeout: 2500 });
    } else {
        setTimeout(ffLoadDvBonThermoPrefs, 400);
    }
    if (typeof window.ffDvBindPaybar === 'function') {
        window.ffDvBindPaybar();
    }
})();
</script>
<style>
    .ff-dv-paybar-sticky {
        position: sticky;
        top: 3.25rem;
        z-index: 1020;
        background: var(--bs-body-bg, #f8f9fa);
        padding-bottom: 0.25rem;
    }
    #Direktverkauf .ff-dv-paybar-compact .card-body {
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
    }
    #ffDvThermoBonCard .ff-dv-thermo-head {
        cursor: pointer;
        user-select: none;
    }
    #ffDvThermoBonCard .ff-dv-thermo-head::after {
        content: "▼";
        margin-left: 0.35rem;
        font-size: 0.65rem;
        opacity: 0.6;
    }
    #ffDvThermoBonCard .ff-dv-thermo-head.collapsed::after {
        content: "▶";
    }
    #ffDvThermoBonCard .card-header .ff-admin-info-btn {
        flex-shrink: 0;
    }
</style>
