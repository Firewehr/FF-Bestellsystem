var ffIsSuperAdmin = !!window.ffIsSuperAdmin;

(function() {
    var scopeEl = document.getElementById('abScope');
    var tableWrap = document.getElementById('abTableWrap');
    var inclDir = document.getElementById('abIncludeDirekt');
    function syncAbScope() {
        if (!scopeEl || !tableWrap) return;
        var v = scopeEl.value;
        tableWrap.style.display = (v === 'table') ? '' : 'none';
        if (inclDir) inclDir.disabled = (v !== 'all_tables');
    }
    if (scopeEl) scopeEl.addEventListener('change', syncAbScope);
    syncAbScope();
})();

function abrechnungVorschau() {
    var scope = (ffById('abScope') && ffById('abScope').value) ? ffById('abScope').value : 'all_tables';
    var tableId = (ffById('abTableId') && ffById('abTableId').value) ? ffById('abTableId').value : '0';
    var incl = (ffById('abIncludeDirekt') && ffById('abIncludeDirekt').checked && scope === 'all_tables') ? '1' : '0';
    var url = 'admin_abrechnung_api.php?action=preview&scope=' + encodeURIComponent(scope) + '&table_id=' + encodeURIComponent(tableId) + '&include_direkt=' + incl;
    fetchGet(url)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var box = ffById('abVorschauBox');
            if (!box) return;
            if (!d.ok) { box.style.display = 'block'; box.innerHTML = '<span class="text-danger">' + (d.error || 'Fehler') + '</span>'; return; }
            var pct = (d.pct_vom_umsatz_heute === null || d.pct_vom_umsatz_heute === undefined) ? '—' : (d.pct_vom_umsatz_heute + ' %');
            var html = '<strong>' + (d.scope_label || '') + '</strong><br>';
            html += 'Offene Zeilen: <strong>' + d.open_count + '</strong>, Summe: <strong>' + Number(d.open_sum).toLocaleString('de-AT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €</strong><br>';
            html += 'Umsatz heute (bezahlt, ohne Schreibaus/Gratis): <strong>' + Number(d.umsatz_heute).toLocaleString('de-AT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €</strong><br>';
            html += 'Anteil Schreibaus an heutigem Kassenumsatz: <strong>' + pct + '</strong><br>';
            html += '<span class="text-muted">' + (d.hint || '') + '</span>';
            box.style.display = 'block';
            box.innerHTML = html;
        })
        .catch(function() { alert('Vorschau fehlgeschlagen.'); });
}

function abrechnungAusfuehren() {
    var scope = (ffById('abScope') && ffById('abScope').value) ? ffById('abScope').value : 'all_tables';
    var tableId = (ffById('abTableId') && ffById('abTableId').value) ? ffById('abTableId').value : '0';
    var incl = (ffById('abIncludeDirekt') && ffById('abIncludeDirekt').checked && scope === 'all_tables') ? '1' : '0';
    var phrase = (ffById('abConfirmPhrase') && ffById('abConfirmPhrase').value) ? ffById('abConfirmPhrase').value.trim() : '';
    if (!confirm('Wirklich alle passenden offenen Posten als Schreibaus abschließen? Es werden keine Zeilen gelöscht.')) return;
    fetchPost('admin_abrechnung_api.php', {
        action: 'execute',
        scope: scope,
        table_id: tableId,
        include_direkt: incl,
        confirm_phrase: phrase
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.ok) { alert(d.error || 'Fehler'); return; }
            alert('Erledigt: ' + d.affected + ' Zeile(n), Summe ' + Number(d.sum || 0).toLocaleString('de-AT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €');
            if (ffById('abConfirmPhrase')) ffById('abConfirmPhrase').value = '';
            abrechnungVorschau();
        })
        .catch(function() { alert('Fehler beim Ausführen.'); });
}

var posStatChartInstance = null;

function posStatAnzeigen() {
    var positionId = (ffById('posStatPosition') && ffById('posStatPosition').value) ? ffById('posStatPosition').value : '0';
    var von = (ffById('posStatVon') && ffById('posStatVon').value) ? String(ffById('posStatVon').value).trim() : '';
    var bis = (ffById('posStatBis') && ffById('posStatBis').value) ? String(ffById('posStatBis').value).trim() : '';
    if (von !== '' && bis === '') bis = von;
    if (bis !== '' && von === '') von = bis;
    var uhrVon = (ffById('posStatUhrVon') && ffById('posStatUhrVon').value) ? ffById('posStatUhrVon').value : '';
    var uhrBis = (ffById('posStatUhrBis') && ffById('posStatUhrBis').value) ? ffById('posStatUhrBis').value : '';
    var inklGast = (ffById('posStatInklGast') && ffById('posStatInklGast').checked) ? 1 : 0;
    var inklMitarbeiter = (ffById('posStatInklMitarbeiter') && ffById('posStatInklMitarbeiter').checked) ? 1 : 0;
    if (!inklGast && !inklMitarbeiter) { alert('Bitte mindestens „Gäste“ oder „Mitarbeiter“ einbeziehen.'); return; }
    var kfEl = document.getElementById('ffStatUserFilter');
    var kf = kfEl && kfEl.value ? String(kfEl.value).trim() : '';
    var apiBase = typeof ffResolveAdminApiUrl === 'function' ? ffResolveAdminApiUrl('statistik_position_zeitraum_api.php') : 'statistik_position_zeitraum_api.php';
    var url = apiBase + (apiBase.indexOf('?') === -1 ? '?' : '&') + 'position_id=' + encodeURIComponent(positionId || '0') + '&inkl_gast=' + inklGast + '&inkl_mitarbeiter=' + inklMitarbeiter;
    if (von) url += '&von=' + encodeURIComponent(von);
    if (bis) url += '&bis=' + encodeURIComponent(bis);
    if (uhrVon) url += '&uhrzeit_von=' + encodeURIComponent(uhrVon);
    if (uhrBis) url += '&uhrzeit_bis=' + encodeURIComponent(uhrBis);
    if (kf) url += '&kellner_filter=' + encodeURIComponent(kf);
    fetchGet(url)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.error) { alert(d.error); return; }
            var titel = d.position_name + ' – ';
            if (d.datum_offen) titel += 'gesamte Historie';
            else titel += d.von + (d.bis !== d.von ? ' bis ' + d.bis : '');
            if (d.uhrzeit_von || d.uhrzeit_bis) titel += ' | ' + (d.uhrzeit_von || '00:00') + '–' + (d.uhrzeit_bis || '24:00');
            if (d.chart_resolution_minutes === 15) {
                titel += ' · Grafik: 15-Minuten-Raster';
            }
            if (kf) titel += ' · Benutzer: ' + (d.kellner_filter_label || kf);
            var pst = ffById('posStatTitel');
            if (pst) pst.textContent = titel;

            function esc(s) { var div = document.createElement('div'); div.textContent = s == null ? '' : s; return div.innerHTML; }
            var thead = ffById('posStatThead');
            var tbody = ffById('posStatTbody');
            if (!thead || !tbody) return;
            tbody.innerHTML = '';

            if (d.alle_positionen) {
                thead.innerHTML = '<tr><th>Zeit / Tag</th><th>Gast</th><th>Mitarbeiter</th><th>Gesamt</th></tr>';
                var labels = d.chart.labels || [];
                var gast = d.chart.gast || [];
                var mit = d.chart.mitarbeiter || [];
                var gesamt = d.chart.gesamt || [];
                for (var i = 0; i < labels.length; i++) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td>' + esc(labels[i]) + '</td><td>' + (gast[i] || 0) + '</td><td>' + (mit[i] || 0) + '</td><td>' + (gesamt[i] || 0) + '</td>';
                    tbody.appendChild(tr);
                }
                if (labels.length === 0) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td colspan="4" class="text-muted">Keine Einträge in diesem Zeitraum.</td>';
                    tbody.appendChild(tr);
                }
            } else {
                thead.innerHTML = '<tr><th>Datum</th><th>Uhrzeit</th><th>Art</th><th>Menge</th><th>Tisch / Bereich</th><th>Notiz</th></tr>';
                var rows = [];
                (d.gast || []).forEach(function(r) {
                    var kl = (r.kellner_label || r.kellner || '').trim();
                    var flags = [];
                    if (r.is_gratis) flags.push('Gratis');
                    if (r.schreibaus) flags.push('Schreibaus');
                    rows.push({
                        datum: r.datum,
                        zeit: r.zeit,
                        art: 'Gast',
                        menge: r.menge || 1,
                        extra: 'Tisch ' + (r.tischnummer || '') + (kl ? ' · ' + kl : ''),
                        notiz: flags.join(', ')
                    });
                });
                (d.mitarbeiter || []).forEach(function(r) {
                    rows.push({ datum: r.datum, zeit: r.zeit, art: 'Mitarbeiter', menge: r.menge, extra: r.bereich || '', notiz: r.notiz || '' });
                });
                rows.sort(function(a, b) { return (a.datum + ' ' + a.zeit).localeCompare(b.datum + ' ' + b.zeit); });
                rows.forEach(function(r) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td>' + esc(r.datum) + '</td><td>' + esc(r.zeit) + '</td><td>' + esc(r.art) + '</td><td>' + esc(String(r.menge)) + '</td><td>' + esc(r.extra) + '</td><td>' + esc(r.notiz) + '</td>';
                    tbody.appendChild(tr);
                });
                if (rows.length === 0) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td colspan="6" class="text-muted">Keine Einträge in diesem Zeitraum.</td>';
                    tbody.appendChild(tr);
                }
            }
            var pse = ffById('posStatErgebnis');
            if (pse) pse.style.display = 'block';

            if (posStatChartInstance) { posStatChartInstance.destroy(); posStatChartInstance = null; }
            var ctx = document.getElementById('posStatChart');
            if (!ctx || typeof Chart === 'undefined') return;
            var isLine = d.is_one_day === true;
            var labLen = (d.chart.labels || []).length;
            var xTicksOpts = {};
            if (isLine && d.chart_resolution_minutes === 15 && labLen > 20) {
                xTicksOpts = { maxRotation: 65, minRotation: 45, autoSkip: true, maxTicksLimit: 40 };
            }
            var datasets = [];
            if (inklGast && (d.chart.gast && d.chart.gast.length)) {
                datasets.push({
                    label: 'Gast',
                    data: d.chart.gast,
                    borderColor: 'rgba(54, 162, 235, 1)',
                    backgroundColor: 'rgba(54, 162, 235, 0.1)',
                    borderWidth: 2,
                    fill: isLine,
                    tension: isLine ? 0.2 : 0
                });
            }
            if (inklMitarbeiter && (d.chart.mitarbeiter && d.chart.mitarbeiter.length)) {
                datasets.push({
                    label: 'Mitarbeiter',
                    data: d.chart.mitarbeiter,
                    borderColor: 'rgba(255, 159, 64, 1)',
                    backgroundColor: 'rgba(255, 159, 64, 0.1)',
                    borderWidth: 2,
                    fill: isLine,
                    tension: isLine ? 0.2 : 0
                });
            }
            if (inklGast && inklMitarbeiter && d.chart.gesamt && d.chart.gesamt.length) {
                datasets.push({
                    label: 'Gesamt',
                    data: d.chart.gesamt,
                    borderColor: 'rgba(75, 192, 192, 1)',
                    backgroundColor: 'rgba(75, 192, 192, 0.1)',
                    borderWidth: 2,
                    fill: isLine,
                    tension: isLine ? 0.2 : 0
                });
            }
            if (datasets.length === 0) {
                datasets.push({ label: 'Gesamt', data: d.chart.gesamt || [], borderColor: 'rgba(75, 192, 192, 1)', backgroundColor: 'rgba(75, 192, 192, 0.2)', borderWidth: 2, fill: isLine, tension: isLine ? 0.2 : 0 });
            }
            posStatChartInstance = new Chart(ctx, {
                type: isLine ? 'line' : 'bar',
                data: { labels: d.chart.labels || [], datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        x: { ticks: xTicksOpts },
                        y: { beginAtZero: true, ticks: { stepSize: 1 } }
                    },
                    plugins: { legend: { position: 'top' } }
                }
            });
        })
        .catch(function() { alert('Fehler beim Laden.'); });
}

function posStatExportCsv() {
    var positionId = (ffById('posStatPosition') && ffById('posStatPosition').value) ? ffById('posStatPosition').value : '0';
    var von = (ffById('posStatVon') && ffById('posStatVon').value) ? String(ffById('posStatVon').value).trim() : '';
    var bis = (ffById('posStatBis') && ffById('posStatBis').value) ? String(ffById('posStatBis').value).trim() : '';
    if (von !== '' && bis === '') bis = von;
    if (bis !== '' && von === '') von = bis;
    var uhrVon = (ffById('posStatUhrVon') && ffById('posStatUhrVon').value) ? ffById('posStatUhrVon').value : '';
    var uhrBis = (ffById('posStatUhrBis') && ffById('posStatUhrBis').value) ? ffById('posStatUhrBis').value : '';
    var inklGast = (ffById('posStatInklGast') && ffById('posStatInklGast').checked) ? 1 : 0;
    var inklMitarbeiter = (ffById('posStatInklMitarbeiter') && ffById('posStatInklMitarbeiter').checked) ? 1 : 0;
    if (!inklGast && !inklMitarbeiter) { alert('Bitte mindestens „Gäste“ oder „Mitarbeiter“ einbeziehen.'); return; }
    var kfEl = document.getElementById('ffStatUserFilter');
    var kf = kfEl && kfEl.value ? String(kfEl.value).trim() : '';
    var base = typeof ffResolveAdminApiUrl === 'function' ? ffResolveAdminApiUrl('statistik_position_export_csv.php') : 'statistik_position_export_csv.php';
    var qs = ['position_id=' + encodeURIComponent(positionId || '0'), 'inkl_gast=' + inklGast, 'inkl_mitarbeiter=' + inklMitarbeiter];
    if (von) qs.push('von=' + encodeURIComponent(von));
    if (bis) qs.push('bis=' + encodeURIComponent(bis));
    if (uhrVon) qs.push('uhrzeit_von=' + encodeURIComponent(uhrVon));
    if (uhrBis) qs.push('uhrzeit_bis=' + encodeURIComponent(uhrBis));
    if (kf) qs.push('kellner_filter=' + encodeURIComponent(kf));
    var sep = base.indexOf('?') === -1 ? '?' : '&';
    window.location.href = base + sep + qs.join('&');
}

function ffReloadStatistikBody() {
    var sel = document.getElementById('ffStatUserFilter');
    var box = document.getElementById('ffStatistikDynamicBody');
    if (!sel || !box) return;
    var v = sel.value ? String(sel.value).trim() : '';
    var vonEl = document.getElementById('ffStatVon');
    var bisEl = document.getElementById('ffStatBis');
    var state = window.__ffStatDateState || { von: '', bis: '', vonZeit: '', bisZeit: '' };
    var von = vonEl && vonEl.value ? String(vonEl.value).trim() : (state.von || '');
    var bis = bisEl && bisEl.value ? String(bisEl.value).trim() : (state.bis || '');
    var vonZeitEl = document.getElementById('ffStatVonZeit');
    var bisZeitEl = document.getElementById('ffStatBisZeit');
    var vonZeit = vonZeitEl && vonZeitEl.value ? String(vonZeitEl.value).trim() : (state.vonZeit || '');
    var bisZeit = bisZeitEl && bisZeitEl.value ? String(bisZeitEl.value).trim() : (state.bisZeit || '');
    window.__ffStatDateState = { von: von, bis: bis, vonZeit: vonZeit, bisZeit: bisZeit };
    var base = typeof ffResolveAdminApiUrl === 'function' ? ffResolveAdminApiUrl('admin_statistik_content.php') : 'admin_statistik_content.php';
    var sep = base.indexOf('?') === -1 ? '?' : '&';
    var url = base + sep + 'inner=1';
    if (v) url += '&kellner=' + encodeURIComponent(v);
    if (von) url += '&von=' + encodeURIComponent(von);
    if (bis) url += '&bis=' + encodeURIComponent(bis);
    if (vonZeit) url += '&von_zeit=' + encodeURIComponent(vonZeit);
    if (bisZeit) url += '&bis_zeit=' + encodeURIComponent(bisZeit);
    fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(function(html) {
            box.innerHTML = html;
            // Nach AJAX-Render Datum wieder in die Felder schreiben (robust gegen
            // Browser/Render-Race, bei dem Input-Werte kurzzeitig leer werden).
            var v2 = document.getElementById('ffStatVon');
            var b2 = document.getElementById('ffStatBis');
            var st = window.__ffStatDateState || { von: '', bis: '', vonZeit: '', bisZeit: '' };
            if (v2) v2.value = st.von || '';
            if (b2) b2.value = st.bis || '';
            var vz2 = document.getElementById('ffStatVonZeit');
            var bz2 = document.getElementById('ffStatBisZeit');
            if (vz2) vz2.value = st.vonZeit || '';
            if (bz2) bz2.value = st.bisZeit || '';
        })
        .catch(function() {
            box.innerHTML = '<div class="alert alert-danger small mb-0">Statistik konnte nicht aktualisiert werden.</div>';
        });
}

