/* Finanzverwaltung: Kassen, Kellner, Gesamtauswertung */
(function() {
    function apiGet(url) {
        return fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function(r) { return r.json(); });
    }
    function apiPost(url, data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function(k) { body.append(k, data[k]); });
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: body }).then(function(r) {
            return r.text().then(function(t) {
                try {
                    var j = JSON.parse(t);
                    if (!r.ok && j && !j.message && j.error) {
                        j.message = String(j.error);
                    }
                    return j;
                } catch (e) {
                    return { ok: false, message: 'Server-Antwort ungültig (HTTP ' + r.status + ').' };
                }
            });
        });
    }
    function euro(n) {
        return (parseFloat(n) || 0).toFixed(2).replace('.', ',') + ' €';
    }

    /** Kellner/Direkt, Bereiche, Gesamt; Unzugeordnet-Kachel nur bei Rest ≠ 0. */
    window.ffFinanceApplyVerkaufSplit = function(d) {
        if (!d) return;
        var kel = parseFloat(d.verkauf_kellner_direkt);
        if (isNaN(kel)) {
            kel = parseFloat(d.verkauf_unzugeordnet != null ? d.verkauf_unzugeordnet : d.umsatz) || 0;
        }
        var ech = parseFloat(d.verkauf_echt_unzugeordnet);
        if (isNaN(ech)) ech = 0;
        var br = parseFloat(d.umsatz_bereiche_summe) || 0;
        var kombi = parseFloat(d.umsatz_gesamt_kombiniert);
        if (isNaN(kombi)) kombi = kel + ech + br;

        var kelAnt = parseFloat(d.verkauf_kellner_anteil);
        if (isNaN(kelAnt)) kelAnt = 0;
        var dirAnt = parseFloat(d.verkauf_direktverkauf_anteil);
        if (isNaN(dirAnt)) dirAnt = 0;
        var breakPairs = [
            ['gKelDirektBreakKellner', kelAnt],
            ['gKelDirektBreakDirekt', dirAnt],
            ['dashKelDirektBreakKellner', kelAnt],
            ['dashKelDirektBreakDirekt', dirAnt],
            ['ffOvKelDirektBreakKellner', kelAnt],
            ['ffOvKelDirektBreakDirekt', dirAnt]
        ];
        breakPairs.forEach(function(bp) {
            var el = document.getElementById(bp[0]);
            if (el) el.textContent = euro(bp[1]);
        });

        var pairs = [
            ['gUmsatzKelDirekt', kel],
            ['dashUmsatzKelDirekt', kel],
            ['gUmsatzBereiche', br],
            ['dashUmsatzBereicheSumme', br],
            ['gUmsatzKombi', kombi],
            ['dashUmsatzGesamtKombiniert', kombi],
            ['gUmsatzUnzugeordnet', ech],
            ['dashUmsatzUnzugeordnet', ech]
        ];
        pairs.forEach(function(p) {
            var el = document.getElementById(p[0]);
            if (el) el.textContent = euro(p[1]);
        });
        [['gUmsatzUnzugeordnetWrap', ech], ['dashUmsatzUnzugeordnetWrap', ech]].forEach(function(w) {
            var wrap = document.getElementById(w[0]);
            if (!wrap) return;
            if (Math.abs(w[1]) > 0.004) wrap.classList.remove('d-none');
            else wrap.classList.add('d-none');
        });
        var gf = document.getElementById('dashGesamtumsatzFormel');
        if (gf) {
            var parts = [euro(kel) + ' Kellner/Direkt'];
            if (Math.abs(ech) > 0.004) parts.push(euro(ech) + ' sonstig unzugeordnet');
            parts.push(euro(br) + ' Bereiche');
            gf.textContent = parts.join(' + ');
        }
    };
    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function escAttr(s) {
        return escHtml(s).replace(/'/g, '&#39;');
    }

    var ffIsSuperAdmin = !!window.ffIsSuperAdmin;

    function ffFinanceUndoErrorMsg(err) {
        var map = {
            super_admin_required: 'Nur Super-Admin.',
            admin_required: 'Nur Super-Admin.',
            confirm_phrase: 'Bitte genau AUFHEBEN eingeben.',
            settlement_not_found: 'Abrechnung nicht gefunden.',
            already_voided: 'Abrechnung wurde bereits aufgehoben.',
            session_not_closed: 'Kasse ist nicht abgeschlossen.',
            bereich_has_open_session: 'In diesem Bereich ist bereits eine Kasse offen.',
            already_open: 'Es ist bereits eine Kasse offen.',
            reopen_failed: 'Kassenabschluss konnte nicht aufgehoben werden.',
            unsettle_failed: 'Abrechnung konnte nicht aufgehoben werden.'
        };
        return map[err] || ('Fehler: ' + (err || 'unbekannt'));
    }

    function ffFinanceUndoConfirm(label) {
        if (!ffIsSuperAdmin) {
            alert('Nur Super-Admin.');
            return null;
        }
        var reason = window.prompt(label + '\n\nOptional: Grund (z. B. Verzählt):', '') || '';
        var phrase = window.prompt('Zum Bestätigen bitte AUFHEBEN eingeben:', '');
        if (phrase === null) return null;
        if (String(phrase).toUpperCase().trim() !== 'AUFHEBEN') {
            alert('Abgebrochen — bitte genau AUFHEBEN eingeben.');
            return null;
        }
        return { reason: reason, confirm_phrase: 'AUFHEBEN' };
    }

    window.ffKelUnsettle = function(settlementId, kellnerLabel) {
        var id = parseInt(settlementId, 10);
        if (!(id > 0)) return;
        var conf = ffFinanceUndoConfirm('Kellner-Abrechnung #' + id + (kellnerLabel ? ' (' + kellnerLabel + ')' : '') + ' aufheben?');
        if (!conf) return;
        apiPost('finance_kellner_api.php', {
            action: 'unsettle',
            settlement_id: String(id),
            confirm_phrase: conf.confirm_phrase,
            reason: conf.reason
        }).then(function(d) {
            if (d && d.ok) {
                alert('Abrechnung aufgehoben. ' + (d.orders_reopened || 0) + ' Position(en) wieder offen.');
                ffKelLoadKellner();
                ffKelLoadOpenSummary();
                var sel = document.getElementById('ffKelKellner');
                if (sel && sel.value) ffKelPreview();
            } else {
                alert(ffFinanceUndoErrorMsg(d && d.error));
            }
        });
    };

    window.ffKasseReopen = function(sessionId, bereichName) {
        var id = parseInt(sessionId, 10);
        if (!(id > 0)) return;
        var conf = ffFinanceUndoConfirm('Kassenabschluss #' + id + (bereichName ? ' (' + bereichName + ')' : '') + ' aufheben?');
        if (!conf) return;
        apiPost('finance_kassen_api.php', {
            action: 'reopen',
            session_id: String(id),
            confirm_phrase: conf.confirm_phrase,
            reason: conf.reason
        }).then(function(d) {
            if (d && d.ok) {
                alert('Kasse wieder geöffnet. Du kannst den Abschluss mit korrigierten Zahlen erneut durchführen.');
                ffKasseRefresh();
            } else {
                alert(ffFinanceUndoErrorMsg(d && d.error));
            }
        });
    };

    if (!document.documentElement.dataset.ffKasseDelBereichBound) {
        document.documentElement.dataset.ffKasseDelBereichBound = '1';
        document.addEventListener('click', function(ev) {
            var btn = ev.target && ev.target.closest ? ev.target.closest('.ff-kasse-del-bereich') : null;
            if (!btn) return;
            ev.preventDefault();
            ev.stopPropagation();
            var id = parseInt(btn.getAttribute('data-bereich-id') || '0', 10);
            var nm = btn.getAttribute('data-bereich-name') || '';
            if (id > 0 && typeof window.ffKasseDeleteBereich === 'function') {
                window.ffKasseDeleteBereich(id, nm);
            }
        });
    }

    window.ffFinanzenRefreshUi = function() {
        if (typeof gewinnAktualisieren === 'function') {
            gewinnAktualisieren();
        }
        if (typeof window.ffBrLoadBereichFilter === 'function') {
            window.ffBrLoadBereichFilter();
        }
    };

    window.ffFinanzenReloadBuchungenTbody = function() {
        var tbody = document.getElementById('buchungenTbody');
        if (!tbody) return Promise.resolve();
        return fetch('buchungen_tbody_api.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                if (typeof html === 'string' && html.length) {
                    tbody.innerHTML = html;
                }
            })
            .catch(function() { /* ignore */ });
    };

    window.ffFinanceInit = function() {
        if (document.getElementById('ffKassePanels')) {
            ffKasseRefresh();
            ffKasseLoadBereichList();
        }
        if (document.getElementById('ffBrBereich')) {
            ffBrInit();
        }
        if (document.getElementById('ffKelKellner')) {
            ffKelLoadKellner();
            var kelSel = document.getElementById('ffKelKellner');
            if (kelSel && !kelSel.dataset.ffKelBound) {
                kelSel.dataset.ffKelBound = '1';
                kelSel.addEventListener('change', function() {
                    ffKelLoadMovements();
                });
            }
        }
        if (document.getElementById('buchungenTbody') && document.getElementById('ffBrBereich')) {
            window.ffBrLoadBereichFilter();
        }
        var kelTabBtn = document.getElementById('ffFinTabKellnerBtn');
        if (kelTabBtn && !kelTabBtn.dataset.ffKelTabBound) {
            kelTabBtn.dataset.ffKelTabBound = '1';
            kelTabBtn.addEventListener('shown.bs.tab', function() {
                ffKelLoadKellner();
                ffKelLoadOpenSummary();
            });
        }
        var dvTabBtn = document.getElementById('ffFinTabDvBtn');
        if (dvTabBtn && !dvTabBtn.dataset.ffDvTabBound) {
            dvTabBtn.dataset.ffDvTabBound = '1';
            dvTabBtn.addEventListener('shown.bs.tab', function() {
                ffDvLoadOpenSummary();
                ffDvLoadKellner();
            });
        }
        if (document.getElementById('ffDvKellner')) {
            var dvSel = document.getElementById('ffDvKellner');
            if (!dvSel.dataset.ffDvBound) {
                dvSel.dataset.ffDvBound = '1';
                dvSel.addEventListener('change', function() { ffDvLoadMovements(); });
            }
        }
        var brTabBtn = document.querySelector('[data-bs-target="#ffFinTabBereich"]');
        if (brTabBtn && !brTabBtn.dataset.ffBrTabBound) {
            brTabBtn.dataset.ffBrTabBound = '1';
            brTabBtn.addEventListener('shown.bs.tab', function() {
                ffBrReloadBereiche();
            });
        }
        var kasseTabBtn = document.querySelector('[data-bs-target="#ffFinTabKasse"]');
        if (kasseTabBtn && !kasseTabBtn.dataset.ffKasseTabBound) {
            kasseTabBtn.dataset.ffKasseTabBound = '1';
            kasseTabBtn.addEventListener('shown.bs.tab', function() {
                ffKasseLoadBereichList();
            });
        }
        var gesamtTabBtn = document.querySelector('[data-bs-target="#ffFinTabGesamt"]');
        if (gesamtTabBtn && !gesamtTabBtn.dataset.ffOvTabBound) {
            gesamtTabBtn.dataset.ffOvTabBound = '1';
            gesamtTabBtn.addEventListener('shown.bs.tab', function() {
                ffFinanceOverview();
            });
        }
    };

    window.ffKasseLoadBereichList = function() {
        var box = document.getElementById('ffKasseBereichList');
        if (!box) return;
        apiGet('finance_kassen_api.php?action=list_bereiche').then(function(d) {
            if (!d || !d.ok) {
                box.innerHTML = '<span class="text-danger">Bereiche konnten nicht geladen werden.</span>';
                return;
            }
            var list = d.bereiche || [];
            if (!list.length) {
                box.innerHTML = '<span class="text-muted">Noch keine Finanzbereiche angelegt.</span>';
                return;
            }
            var h = '<div class="table-responsive"><table class="table table-sm table-bordered mb-0 bg-white"><thead><tr>';
            h += '<th>Name</th><th>Status</th><th>Im Gesamtumsatz</th><th class="text-end">Aktion</th></tr></thead><tbody>';
            list.forEach(function(b) {
                var active = parseInt(b.is_active, 10) === 1;
                var bid = parseInt(b.id, 10);
                var bname = String(b.name || '');
                var nurKontrolle = parseInt(b.kontrolle_only, 10) === 1;
                var zaehltMit = !nurKontrolle;
                h += '<tr><td>' + escHtml(bname) + (nurKontrolle ? ' <span class="badge bg-info text-dark">nur Kassenkontrolle</span>' : '') + '</td>';
                h += '<td>' + (active ? '<span class="badge bg-success">aktiv</span>' : '<span class="badge bg-secondary">inaktiv</span>') + '</td>';
                h += '<td><label class="form-check form-check-inline mb-0 small" title="An = fließt in „Umsatz alle Bereiche“ / Gesamtauswertung ein. Aus = nur physischer Kassenabschluss (z. B. DV-Lade).">';
                h += '<input type="checkbox" class="form-check-input ff-kasse-kontrolle-only" data-bereich-id="' + bid + '"';
                h += ' data-kontrolle-only="' + (nurKontrolle ? '1' : '0') + '"' + (zaehltMit ? ' checked' : '') + '> zählt mit</label></td>';
                h += '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger ff-kasse-del-bereich" data-bereich-id="' + bid + '" data-bereich-name="' + escAttr(bname) + '">Löschen</button></td></tr>';
            });
            box.innerHTML = h + '</tbody></table></div>';
            box.querySelectorAll('.ff-kasse-kontrolle-only').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    var bid = parseInt(cb.getAttribute('data-bereich-id'), 10);
                    var countsInTotal = cb.checked;
                    var kontrolleOnly = countsInTotal ? '0' : '1';
                    cb.disabled = true;
                    apiPost('finance_kassen_api.php', {
                        action: 'save_bereich',
                        id: String(bid),
                        is_active: '1',
                        kontrolle_only: kontrolleOnly
                    }).then(function(d) {
                        cb.disabled = false;
                        if (!d || !d.ok) {
                            cb.checked = !countsInTotal;
                            alert('Einstellung konnte nicht gespeichert werden.');
                            return;
                        }
                        cb.setAttribute('data-kontrolle-only', kontrolleOnly);
                        if (typeof window.ffKasseLoadBereichList === 'function') {
                            window.ffKasseLoadBereichList();
                        }
                        if (typeof window.ffFinanceOverview === 'function') {
                            window.ffFinanceOverview();
                        }
                    }).catch(function() {
                        cb.disabled = false;
                        cb.checked = !countsInTotal;
                        alert('Netzwerkfehler.');
                    });
                });
            });
        });
    };

    window.ffKasseDeleteBereich = function(bereichId, bereichName) {
        var name = String(bereichName || '');
        var msg1 = 'Finanzbereich „' + name + '“ wirklich löschen?\n\n'
            + '• Druckziel-Zuordnungen zu diesem Bereich werden entfernt.\n'
            + '• Bereiche mit Kassen-Abschlüssen in der Historie können nicht gelöscht werden.\n'
            + '• Offene Kassen müssen zuerst abgeschlossen sein.';
        if (!confirm(msg1)) return;
        var typed = prompt('Zur Bestätigung den Bereichsnamen exakt eingeben:\n' + name);
        if (typed === null) return;
        if (typed.trim() !== name.trim()) {
            alert('Name stimmt nicht überein — Löschen abgebrochen.');
            return;
        }
        if (!confirm('Letzte Sicherheitsabfrage:\n\n„' + name + '“ unwiderruflich löschen?')) return;
        apiPost('finance_kassen_api.php', { action: 'delete_bereich', id: String(bereichId) })
            .then(function(d) {
                if (d && d.ok) {
                    ffKasseRefresh();
                    ffKasseLoadBereichList();
                    if (typeof window.ffBrReloadBereiche === 'function') {
                        window.ffBrReloadBereiche();
                    }
                    var buSel = document.getElementById('buchungBereich');
                    if (buSel) {
                        for (var i = buSel.options.length - 1; i >= 0; i--) {
                            if (String(buSel.options[i].value) === String(bereichId)) {
                                buSel.remove(i);
                            }
                        }
                    }
                    alert(d.message || 'Bereich gelöscht.');
                } else {
                    alert((d && d.message) ? d.message : 'Löschen nicht möglich.');
                }
            })
            .catch(function() { alert('Netzwerkfehler beim Löschen.'); });
    };

    window.ffKasseSaveBereich = function() {
        var nameEl = document.getElementById('ffKasseBereichName');
        var name = (nameEl && nameEl.value ? nameEl.value : '').trim();
        if (!name) {
            alert('Bitte einen Namen für den Kassenbereich eingeben.');
            return;
        }
        var koEl = document.getElementById('ffKasseBereichKontrolleOnly');
        var ko = koEl && koEl.checked ? '1' : '0';
        apiPost('finance_kassen_api.php', { action: 'save_bereich', name: name, is_active: '1', kontrolle_only: ko })
            .then(function(d) {
                if (d && d.ok) {
                    if (nameEl) nameEl.value = '';
                    if (koEl) koEl.checked = false;
                    ffKasseRefresh();
                    ffKasseLoadBereichList();
                    if (typeof window.ffBrReloadBereiche === 'function') {
                        window.ffBrReloadBereiche();
                    }
                    var buSel = document.getElementById('buchungBereich');
                    if (buSel && d.id) {
                        var exists = false;
                        for (var i = 0; i < buSel.options.length; i++) {
                            if (String(buSel.options[i].value) === String(d.id)) {
                                exists = true;
                                break;
                            }
                        }
                        if (!exists) {
                            var opt = document.createElement('option');
                            opt.value = String(d.id);
                            opt.textContent = name;
                            buSel.appendChild(opt);
                        }
                    }
                } else alert('Fehler');
            });
    };

    window.ffKasseRefresh = function() {
        var statusEl = document.getElementById('ffKasseStatus');
        var panelsEl = document.getElementById('ffKassePanels');
        if (statusEl) statusEl.textContent = 'Lade …';
        apiGet('finance_kassen_api.php?action=status').then(function(d) {
            if (!d || !d.ok) {
                if (statusEl) statusEl.textContent = 'Fehler beim Laden.';
                if (panelsEl) panelsEl.innerHTML = '';
                return;
            }
            var html = '';
            var openCount = (d.open_sessions || []).length;
            var closedRecent = (d.recent_closed || []).length;
            (d.open_sessions || []).forEach(function(os) {
                var s = os.session;
                var sid = s.session_id;
                html += '<div class="card mb-2"><div class="card-body py-2">';
                html += '<strong>' + escHtml(s.name) + '</strong> <span class="badge bg-success">offen</span>';
                html += '<div class="small text-muted mt-1">Wechselgeld Start: <strong>' + euro(s.opening_amount) + '</strong>';
                if (s.opened_at) html += ' · seit ' + escHtml(String(s.opened_at).replace('T', ' ').substring(0, 16));
                html += '</div>';
                html += '<div class="row g-2 mt-2 align-items-end">';
                html += '<div class="col-md-2"><label class="form-label small mb-0">Betrag</label><input type="text" class="form-control form-control-sm" id="ffMovAmt_' + sid + '" placeholder="0,00"></div>';
                html += '<div class="col-md-3"><label class="form-label small mb-0">Kommentar</label><input type="text" class="form-control form-control-sm" id="ffMovNotiz_' + sid + '" placeholder="optional"></div>';
                html += '<div class="col-md-auto"><button class="btn btn-sm btn-outline-secondary me-1" onclick="ffKasseMov(' + sid + ',\'entnahme\')">Entnahme</button>';
                html += '<button class="btn btn-sm btn-outline-secondary" onclick="ffKasseMov(' + sid + ',\'zuzahlung\')">Zuzählung</button></div>';
                html += '</div>';
                html += '<div class="row g-2 mt-2 align-items-end border-top pt-2">';
                html += '<div class="col-md-3"><label class="form-label small mb-0">Tageslosung (Kassenstand)</label>';
                html += '<input type="text" class="form-control form-control-sm" id="ffClose_' + sid + '" placeholder="z. B. 450,00"></div>';
                html += '<div class="col-md-auto"><button class="btn btn-sm btn-danger" onclick="ffKasseClose(' + sid + ')">Abschluss</button></div>';
                html += '</div>';
                if ((os.movements || []).length) {
                    html += '<ul class="small mb-0 mt-2">';
                    (os.movements || []).forEach(function(m) {
                        var lbl = m.typ === 'entnahme' ? 'Entnahme' : 'Zuzählung';
                        html += '<li>' + lbl + ' ' + euro(m.betrag) + (m.notiz ? ' — ' + escHtml(m.notiz) : '') + '</li>';
                    });
                    html += '</ul>';
                }
                html += '</div></div>';
            });
            var bereicheOhneSession = 0;
            (d.bereiche || []).forEach(function(b) {
                if (!b.session_id) {
                    bereicheOhneSession++;
                    html += '<div class="mb-2 p-2 border rounded"><strong>' + escHtml(b.name) + '</strong> (geschlossen)';
                    html += '<div class="mt-2 d-flex flex-wrap gap-2 align-items-end">';
                    html += '<div><label class="form-label small mb-0">Wechselgeld Start</label>';
                    html += '<input type="text" class="form-control form-control-sm" id="ffOpen_' + b.id + '" placeholder="0,00" style="max-width:8rem;"></div>';
                    html += '<button class="btn btn-sm btn-success" onclick="ffKasseOpen(' + b.id + ')">Kasse öffnen</button></div></div>';
                }
            });
            if (panelsEl) {
                panelsEl.innerHTML = html || '<p class="text-muted mb-0">Keine offenen Kassen. Lege oben einen <strong>Kassenbereich</strong> an und öffne die Kasse.</p>';
            }
            if (statusEl) {
                if (!(d.bereiche || []).length) {
                    statusEl.textContent = 'Noch kein Kassenbereich angelegt — oben Namen eintragen und „Bereich anlegen“.';
                } else if (openCount === 0 && bereicheOhneSession > 0) {
                    statusEl.textContent = bereicheOhneSession + ' Bereich(e) bereit — Wechselgeld eintragen und „Kasse öffnen“. Letzte Abschlüsse: ' + closedRecent + '.';
                } else if (openCount > 0) {
                    statusEl.textContent = openCount + ' Kasse(n) offen.';
                } else {
                    statusEl.textContent = 'Alle Kassen geschlossen.';
                }
            }
        }).catch(function() {
            if (statusEl) statusEl.textContent = 'Netzwerkfehler.';
        });
        ffKasseLoadHistory();
    };

    window.ffKasseLoadHistory = function() {
        var box = document.getElementById('ffKasseHistory');
        if (!box) return;
        var von = (document.getElementById('ffKasseHistVon') || {}).value || '';
        var bis = (document.getElementById('ffKasseHistBis') || {}).value || '';
        var q = 'finance_kassen_api.php?action=list_closed&limit=200';
        if (von) q += '&von=' + encodeURIComponent(von);
        if (bis) q += '&bis=' + encodeURIComponent(bis);
        box.innerHTML = '<span class="text-muted">Lade …</span>';
        apiGet(q).then(function(d) {
            if (!d || !d.ok) {
                box.innerHTML = '<span class="text-danger">Historie konnte nicht geladen werden.</span>';
                return;
            }
            var rows = d.closed_sessions || [];
            if (!rows.length) {
                box.innerHTML = '<span class="text-muted">Keine abgeschlossenen Kassen im Zeitraum.</span>';
                return;
            }
            var h = '<div class="table-responsive"><table class="table table-sm table-bordered mb-0 bg-white"><thead><tr>';
            h += '<th>Abschluss</th><th>Bereich</th><th>Wechselgeld</th><th>Entnahmen</th><th>Zuzahlungen</th><th>Tageslosung</th><th>Umsatz</th><th>Bewegungen</th>';
            if (ffIsSuperAdmin) h += '<th>Aktion</th>';
            h += '</tr></thead><tbody>';
            rows.forEach(function(row) {
                var s = row.session || {};
                var sid = parseInt(s.id, 10) || 0;
                var closed = s.closed_at ? String(s.closed_at).replace('T', ' ').substring(0, 16) : '—';
                h += '<tr><td>' + escHtml(closed) + '</td><td>' + escHtml(s.bereich_name || '') + '</td>';
                h += '<td>' + euro(s.opening_amount) + '</td>';
                h += '<td>' + euro(row.entnahmen) + '</td><td>' + euro(row.zuzahlungen) + '</td>';
                h += '<td>' + euro(s.closing_amount) + '</td><td><strong>' + euro(s.revenue_amount) + '</strong></td>';
                h += '<td class="small">';
                (row.movements || []).forEach(function(m) {
                    var lbl = m.typ === 'entnahme' ? 'Entnahme' : 'Zuzählung';
                    h += escHtml(lbl + ' ' + (parseFloat(m.betrag) || 0).toFixed(2).replace('.', ',') + (m.notiz ? ' — ' + m.notiz : '')) + '<br>';
                });
                h += '</td>';
                if (ffIsSuperAdmin) {
                    h += '<td><button type="button" class="btn btn-sm btn-outline-danger" data-session-id="' + sid + '" data-bereich="' + escAttr(s.bereich_name || '') + '" onclick="ffKasseReopen(parseInt(this.getAttribute(\'data-session-id\'),10), this.getAttribute(\'data-bereich\'));">Aufheben</button></td>';
                }
                h += '</tr>';
            });
            box.innerHTML = h + '</tbody></table></div>';
        });
    };

    window.ffKasseExportCsv = function(mode) {
        var von = (document.getElementById('ffKasseHistVon') || {}).value || '';
        var bis = (document.getElementById('ffKasseHistBis') || {}).value || '';
        var q = 'finance_kassen_export.php?mode=' + encodeURIComponent(mode || 'detail');
        if (von) q += '&von=' + encodeURIComponent(von);
        if (bis) q += '&bis=' + encodeURIComponent(bis);
        window.location.href = q;
    };

    window.ffKasseOpen = function(bid) {
        var amt = (document.getElementById('ffOpen_' + bid) || {}).value || '0';
        apiPost('finance_kassen_api.php', { action: 'open', bereich_id: String(bid), opening_amount: amt }).then(function(d) {
            if (d && d.ok) ffKasseRefresh(); else alert('Fehler: ' + (d && d.error));
        });
    };
    window.ffKasseMov = function(sid, typ) {
        var amt = (document.getElementById('ffMovAmt_' + sid) || {}).value || '';
        var notiz = (document.getElementById('ffMovNotiz_' + sid) || {}).value || '';
        apiPost('finance_kassen_api.php', { action: 'movement', session_id: String(sid), typ: typ, betrag: amt, notiz: notiz }).then(function(d) {
            if (d && d.ok) ffKasseRefresh(); else alert('Fehler');
        });
    };
    window.ffKasseClose = function(sid) {
        var amt = (document.getElementById('ffClose_' + sid) || {}).value || '';
        apiPost('finance_kassen_api.php', { action: 'close', session_id: String(sid), closing_amount: amt }).then(function(d) {
            if (d && d.ok) {
                alert('Umsatz: ' + euro(d.revenue_amount));
                ffKasseRefresh();
            } else alert('Fehler');
        });
    };

    function ffFinanceFillKellnerSelect(sel, list, emptyLabel) {
        if (!sel) return;
        sel.innerHTML = '<option value="">—</option>';
        if (!list || !list.length) {
            sel.innerHTML = '<option value="">' + (emptyLabel || '— Keine Benutzer —') + '</option>';
            return;
        }
        list.forEach(function(k) {
            var o = document.createElement('option');
            var login = (k && typeof k === 'object') ? (k.login || '') : String(k || '');
            var label = (k && typeof k === 'object') ? (k.label || login) : String(k || '');
            if (!login) return;
            o.value = login;
            o.textContent = label || login;
            sel.appendChild(o);
        });
    }

    window.ffKelLoadKellner = function() {
        var sel = document.getElementById('ffKelKellner');
        if (!sel) return;
        apiGet('finance_kellner_api.php?action=list_kellner').then(function(d) {
            if (!d || !d.ok) {
                ffFinanceFillKellnerSelect(sel, [], d && d.error === 'finance_forbidden'
                    ? '— Keine Finanz-Berechtigung —' : '— Laden fehlgeschlagen —');
                return;
            }
            ffFinanceFillKellnerSelect(sel, d.kellner || [], '— Keine Benutzer in der Datenbank —');
        }).catch(function() {
            ffFinanceFillKellnerSelect(sel, [], '— Laden fehlgeschlagen —');
        });
        apiGet('finance_kellner_api.php?action=history').then(function(d) {
            var box = document.getElementById('ffKelHistory');
            if (!box || !d || !d.ok) return;
            var h = '<table class="table table-sm table-bordered"><thead><tr><th>#</th><th>Kellner</th><th>Zeitraum</th><th>Umsatz</th><th>Trinkgeld</th><th>Status</th>';
            if (ffIsSuperAdmin) h += '<th>Aktion</th>';
            h += '</tr></thead><tbody>';
            (d.history || []).forEach(function(r) {
                var voided = r.voided_at && String(r.voided_at) !== '0000-00-00 00:00:00';
                var sid = parseInt(r.id, 10) || 0;
                var lbl = r.kellner_label || r.kellner_login || '';
                h += '<tr' + (voided ? ' class="table-secondary text-muted"' : '') + '>';
                h += '<td>' + sid + '</td>';
                h += '<td>' + escHtml(lbl) + '</td><td>' + escHtml(r.von_dt) + ' – ' + escHtml(r.bis_dt) + '</td>';
                h += '<td>' + euro(r.umsatz_soll) + '</td><td>' + euro(r.trinkgeld) + '</td>';
                h += '<td class="small">';
                if (voided) {
                    h += '<span class="badge bg-secondary">Aufgehoben</span><br>' + escHtml(String(r.voided_at).substring(0, 16));
                    if (r.void_reason) h += '<br>' + escHtml(r.void_reason);
                } else {
                    h += '<span class="badge bg-success">Abgerechnet</span>';
                }
                h += '</td>';
                if (ffIsSuperAdmin) {
                    h += '<td>';
                    if (!voided && sid > 0) {
                        h += '<button type="button" class="btn btn-sm btn-outline-danger" data-settlement-id="' + sid + '" data-kellner="' + escAttr(lbl) + '" onclick="ffKelUnsettle(parseInt(this.getAttribute(\'data-settlement-id\'),10), this.getAttribute(\'data-kellner\'));">Aufheben</button>';
                    } else {
                        h += '—';
                    }
                    h += '</td>';
                }
                h += '</tr>';
            });
            box.innerHTML = h + '</tbody></table>';
        });
    };

    window.ffKelLoadOpenSummary = function() {
        var box = document.getElementById('ffKelOpenSummary');
        if (!box) return;
        box.innerHTML = '<span class="text-muted">Lade …</span>';
        apiGet('finance_kellner_api.php?action=list_open_summary').then(function(d) {
            if (!d || !d.ok) {
                box.innerHTML = '<span class="text-danger">Liste konnte nicht geladen werden.</span>';
                return;
            }
            var rows = d.kellner || [];
            if (!rows.length) {
                box.innerHTML = '<span class="text-success">Keine offenen Kellner-Abrechnungen.</span>';
                return;
            }
            var h = '<div class="table-responsive"><table class="table table-sm table-bordered mb-0 bg-white">';
            h += '<thead><tr><th>Kellner</th><th>Positionen</th><th>Umsatz</th><th>Entnahmen</th><th>Zuzahlungen</th><th>Erwartete Abgabe</th><th></th></tr></thead><tbody>';
            rows.forEach(function(r) {
                var login = r.kellner_login || '';
                h += '<tr><td>' + escHtml(r.label || login) + '</td>';
                h += '<td>' + (r.positionen || 0) + '</td>';
                h += '<td>' + euro(r.umsatz_soll) + '</td>';
                h += '<td>−' + euro(r.entnahmen) + '</td>';
                h += '<td>+' + euro(r.zuzahlungen) + '</td>';
                h += '<td><strong>' + euro(r.umsatz_abgabe) + '</strong></td>';
                h += '<td><button type="button" class="btn btn-sm btn-outline-primary" data-kellner="' + escHtml(login) + '" onclick="ffKelShowOpenDetail(this.getAttribute(\'data-kellner\'));">Positionen</button></td></tr>';
            });
            box.innerHTML = h + '</tbody></table></div>';
        });
    };

    window.ffKelShowOpenDetail = function(login) {
        if (!login) return;
        var kelTab = document.getElementById('ffFinTabKellnerBtn');
        if (kelTab && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(kelTab).show();
        }
        var sel = document.getElementById('ffKelKellner');
        var vonEl = document.getElementById('ffKelVon');
        var bisEl = document.getElementById('ffKelBis');
        if (sel) sel.value = login;
        if (vonEl) vonEl.value = '';
        if (bisEl) bisEl.value = '';
        ffKelLoadMovements();
        ffKelPreview();
        var box = document.getElementById('ffKelPreviewBox');
        if (box && box.scrollIntoView) {
            box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    };

    function ffKelAccordionSection(sectionId, title, bodyHtml, open) {
        var showCls = open ? ' show' : '';
        var btnCls = open ? '' : ' collapsed';
        var expanded = open ? 'true' : 'false';
        return '<div class="accordion-item">'
            + '<h2 class="accordion-header">'
            + '<button class="accordion-button' + btnCls + '" type="button" data-bs-toggle="collapse" data-bs-target="#'
            + sectionId + '" aria-expanded="' + expanded + '">' + title + '</button>'
            + '</h2>'
            + '<div id="' + sectionId + '" class="accordion-collapse collapse' + showCls + '">'
            + '<div class="accordion-body p-2">' + (bodyHtml || '<span class="text-muted">Keine Zeilen.</span>') + '</div>'
            + '</div></div>';
    }

    function ffKelRenderLinesTable(lines, statusCol) {
        if (!lines || !lines.length) return '';
        var h = '<div class="table-responsive mt-2"><table class="table table-sm table-striped mb-0"><thead><tr>';
        h += '<th>Bezahlt</th><th>Tisch</th><th>Position</th><th class="text-end">Betrag</th>';
        if (statusCol) h += '<th>Abrechnung</th>';
        h += '</tr></thead><tbody>';
        lines.forEach(function(ln) {
            var bez = ln.bezahlt ? String(ln.bezahlt).replace('T', ' ').substring(0, 16) : '—';
            var tischLbl = ln.tisch_label || ln.tischname || (ln.tisch != null ? String(ln.tisch) : '—');
            h += '<tr><td>' + escHtml(bez) + '</td><td>' + escHtml(tischLbl) + '</td>';
            h += '<td>' + escHtml(ln.name || '') + '</td><td class="text-end">' + euro(ln.betrag) + '</td>';
            if (statusCol) {
                var st = '<span class="badge bg-warning text-dark">Offen</span>';
                if (ln.storniert) {
                    st = '<span class="badge bg-danger">Storno nach Abrechnung #' + escHtml(String(ln.settlement_id || '')) + '</span>';
                } else if (ln.settlement_id) {
                    st = '<span class="badge bg-secondary">Abgerechnet #' + escHtml(String(ln.settlement_id)) + '</span>';
                }
                h += '<td>' + st + '</td>';
            }
            h += '</tr>';
        });
        return h + '</tbody></table></div>';
    }

    function ffKelRenderBreakdown(bd) {
        if (!bd) return '';
        var parts = [];
        var settled = bd.settled || {};
        var otherFest = bd.open_other_fest || {};
        var otherKz = bd.paid_other_kellner || {};
        if ((settled.count || 0) > 0) {
            parts.push('<strong>Bereits abgerechnet:</strong> ' + (settled.count || 0) + ' Pos. · ' + euro(settled.sum));
        }
        if ((otherFest.count || 0) > 0) {
            parts.push('<strong>Andere Fest-ID (offen):</strong> ' + (otherFest.count || 0) + ' Pos. · ' + euro(otherFest.sum)
                + ' <span class="text-muted">(nicht aktuelles Fest – bei nur einem Fest selten)</span>');
        }
        if ((otherKz.count || 0) > 0) {
            parts.push('<strong>Bezahlt, andere Kasse:</strong> ' + (otherKz.count || 0) + ' Pos. · ' + euro(otherKz.sum) + ' (Aufnahme = Kellner, <code>kellnerZahlung</code> abweichend)');
        }
        var unbez = bd.excluded_unpaid || {};
        var gratis = bd.excluded_gratis || {};
        var schr = bd.excluded_schreibaus || {};
        if ((unbez.count || 0) > 0) {
            parts.push('<strong>Unbezahlt</strong> (nicht in Abrechnung): ' + (unbez.count || 0) + ' Pos.');
        }
        if ((gratis.count || 0) > 0) {
            parts.push('<strong>Ehrengast/Personal</strong> (<code>is_gratis</code>, 0 € Umsatz): ' + (gratis.count || 0) + ' Pos.');
        }
        if ((schr.count || 0) > 0) {
            parts.push('<strong>Schreibaus</strong>: ' + (schr.count || 0) + ' Pos.');
        }
        if (!parts.length) return '';
        return '<div class="border rounded p-2 mt-2 mb-2 bg-light small">' + parts.join('<br>') + '</div>';
    }

    window.ffKelLoadMovements = function() {
        var k = (document.getElementById('ffKelKellner') || {}).value || '';
        var box = document.getElementById('ffKelMovements');
        if (!box) return;
        if (!k) {
            box.innerHTML = '<span class="text-muted">Kellner wählen …</span>';
            return;
        }
        apiGet('finance_kellner_api.php?action=list_movements&kellner=' + encodeURIComponent(k)).then(function(d) {
            if (!d || !d.ok) {
                box.textContent = 'Fehler beim Laden';
                return;
            }
            var ent = 0;
            var zuz = 0;
            var h = '<ul class="list-unstyled mb-0">';
            (d.movements || []).forEach(function(m) {
                var lbl = m.typ === 'entnahme' ? 'Entnahme' : 'Zuzahlung';
                if (m.typ === 'entnahme') ent += parseFloat(m.betrag) || 0;
                else zuz += parseFloat(m.betrag) || 0;
                h += '<li>' + lbl + ' ' + euro(m.betrag) + (m.notiz ? ' — ' + m.notiz : '') + ' <span class="text-muted">(' + (m.created_at || '') + ')</span></li>';
            });
            h += '</ul>';
            if (!(d.movements || []).length) {
                h = '<span class="text-muted">Keine offenen Bewegungen.</span>';
            } else {
                h += '<p class="mb-0 mt-1"><strong>Summe Entnahmen:</strong> ' + euro(ent) + ' · <strong>Zuzahlungen:</strong> ' + euro(zuz) + '</p>';
            }
            box.innerHTML = h;
        });
    };

    window.ffKelMov = function(typ) {
        var k = (document.getElementById('ffKelKellner') || {}).value || '';
        var amt = (document.getElementById('ffKelMovAmt') || {}).value || '';
        var notiz = (document.getElementById('ffKelMovNotiz') || {}).value || '';
        if (!k) {
            alert('Bitte Kellner wählen.');
            return;
        }
        if (!amt) {
            alert('Bitte Betrag eingeben.');
            return;
        }
        apiPost('finance_kellner_api.php', {
            action: 'movement',
            kellner: k,
            typ: typ,
            betrag: amt,
            notiz: notiz
        }).then(function(d) {
            if (d && d.ok) {
                var amtEl = document.getElementById('ffKelMovAmt');
                var notEl = document.getElementById('ffKelMovNotiz');
                if (amtEl) amtEl.value = '';
                if (notEl) notEl.value = '';
                ffKelLoadMovements();
                if (document.getElementById('ffKelVon').value && document.getElementById('ffKelBis').value) {
                    ffKelPreview();
                }
            } else {
                alert('Fehler');
            }
        });
    };

    window.ffKelPreview = function() {
        var k = (document.getElementById('ffKelKellner') || {}).value || '';
        var von = (document.getElementById('ffKelVon') || {}).value || '';
        var bis = (document.getElementById('ffKelBis') || {}).value || '';
        var box = document.getElementById('ffKelPreviewBox');
        if (!k) {
            if (box) box.textContent = 'Bitte Kellner wählen.';
            return;
        }
        apiPost('finance_kellner_api.php', { action: 'preview', kellner: k, von: von, bis: bis }).then(function(d) {
            if (!box) return;
            if (!d || !d.ok) {
                var msg = 'Vorschau fehlgeschlagen.';
                if (d && d.error === 'missing_kellner') msg = 'Bitte Kellner wählen.';
                else if (d && d.error === 'invalid_range') msg = '„Von“ muss vor „Bis“ liegen.';
                box.textContent = msg;
                return;
            }
            var mov = d.movements || {};
            var ent = parseFloat(mov.entnahmen) || 0;
            var zuz = parseFloat(mov.zuzahlungen) || 0;
            var umsatz = parseFloat(d.umsatz_soll) || 0;
            var abgabe = parseFloat(d.umsatz_abgabe);
            if (isNaN(abgabe)) abgabe = umsatz - ent + zuz;
            var n = (d.lines || []).length;
            var zeit = '';
            if (d.zeitraum_von && d.zeitraum_bis) {
                zeit = 'Zeitraum der offenen Positionen: <strong>' + d.zeitraum_von + '</strong> bis <strong>' + d.zeitraum_bis + '</strong><br>';
            }
            if (d.alle_offenen) {
                zeit = '<span class="text-muted">Alle noch nicht abgerechneten Positionen dieses Kellners.</span><br>' + zeit;
            }
            if (n === 0) {
                box.innerHTML = zeit + '<span class="text-warning">Keine offenen bezahlten Positionen in diesem Zeitraum.</span>';
                return;
            }
            var settledLines = d.settled_lines || [];
            var kelHead = '';
            if (d.kellner_label) {
                kelHead = '<p class="mb-1"><strong>Kellner:</strong> ' + escHtml(d.kellner_label) + '</p>';
            }
            var accBase = 'ffKelAcc_' + String(Date.now());
            var summaryHtml = kelHead + zeit
                + '<div class="border rounded p-2 mb-2 bg-white small">'
                + '<strong>Umsatz (offen): ' + euro(umsatz) + '</strong> · ' + n + ' Position(en)<br>'
                + 'Entnahmen: −' + euro(ent) + ' · Zuzahlungen: +' + euro(zuz) + '<br>'
                + '<strong>Erwartete Abgabe: ' + euro(abgabe) + '</strong>'
                + ' <span class="text-muted">(Umsatz − Entnahmen + Zuzahlungen)</span><br>'
                + '<span class="text-muted">Trinkgeld: Abgegeben − Wechselgeld − erwartete Abgabe</span>'
                + '</div>'
                + ffKelRenderBreakdown(d.breakdown);
            var accHtml = '<div class="accordion" id="' + accBase + '">'
                + ffKelAccordionSection(
                    accBase + '_open',
                    'Offene Positionen (' + n + ') · ' + euro(umsatz),
                    ffKelRenderLinesTable(d.lines || [], true),
                    false
                );
            if (settledLines.length) {
                var settledSum = 0;
                settledLines.forEach(function(ln) { settledSum += parseFloat(ln.betrag) || 0; });
                accHtml += ffKelAccordionSection(
                    accBase + '_settled',
                    'Bereits abgerechnet (' + settledLines.length + ') · ' + euro(settledSum),
                    ffKelRenderLinesTable(settledLines, true),
                    false
                );
            }
            var stornoLines = d.storno_nach_abrechnung_lines || [];
            if (stornoLines.length) {
                accHtml += ffKelAccordionSection(
                    accBase + '_storno',
                    'Storno nach Abrechnung (' + stornoLines.length + ')',
                    ffKelRenderLinesTable(stornoLines, true),
                    false
                );
            }
            accHtml += '</div>';
            box.innerHTML = summaryHtml + accHtml
                + '<p class="text-muted small mt-2 mb-0">Tipp: Bereiche bei Bedarf aufklappen — für die Abrechnung reicht die Summe oben.</p>';
        });
    };

    window.ffKelSettle = function() {
        apiPost('finance_kellner_api.php', {
            action: 'settle',
            kellner: document.getElementById('ffKelKellner').value,
            von: document.getElementById('ffKelVon').value,
            bis: document.getElementById('ffKelBis').value,
            betrag_abgegeben: document.getElementById('ffKelAbgegeben').value,
            wechselgeld_zurueck: document.getElementById('ffKelWechsel').value
        }).then(function(d) {
            if (d && d.ok) {
                alert('Abgerechnet.\nErwartete Abgabe: ' + euro(d.umsatz_abgabe) + '\nTrinkgeld: ' + euro(d.trinkgeld));
                var abgEl = document.getElementById('ffKelAbgegeben');
                var wechEl = document.getElementById('ffKelWechsel');
                if (abgEl) abgEl.value = '';
                if (wechEl) wechEl.value = '';
                ffKelLoadKellner();
                ffKelLoadMovements();
                ffKelLoadOpenSummary();
                var prev = document.getElementById('ffKelPreviewBox');
                if (prev) prev.innerHTML = '';
            } else {
                var err = (d && d.error) ? d.error : '';
                if (err === 'no_open_orders') alert('Keine offenen Positionen zum Abrechnen.');
                else if (err === 'missing_kellner') alert('Bitte Kellner wählen.');
                else if (err === 'invalid_range') alert('„Von“ muss vor „Bis“ liegen.');
                else alert('Fehler');
            }
        });
    };

    var FF_KEL_SCOPE_DV = 'dv';

    window.ffDvLoadKellner = function() {
        var sel = document.getElementById('ffDvKellner');
        if (!sel) return;
        apiGet('finance_kellner_api.php?action=list_kellner&scope=dv').then(function(d) {
            if (!d || !d.ok) {
                ffFinanceFillKellnerSelect(sel, [], '— Laden fehlgeschlagen —');
                return;
            }
            ffFinanceFillKellnerSelect(sel, d.kellner || [], '— Keine Benutzer —');
        }).catch(function() {
            ffFinanceFillKellnerSelect(sel, [], '— Laden fehlgeschlagen —');
        });
        apiGet('finance_kellner_api.php?action=history&scope=dv').then(function(d) {
            var box = document.getElementById('ffDvHistory');
            if (!box || !d || !d.ok) return;
            var h = '<table class="table table-sm table-bordered"><thead><tr><th>#</th><th>Mitarbeiter</th><th>Zeitraum</th><th>Umsatz</th><th>Trinkgeld</th><th>Status</th>';
            if (ffIsSuperAdmin) h += '<th>Aktion</th>';
            h += '</tr></thead><tbody>';
            (d.history || []).forEach(function(r) {
                var voided = r.voided_at && String(r.voided_at) !== '0000-00-00 00:00:00';
                var sid = parseInt(r.id, 10) || 0;
                var lbl = r.kellner_label || r.kellner_login || '';
                h += '<tr' + (voided ? ' class="table-secondary text-muted"' : '') + '>';
                h += '<td>' + sid + '</td><td>' + escHtml(lbl) + '</td><td>' + escHtml(r.von_dt) + ' – ' + escHtml(r.bis_dt) + '</td>';
                h += '<td>' + euro(r.umsatz_soll) + '</td><td>' + euro(r.trinkgeld) + '</td><td class="small">';
                h += voided ? '<span class="badge bg-secondary">Aufgehoben</span>' : '<span class="badge bg-success">Abgerechnet</span>';
                h += '</td>';
                if (ffIsSuperAdmin) {
                    h += '<td>';
                    if (!voided && sid > 0) {
                        h += '<button type="button" class="btn btn-sm btn-outline-danger" onclick="ffKelUnsettle(' + sid + ', \'' + escAttr(lbl) + '\');">Aufheben</button>';
                    } else h += '—';
                    h += '</td>';
                }
                h += '</tr>';
            });
            box.innerHTML = h + '</tbody></table>';
        });
    };

    function ffDvEnsureKellnerOption(login, label) {
        var sel = document.getElementById('ffDvKellner');
        if (!sel || !login) return;
        var found = false;
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === login) {
                found = true;
                break;
            }
        }
        if (!found) {
            var o = document.createElement('option');
            o.value = login;
            o.textContent = label || login;
            sel.appendChild(o);
        }
        sel.value = login;
    }

    window.ffDvLoadOpenSummary = function() {
        var box = document.getElementById('ffDvOpenSummary');
        if (!box) return;
        box.innerHTML = '<span class="text-muted">Lade …</span>';
        apiGet('finance_kellner_api.php?action=list_open_summary&scope=dv').then(function(d) {
            if (!d || !d.ok) {
                box.innerHTML = '<span class="text-danger">Liste konnte nicht geladen werden.</span>';
                return;
            }
            var rows = d.kellner || [];
            if (!rows.length) {
                box.innerHTML = '<span class="text-success">Keine offenen DV-Schichten.</span>';
                return;
            }
            var h = '<div class="table-responsive"><table class="table table-sm table-bordered mb-0 bg-white"><thead><tr>';
            h += '<th>Mitarbeiter</th><th>Positionen</th><th>Umsatz</th><th>Entnahmen</th><th>Zuzahlungen</th><th>Erwartete Abgabe</th><th></th></tr></thead><tbody>';
            rows.forEach(function(r) {
                var login = r.kellner_login || '';
                h += '<tr><td>' + escHtml(r.label || login) + '</td><td>' + (r.positionen || 0) + '</td>';
                h += '<td>' + euro(r.umsatz_soll) + '</td><td>−' + euro(r.entnahmen) + '</td><td>+' + euro(r.zuzahlungen) + '</td>';
                h += '<td><strong>' + euro(r.umsatz_abgabe) + '</strong></td>';
                h += '<td><button type="button" class="btn btn-sm btn-outline-primary" data-kellner="' + escAttr(login) + '" data-label="' + escAttr(r.label || login) + '" onclick="ffDvPickKellner(this.getAttribute(\'data-kellner\'), this.getAttribute(\'data-label\'));">Details</button></td></tr>';
            });
            box.innerHTML = h + '</tbody></table></div>';
        });
    };

    window.ffDvPickKellner = function(login, label) {
        if (!login) return;
        var tab = document.getElementById('ffFinTabDvBtn');
        if (tab && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(tab).show();
        }
        ffDvEnsureKellnerOption(login, label || login);
        ffDvLoadMovements();
        ffDvPreview();
    };

    window.ffDvLoadMovements = function() {
        var k = (document.getElementById('ffDvKellner') || {}).value || '';
        var box = document.getElementById('ffDvMovements');
        if (!box) return;
        if (!k) { box.innerHTML = '<span class="text-muted">Mitarbeiter wählen …</span>'; return; }
        apiGet('finance_kellner_api.php?action=list_movements&kellner=' + encodeURIComponent(k)).then(function(d) {
            if (!d || !d.ok) { box.textContent = 'Fehler'; return; }
            var h = '<ul class="list-unstyled mb-0">';
            (d.movements || []).forEach(function(m) {
                h += '<li>' + (m.typ === 'entnahme' ? 'Entnahme' : 'Zuzahlung') + ' ' + euro(m.betrag) + '</li>';
            });
            box.innerHTML = (d.movements || []).length ? h + '</ul>' : '<span class="text-muted">Keine offenen Bewegungen.</span>';
        });
    };

    window.ffDvMov = function(typ) {
        var k = (document.getElementById('ffDvKellner') || {}).value || '';
        apiPost('finance_kellner_api.php', { action: 'movement', scope: FF_KEL_SCOPE_DV, kellner: k, typ: typ,
            betrag: (document.getElementById('ffDvMovAmt') || {}).value, notiz: (document.getElementById('ffDvMovNotiz') || {}).value
        }).then(function(d) { if (d && d.ok) ffDvLoadMovements(); else alert('Fehler'); });
    };

    window.ffDvPreview = function() {
        var k = (document.getElementById('ffDvKellner') || {}).value || '';
        var box = document.getElementById('ffDvPreviewBox');
        if (!k) { if (box) box.textContent = 'Bitte Mitarbeiter wählen.'; return; }
        apiPost('finance_kellner_api.php', { action: 'preview', scope: FF_KEL_SCOPE_DV, kellner: k,
            von: (document.getElementById('ffDvVon') || {}).value, bis: (document.getElementById('ffDvBis') || {}).value
        }).then(function(d) {
            if (!box) return;
            if (!d || !d.ok) { box.textContent = 'Vorschau fehlgeschlagen.'; return; }
            var n = (d.lines || []).length;
            if (n === 0) { box.innerHTML = '<span class="text-warning">Keine offenen DV-Positionen.</span>'; return; }
            var settledDv = d.settled_lines || [];
            var stornoDv = d.storno_nach_abrechnung_lines || [];
            var accDv = 'ffDvAcc_' + String(Date.now());
            var dvSummary = '<div class="border rounded p-2 mb-2 bg-white small">'
                + '<strong>Umsatz DV (offen): ' + euro(d.umsatz_soll) + '</strong> · ' + n + ' Pos.<br>'
                + '<strong>Abgabe: ' + euro(d.umsatz_abgabe) + '</strong></div>'
                + ffKelRenderBreakdown(d.breakdown);
            var accDvHtml = '<div class="accordion" id="' + accDv + '">'
                + ffKelAccordionSection(accDv + '_open', 'Offene DV-Positionen (' + n + ')', ffKelRenderLinesTable(d.lines || [], true), false);
            if (settledDv.length) {
                accDvHtml += ffKelAccordionSection(accDv + '_settled', 'Bereits abgerechnet (' + settledDv.length + ')', ffKelRenderLinesTable(settledDv, true), false);
            }
            if (stornoDv.length) {
                accDvHtml += ffKelAccordionSection(accDv + '_storno', 'Storno nach Abrechnung (' + stornoDv.length + ')', ffKelRenderLinesTable(stornoDv, true), false);
            }
            accDvHtml += '</div>';
            box.innerHTML = dvSummary + accDvHtml;
        });
    };

    window.ffDvSettle = function() {
        apiPost('finance_kellner_api.php', {
            action: 'settle', scope: FF_KEL_SCOPE_DV,
            kellner: (document.getElementById('ffDvKellner') || {}).value,
            von: (document.getElementById('ffDvVon') || {}).value,
            bis: (document.getElementById('ffDvBis') || {}).value,
            betrag_abgegeben: (document.getElementById('ffDvAbgegeben') || {}).value,
            wechselgeld_zurueck: (document.getElementById('ffDvWechsel') || {}).value
        }).then(function(d) {
            if (d && d.ok) {
                alert('DV-Schicht abgerechnet.\nAbgabe: ' + euro(d.umsatz_abgabe) + '\nTrinkgeld: ' + euro(d.trinkgeld));
                ffDvLoadKellner();
                ffDvLoadOpenSummary();
                ffDvLoadMovements();
                var prev = document.getElementById('ffDvPreviewBox');
                if (prev) prev.innerHTML = '';
            } else {
                alert((d && d.error === 'no_open_orders') ? 'Keine offenen DV-Positionen.' : 'Fehler');
            }
        });
    };

    function ffBrPopulateBereichSelect(bereiche, prevValue) {
        var sel = document.getElementById('ffBrBereich');
        if (!sel) return;
        var prev = prevValue != null ? String(prevValue) : String(sel.value || '-1');
        sel.innerHTML = '<option value="-1">Alle Bereiche</option>';
        (bereiche || []).forEach(function(b) {
            var id = b && b.id != null ? b.id : (b && b['id']);
            var name = (b && (b.name || b.Name)) ? (b.name || b.Name) : ('Bereich ' + id);
            if (id == null || id === '') return;
            var o = document.createElement('option');
            o.value = String(id);
            o.textContent = name;
            sel.appendChild(o);
        });
        var oKd = document.createElement('option');
        oKd.value = '-2';
        oKd.textContent = 'Kellner / Direktverkauf';
        sel.appendChild(oKd);
        var o0 = document.createElement('option');
        o0.value = '0';
        o0.textContent = 'Unzugeordnet (sonstig)';
        sel.appendChild(o0);
        var hasPrev = false;
        for (var j = 0; j < sel.options.length; j++) {
            if (sel.options[j].value === prev) {
                hasPrev = true;
                break;
            }
        }
        sel.value = hasPrev ? prev : '-1';
    }

    window.ffBrLoadBereichFilter = function() {
        return apiGet('finance_bereich_api.php?action=list_bereiche').then(function(d) {
            if (!d || !d.ok) return;
            var sel = document.getElementById('ffBrBereich');
            ffBrPopulateBereichSelect(d.bereiche || [], sel ? sel.value : '-1');
        });
    };

    window.ffBrReloadBereiche = function() {
        ffBrLoadBereichFilter();
        apiGet('finance_bereich_api.php?action=list_print_targets').then(function(d) {
            var box = document.getElementById('ffBrPrintMap');
            if (!box) return;
            if (!d || !d.ok) {
                box.innerHTML = '<span class="text-danger">Druckziele konnten nicht geladen werden.</span>';
                return;
            }
            var bereiche = d.bereiche || [];
            var fixed = d.print_targets_kellner_direkt_fixed || [];
            var html = '';
            if (fixed.length) {
                html += '<p class="small text-muted mb-2">Fest <strong>Kellner / Direktverkauf</strong> (nicht einem Finanzbereich zuordenbar): '
                    + fixed.map(function(pt) {
                        return escHtml(pt.name || ('Druckziel ' + pt.print_target));
                    }).join(', ') + '</p>';
            }
            html += '<table class="table table-sm mb-0"><tr><th>Druckziel</th><th>Finanzbereich</th></tr>';
            (d.print_targets || []).forEach(function(pt) {
                if (pt.is_kellner_direkt_fixed) return;
                html += '<tr><td>' + escHtml(pt.name || ('Druckziel ' + pt.print_target)) + '</td>';
                html += '<td><select class="form-select form-select-sm" onchange="ffBrSavePrintMap(' + pt.print_target + ', this.value)">';
                html += '<option value="0">— nicht zugeordnet —</option>';
                bereiche.forEach(function(b) {
                    var bid = b && b.id != null ? b.id : b['id'];
                    var sel = parseInt(pt.finance_bereich_id, 10) === parseInt(bid, 10) ? ' selected' : '';
                    html += '<option value="' + bid + '"' + sel + '>' + escHtml(b.name || ('Bereich ' + bid)) + '</option>';
                });
                html += '</select></td></tr>';
            });
            if (!(d.print_targets || []).length && !fixed.length) {
                html += '<tr><td colspan="2" class="text-muted">Keine Druckziele angelegt.</td></tr>';
            } else if (!(d.print_targets || []).length) {
                html += '<tr><td colspan="2" class="text-muted">Alle Druckziele sind fest Kellner/Direktverkauf — keine weitere Zuordnung nötig.</td></tr>';
            }
            box.innerHTML = html;
        });
    };

    window.ffBrInit = function() {
        window.ffBrReloadBereiche();
    };

    window.ffBrSavePrintMap = function(printTarget, bereichId) {
        apiPost('finance_bereich_api.php', {
            action: 'save_print_mapping',
            print_target: String(printTarget),
            finance_bereich_id: String(bereichId || '0')
        }).then(function(r) {
            if (r && r.ok) return;
            if (r && r.error === 'print_target_fixed') {
                alert(r.message || 'Dieses Druckziel ist fest Kellner/Direktverkauf.');
                ffBrReloadBereiche();
                return;
            }
            alert('Speichern fehlgeschlagen');
        });
    };

    function ffBrMetricsRow(label, m, showVerkauf) {
        var verkaufCell = showVerkauf !== false
            ? euro(m.verkauf_umsatz)
            : '<span class="text-muted" title="Verkauf nur bei Kellner/Direktverkauf">—</span>';
        return '<tr><td><strong>' + escHtml(label) + '</strong></td>' +
            '<td>' + euro(m.kassen_umsatz) + '</td>' +
            '<td>' + verkaufCell + '</td>' +
            '<td>' + euro(m.umsatz_gesamt) + '</td>' +
            '<td>' + euro(m.variable_kosten) + '</td>' +
            '<td>' + euro(m.fixe_einnahmen) + '</td>' +
            '<td>' + euro(m.fixe_ausgaben) + '</td>' +
            '<td><strong>' + euro(m.gewinn) + '</strong></td></tr>';
    }

    function ffBrRenderKassenSessions(sessions) {
        if (!sessions || !sessions.length) return '';
        var h = '<h6 class="mt-3 mb-2">Kassenabschlüsse (Detail)</h6>';
        h += '<p class="small text-muted mb-2">Umsatz = Tageslosung − Wechselgeld Start + Entnahmen − Zuzahlungen</p>';
        h += '<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr>';
        h += '<th>Abschluss</th><th>Wechselgeld Start</th><th>Entnahmen</th><th>Zuzahlungen</th><th>Tageslosung</th><th>Umsatz</th></tr></thead><tbody>';
        sessions.forEach(function(s) {
            var closed = s.closed_at ? String(s.closed_at).replace('T', ' ').substring(0, 16) : '—';
            h += '<tr><td>' + escHtml(closed) + '</td>';
            h += '<td>' + euro(s.opening_amount) + '</td>';
            h += '<td>' + (s.entnahmen_count || 0) + '× ' + euro(s.entnahmen_sum) + '</td>';
            h += '<td>' + (s.zuzahlungen_count || 0) + '× ' + euro(s.zuzahlungen_sum) + '</td>';
            h += '<td>' + euro(s.closing_amount) + '</td>';
            h += '<td><strong>' + euro(s.revenue_amount) + '</strong></td></tr>';
            if (s.movements && s.movements.length) {
                h += '<tr><td colspan="6" class="small bg-light"><ul class="mb-0">';
                s.movements.forEach(function(m) {
                    var lbl = m.typ === 'entnahme' ? 'Entnahme' : 'Zuzählung';
                    h += '<li>' + lbl + ' ' + euro(m.betrag) + (m.notiz ? ' — ' + escHtml(m.notiz) : '') + '</li>';
                });
                h += '</ul></td></tr>';
            }
        });
        return h + '</tbody></table></div>';
    }

    window.ffBereichEvaluate = function() {
        var von = (document.getElementById('ffBrVon') || {}).value || '';
        var bis = (document.getElementById('ffBrBis') || {}).value || '';
        var bid = (document.getElementById('ffBrBereich') || {}).value;
        if (bid === undefined || bid === null || bid === '') bid = '-1';
        var q = 'finance_bereich_api.php?action=evaluate&bereich_id=' + encodeURIComponent(String(bid));
        if (von) q += '&von=' + encodeURIComponent(von);
        if (bis) q += '&bis=' + encodeURIComponent(bis);
        apiGet(q).then(function(d) {
            var box = document.getElementById('ffBrResult');
            if (!box) return;
            if (!d || !d.ok) {
                box.innerHTML = '<p class="text-danger">Auswertung fehlgeschlagen.</p>';
                return;
            }
            var zeit = '';
            if (d.von || d.bis) zeit = '<p class="mb-2">Zeitraum: <strong>' + (d.von || '…') + '</strong> bis <strong>' + (d.bis || '…') + '</strong></p>';
            var head = '<table class="table table-sm table-bordered"><thead><tr><th>Bereich</th><th>Kasse</th><th>Verkauf</th><th>Umsatz</th><th>Var. Kosten</th><th>Fix Einn.</th><th>Fix Ausg.</th><th>Gewinn</th></tr></thead><tbody>';
            var body = '';
            var kassenHtml = '';
            if (d.metrics) {
                var bidNum = parseInt(bid, 10);
                var showVk = bidNum === -2 || bidNum === 0;
                body += ffBrMetricsRow(d.name || 'Bereich', d.metrics, showVk);
                kassenHtml = ffBrRenderKassenSessions(d.kassen_sessions);
                if (bidNum === -2 && d.kellner_direkt_breakdown) {
                    var bd = d.kellner_direkt_breakdown;
                    zeit += '<p class="small mb-2"><strong>Aufschlüsselung Verkauf:</strong> Kellner (Kasse) '
                        + euro(bd.kellner) + ' · Direktverkauf (Tisch 999999) ' + euro(bd.direktverkauf) + '</p>';
                }
            } else {
                (d.bereiche || []).forEach(function(r) {
                    body += ffBrMetricsRow(r.name, r, false);
                });
                if (d.kellner_direktverkauf) {
                    body += ffBrMetricsRow('Kellner / Direktverkauf', d.kellner_direktverkauf, true);
                }
                if (d.unzugeordnet && Math.abs(parseFloat(d.unzugeordnet.umsatz_gesamt || d.unzugeordnet.verkauf_umsatz) || 0) > 0.004) {
                    body += ffBrMetricsRow('Unzugeordnet (sonstig)', d.unzugeordnet, true);
                }
                if (d.gesamt) {
                    body += ffBrMetricsRow('Gesamt (Fest)', d.gesamt, true);
                }
            }
            box.innerHTML = zeit + head + body + '</tbody></table>' + kassenHtml;
        });
    };

    window.ffFinanceOverview = function() {
        var vonEl = document.getElementById('ffOvVon');
        var bisEl = document.getElementById('ffOvBis');
        var von = vonEl ? vonEl.value : '';
        var bis = bisEl ? bisEl.value : '';
        var q = 'finance_overview_api.php';
        var parts = [];
        if (von) parts.push('von=' + encodeURIComponent(von));
        if (bis) parts.push('bis=' + encodeURIComponent(bis));
        if (parts.length) q += '?' + parts.join('&');
        var box = document.getElementById('ffOvBox');
        if (box) box.innerHTML = '<span class="text-muted">Lade …</span>';
        apiGet(q).then(function(d) {
            if (!box) return;
            if (!d || !d.ok) {
                box.innerHTML = '<p class="text-danger">Auswertung fehlgeschlagen.</p>';
                return;
            }
            var zeit = '';
            if (d.von || d.bis) {
                zeit = '<p class="small text-muted mb-2">Zeitraum: <strong>' + escHtml(d.von || '…') + '</strong> bis <strong>' + escHtml(d.bis || '…') + '</strong></p>';
            }
            var ka = d.kellner_abrechnung || {};
            var kelZeilenAbg = parseFloat(ka.abgerechnet_zeilen_gueltig != null ? ka.abgerechnet_zeilen_gueltig : ka.abgerechnet_alle) || 0;
            var kelUmsatz = kelZeilenAbg || parseFloat(d.kellner_abgerechnet_umsatz) || 0;
            var kelOffen = parseFloat(ka.offen_alle != null ? ka.offen_alle : d.kellner_nicht_abgerechnet) || 0;
            var kelAbKachel = parseFloat(ka.abgerechnet_kachel) || 0;
            var kelOffKachel = parseFloat(ka.offen_kachel) || 0;
            var kelInBereich = parseFloat(ka.zugeordnet_finanzbereich) || 0;
            var kelProtokoll = parseFloat(ka.protokoll_soll_abgleich != null ? ka.protokoll_soll_abgleich : ka.abgerechnet_protokoll) || 0;
            var kelProtokollStorno = parseFloat(ka.protokoll_storno_nach_abrechnung) || 0;
            var kelProtDiff = parseFloat(ka.protokoll_zeilen_diff) || 0;
            var kelStornoGes = parseFloat(ka.storno_geloescht_summe) || 0;
            var kelStornoNachAbg = parseFloat(ka.storno_nach_abrechnung_summe) || 0;
            var kelStornoOffen = parseFloat(ka.storno_offen_summe) || 0;
            var kelStornoCnt = parseInt(ka.storno_geloescht_anzahl, 10) || 0;
            var kelTrink = parseFloat(d.kellner_abgerechnet_trinkgeld) || 0;
            var kelKachelSum = kelAbKachel + kelOffKachel;
            var kelGueltigSum = kelUmsatz + kelOffen;
            var g = parseFloat(d.gewinn) || 0;
            var brTbl = '';
            if (d.bereiche_umsatz && d.bereiche_umsatz.length) {
                brTbl = '<div class="mt-4 pt-3 border-top"><h6 class="text-muted mb-2">Umsatz nach Bereich</h6>' +
                    '<div class="table-responsive ff-ov-bereiche-scroll"><table class="table table-sm table-bordered bg-white mb-0 small">' +
                    '<thead><tr><th>Bereich</th><th>Kasse</th><th>Summe</th></tr></thead><tbody>';
                d.bereiche_umsatz.forEach(function(b) {
                    var koBr = parseInt(b.kontrolle_only, 10) === 1;
                    var nm = escHtml(b.name) + (koBr ? ' <span class="badge bg-secondary">nur Kassenkontrolle</span>' : '');
                    brTbl += '<tr><td>' + nm + '</td><td>' + euro(b.kassen_umsatz) + '</td><td><strong>' + euro(b.umsatz_gesamt) + '</strong></td></tr>';
                });
                brTbl += '</tbody></table></div>';
                brTbl += '<p class="small text-muted mb-0 mt-1">Bereiche „nur Kassenkontrolle“ erscheinen hier, fließen aber nicht in „Umsatz alle Bereiche“ / Gesamtumsatz ein.</p></div>';
            }
            var kelD = parseFloat(d.verkauf_kellner_direkt);
            if (isNaN(kelD)) kelD = parseFloat(d.verkauf_unzugeordnet != null ? d.verkauf_unzugeordnet : d.verkauf_umsatz) || 0;
            var echU = parseFloat(d.verkauf_echt_unzugeordnet) || 0;
            var kelAnt = parseFloat(d.verkauf_kellner_anteil) || 0;
            var dirAnt = parseFloat(d.verkauf_direktverkauf_anteil) || 0;
            var kpiRow = '<div class="row g-3 mb-3">' +
                '<div class="col-md-4"><div class="border rounded-3 p-3 bg-light h-100">' +
                '<div class="d-flex align-items-center gap-1 flex-wrap mb-1"><span class="text-muted small">Kellner / Direktverkauf</span>' +
                '<button type="button" class="btn ff-admin-info-btn" data-bs-toggle="collapse" data-bs-target="#ffOvKelDirektHint" aria-expanded="false" title="Aufschlüsselung Kellner / Direktverkauf" aria-label="Aufschlüsselung Kellner / Direktverkauf"><span aria-hidden="true">i</span></button></div>' +
                '<div class="collapse ff-admin-info-panel small text-muted" id="ffOvKelDirektHint"><div class="ff-admin-info-panel-inner">' +
                '<p class="mb-1"><strong>Aufschlüsselung</strong> (unzugeordneter Verkauf):</p><ul class="mb-0 ps-3">' +
                '<li><strong>Kellner</strong> (Kasse): <strong id="ffOvKelDirektBreakKellner">' + euro(kelAnt) + '</strong></li>' +
                '<li><strong>Direktverkauf</strong> (Tisch 999999): <strong id="ffOvKelDirektBreakDirekt">' + euro(dirAnt) + '</strong></li></ul></div></div>' +
                '<div class="fs-4 fw-bold text-primary">' + euro(kelD) + '</div></div></div>' +
                '<div class="col-md-4"><div class="border rounded-3 p-3 bg-light h-100"><div class="text-muted small">Umsatz alle Bereiche</div>' +
                '<div class="fs-4 fw-bold">' + euro(d.umsatz_bereiche_summe) + '</div></div></div>' +
                '<div class="col-md-4"><div class="border rounded-3 p-3 bg-success-subtle h-100 border-success"><div class="text-muted small">Gesamtumsatz</div>' +
                '<div class="fs-4 fw-bold text-success">' + euro(d.umsatz_gesamt_kombiniert) + '</div></div></div>';
            if (Math.abs(echU) > 0.004) {
                kpiRow += '<div class="col-md-4"><div class="border rounded-3 p-3 bg-warning-subtle h-100 border-warning"><div class="text-muted small">Unzugeordnet (sonstig)</div>' +
                    '<div class="fs-4 fw-bold">' + euro(echU) + '</div></div></div>';
            }
            kpiRow += '</div>';
            var gewinnCardCls = g >= 0 ? 'bg-success-subtle border-success' : 'bg-danger-subtle border-danger';
            var kelAbOffHint = '<p class="mb-1"><strong>Nur gültige Zeilen</strong> (bezahlt, nicht storniert, kein Ehrengast/Schreibaus):</p>'
                + '<ul class="mb-0 ps-3"><li><strong>Abgerechnet</strong> = am Kellner-Abrechnungsblatt hängend.</li>'
                + '<li><strong>Offen</strong> = bezahlt, aber noch kein Abrechnungsblatt.</li>'
                + '<li><strong>Summe</strong> = Kellner-Kasse, alle Druckziele (ohne Direktverkauf Tisch 999999).</li></ul>'
                + '<p class="mb-0 mt-1">Stornos sind <em>nicht</em> in Abgerechnet/Offen enthalten (eigener Abschnitt unten).</p>';
            var kelProtHint = '<p class="mb-1"><strong>Protokoll-Soll</strong> = Summe auf den Abrechnungsblättern beim Abrechnen.</p>'
                + '<p class="mb-1"><strong>Abgerechnete Zeilen (gültig)</strong> = wie „Abgerechnet“ oben.</p>'
                + '<p class="mb-0"><strong>Storno nach Abrechnung (Protokoll)</strong> = Differenz pro Blatt (Soll minus heute noch gültige Zeilen). '
                + 'Enthält auch historische/technische Abweichungen, nicht nur sichtbare Storno-Positionen.</p>';
            var stornoZeilen = ka.storno_nach_abrechnung_zeilen || [];
            var accKel = 'ffOvKelAcc';
            var stornoAccBody = '<p class="mb-2 text-muted small">Stornierte Kellner-Kassenzeilen im Zeitraum — zählen <strong>nicht</strong> zu Abgerechnet/Offen oben.</p>'
                + '<div class="row g-2 mb-2"><div class="col-md-4"><span class="text-muted small">Gesamt</span><div class="fs-5 fw-bold">' + euro(kelStornoGes) + '</div></div>'
                + '<div class="col-md-4"><span class="text-muted small">Nach Abrechnung</span><div class="fs-5 fw-bold">' + euro(kelStornoNachAbg) + '</div></div>'
                + '<div class="col-md-4"><span class="text-muted small">Vor Abrechnung</span><div class="fs-5 fw-bold">' + euro(kelStornoOffen) + '</div></div></div>'
                + '<p class="small text-muted mb-0">(' + kelStornoCnt + ' Positionen)</p>';
            var kachelAccBody = '<p class="small text-muted mb-2">Nur Druckziele <em>ohne</em> Finanzbereich — entspricht der Kachel „Kellner (Kasse)“ oben.</p>'
                + '<table class="table table-sm table-borderless mb-0 small"><tbody>'
                + '<tr><td class="text-muted">Abgerechnet (Kachel)</td><td class="text-end fw-semibold">' + euro(kelAbKachel) + '</td></tr>'
                + '<tr><td class="text-muted">Offen (Kachel)</td><td class="text-end fw-semibold">' + euro(kelOffKachel) + '</td></tr>'
                + '<tr class="border-top"><td><strong>Summe Kachel</strong></td><td class="text-end fw-bold">' + euro(kelKachelSum) + '</td></tr>'
                + '</tbody></table>';
            var kelDvAccBody = '<p class="small text-muted mb-2">Vergleich zur KPI-Kachel „Kellner / Direktverkauf“ (inkl. Direktverkauf Tisch 999999).</p>'
                + '<table class="table table-sm table-borderless mb-0 small"><tbody>'
                + '<tr><td class="text-muted">Kellner-Kasse (Kachel)</td><td class="text-end">' + euro(kelKachelSum) + '</td></tr>'
                + '<tr><td class="text-muted">+ Direktverkauf</td><td class="text-end">' + euro(dirAnt) + '</td></tr>'
                + '<tr class="border-top"><td><strong>= Kachel gesamt</strong></td><td class="text-end fw-bold">' + euro(kelD) + '</td></tr>'
                + '</tbody></table>'
                + '<p class="small text-muted mb-0 mt-2">Die Summe „Kellner-Kasse“ oben (alle Druckziele) kann höher sein als die Kachel, wenn Umsatz über einen <strong>Finanzbereich</strong> am Druckziel läuft.</p>';
            var finanzAccBody = '<p class="small text-muted mb-2">Anteil der Kellner-Kassen-Zeilen, der einem Finanzbereich am Druckziel zugeordnet ist.</p>'
                + '<p class="mb-0 fs-5 fw-bold">' + euro(kelInBereich) + '</p>'
                + '<p class="small text-muted mb-0">Zählt in „Umsatz alle Bereiche“, nicht in der Kellner-Kachel.</p>';
            var protAccBody = '<div class="fw-semibold mb-2">' + euro(kelZeilenAbg) + ' + ' + euro(kelProtokollStorno) + ' = <strong class="fs-5">' + euro(kelProtokoll) + '</strong> <span class="small text-muted">Protokoll-Soll</span></div>'
                + '<p class="small text-muted mb-2">Zeilen gültig · Storno nach Abrechnung (Differenz) · Summe der Abrechnungsblätter</p>'
                + '<div class="small text-muted border-top pt-2 mt-2">' + kelProtHint + '</div>'
                + (Math.abs(kelProtDiff) > 0.02 ? '<p class="small text-danger mb-0">Rundungsabweichung ' + euro(kelProtDiff) + '</p>' : '');
            var stornoTblAccBody = '<p class="small text-muted mb-2">Einzelpositionen mit Storno nach Abrechnung (Blatt behält ursprüngliches Soll).</p>';
            if (stornoZeilen.length) {
                stornoTblAccBody += '<div class="table-responsive"><table class="table table-sm table-bordered mb-0 bg-white"><thead><tr>'
                    + '<th>Abrechnung</th><th>Kellner</th><th>Position</th><th class="text-end">Betrag</th><th>Blatt</th></tr></thead><tbody>';
                stornoZeilen.forEach(function(z) {
                    var abDt = z.abrechnung_am ? String(z.abrechnung_am).replace('T', ' ').substring(0, 16) : '—';
                    stornoTblAccBody += '<tr><td>' + escHtml(abDt) + '</td><td>' + escHtml(z.kellner_label || z.kellner_login || '') + '</td>';
                    stornoTblAccBody += '<td>' + escHtml(z.name || '') + '</td><td class="text-end">' + euro(z.betrag) + '</td>';
                    stornoTblAccBody += '<td>#' + escHtml(String(z.settlement_id || '')) + '</td></tr>';
                });
                stornoTblAccBody += '</tbody></table></div>';
            } else {
                stornoTblAccBody += '<span class="text-muted">Keine Einträge.</span>';
            }
            var kelDetailAcc = '<div class="accordion accordion-flush mt-3 border rounded" id="' + accKel + '">';
            if (Math.abs(kelStornoGes) > 0.004 || kelStornoCnt > 0) {
                kelDetailAcc += ffKelAccordionSection(accKel + '_storno', 'Stornos — ' + euro(kelStornoGes) + ' (' + kelStornoCnt + ' Pos.)', stornoAccBody, false);
            }
            kelDetailAcc += ffKelAccordionSection(accKel + '_kachel', 'Kachel „Kellner (Kasse)“ — ' + euro(kelKachelSum), kachelAccBody, false);
            kelDetailAcc += ffKelAccordionSection(accKel + '_kd', 'Kachel „Kellner / Direktverkauf“ — ' + euro(kelD), kelDvAccBody, false);
            if (Math.abs(kelInBereich) > 0.004) {
                kelDetailAcc += ffKelAccordionSection(accKel + '_fin', 'Finanzbereich am Druckziel — ' + euro(kelInBereich), finanzAccBody, false);
            }
            if (Math.abs(kelProtokoll) > 0.004 || Math.abs(kelZeilenAbg) > 0.004) {
                kelDetailAcc += ffKelAccordionSection(accKel + '_prot', 'Abgleich Protokoll — Soll ' + euro(kelProtokoll), protAccBody, false);
            }
            if (stornoZeilen.length) {
                kelDetailAcc += ffKelAccordionSection(accKel + '_stornoTbl', 'Storno-Positionen (' + stornoZeilen.length + ')', stornoTblAccBody, false);
            }
            kelDetailAcc += ffKelAccordionSection(accKel + '_trink', 'Trinkgeld (Protokoll) — ' + euro(kelTrink), '<p class="mb-0 small text-muted">Summe Trinkgeld aus Kellner-Abrechnungsprotokollen im Zeitraum.</p>', false);
            kelDetailAcc += '</div>';
            var summaryRow = '<div class="row g-3 mb-2">' +
                '<div class="col-lg-6"><div class="border rounded-3 p-4 bg-light h-100">' +
                '<div class="d-flex align-items-start justify-content-between gap-2 mb-2">' +
                '<h5 class="mb-0">Kellnerabrechnung</h5>' +
                '<button type="button" class="btn ff-admin-info-btn flex-shrink-0" data-bs-toggle="collapse" data-bs-target="#ffOvKelAbOffHint" aria-expanded="false" title="Erklärung" aria-label="Erklärung"><span aria-hidden="true">i</span></button>' +
                '</div>' +
                '<div class="collapse ff-admin-info-panel small text-muted mb-2" id="ffOvKelAbOffHint"><div class="ff-admin-info-panel-inner">' + kelAbOffHint + '</div></div>' +
                '<p class="small text-muted mb-3">Kellner-Kasse · alle Druckziele · ohne Direktverkauf</p>' +
                '<div class="row g-2 mb-3">' +
                '<div class="col-sm-4"><div class="border rounded-3 p-3 h-100 border-success bg-success-subtle">' +
                '<div class="small text-muted">Abgerechnet</div><div class="fs-4 fw-bold text-success">' + euro(kelUmsatz) + '</div>' +
                '<div class="small text-muted">am Abrechnungsblatt</div></div></div>' +
                '<div class="col-sm-4"><div class="border rounded-3 p-3 h-100 border-warning bg-warning-subtle">' +
                '<div class="small text-muted">Noch offen</div><div class="fs-4 fw-bold text-warning">' + euro(kelOffen) + '</div>' +
                '<div class="small text-muted">bezahlt, nicht abgerechnet</div></div></div>' +
                '<div class="col-sm-4"><div class="border rounded-3 p-3 h-100 bg-white border-primary">' +
                '<div class="small text-muted">Summe Kellner-Kasse</div><div class="fs-4 fw-bold text-primary">' + euro(kelGueltigSum) + '</div>' +
                '<div class="small text-muted">abgerechnet + offen</div></div></div></div>' +
                (Math.abs(kelInBereich) > 0.004
                    ? '<p class="small mb-3"><span class="text-muted">Davon mit Finanzbereich am Druckziel:</span> <strong>' + euro(kelInBereich) + '</strong> '
                    + '<span class="text-muted">(in „Umsatz alle Bereiche“, Details aufklappen)</span></p>'
                    : '') +
                '<p class="small text-muted mb-2">Details (Stornos, Kacheln, Protokoll …)</p>' +
                kelDetailAcc +
                '<p class="small text-muted mb-0 mt-3">Ehrengast und Schreibaus sind in allen Summen ausgeschlossen.</p>' +
                '</div></div>' +
                '<div class="col-lg-6"><div class="border rounded-3 p-4 h-100 ' + gewinnCardCls + '">' +
                '<h5 class="mb-3">Gewinn-Berechnung (Fest gesamt)</h5>' +
                '<ul class="list-unstyled mb-3 fs-5">' +
                '<li class="mb-2">Gesamtumsatz: <strong>' + euro(d.umsatz_gesamt_kombiniert) + '</strong></li>' +
                '<li class="mb-2">+ Fixe Einnahmen: <strong>' + euro(d.fixe_einnahmen) + '</strong></li>' +
                '<li class="mb-2">− Fixe Ausgaben: <strong>' + euro(d.fixe_ausgaben) + '</strong></li>' +
                '<li class="mb-2">− Variable Kosten (EK): <strong>' + euro(d.variable_kosten) + '</strong></li>' +
                '</ul>' +
                '<div class="pt-3 border-top"><div class="text-muted small mb-1">Ergebnis</div>' +
                '<div class="fs-1 fw-bold ' + (g >= 0 ? 'text-success' : 'text-danger') + '">' + euro(d.gewinn) + '</div></div>' +
                '</div></div></div>';
            box.innerHTML = zeit + kpiRow + summaryRow + brTbl;
        });
    };
})();
