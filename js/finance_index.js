/* Gewinn & Buchungen auf index.php (Finanz-Menü ohne Admin) */
(function() {
    function byId(id) {
        return document.getElementById(id);
    }
    function fetchJson(url, opts) {
        opts = opts || {};
        return fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, opts))
            .then(function(r) { return r.json(); });
    }
    function fetchPost(url, data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function(k) { body.append(k, data[k]); });
        return fetchJson(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body });
    }

    function finFormatEur(n) {
        return (parseFloat(n) || 0).toLocaleString('de-AT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    function finRenderPositionStock(items) {
        var box = byId('ffFinPositionStock');
        var wrap = byId('ffFinPositionStockWrap');
        if (!box) return;
        var list = items || [];
        if (!list.length) {
            if (wrap) wrap.classList.add('d-none');
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
    }

    window.gewinnAktualisieren = function() {
        var vonEl = byId('gewinnVon');
        var bisEl = byId('gewinnBis');
        var gV = byId('gVarKosten');
        var gFe = byId('gFixeEinnahmen');
        var gFa = byId('gFixeAusgaben');
        var gG = byId('gGewinn');
        var von = vonEl && vonEl.value ? vonEl.value : '';
        var bis = bisEl && bisEl.value ? bisEl.value : '';
        var url = 'gewinn_api.php';
        if (von || bis) {
            var q = [];
            if (von) q.push('von=' + encodeURIComponent(von));
            if (bis) q.push('bis=' + encodeURIComponent(bis));
            url += '?' + q.join('&');
        }
        fetchJson(url)
            .then(function(d) {
                if (d.error) {
                    return;
                }
                var gBd = byId('gBereicheDetail');
                if (typeof window.ffFinanceApplyVerkaufSplit === 'function') {
                    window.ffFinanceApplyVerkaufSplit(d);
                } else {
                    var gKd = byId('gUmsatzKelDirekt');
                    var gUb = byId('gUmsatzBereiche');
                    var gUk = byId('gUmsatzKombi');
                    if (gKd) gKd.textContent = finFormatEur(d.verkauf_kellner_direkt != null ? d.verkauf_kellner_direkt : d.umsatz);
                    if (gUb) gUb.textContent = finFormatEur(d.umsatz_bereiche_summe);
                    if (gUk) gUk.textContent = finFormatEur(d.umsatz_gesamt_kombiniert);
                }
                if (gV) gV.textContent = finFormatEur(d.variable_kosten);
                if (gFe) gFe.textContent = finFormatEur(d.fixe_einnahmen);
                if (gFa) gFa.textContent = finFormatEur(d.fixe_ausgaben);
                var g = Number(d.gewinn);
                if (gG) {
                    gG.textContent = finFormatEur(g);
                    gG.classList.remove('text-success', 'text-danger');
                    gG.classList.add(g >= 0 ? 'text-success' : 'text-danger');
                }
                if (gBd && d.bereiche_umsatz && d.bereiche_umsatz.length) {
                    var bh = '<h6 class="text-muted mb-2">Umsatz nach Bereich</h6><div class="table-responsive ff-ov-bereiche-scroll">' +
                        '<table class="table table-sm table-bordered mb-0 bg-white small"><thead><tr><th>Bereich</th><th>Kasse</th><th>Summe</th></tr></thead><tbody>';
                    d.bereiche_umsatz.forEach(function(b) {
                        bh += '<tr><td>' + (b.name || '') + '</td><td>' + finFormatEur(b.kassen_umsatz) + '</td><td><strong>' + finFormatEur(b.umsatz_gesamt) + '</strong></td></tr>';
                    });
                    gBd.innerHTML = bh + '</tbody></table></div>';
                    gBd.classList.remove('d-none');
                } else if (gBd) {
                    gBd.classList.add('d-none');
                }
                finRenderPositionStock(d.position_stock);
            })
            .catch(function() { /* ignore */ });
    };

    window.buchungZeileSpeichern = function(id) {
        var row = document.querySelector('tr[data-buchung-id="' + id + '"]');
        if (!row) { alert('Zeile nicht gefunden.'); return; }
        var bezeichnung = row.querySelector('.bu-bezeichnung');
        var name = bezeichnung ? (bezeichnung.value || '').trim() : '';
        if (!name) { alert('Bezeichnung eingeben'); return; }
        fetchPost('buchungen_save.php', {
            id: String(id),
            typ: row.querySelector('.bu-typ') ? row.querySelector('.bu-typ').value : 'ausgabe',
            bezeichnung: name,
            betrag: row.querySelector('.bu-betrag') ? (row.querySelector('.bu-betrag').value || '0').replace(',', '.') : '0',
            bereich_id: row.querySelector('.bu-bereich') ? (row.querySelector('.bu-bereich').value || '') : '',
            datum: row.querySelector('.bu-datum') ? (row.querySelector('.bu-datum').value || '') : '',
            kategorie: row.querySelector('.bu-kategorie') ? (row.querySelector('.bu-kategorie').value || '').trim() : '',
            notiz: row.querySelector('.bu-notiz') ? (row.querySelector('.bu-notiz').value || '').trim() : ''
        }).then(function(res) {
            if (res && res.ok) {
                if (typeof window.ffFinanzenRefreshUi === 'function') {
                    window.ffFinanzenRefreshUi();
                } else {
                    gewinnAktualisieren();
                }
            } else {
                alert('Fehler: ' + (res && res.error ? res.error : 'unbekannt'));
            }
        }).catch(function() { alert('Fehler beim Speichern'); });
    };

    window.buchungSpeichern = function() {
        var typEl = byId('buchungTyp');
        var bezEl = byId('buchungBezeichnung');
        var betEl = byId('buchungBetrag');
        if (!typEl || !bezEl || !betEl) {
            alert('Buchungsformular nicht gefunden.');
            return;
        }
        var bezeichnung = (bezEl.value || '').trim();
        if (!bezeichnung) {
            alert('Bezeichnung eingeben');
            return;
        }
        fetchPost('buchungen_save.php', {
            typ: typEl.value,
            bezeichnung: bezeichnung,
            betrag: (betEl.value || '0').replace(',', '.'),
            bereich_id: byId('buchungBereich') ? (byId('buchungBereich').value || '') : '',
            datum: byId('buchungDatum') ? (byId('buchungDatum').value || '') : '',
            kategorie: byId('buchungKategorie') ? (byId('buchungKategorie').value || '').trim() : '',
            notiz: byId('buchungNotiz') ? (byId('buchungNotiz').value || '').trim() : ''
        }).then(function(res) {
            if (res && res.ok) {
                bezEl.value = '';
                betEl.value = '';
                if (byId('buchungDatum')) byId('buchungDatum').value = '';
                if (byId('buchungKategorie')) byId('buchungKategorie').value = '';
                if (byId('buchungNotiz')) byId('buchungNotiz').value = '';
                if (byId('buchungBereich')) byId('buchungBereich').value = '';
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
            } else {
                alert('Fehler: ' + (res && res.error ? res.error : 'unbekannt'));
            }
        }).catch(function() { alert('Fehler'); });
    };

    window.buchungLoeschen = function(id) {
        if (!confirm('Buchung wirklich löschen?')) return;
        fetchPost('buchungen_delete.php', { id: String(id) })
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
    };
})();