document.addEventListener('change', function(ev) {
    if (ev.target && ev.target.id === 'ffStatUserFilter') {
        ffReloadStatistikBody();
    }
});

function ffStatClearUserFilter() {
    var sel = document.getElementById('ffStatUserFilter');
    if (!sel) return;
    sel.value = '';
    ffReloadStatistikBody();
}

window.ffStatClearUserFilter = ffStatClearUserFilter;

function ffStatExportKellnerCsv() {
    var sel = document.getElementById('ffStatUserFilter');
    var vonEl = document.getElementById('ffStatVon');
    var bisEl = document.getElementById('ffStatBis');
    var v = sel && sel.value ? String(sel.value).trim() : '';
    var state = window.__ffStatDateState || { von: '', bis: '', vonZeit: '', bisZeit: '' };
    var von = vonEl && vonEl.value ? String(vonEl.value).trim() : (state.von || '');
    var bis = bisEl && bisEl.value ? String(bisEl.value).trim() : (state.bis || '');
    var vonZeitEl = document.getElementById('ffStatVonZeit');
    var bisZeitEl = document.getElementById('ffStatBisZeit');
    var vonZeit = vonZeitEl && vonZeitEl.value ? String(vonZeitEl.value).trim() : (state.vonZeit || '');
    var bisZeit = bisZeitEl && bisZeitEl.value ? String(bisZeitEl.value).trim() : (state.bisZeit || '');
    var base = typeof ffResolveAdminApiUrl === 'function' ? ffResolveAdminApiUrl('admin_statistik_kellner_export.php') : 'admin_statistik_kellner_export.php';
    var qs = [];
    if (v) qs.push('kellner=' + encodeURIComponent(v));
    if (von) qs.push('von=' + encodeURIComponent(von));
    if (bis) qs.push('bis=' + encodeURIComponent(bis));
    if (vonZeit) qs.push('von_zeit=' + encodeURIComponent(vonZeit));
    if (bisZeit) qs.push('bis_zeit=' + encodeURIComponent(bisZeit));
    var url = base + (qs.length ? ((base.indexOf('?') === -1 ? '?' : '&') + qs.join('&')) : '');
    window.location.href = url;
}

function ffStatExportDetailCsv() {
    var sel = document.getElementById('ffStatUserFilter');
    var vonEl = document.getElementById('ffStatVon');
    var bisEl = document.getElementById('ffStatBis');
    var v = sel && sel.value ? String(sel.value).trim() : '';
    var state = window.__ffStatDateState || { von: '', bis: '', vonZeit: '', bisZeit: '' };
    var von = vonEl && vonEl.value ? String(vonEl.value).trim() : (state.von || '');
    var bis = bisEl && bisEl.value ? String(bisEl.value).trim() : (state.bis || '');
    var vonZeitEl = document.getElementById('ffStatVonZeit');
    var bisZeitEl = document.getElementById('ffStatBisZeit');
    var vonZeit = vonZeitEl && vonZeitEl.value ? String(vonZeitEl.value).trim() : (state.vonZeit || '');
    var bisZeit = bisZeitEl && bisZeitEl.value ? String(bisZeitEl.value).trim() : (state.bisZeit || '');
    var base = typeof ffResolveAdminApiUrl === 'function' ? ffResolveAdminApiUrl('admin_statistik_detail_export.php') : 'admin_statistik_detail_export.php';
    var qs = [];
    if (v) qs.push('kellner=' + encodeURIComponent(v));
    if (von) qs.push('von=' + encodeURIComponent(von));
    if (bis) qs.push('bis=' + encodeURIComponent(bis));
    if (vonZeit) qs.push('von_zeit=' + encodeURIComponent(vonZeit));
    if (bisZeit) qs.push('bis_zeit=' + encodeURIComponent(bisZeit));
    var url = base + (qs.length ? ((base.indexOf('?') === -1 ? '?' : '&') + qs.join('&')) : '');
    window.location.href = url;
}

function ffStatExportKellnerAufgenommenCsv() {
    var sel = document.getElementById('ffStatUserFilter');
    var vonEl = document.getElementById('ffStatVon');
    var bisEl = document.getElementById('ffStatBis');
    var v = sel && sel.value ? String(sel.value).trim() : '';
    var state = window.__ffStatDateState || { von: '', bis: '', vonZeit: '', bisZeit: '' };
    var von = vonEl && vonEl.value ? String(vonEl.value).trim() : (state.von || '');
    var bis = bisEl && bisEl.value ? String(bisEl.value).trim() : (state.bis || '');
    var vonZeitEl = document.getElementById('ffStatVonZeit');
    var bisZeitEl = document.getElementById('ffStatBisZeit');
    var vonZeit = vonZeitEl && vonZeitEl.value ? String(vonZeitEl.value).trim() : (state.vonZeit || '');
    var bisZeit = bisZeitEl && bisZeitEl.value ? String(bisZeitEl.value).trim() : (state.bisZeit || '');
    var base = typeof ffResolveAdminApiUrl === 'function' ? ffResolveAdminApiUrl('admin_statistik_kellner_aufnahme_export.php') : 'admin_statistik_kellner_aufnahme_export.php';
    var qs = [];
    if (v) qs.push('kellner=' + encodeURIComponent(v));
    if (von) qs.push('von=' + encodeURIComponent(von));
    if (bis) qs.push('bis=' + encodeURIComponent(bis));
    if (vonZeit) qs.push('von_zeit=' + encodeURIComponent(vonZeit));
    if (bisZeit) qs.push('bis_zeit=' + encodeURIComponent(bisZeit));
    var url = base + (qs.length ? ((base.indexOf('?') === -1 ? '?' : '&') + qs.join('&')) : '');
    window.location.href = url;
}

function ffAdminStatistikFuerKellnerOeffnen() {
    var pre = document.getElementById('abStatPrefillUser');
    var v = pre && pre.value ? String(pre.value).trim() : '';
    ffAdminOpenSection('Statistik', v ? 'ffStatKellnerAbgerechnet' : undefined);
    function apply() {
        var statSel = document.getElementById('ffStatUserFilter');
        if (!statSel) return;
        statSel.value = v;
        ffReloadStatistikBody();
    }
    setTimeout(apply, 250);
    setTimeout(apply, 700);
    setTimeout(apply, 1400);
}

function ffResetCall(mode, onDone) {
    var url = 'reset.php?cmd=reset' + (mode ? '&mode=' + encodeURIComponent(mode) : '');
    fetchGet(url)
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
        .then(function(x) {
            if (!x.ok || !x.j || !x.j.ok) {
                var msg = (x.j && x.j.error) ? x.j.error : ('HTTP ' + (x.ok ? '?' : 'Fehler'));
                if (x.j && x.j.cleared && x.j.cleared.length) {
                    msg += '\n\nBereits geleert: ' + x.j.cleared.join(', ');
                }
                alert(msg);
                return;
            }
            var detail = x.j.message || 'Erledigt.';
            if (x.j.cleared && x.j.cleared.length) {
                detail += '\n\nGeleert: ' + x.j.cleared.join(', ');
            }
            alert(detail);
            if (typeof onDone === 'function') onDone();
            else location.reload();
        })
        .catch(function() { alert('Netzwerk- oder Serverfehler.'); });
}

window.festStartVorbereiten = function() {
    if (!ffIsSuperAdmin) { alert('Nur Super-Admin.'); return; }
    alert(
        'FEST-START\n\n'
        + 'Gelöscht: Bestellungen, Druck-Queues, Rechnungen, Sammelrechnungen, alle Buchungen, Kassen-Sessions/-Bewegungen, Kellner-Abrechnungen, Verpflegung, Menü-Sperren; Zähler zurückgesetzt.\n\n'
        + 'Bleibt: Tische, Finanzbereiche (Kassen-Stammdaten), Speisekarte, Nutzer, Feste, Einstellungen.\n\n'
        + 'Vorher Vollbackup empfohlen. Kein Widerruf.'
    );
    if (!confirm('Fest-Start wirklich ausführen?')) return;
    var typed = prompt('Zum Bestätigen FEST-START eintippen (Großbuchstaben):');
    if (typed !== 'FEST-START') {
        if (typed !== null) alert('Abgebrochen — Eingabe war nicht FEST-START.');
        return;
    }
    ffResetCall('fest_start', function() { location.reload(); });
};

window.resetBestellungen = function() {
    if (!ffIsSuperAdmin) { alert('Nur Super-Admin darf die Datenbank leeren.'); return; }
    alert('NOTFALL: Nur Bestellungen und print-Queue. Rechnungen, Finanzen und printer_jobs bleiben. Für Fest-Start den gelben Button nutzen.');
    if (!confirm('Nur bestellungen + print wirklich LÖSCHEN?')) return;
    if (!confirm('Letzte Rückfrage: TRUNCATE — nur diese Tabellen?')) return;
    ffResetCall('notfall', function() { location.reload(); });
};

var ffUserPwResetModal = null;
var ffUserPwResetUserId = 0;

function ffOpenUserPwResetModal(btn) {
    ffUserPwResetUserId = parseInt(btn.getAttribute('data-userid'), 10) || 0;
    var el = document.getElementById('ffUserPwResetModal');
    if (!el || !ffUserPwResetUserId) return;
    var sub = document.getElementById('ffUserPwResetModalUser');
    if (sub) sub.textContent = 'Benutzer: ' + (btn.getAttribute('data-username') || '');
    var pwEl = document.getElementById('ffUserPwResetValue');
    var forceEl = document.getElementById('ffUserPwResetForce');
    var resEl = document.getElementById('ffUserPwResetResult');
    if (pwEl) pwEl.value = '';
    if (forceEl) forceEl.checked = true;
    if (resEl) {
        resEl.classList.add('d-none');
        resEl.textContent = '';
    }
    if (!ffUserPwResetModal && typeof bootstrap !== 'undefined') {
        ffUserPwResetModal = new bootstrap.Modal(el);
    }
    if (ffUserPwResetModal) ffUserPwResetModal.show();
}

function ffGeneratePwResetValue() {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    var out = '';
    for (var i = 0; i < 10; i++) {
        out += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    var pwEl = document.getElementById('ffUserPwResetValue');
    if (pwEl) pwEl.value = out;
}

function ffSaveUserPwReset() {
    var uid = ffUserPwResetUserId;
    if (!uid) return;
    var pwEl = document.getElementById('ffUserPwResetValue');
    var forceEl = document.getElementById('ffUserPwResetForce');
    var pw = pwEl ? String(pwEl.value || '').trim() : '';
    var body = new URLSearchParams();
    body.append('userid', String(uid));
    body.append('force_password_change', (forceEl && forceEl.checked) ? '1' : '0');
    if (pw !== '') {
        body.append('pw', pw);
    } else {
        body.append('generate', '1');
    }
    fetch(ffResolveAdminApiUrl('update_pw.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
    })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
        .then(function(x) {
            if (!x.ok || !x.j || !x.j.ok) {
                var err = (x.j && x.j.error) ? x.j.error : 'unbekannt';
                if (err === 'own_account_locked') err = 'Eigenes Passwort bitte im Menü ändern.';
                alert('Passwort konnte nicht gesetzt werden: ' + err);
                return;
            }
            if (pwEl && x.j.password) pwEl.value = x.j.password;
            var resEl = document.getElementById('ffUserPwResetResult');
            if (resEl) {
                resEl.classList.remove('d-none');
                resEl.innerHTML = '<strong>Kennwort für „' + (x.j.username || '') + '“:</strong> '
                    + '<code class="user-select-all">' + (x.j.password || '') + '</code>'
                    + '<br><span class="text-muted">Dem Benutzer mitteilen (z.&nbsp;B. mündlich/SMS). '
                    + (x.j.force_password_change ? 'Beim Login muss es geändert werden.' : '') + '</span>';
            }
        })
        .catch(function() { alert('Netzwerkfehler'); });
}

window.updatePW = function(userid) {
    var btn = document.querySelector('.btn-user-pw-reset[data-userid="' + userid + '"]');
    if (btn) {
        ffOpenUserPwResetModal(btn);
        return;
    }
    ffUserPwResetUserId = parseInt(userid, 10) || 0;
    ffOpenUserPwResetModal({ getAttribute: function(k) {
        if (k === 'data-userid') return String(ffUserPwResetUserId);
        if (k === 'data-username') return '';
        return '';
    } });
};

var ffAdminOwnPwModal = null;

function ffOpenAdminOwnPwModal() {
    var el = document.getElementById('ffAdminOwnPwModal');
    if (!el) return;
    ['ffAdminOwnPwCurrent', 'ffAdminOwnPwNew1', 'ffAdminOwnPwNew2'].forEach(function(id) {
        var inp = document.getElementById(id);
        if (inp) inp.value = '';
    });
    var errEl = document.getElementById('ffAdminOwnPwErr');
    if (errEl) { errEl.classList.add('d-none'); errEl.textContent = ''; }
    if (!ffAdminOwnPwModal && typeof bootstrap !== 'undefined') {
        ffAdminOwnPwModal = new bootstrap.Modal(el);
    }
    if (ffAdminOwnPwModal) ffAdminOwnPwModal.show();
}

function ffSaveAdminOwnPw() {
    var cur = document.getElementById('ffAdminOwnPwCurrent');
    var n1 = document.getElementById('ffAdminOwnPwNew1');
    var n2 = document.getElementById('ffAdminOwnPwNew2');
    var errEl = document.getElementById('ffAdminOwnPwErr');
    var current = cur ? cur.value : '';
    var pw1 = n1 ? n1.value : '';
    var pw2 = n2 ? n2.value : '';
    if (!current || !pw1 || !pw2) {
        if (errEl) { errEl.textContent = 'Bitte alle Felder ausfüllen.'; errEl.classList.remove('d-none'); }
        return;
    }
    if (pw1 !== pw2) {
        if (errEl) { errEl.textContent = 'Die neuen Kennwörter stimmen nicht überein.'; errEl.classList.remove('d-none'); }
        return;
    }
    if (pw1.length < 6) {
        if (errEl) { errEl.textContent = 'Mindestens 6 Zeichen.'; errEl.classList.remove('d-none'); }
        return;
    }
    fetch(ffResolveAdminApiUrl('pw_verify.php'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ current_password: current }).toString()
    })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
        .then(function(x) {
            if (!x.ok || !x.j || !x.j.ok) {
                if (errEl) { errEl.textContent = 'Aktuelles Kennwort ist falsch.'; errEl.classList.remove('d-none'); }
                return;
            }
            return fetch(ffResolveAdminApiUrl('pw_change_own.php'), {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ new_password: pw1, new_password_again: pw2 }).toString()
            });
        })
        .then(function(r) {
            if (!r) return;
            return r.json().then(function(j) { return { ok: r.ok, j: j }; });
        })
        .then(function(x) {
            if (!x) return;
            if (!x.ok || !x.j || !x.j.ok) {
                var e = (x.j && x.j.error) ? x.j.error : 'Speichern fehlgeschlagen';
                if (e === 'pw_gate_required') e = 'Bitte erneut öffnen und direkt speichern.';
                if (errEl) { errEl.textContent = e; errEl.classList.remove('d-none'); }
                return;
            }
            if (ffAdminOwnPwModal) ffAdminOwnPwModal.hide();
            alert('Passwort wurde geändert.');
        })
        .catch(function() {
            if (errEl) { errEl.textContent = 'Netzwerkfehler.'; errEl.classList.remove('d-none'); }
        });
}

(function() {
    var genBtn = document.getElementById('ffUserPwResetGenBtn');
    if (genBtn) genBtn.addEventListener('click', ffGeneratePwResetValue);
    var saveBtn = document.getElementById('ffUserPwResetSaveBtn');
    if (saveBtn) saveBtn.addEventListener('click', ffSaveUserPwReset);
    var ownSave = document.getElementById('ffAdminOwnPwSaveBtn');
    if (ownSave) ownSave.addEventListener('click', ffSaveAdminOwnPw);
})();

function saveUserAdminErr(e) {
    var m = {
        forbidden: 'Keine Berechtigung.',
        bad_params: 'Ungültige Angaben.',
        user_not_found: 'Benutzer nicht gefunden.',
        cannot_change_self: 'Das eigene Konto kann nicht geändert werden.',
        target_is_super: 'Der Super-Admin kann von normalen Administratoren nicht geändert werden.',
        super_admin_via_setup_only: 'Super-Admin gibt es nur beim ersten Einrichten – hier nicht setzbar.'
    };
    return m[e] || e || 'Fehler';
}

function deleteUserErr(e) {
    var m = {
        forbidden: 'Keine Berechtigung.',
        bad_params: 'Ungültige Angaben.',
        user_not_found: 'Benutzer nicht gefunden.',
        cannot_delete_self: 'Das eigene Konto kann nicht gelöscht werden.',
        cannot_delete_super: 'Super-Admin-Konten können hier nicht gelöscht werden.',
        admin_may_delete_users_only: 'Als Administrator dürfen Sie nur normale Benutzer löschen.',
        delete_failed: 'Löschen fehlgeschlagen (Datenbank).'
    };
    return m[e] || e || 'Fehler';
}

window.deleteUser = function(userid, username) {
    var label = username ? String(username) : 'diesen Benutzer';
    if (!confirm('Benutzer „' + label + '“ wirklich unwiderruflich löschen?')) return;
    fetchPost('delete_user.php', { userid: String(userid) })
        .then(function(r) {
            return r.text().then(function(t) {
                var j = null;
                var raw = String(t || '').replace(/^\uFEFF/, '').trim();
                try { j = JSON.parse(raw); } catch (e) { j = null; }
                return { ok: r.ok, status: r.status, t: t, j: j };
            });
        })
        .then(function(x) {
            if (x.j && x.j.ok) {
                alert('Benutzer gelöscht.');
                AdminAnsicht();
            } else {
                var msg = deleteUserErr(x.j && x.j.error);
                if (x.j && x.j.fk_blocked) {
                    msg += ' Vermutlich sind noch Bestellungen oder andere Einträge mit diesem Benutzer verknüpft.';
                }
                if (x.j && x.j.detail) {
                    msg += ' ' + String(x.j.detail);
                } else if (!x.j && String(x.t || '').trim()) {
                    msg = 'Server-Antwort: ' + String(x.t).trim().slice(0, 200);
                }
                alert('Fehler: ' + msg);
            }
        })
        .catch(function() { alert('Fehler (Netzwerk).'); });
};

window.saveUserAdmin = function(userid, sel) {
    var v = sel.value;
    var prev = sel.getAttribute('data-prev') || '0';
    if (v === prev) return;
    if (!confirm('Rechte wirklich ändern?')) { sel.value = prev; return; }
    fetchPost('save_user_admin.php', { userid: String(userid), admin: v })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
        .then(function(x) {
            if (x.j && x.j.ok) {
                sel.setAttribute('data-prev', v);
                alert('Gespeichert');
                AdminAnsicht();
            } else {
                alert('Fehler: ' + saveUserAdminErr(x.j && x.j.error));
                sel.value = prev;
            }
        })
        .catch(function() { alert('Fehler'); sel.value = prev; });
};

function saveUserStartPageErr(e) {
    var m = {
        forbidden: 'Keine Berechtigung.',
        bad_params: 'Ungültige Angaben.',
        user_not_found: 'Benutzer nicht gefunden.',
        invalid_print_target: 'Druckziel ungültig oder nicht in der Speisekarte.',
        admin_always_menu: 'Für Administratoren ist immer das Hauptmenü aktiv.',
        target_is_super: 'Super-Admin nicht änderbar.',
        update_failed: 'Speichern fehlgeschlagen.'
    };
    return m[e] || e || 'Fehler';
}

function ffSyncUserStartPtVisibility(pageSel) {
    if (!pageSel) return;
    var uid = pageSel.getAttribute('data-userid');
    if (!uid) return;
    var wrap = document.querySelector('.user-start-pt-wrap[data-userid="' + uid + '"]');
    if (!wrap) return;
    wrap.style.display = pageSel.value === 'print_target' ? 'block' : 'none';
}

function ffSyncAllUserStartPtWraps() {
    document.querySelectorAll('#myUsers .user-start-page-select').forEach(function(sel) {
        ffSyncUserStartPtVisibility(sel);
    });
}

function ffSyncNewUserPtWrap() {
    var wrap = ffById('new_user_pt_wrap');
    var sel = ffById('new_user_start_page');
    var adm = ffById('adminyesno');
    if (!wrap || !sel) return;
    if (adm && adm.value === '1') {
        wrap.style.display = 'none';
        return;
    }
    wrap.style.display = sel.value === 'print_target' ? '' : 'none';
}

window.saveUserDvBonTargetFromSelect = function(userid) {
    var uid = String(userid);
    var sel = document.querySelector('#myUsers .user-dv-bon-target-select[data-userid="' + uid + '"]');
    if (!sel) return;
    var val = sel.value;
    var prev = sel.getAttribute('data-prev') || '';
    if (String(val) === String(prev)) return;
    fetchPost('save_user_dv_abholbon_target.php', { userid: uid, dv_abholbon_print_target: val })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
        .then(function(x) {
            if (x.j && x.j.ok) {
                sel.setAttribute('data-prev', val === '' ? '' : String(val));
            } else {
                alert('Fehler: ' + (x.j && x.j.error ? x.j.error : 'Speichern'));
                sel.value = prev === '' ? '' : prev;
            }
        })
        .catch(function() {
            alert('Fehler (Netzwerk)');
            sel.value = prev === '' ? '' : prev;
        });
};

window.saveUserDisplayNameFromInput = function(userid) {
    var uid = String(userid);
    var inp = document.querySelector('#myUsers .user-display-name-input[data-userid="' + uid + '"]');
    if (!inp) return;
    var val = (inp.value || '').trim();
    var prev = inp.getAttribute('data-prev') || '';
    if (val === prev) return;
    inp.disabled = true;
    fetchPost('save_user_display_name.php', { userid: uid, display_name: val })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
        .then(function(x) {
            if (x.j && x.j.ok) {
                inp.setAttribute('data-prev', val);
                inp.classList.remove('is-invalid');
                inp.classList.add('is-valid');
                setTimeout(function() { inp.classList.remove('is-valid'); }, 1200);
            } else {
                alert('Fehler beim Speichern: ' + (x.j && x.j.error ? x.j.error : 'unbekannt'));
                inp.value = prev;
                inp.classList.add('is-invalid');
            }
        })
        .catch(function() {
            alert('Fehler (Netzwerk)');
            inp.value = prev;
            inp.classList.add('is-invalid');
        })
        .finally(function() {
            inp.disabled = false;
        });
};

window.saveUserStartPageFromSelects = function(userid) {
    var uid = String(userid);
    var pageSel = document.querySelector('#myUsers .user-start-page-select[data-userid="' + uid + '"]');
    var ptSel = document.querySelector('#myUsers .user-start-pt-select[data-userid="' + uid + '"]');
    if (!pageSel) return;
    var sp = pageSel.value;
    var pt = (sp === 'print_target' && ptSel) ? ptSel.value : '';
    var prevP = pageSel.getAttribute('data-prev-page') || 'menu';
    var prevPt = ptSel ? (ptSel.getAttribute('data-prev-pt') || '0') : '0';
    if (sp === prevP && (sp !== 'print_target' || String(pt) === String(prevPt))) return;
    fetchPost('save_user_start_page.php', { userid: uid, start_page: sp, start_print_target: pt })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
        .then(function(x) {
            if (x.j && x.j.ok) {
                pageSel.setAttribute('data-prev-page', sp);
                if (ptSel) ptSel.setAttribute('data-prev-pt', String(pt));
                fetch(ffResolveAdminApiUrl('get_user_menu_permissions.php?userid=' + encodeURIComponent(uid)), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (!d || !d.ok || !d.permissions) return;
                        var sumEl = document.querySelector('#myUsers .user-perm-summary[data-userid="' + uid + '"]');
                        if (sumEl && typeof d.summary === 'string') {
                            sumEl.textContent = d.summary;
                            sumEl.setAttribute('title', d.summary);
                        }
                    })
                    .catch(function() {});
            } else {
                alert('Fehler: ' + saveUserStartPageErr(x.j && x.j.error));
                pageSel.value = prevP;
                if (ptSel) ptSel.value = prevPt;
                ffSyncUserStartPtVisibility(pageSel);
            }
        })
        .catch(function() {
            alert('Fehler (Netzwerk)');
            pageSel.value = prevP;
            if (ptSel) ptSel.value = prevPt;
            ffSyncUserStartPtVisibility(pageSel);
        });
};

var FF_ADMIN_COLLAPSE_STORAGE_KEY = 'ff_admin_open_collapses';
var FF_ADMIN_SCROLL_STORAGE_KEY = 'ff_admin_scroll_y';

function ffAdminEscapeHtml(s) {
    if (s == null) {
        return '';
    }
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** Vor Admin-Reload: offene Accordion-Bereiche und Scrollposition merken. */
function ffAdminSaveOpenCollapses() {
    var ids = [];
    document.querySelectorAll('.admin-content .collapse.show').forEach(function(el) {
        if (el.id && ids.indexOf(el.id) === -1) {
            ids.push(el.id);
        }
    });
    try {
        sessionStorage.setItem(FF_ADMIN_COLLAPSE_STORAGE_KEY, JSON.stringify(ids));
        sessionStorage.setItem(FF_ADMIN_SCROLL_STORAGE_KEY, String(window.scrollY || 0));
    } catch (e) {}
}

function ffAdminRestoreOpenCollapses() {
    var raw;
    try {
        raw = sessionStorage.getItem(FF_ADMIN_COLLAPSE_STORAGE_KEY);
    } catch (e) {
        return;
    }
    if (!raw) {
        return;
    }
    var ids;
    try {
        ids = JSON.parse(raw);
    } catch (e) {
        return;
    }
    if (!Array.isArray(ids)) {
        return;
    }
    try {
        sessionStorage.removeItem(FF_ADMIN_COLLAPSE_STORAGE_KEY);
    } catch (e2) {}
    ids.forEach(function(id) {
        if (typeof ffAdminOpenSection === 'function') {
            ffAdminOpenSection(id);
        }
    });
    [50, 250, 600].forEach(function(ms) {
        setTimeout(function() {
            ids.forEach(function(id) {
                var el = document.getElementById(id);
                if (el && typeof ffOnCollapseShow === 'function') {
                    ffOnCollapseShow(el);
                }
            });
        }, ms);
    });
    var scrollRaw;
    try {
        scrollRaw = sessionStorage.getItem(FF_ADMIN_SCROLL_STORAGE_KEY);
        sessionStorage.removeItem(FF_ADMIN_SCROLL_STORAGE_KEY);
    } catch (e3) {}
    var scrollY = scrollRaw != null ? parseInt(scrollRaw, 10) : NaN;
    if (!isNaN(scrollY) && scrollY >= 0) {
        requestAnimationFrame(function() {
            window.scrollTo(0, scrollY);
        });
    }
}

function ffAdminReloadPreserveScroll() {
    ffAdminSaveOpenCollapses();
    location.reload();
}

/** Empfänger-Spalte in der Rechnungsliste nach Bearbeitung aktualisieren (ohne Seiten-Reload). */
function ffAdminUpdateRechnungRowEmpfaenger(rechnungId, data) {
    var row = document.getElementById('rechnung_row_' + rechnungId);
    if (!row) {
        return false;
    }
    var cells = row.getElementsByTagName('td');
    if (cells.length < 5) {
        return false;
    }
    var empCell = cells[4];
    var isFirma = parseInt(data.is_firma, 10) === 1;
    var name = String(data.empfaenger_name || '').trim();
    if (isFirma && name) {
        var html = '<small>' + ffAdminEscapeHtml(name);
        var ort = String(data.empfaenger_ort || '').trim();
        if (ort) {
            html += ', ' + ffAdminEscapeHtml(ort);
        }
        html += '</small>';
        empCell.innerHTML = html;
    } else {
        empCell.innerHTML = '<span class="text-muted">-</span>';
    }
    return true;
}

function ffOnCollapseShow(el) {
    try {
        if (el.id === 'AdminDashboard' && typeof adminDashboardRefresh === 'function') adminDashboardRefresh();
    } catch (e2) {}
    try {
        if (el.id === 'Finanzen' && typeof gewinnAktualisieren === 'function') gewinnAktualisieren();
        if (el.id === 'Finanzen' && typeof ffFinanceInit === 'function') ffFinanceInit();
    } catch (e3) {}
    try {
        if (el.id === 'Benutzer' && typeof ffSyncAllUserStartPtWraps === 'function') ffSyncAllUserStartPtWraps();
    } catch (e5) {}
    try {
        if (el.id === 'SystemEinstellungen') {
            if (typeof ffBindSystemSettingsAutosave === 'function') ffBindSystemSettingsAutosave();
        }
    } catch (e6) {}
}

document.querySelectorAll('.admin-accordion .collapse').forEach(function(el) {
    el.addEventListener('show.bs.collapse', function() { ffOnCollapseShow(this); });
});

document.querySelectorAll('#myUsers .user-admin-select').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var uid = parseInt(sel.getAttribute('data-userid'), 10);
        if (uid) saveUserAdmin(uid, sel);
    });
});

var ffUserPermsModal = null;
var ffUserPermsUserId = 0;
var ffUserPermsIsNewUser = false;

function ffCollectMenuPermissionsFromModal() {
    var menu = {};
    document.querySelectorAll('#ffUserPermsModal .ff-perm-menu').forEach(function(cb) {
        var k = cb.getAttribute('data-key');
        if (k) menu[k] = cb.checked ? 1 : 0;
    });
    var print_targets = [];
    document.querySelectorAll('#ffUserPermsModal .ff-perm-pt').forEach(function(cb) {
        if (cb.checked) {
            var pt = parseInt(cb.getAttribute('data-pt'), 10);
            if (pt > 0) print_targets.push(pt);
        }
    });
    return { menu: menu, print_targets: print_targets };
}

function ffApplyMenuPermissionsToModal(perms) {
    perms = perms || { menu: {}, print_targets: [] };
    var menu = perms.menu || {};
    document.querySelectorAll('#ffUserPermsModal .ff-perm-menu').forEach(function(cb) {
        var k = cb.getAttribute('data-key');
        cb.checked = !!(menu[k]);
    });
    var pts = perms.print_targets || [];
    document.querySelectorAll('#ffUserPermsModal .ff-perm-pt').forEach(function(cb) {
        var pt = parseInt(cb.getAttribute('data-pt'), 10);
        cb.checked = pts.indexOf(pt) >= 0;
    });
}

window.ffGetNewUserMenuPermissionsJson = function() {
    return JSON.stringify(ffCollectMenuPermissionsFromModal());
};

function ffOpenUserPermsModal(userid, username, isNew) {
    ffUserPermsUserId = userid || 0;
    ffUserPermsIsNewUser = !!isNew;
    var el = document.getElementById('ffUserPermsModal');
    if (!el) return;
    var title = document.getElementById('ffUserPermsModalLabel');
    var sub = document.getElementById('ffUserPermsModalUser');
    if (title) title.textContent = isNew ? 'Berechtigungen (neuer Benutzer)' : 'Berechtigungen';
    if (sub) sub.textContent = isNew ? 'Wird beim Anlegen mit gespeichert.' : ('Benutzer: ' + (username || ''));
    var saveBtn = document.getElementById('ffUserPermsSaveBtn');
    if (saveBtn) saveBtn.textContent = isNew ? 'Übernehmen' : 'Speichern';
    if (isNew) {
        ffApplyMenuPermissionsToModal({ menu: {}, print_targets: [] });
        if (!ffUserPermsModal && typeof bootstrap !== 'undefined') {
            ffUserPermsModal = new bootstrap.Modal(el);
        }
        if (ffUserPermsModal) ffUserPermsModal.show();
        return;
    }
    fetch(ffResolveAdminApiUrl('get_user_menu_permissions.php?userid=' + encodeURIComponent(String(userid))), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.ok) {
                alert('Berechtigungen konnten nicht geladen werden.');
                return;
            }
            ffApplyMenuPermissionsToModal(d.permissions);
            var sumEl = document.querySelector('#myUsers .user-perm-summary[data-userid="' + userid + '"]');
            if (sumEl && d.summary) {
                sumEl.textContent = d.summary;
                sumEl.setAttribute('title', d.summary);
            }
            if (!ffUserPermsModal && typeof bootstrap !== 'undefined') {
                ffUserPermsModal = new bootstrap.Modal(el);
            }
            if (ffUserPermsModal) ffUserPermsModal.show();
        })
        .catch(function() { alert('Netzwerkfehler beim Laden der Berechtigungen.'); });
}

function ffSaveUserMenuPermissions() {
    if (ffUserPermsIsNewUser) {
        if (ffUserPermsModal) ffUserPermsModal.hide();
        return;
    }
    var uid = ffUserPermsUserId;
    if (!uid) return;
    var body = new URLSearchParams();
    body.append('userid', String(uid));
    body.append('menu_permissions', JSON.stringify(ffCollectMenuPermissionsFromModal()));
    fetch(ffResolveAdminApiUrl('save_user_menu_permissions.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.ok) {
                var err = (d && d.error) ? d.error : 'unbekannt';
                if (err === 'own_account_locked') err = 'Eigenes Konto: Berechtigungen nicht änderbar.';
                alert('Speichern fehlgeschlagen: ' + err);
                return;
            }
            var sumEl = document.querySelector('#myUsers .user-perm-summary[data-userid="' + uid + '"]');
            if (sumEl && d.summary) {
                sumEl.textContent = d.summary;
                sumEl.setAttribute('title', d.summary);
            }
            if (ffUserPermsModal) ffUserPermsModal.hide();
        })
        .catch(function() { alert('Netzwerkfehler'); });
}

var ffUserStatusModal = null;
var ffUserStatusUserId = 0;

function ffApplyUserStatusDisplay(uid, d) {
    if (!uid || !d) return;
    var badge = document.querySelector('.ff-user-status-badge[data-userid="' + uid + '"]');
    if (badge && typeof d.effective_active !== 'undefined') {
        var active = parseInt(d.effective_active, 10) === 1;
        badge.textContent = active ? 'aktiv' : 'inaktiv';
        badge.classList.remove('text-bg-success', 'text-bg-danger');
        badge.classList.add(active ? 'text-bg-success' : 'text-bg-danger');
    }
    var win = document.querySelector('.user-status-window[data-userid="' + uid + '"]');
    if (win && typeof d.window_label !== 'undefined') {
        win.innerHTML = d.window_label
            ? (' · Fenster: <strong>' + d.window_label.replace(/</g, '&lt;') + '</strong>')
            : '';
    }
    var card = document.querySelector('.ff-user-card[data-userid="' + uid + '"]');
    if (!card) return;
    var hintEl = card.querySelector('.ff-user-window-hint');
    if (d.window_hint) {
        if (!hintEl && badge) {
            hintEl = document.createElement('span');
            hintEl.className = 'badge text-bg-warning text-dark ff-user-window-hint';
            hintEl.setAttribute('data-userid', String(uid));
            badge.insertAdjacentElement('afterend', hintEl);
        }
        if (hintEl) hintEl.textContent = d.window_hint;
    } else if (hintEl) {
        hintEl.remove();
    }
}

function ffSaveUserActiveFlag(cb) {
    var uid = parseInt(cb.getAttribute('data-userid'), 10);
    if (!uid) return;
    var body = new URLSearchParams();
    body.append('userid', String(uid));
    body.append('is_active', cb.checked ? '0' : '1');
    fetch(ffResolveAdminApiUrl('save_user_status.php'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.ok) {
                cb.checked = cb.getAttribute('data-prev') === '1';
                var err = (d && d.error) ? d.error : 'unbekannt';
                if (err === 'own_account_locked') err = 'Eigenes Konto kann nicht deaktiviert werden.';
                if (err === 'super_admin_locked') err = 'Super-Admin kann nicht deaktiviert werden.';
                alert('Status konnte nicht gespeichert werden: ' + err);
            } else {
                cb.setAttribute('data-prev', cb.checked ? '1' : '0');
                ffApplyUserStatusDisplay(uid, d);
            }
        })
        .catch(function() {
            cb.checked = cb.getAttribute('data-prev') === '1';
            alert('Netzwerkfehler');
        });
}

function ffOpenUserStatusModal(btn) {
    ffUserStatusUserId = parseInt(btn.getAttribute('data-userid'), 10) || 0;
    var el = document.getElementById('ffUserStatusModal');
    if (!el || !ffUserStatusUserId) return;
    var sub = document.getElementById('ffUserStatusModalUser');
    if (sub) sub.textContent = 'Benutzer: ' + (btn.getAttribute('data-username') || '');
    var fromEl = document.getElementById('ffUserStatusFrom');
    var untilEl = document.getElementById('ffUserStatusUntil');
    if (fromEl) fromEl.value = btn.getAttribute('data-from') || '';
    if (untilEl) untilEl.value = btn.getAttribute('data-until') || '';
    if (!ffUserStatusModal && typeof bootstrap !== 'undefined') {
        ffUserStatusModal = new bootstrap.Modal(el);
    }
    if (ffUserStatusModal) ffUserStatusModal.show();
}

function ffSaveUserStatusWindow() {
    var uid = ffUserStatusUserId;
    if (!uid) return;
    var fromEl = document.getElementById('ffUserStatusFrom');
    var untilEl = document.getElementById('ffUserStatusUntil');
    var inactiveCb = document.querySelector('#myUsers .user-inactive-check[data-userid="' + uid + '"]');
    var body = new URLSearchParams();
    body.append('userid', String(uid));
    body.append('is_active', (inactiveCb && inactiveCb.checked) ? '0' : '1');
    body.append('active_from', fromEl ? fromEl.value : '');
    body.append('active_until', untilEl ? untilEl.value : '');
    fetch(ffResolveAdminApiUrl('save_user_status.php'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.ok) {
                var err = (d && d.error) ? d.error : 'unbekannt';
                if (err === 'range_invalid') err = '„Aktiv bis“ liegt vor „Aktiv ab“.';
                alert('Speichern fehlgeschlagen: ' + err);
                return;
            }
            var btn = document.querySelector('#myUsers .btn-user-status[data-userid="' + uid + '"]');
            if (btn) {
                btn.setAttribute('data-from', d.active_from || '');
                btn.setAttribute('data-until', d.active_until || '');
            }
            ffApplyUserStatusDisplay(uid, d);
            if (ffUserStatusModal) ffUserStatusModal.hide();
        })
        .catch(function() { alert('Netzwerkfehler'); });
}

(function() {
    var saveBtn = document.getElementById('ffUserStatusSaveBtn');
    if (saveBtn) saveBtn.addEventListener('click', ffSaveUserStatusWindow);
    var clearBtn = document.getElementById('ffUserStatusClearBtn');
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            var fromEl = document.getElementById('ffUserStatusFrom');
            var untilEl = document.getElementById('ffUserStatusUntil');
            if (fromEl) fromEl.value = '';
            if (untilEl) untilEl.value = '';
        });
    }
})();

function ffPermAutoBundleInModal() {
    var anyPt = false;
    document.querySelectorAll('#ffUserPermsModal .ff-perm-pt').forEach(function(cb) {
        if (cb.checked) anyPt = true;
    });
    if (!anyPt) return;
    document.querySelectorAll('#ffUserPermsModal .ff-perm-menu').forEach(function(cb) {
        var k = cb.getAttribute('data-key');
        if (k === 'mitarbeiter_verpflegung' || k === 'pw_change') cb.checked = true;
    });
}

(function() {
    var saveBtn = document.getElementById('ffUserPermsSaveBtn');
    if (saveBtn) saveBtn.addEventListener('click', ffSaveUserMenuPermissions);
    var newBtn = document.getElementById('btnNewUserPerms');
    if (newBtn) {
        newBtn.addEventListener('click', function() {
            ffOpenUserPermsModal(0, '', true);
        });
    }
    var modalEl = document.getElementById('ffUserPermsModal');
    if (modalEl) {
        modalEl.addEventListener('change', function(e) {
            if (e.target && e.target.classList && e.target.classList.contains('ff-perm-pt')) {
                ffPermAutoBundleInModal();
            }
        });
    }
})();

var myUsersTbl = document.getElementById('myUsers');
if (myUsersTbl) {
    myUsersTbl.addEventListener('change', function(e) {
        var ps = e.target.closest && e.target.closest('.user-start-page-select');
        if (ps) {
            ffSyncUserStartPtVisibility(ps);
            var u1 = ps.getAttribute('data-userid');
            if (u1) saveUserStartPageFromSelects(u1);
            return;
        }
        var pts = e.target.closest && e.target.closest('.user-start-pt-select');
        if (pts) {
            var u2 = pts.getAttribute('data-userid');
            if (u2) saveUserStartPageFromSelects(u2);
            return;
        }
        var dvs = e.target.closest && e.target.closest('.user-dv-bon-target-select');
        if (dvs) {
            var u3 = dvs.getAttribute('data-userid');
            if (u3) window.saveUserDvBonTargetFromSelect(u3);
            return;
        }
        var dn = e.target.closest && e.target.closest('.user-display-name-input');
        if (dn) {
            var u4 = dn.getAttribute('data-userid');
            if (u4) window.saveUserDisplayNameFromInput(u4);
            return;
        }
        var inactiveCb = e.target.closest && e.target.closest('.user-inactive-check');
        if (inactiveCb) {
            ffSaveUserActiveFlag(inactiveCb);
        }
    });
    myUsersTbl.addEventListener('click', function(e) {
        var permBtn = e.target.closest && e.target.closest('.btn-user-perms');
        if (permBtn) {
            e.preventDefault();
            var uidP = parseInt(permBtn.getAttribute('data-userid'), 10);
            var unP = permBtn.getAttribute('data-username') || '';
            if (uidP) ffOpenUserPermsModal(uidP, unP, false);
            return;
        }
        var statusBtn = e.target.closest && e.target.closest('.btn-user-status');
        if (statusBtn) {
            e.preventDefault();
            ffOpenUserStatusModal(statusBtn);
            return;
        }
        var pwBtn = e.target.closest && e.target.closest('.btn-user-pw-reset');
        if (pwBtn) {
            e.preventDefault();
            ffOpenUserPwResetModal(pwBtn);
            return;
        }
        var ownPwBtn = e.target.closest && e.target.closest('.btn-user-pw-own');
        if (ownPwBtn) {
            e.preventDefault();
            ffOpenAdminOwnPwModal();
        }
    });
    // Enter im Display-Name Feld -> sofort speichern + Fokus verlassen
    myUsersTbl.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        var dn = e.target.closest && e.target.closest('.user-display-name-input');
        if (dn) {
            e.preventDefault();
            dn.blur();
        }
    });
}

var admNew = ffById('adminyesno');
if (admNew) {
    admNew.addEventListener('change', function() {
        var row = ffById('newUserStartRow');
        if (row) row.style.display = admNew.value === '1' ? 'none' : '';
        ffSyncNewUserPtWrap();
    });
}
var nusp = ffById('new_user_start_page');
if (nusp) nusp.addEventListener('change', ffSyncNewUserPtWrap);
ffSyncNewUserPtWrap();
ffSyncAllUserStartPtWraps();

document.addEventListener('click', function(e) {
    var delBtn = e.target.closest && e.target.closest('.btn-user-delete');
    if (!delBtn) return;
    e.preventDefault();
    var uid = parseInt(delBtn.getAttribute('data-userid'), 10);
    var uname = delBtn.getAttribute('data-username') || '';
    if (uid > 0 && typeof window.deleteUser === 'function') {
        window.deleteUser(uid, uname);
    }
});

function adminFormatEur(n) {
    try {
        return Number(n).toLocaleString('de-AT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    } catch (e) {
        return String(n) + ' €';
    }
}

function adminDashboardPrinterLabel(svc) {
    var labels = (window.__FF_ADMIN_DASHBOARD_INIT && window.__FF_ADMIN_DASHBOARD_INIT.printer_service_labels) || window.__FF_PRINTER_LABELS || {};
    if (labels[svc]) return labels[svc];
    var p = window.__FF_ADMIN_DASHBOARD_INIT && window.__FF_ADMIN_DASHBOARD_INIT.printer_services && window.__FF_ADMIN_DASHBOARD_INIT.printer_services[svc];
    if (p && p.display_name) return p.display_name;
    var map = { kueche: 'Küche', schank: 'Schank', rechnung: 'Rechnung (Thermo)' };
    if (map[svc]) return map[svc];
    return svc;
}

function adminDashEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

var ffAdminPrintPollTimer = null;
var FF_ADMIN_PRINT_SVC_ACK_KEY = 'ff_admin_print_service_ack';
var FF_ADMIN_PRINT_ISSUE_ACK_KEY = 'ff_admin_print_issue_ack';

function adminPrintLoadServiceAcks() {
    try {
        var raw = localStorage.getItem(FF_ADMIN_PRINT_SVC_ACK_KEY);
        if (!raw) {
            return {};
        }
        var o = JSON.parse(raw);
        return o && typeof o === 'object' ? o : {};
    } catch (e) {
        return {};
    }
}

function adminPrintSaveServiceAcks(map) {
    try {
        localStorage.setItem(FF_ADMIN_PRINT_SVC_ACK_KEY, JSON.stringify(map || {}));
    } catch (e) {}
}

function adminPrintLoadIssueAcks() {
    try {
        var raw = localStorage.getItem(FF_ADMIN_PRINT_ISSUE_ACK_KEY);
        if (!raw) {
            return {};
        }
        var o = JSON.parse(raw);
        return o && typeof o === 'object' ? o : {};
    } catch (e) {
        return {};
    }
}

function adminPrintSaveIssueAcks(map) {
    try {
        localStorage.setItem(FF_ADMIN_PRINT_ISSUE_ACK_KEY, JSON.stringify(map || {}));
    } catch (e) {}
}

/** Quittierungen aufheben, sobald Heartbeat wieder OK bzw. Job-Probleme weg sind. */
function adminPrintClearResolvedAcks(d) {
    if (!d) {
        return;
    }
    var svcAck = adminPrintLoadServiceAcks();
    var svcChanged = false;
    if (d.printer_services) {
        Object.keys(d.printer_services).forEach(function(svc) {
            if (d.printer_services[svc].state === 'ok' && svcAck[svc]) {
                delete svcAck[svc];
                svcChanged = true;
            }
        });
    }
    if (svcChanged) {
        adminPrintSaveServiceAcks(svcAck);
    }

    var issueAck = adminPrintLoadIssueAcks();
    var issueChanged = false;
    var jb = d.printer_jobs_by_status || {};
    if ((jb.error || 0) < 1 && issueAck.job_errors) {
        delete issueAck.job_errors;
        issueChanged = true;
    }
    if ((d.printer_jobs_stuck_reserved || 0) < 1 && issueAck.stuck_reserved) {
        delete issueAck.stuck_reserved;
        issueChanged = true;
    }
    if (issueChanged) {
        adminPrintSaveIssueAcks(issueAck);
    }
}

function adminPrintIsServiceAcked(svc) {
    var ack = adminPrintLoadServiceAcks();
    return !!ack[svc];
}

function adminPrintAckService(svc) {
    if (!svc) {
        return;
    }
    var ack = adminPrintLoadServiceAcks();
    ack[svc] = Date.now();
    adminPrintSaveServiceAcks(ack);
    if (window.__FF_ADMIN_DASH_LAST) {
        adminDashboardApplyPayload(window.__FF_ADMIN_DASH_LAST);
    }
}

function adminPrintUnackService(svc) {
    if (!svc) {
        return;
    }
    var ack = adminPrintLoadServiceAcks();
    delete ack[svc];
    adminPrintSaveServiceAcks(ack);
    if (window.__FF_ADMIN_DASH_LAST) {
        adminDashboardApplyPayload(window.__FF_ADMIN_DASH_LAST);
    }
}

function adminPrintAckIssue(code) {
    if (!code) {
        return;
    }
    var ack = adminPrintLoadIssueAcks();
    ack[code] = Date.now();
    adminPrintSaveIssueAcks(ack);
    if (window.__FF_ADMIN_DASH_LAST) {
        adminDashboardApplyPayload(window.__FF_ADMIN_DASH_LAST);
    }
}

function adminPrintUnackIssue(code) {
    if (!code) {
        return;
    }
    var ack = adminPrintLoadIssueAcks();
    delete ack[code];
    adminPrintSaveIssueAcks(ack);
    if (window.__FF_ADMIN_DASH_LAST) {
        adminDashboardApplyPayload(window.__FF_ADMIN_DASH_LAST);
    }
}

/** Stale-Dienste, die noch nicht quittiert sind. */
function adminPrintStaleUnacked(d) {
    var list = d && d.printer_stale_services ? d.printer_stale_services.slice() : [];
    return list.filter(function(svc) {
        return !adminPrintIsServiceAcked(svc);
    });
}

function adminPrintHasVisibleIssues(d) {
    if (!d) {
        return false;
    }
    adminPrintClearResolvedAcks(d);
    if (adminPrintStaleUnacked(d).length > 0) {
        return true;
    }
    var issueAck = adminPrintLoadIssueAcks();
    var jb = d.printer_jobs_by_status || {};
    if ((jb.error || 0) > 0 && !issueAck.job_errors) {
        return true;
    }
    if ((d.printer_jobs_stuck_reserved || 0) > 0 && !issueAck.stuck_reserved) {
        return true;
    }
    return false;
}

function adminPrintPrinterRowActionHtml(svc, st) {
    var acked = adminPrintIsServiceAcked(svc);
    var needsAck = st === 'stale' || st === 'unknown';
    if (!needsAck && !acked) {
        return '<span class="text-muted">—</span>';
    }
    var esc = adminDashEsc(svc);
    if (acked && needsAck) {
        return '<button type="button" class="btn btn-sm btn-outline-secondary" data-ff-print-unack="' + esc + '" title="Warnung wieder anzeigen">Quittierung aufheben</button>';
    }
    if (needsAck) {
        return '<button type="button" class="btn btn-sm btn-outline-warning" data-ff-print-ack="' + esc + '" title="Bewusst stillgelegt (z. B. Kassa aus)">Quittieren</button>';
    }
    return '<span class="text-muted">—</span>';
}

function ffAdminDashboardPrinterTableSetup() {
    var tb = ffById('dashPrinterTbody');
    if (tb && tb.getAttribute('data-ff-print-ack-bound') !== '1') {
        tb.setAttribute('data-ff-print-ack-bound', '1');
        tb.addEventListener('click', function(ev) {
            var ackBtn = ev.target.closest('[data-ff-print-ack]');
            if (ackBtn) {
                ev.preventDefault();
                adminPrintAckService(ackBtn.getAttribute('data-ff-print-ack'));
                return;
            }
            var unackBtn = ev.target.closest('[data-ff-print-unack]');
            if (unackBtn) {
                ev.preventDefault();
                adminPrintUnackService(unackBtn.getAttribute('data-ff-print-unack'));
            }
        });
    }
    var alertUl = ffById('dashPrintAlertList');
    if (alertUl && alertUl.getAttribute('data-ff-print-ack-bound') !== '1') {
        alertUl.setAttribute('data-ff-print-ack-bound', '1');
        alertUl.addEventListener('click', function(ev) {
            // Heartbeat-Zeilen oben: gleiches Quittieren wie in der Tabelle
            var svcAck = ev.target.closest('[data-ff-print-ack]');
            if (svcAck) {
                ev.preventDefault();
                adminPrintAckService(svcAck.getAttribute('data-ff-print-ack'));
                return;
            }
            var svcUnack = ev.target.closest('[data-ff-print-unack]');
            if (svcUnack) {
                ev.preventDefault();
                adminPrintUnackService(svcUnack.getAttribute('data-ff-print-unack'));
                return;
            }
            var ib = ev.target.closest('[data-ff-print-issue-ack]');
            if (ib) {
                ev.preventDefault();
                adminPrintAckIssue(ib.getAttribute('data-ff-print-issue-ack'));
                return;
            }
            var iu = ev.target.closest('[data-ff-print-issue-unack]');
            if (iu) {
                ev.preventDefault();
                adminPrintUnackIssue(iu.getAttribute('data-ff-print-issue-unack'));
            }
        });
    }
}

function adminPrintNotifyUpdateStatus() {
    var el = ffById('dashPrintNotifyStatus');
    if (!el) return;
    if (!('Notification' in window)) {
        el.textContent = 'Keine Browser-Benachrichtigungen (zu alt oder iOS Safari eingeschränkt).';
        return;
    }
    if (Notification.permission === 'granted') el.textContent = 'Berechtigung: erteilt';
    else if (Notification.permission === 'denied') el.textContent = 'Berechtigung: abgelehnt (Browser-Einstellungen)';
    else el.textContent = 'Berechtigung: noch offen – Button klicken';
}

function adminMaybeNotifyPrintIssues(d) {
    if (!d || !adminPrintHasVisibleIssues(d)) {
        try { localStorage.removeItem('ff_admin_print_last_sig'); } catch (e) {}
        return;
    }
    var cb = ffById('dashPrintNotifyEnable');
    if (!cb || !cb.checked) return;
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    var jb = d.printer_jobs_by_status || {};
    var parts = [];
    var staleUa = adminPrintStaleUnacked(d);
    if (staleUa.length) {
        parts.push('stale:' + staleUa.join(','));
    }
    parts.push('err:' + (jb.error || 0));
    parts.push('stuck:' + (d.printer_jobs_stuck_reserved || 0));
    var sig = parts.join('|');
    var prev = '';
    try { prev = localStorage.getItem('ff_admin_print_last_sig') || ''; } catch (e) {}
    if (sig === prev) return;
    try { localStorage.setItem('ff_admin_print_last_sig', sig); } catch (e) {}
    var body = [];
    if (staleUa.length) {
        body.push('Client/Heartbeat: ' + staleUa.map(function(s) { return adminDashboardPrinterLabel(s); }).join(', '));
    }
    if ((jb.error || 0) > 0) body.push('Jobs mit Fehler: ' + jb.error);
    if ((d.printer_jobs_stuck_reserved || 0) > 0) body.push('Hängend (reserved): ' + d.printer_jobs_stuck_reserved);
    try {
        new Notification('FF Bestellsystem – Druck', { body: body.join(' · '), tag: 'ff-print-health' });
    } catch (e) {}
}

function adminDashboardApplyPrintAlerts(d) {
    var box = ffById('dashPrintAlertCritical');
    var ul = ffById('dashPrintAlertList');
    var js = ffById('dashPrinterJobsSummary');
    if (!d) {
        return;
    }
    adminPrintClearResolvedAcks(d);
    if (js && d.printer_jobs_by_status) {
        var jb = d.printer_jobs_by_status;
        var stuck = d.printer_jobs_stuck_reserved || 0;
        var sm = d.printer_job_stuck_reserved_min != null ? d.printer_job_stuck_reserved_min : 10;
        var line = 'Druckjobs: pending ' + (jb.pending || 0) + ', reserved ' + (jb.reserved || 0) + ', done ' + (jb.done || 0) + ', Fehler ' + (jb.error || 0);
        if (stuck > 0) line += ' · <span class="text-danger fw-semibold">hängend reserved (&gt;' + sm + ' Min): ' + stuck + '</span>';
        js.innerHTML = line;
    }
    if (!box || !ul) {
        return;
    }
    var issueAck = adminPrintLoadIssueAcks();
    var items = [];
    var staleUa = adminPrintStaleUnacked(d);
    staleUa.forEach(function(svc) {
        var lbl = adminDashEsc(adminDashboardPrinterLabel(svc));
        var esc = adminDashEsc(svc);
        items.push(
            '<li class="d-flex flex-wrap justify-content-between align-items-center gap-2">'
            + '<span>Heartbeat <strong>' + lbl + '</strong>: zu alt / vermutlich offline</span>'
            + '<button type="button" class="btn btn-sm btn-outline-warning flex-shrink-0" data-ff-print-ack="' + esc + '">Quittieren</button>'
            + '</li>'
        );
    });
    var jb2 = d.printer_jobs_by_status || {};
    if ((jb2.error || 0) > 0 && !issueAck.job_errors) {
        items.push(
            '<li class="d-flex flex-wrap justify-content-between align-items-center gap-2">'
            + '<span><strong>' + jb2.error + '</strong> Druckjob(s) mit Status <code>error</code></span>'
            + '<button type="button" class="btn btn-sm btn-outline-warning flex-shrink-0" data-ff-print-issue-ack="job_errors">Quittieren</button>'
            + '</li>'
        );
    }
    if ((d.printer_jobs_stuck_reserved || 0) > 0 && !issueAck.stuck_reserved) {
        var sm2 = d.printer_job_stuck_reserved_min != null ? d.printer_job_stuck_reserved_min : 10;
        items.push(
            '<li class="d-flex flex-wrap justify-content-between align-items-center gap-2">'
            + '<span><strong>' + d.printer_jobs_stuck_reserved + '</strong> Job(s) hängen in <code>reserved</code> (&gt;' + sm2 + ' Min.)</span>'
            + '<button type="button" class="btn btn-sm btn-outline-warning flex-shrink-0" data-ff-print-issue-ack="stuck_reserved">Quittieren</button>'
            + '</li>'
        );
    }
    if (items.length === 0) {
        box.classList.add('d-none');
        ul.innerHTML = '';
    } else {
        box.classList.remove('d-none');
        ul.innerHTML = items.join('');
    }
}

function adminDashboardApplyPayload(d) {
    if (d && d.ok) {
        window.__FF_ADMIN_DASH_LAST = d;
    }
    if (d && d.printer_service_labels) {
        window.__FF_PRINTER_LABELS = d.printer_service_labels;
    }
    var metaEl = ffById('dashFestMeta');
    var hinweisApi = ffById('dashHinweisApi');
    var tbody = ffById('dashPrinterTbody');
    if (!d || !d.ok) return;
    var h = ffById('dashUmsatzHeute');
    var gk = ffById('dashUmsatzGesamtKombiniert'), bs = ffById('dashUmsatzBereicheSumme');
    var zh = ffById('dashZeilenHeute'), zg = ffById('dashZeilenGesamt');
    var bereichBox = ffById('dashBereicheUmsatz');
    if (h) h.textContent = adminFormatEur(d.umsatz_heute);
    if (typeof window.ffFinanceApplyVerkaufSplit === 'function') {
        window.ffFinanceApplyVerkaufSplit(d);
    } else {
        var g = ffById('dashUmsatzKelDirekt');
        if (g) g.textContent = adminFormatEur(d.verkauf_unzugeordnet != null ? d.verkauf_unzugeordnet : d.umsatz_gesamt);
        if (bs) bs.textContent = adminFormatEur(d.umsatz_bereiche_summe);
        if (gk) gk.textContent = adminFormatEur(d.umsatz_gesamt_kombiniert);
    }
    if (zh) zh.textContent = String(d.zeilen_heute);
    if (zg) zg.textContent = String(d.zeilen_gesamt);
    if (bereichBox) {
        var br = d.bereiche_umsatz || [];
        if (!br.length) {
            bereichBox.innerHTML = '<span class="text-muted">Keine Finanzbereiche angelegt (Tab Finanzen → Kassen).</span>';
        } else {
            var html = '<table class="table table-sm table-bordered mb-0 bg-white"><thead><tr><th>Bereich</th><th>Kasse</th><th>Summe</th></tr></thead><tbody>';
            br.forEach(function(b) {
                html += '<tr><td>' + adminDashEsc(b.name) + '</td><td>' + adminFormatEur(b.kassen_umsatz) + '</td><td><strong>' + adminFormatEur(b.umsatz_gesamt) + '</strong></td></tr>';
            });
            bereichBox.innerHTML = html + '</tbody></table>';
        }
    }
    if (metaEl) {
        var name = (d.fest_name || '').trim();
        var idPart = d.fest_id ? 'Fest-ID ' + d.fest_id : 'Kein aktuelles Fest';
        metaEl.textContent = (name ? name + ' · ' : '') + idPart;
    }
    if (hinweisApi) {
        if (d.hinweis) {
            hinweisApi.textContent = d.hinweis;
            hinweisApi.classList.remove('d-none');
        } else {
            hinweisApi.textContent = '';
            hinweisApi.classList.add('d-none');
        }
    }
    if (typeof ffRenderPositionStockList === 'function') {
        ffRenderPositionStockList(d.position_stock, 'dashPositionStock', 'dashPositionStockWrap');
    }
    adminDashboardApplyPrintAlerts(d);
    adminMaybeNotifyPrintIssues(d);
    if (tbody && d.printer_services) {
        var rows = [];
        Object.keys(d.printer_services).sort().forEach(function(svc) {
            var p = d.printer_services[svc];
            var st = p.state || 'unknown';
            var acked = adminPrintIsServiceAcked(svc);
            var badge = 'bg-secondary';
            var stText = 'Unbekannt';
            if (st === 'ok') {
                badge = 'bg-success';
                stText = 'OK';
            } else if (acked && (st === 'stale' || st === 'unknown')) {
                badge = 'bg-secondary';
                stText = 'Quittiert (stillgelegt)';
            } else if (st === 'stale') {
                badge = 'bg-danger';
                stText = 'Zu alt / vermutlich offline';
            }
            var last = (p.last_seen && String(p.last_seen).trim()) ? adminDashEsc(p.last_seen) : '—';
            var age = (p.age_sec != null && p.age_sec !== '') ? (adminDashEsc(p.age_sec) + ' s') : '—';
            var host = (p.host && String(p.host).trim()) ? adminDashEsc(p.host) : '—';
            var detail = (st === 'ok' || st === 'stale') ? ('<span class="text-muted">' + age + '</span>') : ('<span class="text-muted">kein Heartbeat</span>');
            var svcLabel = (p && p.display_name) ? p.display_name : adminDashboardPrinterLabel(svc);
            var action = adminPrintPrinterRowActionHtml(svc, st);
            rows.push('<tr><td>' + adminDashEsc(svcLabel) + '</td><td><span class="badge ' + badge + '">' + stText + '</span> ' + detail + '</td><td>' + last + '</td><td>' + host + '</td><td class="text-end text-nowrap">' + action + '</td></tr>');
        });
        tbody.innerHTML = rows.length ? rows.join('') : '<tr><td colspan="5" class="text-muted">Keine Daten</td></tr>';
    }
}

function adminDashboardRefresh() {
    var tbody = ffById('dashPrinterTbody');
    var hadInline = false;
    try {
        var init = window.__FF_ADMIN_DASHBOARD_INIT;
        if (init && typeof init === 'object' && init.ok) {
            adminDashboardApplyPayload(init);
            hadInline = true;
        }
    } catch (e0) {}
    try {
        delete window.__FF_ADMIN_DASHBOARD_INIT;
    } catch (e1) {}

    fetchGet('admin_dashboard_api.php')
        .then(function(r) {
            return r.text().then(function(t) {
                var j = null;
                try {
                    j = JSON.parse(t);
                } catch (e) {
                    return { _bad: true, _status: r.status, _snippet: String(t || '').slice(0, 200) };
                }
                return { _bad: false, _status: r.status, data: j };
            });
        })
        .then(function(x) {
            if (x._bad) {
                try { console.warn('admin_dashboard_api (kein JSON, HTTP ' + x._status + ')', x._snippet); } catch (e2) {}
                if (!hadInline && tbody) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-danger">Dashboard: API liefert kein JSON (HTTP ' + x._status + '). Daten oben kamen aus der Seite selbst — Live-Update per API fehlt.</td></tr>';
                }
                return;
            }
            var d = x.data;
            if (!d || !d.ok) {
                var err = (d && d.error) ? d.error : ('HTTP ' + x._status);
                if (!hadInline && tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-danger">Dashboard: ' + String(err) + '</td></tr>';
                return;
            }
            adminDashboardApplyPayload(d);
        })
        .catch(function(err) {
            try { console.warn('admin_dashboard_api fetch', err); } catch (e3) {}
            if (!hadInline) {
                var h = ffById('dashUmsatzHeute');
                if (h) h.textContent = '—';
                if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-danger">Dashboard: Netzwerkfehler. Bei erneutem Laden kommen KPIs aus der eingebetteten Vorschau.</td></tr>';
            }
        });
}

function adminDashboardPrintPollSetup() {
    var cb = ffById('dashPrintNotifyEnable');
    var btn = ffById('dashPrintNotifyPerm');
    if (cb) {
        try {
            cb.checked = localStorage.getItem('ff_admin_print_notify') === '1';
        } catch (e) {}
        cb.addEventListener('change', function() {
            try {
                localStorage.setItem('ff_admin_print_notify', cb.checked ? '1' : '0');
            } catch (e) {}
            adminDashboardPrintPollRestart();
        });
    }
    if (btn) {
        btn.addEventListener('click', function() {
            if (!('Notification' in window)) {
                alert('Dieser Browser unterstützt keine Benachrichtigungen.');
                return;
            }
            Notification.requestPermission().then(function() {
                adminPrintNotifyUpdateStatus();
            });
        });
    }
    adminPrintNotifyUpdateStatus();
    adminDashboardPrintPollRestart();
}

function adminDashboardPrintPollRestart() {
    if (ffAdminPrintPollTimer) {
        clearInterval(ffAdminPrintPollTimer);
        ffAdminPrintPollTimer = null;
    }
    var cb = ffById('dashPrintNotifyEnable');
    if (cb && cb.checked) {
        ffAdminPrintPollTimer = setInterval(function() {
            adminDashboardRefresh();
        }, 120000);
    }
}

function ffAdminSessionIdleUnlimitedSync() {
    var cb = ffById('set_session_idle_unlimited');
    var num = ffById('set_session_max_idle_sec');
    if (!cb || !num) return;
    num.disabled = cb.checked;
}

function ffAdminDashboardRefreshBtnSetup() {
    var btn = ffById('dashRefreshBtn');
    if (!btn || btn.getAttribute('data-ff-dash-refresh-bound') === '1') {
        return;
    }
    btn.setAttribute('data-ff-dash-refresh-bound', '1');
    btn.addEventListener('click', function(ev) {
        ev.stopPropagation();
        ev.preventDefault();
        if (typeof adminDashboardRefresh === 'function') {
            adminDashboardRefresh();
        }
    });
    var actions = btn.closest('.ff-admin-dash-actions');
    if (actions) {
        actions.addEventListener('click', function(ev) {
            ev.stopPropagation();
        });
    }
}

function ffAdminDashboardBoot() {
    ffAdminDashboardPrinterTableSetup();
    ffAdminDashboardRefreshBtnSetup();
    adminDashboardPrintPollSetup();
    adminDashboardRefresh();
    ffAdminSessionIdleUnlimitedSync();
    ffBindSystemSettingsAutosave();
    ffBindPrintTargetAutosave();
}
function ffAdminPageBoot() {
    ffAdminDashboardBoot();
    ffAdminRestoreOpenCollapses();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ffAdminPageBoot);
} else {
    ffAdminPageBoot();
}

function ffSaveSetting(k, v) {
    return fetchPost('save_setting.php', { k: k, v: String(v) })
        .then(function(r) {
            return r.text().then(function(t) {
                if (!r.ok) {
                    throw new Error('Speichern fehlgeschlagen (HTTP ' + r.status + ').');
                }
                var j = null;
                try {
                    j = JSON.parse(t);
                } catch (e) {
                    throw new Error('Ungültige Server-Antwort.');
                }
                if (!j || !j.ok) {
                    throw new Error((j && j.error) ? j.error : 'Speichern fehlgeschlagen.');
                }
            });
        });
}

function ffSystemSettingsStatus(msg, ok) {
    var el = ffById('ffSystemSettingsStatus');
    if (!el) return;
    el.classList.remove('text-muted', 'text-success', 'text-danger');
    el.classList.add(ok === true ? 'text-success' : (ok === false ? 'text-danger' : 'text-muted'));
    el.textContent = msg || '';
}

function ffSaveSystemSettingKey(k, v) {
    ffSystemSettingsStatus('Speichern …', null);
    return ffSaveSetting(k, v)
        .then(function() {
            ffSystemSettingsStatus('Gespeichert', true);
        })
        .catch(function(err) {
            ffSystemSettingsStatus(err && err.message ? err.message : 'Fehler', false);
            throw err;
        });
}

function ffSystemSettingsClampNum(el, min, max, fallback) {
    if (!el) return fallback;
    var n = parseInt(el.value, 10);
    if (isNaN(n)) n = fallback;
    n = Math.max(min, Math.min(max, n));
    el.value = String(n);
    return n;
}

function ffSaveSessionIdleSetting() {
    var sessUnlim = ffById('set_session_idle_unlimited');
    var sessIdle = 0;
    if (sessUnlim && sessUnlim.checked) {
        sessIdle = 0;
    } else {
        sessIdle = ffSystemSettingsClampNum(ffById('set_session_max_idle_sec'), 60, 604800, 900);
    }
    return ffSaveSystemSettingKey('session_max_idle_sec', sessIdle);
}

function ffBindSystemSettingsAutosave() {
    var root = ffById('SystemEinstellungen');
    if (!root || root.dataset.ffSysAutosaveBound === '1') return;
    root.dataset.ffSysAutosaveBound = '1';

    function bindCb(id, key) {
        var el = ffById(id);
        if (!el) return;
        el.addEventListener('change', function() {
            ffSaveSystemSettingKey(key, el.checked ? '1' : '0');
        });
    }
    bindCb('set_kellner_nur_eigene', 'kellner_nur_eigene');
    bindCb('set_fast_refresh', 'fast_refresh');
    bindCb('set_station_summary_top', 'station_summary_top');
    bindCb('set_station_summary_right', 'station_summary_right');
    bindCb('set_station_one_click_abschliessen', 'station_one_click_abschliessen');
    bindCb('set_station_teillieferung_druck', 'station_teillieferung_druck');

    var idleCb = ffById('set_session_idle_unlimited');
    if (idleCb) {
        idleCb.addEventListener('change', function() {
            ffAdminSessionIdleUnlimitedSync();
            ffSaveSessionIdleSetting();
        });
    }
    var idleNum = ffById('set_session_max_idle_sec');
    if (idleNum) {
        idleNum.addEventListener('change', function() { ffSaveSessionIdleSetting(); });
        idleNum.addEventListener('blur', function() { ffSaveSessionIdleSetting(); });
    }

    function bindNum(id, key, min, max, fallback) {
        var el = ffById(id);
        if (!el) return;
        function save() {
            var n = ffSystemSettingsClampNum(el, min, max, fallback);
            ffSaveSystemSettingKey(key, n);
        }
        el.addEventListener('change', save);
        el.addEventListener('blur', save);
    }
    bindNum('set_station_spalten', 'station_spalten', 0, 6, 0);
    bindNum('set_station_spalten_mobil', 'station_spalten_mobil', 0, 2, 0);
    bindNum('set_karte_spalten', 'karte_spalten', 2, 6, 3);
    bindNum('set_karte_spalten_mobil', 'karte_spalten_mobil', 2, 3, 3);
    bindNum('set_tisch_raster_spalten', 'tisch_raster_spalten', 3, 8, 5);
    bindNum('set_tisch_raster_spalten_mobil', 'tisch_raster_spalten_mobil', 3, 5, 5);

    var appTitleEl = ffById('set_app_title');
    if (appTitleEl) {
        function saveAppTitle() {
            var t = String(appTitleEl.value || '').trim();
            if (t.length > 80) t = t.slice(0, 80);
            appTitleEl.value = t;
            // Leer = Fallback $FFName / $Titellogin
            ffSaveSystemSettingKey('app_title', t);
        }
        appTitleEl.addEventListener('change', saveAppTitle);
        appTitleEl.addEventListener('blur', saveAppTitle);
    }
}

/** @deprecated Sofort-Save über ffBindSystemSettingsAutosave; bleibt für Kompatibilität. */
function saveSystemSettings() {
    ffBindSystemSettingsAutosave();
    ffSystemSettingsStatus('Bereits aktiv: Änderungen speichern sich automatisch.', true);
}

function ffAdminClearSchemaCache() {
    var statusEl = ffById('ffSchemaCacheClearStatus');
    if (statusEl) {
        statusEl.classList.remove('text-success', 'text-danger');
        statusEl.classList.add('text-muted');
        statusEl.textContent = 'wird geleert …';
    }
    fetchPost('admin_schema_cache_clear.php', {})
        .then(function(r) {
            return r.text().then(function(t) {
                var j = null;
                try { j = JSON.parse(t); } catch (e) {}
                if (!r.ok || !j || !j.ok) {
                    var msg = (j && (j.message || j.error)) ? (j.message || j.error) : ('HTTP ' + r.status);
                    throw new Error(msg);
                }
                return j;
            });
        })
        .then(function(j) {
            if (statusEl) {
                statusEl.classList.remove('text-muted', 'text-danger');
                statusEl.classList.add('text-success');
                statusEl.textContent = 'OK – ' + (j.deleted || 0) + ' Flag-Dateien gelöscht. Beim nächsten Request laufen die Schema-Checks einmalig wieder.';
            }
        })
        .catch(function(err) {
            if (statusEl) {
                statusEl.classList.remove('text-muted', 'text-success');
                statusEl.classList.add('text-danger');
                statusEl.textContent = 'Fehler: ' + (err && err.message ? err.message : 'unbekannt');
            }
        });
}
window.ffAdminClearSchemaCache = ffAdminClearSchemaCache;

function saveTischFlags(tischnummer, opts) {
    opts = opts || {};
    var sr = ffById('sr_' + tischnummer), eg = ffById('eg_' + tischnummer);
    if (!sr || !eg) {
        if (!opts.silent) alert('Tisch-Zeile nicht gefunden.');
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
    fetchPost('save_tisch_flags.php', { tischnummer: tischnummer, is_sammelrechnung: isSammel, is_ehrengast: isEhren })
        .then(function(r) {
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
                if (!opts.silent) alert('Gespeichert');
            });
        })
        .catch(function(err) { alert(err && err.message ? err.message : 'Fehler'); });
}

/** Sammelrechnung ↔ Ehrengast: nur eine Checkbox pro Tisch aktiv + Sofort-Save */
document.addEventListener('change', function(ev) {
    var t = ev.target;
    if (!t || t.type !== 'checkbox') return;
    var id = t.id || '';
    var m = id.match(/^sr_(\d+)$/);
    if (m) {
        if (t.checked) {
            var eg = ffById('eg_' + m[1]);
            if (eg) eg.checked = false;
        }
        if (document.getElementById('tischFlags') && t.closest && t.closest('#tischFlags')) {
            saveTischFlags(parseInt(m[1], 10), { silent: true });
        }
        return;
    }
    m = id.match(/^eg_(\d+)$/);
    if (m) {
        if (t.checked) {
            var sr = ffById('sr_' + m[1]);
            if (sr) sr.checked = false;
        }
        if (document.getElementById('tischFlags') && t.closest && t.closest('#tischFlags')) {
            saveTischFlags(parseInt(m[1], 10), { silent: true });
        }
    }
});

function savePrintTarget(printTargetId, opts) {
    opts = opts || {};
    var ne = ffById('pt_name_' + printTargetId), se = ffById('pt_sort_' + printTargetId), ae = ffById('pt_active_' + printTargetId);
    if (!ne || !se || !ae) {
        if (!opts.silent) alert('Zeile nicht gefunden.');
        return Promise.reject(new Error('missing'));
    }
    var name = ne.value;
    var sortOrder = parseInt(se.value, 10) || 0;
    var active = ae.checked ? 1 : 0;
    return fetchPost('save_print_target_admin.php', { print_target: printTargetId, name: name, sort_order: sortOrder, active: active })
        .then(function(r) { return r.json(); })
        .then(function(r) {
            if (r && r.ok) {
                if (!opts.silent) alert('Gespeichert');
                return;
            }
            throw new Error((r && r.error) || 'Fehler');
        })
        .catch(function(err) {
            if (!opts.silent) alert(err && err.message ? err.message : 'Fehler');
            throw err;
        });
}

function ffBindPrintTargetAutosave() {
    var table = ffById('printTargetsTable');
    if (!table || table.dataset.ffPtAutosaveBound === '1') return;
    table.dataset.ffPtAutosaveBound = '1';
    table.addEventListener('change', function(ev) {
        var t = ev.target;
        if (!t || !t.classList || !t.classList.contains('ff-pt-autosave')) return;
        var pid = parseInt(t.getAttribute('data-ptid') || '0', 10);
        if (pid > 0) savePrintTarget(pid, { silent: true }).catch(function() {});
    });
    table.addEventListener('focusout', function(ev) {
        var t = ev.target;
        if (!t || !t.classList || !t.classList.contains('ff-pt-autosave')) return;
        if (t.type === 'checkbox') return;
        var pid = parseInt(t.getAttribute('data-ptid') || '0', 10);
        if (pid > 0) savePrintTarget(pid, { silent: true }).catch(function() {});
    });
}

function addPrintTarget() {
    var idEl = ffById('pt_new_id'), nameEl = ffById('pt_new_name'), sortEl = ffById('pt_new_sort');
    if (!idEl || !nameEl || !sortEl) { alert('Druckziel-Formular nicht gefunden (Seite neu laden).'); return; }
    var id = parseInt(idEl.value, 10) || 0;
    var name = String(nameEl.value || '').trim();
    var sortOrder = parseInt(sortEl.value, 10) || 30;
    if (!id || !name) { alert('ID und Name angeben'); return; }
    fetchPost('save_print_target_admin.php', { new: 1, print_target: id, name: name, sort_order: sortOrder, active: 1 })
        .then(function(r) { return r.json(); })
        .then(function(r) { if (r && r.ok) { alert('Hinzugefügt'); location.reload(); } else alert(r && r.error || 'Fehler'); })
        .catch(function() { alert('Fehler'); });
}

function deletePrintTarget(printTargetId) {
    var id = parseInt(printTargetId, 10) || 0;
    if (id <= 0) return;
    if (id === 11 || id === 12) {
        alert('Die Standard-Druckziele Küche (11) und Schank (12) können nicht gelöscht werden.');
        return;
    }
    if (!confirm('Druckziel ' + id + ' wirklich löschen? Es darf keiner Speisekarten-Position mehr zugeordnet sein (in manage/ prüfen).')) return;
    fetchPost('save_print_target_admin.php', { delete: 1, print_target: id })
        .then(function(r) {
            return r.text().then(function(t) {
                var j = null;
                try {
                    j = t ? JSON.parse(t) : null;
                } catch (e1) {
                    j = null;
                }
                return { httpOk: r.ok, body: j, raw: t };
            });
        })
        .then(function(x) {
            var r = x.body;
            if (r && r.ok) {
                alert('Druckziel gelöscht.');
                location.reload();
                return;
            }
            var err = r && r.error;
            if (err === 'protected') {
                alert('Küche (11) und Schank (12) können nicht gelöscht werden.');
            } else if (err === 'positions_referenced') {
                var n = r.count != null ? r.count : '?';
                alert('Noch ' + n + ' Speisekarten-Position(en) nutzen dieses Druckziel. Bitte in manage/ zuerst auf ein anderes Druckziel umstellen.');
            } else if (err === 'not_found') {
                alert('Druckziel existiert nicht (evtl. schon gelöscht).');
            } else if (err === 'server_error') {
                alert('Serverfehler beim Löschen (Datenbank). Details ggf. in den PHP-Logs.');
            } else if (err === 'db_error' && r.message) {
                alert('Datenbank: ' + r.message);
            } else if (!x.httpOk || !r) {
                alert('Ungültige Server-Antwort (kein JSON). PHP-Warnung oder falscher Pfad? Erste Zeichen: ' + String(x.raw || '').substring(0, 120));
            } else {
                alert(err || 'Löschen fehlgeschlagen.');
            }
        })
        .catch(function() { alert('Netzwerkfehler beim Löschen (keine Verbindung).'); });
}

function saveFestRechnungPrefix(festId) {
    var el = ffById('fest_rp_' + festId);
    var v = el ? String(el.value || '').trim() : '';
    fetchPost('fest_set_rechnung_prefix.php', { fest_id: String(festId), rechnung_prefix: v })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                alert('Rechnungs-Präfix gespeichert');
            } else {
                alert('Fehler: ' + (res && res.error ? res.error : 'unbekannt'));
            }
        })
        .catch(function(err) { alert('Fehler: ' + err); });
}

function createFest() {
    var ne = ffById('fest_name'), ce = ffById('fest_code'), rpe = ffById('fest_rechnung_prefix'), de = ffById('fest_datum'), ae = ffById('fest_aktiv'), pe = ffById('fest_payment_mode');
    if (!ne || !ce) { alert('Fest-Formular nicht gefunden.'); return; }
    var name = ne.value;
    var code = ce.value;
    var rechnung_prefix = rpe ? String(rpe.value || '').trim() : '';
    var datum = de ? de.value : '';
    var aktiv = ae ? (ae.checked ? 1 : 0) : 1;
    var payment_mode = pe ? pe.value : 'after';

    fetchPost('fest_save.php', { name: name, code: code, rechnung_prefix: rechnung_prefix, fest_datum: datum, aktiv: aktiv, payment_mode: payment_mode })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                alert('Fest angelegt');
                AdminAnsicht();
            } else {
                alert('Fehler: ' + (res && res.error ? res.error : 'unbekannt'));
            }
        })
        .catch(function(err) { alert('Fehler: ' + err); });
}

function setCurrentFest(id) {
    fetchPost('fest_set_current.php', { id: id })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                alert('Aktuelles Fest gesetzt');
                AdminAnsicht();
            } else {
                alert('Fehler: ' + (res && res.error ? res.error : 'unbekannt'));
            }
        })
        .catch(function(err) { alert('Fehler: ' + err); });
}

function setFestPaymentMode(id, mode) {
    if (!confirm('Zahlungsmodus wirklich ändern?')) return;

    fetchPost('fest_set_payment_mode.php', { id: id, payment_mode: mode })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                AdminAnsicht();
            } else {
                alert('Fehler: ' + (res && res.error ? res.error : 'unbekannt'));
            }
        })
        .catch(function(err) { alert('Fehler: ' + err); });
}

function saveRechnungSettings() {
    var msg = ffById('rechnungSettingsMsg');
    if (msg) msg.textContent = 'speichern...';
    var form = ffById('frmRechnungSettings');
    var formData = new FormData(form);
    var params = new URLSearchParams(formData).toString();
    fetchPost('save_rechnung_settings.php', params)
        .then(function() { var m = ffById('rechnungSettingsMsg'); if (m) m.textContent = 'OK gespeichert'; })
        .catch(function(err) { var m = ffById('rechnungSettingsMsg'); if (m) m.textContent = 'Fehler: ' + err; });
}

function saveNummernSettings() {
    var msgEl = ffById('nummernSettingsMsg');
    msgEl.textContent = 'speichern...';
    var form = ffById('frmNummernSettings');
    var formData = new FormData(form);
    var params = new URLSearchParams(formData).toString();
    fetchPost('save_nummern_settings.php', params)
        .then(function() { msgEl.textContent = 'OK gespeichert'; })
        .catch(function(err) { msgEl.textContent = 'Fehler: ' + err; });
}

function uploadLogo() {
    var fileInput = ffById('logo_file');
    var msgEl = ffById('logoMsg');
    
    if (!fileInput.files || !fileInput.files[0]) {
        alert('Bitte eine Datei auswählen');
        return;
    }
    
    var file = fileInput.files[0];
    if (!file.type.match(/image\/(png|jpeg|gif)/)) {
        alert('Nur PNG, JPG oder GIF erlaubt');
        return;
    }
    
    if (file.size > 2 * 1024 * 1024) {
        alert('Datei zu groß (max. 2 MB)');
        return;
    }
    
    msgEl.textContent = 'Hochladen...';
    
    var formData = new FormData();
    formData.append('logo', file);
    
    fetch((function() {
        try { return new URL('upload_logo.php', window.location.href).href; } catch (e) { return 'upload_logo.php'; }
    })(), {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json, text/javascript, */*;q=0.01',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res && res.ok) {
            msgEl.textContent = 'OK!';
            location.reload();
        } else {
            msgEl.textContent = 'Fehler: ' + (res && res.error ? res.error : 'unbekannt');
        }
    })
    .catch(function(err) {
        msgEl.textContent = 'Fehler: ' + err;
    });
}

function deleteLogo() {
    if (!confirm('Logo wirklich löschen?')) return;
    
    fetchPost('upload_logo.php', { delete: 1 })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                location.reload();
            } else {
                alert('Fehler: ' + (res && res.error ? res.error : 'unbekannt'));
            }
        })
        .catch(function(err) { alert('Fehler: ' + err); });
}

var editRechnungModal = null;

function rechnungThermoDruck(rechnungId) {
    var sel = ffById('rt_pt_' + rechnungId);
    var pt = sel && sel.value ? parseInt(sel.value, 10) : 0;
    if (!pt || pt <= 0) {
        alert('Druckziel wählen.');
        return;
    }
    fetchPost('rechnung_admin_thermo.php', { rechnung_id: String(rechnungId), print_target: String(pt) })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                var ptName = (sel && sel.options && sel.selectedIndex >= 0) ? sel.options[sel.selectedIndex].text : ('ID ' + pt);
                alert('Thermo-Auftrag an „' + ptName + '“ gesendet.');
            } else {
                alert('Fehler: ' + (res && res.error ? res.error : 'unbekannt'));
            }
        })
        .catch(function(err) { alert('Fehler: ' + err); });
}

function editRechnung(id) {
    fetchGet('rechnung_get.php?id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || data.error) {
                alert('Fehler: ' + (data && data.error ? data.error : 'Rechnung nicht gefunden'));
                return;
            }
            ffById('edit_rechnung_id').value = data.id;
            ffById('edit_rechnungsnummer').value = data.rechnungsnummer;
            ffById('edit_is_firma').checked = (parseInt(data.is_firma, 10) === 1);
            ffById('edit_empfaenger_name').value = data.empfaenger_name || '';
            ffById('edit_empfaenger_strasse').value = data.empfaenger_strasse || '';
            ffById('edit_empfaenger_plz').value = data.empfaenger_plz || '';
            ffById('edit_empfaenger_ort').value = data.empfaenger_ort || '';
            ffById('edit_empfaenger_uid').value = data.empfaenger_uid || '';
            
            updateEditFirmaFields();
            
            if (!editRechnungModal) {
                editRechnungModal = new bootstrap.Modal(document.getElementById('editRechnungModal'));
            }
            editRechnungModal.show();
        })
        .catch(function(err) { alert('Fehler: ' + err); });
}

function updateEditFirmaFields() {
    var cb = ffById('edit_is_firma'), wrap = ffById('edit_firma_fields');
    if (!cb || !wrap) return;
    wrap.style.display = cb.checked ? 'block' : 'none';
}

var _eif = ffById('edit_is_firma');
if (_eif) _eif.addEventListener('change', updateEditFirmaFields);

window.ffRenderPositionStockList = function ffRenderPositionStockList(items, boxId, wrapId) {
    var box = ffById(boxId);
    var wrap = wrapId ? ffById(wrapId) : null;
    if (!box) return;
    var list = items || [];
    if (!list.length) {
        if (wrap) wrap.classList.add('d-none');
        box.innerHTML = '<span class="text-muted">Keine begrenzten Positionen.</span>';
        return;
    }
    if (wrap) wrap.classList.remove('d-none');
    var html = '<div class="d-flex flex-wrap gap-2">';
    list.forEach(function(p) {
        var rest = parseInt(p.rest, 10) || 0;
        var max = parseInt(p.max, 10) || 0;
        var cls = rest <= 0 ? 'bg-danger-subtle border-danger' : (rest < 10 ? 'bg-warning-subtle border-warning' : 'bg-white');
        html += '<span class="badge border ' + cls + ' text-dark fw-normal">' + (p.name || '?') + ': <strong>' + rest + '</strong> von ' + max + '</span>';
    });
    box.innerHTML = html + '</div>';
};

function gewinnAktualisieren() {
    var vonEl = ffById('gewinnVon'), bisEl = ffById('gewinnBis');
    var gV = ffById('gVarKosten'), gFe = ffById('gFixeEinnahmen'), gFa = ffById('gFixeAusgaben'), gG = ffById('gGewinn');
    var von = vonEl && vonEl.value ? vonEl.value : '';
    var bis = bisEl && bisEl.value ? bisEl.value : '';
    var url = 'gewinn_api.php';
    if (von || bis) {
        var q = [];
        if (von) q.push('von=' + encodeURIComponent(von));
        if (bis) q.push('bis=' + encodeURIComponent(bis));
        url += '?' + q.join('&');
    }
    fetchGet(url)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.error) { return; }
            var gBd = ffById('gBereicheDetail');
            if (typeof window.ffFinanceApplyVerkaufSplit === 'function') {
                window.ffFinanceApplyVerkaufSplit(d);
            } else {
                var gUb = ffById('gUmsatzBereiche');
                var gUk = ffById('gUmsatzKombi');
                var gKd = ffById('gUmsatzKelDirekt');
                if (gKd) gKd.textContent = adminFormatEur(d.verkauf_unzugeordnet != null ? d.verkauf_unzugeordnet : d.umsatz);
                if (gUb) gUb.textContent = adminFormatEur(d.umsatz_bereiche_summe);
                if (gUk) gUk.textContent = adminFormatEur(d.umsatz_gesamt_kombiniert);
            }
            if (gV) gV.textContent = adminFormatEur(d.variable_kosten);
            if (gFe) gFe.textContent = adminFormatEur(d.fixe_einnahmen);
            if (gFa) gFa.textContent = adminFormatEur(d.fixe_ausgaben);
            var g = Number(d.gewinn);
            if (gG) {
                gG.textContent = adminFormatEur(g);
                gG.classList.remove('text-success', 'text-danger');
                gG.classList.add(g >= 0 ? 'text-success' : 'text-danger');
            }
            if (gBd && d.bereiche_umsatz && d.bereiche_umsatz.length) {
                var bh = '<h6 class="text-muted mb-2">Umsatz nach Bereich</h6><div class="table-responsive ff-ov-bereiche-scroll">' +
                    '<table class="table table-sm table-bordered mb-0 bg-white small"><thead><tr><th>Bereich</th><th>Kasse</th><th>Summe</th></tr></thead><tbody>';
                d.bereiche_umsatz.forEach(function(b) {
                    bh += '<tr><td>' + (b.name || '') + '</td><td>' + adminFormatEur(b.kassen_umsatz) + '</td><td><strong>' + adminFormatEur(b.umsatz_gesamt) + '</strong></td></tr>';
                });
                gBd.innerHTML = bh + '</tbody></table></div>';
                gBd.classList.remove('d-none');
            } else if (gBd) {
                gBd.classList.add('d-none');
            }
            ffRenderPositionStockList(d.position_stock, 'ffFinPositionStock', 'ffFinPositionStockWrap');
        })
        .catch(function() { /* ignore */ });
}

function buchungZeileSpeichern(id) {
    var row = document.querySelector('tr[data-buchung-id="' + id + '"]');
    if (!row) { alert('Zeile nicht gefunden.'); return; }
    var typEl = row.querySelector('.bu-typ');
    var bezEl = row.querySelector('.bu-bezeichnung');
    var betEl = row.querySelector('.bu-betrag');
    var berEl = row.querySelector('.bu-bereich');
    var datEl = row.querySelector('.bu-datum');
    var katEl = row.querySelector('.bu-kategorie');
    var notEl = row.querySelector('.bu-notiz');
    var bezeichnung = bezEl ? (bezEl.value || '').trim() : '';
    if (!bezeichnung) { alert('Bezeichnung eingeben'); return; }
    fetchPost('buchungen_save.php', {
        id: String(id),
        typ: typEl ? typEl.value : 'ausgabe',
        bezeichnung: bezeichnung,
        betrag: betEl ? (betEl.value || '0').replace(',', '.') : '0',
        bereich_id: berEl ? (berEl.value || '') : '',
        datum: datEl ? (datEl.value || '') : '',
        kategorie: katEl ? (katEl.value || '').trim() : '',
        notiz: notEl ? (notEl.value || '').trim() : ''
    })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                if (typeof window.ffFinanzenRefreshUi === 'function') {
                    window.ffFinanzenRefreshUi();
                } else {
                    gewinnAktualisieren();
                }
            } else {
                alert('Fehler: ' + (res && res.error ? res.error : 'unbekannt'));
            }
        })
        .catch(function() { alert('Fehler beim Speichern'); });
}

function buchungSpeichern() {
    var typEl = ffById('buchungTyp'), bezEl = ffById('buchungBezeichnung'), betEl = ffById('buchungBetrag');
    var datEl = ffById('buchungDatum'), katEl = ffById('buchungKategorie'), notEl = ffById('buchungNotiz');
    var berEl = ffById('buchungBereich');
    if (!typEl || !bezEl || !betEl) { alert('Buchungsformular nicht gefunden.'); return; }
    var typ = typEl.value;
    var bezeichnung = (bezEl.value || '').trim();
    var betrag = (betEl.value || '0').replace(',', '.');
    var datum = datEl ? (datEl.value || '') : '';
    var kategorie = katEl ? (katEl.value || '').trim() : '';
    var notiz = notEl ? (notEl.value || '').trim() : '';
    var bereichId = berEl ? (berEl.value || '') : '';
    if (!bezeichnung) { alert('Bezeichnung eingeben'); return; }
    fetchPost('buchungen_save.php', { typ: typ, bezeichnung: bezeichnung, betrag: betrag, bereich_id: bereichId, datum: datum, kategorie: kategorie, notiz: notiz })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                ffById('buchungBezeichnung').value = '';
                ffById('buchungBetrag').value = '';
                ffById('buchungDatum').value = '';
                ffById('buchungKategorie').value = '';
                ffById('buchungNotiz').value = '';
                if (berEl) berEl.value = '';
                var done = function() {
                    if (typeof window.ffFinanzenRefreshUi === 'function') {
                        window.ffFinanzenRefreshUi();
                    } else {
                        gewinnAktualisieren();
                    }
                };
                if (typeof window.ffFinanzenReloadBuchungenTbody === 'function') {
                    window.ffFinanzenReloadBuchungenTbody().then(done);
                } else {
                    done();
                }
            } else { alert('Fehler: ' + (res && res.error ? res.error : 'unbekannt')); }
        })
        .catch(function() { alert('Fehler'); });
}

function buchungLoeschen(id) {
    if (!confirm('Buchung wirklich löschen?')) return;
    fetchPost('buchungen_delete.php', { id: id })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                var row = document.querySelector('tr[data-buchung-id="' + id + '"]');
                if (row) row.remove();
                if (typeof window.ffFinanzenRefreshUi === 'function') {
                    window.ffFinanzenRefreshUi();
                } else {
                    gewinnAktualisieren();
                }
            } else alert('Fehler');
        })
        .catch(function() { alert('Fehler'); });
}

function mvFilterAnzeigen() {
    var d = ffById('mvFilterDatum') && ffById('mvFilterDatum').value ? ffById('mvFilterDatum').value : '';
    if (d) location.href = 'admin.php?mv_datum=' + encodeURIComponent(d) + '#MitarbeiterVerpflegung';
    else AdminAnsicht();
}

function mvHinzufuegen() {
    var datum = ffById('mvDatum').value;
    var bereich_id = ffById('mvBereich').value;
    var position_id = ffById('mvPosition').value;
    var menge = parseInt(ffById('mvMenge').value, 10) || 1;
    var notiz = (ffById('mvNotiz').value || '').trim();
    if (!datum || !bereich_id || !position_id) { alert('Datum, Bereich und Position wählen.'); return; }
    fetchPost('mitarbeiter_verpflegung_save.php', { datum: datum, bereich_id: bereich_id, position_id: position_id, menge: menge, notiz: notiz })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                ffById('mvMenge').value = '1';
                ffById('mvNotiz').value = '';
                if (ffById('mvFilterDatum').value === datum) location.reload();
                else { ffById('mvFilterDatum').value = datum; mvFilterAnzeigen(); }
            } else {
                var errTxt = (res && res.message) ? res.message : ((res && res.error) ? res.error : 'Fehler');
                alert(errTxt);
            }
        })
        .catch(function() { alert('Fehler'); });
}

function mvLoeschen(id) {
    if (!confirm('Eintrag wirklich löschen? Die Kapazität der Position wird wieder freigegeben.')) return;
    fetchPost('mitarbeiter_verpflegung_delete.php', { id: id })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                if (typeof mvFilterAnzeigen === 'function') {
                    mvFilterAnzeigen();
                } else {
                    AdminAnsicht();
                }
            } else {
                alert((res && res.error) ? res.error : 'Fehler');
            }
        })
        .catch(function() { alert('Fehler'); });
}

function mvBereichHinzufuegen() {
    var name = (ffById('mvBereichName').value || '').trim();
    var sort_order = parseInt(ffById('mvBereichSort').value, 10) || 0;
    if (!name) { alert('Name eingeben'); return; }
    fetchPost('mitarbeiter_bereich_save.php', { name: name, sort_order: sort_order })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) { ffById('mvBereichName').value = ''; AdminAnsicht(); } else alert('Fehler: ' + (res && res.error ? res.error : ''));
        })
        .catch(function() { alert('Fehler'); });
}

function mvBereichLoeschen(id) {
    if (!confirm('Bereich löschen? Alle Verpflegungseinträge dieses Bereichs gehen verloren.')) return;
    fetchPost('mitarbeiter_bereich_delete.php', { id: id })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) AdminAnsicht(); else alert('Fehler');
        })
        .catch(function() { alert('Fehler'); });
}

function saveRechnungEdit() {
    var data = {
        id: ffById('edit_rechnung_id').value,
        is_firma: ffById('edit_is_firma').checked ? 1 : 0,
        empfaenger_name: ffById('edit_empfaenger_name').value,
        empfaenger_strasse: ffById('edit_empfaenger_strasse').value,
        empfaenger_plz: ffById('edit_empfaenger_plz').value,
        empfaenger_ort: ffById('edit_empfaenger_ort').value,
        empfaenger_uid: ffById('edit_empfaenger_uid').value
    };
    
    fetchPost('rechnung_update.php', data)
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                if (editRechnungModal) {
                    editRechnungModal.hide();
                }
                var rid = parseInt(data.id, 10) || 0;
                if (rid > 0 && ffAdminUpdateRechnungRowEmpfaenger(rid, data)) {
                    return;
                }
                ffAdminReloadPreserveScroll();
            } else {
                alert('Fehler: ' + (res && res.error ? res.error : 'unbekannt'));
            }
        })
        .catch(function(err) { alert('Fehler: ' + err); });
}

function ffAdminBootstrapJsOk() {
    return typeof bootstrap !== 'undefined' && bootstrap.Collapse;
}

function ffAdminEscapeCssId(id) {
    var s = String(id || '');
    if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
        return CSS.escape(s);
    }
    return s.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
}

var ffStatistikEnsurePromise = null;
function ffEnsureStatistikPanel() {
    var el = document.getElementById('Statistik');
    if (el && el.classList && el.classList.contains('collapse')) {
        return Promise.resolve();
    }
    if (ffStatistikEnsurePromise) return ffStatistikEnsurePromise;
    var acc = document.getElementById('adminAccordion');
    var tpl = document.getElementById('ffAdminTplStatistikCard');
    if (!acc || !tpl || !tpl.content) {
        return Promise.reject(new Error('ffAdminTplStatistikCard oder adminAccordion fehlt'));
    }
    acc.appendChild(tpl.content.cloneNode(true));
    var mount = document.getElementById('ffStatistikBodyRemoteMount');
    var url = typeof ffResolveAdminApiUrl === 'function' ? ffResolveAdminApiUrl('admin_statistik_content.php') : 'admin_statistik_content.php';
    ffStatistikEnsurePromise = fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(function(html) {
            if (mount) {
                mount.innerHTML = html;
                mount.setAttribute('data-ff-stat-fetched', '1');
            }
        })
        .catch(function(err) {
            if (mount) {
                mount.innerHTML =
                    '<div class="alert alert-warning mb-0">Statistik konnte nicht geladen werden. Datei <code>admin_statistik_content.php</code> und <code>include/admin_statistik_body.php</code> auf den Server legen und Seite neu laden.</div>';
            }
            throw err;
        })
        .finally(function() {
            ffStatistikEnsurePromise = null;
        });
    return ffStatistikEnsurePromise;
}

var ffFinanzenEnsurePromise = null;
function ffEnsureFinanzenPanel() {
    var el = document.getElementById('Finanzen');
    if (el && el.classList && el.classList.contains('collapse')) {
        return Promise.resolve();
    }
    if (ffFinanzenEnsurePromise) return ffFinanzenEnsurePromise;
    var acc = document.getElementById('adminAccordion');
    var tpl = document.getElementById('ffAdminTplFinanzenCard');
    if (!acc || !tpl || !tpl.content) {
        return Promise.reject(new Error('ffAdminTplFinanzenCard oder adminAccordion fehlt'));
    }
    acc.appendChild(tpl.content.cloneNode(true));
    var mount = document.getElementById('ffFinanzenBodyRemoteMount');
    var url = typeof ffResolveAdminApiUrl === 'function' ? ffResolveAdminApiUrl('admin_finanzen_content.php') : 'admin_finanzen_content.php';
    ffFinanzenEnsurePromise = fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(function(html) {
            if (mount) {
                mount.innerHTML = html;
                mount.setAttribute('data-ff-fin-fetched', '1');
                if (typeof ffFinanceInit === 'function') {
                    ffFinanceInit();
                }
            }
        })
        .catch(function(err) {
            if (mount) {
                mount.innerHTML =
                    '<div class="alert alert-warning mb-0">Finanzen konnte nicht geladen werden. Datei <code>admin_finanzen_content.php</code> und <code>include/admin_finanzen_body.php</code> auf den Server legen.</div>';
            }
            throw err;
        })
        .finally(function() {
            ffFinanzenEnsurePromise = null;
        });
    return ffFinanzenEnsurePromise;
}

function ffAdminOpenSection(collapseId, scrollToId) {
    if (collapseId === 'Finanzen') {
        var fi = document.getElementById('Finanzen');
        if (!fi || !fi.classList || !fi.classList.contains('collapse')) {
            ffEnsureFinanzenPanel()
                .then(function() {
                    ffAdminOpenSection(collapseId, scrollToId);
                })
                .catch(function(err) {
                    try {
                        console.warn('ffAdminOpenSection: Finanzen-Fallback fehlgeschlagen', err);
                    } catch (eIf) {}
                });
            return;
        }
    }
    if (collapseId === 'Statistik') {
        var st = document.getElementById('Statistik');
        if (!st || !st.classList || !st.classList.contains('collapse')) {
            ffEnsureStatistikPanel()
                .then(function() {
                    ffAdminOpenSection(collapseId, scrollToId);
                })
                .catch(function(err) {
                    try {
                        console.warn('ffAdminOpenSection: Statistik-Fallback fehlgeschlagen', err);
                    } catch (eIg) {}
                });
            return;
        }
    }
    var esc = ffAdminEscapeCssId(collapseId);
    var el = null;
    try {
        el = document.querySelector('#' + esc + '.collapse');
    } catch (eSel) {
        el = null;
    }
    if (!el) {
        el = document.getElementById(collapseId);
    }
    if (!el || !el.classList || !el.classList.contains('collapse')) {
        el = null;
    }
    var hdr =
        document.querySelector('#adminAccordion [data-bs-target="#' + collapseId + '"]') ||
        document.querySelector('.admin-accordion [data-bs-target="#' + collapseId + '"]') ||
        document.querySelector('[data-bs-target="#' + collapseId + '"]');
    if (!el && hdr) {
        var sib = hdr.nextElementSibling;
        while (sib) {
            if (sib.classList && sib.classList.contains('collapse')) {
                el = sib;
                break;
            }
            sib = sib.nextElementSibling;
        }
    }
    if (el && !hdr) {
        var prev = el.previousElementSibling;
        if (prev && prev.classList && prev.classList.contains('card-header')) {
            hdr = prev;
        }
    }
    if (!el) {
        try {
            console.warn('ffAdminOpenSection: Collapse „' + collapseId + '“ nicht im Dokument (admin_page.php vollständig laden / Cache leeren).');
        } catch (e0) {}
        return;
    }
    if (!el.getAttribute('data-ff-collapse-inline-guard')) {
        el.setAttribute('data-ff-collapse-inline-guard', '1');
        el.addEventListener('hidden.bs.collapse', function() {
            el.style.removeProperty('display');
            el.style.removeProperty('height');
            el.style.removeProperty('overflow');
        });
    }
    var hasBs = ffAdminBootstrapJsOk();
    function syncHeaderOpen() {
        if (hdr) {
            hdr.classList.remove('collapsed');
            hdr.setAttribute('aria-expanded', 'true');
        }
    }
    /** Ohne Bootstrap: Inline-Styles nötig. Mit Bootstrap: keine display/height setzen — sonst lässt sich der Bereich nicht wieder zuklappen. */
    function forceShowNoBootstrap() {
        el.classList.remove('collapsing');
        el.classList.add('show');
        el.style.display = 'block';
        el.style.height = 'auto';
        el.style.overflow = 'visible';
        syncHeaderOpen();
    }
    function forceShowClassOnly() {
        el.classList.remove('collapsing');
        el.classList.add('show');
        el.style.removeProperty('display');
        el.style.removeProperty('height');
        el.style.removeProperty('overflow');
        syncHeaderOpen();
    }
    function scrollToTargetOrPanel() {
        if (scrollToId) {
            var t = document.getElementById(scrollToId);
            if (t) {
                t.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }
        }
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    if (scrollToId && hasBs && !el.classList.contains('show')) {
        el.addEventListener(
            'shown.bs.collapse',
            function ffScrollAfterStatShown() {
                scrollToTargetOrPanel();
                setTimeout(scrollToTargetOrPanel, 120);
                setTimeout(scrollToTargetOrPanel, 400);
            },
            { once: true }
        );
    }
    if (!el.classList.contains('show')) {
        if (hasBs) {
            try {
                bootstrap.Collapse.getOrCreateInstance(el).show();
            } catch (err) {
                forceShowClassOnly();
            }
        } else {
            forceShowNoBootstrap();
        }
    }
    syncHeaderOpen();
    [50, 200, 450].forEach(function(ms) {
        setTimeout(function() {
            if (!el.classList.contains('show')) {
                if (hasBs) {
                    try {
                        bootstrap.Collapse.getOrCreateInstance(el).show();
                    } catch (eR) {
                        forceShowClassOnly();
                    }
                } else {
                    if (hdr) {
                        try {
                            hdr.click();
                        } catch (e1) {}
                    }
                    if (!el.classList.contains('show')) {
                        forceShowNoBootstrap();
                    }
                }
            }
        }, ms);
    });
    if (scrollToId) {
        [350, 700, 1100, 1800].forEach(function(ms) {
            setTimeout(scrollToTargetOrPanel, ms);
        });
    } else {
        setTimeout(function() {
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 300);
    }
    if (collapseId === 'Finanzen' || collapseId === 'Statistik') {
        [0, 150, 400].forEach(function(ms) {
            setTimeout(function() {
                if (typeof ffOnCollapseShow === 'function') {
                    ffOnCollapseShow(el);
                }
            }, ms);
        });
    }
}

function ffBindAdminSectionJumpButtons() {
    document.querySelectorAll('[data-ff-admin-section]').forEach(function(btn) {
        if (btn.getAttribute('data-ff-jump-bound') === '1') return;
        btn.setAttribute('data-ff-jump-bound', '1');
        btn.addEventListener(
            'click',
            function(ev) {
                ev.preventDefault();
                var sec = btn.getAttribute('data-ff-admin-section');
                if (!sec) return;
                var scr = btn.getAttribute('data-ff-admin-scroll');
                ffAdminOpenSection(sec, scr ? scr : undefined);
            },
            true
        );
    });
}

document.addEventListener('click', function(ev) {
    var btn = ev.target && ev.target.closest && ev.target.closest('[data-ff-admin-section]');
    if (!btn || btn.getAttribute('data-ff-jump-bound') === '1') return;
    ev.preventDefault();
    var sec = btn.getAttribute('data-ff-admin-section');
    if (!sec) return;
    var scr = btn.getAttribute('data-ff-admin-scroll');
    ffAdminOpenSection(sec, scr ? scr : undefined);
}, true);
ffBindAdminSectionJumpButtons();
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ffBindAdminSectionJumpButtons);
}

window.adminOpenSection = ffAdminOpenSection;
window.ffBindAdminSectionJumpButtons = ffBindAdminSectionJumpButtons;
window.adminDashboardRefresh = adminDashboardRefresh;
window.addPrintTarget = addPrintTarget;
window.saveFestRechnungPrefix = saveFestRechnungPrefix;
window.setCurrentFest = setCurrentFest;
window.savePrintTarget = savePrintTarget;
window.deletePrintTarget = deletePrintTarget;
window.createFest = createFest;
window.posStatAnzeigen = posStatAnzeigen;
window.ffReloadStatistikBody = ffReloadStatistikBody;
window.ffStatExportKellnerCsv = ffStatExportKellnerCsv;
window.ffStatExportKellnerAufgenommenCsv = ffStatExportKellnerAufgenommenCsv;
window.ffStatExportDetailCsv = ffStatExportDetailCsv;
window.abrechnungVorschau = abrechnungVorschau;
window.abrechnungAusfuehren = abrechnungAusfuehren;
window.ffReloadStatistikBody = ffReloadStatistikBody;
window.ffAdminStatistikFuerKellnerOeffnen = ffAdminStatistikFuerKellnerOeffnen;
window.saveSystemSettings = saveSystemSettings;
window.saveTischFlags = saveTischFlags;
window.saveRechnungSettings = saveRechnungSettings;
window.saveNummernSettings = saveNummernSettings;
window.uploadLogo = uploadLogo;
window.deleteLogo = deleteLogo;
window.rechnungThermoDruck = rechnungThermoDruck;
window.editRechnung = editRechnung;
window.saveRechnungEdit = saveRechnungEdit;
window.ffAdminReloadPreserveScroll = ffAdminReloadPreserveScroll;
window.gewinnAktualisieren = gewinnAktualisieren;
window.buchungSpeichern = buchungSpeichern;
window.buchungZeileSpeichern = buchungZeileSpeichern;
window.buchungLoeschen = buchungLoeschen;
window.mvFilterAnzeigen = mvFilterAnzeigen;
window.mvHinzufuegen = mvHinzufuegen;
window.mvLoeschen = mvLoeschen;
window.mvBereichHinzufuegen = mvBereichHinzufuegen;
window.mvBereichLoeschen = mvBereichLoeschen;

function ffSystemBroadcastApi(action, fields) {
    var body = new URLSearchParams();
    body.append('action', action);
    if (fields) {
        Object.keys(fields).forEach(function(k) {
            body.append(k, fields[k]);
        });
    }
    return fetch(ffResolveAdminApiUrl('save_system_broadcast.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
    }).then(function(r) {
        return r.json().then(function(j) {
            return { ok: r.ok, j: j };
        });
    });
}

(function() {
    var sendBtn = document.getElementById('ffSystemBroadcastSendBtn');
    var endBtn = document.getElementById('ffSystemBroadcastEndBtn');
    var unlockBtn = document.getElementById('ffSystemBroadcastUnlockBtn');
    var textEl = document.getElementById('ffSystemBroadcastText');
    var lockEl = document.getElementById('ffSystemBroadcastLockAll');

    if (sendBtn) {
        sendBtn.addEventListener('click', function() {
            var text = textEl ? String(textEl.value || '').trim() : '';
            var lock = lockEl && lockEl.checked;
            if (!text && !lock) {
                alert(lockEl ? 'Bitte Nachricht eingeben und/oder Login-Sperre aktivieren.' : 'Bitte eine Nachricht eingeben.');
                return;
            }
            var msg = 'Systemnachricht senden?';
            if (lock) {
                msg += '\n\nAlle Benutzer und Administratoren (außer Super-Admin) werden ausgeloggt und können sich nicht mehr anmelden, bis die Sperre aufgehoben wird.';
            }
            if (!confirm(msg)) return;
            sendBtn.disabled = true;
            ffSystemBroadcastApi('publish', {
                text: text,
                lock_all_logins: lock ? '1' : '0'
            })
                .then(function(x) {
                    sendBtn.disabled = false;
                    if (!x.ok || !x.j || !x.j.ok) {
                        alert((x.j && x.j.message) ? x.j.message : 'Senden fehlgeschlagen.');
                        return;
                    }
                    alert(x.j.message || 'Gesendet.');
                    if (typeof AdminAnsicht === 'function') {
                        AdminAnsicht();
                    } else {
                        location.reload();
                    }
                })
                .catch(function() {
                    sendBtn.disabled = false;
                    alert('Netzwerkfehler');
                });
        });
    }

    if (endBtn) {
        endBtn.addEventListener('click', function() {
            if (!confirm('Aktive Systemnachricht für alle beenden?')) return;
            ffSystemBroadcastApi('clear_message', {})
                .then(function(x) {
                    if (!x.ok || !x.j || !x.j.ok) {
                        alert('Fehler beim Beenden.');
                        return;
                    }
                    if (typeof AdminAnsicht === 'function') AdminAnsicht();
                    else location.reload();
                })
                .catch(function() { alert('Netzwerkfehler'); });
        });
    }

    if (unlockBtn) {
        unlockBtn.addEventListener('click', function() {
            if (!confirm('Login-Sperre aufheben? Manuell deaktivierte Konten bleiben inaktiv.')) return;
            ffSystemBroadcastApi('unlock_logins', {})
                .then(function(x) {
                    if (!x.ok || !x.j || !x.j.ok) {
                        alert('Fehler.');
                        return;
                    }
                    alert(x.j.message || 'Sperre aufgehoben.');
                    if (typeof AdminAnsicht === 'function') AdminAnsicht();
                    else location.reload();
                })
                .catch(function() { alert('Netzwerkfehler'); });
        });
    }
})();

(function() {
    var w = document.getElementById('ffAdminBootstrapWarn');
    if (typeof bootstrap === 'undefined') {
        if (w) w.style.display = 'block';
    } else if (w && w.parentNode) {
        w.parentNode.removeChild(w);
    }
})();
