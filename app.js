/**
 * FF Fest Bestellsystem - Vanilla JS App (Bootstrap 5)
 * Ersetzt jQuery und jQuery Mobile
 */
(function() {
    'use strict';

    // --- Globale Variablen (wie zuvor) ---
    window.Tischnummer = 0;
    window._payState = window._payState || { selPay: {}, aggSel: {} };
    window._selPay = window._payState.selPay;
    window._aggSel = window._payState.aggSel;
    window.Summe = 0;
    window.AnzahlBestellungenAktuell = -1;
    window.AnzahlGetraenkeWartendAktuell = -1;
    window.bestellungSQL = "";
    window.bestellungTischnr = "";
    window.bestellungListe = [];
    window.Beilagen = "";
    window.rowid = "";

    /** true = Auto-Reload Küche/Schank/Druckziel anhalten (z. B. Maske „Sperren“) */
    window._pauseOperationsPoll = false;

    function ffGetTischFromUrl() {
        try {
            return parseInt(new URLSearchParams(window.location.search).get('tischnummer') || '', 10) || 0;
        } catch (e) {
            return 0;
        }
    }

    function ffGetLastTischFromStorage() {
        try {
            return parseInt(sessionStorage.getItem('ff_last_tisch') || '', 10) || 0;
        } catch (e2) {
            return 0;
        }
    }

    function ffRememberTisch(tischnummer) {
        var t = parseInt(tischnummer, 10) || 0;
        if (t <= 0) return 0;
        try { sessionStorage.setItem('ff_last_tisch', String(t)); } catch (e) {}
        return t;
    }

    function ffGetTischViewFromUrl() {
        try {
            return String(new URLSearchParams(window.location.search).get('view') || '').toLowerCase();
        } catch (eV) {
            return '';
        }
    }

    /** Aktuelle Teilansicht am Tisch (URL + DOM), vor Wechsel in Historie. */
    function ffDetectTischSubView() {
        var fromUrl = ffGetTischViewFromUrl();
        if (fromUrl === 'zahlen' || fromUrl === 'rechnung') return fromUrl;
        var bo = document.getElementById('Bestellungen');
        if (bo) {
            if (bo.querySelector('#BestellungZahlen') || bo.querySelector('[data-ff-tisch-view="zahlen"]')) return 'zahlen';
            if (bo.querySelector('[data-ff-tisch-view="rechnung"]')) return 'rechnung';
        }
        return 'bestellen';
    }

    function ffPersistTischHistorieReturn(view, requirePayment, tischnummer) {
        var v = (view === 'zahlen' || view === 'rechnung') ? view : 'bestellen';
        window._tischHistorieReturn = v;
        window._tischHistorieRequirePayment = !!requirePayment;
        var t = parseInt(tischnummer, 10) || parseInt(window.Tischnummer, 10) || 0;
        try {
            sessionStorage.setItem('ff_tisch_hist_return', v);
            sessionStorage.setItem('ff_tisch_hist_require_pay', requirePayment ? '1' : '0');
            if (t > 0) {
                sessionStorage.setItem('ff_tisch_hist_tischnummer', String(t));
            }
        } catch (eSt) {}
    }

    function ffRequirePaymentTischNummer() {
        var t = parseInt(window.Tischnummer, 10) || 0;
        if (t > 0) {
            return t;
        }
        try {
            var st = parseInt(sessionStorage.getItem('ff_tisch_hist_tischnummer'), 10);
            if (st > 0) {
                return st;
            }
        } catch (eTn) { /* ignore */ }
        return ffGetTischFromUrl() || ffGetLastTischFromStorage() || 0;
    }

    function ffIsRequirePaymentLocked() {
        return !!(window._requirePaymentActive || ffGetTischHistorieRequirePayment());
    }

    function ffRedirectToRequiredPayment(message) {
        var t = ffRequirePaymentTischNummer();
        if (t <= 0) {
            ffClearTischRequirePayment();
            return false;
        }
        ffSyncTischRequirePaymentFromServer(t).then(function(needPay) {
            if (!needPay) {
                return;
            }
            window._requirePaymentActive = true;
            window.Tischnummer = t;
            alert(message || 'Bitte erst zahlen oder Bezahlung abschließen.');
            ffPersistTischHistorieReturn('zahlen', true, t);
            ffSetTischInUrl(t, 'listTischBestellungen', 'zahlen');
            ffLoadTischZahlen(t, true);
            showPage('listTischBestellungen');
        });
        return true;
    }

    window.ffIsRequirePaymentLocked = ffIsRequirePaymentLocked;
    window.ffRedirectToRequiredPayment = ffRedirectToRequiredPayment;

    function ffGetTischHistorieReturn() {
        var v = window._tischHistorieReturn;
        if (v !== 'zahlen' && v !== 'rechnung') {
            try { v = sessionStorage.getItem('ff_tisch_hist_return') || ''; } catch (eGt) { v = ''; }
        }
        return (v === 'zahlen' || v === 'rechnung') ? v : 'bestellen';
    }

    function ffGetTischHistorieRequirePayment() {
        if (window._tischHistorieRequirePayment) return true;
        try { return sessionStorage.getItem('ff_tisch_hist_require_pay') === '1'; } catch (eRp) { return false; }
    }

    function ffClearTischRequirePayment() {
        window._requirePaymentActive = false;
        window._tischHistorieRequirePayment = false;
        try {
            sessionStorage.setItem('ff_tisch_hist_require_pay', '0');
            sessionStorage.removeItem('ff_tisch_hist_tischnummer');
        } catch (eCr) {}
    }
    window.ffClearTischRequirePayment = ffClearTischRequirePayment;

    /** Server: noch offene kassierbare Positionen? → Sperre setzen oder aufheben. */
    function ffSyncTischRequirePaymentFromServer(tischnummer) {
        var t = parseInt(tischnummer, 10) || ffRequirePaymentTischNummer();
        if (t <= 0) {
            ffClearTischRequirePayment();
            return Promise.resolve(false);
        }
        return fetch('tisch_pay_lock_status.php?tischnummer=' + encodeURIComponent(String(t)), {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function(r) { return r.json(); })
            .then(function(j) {
                if (!j || j.ok !== true) {
                    return ffIsRequirePaymentLocked();
                }
                if (parseInt(j.require_payment, 10) === 1) {
                    window._requirePaymentActive = true;
                    ffPersistTischHistorieReturn('zahlen', true, t);
                    return true;
                }
                ffClearTischRequirePayment();
                return false;
            })
            .catch(function() {
                return ffIsRequirePaymentLocked();
            });
    }
    window.ffSyncTischRequirePaymentFromServer = ffSyncTischRequirePaymentFromServer;

    function ffUpdateHistorieBackLabel() {
        var lbl = document.getElementById('ffHistBackLabel');
        if (!lbl) return;
        var ret = ffGetTischHistorieReturn();
        if (ret === 'zahlen') lbl.textContent = 'Zurück zum Abrechnen';
        else if (ret === 'rechnung') lbl.textContent = 'Zurück zur Rechnungsauswahl';
        else lbl.textContent = 'Zurück zur Speisekarte';
    }

    /** Tischnummer (+ optional Teilansicht) in der URL, damit F5 den richtigen Tisch-Bereich lädt. */
    function ffSetTischInUrl(tischnummer, pageHash, view) {
        var t = ffRememberTisch(tischnummer);
        if (t <= 0) return;
        var hash = pageHash || 'listTischBestellungen';
        var v = view ? String(view).toLowerCase() : '';
        try {
            var url = new URL(window.location.href);
            url.searchParams.set('tischnummer', String(t));
            if (v && v !== 'bestellen') {
                url.searchParams.set('view', v);
            } else {
                url.searchParams.delete('view');
            }
            url.hash = hash;
            history.replaceState(null, '', url.pathname + url.search + url.hash);
        } catch (eUrl) {
            window.location.hash = hash;
        }
    }

    /** Tisch-Shell (Navbar + Speisekarten-Tabs) bereits im DOM? */
    function ffTischShellReady() {
        var shell = document.getElementById('listTischBestellungen');
        return !!(shell && shell.querySelector('#TischAnzeigenContent'));
    }

    function ffZahlenPayUrl(tischnummer, requirePayment, partial) {
        var u = 'list_BestellungenZahlen.php?tischnummer=' + (parseInt(tischnummer, 10) || 0);
        if (requirePayment) u += '&require_payment=1';
        if (partial) u += '&partial=1';
        return u;
    }

    /** Navbar (offene Posten, „Erst zahlen“) im Hintergrund aktualisieren, ohne Speisekarte/Bezahlung zu ersetzen. */
    function ffRefreshTischNavbarSilent(tischnummer) {
        var t = parseInt(tischnummer, 10) || 0;
        if (t <= 0 || !ffTischShellReady()) return;
        fetch('tisch_anzeigen.php?tischnummer=' + t, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function(r) { return r.ok ? r.text() : ''; })
            .then(function(html) {
                if (!html || html.length < 80) return;
                var shell = document.getElementById('listTischBestellungen');
                if (!shell) return;
                var wrap = document.createElement('div');
                wrap.innerHTML = html;
                var newNav = wrap.querySelector('nav.app-navbar');
                var oldNav = shell.querySelector('nav.app-navbar');
                if (newNav && oldNav) {
                    oldNav.replaceWith(newNav);
                }
            })
            .catch(function() {});
    }

    /**
     * Bezahlansicht laden. Shell nur bei Bedarf (erster Tischaufruf), sonst nur #Bestellungen
     * (deutlich schneller bei „Zahlen“ / „Erst zahlen!“ vom gleichen Tisch).
     */
    function ffLoadTischZahlen(tischnummer, requirePayment, onDone) {
        var t = parseInt(tischnummer, 10) || parseInt(window.Tischnummer, 10) || 0;
        if (t <= 0) return;
        window.Tischnummer = t;
        var reqPay = !!requirePayment;
        if (reqPay) {
            window._requirePaymentActive = true;
            ffPersistTischHistorieReturn('zahlen', true, t);
        }
        var shellReady = ffTischShellReady();
        var payUrl = ffZahlenPayUrl(t, reqPay, shellReady);
        var bo = document.getElementById('Bestellungen');
        /* Immer „Laden…“ wenn von Speisekarte/Historie → Zahlen (kein transparentes Durchscheinen) */
        var payOpts = {};
        showPage('listTischBestellungen');

        function afterPay() {
            setTimeout(function() { ffRefreshTischNavbarSilent(t); }, 1200);
            if (typeof onDone === 'function') onDone();
        }

        if (shellReady && bo) {
            loadContent(payUrl, 'Bestellungen', afterPay, payOpts);
            return;
        }
        loadContent('tisch_anzeigen.php?tischnummer=' + t, 'listTischBestellungen', function() {
            initTischTabs();
            loadContent(payUrl, 'Bestellungen', afterPay, payOpts);
            showPage('listTischBestellungen');
        });
    }

    /** Bestell-History (Vollseite) aus eingebetteter Tisch-Historie – Rückweg/Bezahlung erhalten. */
    window.ffOpenTischGesamteStory = function(adminExtras) {
        var t = parseInt(window.Tischnummer, 10) || ffGetTischFromUrl() || 0;
        if (t <= 0) return;
        var ret = ffGetTischHistorieReturn();
        var curView = ffGetTischViewFromUrl();
        if (curView === 'historie' && ret === 'bestellen') {
            ret = 'historie';
        }
        var reqPay = ffGetTischHistorieRequirePayment() || !!window._requirePaymentActive;
        ffPersistTischHistorieReturn(ret, reqPay, t);
        var url = 'bestell_history.php?table=' + encodeURIComponent(String(t))
            + '&from=tisch&return=' + encodeURIComponent(ret)
            + '&require_pay=' + (reqPay ? '1' : '0');
        if (adminExtras) {
            url += '&abrechnung=alle&alle=1';
        }
        window.location.href = url;
    };

    /** Eine Bestellungsrunde in der Bestell-History (from=tisch → nur eigene Zeilen). */
    window.ffOpenTischOrderDetail = function(orderNr, bonId) {
        var t = parseInt(window.Tischnummer, 10) || ffGetTischFromUrl() || 0;
        if (t <= 0) return;
        var onr = parseInt(orderNr, 10) || 0;
        var bon = (bonId != null && bonId !== undefined) ? String(bonId).trim() : '';
        if (onr <= 0 && bon === '') return;
        var ret = ffGetTischHistorieReturn();
        var curView = ffGetTischViewFromUrl();
        if (curView === 'historie' && ret === 'bestellen') {
            ret = 'historie';
        }
        var reqPay = ffGetTischHistorieRequirePayment() || !!window._requirePaymentActive;
        ffPersistTischHistorieReturn(ret, reqPay, t);
        var url = 'bestell_history.php?from=tisch&table=' + encodeURIComponent(String(t))
            + '&return=' + encodeURIComponent(ret)
            + '&require_pay=' + (reqPay ? '1' : '0');
        if (t === 999999 && bon !== '') {
            url += '&bon=' + encodeURIComponent(bon);
        } else if (onr > 0) {
            url += '&q=' + encodeURIComponent(String(onr));
        } else {
            return;
        }
        window.location.href = url;
    };

    /** Nach F5: Shell + Speisekarte / Zahlen / Historie / Rechnung-Auswahl. */
    function ffRestoreTischPage(tischnummer, view) {
        var t = parseInt(tischnummer, 10) || 0;
        if (t <= 0) return;
        window.Tischnummer = t;
        var v = (view || 'bestellen').toLowerCase();
        if (ffGetTischHistorieRequirePayment() || window._requirePaymentActive) {
            window._requirePaymentActive = true;
            var allowedWhileLocked = { bestellen: 1, historie: 1, zahlen: 1, rechnung: 1 };
            if (!allowedWhileLocked[v]) {
                v = 'zahlen';
            }
        }
        ffSetTischInUrl(t, 'listTischBestellungen', v);

        function loadSubView() {
            if (v === 'zahlen') {
                ffLoadTischZahlen(t, window._requirePaymentActive || ffGetTischHistorieRequirePayment());
                return;
            }
            if (v === 'historie') {
                loadContent('list_Bestellungen.php?tischnummer=' + t, 'Bestellungen');
                return;
            }
            if (v === 'rechnung') {
                loadContent('list_rechnung_nachtraeglich.php?tischnummer=' + t, 'Bestellungen');
            }
        }

        function afterShell() {
            initTischTabs();
            showPage('listTischBestellungen');
            if (v !== 'bestellen') loadSubView();
        }

        if (!ffTischShellReady()) {
            loadContent('tisch_anzeigen.php?tischnummer=' + t, 'listTischBestellungen', afterShell);
        } else {
            afterShell();
        }
    }

    function ffClearTischFromUrl() {
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete('tischnummer');
            history.replaceState(null, '', url.pathname + url.search + url.hash);
        } catch (eClr) {}
    }

    window.ffOpsBellMuted = function() {
        try {
            return localStorage.getItem('ff_mute_ops_bell') === '1';
        } catch (e) {
            return false;
        }
    };
    window.ffSyncOpsBellButton = function(btn) {
        if (!btn) return;
        btn.textContent = window.ffOpsBellMuted() ? 'Klingel an' : 'Klingel aus';
    };
    window.ffToggleOpsBell = function(btn) {
        try {
            if (window.ffOpsBellMuted()) {
                localStorage.removeItem('ff_mute_ops_bell');
            } else {
                localStorage.setItem('ff_mute_ops_bell', '1');
            }
        } catch (e) {}
        window.ffSyncOpsBellButton(btn);
    };

    /** Klingel nur, wenn die Liste leer war und wieder mindestens Nr. 1 da ist (pro Druckziel). */
    window._ffStationListenPrev = window._ffStationListenPrev || {};
    window.ffStationBellOnPoll = function(printTargetId, listenCount) {
        var pt = parseInt(printTargetId, 10) || 0;
        var n = parseInt(listenCount, 10);
        if (isNaN(n) || n < 0) n = 0;
        if (typeof window.ffOpsBellMuted === 'function' && window.ffOpsBellMuted()) {
            window._ffStationListenPrev[pt] = n;
            return;
        }
        var prev = window._ffStationListenPrev[pt];
        if (typeof prev !== 'number') {
            window._ffStationListenPrev[pt] = n;
            return;
        }
        if (prev === 0 && n > 0) {
            var a = document.getElementById('sound1');
            if (a) {
                try {
                    a.currentTime = 0;
                    var pr = a.play();
                    if (pr && typeof pr.catch === 'function') pr.catch(function() {});
                } catch (e) {}
            }
        }
        window._ffStationListenPrev[pt] = n;
    };

    // --- Hilfsfunktionen ---
    function $(id) { return document.getElementById(id); }
    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return (root || document).querySelectorAll(sel); }

    function showLoading(show) {
        var overlay = document.getElementById('loadingOverlay');
        if (overlay) overlay.style.display = show ? 'flex' : 'none';
    }

    function getActivePageId() {
        var pages = document.querySelectorAll('[data-page]');
        for (var i = 0; i < pages.length; i++) {
            if (pages[i].classList.contains('active')) return pages[i].id;
        }
        return 'indexPage';
    }

    /**
     * Pause zwischen zwei Auto-Reloads: Küche, Schank, Druckziele (Millisekunden, vor _delay).
     * Bewusst ein fester Wert – keine Schwellen nach Auftragsanzahl (gleiches Verhalten, leicht zu erklären).
     * Mit Admin „Schnellere Aktualisierung“: _delay() halbiert (mind. 800 ms).
     */
    var OPERATIONS_POLL_INTERVAL_MS = 3000;

    function syncBodySubviewClass() {
    }

    /** Hash ohne exakte Groß/Kleinschreibung auf data-page-id abbilden (z. B. #listtische → listTische). */
    function resolveDataPageIdFromHash(raw) {
        if (!raw || raw === '') return 'indexPage';
        var el = document.getElementById(raw);
        if (el && el.hasAttribute('data-page')) return raw;
        var lower = String(raw).toLowerCase();
        var nodes = document.querySelectorAll('[data-page]');
        for (var i = 0; i < nodes.length; i++) {
            var pid = nodes[i].id;
            if (pid && String(pid).toLowerCase() === lower) return pid;
        }
        return raw;
    }

    /** Druckziel-Hash kanonisieren (z. B. #druckzielansicht_12). */
    function normalizeDruckzielHash(h) {
        var m = String(h || '').match(/^druckzielansicht(?:_(\d+))?$/i);
        if (!m) return null;
        return m[1] ? ('DruckzielAnsicht_' + m[1]) : 'DruckzielAnsicht';
    }

    /** Kompakt-Menü / „← Menü“: eingestellte Startseite inkl. Inhalt laden (nicht nur Hash). */
    function ffNavigateHome() {
        if (ffIsRequirePaymentLocked()) {
            ffRedirectToRequiredPayment();
            return;
        }
        if (!window.FF_USER_COMPACT_MENU || !window.FF_COMPACT_HOME_HASH || window.FF_COMPACT_HOME_HASH === 'indexPage') {
            showPage('indexPage');
            return;
        }
        var h = window.FF_COMPACT_HOME_HASH;
        var dCanon = normalizeDruckzielHash(h);
        if (dCanon) {
            var ptId = parseInt(dCanon.replace(/^DruckzielAnsicht_/, ''), 10) || 0;
            if (ptId > 0) {
                DruckzielAnsicht(ptId, '');
                return;
            }
        }
        if (h === 'Kuechenansicht') {
            DruckzielAnsicht(11, 'Küche');
            return;
        }
        if (h === 'Schankansicht') {
            DruckzielAnsicht(12, 'Schank');
            return;
        }
        var nav = {
            listTische: TischAnsicht,
            Direktverkauf: Direktverkauf,
            MitarbeiterVerpflegungPage: MitarbeiterVerpflegungAnsicht,
            myOrdersPage: myOrdersAnsicht
        };
        if (nav[h]) {
            nav[h]();
            return;
        }
        showPage('indexPage');
    }

    function showPage(pageId, hashOverride) {
        if (ffIsRequirePaymentLocked() && pageId !== 'listTischBestellungen') {
            ffRedirectToRequiredPayment();
            return;
        }
        /* Kompakt-Menü: „Hauptmenü“-Kachel entfällt — Start = eingestellte Ansicht (Küche/Schank/…).
           Nur den Hash zu setzen reicht nicht: .active muss auf demselben [data-page] liegen wie der Hash,
           sonst wird in den versteckten Container geladen und wirkt „ungestylt“ / leer. */
        if (pageId === 'indexPage' && window.FF_USER_COMPACT_MENU && window.FF_COMPACT_HOME_HASH && window.FF_COMPACT_HOME_HASH !== 'indexPage') {
            var shell = window.FF_COMPACT_DOM_PAGE_ID || '';
            if (shell === 'Kuechenansicht' || shell === 'Schankansicht') {
                shell = 'DruckzielAnsicht';
            }
            if (shell && shell !== 'indexPage') {
                pageId = shell;
                hashOverride = window.FF_COMPACT_HOME_HASH;
            }
        }
        var pages = document.querySelectorAll('[data-page]');
        for (var i = 0; i < pages.length; i++) {
            pages[i].classList.remove('active');
        }
        var page = document.getElementById(pageId);
        if (!page || !page.hasAttribute('data-page')) {
            page = document.getElementById('indexPage');
            pageId = 'indexPage';
            hashOverride = 'indexPage';
        }
        if (page) {
            page.classList.add('active');
            var h = hashOverride !== undefined && hashOverride !== null && hashOverride !== '' ? hashOverride : pageId;
            window.location.hash = h;
        }
        syncBodySubviewClass();
    }

    function ffRedirectToLogin(reasonMsg) {
        var msg = reasonMsg || 'Sie wurden abgemeldet. Bitte erneut anmelden.';
        window.location.href = 'login.php?msg=' + encodeURIComponent(msg);
    }

    function ffParseAuthLogoutMessage(text) {
        var msg = 'Sie wurden abgemeldet. Bitte erneut anmelden.';
        if (!text) return msg;
        try {
            var j = JSON.parse(text);
            if (j && j.message) return String(j.message);
            if (j && j.error === 'session_invalid') return msg;
        } catch (e) { /* HTML-Loginseite */ }
        return msg;
    }

    function loadContent(url, containerId, callback, opts) {
        opts = opts || {};
        var el = typeof containerId === 'string' ? document.getElementById(containerId) : containerId;
        if (!el) {
            console.error('loadContent: Container nicht gefunden:', containerId);
            return;
        }
        /* silentPoll: nur Auto-Refresh (Küche/Speisekarte) – nie halbtransparentes Durchscheinen */
        var silent = opts.silentPoll === true && el.children.length > 0;
        var prevScrollY = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
        var prevMinH = '';
        if (silent) {
            el.classList.add('app-content-silent-reload');
            var h = el.offsetHeight;
            if (h > 48) {
                prevMinH = el.style.minHeight;
                el.style.minHeight = h + 'px';
            }
        } else {
        el.innerHTML = '<div class="p-4 text-center"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Laden...</p></div>';
        }
        fetch(url, { cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) {
                if (r.status === 401) {
                    return r.text().then(function(t) {
                        ffRedirectToLogin(ffParseAuthLogoutMessage(t));
                        throw new Error('logged_out');
                    });
                }
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function(html) {
                if (!html || html.length < 50) throw new Error('Leere Antwort');
                if (html.indexOf('login.php') !== -1 || html.indexOf('Kennwort') !== -1 || html.indexOf('Benutzername') !== -1) {
                    window.location.href = 'login.php' + (window.location.hash ? '?redirect=' + encodeURIComponent(window.location.hash) : '');
                    return;
                }
                el.innerHTML = html;
                el.classList.remove('app-content-silent-reload');
                el.style.minHeight = prevMinH;
                // Wenn Historie oder Tischansicht geladen wurde: Zahlungspflicht-Flag zurücksetzen
                // Nur bei Historie-Inhalt zurücksetzen (nicht bei Tischliste/Tischansicht – sonst könnte man aus der Bezahlmaske „entkommen“)
                if (url.indexOf('list_Bestellungen.php') !== -1) {
                    if (!ffGetTischHistorieRequirePayment()) {
                        window._requirePaymentActive = false;
                    }
                    ffUpdateHistorieBackLabel();
                }
                // Scripts im geladenen HTML ausführen (externe .js erst laden, dann Inline)
                executeScriptsIn(el, function() {
                    if (silent) {
                        requestAnimationFrame(function() {
                            requestAnimationFrame(function() {
                                window.scrollTo(0, prevScrollY);
                            });
                        });
                    }
                    if (typeof window.ffSystemBroadcastPoll === 'function') {
                        window.ffSystemBroadcastPoll();
                    }
                    if (typeof callback === 'function') callback();
                });
            })
            .catch(function(err) {
                if (err && err.message === 'logged_out') return;
                console.error('loadContent error:', url, err);
                el.classList.remove('app-content-silent-reload');
                el.style.minHeight = prevMinH;
                el.innerHTML = '<div class="alert alert-danger m-3">Fehler beim Laden von ' + url + '. Bitte Konsole prüfen (F12).</div>';
                if (typeof callback === 'function') callback();
            });
    }

    /**
     * Tischansicht: Speisen- oder Getränke-Tab neu laden (z. B. nach „–“), damit „nur noch X× verfügbar“ etc. stimmt.
     * @param {number} tischnummer
     * @param {number} type 1 = Speisen, 0 = Getränke
     * @returns {boolean} true wenn neu geladen wurde
     */
    window.ffReloadTischKarteTab = function(tischnummer, type) {
        var tnum = parseInt(String(tischnummer), 10) || 0;
        var ty = parseInt(String(type), 10);
        if (tnum <= 0) return false;
        var tabId = (tnum === 999999)
            ? ((ty === 1) ? 'tabSpeisenDirektverkauf' : 'tabGetraenkeDirektverkauf')
            : ((ty === 1) ? 'tabSpeisen' : 'tabGetraenke');
        var pane = document.getElementById(tabId);
        if (!pane) return false;
        var inner = pane.querySelector('.tab-content-inner');
        if (!inner) return false;
        var base;
        if (tnum === 999999) {
            base = (ty === 1) ? 'listSpeisen_direktverkauf.php' : 'listGetraenke_direktverkauf.php';
            loadContent(base, inner, null, { silentPoll: true });
        } else {
            base = (ty === 1) ? 'listSpeisen.php' : 'listGetraenke.php';
            var url = base + '?tischnummer=' + encodeURIComponent(String(tnum));
            loadContent(url, inner, null, { silentPoll: true });
        }
        return true;
    };

    function executeScriptsIn(container, done) {
        if (!container) {
            if (typeof done === 'function') done();
            return;
        }
        var scripts = Array.prototype.slice.call(container.querySelectorAll('script'));
        var idx = 0;
        function next() {
            if (idx >= scripts.length) {
                if (typeof done === 'function') done();
                return;
            }
            var oldScript = scripts[idx++];
            if (oldScript.getAttribute('data-ff-pay-static') === '1'
                && typeof window.paySelect === 'function'
                && typeof window.updateButtonsAndSum === 'function') {
                if (oldScript.parentNode) oldScript.parentNode.removeChild(oldScript);
                next();
                return;
            }
            if (oldScript.src) {
                var newScript = document.createElement('script');
                newScript.src = oldScript.src;
                newScript.onload = function() { next(); };
                newScript.onerror = function() {
                    console.error('Script konnte nicht geladen werden:', oldScript.src);
                    next();
                };
                oldScript.parentNode.replaceChild(newScript, oldScript);
            } else {
                try {
                    var code = oldScript.textContent;
                    if (code && code.trim()) {
                        (new Function(code))();
                    }
                } catch (e) {
                    console.error('Script-Fehler:', e, oldScript.textContent.substring(0, 200));
                }
                next();
            }
        }
        next();
    }

    /** z. B. Direktverkauf-Tabs: nach innerHTML Scripts ausführen (minusPosition, …) */
    window.ffExecuteScriptsInContainer = executeScriptsIn;

    function fetchPost(url, data, options) {
        options = options || {};
        var body;
        if (typeof data === 'string') {
            body = data;
        } else if (typeof URLSearchParams !== 'undefined') {
            body = new URLSearchParams(data).toString();
        } else {
            body = Object.keys(data).map(function(k) {
                return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
            }).join('&');
        }
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
            cache: 'no-store',
            credentials: 'same-origin'
        }).then(function(r) {
            if (r.status === 401) {
                return r.text().then(function(t) {
                    ffRedirectToLogin(ffParseAuthLogoutMessage(t));
                    throw new Error('logged_out');
                });
            }
            return r;
        });
    }

    /** @returns {Promise<void>} Gemeinsame Fehlerbehandlung für bestellung_save.php (403 gesperrt, 400 Limit/Ausverkauf). */
    function handleBestellungSaveFetchResponse(r) {
        if (r.status === 403) {
            return r.json().then(function(j) {
                alert(j.message || 'Diese Position ist vorübergehend gesperrt.');
                throw new Error('locked');
            });
        }
        if (r.status === 400) {
            return r.json().then(function(j) {
                var msg = (j && j.message) ? j.message : ((j && j.error === 'Ausverkauft') ? 'Ausverkauft.' : 'Bestellung nicht möglich.');
                alert(msg);
                throw new Error('badreq');
            }).catch(function(e) {
                if (e && (e.message === 'badreq' || e.message === 'locked')) throw e;
                alert('Ausverkauft oder Limit erreicht.');
                throw new Error('badreq');
            });
        }
        if (!r.ok) return Promise.reject(new Error('http'));
        var ct = (r.headers.get('Content-Type') || '').toLowerCase();
        if (ct.indexOf('application/json') >= 0) {
            return r.json().catch(function() { return { ok: true }; });
        }
        return Promise.resolve({ ok: true });
    }

    /** Paybar inkl. Warenkorb/Bezahlen-Buttons (leer ↔ belegt wie Server-HTML). */
    function ffDvSyncPaybarUi(res) {
        if (!res || res.dv_count === undefined || res.dv_count === null) {
            return false;
        }
        var sumEl = document.getElementById('ffDvPaySummeText');
        var cntEl = document.getElementById('ffDvPayCountText');
        var idsEl = document.getElementById('ffDvPayIds');
        if (!sumEl || !cntEl) {
            return false;
        }
        var cnt = Math.max(0, parseInt(String(res.dv_count), 10) || 0);
        var ids = Array.isArray(res.dv_ids) ? res.dv_ids : [];
        var idsJson = JSON.stringify(ids);
        sumEl.textContent = res.dv_sum_fmt || '0,00 €';
        cntEl.textContent = cnt > 0 ? ('(' + cnt + ' Pos.)') : '(leer)';
        var cartSumEl = document.getElementById('ffDvCartGesamtText');
        if (cartSumEl) {
            cartSumEl.textContent = res.dv_sum_fmt || '0,00 €';
        }
        if (idsEl) {
            idsEl.setAttribute('data-ids', idsJson);
        }
        var actions = document.querySelector('#ffDvPaybar .ff-dv-paybar-actions');
        if (actions) {
            if (cnt > 0) {
                actions.innerHTML =
                    '<button type="button" class="btn btn-outline-primary btn-sm" id="ffDvCartOpenBtn" onclick="ffDvToggleCart(true); return false;">'
                    + 'Warenkorb <span class="badge bg-primary" id="ffDvCartBadge">' + cnt + '</span></button>'
                    + '<button type="button" class="btn btn-success ff-tap-fast" onclick="ffDvBezahlenConfirm(' + idsJson + '); return false;">Bezahlen</button>';
            } else {
                actions.innerHTML =
                    '<button type="button" class="btn btn-outline-secondary btn-sm" disabled>Warenkorb</button>'
                    + '<span class="btn btn-outline-secondary disabled" aria-disabled="true">Bezahlen</span>';
            }
        }
        if (typeof window.ffDvBindPaybar === 'function') {
            window.ffDvBindPaybar();
        }
        return true;
    }

    function ffUpdateDvPaybarFromSave(res) {
        return ffDvSyncPaybarUi(res);
    }

    /** Alle DV-Kachel-Zähler aus Server-Map (nach Warenkorb − / leerer Bon). */
    function ffApplyDvOpenCountMap(openMap) {
        var tabRoot = document.getElementById('DirektverkaufTabContent');
        if (!tabRoot) return;
        var map = openMap && typeof openMap === 'object' ? openMap : {};
        tabRoot.querySelectorAll('[id^="cnt-"]').forEach(function(cntEl) {
            var m = cntEl.id.match(/^cnt-(\d+)$/);
            if (!m) return;
            var posId = parseInt(m[1], 10);
            var openCnt = Object.prototype.hasOwnProperty.call(map, posId)
                ? parseInt(String(map[posId]), 10)
                : (Object.prototype.hasOwnProperty.call(map, String(posId))
                    ? parseInt(String(map[String(posId)]), 10) : 0);
            if (isNaN(openCnt) || openCnt < 0) openCnt = 0;
            ffUpdatePosTileFromSave(posId, { open_cnt: openCnt });
        });
    }

    function ffDvAfterCartChange(j) {
        if (!j || !j.ok) return;
        if (typeof ffDvSyncPaybarUi === 'function' && !ffDvSyncPaybarUi(j)) {
            if (typeof window.ffDvRefreshPaybar === 'function') {
                window.ffDvRefreshPaybar();
            }
        }
        if (j.open_counts && typeof ffApplyDvOpenCountMap === 'function') {
            ffApplyDvOpenCountMap(j.open_counts);
        } else if (j.position_id) {
            ffUpdatePosTileFromSave(j.position_id, j);
        }
        if (j.position_id && j.max_bestellbar > 0 && typeof j.rest === 'number') {
            ffUpdatePosTileFromSave(j.position_id, {
                open_cnt: j.open_cnt,
                rest: j.rest,
                max_bestellbar: j.max_bestellbar
            });
        }
        var cartOc = document.getElementById('ffDvCartOffcanvas');
        var cartOpen = cartOc && cartOc.classList.contains('show');
        var dvCntLeft = j.dv_count !== undefined && j.dv_count !== null
            ? Math.max(0, parseInt(String(j.dv_count), 10) || 0)
            : -1;
        if (dvCntLeft === 0) {
            var body = document.getElementById('ffDvCartOffcanvasBody');
            if (body) {
                body.innerHTML = '<p class="text-muted small mb-0">Keine offenen Positionen auf diesem Bon.</p>';
            }
            var footer = document.getElementById('ffDvCartOffcanvasFooter');
            if (footer) footer.classList.add('d-none');
        } else if (cartOpen && typeof window.ffDvRefreshCart === 'function') {
            window.ffDvRefreshCart();
        }
    }

  /** Rest-/Ausverkauft-Hinweis an der Kachel ohne Tab-Reload (DV mit maxBestellbar). */
    function ffUpdatePosTileStockVisual(btnEl, res) {
        if (!btnEl || !res || !(res.max_bestellbar > 0) || typeof res.rest !== 'number') {
            return;
        }
        var rest = parseInt(String(res.rest), 10);
        if (isNaN(rest)) return;
        var wrap = btnEl.closest('.posWrap');
        if (!wrap) return;
        var meta = wrap.querySelector('.pos-tile-meta');
        btnEl.classList.remove('pos-tile--sold-out', 'pos-tile--low-stock');
        btnEl.setAttribute('data-rest', String(rest));
        if (rest <= 0) {
            btnEl.disabled = true;
            btnEl.classList.add('pos-tile--sold-out');
            if (meta) {
                meta.textContent = ' AUSVERKAUFT!';
                meta.className = 'pos-tile-meta pos-tile-meta--sold';
            }
        } else {
            btnEl.disabled = false;
            if (rest < 10) {
                btnEl.classList.add('pos-tile--low-stock');
                if (meta) {
                    meta.textContent = ' Noch ' + rest + '×';
                    meta.className = 'pos-tile-meta pos-tile-meta--warn';
                }
            } else if (meta) {
                meta.textContent = '';
                meta.className = 'pos-tile-meta';
            }
        }
    }

    /** Nach bestellung_save: Kachel-Zähler lokal aktualisieren (kein Tab-Reload). */
    function ffUpdatePosTileFromSave(positionId, res) {
        var posId = parseInt(String(positionId), 10) || 0;
        if (posId <= 0 || !res) return false;
        var btnEl = document.getElementById('btn-pos-' + posId);
        var cntEl = document.getElementById('cnt-' + posId);
        if (!btnEl || !cntEl) return false;
        if (res.open_cnt !== undefined && res.open_cnt !== null) {
            var openCnt = parseInt(String(res.open_cnt), 10);
            if (isNaN(openCnt) || openCnt < 0) openCnt = 0;
            cntEl.setAttribute('data-cnt', String(openCnt));
            if (openCnt > 0) {
                cntEl.textContent = ' (' + openCnt + 'x)';
                btnEl.classList.add('pos-tile--selected');
                btnEl.style.background = '';
                btnEl.style.color = '';
            } else {
                cntEl.textContent = '';
                btnEl.classList.remove('pos-tile--selected');
                var wrap = btnEl.closest('.posWrap');
                var baseBg = wrap ? wrap.getAttribute('data-basebg') || '#ffffff' : '#ffffff';
                btnEl.style.background = baseBg;
                btnEl.style.color = '';
            }
        }
        ffUpdatePosTileStockVisual(btnEl, res);
        return true;
    }

    function fetchGet(url) {
        return fetch(url, { cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    }

    function onError() {
        alert("Fehler: Der Eintrag konnte nicht gespeichert werden!");
    }

    // --- Tab-Reload (ersetzt jQuery Mobile tabs) ---
    function reloadTabs(containerId, tabIndex) {
        var container = $(containerId);
        if (!container) return;
        var navLinks = container.querySelectorAll('[data-bs-toggle="tab"]');
        if (navLinks && navLinks[tabIndex]) {
            var tab = new bootstrap.Tab(navLinks[tabIndex]);
            tab.show();
        }
        var pane = container.querySelector('.tab-pane.active');
        if (pane && pane.dataset.loadUrl) {
            loadContent(pane.dataset.loadUrl, pane.querySelector('.tab-content-inner') || pane);
        }
    }

    // --- Öffentliche API ---
    window.showPage = showPage;
    window.ffNavigateHome = ffNavigateHome;
    window.loadContent = loadContent;
    window.getActivePageId = getActivePageId;
    window.reloadTabs = reloadTabs;

    window.TischAnsicht = function() {
        if (ffIsRequirePaymentLocked()) {
            ffRedirectToRequiredPayment();
            return;
        }
        if (window.FF_DV_ONLY_UI === 1) {
            if (typeof window.Direktverkauf === 'function') {
                window.Direktverkauf();
            }
            return;
        }
        // If camera permission is explicitly denied, go straight to classic table list
        try {
            if (window._ffCameraPermissionDenied === true) {
                loadListTische();
                return;
            }
        } catch (e) {}
        // Show table selection modal (QR scan or all tables)
        try {
            var modalEl = document.getElementById('ffTischSelectModal');
            if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var m = new bootstrap.Modal(modalEl);
                m.show();
                return;
            }
        } catch (e) {}
        // Fallback: load full table list
        loadListTische();
    };

    /* Load the regular table list into the page */
    window.loadListTische = function() {
        console.log('loadListTische()');
        window.Tischnummer = 0;
        window._ffTischUnsentCount = 0;
        window._ffTischUnsentTable = 0;
        ffClearTischFromUrl();
        showLoading(true);
        loadContent('list_tische.php', 'listTische', function() {
            showLoading(false);
            showPage('listTische');
        });
    };

    /* Close the select modal and then load the full table list */
    window.closeTischSelectAndLoadAll = function() {
        try {
            var sel = document.getElementById('ffTischSelectModal');
            if (sel && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var inst = bootstrap.Modal.getInstance(sel) || new bootstrap.Modal(sel);
                inst.hide();
            }
        } catch (e) {}
        loadListTische();
    };

    /* QR scanning helpers for table selection (robust camera detection) */
    window._ffTischQrScanner = null; // Html5Qrcode instance
    window.startTischQrScan = function() {
        // Hide select modal if open
        try { var sel = document.getElementById('ffTischSelectModal'); if (sel) { var _m = bootstrap.Modal.getInstance(sel); if (_m) _m.hide(); } } catch (e) {}
        // If permission was previously denied, don't attempt scanner — load classic table view
        try { if (window._ffCameraPermissionDenied === true) { loadListTische(); return; } } catch (e) {}
        var scanModalEl = document.getElementById('ffTischScanModal');
        var scanRegion = document.getElementById('ffTischScanRegion');
        if (!scanModalEl || !scanRegion) {
            loadListTische();
            return;
        }
        // Clear previous content
        scanRegion.innerHTML = '';
        // Show modal first (camera prompt will come after)
        var m = new bootstrap.Modal(scanModalEl);
        m.show();

        var startNativeScanner = function() {
            if (!('BarcodeDetector' in window)) return false;
            try {
                var formats = ['qr_code'];
                if (typeof BarcodeDetector.getSupportedFormats === 'function') {
                    // some browsers require checking
                    // proceed anyway — constructor will throw if not supported
                }
                var detector = new BarcodeDetector({ formats: formats });
                // create video element
                var vid = document.createElement('video');
                vid.setAttribute('autoplay', '');
                vid.setAttribute('playsinline', '');
                vid.style.width = '100%';
                vid.style.height = '100%';
                vid.id = 'ffTischNativeVideo';
                scanRegion.appendChild(vid);
                window._ffTischNativeDetector = detector;
                window._ffTischNativeVideo = vid;
                navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function(stream) {
                    vid.srcObject = stream;
                    window._ffTischNativeStream = stream;
                    vid.play().catch(function() {});
                    var running = true;
                    var detectLoop = function() {
                        if (!running) return;
                        detector.detect(vid).then(function(barcodes) {
                            if (barcodes && barcodes.length > 0) {
                                running = false;
                                var v = barcodes[0].rawValue || (barcodes[0].rawData && barcodes[0].rawData.toString()) || '';
                                _handleTischScanResult(v);
                            } else {
                                setTimeout(detectLoop, 200);
                            }
                        }).catch(function(err) {
                            // some implementations require drawing video to canvas — fallback to library
                            running = false;
                            console.error('BarcodeDetector.detect error', err);
                            showScanFallback();
                        });
                    };
                    setTimeout(detectLoop, 300);
                }).catch(function(err) {
                    console.error('getUserMedia for BarcodeDetector failed', err);
                    showScanFallback();
                });
                return true;
            } catch (e) {
                console.error('BarcodeDetector init failed', e);
                return false;
            }
        };

        var ensureLib = function(cb, errCb) {
            if (typeof Html5Qrcode !== 'undefined') {
                return cb();
            }
            // try native BarcodeDetector first (no CDN required)
            try {
                if (startNativeScanner()) return;
            } catch (e) {}
            var urls = [
                'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js',
                'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/dist/html5-qrcode.min.js',
                'https://unpkg.com/html5-qrcode@2.3.8/dist/html5-qrcode.min.js'
            ];
            var tryLoad = function(i) {
                if (i >= urls.length) {
                    if (typeof errCb === 'function') errCb();
                    return;
                }
                var s = document.createElement('script');
                s.src = urls[i];
                s.onload = function() { setTimeout(cb, 50); };
                s.onerror = function() { tryLoad(i + 1); };
                document.head.appendChild(s);
            };
            tryLoad(0);
        };

        ensureLib(function() {
            // Try to get cameras and prefer back camera
            if (typeof Html5Qrcode.getCameras === 'function') {
                Html5Qrcode.getCameras().then(function(cameras) {
            var cameraId = null;
            if (Array.isArray(cameras) && cameras.length > 0) {
                // try to find environment/back camera
                for (var i = 0; i < cameras.length; i++) {
                    var cam = cameras[i];
                    if (cam.label && /back|rear|environment/i.test(cam.label)) { cameraId = cam.id; break; }
                }
                if (!cameraId) cameraId = cameras[0].id;
            }
                    var html5Qr = new Html5Qrcode('ffTischScanRegion');
            window._ffTischQrScanner = html5Qr;
            var constraints = cameraId || { facingMode: { exact: 'environment' } };
            var startArg = cameraId || { facingMode: 'environment' };
            html5Qr.start(startArg, { fps: 10, qrbox: 250 }, function(decodedText) {
                _handleTischScanResult(decodedText);
            }, function(errorMessage) {
                // ignore per-frame scan errors
            }).catch(function(err) {
                console.error('html5-qrcode start failed', err);
                alert('Kamera konnte nicht gestartet werden. Stelle sicher, dass die Seite per HTTPS geladen wird und die Kamera‑Berechtigung erlaubt ist.');
                try { m.hide(); } catch (e) {}
                loadListTische();
            });
                }).catch(function(err) {
                    console.error('getCameras failed', err);
                    showScanFallback();
                });
            } else {
                // Older versions: try to start directly
                try {
                    var html5Qr2 = new Html5Qrcode('ffTischScanRegion');
                    window._ffTischQrScanner = html5Qr2;
                    html5Qr2.start({ facingMode: 'environment' }, { fps: 10, qrbox: 250 }, function(decodedText) { _handleTischScanResult(decodedText); }, function() {}).catch(function(err) { console.error(err); showScanFallback(); });
                } catch (e) {
                    console.error('start fallback failed', e);
                    showScanFallback();
                }
            }
        }, function() {
            // Failed to load library from CDN — show fallback UI
            showScanFallback();
        });
    };

    window.stopTischQrScan = function() {
        try {
            if (window._ffTischQrScanner && typeof window._ffTischQrScanner.stop === 'function') {
                window._ffTischQrScanner.stop().then(function() {
                    try { window._ffTischQrScanner.clear(); } catch (e) {}
                }).catch(function() {
                    try { window._ffTischQrScanner.clear(); } catch (e) {}
                });
            }
        } catch (e) {}
        try {
            if (window._ffTischNativeStream) {
                try { window._ffTischNativeStream.getTracks().forEach(function(t){ try{ t.stop(); }catch(e){} }); } catch (e) {}
                window._ffTischNativeStream = null;
            }
            if (window._ffTischNativeVideo) {
                try { var v = document.getElementById('ffTischNativeVideo'); if (v && v.parentNode) v.parentNode.removeChild(v); } catch (e) {}
                window._ffTischNativeVideo = null;
            }
            window._ffTischNativeDetector = null;
        } catch (e) {}
        window._ffTischQrScanner = null;
    };

    function _handleTischScanResult(decodedText) {
        // stop scanner and close modal
        stopTischQrScan();
        try { var scanModal = document.getElementById('ffTischScanModal'); if (scanModal) { var mi = bootstrap.Modal.getInstance(scanModal); if (mi) mi.hide(); } } catch (e) {}
        if (!decodedText) {
            loadListTische();
            return;
        }
        // Try to extract a numeric table id from the scanned text
        var parsed = null;
        try {
            // If the code is a URL with parameter 'tischnummer' or 'tisch'
            var m = decodedText.match(/[?&](?:tischnummer|tisch)=([0-9]+)/i);
            if (m && m[1]) parsed = parseInt(m[1], 10);
            if (!parsed) {
                // any number in the text
                var n = decodedText.match(/(\d+)/);
                if (n && n[1]) parsed = parseInt(n[1], 10);
            }
        } catch (e) {}
        if (parsed && parsed > 0) {
            window.Tischnummer = parsed;
            if (typeof window.tisch === 'function') {
                window.tisch();
                return;
            }
            // fallback: open tisch_anzeigen directly
            ffSetTischInUrl(parsed, 'listTischBestellungen', 'bestellen');
            loadContent('tisch_anzeigen.php?tischnummer=' + encodeURIComponent(parsed), 'listTischBestellungen', function() {
                initTischTabs();
                showPage('listTischBestellungen');
            });
            return;
        }
        // if no parseable ID, show full list
        loadListTische();
    }

    function showScanFallback() {
        try {
            var scanRegion = document.getElementById('ffTischScanRegion');
            var fb = document.getElementById('ffTischScanFallback');
            if (scanRegion) scanRegion.style.display = 'none';
            if (fb) fb.style.display = 'block';
        } catch (e) {}
    }

    window.startTischQrFromImage = function() {
        var fi = document.getElementById('ffTischScanFileInput');
        if (!fi || !fi.files || fi.files.length === 0) { alert('Bitte eine Bilddatei auswählen.'); return; }
        var file = fi.files[0];
        if (typeof Html5Qrcode === 'undefined') {
            // Try to load library then scan (jsDelivr then unpkg)
            var urls = [
                'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js',
                'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/dist/html5-qrcode.min.js',
                'https://unpkg.com/html5-qrcode@2.3.8/dist/html5-qrcode.min.js'
            ];
            var tryLoadFile = function(i) {
                if (i >= urls.length) { alert('QR‑Bibliothek konnte nicht geladen werden.'); return; }
                var s = document.createElement('script');
                s.src = urls[i];
                s.onload = function() { setTimeout(function(){ startTischQrFromImage(); },50); };
                s.onerror = function() { tryLoadFile(i + 1); };
                document.head.appendChild(s);
            };
            tryLoadFile(0);
            return;
        }
        var html5Qr = new Html5Qrcode(/* element id not needed for file scan */ 'ffTischScanRegion');
        html5Qr.scanFile(file, true).then(function(decodedText) {
            _handleTischScanResult(decodedText);
        }).catch(function(err) {
            alert('QR aus Bild konnte nicht gelesen werden.');
        });
    };

    window._handleManualTischEntry = function() {
        var el = document.getElementById('ffTischScanManualInput');
        if (!el) return;
        var v = parseInt(el.value, 10) || 0;
        if (v <= 0) { alert('Ungültige Tischnummer'); return; }
        _handleTischScanResult(String(v));
    };

    /* Check camera permission on main page load to trigger browser prompt early */
    window.ffCheckCameraPermission = function() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
        // Track camera permission state so callers (e.g. TischAnsicht/startTischQrScan)
        // can decide to fall back to the classic table list when access is denied.
        window._ffCameraPermissionState = window._ffCameraPermissionState || null; // 'granted'|'prompt'|'denied'
        window._ffCameraPermissionDenied = !!window._ffCameraPermissionDenied;

        var tryGet = function() {
            return navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(function(stream) {
                    try { stream.getTracks().forEach(function(t){ try{ t.stop(); }catch(e){} }); } catch (e) {}
                    window._ffCameraPermissionState = 'granted';
                    window._ffCameraPermissionDenied = false;
                })
                .catch(function(err) {
                    // denied or not available
                    try { if (err && err.name === 'NotAllowedError') { window._ffCameraPermissionState = 'denied'; window._ffCameraPermissionDenied = true; } } catch (e) {}
                });
        };

        if (navigator.permissions && typeof navigator.permissions.query === 'function') {
            try {
                navigator.permissions.query({ name: 'camera' }).then(function(result) {
                    if (result) {
                        window._ffCameraPermissionState = result.state || window._ffCameraPermissionState;
                        window._ffCameraPermissionDenied = (result.state === 'denied');
                        if (result.state === 'prompt') {
                            // trigger prompt so browser asks user early
                            tryGet();
                        }
                        // listen for future changes
                        try {
                            result.onchange = function() {
                                try { window._ffCameraPermissionState = result.state || null; window._ffCameraPermissionDenied = (result.state === 'denied'); } catch (e) {}
                            };
                        } catch (e) {}
                    }
                }).catch(function() {
                    // permissions API may not support 'camera' — fallback to direct getUserMedia
                    tryGet();
                });
            } catch (e) {
                tryGet();
            }
        } else {
            // no permissions API — try direct getUserMedia to prompt
            tryGet();
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        // Run permission check once when main document loads
        try { window.ffCheckCameraPermission(); } catch (e) {}
    });

    /**
     * Dialog mit Buttons „Ja“ / „Nein“ (Bootstrap-Modal).
     * @returns {Promise<boolean>} true = Ja, false = Nein / Schließen
     */
    window.ffConfirmJaNein = function(message, title) {
        var modal = document.getElementById('ffConfirmJaNeinModal');
        if (!modal || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return Promise.resolve(window.confirm(String(message || '')));
        }
        var bodyEl = document.getElementById('ffConfirmJaNeinBody');
        var titleEl = document.getElementById('ffConfirmJaNeinTitle');
        var btnJa = document.getElementById('ffConfirmJaNeinJa');
        var btnNein = document.getElementById('ffConfirmJaNeinNein');
        if (titleEl) {
            titleEl.textContent = title || 'Hinweis';
        }
        if (bodyEl) {
            bodyEl.style.whiteSpace = 'pre-line';
            bodyEl.textContent = String(message || '');
        }
        return new Promise(function(resolve) {
            var settled = false;
            function finish(val) {
                if (settled) {
                    return;
                }
                settled = true;
                var inst = bootstrap.Modal.getInstance(modal);
                if (inst) {
                    inst.hide();
                }
                resolve(!!val);
            }
            if (btnJa) {
                btnJa.onclick = function() { finish(true); };
            }
            if (btnNein) {
                btnNein.onclick = function() { finish(false); };
            }
            function onHidden() {
                modal.removeEventListener('hidden.bs.modal', onHidden);
                finish(false);
            }
            modal.addEventListener('hidden.bs.modal', onHidden);
            function onShown() {
                modal.removeEventListener('shown.bs.modal', onShown);
                if (btnNein) {
                    btnNein.focus();
                }
            }
            modal.addEventListener('shown.bs.modal', onShown);
            bootstrap.Modal.getOrCreateInstance(modal).show();
        });
    };

    /** Tisch verlassen: Hinweis bei nicht abgeschickten Positionen (optional löschen). */
    window.ffLeaveTischToOverview = function() {
        if (ffIsRequirePaymentLocked()) {
            ffRedirectToRequiredPayment();
            return;
        }
        var t = parseInt(window._ffTischUnsentTable, 10) || parseInt(window.Tischnummer, 10) || 0;
        var n = parseInt(window._ffTischUnsentCount, 10) || 0;
        if (t > 0 && n > 0) {
            var msg = 'Es sind noch ' + n + ' Position(en), die nicht bestellt wurden.\n\n'
                + 'Ja: alle entfernen\n'
                + 'Nein: trotzdem verlassen';
            ffConfirmJaNein(msg, 'Tisch verlassen').then(function(ja) {
                if (!ja) {
                    TischAnsicht();
                    return;
                }
                var fd = 'tischnummer=' + encodeURIComponent(String(t));
                fetchPost('bestellung_unsent_clear.php', fd)
                    .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
                    .then(function(x) {
                        if (!x.ok || !x.j || !x.j.ok) {
                            alert((x.j && x.j.message) ? x.j.message : 'Entfernen fehlgeschlagen.');
                            return;
                        }
                        window._ffTischUnsentCount = 0;
                        TischAnsicht();
                    })
                    .catch(function() { alert('Netzwerkfehler beim Entfernen.'); });
            });
            return;
        }
        TischAnsicht();
    };

    /* Spaltenlayout Küche/Schank/Druckziel: Admin-Standard + pro Terminal (localStorage) */
    var FF_STATION_COLS_LS = 'ff_station_cols';
    var FF_STATION_COLS_MOBILE_LS = 'ff_station_cols_mobil';

    function ffStationIsMobileViewport() {
        return Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0) <= 992;
    }

    function ffStationColsMax() {
        return ffStationIsMobileViewport() ? 2 : 6;
    }

    function ffStationColsAdminDefault() {
        if (ffStationIsMobileViewport()) {
            return typeof window.FF_STATION_COLS_MOBILE_DEFAULT === 'number' ? window.FF_STATION_COLS_MOBILE_DEFAULT : 0;
        }
        return typeof window.FF_STATION_COLS_DEFAULT === 'number' ? window.FF_STATION_COLS_DEFAULT : 0;
    }

    function ffStationColsLsKey() {
        return ffStationIsMobileViewport() ? FF_STATION_COLS_MOBILE_LS : FF_STATION_COLS_LS;
    }

    function ffStationColsReadLs() {
        try {
            var raw = localStorage.getItem(ffStationColsLsKey());
            if (raw === null || raw === '') return null;
            var n = parseInt(raw, 10);
            return isNaN(n) ? null : n;
        } catch (e) {
            return null;
        }
    }

    function ffStationColsEffective() {
        var ls = ffStationColsReadLs();
        if (ls !== null) return ls;
        return ffStationColsAdminDefault();
    }

    function ffStationColsWriteLs(n) {
        try { localStorage.setItem(ffStationColsLsKey(), String(n)); } catch (e) { /* ignore */ }
    }

    function ffStationColsClearLs() {
        try { localStorage.removeItem(ffStationColsLsKey()); } catch (e) { /* ignore */ }
    }

    function ffStationColsLabelText(n) {
        return n === 0 ? 'Auto' : String(n);
    }

    function ffStationColsApplyToEl(el, cols) {
        if (!el) return;
        if (cols > 0) {
            el.classList.add('ff-station-cols-fixed');
            el.style.setProperty('--station-cols', String(cols));
        } else {
            el.classList.remove('ff-station-cols-fixed');
            el.style.removeProperty('--station-cols');
        }
    }

    function ffStationColsCollectContainers() {
        var containers = [];
        var root = document.getElementById('DruckzielContent');
        if (root) {
            var list = root.querySelectorAll('[id^="druckzielOrders"].kueche-orders-flow');
            for (var i = 0; i < list.length; i++) containers.push(list[i]);
        }
        var k = document.getElementById('kuecheOrders');
        var s = document.getElementById('schankOrders');
        if (k) containers.push(k);
        if (s) containers.push(s);
        return containers;
    }

    function ffStationColsApplyAll() {
        var cols = ffStationColsEffective();
        var label = document.getElementById('ffStationColsLabel');
        if (label) label.textContent = ffStationColsLabelText(cols);
        var containers = ffStationColsCollectContainers();
        for (var i = 0; i < containers.length; i++) {
            ffStationColsApplyToEl(containers[i], cols);
        }
    }

    window.applyDruckzielCols = function(printTargetId) {
        ffStationColsApplyAll();
    };

    window.ffStationColsDelta = function(delta) {
        var max = ffStationColsMax();
        var cur = ffStationColsEffective();
        var next;
        if (delta > 0) {
            next = cur === 0 ? 1 : (cur >= max ? 0 : cur + 1);
        } else {
            next = cur === 0 ? max : (cur <= 1 ? 0 : cur - 1);
        }
        ffStationColsWriteLs(next);
        ffStationColsApplyAll();
    };

    window.ffStationColsReset = function() {
        ffStationColsClearLs();
        ffStationColsApplyAll();
    };

    if (!window._ffStationColsResizeBound) {
        window._ffStationColsResizeBound = true;
        window.addEventListener('resize', function() {
            clearTimeout(window._ffStationColsResizeTimer);
            window._ffStationColsResizeTimer = setTimeout(function() {
                if (typeof window.applyDruckzielCols === 'function') {
                    window.applyDruckzielCols(window.currentDruckzielId || 0);
                }
            }, 150);
        }, { passive: true });
    }

    window.toggleDruckzielCols = function() {
        window.ffStationColsDelta(1);
    };
    window._ffStationPollSig = window._ffStationPollSig || {};

    function ffStationPollSignatureFromJson(j, printTargetId) {
        if (!j) return null;
        var pt = parseInt(printTargetId, 10) || 0;
        var open = 0;
        if (typeof j.open === 'number' && pt > 0) {
            open = j.open;
        } else if (pt === 12) {
            open = parseInt(j.schank_open, 10) || 0;
        } else if (pt === 11) {
            open = parseInt(j.kueche_open, 10) || 0;
        }
        // last_order (höchste Bestellnummer) erzwingt einen Reload, sobald eine neue Runde
        // abgeschickt wurde – auch wenn die offene Anzahl gleich bleibt.
        var lastOrder = (typeof j.last_order !== 'undefined') ? String(j.last_order) : '0';
        var sig = String(open) + '|' + String(j.last || '') + '|o' + lastOrder;
        if (pt > 0 && j.station_rev) {
            sig += '|r' + String(j.station_rev);
        }
        return sig;
    }

    function ffStationPollFetchSignature(printTargetId) {
        var pt = parseInt(printTargetId, 10) || 0;
        var url = 'status_counts.php';
        if (pt > 0) url += '?print_target=' + encodeURIComponent(String(pt));
        return fetch(url, { cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(j) { return ffStationPollSignatureFromJson(j, pt); })
            .catch(function() { return null; });
    }

    function ffClearStationPollSignature(printTargetId) {
        var pt = parseInt(printTargetId, 10) || 0;
        if (pt > 0) delete window._ffStationPollSig[pt];
    }

    /** Küche/Schank-Poll: nur Listen + Sidebar tauschen (Navbar bleibt), Scroll bleibt. */
    function ffPatchStationPollContent(html, printTargetId) {
        var root = document.getElementById('DruckzielContent');
        if (!root || !html) return false;
        var pt = parseInt(printTargetId, 10) || 0;
        var ordersId = 'druckzielOrders' + pt;
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        var newOrders = wrap.querySelector('#' + ordersId);
        var oldOrders = document.getElementById(ordersId);
        if (!newOrders || !oldOrders) return false;

        var scrollY = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
        var oldMain = oldOrders.closest('.kueche-main');
        var newMain = wrap.querySelector('.kueche-main');
        if (newMain && oldMain) {
            var newSummary = newMain.querySelector('.kueche-inline-summary');
            var oldSummary = oldMain.querySelector('.kueche-inline-summary');
            if (newSummary && oldSummary) {
                oldSummary.replaceWith(newSummary.cloneNode(true));
            } else if (newSummary && !oldSummary) {
                oldMain.insertBefore(newSummary.cloneNode(true), oldOrders);
            } else if (!newSummary && oldSummary) {
                oldSummary.remove();
            }
        }

        oldOrders.innerHTML = newOrders.innerHTML;

        var oldLayout = oldOrders.closest('.ff-station-layout') || oldOrders.closest('.kueche-layout');
        var newLayout = wrap.querySelector('.ff-station-layout') || wrap.querySelector('.kueche-layout');
        if (oldLayout && newLayout) {
            oldLayout.classList.toggle('ff-station-layout--no-sidebar', newLayout.classList.contains('ff-station-layout--no-sidebar'));
        }

        var newSidebar = wrap.querySelector('.ff-station-sidebar');
        var oldSidebar = root.querySelector('.ff-station-sidebar');
        if (newSidebar && oldSidebar) {
            oldSidebar.innerHTML = newSidebar.innerHTML;
            var sideStyle = newSidebar.getAttribute('style');
            if (sideStyle) oldSidebar.setAttribute('style', sideStyle);
        } else if (newSidebar && !oldSidebar && oldLayout) {
            oldLayout.appendChild(newSidebar.cloneNode(true));
        } else if (!newSidebar && oldSidebar) {
            oldSidebar.remove();
        }

        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                window.scrollTo(0, scrollY);
            });
        });
        return true;
    }

    function ffLoadOperationsPoll(url, printTargetId, callback) {
        var pt = parseInt(printTargetId, 10) || 0;
        ffStationPollFetchSignature(pt).then(function(sig) {
            if (sig && window._ffStationPollSig[pt] === sig) {
                if (typeof callback === 'function') callback();
                return;
            }
            fetch(url, { cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.text();
                })
                .then(function(html) {
                    if (!html || html.length < 50) throw new Error('Leere Antwort');
                    if (html.indexOf('login.php') !== -1 || html.indexOf('Kennwort') !== -1) {
                        window.location.href = 'login.php' + (window.location.hash ? '?redirect=' + encodeURIComponent(window.location.hash) : '');
                        return;
                    }
                    if (!ffPatchStationPollContent(html, pt)) {
                        loadContent(url, 'DruckzielContent', callback, { silentPoll: true });
                        return;
                    }
                    if (sig) window._ffStationPollSig[pt] = sig;
                    executeScriptsIn(document.getElementById('DruckzielContent'), callback);
                })
                .catch(function(err) {
                    console.error('ffLoadOperationsPoll:', url, err);
                    if (typeof callback === 'function') callback();
                });
        });
    }

    window.ffWakeUpPolls = function() {
        if (window._pauseOperationsPoll) return;
        if (getActivePageId() === 'DruckzielAnsicht' && window.currentDruckzielId) {
            ffClearStationPollSignature(window.currentDruckzielId);
            window.DruckzielAnsichtAutoReload(window.currentDruckzielId);
        }
    };

    window.DruckzielAnsichtAutoReload = function(printTargetId) {
        if (getActivePageId() !== 'DruckzielAnsicht' || window.currentDruckzielId !== printTargetId) return;
        if (window._pauseOperationsPoll) {
            setTimeout(function() { window.DruckzielAnsichtAutoReload(printTargetId); }, 800);
            return;
        }
        var url = 'list_druckziel.php?print_target=' + encodeURIComponent(printTargetId);
        var el = document.getElementById('DruckzielContent');
        if (!el || !el.querySelector('[id^="druckzielOrders"]')) {
            loadContent(url, 'DruckzielContent', function() {
                window.applyDruckzielCols(printTargetId);
                ffScheduleOperationsPoll(printTargetId);
            }, { silentPoll: !!(el && el.children.length > 0) });
            return;
        }
        ffLoadOperationsPoll(url, printTargetId, function() {
            window.applyDruckzielCols(printTargetId);
            ffScheduleOperationsPoll(printTargetId);
        });
    };

    function ffScheduleOperationsPoll(printTargetId) {
        if (getActivePageId() === 'DruckzielAnsicht' && window.currentDruckzielId === printTargetId) {
            setTimeout(function() { window.DruckzielAnsichtAutoReload(printTargetId); }, _delay(OPERATIONS_POLL_INTERVAL_MS));
        }
    }

    window.DruckzielAnsicht = function(printTargetId, name) {
        window.currentDruckzielId = printTargetId;
        try { sessionStorage.setItem('lastDruckzielId', String(printTargetId)); } catch(e) {}
        var url = 'list_druckziel.php?print_target=' + encodeURIComponent(printTargetId);
        if (name) url += '&name=' + encodeURIComponent(name);
        showPage('DruckzielAnsicht', 'DruckzielAnsicht_' + printTargetId);
        ffClearStationPollSignature(printTargetId);
        loadContent(url, 'DruckzielContent', function() {
            window.applyDruckzielCols(printTargetId);
            ffScheduleOperationsPoll(printTargetId);
        });
    };

    window.DruckzielHistory = function(printTargetId) {
        showPage('DruckzielHistory');
        loadContent('history_druckziel.php?print_target=' + encodeURIComponent(printTargetId), 'DruckzielHistoryContent');
    };

    window.DruckzielAnsichtRefresh = function() {
        var pid = window.currentDruckzielId;
        if (!pid) return;
        ffClearStationPollSignature(pid);
        var url = 'list_druckziel.php?print_target=' + encodeURIComponent(pid);
        var el = document.getElementById('DruckzielContent');
        if (el && el.querySelector('[id^="druckzielOrders"]')) {
            ffLoadOperationsPoll(url, pid, function() {
                window.applyDruckzielCols(pid);
            });
            return;
        }
        loadContent(url, 'DruckzielContent', function() {
            window.applyDruckzielCols(pid);
        }, { silentPoll: !!(el && el.children.length > 0) });
    };

    window.tisch = function() {
        var t = parseInt(window.Tischnummer, 10) || 0;
        if (t <= 0) return;
        var reqPay = ffIsRequirePaymentLocked();
        var returnView = reqPay ? 'zahlen' : 'bestellen';
        ffSetTischInUrl(t, 'listTischBestellungen', 'bestellen');
        ffPersistTischHistorieReturn(returnView, reqPay, t);
        var shellEl = $('listTischBestellungen');
        var shellOpts = ffTischShellReady() ? { silentPoll: true } : {};
        loadContent('tisch_anzeigen.php?tischnummer=' + t, 'listTischBestellungen', function() {
            initTischTabs();
            showPage('listTischBestellungen');
        }, shellOpts);
    };

    window.tischnr = function(nummer) {
        if (ffIsRequirePaymentLocked()) {
            ffRedirectToRequiredPayment();
            return;
        }
        var t = parseInt(nummer, 10) || 0;
        if (t <= 0) return;
        window.Tischnummer = t;
        ffSetTischInUrl(t, 'listTischBestellungen', 'bestellen');
        var shellEl = $('listTischBestellungen');
        var shellOpts = (shellEl && shellEl.children.length > 0) ? { silentPoll: true } : {};
        loadContent('tisch_anzeigen.php?tischnummer=' + t, 'listTischBestellungen', function() {
            initTischTabs();
            showPage('listTischBestellungen');
        }, shellOpts);
    };

    /**
     * Getränke ↔ Speisen: horizontale Wischgeste auf dem Tab-Inhalt (vor allem Handy).
     * @param {HTMLElement} root Eltern-Element, das die Tab-Buttons [data-bs-toggle="tab"] enthält
     * @param {HTMLElement} tabContent .tab-content mit den .tab-pane
     */
    function ffBindSpeisenGetraenkeTabSwipe(root, tabContent) {
        if (!root || !tabContent || tabContent.getAttribute('data-ff-swipe-tabs') === '1') return;
        var triggers = Array.prototype.slice.call(root.querySelectorAll('[data-bs-toggle="tab"]')).filter(function(t) {
            return t.getAttribute('data-bs-target') && root.contains(t);
        });
        if (triggers.length < 2) return;
        tabContent.setAttribute('data-ff-swipe-tabs', '1');

        var minDist = 48;
        var ratio = 1.25;
        var startX = null;
        var startY = null;

        /** Wisch auch ab Positions-Kacheln (button): bei klarem Horizontal-Wisch Tab wechseln, sonst normal tippen */
        function swipeTrackTargetOk(el) {
            if (!el || !el.closest) return false;
            if (el.closest('input, textarea, select')) return false;
            if (el.closest('[data-ff-no-tab-swipe]')) return false;
            return true;
        }

        function showTabIndex(idx) {
            if (idx < 0 || idx >= triggers.length) return;
            var btn = triggers[idx];
            var TabApi = window.bootstrap && window.bootstrap.Tab;
            if (TabApi && typeof TabApi.getOrCreateInstance === 'function') {
                TabApi.getOrCreateInstance(btn).show();
            } else {
                btn.click();
            }
        }

        tabContent.addEventListener('touchstart', function(e) {
            if (!swipeTrackTargetOk(e.target)) {
                startX = null;
                startY = null;
                return;
            }
            if (!e.touches || !e.touches[0]) return;
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        }, { passive: true });

        tabContent.addEventListener('touchcancel', function() {
            startX = null;
            startY = null;
        }, { passive: true });

        tabContent.addEventListener('touchend', function(e) {
            if (startX == null || startY == null) return;
            if (!e.changedTouches || !e.changedTouches[0]) {
                startX = null;
                startY = null;
                return;
            }
            var x = e.changedTouches[0].clientX;
            var y = e.changedTouches[0].clientY;
            var dx = x - startX;
            var dy = y - startY;
            startX = null;
            startY = null;
            if (Math.abs(dx) < minDist) return;
            if (Math.abs(dx) < Math.abs(dy) * ratio) return;

            var activeIdx = -1;
            for (var i = 0; i < triggers.length; i++) {
                if (triggers[i].classList.contains('active')) {
                    activeIdx = i;
                    break;
                }
            }
            if (activeIdx < 0) return;
            var nextIdx = dx < 0 ? Math.min(activeIdx + 1, triggers.length - 1) : Math.max(activeIdx - 1, 0);
            if (nextIdx !== activeIdx) {
                showTabIndex(nextIdx);
                e.preventDefault();
            }
        }, { passive: false });
    }

    function initTischTabs() {
        var container = $('listTischBestellungen');
        if (!container) return;
        var tabContent = container.querySelector('#TischAnzeigenContent');
        if (!tabContent) return;
        var orderMain = container.querySelector('.tisch-order-main');
        if (orderMain) ffBindSpeisenGetraenkeTabSwipe(orderMain, tabContent);
        var loadTab = function(pane, force) {
            var url = pane.getAttribute('data-load-url');
            var inner = pane.querySelector('.tab-content-inner');
            if (!url || !inner) return;
            if (!force && inner.getAttribute('data-loaded') === '1') return;
            inner.removeAttribute('data-loaded');
            var tabHadContent = inner.children.length > 0 && (inner.textContent || '').trim() !== '';
            if (!tabHadContent) {
                inner.innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm text-secondary" role="status"></div></div>';
            }
            fetch(url, { cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    inner.innerHTML = html;
                    inner.setAttribute('data-loaded', '1');
                    runScriptsInElement(inner);
                })
                .catch(function() { inner.innerHTML = '<div class="alert alert-danger">Fehler beim Laden</div>'; });
        };
        var runScriptsInElement = function(el) {
            if (!el) return;
            el.querySelectorAll('script').forEach(function(s) {
                var n = document.createElement('script');
                if (s.src) n.src = s.src; else n.textContent = s.textContent;
                s.parentNode.replaceChild(n, s);
            });
        };
        var panes = tabContent.querySelectorAll('.tab-pane[data-load-url]');
        panes.forEach(function(pane) { loadTab(pane); });
        tabContent.querySelectorAll('[data-bs-toggle="tab"]').forEach(function(btn) {
            btn.addEventListener('shown.bs.tab', function(e) {
                var target = document.querySelector(e.target.getAttribute('data-bs-target'));
                if (target && target.dataset.loadUrl) loadTab(target, false);
            });
        });

    }

    window.AdminAnsicht = function() {
        showPage('adminPage');
        var el = $('adminPage');
        if (el) {
            el.innerHTML = '<div class="p-2 border-bottom bg-light"><a href="#indexPage" class="btn btn-link" onclick="if(typeof ffNavigateHome===\'function\'){ffNavigateHome();}else{showPage(\'indexPage\');} return false;">&larr; Zur&uuml;ck</a></div><iframe src="admin.php?embedded=1" style="width:100%;min-height:calc(100vh - 120px);border:none;" title="Admin"></iframe>';
        }
    };

    window.FinanzAnsicht = function() {
        showPage('financePage');
        loadContent('finance_page.php', 'financePage', function() {
            if (typeof window.ffFinanceInit === 'function') {
                window.ffFinanceInit();
            }
            if (typeof window.gewinnAktualisieren === 'function') {
                window.gewinnAktualisieren();
            }
        });
    };

    window.myOrdersAnsicht = function() {
        showPage('myOrdersPage');
        loadContent('myOrders.php', 'myOrdersPage');
    };

    window.bestellHistoryAnsicht = function() {};

    function ffDvBonMatchesToday(bonId) {
        var s = String(bonId || '').trim();
        var m = s.match(/^(\d{2})-\d{3}$/);
        if (!m) return false;
        return parseInt(m[1], 10) === (new Date()).getDate();
    }

    window.Direktverkauf = function() {
        if (window.FF_CAN_DIREKTVERKAUF !== 1) {
            alert('Keine Berechtigung für den Direktverkauf.');
            return;
        }
        showPage('Direktverkauf');
        var dvUrl = 'direktverkauf.php';
        try {
            var storedBon = localStorage.getItem('direktverkauf_bon_id');
            if (storedBon && String(storedBon).trim() !== '' && ffDvBonMatchesToday(storedBon)) {
                dvUrl += '?bon_id=' + encodeURIComponent(String(storedBon).trim());
            } else if (storedBon && !ffDvBonMatchesToday(storedBon)) {
                localStorage.removeItem('direktverkauf_bon_id');
            }
        } catch (e) { /* ignore */ }
        loadContent(dvUrl, 'Direktverkauf', function() {
            initDirektverkaufTabs();
        });
    };

    window.MitarbeiterVerpflegungAnsicht = function() {
        showPage('MitarbeiterVerpflegungPage');
        loadContent('mitarbeiter_verpflegung.php', 'MitarbeiterVerpflegungPage');
    };

    window.ffDvBonQuery = function() {
        var bon = typeof getDirektverkaufBonId === 'function' ? getDirektverkaufBonId() : '';
        return bon ? ('&bon_id=' + encodeURIComponent(bon)) : '';
    };

    window.ffDvZahlenQuery = function(extra) {
        var q = window.ffDvBonQuery().replace(/^&/, '');
        if (extra) {
            q = q ? (q + '&' + extra) : extra;
        }
        return q;
    };

    window.ffDvBindPaybar = function() {
        var idsEl = document.getElementById('ffDvPayIds');
        var payBtn = document.getElementById('ffDvCartPayBtn');
        var footer = document.getElementById('ffDvCartOffcanvasFooter');
        var badge = document.getElementById('ffDvCartBadge');
        var cntEl = document.getElementById('ffDvPayCountText');
        if (badge && cntEl) {
            var m = (cntEl.textContent || '').match(/\((\d+)\s+Pos\.\)/);
            if (m) badge.textContent = m[1];
        }
        if (!idsEl || !payBtn || !footer) return;
        var raw = idsEl.getAttribute('data-ids') || '[]';
        var ids;
        try {
            ids = JSON.parse(raw);
        } catch (e) {
            ids = [];
        }
        if (ids.length > 0) {
            footer.classList.remove('d-none');
            payBtn.onclick = function() { window.ffDvBezahlenConfirm(ids); };
        } else {
            footer.classList.add('d-none');
        }
    };

    window.ffDvRefreshCart = function() {
        var body = document.getElementById('ffDvCartOffcanvasBody');
        if (!body) return;
        var q = window.ffDvZahlenQuery('partial=cart');
        fetch('zahlen_direktverkauf.php' + (q ? '?' + q : ''), {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                body.innerHTML = html;
            })
            .catch(function() {
                body.innerHTML = '<p class="text-danger small">Warenkorb konnte nicht geladen werden.</p>';
            });
    };

    window.ffDvToggleCart = function(open) {
        var el = document.getElementById('ffDvCartOffcanvas');
        if (!el || typeof bootstrap === 'undefined' || !bootstrap.Offcanvas) {
            window.ffDvRefreshCart();
            return;
        }
        var oc = bootstrap.Offcanvas.getOrCreateInstance(el);
        if (open) {
            window.ffDvRefreshCart();
            oc.show();
        } else {
            oc.hide();
        }
    };

    var _ffDvPaybarTimer = null;
    function ffDvRefreshPaybarNow() {
        var mount = document.getElementById('ffDvPaybar');
        if (!mount) return;
        var q = window.ffDvZahlenQuery('');
        fetch('zahlen_direktverkauf.php' + (q ? '?' + q : ''), {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                mount.innerHTML = html;
                window.ffDvBindPaybar();
            })
            .catch(function() {
                mount.innerHTML = '<div class="alert alert-warning py-2 small mb-0">Summe konnte nicht geladen werden.</div>';
            });
    }
    /** Summen-Leiste (klein); mehrfache Klicks werden zusammengefasst. */
    window.ffDvRefreshPaybar = function() {
        if (_ffDvPaybarTimer) clearTimeout(_ffDvPaybarTimer);
        _ffDvPaybarTimer = setTimeout(function() {
            _ffDvPaybarTimer = null;
            ffDvRefreshPaybarNow();
        }, 120);
    };

    window.ffDvRefreshActiveTab = function() {
        var container = document.getElementById('Direktverkauf');
        if (!container) return;
        var activePane = container.querySelector('#DirektverkaufTabContent .tab-pane.active');
        if (!activePane) return;
        var inner = activePane.querySelector('.tab-content-inner');
        var url = activePane.getAttribute('data-load-url');
        if (!inner || !url) return;
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        var fullUrl = url + (typeof window.ffDvBonQuery === 'function' ? sep + window.ffDvBonQuery().replace(/^&/, '') : '');
        fetch(fullUrl, { cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                inner.innerHTML = html;
                if (typeof window.ffExecuteScriptsInContainer === 'function') {
                    window.ffExecuteScriptsInContainer(inner);
                }
            });
    };

    /**
     * DV nach Klick: standard nur Paybar (+ optional Warenkorb), kein Tab-Reload.
     * @param {{paybar?:boolean,tab?:boolean,cart?:boolean}} opts
     */
    window.ffDvQuickRefresh = function(opts) {
        opts = opts || {};
        var paybar = opts.paybar !== false;
        var tab = opts.tab === true;
        var cart = opts.cart === true;
        if (paybar) window.ffDvRefreshPaybar();
        var el = document.getElementById('ffDvCartOffcanvas');
        if (cart || (el && el.classList.contains('show'))) {
            window.ffDvRefreshCart();
        }
        if (tab) window.ffDvRefreshActiveTab();
    };

    window.ffDvBezahlenConfirm = function(arrayListe) {
        if (!Array.isArray(arrayListe) || arrayListe.length === 0) {
            alert('Keine Positionen zum Bezahlen.');
            return;
        }
        var summeEl = document.getElementById('ffDvPaySummeText');
        var cntEl = document.getElementById('ffDvPayCountText');
        var summe = summeEl ? summeEl.textContent.trim() : '';
        var cnt = cntEl ? cntEl.textContent.trim() : (arrayListe.length + ' Positionen');
        if (!confirm('Jetzt bezahlen?\n\n' + cnt + '\nSumme: ' + summe + '\n\nDer Warenkorb wird geleert (neuer Bon).')) {
            return;
        }
        BestellungBezahlt(arrayListe, 1);
    };

    /** Eine Portion aus aggregierter Warenkorb-Zeile entfernen (neueste rowid der Gruppe). */
    window.ffDvWarenkorbMinusAgg = function(rowsJson, positionId, type) {
        var rows = rowsJson;
        if (typeof rowsJson === 'string') {
            try {
                rows = JSON.parse(rowsJson);
            } catch (e) {
                rows = [];
            }
        }
        if (!Array.isArray(rows) || rows.length === 0) {
            return;
        }
        var rid = parseInt(rows[rows.length - 1], 10) || 0;
        window.ffDvWarenkorbMinus(rid, positionId, type);
    };

    window.ffDvWarenkorbMinus = function(rowid, positionId, type) {
        if (rowid > 0) {
            var fd = new FormData();
            fd.append('rowid', String(rowid));
            fetch('bestellung_dv_row_remove.php', { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' })
                .then(function(r) { return r.json(); })
                .then(function(j) {
                    if (!j || !j.ok) {
                        alert((j && j.message) ? j.message : 'Entfernen fehlgeschlagen.');
                        return;
                    }
                    ffDvAfterCartChange(j);
                })
                .catch(onError);
            return;
        }
        minusPosition({ preventDefault: function() {} }, 999999, positionId, type);
    };

    window.ffDvRefreshAll = function() {
        window.ffDvQuickRefresh({ paybar: true, tab: true, cart: true });
    };

    function initDirektverkaufTabs() {
        var container = $('Direktverkauf');
        if (!container) return;

        var paybar = document.getElementById('ffDvPaybar');
        if (paybar && paybar.getAttribute('data-ssr') === '1') {
            if (typeof window.ffDvBindPaybar === 'function') {
                window.ffDvBindPaybar();
            }
        } else if (typeof window.ffDvRefreshPaybar === 'function') {
            window.ffDvRefreshPaybar();
        }

        var tabContent = container.querySelector('#DirektverkaufTabContent');
        if (!tabContent) return;

        var orderMain = container.querySelector('.app-content');
        if (orderMain && tabContent) {
            ffBindSpeisenGetraenkeTabSwipe(container, tabContent);
        }

        var loadTab = function(pane, force) {
            if (!pane) return;
            var url = pane.getAttribute('data-load-url');
            var inner = pane.querySelector('.tab-content-inner');
            if (!url || !inner) return;
            if (!force && inner.getAttribute('data-loaded') === '1') return;
            inner.removeAttribute('data-loaded');
            var sep = url.indexOf('?') >= 0 ? '&' : '?';
            var fullUrl = url + (typeof window.ffDvBonQuery === 'function' ? sep + window.ffDvBonQuery().replace(/^&/, '') : '');
            fetch(fullUrl, { cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    inner.innerHTML = html;
                    inner.setAttribute('data-loaded', '1');
                    if (typeof window.ffExecuteScriptsInContainer === 'function') {
                        window.ffExecuteScriptsInContainer(inner);
                    } else if (typeof executeScriptsIn === 'function') {
                        executeScriptsIn(inner);
                    }
                })
                .catch(function() { inner.innerHTML = '<div class="alert alert-danger py-2 small mb-0">Fehler beim Laden</div>'; });
        };

        tabContent.querySelectorAll('.tab-pane[data-load-url]').forEach(function(pane) {
            loadTab(pane, false);
        });

        container.querySelectorAll('[data-bs-toggle="tab"]').forEach(function(btn) {
            btn.addEventListener('shown.bs.tab', function(e) {
                var targetSelector = e.target.getAttribute('data-bs-target');
                if (!targetSelector) return;
                var target = container.querySelector(targetSelector);
                if (target) loadTab(target, false);
            });
        });

        ffBindSpeisenGetraenkeTabSwipe(container, tabContent);

        var dvReset = container.querySelector('#ffDvResetBtn');
        if (dvReset && dvReset.getAttribute('data-ff-dv-reset-bound') !== '1') {
            dvReset.setAttribute('data-ff-dv-reset-bound', '1');
            dvReset.addEventListener('click', function(ev) {
                ev.preventDefault();
                if (typeof window.Direkt_reset === 'function') window.Direkt_reset();
            });
        }
    }

    window.TischBezahlen = function(tischnummer, requirePayment) {
        if (typeof tischnummer !== "undefined" && tischnummer !== null) {
            window.Tischnummer = parseInt(tischnummer, 10) || window.Tischnummer;
        }
        var t = parseInt(window.Tischnummer, 10) || 0;
        if (t <= 0) return;
        ffSetTischInUrl(t, 'listTischBestellungen', 'zahlen');
        window._tischHistorieReturn = 'zahlen';
        ffPersistTischHistorieReturn('zahlen', !!requirePayment, t);
        ffLoadTischZahlen(t, !!requirePayment);
    };

    window.TischAnsichtHistory = function(explicitReturn) {
        var t = parseInt(window.Tischnummer, 10) || 0;
        var curView = ffGetTischViewFromUrl();
        var reqPay = !!window._requirePaymentActive || ffGetTischHistorieRequirePayment();
        if (curView !== 'historie') {
            var ret = (explicitReturn === 'zahlen' || explicitReturn === 'rechnung' || explicitReturn === 'bestellen')
                ? explicitReturn : ffDetectTischSubView();
            if (reqPay && ret === 'bestellen' && ffGetTischHistorieReturn() === 'zahlen') {
                ret = 'zahlen';
            }
            ffPersistTischHistorieReturn(ret, reqPay, t);
        } else if (reqPay) {
            ffPersistTischHistorieReturn(ffGetTischHistorieReturn(), true, t);
        }
        if (t > 0) ffSetTischInUrl(t, 'listTischBestellungen', 'historie');
        loadContent('list_Bestellungen.php?tischnummer=' + Tischnummer, 'Bestellungen');
    };

    window.TischHistorieZurueck = function() {
        var ret = ffGetTischHistorieReturn();
        var t = parseInt(window.Tischnummer, 10) || 0;
        ffSyncTischRequirePaymentFromServer(t).then(function(needPay) {
            if (ret === 'zahlen') {
                if (needPay && typeof window.TischBezahlen === 'function') {
                    TischBezahlen(t > 0 ? t : undefined, true);
                } else if (typeof window.tisch === 'function') {
                    tisch();
                }
                return;
            }
            if (ret === 'rechnung') {
                if (typeof window.TischRechnungNachtraeglich === 'function') {
                    TischRechnungNachtraeglich(t > 0 ? t : undefined);
                }
                return;
            }
            if (typeof window.tisch === 'function') {
                tisch();
            }
        });
    };

    window.TischRechnungNachtraeglich = function(tischnummer) {
        if (typeof tischnummer !== 'undefined' && tischnummer !== null) {
            Tischnummer = parseInt(tischnummer, 10) || Tischnummer;
        }
        var t = parseInt(window.Tischnummer, 10) || 0;
        if (t > 0) ffSetTischInUrl(t, 'listTischBestellungen', 'rechnung');
        ffPersistTischHistorieReturn('rechnung', false);
        loadContent('list_rechnung_nachtraeglich.php?tischnummer=' + Tischnummer, 'Bestellungen');
        showPage('listTischBestellungen');
    };

    window.offeneBestellungen = function() {
        loadContent('list_Bestellungen.php?tischnummer=' + Tischnummer, 'offeneBestellungen');
    };

    window.applyKuecheCols = function() {};
    window.toggleKuecheCols = function() {};
    /** Legacy-Hash #Kuechenansicht → Druckziel 11 (eine gemeinsame Stations-UI). */
    window.Kuechenansicht = function() {
        if (window._pauseOperationsPoll) {
            if (getActivePageId() === 'DruckzielAnsicht' && window.currentDruckzielId === 11) {
                setTimeout(Kuechenansicht, 800);
            }
            return;
        }
        DruckzielAnsicht(11, 'Küche');
    };

    /** Legacy-Hash #Schankansicht → Druckziel 12 (eine gemeinsame Stations-UI). */
    window.SchankAnsicht = function() {
        if (window._pauseOperationsPoll) {
            if (getActivePageId() === 'DruckzielAnsicht' && window.currentDruckzielId === 12) {
                setTimeout(SchankAnsicht, 800);
            }
            return;
        }
        DruckzielAnsicht(12, 'Schank');
    };

    window.applySchankCols = function() {};
    window.toggleSchankCols = function() {};

    /** Nach Fertig / Gesamt fertig: stilles Nachladen wie beim Auto-Poll (kein Vollbild-Spinner); Polling-Timer läuft unverändert weiter. */
    window.KuechenansichtRefresh = function() {
        window.currentDruckzielId = 11;
        DruckzielAnsichtRefresh();
    };

    window.SchankAnsichtRefresh = function() {
        window.currentDruckzielId = 12;
        DruckzielAnsichtRefresh();
    };

    window.KuecheHistory = function() {
        loadContent('kueche_history.php', 'KuecheHistoryContent');
        showPage('KuecheHistory');
    };

    window.SchankHistory = function() {
        loadContent('schank_history.php', 'SchankHistoryContent');
        showPage('SchankHistory');
    };

    window.DirektHistory = function(queryString) {
        var url = 'direkt_history.php';
        var extra = '';
        if (typeof queryString === 'string' && queryString.trim() !== '') {
            extra = queryString.trim().replace(/^\?/, '');
        }
        if (extra) {
            url += '?' + extra;
        }
        loadContent(url, 'DirektHistoryContent');
        showPage('DirektHistory');
    };

    /** Filter-Formular in Direktverkauf-Historie (SPA, kein Reload von index.php). */
    window.ffDvHistFilterSubmit = function(ev) {
        if (ev && ev.preventDefault) ev.preventDefault();
        var form = ev && ev.target ? ev.target : document.getElementById('ffDvHistFilterForm');
        var params = new URLSearchParams();
        if (form) {
            var k = form.querySelector('[name=kellner]');
            var b = form.querySelector('[name=bon_id]');
            if (k && k.value) params.set('kellner', k.value);
            if (b && b.value && String(b.value).trim() !== '') params.set('bon_id', String(b.value).trim());
        }
        var qs = params.toString();
        window.DirektHistory(qs);
        return false;
    };

    window.ffDvHistReload = function() {
        var form = document.getElementById('ffDvHistFilterForm');
        if (form && typeof window.ffDvHistFilterSubmit === 'function') {
            window.ffDvHistFilterSubmit({ preventDefault: function() {}, target: form });
            return;
        }
        window.DirektHistory();
    };

    window.ffDvHistStorno = function(rowid, isPaid, stillOpenAtStation) {
        var msg;
        if (isPaid && !stillOpenAtStation) {
            msg = 'Bezahlung wirklich stornieren (Rückerstattung)?\n\nDie Position war bereits ausgeliefert — nur die Bezahlung wird zurückgesetzt.';
        } else if (isPaid) {
            msg = 'Position wirklich stornieren?\n\nBezahlung zurück und aus Küche/Schank entfernen (noch nicht ausgeliefert).';
        } else {
            msg = 'Position wirklich stornieren?';
        }
        if (!confirm(msg)) return;
        var url = isPaid
            ? 'bestellung_bez_storno.php?rowid=' + encodeURIComponent(String(rowid))
            : 'bestellung_loeschen.php?rowid=' + encodeURIComponent(String(rowid));
        fetch(url, { cache: 'no-store', credentials: 'same-origin' })
            .then(function(r) { return r.json().then(function(j) { return { r: r, j: j }; }); })
            .then(function(x) {
                if (!x.r.ok || !x.j || !x.j.ok) {
                    alert((x.j && x.j.message) ? x.j.message : 'Storno nicht möglich.');
                    return;
                }
                if (x.j.message) alert(x.j.message);
                window.ffDvHistReload();
                if (getActivePageId() === 'Direktverkauf' && typeof window.ffDvQuickRefresh === 'function') {
                    window.ffDvQuickRefresh({ tab: true });
                }
            })
            .catch(function() { alert('Netzwerkfehler beim Storno.'); });
    };

    window.ffDvHistRemoveOpen = function(rowid) {
        if (!confirm('Offene Position wirklich entfernen?')) return;
        var fd = new FormData();
        fd.append('rowid', String(rowid));
        fetch('bestellung_dv_row_remove.php', { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(j) {
                if (!j || !j.ok) {
                    alert((j && j.message) ? j.message : 'Entfernen fehlgeschlagen.');
                    return;
                }
                window.ffDvHistReload();
                if (getActivePageId() === 'Direktverkauf' && typeof window.ffDvQuickRefresh === 'function') {
                    window.ffDvQuickRefresh({ tab: true });
                }
            })
            .catch(function() { alert('Netzwerkfehler.'); });
    };

    window.ffTischOrderSummaryPrompt = function(tischnummer, callback) {
        var modal = document.getElementById('ffTischOrderSummaryModal');
        var body = document.getElementById('ffTischOrderSummaryBody');
        var submitBtn = document.getElementById('ffTischOrderSummarySubmit');
        if (!modal || !body || !submitBtn) {
            if (typeof callback === 'function') {
                callback();
            }
            return;
        }

        body.innerHTML = '<div class="text-muted">Übersicht wird geladen…</div>';
        submitBtn.disabled = true;
        submitBtn.textContent = 'Bestellung endgültig absenden';
        submitBtn.onclick = null;

        function moneyText(value) {
            var num = Number(value) || 0;
            return num.toLocaleString('de-AT', { style: 'currency', currency: 'EUR' });
        }

        fetchPost('bestellung_unsent_summary.php', { tischnummer: tischnummer })
            .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
            .then(function(x) {
                if (!x.ok || !x.j || !x.j.ok) {
                    var msg = (x.j && x.j.message) ? x.j.message : 'Die Bestellübersicht konnte nicht geladen werden.';
                    alert(msg);
                    return;
                }

                var lines = Array.isArray(x.j.lines) ? x.j.lines : [];
                var total = Number(x.j.total || 0);
                var html = '';
                if (lines.length === 0) {
                    html = '<div class="alert alert-warning mb-0">Keine offenen Positionen zum Abschicken.</div>';
                } else {
                    html = '<div class="list-group list-group-flush">';
                    lines.forEach(function(line) {
                        var name = String(line.name || 'Unbekannt');
                        var qty = Number(line.quantity || 0);
                        var price = Number(line.price || 0);
                        var lineTotal = Number(line.total || (qty * price));
                        var priceText = moneyText(price);
                        var lineTotalText = moneyText(lineTotal);
                        html += '<div class="list-group-item px-0 py-2 border-0 border-bottom">'
                            + '<div class="d-flex justify-content-between gap-3 align-items-start">'
                            + '<div><div class="fw-semibold">' + name + '</div><div class="text-muted">' + qty + ' × ' + priceText + '</div></div>'
                            + '<div class="fw-semibold text-end">' + lineTotalText + '</div>'
                            + '</div>'
                            + '</div>';
                    });
                    html += '</div>';
                }
                html += '<div class="d-flex justify-content-between align-items-center border-top mt-3 pt-3 fw-bold">'
                    + '<span>Gesamt</span>'
                    + '<span>' + moneyText(total) + '</span>'
                    + '</div>';
                body.innerHTML = html;

                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var inst = bootstrap.Modal.getOrCreateInstance(modal);
                    inst.show();
                }
                submitBtn.disabled = false;
                submitBtn.onclick = function() {
                    var inst2 = bootstrap && bootstrap.Modal ? bootstrap.Modal.getInstance(modal) : null;
                    if (inst2) inst2.hide();
                    if (typeof callback === 'function') callback();
                };
            })
            .catch(function() {
                alert('Fehler beim Laden der Bestellübersicht.');
            });
    };

    window.ffTischOrderSendConfirmed = function(tischnummer) {
        fetchPost('bestellung_has_items.php', { tischnummer: tischnummer })
            .then(function(r) { return r.json(); })
            .then(function(check) {
                if (!check || !check.ok) { alert("Fehler beim Prüfen der Bestellung."); return; }
                if (check.has_items !== 1) { alert("Keine offenen Positionen zum Abschicken."); return; }
                return fetchPost('bestellung_abschicken.php', { tischnummer: tischnummer });
            })
            .then(function(r) { return r && r.json ? r.json() : null; })
            .then(function(resp) {
                if (!resp) return;
                if (resp.ok) {
                    window._ffTischUnsentCount = 0;
                    if (resp.require_payment === 1) {
                        window._requirePaymentActive = true;
                        ffPersistTischHistorieReturn('zahlen', true, resp.tischnummer);
                        ffLoadTischZahlen(resp.tischnummer, true);
                        return;
                    }
                    TischAnsicht();
                    showPage('listTische');
                } else {
                    alert("Fehler beim Speichern: " + (resp.error || 'unbekannt'));
                }
            })
            .catch(function(xhr) {
                alert("Fehler beim Speichern! Siehe Konsole für Details.");
            });
    };

    window.bestellungAbschicken = function(tischnummer) {
        if (typeof window.ffSystemBroadcastIsOpen === 'function' && window.ffSystemBroadcastIsOpen()) {
            alert('Bitte zuerst die Systemnachricht mit OK bestätigen.');
            return false;
        }
        var btn = $('btnBestellungAbschicken');
        if (btn && btn.classList.contains('disabled')) return false;
        console.log("bestellung Abschicken " + tischnummer);

        fetchPost('bestellung_has_items.php', { tischnummer: tischnummer })
            .then(function(r) { return r.json(); })
            .then(function(check) {
                if (!check || !check.ok) { alert("Fehler beim Prüfen der Bestellung."); return; }
                if (check.has_items !== 1) { alert("Keine offenen Positionen zum Abschicken."); return; }
                window.ffTischOrderSummaryPrompt(tischnummer, function() {
                    window.ffTischOrderSendConfirmed(tischnummer);
                });
            })
            .catch(function(xhr) {
                alert("Fehler beim Speichern! Siehe Konsole für Details.");
            });
    };

    window.bestellungKUAbschicken = function(tischnummer) {
        fetchPost('bestellung_abschicken.php', 'tischnummer=' + tischnummer)
            .then(function() {
                if (getActivePageId() === 'DruckzielAnsicht') DruckzielAnsichtRefresh();
                else KuechenansichtRefresh();
            })
            .catch(onError);
    };

    window.bestellungSAAbschicken = function(tischnummer) {
        fetchPost('bestellung_abschicken.php', 'tischnummer=' + tischnummer)
            .then(function() {
                if (getActivePageId() === 'DruckzielAnsicht') DruckzielAnsichtRefresh();
                else SchankAnsichtRefresh();
            })
            .catch(onError);
    };

    /** Tisch (#TischAnzeigenContent) vs. Direktverkauf (#DirektverkaufTabContent) – nicht mit kuechefertig-Parameter verwechseln (DV nutzt dort 1). */
    function ffSpeisekarteTabContentId(tischnummer) {
        var t = parseInt(String(tischnummer), 10) || 0;
        return t === 999999 ? 'DirektverkaufTabContent' : 'TischAnzeigenContent';
    }

    var _saveBestellungInflight = {};
    window.saveBestellung = function(position, tab, tischnummer, fertig) {
        var tnum = parseInt(String(tischnummer), 10) || 0;
        var posId = parseInt(String(position), 10) || 0;
        var inflightKey = tnum + ':' + posId;
        if (_saveBestellungInflight[inflightKey]) {
            return;
        }
        _saveBestellungInflight[inflightKey] = true;
        var tabContentIdPre = ffSpeisekarteTabContentId(tischnummer);
        var tabContentPre = document.getElementById(tabContentIdPre);
        var panePre = tabContentPre ? tabContentPre.querySelector('.tab-pane.active') : null;
        var innerPre = panePre ? panePre.querySelector('.tab-content-inner') : null;
        // Tisch + Direktverkauf: Karte still neu laden, Scrollposition behalten (silentPoll)
        var useSilentKarteReload = !!(innerPre && innerPre.children.length > 0);
        var isDv = (tnum === 999999);
        var dvOptimistic = null;
        if (isDv) {
            var cntOpt = document.getElementById('cnt-' + posId);
            var btnOpt = document.getElementById('btn-pos-' + posId);
            if (cntOpt && btnOpt) {
                var curCnt = parseInt(cntOpt.getAttribute('data-cnt') || '0', 10) || 0;
                dvOptimistic = { prevCnt: curCnt };
                ffUpdatePosTileFromSave(posId, { open_cnt: curCnt + 1 });
            }
        }
        if (!useSilentKarteReload && !isDv) {
            showLoading(true);
        }
        var data = "Tischnummer=" + tischnummer + "&positionsid=" + position + "&Zusatzinfo=" + encodeURIComponent(Beilagen) + "&kuechefertig=" + (fertig || 0);
        
        // Bei Direktverkauf (fertig=1, tischnummer=999999) Bon-ID mitsenden
        if (fertig === 1 && tischnummer === 999999 && typeof getDirektverkaufBonId === 'function') {
            var bonId = getDirektverkaufBonId();
            if (bonId) {
                data += "&bon_id=" + encodeURIComponent(bonId);
            }
        }
        
        fetchPost('bestellung_save.php', data)
            .then(function(r) { return handleBestellungSaveFetchResponse(r); })
            .then(function(res) {
                var dvOpts = { paybar: true, tab: false, cart: false };
                if (ffUpdatePosTileFromSave(position, res)) {
                    Summe = 0;
                    if (isDv) {
                        if (res.open_counts && typeof ffApplyDvOpenCountMap === 'function') {
                            ffApplyDvOpenCountMap(res.open_counts);
                        }
                        var paybarOk = typeof ffDvSyncPaybarUi === 'function' && ffDvSyncPaybarUi(res);
                        if (!paybarOk && typeof window.ffDvQuickRefresh === 'function') {
                            window.ffDvQuickRefresh({ paybar: true, tab: dvOpts.tab, cart: false });
                        } else {
                            var cartOc = document.getElementById('ffDvCartOffcanvas');
                            if (cartOc && cartOc.classList.contains('show') && typeof window.ffDvRefreshCart === 'function') {
                                window.ffDvRefreshCart();
                            }
                            if (dvOpts.tab && typeof window.ffDvQuickRefresh === 'function') {
                                window.ffDvQuickRefresh({ paybar: false, tab: true, cart: false });
                            }
                        }
                    } else {
                        refreshAbschickenButton(tischnummer);
                    }
                    return;
                }
                var tabContentId = ffSpeisekarteTabContentId(tischnummer);
                var tabContent = document.getElementById(tabContentId);
                var pane = tabContent ? tabContent.querySelector('.tab-pane.active') : null;
                if (pane && pane.dataset.loadUrl) {
                    var inner = pane.querySelector('.tab-content-inner');
                    loadContent(pane.dataset.loadUrl, inner || pane, null, { silentPoll: useSilentKarteReload });
                }
                Summe = 0;
                if (isDv) {
                    dvOpts.tab = true;
                    if (typeof window.ffDvQuickRefresh === 'function') window.ffDvQuickRefresh(dvOpts);
                } else {
                    refreshAbschickenButton(tischnummer);
                }
            })
            .catch(function(err) {
                if (isDv && dvOptimistic) {
                    ffUpdatePosTileFromSave(posId, { open_cnt: dvOptimistic.prevCnt });
                }
                if (err && (err.message === 'locked' || err.message === 'badreq')) return;
                onError();
            })
            .finally(function() {
                delete _saveBestellungInflight[inflightKey];
                if (!useSilentKarteReload && !isDv) showLoading(false);
            });
    };

    window.ffParseHinweisToFreeAndChecks = function(str, presetItems) {
        var names = (presetItems || []).map(function(it) { return typeof it === 'string' ? it : String(it.name || ''); });
        var checks = names.map(function() { return false; });
        var free = '';
        var s = String(str || '').trim();
        if (!s) return { checks: checks, free: '' };
        var left = s;
        var sep = s.indexOf(' | ');
        if (sep >= 0) {
            left = s.slice(0, sep).trim();
            free = s.slice(sep + 3).trim();
        }
        if (left && names.length) {
            var parts = left.split(',').map(function(x) { return x.trim(); }).filter(Boolean);
            var unmatched = [];
            for (var p = 0; p < parts.length; p++) {
                var pi = names.indexOf(parts[p]);
                if (pi >= 0) checks[pi] = true;
                else unmatched.push(parts[p]);
            }
            if (unmatched.length) {
                var extra = unmatched.join(', ');
                free = free ? (extra + ', ' + free) : extra;
            }
        } else if (left && !names.length) {
            free = free ? (left + ', ' + free) : left;
        }
        return { checks: checks, free: free };
    };

    window.ffComposeHinweisLine = function(i) {
        var items = window._ffHinweisPresetItems || [];
        var picked = [];
        for (var pi = 0; pi < items.length; pi++) {
            var el = document.getElementById('ffH_cb_' + i + '_' + pi);
            if (el && el.checked) {
                var it = items[pi];
                picked.push(typeof it === 'string' ? it : String(it.name || ''));
            }
        }
        var ta = document.getElementById('ffHinweisLine' + i);
        var fr = ta ? String(ta.value || '').trim() : '';
        var parts = picked.filter(Boolean).join(', ');
        var out = '';
        if (parts && fr) out = parts + ' | ' + fr;
        else if (parts) out = parts;
        else out = fr;
        if (out.length > 255) out = out.slice(0, 255);
        return out;
    };

    /**
     * Pro Portion: Checkboxen für jede vordefinierte Zusatzinfo + optionaler Freitext.
     */
    window.ffHinweisRebuildZeilen = function() {
        var mEl = document.getElementById('ffHinweisMenge');
        var container = document.getElementById('ffHinweisZeilen');
        if (!mEl || !container) return;
        var presetItems = window._ffHinweisPresetItems || [];
        var prev = [];
        var oldBlocks = container.querySelectorAll('.ff-hinweis-block');
        for (var o = 0; o < oldBlocks.length; o++) {
            var ta0 = oldBlocks[o].querySelector('.ff-hinweis-zeile');
            var row = { text: ta0 ? (ta0.value || '') : '', checks: [] };
            for (var pi = 0; pi < presetItems.length; pi++) {
                var cb0 = document.getElementById('ffH_cb_' + o + '_' + pi);
                row.checks.push(cb0 ? !!cb0.checked : false);
            }
            prev.push(row);
        }
        var n = parseInt(mEl.value, 10);
        if (isNaN(n) || n < 0) n = 0;
        var min = parseInt(mEl.getAttribute('min') || '0', 10);
        if (!isNaN(min) && n < min) {
            n = min;
            mEl.value = String(n);
        }
        var max = parseInt(mEl.getAttribute('max') || '50', 10);
        if (n > max) {
            n = max;
            mEl.value = String(n);
        }
        container.innerHTML = '';
        if (n === 0) {
            container.innerHTML = '<p class="text-muted small mb-0">Alle offenen Portionen dieser Position werden beim Übernehmen entfernt.</p>';
            return;
        }
        for (var i = 0; i < n; i++) {
            var block = document.createElement('div');
            block.className = 'ff-hinweis-block mb-3 p-2 border rounded';
            var title = document.createElement('div');
            title.className = 'form-label small fw-semibold mb-1';
            title.textContent = 'Portion ' + (i + 1);
            block.appendChild(title);
            var checkEls = [];
            if (presetItems.length > 0) {
                var cbRow = document.createElement('div');
                cbRow.className = 'd-flex flex-wrap gap-2 mb-2 small';
                for (var pi = 0; pi < presetItems.length; pi++) {
                    var it = presetItems[pi];
                    var nm = typeof it === 'string' ? it : String(it.name || '');
                    var bet = (typeof it === 'object' && it && Number(it.betrag) > 0) ? Number(it.betrag) : 0;
                    var cid = 'ffH_cb_' + i + '_' + pi;
                    var fdiv = document.createElement('div');
                    fdiv.className = 'form-check m-0';
                    var inp = document.createElement('input');
                    inp.type = 'checkbox';
                    inp.className = 'form-check-input';
                    inp.id = cid;
                    var lab = document.createElement('label');
                    lab.className = 'form-check-label';
                    lab.setAttribute('for', cid);
                    lab.textContent = nm + (bet > 0 ? ' (+' + bet.toFixed(2).replace('.', ',') + ' €)' : '');
                    fdiv.appendChild(inp);
                    fdiv.appendChild(lab);
                    cbRow.appendChild(fdiv);
                    checkEls.push(inp);
                }
                block.appendChild(cbRow);
            }
            var ta = document.createElement('textarea');
            ta.id = 'ffHinweisLine' + i;
            ta.className = 'form-control ff-hinweis-zeile';
            ta.rows = 2;
            ta.maxLength = 255;
            ta.placeholder = 'Zusätzlicher Freitext (optional)';
            if (prev[i]) {
                ta.value = prev[i].text || '';
                for (var pi2 = 0; pi2 < checkEls.length; pi2++) {
                    if (prev[i].checks[pi2]) checkEls[pi2].checked = true;
                }
            }
            block.appendChild(ta);
            container.appendChild(block);
        }
    };

    /**
     * Hinweis/Zusatzinfo pro Portion (Küche/Bon gruppieren nach Zusatzinfo).
     *
     * @param {number} openMenge aktuell offene Portionen (damit du genau z.B. 4 Schnitzel bearbeiten kannst)
     * @param {number} maxRest verbleibend laut Karte (0 = unbegrenzt → UI max 50)
     */
    window.ffOpenPosHinweisModal = function(positionId, tab, tischnummer, fertig, openMenge, maxRest) {
        window._ffHinweisSuccessMode = '';
        var modal = document.getElementById('ffPosHinweisModal');
        if (!modal) {
            alert('Hinweis-Dialog ist nicht verfügbar.');
            return;
        }
        var mElRo = document.getElementById('ffHinweisMenge');
        if (mElRo) mElRo.readOnly = false;
        var posEl = document.getElementById('ffHinweisPosition');
        var tabEl = document.getElementById('ffHinweisTab');
        var tischEl = document.getElementById('ffHinweisTisch');
        var fertigEl = document.getElementById('ffHinweisFertig');
        var mEl = document.getElementById('ffHinweisMenge');
        var container = document.getElementById('ffHinweisZeilen');
        var rowidsEl = document.getElementById('ffHinweisRowids');

        if (!posEl || !tabEl || !tischEl || !fertigEl || !mEl || !container) return;

        posEl.value = String(positionId);
        tabEl.value = String(tab);
        tischEl.value = String(tischnummer);
        fertigEl.value = String(fertig || 0);

        var openCntPassed = parseInt(openMenge, 10) || 0;
        var cntElTile = document.getElementById('cnt-' + positionId);
        if (cntElTile) {
            var tileCnt = parseInt(cntElTile.getAttribute('data-cnt') || '0', 10);
            if (!isNaN(tileCnt) && tileCnt > openCntPassed) {
                openCntPassed = tileCnt;
            }
        }
        var mr = parseInt(maxRest, 10);
        var initialMenge = openCntPassed > 0 ? openCntPassed : 1;
        var maxAllowed = 50;
        if (!isNaN(mr) && mr > 0) maxAllowed = initialMenge + mr;

        mEl.value = String(initialMenge);
        mEl.setAttribute('min', '0');
        mEl.setAttribute('max', String(maxAllowed));

        if (rowidsEl) rowidsEl.value = '[]';
        container.innerHTML = '';
        window._ffHinweisPresetItems = [];

        var bs = typeof bootstrap !== 'undefined' && bootstrap.Modal ? bootstrap.Modal.getOrCreateInstance(modal) : null;
        if (bs) bs.show();

        container.innerHTML = '<p class="text-muted small mb-0">Lade Zusatzoptionen…</p>';

        var loadUrl = 'pos_hinweis_modal_data.php'
            + '?tischnummer=' + encodeURIComponent(tischnummer)
            + '&positionsid=' + encodeURIComponent(positionId)
            + '&kuechefertig=' + encodeURIComponent(fertig || 0);
        if (parseInt(tischnummer, 10) === 999999 && typeof getDirektverkaufBonId === 'function') {
            var bonForModal = getDirektverkaufBonId();
            if (bonForModal) {
                loadUrl += '&bon_id=' + encodeURIComponent(bonForModal);
            }
        }

        fetchGet(loadUrl).then(function(resp) { return resp.json(); }).then(function(data) {
            var bData = data || {};
            var lData = data || {};
            var rawItems = Array.isArray(bData.items) ? bData.items : [];
            if (!rawItems.length && Array.isArray(bData.options)) {
                for (var ox = 0; ox < bData.options.length; ox++) {
                    rawItems.push({ name: bData.options[ox], betrag: 0 });
                }
            }
            window._ffHinweisPresetItems = rawItems;

            var hinweise = [];
            if (lData && lData.ok) {
                var rowids = Array.isArray(lData.rowids) ? lData.rowids : [];
                hinweise = Array.isArray(lData.hinweise) ? lData.hinweise : [];
                var actualCnt = rowids.length;
                if (rowidsEl) rowidsEl.value = JSON.stringify(rowids);
                var displayCnt = actualCnt > 0 ? actualCnt : initialMenge;
                if (openCntPassed > displayCnt) {
                    displayCnt = openCntPassed;
                }
                var maxAllowed2 = 50;
                if (!isNaN(mr) && mr > 0) {
                    maxAllowed2 = displayCnt + mr;
                    if (maxAllowed2 < 1) maxAllowed2 = 1;
                }
                mEl.setAttribute('min', '0');
                mEl.setAttribute('max', String(maxAllowed2));
                mEl.value = String(displayCnt);
            }

            container.innerHTML = '';
            window.ffHinweisRebuildZeilen();

            if (lData && lData.ok && hinweise.length) {
                for (var li = 0; li < hinweise.length; li++) {
                    var parsed = window.ffParseHinweisToFreeAndChecks(hinweise[li], window._ffHinweisPresetItems);
                    var ta = document.getElementById('ffHinweisLine' + li);
                    if (ta) ta.value = parsed.free;
                    for (var cpi = 0; cpi < parsed.checks.length; cpi++) {
                        var cbel = document.getElementById('ffH_cb_' + li + '_' + cpi);
                        if (cbel) cbel.checked = !!parsed.checks[cpi];
                    }
                }
            }
        }).catch(function() {
            window._ffHinweisPresetItems = [];
            window.ffHinweisRebuildZeilen();
        });
    };

    window.ffMinusOpenPortions = function(tischnummer, position, type, count) {
        var tnum = parseInt(String(tischnummer), 10) || 0;
        var pos = parseInt(String(position), 10) || 0;
        var ty = parseInt(String(type), 10);
        if (isNaN(ty)) ty = 1;
        var n = parseInt(String(count), 10) || 0;
        if (tnum <= 0 || pos <= 0 || n <= 0) {
            return Promise.resolve(0);
        }
        var chain = Promise.resolve(0);
        for (var i = 0; i < n; i++) {
            chain = chain.then(function(removedSoFar) {
                var fd = new FormData();
                fd.append('tischnummer', String(tnum));
                fd.append('position', String(pos));
                fd.append('type', String(ty));
                return fetch('bestellung_minus.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    cache: 'no-store'
                })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        return removedSoFar + (res && res.ok && res.removed ? 1 : 0);
                    });
            });
        }
        return chain;
    };

    window.ffSubmitPosHinweis = function() {
        var posEl = document.getElementById('ffHinweisPosition');
        var tabEl = document.getElementById('ffHinweisTab');
        var tischEl = document.getElementById('ffHinweisTisch');
        var fertigEl = document.getElementById('ffHinweisFertig');
        var mEl = document.getElementById('ffHinweisMenge');
        var container = document.getElementById('ffHinweisZeilen');
        var rowidsEl = document.getElementById('ffHinweisRowids');
        var modal = document.getElementById('ffPosHinweisModal');

        if (!posEl || !tischEl || !fertigEl || !mEl || !container) return;

        var pos = posEl.value;
        var tab = parseInt(tabEl ? tabEl.value : '1', 10);
        if (isNaN(tab)) tab = 1;
        var tischnummer = parseInt(tischEl.value, 10);
        var fertig = parseInt(fertigEl.value, 10) || 0;
        var menge = parseInt(mEl.value, 10);
        if (isNaN(menge) || menge < 0) menge = 0;

        var minAllowed = parseInt(mEl.getAttribute('min') || '0', 10);
        if (!isNaN(minAllowed) && menge < minAllowed) menge = minAllowed;

        var maxAllowed = parseInt(mEl.getAttribute('max') || '50', 10);
        if (!isNaN(maxAllowed) && menge > maxAllowed) menge = maxAllowed;
        mEl.value = String(menge);

        var rowids = [];
        if (rowidsEl && rowidsEl.value) {
            try {
                var parsedIds = JSON.parse(rowidsEl.value);
                if (Array.isArray(parsedIds)) rowids = parsedIds;
            } catch (e) {}
        }

        var hinweise = [];
        if (menge > 0) {
            var lineEls = container.querySelectorAll('.ff-hinweis-zeile');
            if (lineEls.length !== menge) {
                window.ffHinweisRebuildZeilen();
                lineEls = container.querySelectorAll('.ff-hinweis-zeile');
            }
            for (var h = 0; h < menge; h++) {
                hinweise.push(typeof window.ffComposeHinweisLine === 'function' ? window.ffComposeHinweisLine(h) : ((lineEls[h] && lineEls[h].value) || '').trim());
            }
        }

        if (menge === 0 && rowids.length === 0) {
            if (modal && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var inst0 = bootstrap.Modal.getInstance(modal);
                if (inst0) inst0.hide();
            }
            if (tischnummer > 0 && tischnummer !== 999999 && typeof refreshAbschickenButton === 'function') {
                refreshAbschickenButton(tischnummer);
            }
            return;
        }

        if (modal && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            if (window._ffHinweisSuccessMode === 'hist') {
                window._ffHinweisHistSaving = true;
            }
            var inst = bootstrap.Modal.getInstance(modal);
            if (inst) inst.hide();
        }

        var tabContentIdPreH = ffSpeisekarteTabContentId(tischnummer);
        var tabContentPreH = document.getElementById(tabContentIdPreH);
        var panePreH = tabContentPreH ? tabContentPreH.querySelector('.tab-pane.active') : null;
        var innerPreH = panePreH ? panePreH.querySelector('.tab-content-inner') : null;
        var useSilentHinweisReload = !!(innerPreH && innerPreH.children.length > 0);
        if (!useSilentHinweisReload) {
            showLoading(true);
        }

        var removeCount = Math.max(0, rowids.length - menge);
        var updateCount = Math.min(rowids.length, menge);
        var updateRowids = rowids.slice(0, updateCount);
        var updateHints = hinweise.slice(0, updateCount);

        var extraCount = Math.max(0, menge - rowids.length);
        var extraHints = hinweise.slice(updateCount);

        var promises = [];
        if (removeCount > 0) {
            promises.push(window.ffMinusOpenPortions(tischnummer, pos, tab, removeCount));
        }
        if (updateCount > 0) {
            var updData = 'rowids_json=' + encodeURIComponent(JSON.stringify(updateRowids))
                + '&hinweise_json=' + encodeURIComponent(JSON.stringify(updateHints));
            promises.push(fetchPost('saveZusatzinfos_multi.php', updData).then(function(r) {
                if (!r.ok) throw new Error('http');
            }));
        }

        if (extraCount > 0) {
            var hinweiseJson = JSON.stringify(extraHints);
            var insertData = 'Tischnummer=' + encodeURIComponent(tischnummer)
                + '&positionsid=' + encodeURIComponent(pos)
                + '&Zusatzinfo=&menge=' + encodeURIComponent(extraCount)
                + '&hinweise_json=' + encodeURIComponent(hinweiseJson)
                + '&kuechefertig=' + encodeURIComponent(fertig);

            if (fertig === 1 && tischnummer === 999999 && typeof getDirektverkaufBonId === 'function') {
                var bonId = getDirektverkaufBonId();
                if (bonId) insertData += '&bon_id=' + encodeURIComponent(bonId);
            }

            promises.push(fetchPost('bestellung_save.php', insertData)
                .then(function(r) { return handleBestellungSaveFetchResponse(r); }));
        }

        Promise.all(promises)
            .then(function() {
                if (window._ffHinweisSuccessMode === 'hist') {
                    window._ffHinweisHistSaving = false;
                    window._ffHinweisSuccessMode = '';
                    var mFix = document.getElementById('ffHinweisMenge');
                    if (mFix) mFix.readOnly = false;
                    if (typeof TischAnsichtHistory === 'function') {
                        TischAnsichtHistory();
                    }
                    return;
                }
                var tabContentId = ffSpeisekarteTabContentId(tischnummer);
                var tabContent = document.getElementById(tabContentId);
                var pane = tabContent ? tabContent.querySelector('.tab-pane.active') : null;
                if (pane && pane.dataset.loadUrl) {
                    var inner = pane.querySelector('.tab-content-inner');
                    loadContent(pane.dataset.loadUrl, inner || pane, null, { silentPoll: useSilentHinweisReload });
                }
                Summe = 0;
                if (parseInt(String(tischnummer), 10) !== 999999) {
                    refreshAbschickenButton(tischnummer);
                } else if (typeof window.ffDvQuickRefresh === 'function') {
                    window.ffDvQuickRefresh({ tab: false });
                }
            })
            .catch(function(err) {
                if (window._ffHinweisSuccessMode === 'hist') {
                    window._ffHinweisHistSaving = false;
                }
                if (err && (err.message === 'locked' || err.message === 'badreq')) return;
                onError();
            })
            .finally(function() {
                if (!useSilentHinweisReload) showLoading(false);
            });
    };

    window.saveBestellung2 = function(position, tab, tischnummer) {
        showLoading(true);
        var data = "Tischnummer=" + Tischnummer + "&positionsid=" + position + "&Zusatzinfo=" + encodeURIComponent(Beilagen) + "&kuechefertig=0";
        fetchPost('bestellung_save.php', data)
            .then(function(r) { return handleBestellungSaveFetchResponse(r); })
            .then(function() {
                var pane = document.querySelector('#TischAnzeigen .tab-pane.active');
                if (pane && pane.dataset.loadUrl) loadContent(pane.dataset.loadUrl, pane.querySelector('.tab-content-inner') || pane);
                Summe = 0;
                Beilagen = "";
            })
            .catch(function(err) {
                if (err && (err.message === 'locked' || err.message === 'badreq')) return;
                onError();
            })
            .finally(function() { showLoading(false); });
    };

    window.updateZusatzinfo = function() {
        var data = 'Zusatzinfo=' + encodeURIComponent(Beilagen) + '&rowid=' + rowid;
        fetchPost('saveZusatzinfo.php', data)
            .then(function(r) { return r.text(); })
            .then(function(text) { rowid = ""; alert(text); })
            .catch(onError);
    };

    window.saveZusatzinfo = function(text, rowid) {
        if (text === null || text === undefined) {
            return;
        }
        var data = 'Zusatzinfo=' + encodeURIComponent(String(text)) + '&rowid=' + rowid;
        fetchPost('saveZusatzinfo.php', data)
            .then(function() { TischAnsichtHistory(); })
            .catch(onError);
    };

    /** Tisch-Historie: Zusatzinfos wie am Tisch (Modal mit Beilagen-Checkboxen), eine abgeschickte Zeile. */
    window.ffOpenHistZusatzinfoEdit = function(rowid, positionId, tischnummer, posType) {
        var modal = document.getElementById('ffPosHinweisModal');
        if (!modal) {
            alert('Hinweis-Dialog ist nicht verfügbar.');
            return;
        }
        var posEl = document.getElementById('ffHinweisPosition');
        var tabEl = document.getElementById('ffHinweisTab');
        var tischEl = document.getElementById('ffHinweisTisch');
        var fertigEl = document.getElementById('ffHinweisFertig');
        var mEl = document.getElementById('ffHinweisMenge');
        var container = document.getElementById('ffHinweisZeilen');
        var rowidsEl = document.getElementById('ffHinweisRowids');

        if (!posEl || !tabEl || !tischEl || !fertigEl || !mEl || !container) return;

        window._ffHinweisSuccessMode = 'hist';
        posEl.value = String(positionId);
        tabEl.value = String(parseInt(posType, 10) === 0 ? 0 : 1);
        tischEl.value = String(tischnummer);
        fertigEl.value = '0';
        mEl.value = '1';
        mEl.setAttribute('min', '1');
        mEl.setAttribute('max', '1');
        mEl.readOnly = true;
        if (rowidsEl) rowidsEl.value = '[]';
        container.innerHTML = '';
        window._ffHinweisPresetItems = [];

        var bs = typeof bootstrap !== 'undefined' && bootstrap.Modal ? bootstrap.Modal.getOrCreateInstance(modal) : null;
        function ffHistHinweisModalReset() {
            window._ffHinweisSuccessMode = '';
            window._ffHinweisHistSaving = false;
            if (mEl) mEl.readOnly = false;
        }
        modal.addEventListener('hidden.bs.modal', function onHistHinweisHidden() {
            if (window._ffHinweisSuccessMode === 'hist' && !window._ffHinweisHistSaving) {
                ffHistHinweisModalReset();
            }
        }, { once: true });
        if (bs) bs.show();

        container.innerHTML = '<p class="text-muted small mb-0">Lade Zusatzoptionen…</p>';

        var loadUrl = 'pos_hinweis_modal_data.php'
            + '?tischnummer=' + encodeURIComponent(tischnummer)
            + '&positionsid=' + encodeURIComponent(positionId)
            + '&rowid=' + encodeURIComponent(rowid);

        fetchGet(loadUrl).then(function(resp) { return resp.json(); }).then(function(lData) {
            if (!lData || !lData.ok) {
                alert('Zusatzinfos konnten nicht geladen werden.');
                if (bs) bs.hide();
                window._ffHinweisSuccessMode = '';
                return;
            }
            var rawItems = Array.isArray(lData.items) ? lData.items : [];
            window._ffHinweisPresetItems = rawItems;
            var rowids = Array.isArray(lData.rowids) ? lData.rowids : [];
            var hinweise = Array.isArray(lData.hinweise) ? lData.hinweise : [];
            if (rowidsEl) rowidsEl.value = JSON.stringify(rowids);
            container.innerHTML = '';
            window.ffHinweisRebuildZeilen();
            if (hinweise.length) {
                var parsed = window.ffParseHinweisToFreeAndChecks(hinweise[0], window._ffHinweisPresetItems);
                var ta = document.getElementById('ffHinweisLine0');
                if (ta) ta.value = parsed.free;
                for (var cpi = 0; cpi < parsed.checks.length; cpi++) {
                    var cbel = document.getElementById('ffH_cb_0_' + cpi);
                    if (cbel) cbel.checked = !!parsed.checks[cpi];
                }
            }
        }).catch(function() {
            alert('Netzwerkfehler beim Laden der Zusatzoptionen.');
            if (bs) bs.hide();
            window._ffHinweisSuccessMode = '';
        });
    };

    window.BenutzerNeu = function() {
        var data = "username=" + encodeURIComponent(($('username')||{}).value||'') + "&password=" + encodeURIComponent(($('password')||{}).value||'') + "&password_again=" + encodeURIComponent(($('password_again')||{}).value||'') + "&admin=" + encodeURIComponent(($('adminyesno')||{}).value||'');
        fetchPost('register.php', data)
            .then(function(r) { return r.text(); })
            .then(function(text) {
                alert(text);
                var u = $('username'), p = $('password'), pa = $('password_again');
                if (u) u.value = ""; if (p) p.value = ""; if (pa) pa.value = "";
            })
            .catch(onError);
    };

    window.ProduktNeu = function() {
        var data = "Positionsname=" + encodeURIComponent(($('Positionsname')||{}).value||'') + "&Betrag=" + encodeURIComponent(($('Betrag')||{}).value||'') + "&type=" + encodeURIComponent(($('produktkategorie')||{}).value||'') + "&Kapazitaet=" + encodeURIComponent(($('Kapazitaet')||{}).value||'');
        fetchPost('produktNeu.php', data)
            .then(function() {
                var f = function(id) { var e = $(id); if (e) e.value = ""; };
                f('produktname'); f('produktkategorie'); f('produktpreis'); f('Kapazitaet');
                AdminAnsicht();
            })
            .catch(onError);
    };

    window.ProduktLoeschen = function(rowid) {
        if (!confirm("Wirklich löschen?")) return;
        fetchPost('produkt_loeschen.php', 'rowid=' + rowid)
            .then(function() { AdminAnsicht(); })
            .catch(onError);
    };

    window.kuecheFertig = function(rowid) {
        fetchGet('kueche_fertig.php?rowid=' + rowid)
            .then(function() {
                if (getActivePageId() === 'DruckzielAnsicht') DruckzielAnsichtRefresh();
                else KuechenansichtRefresh();
            })
            .catch(onError);
    };

    /** rowids: eine ID oder „12,34,56“ (zusammengefasste Zeilen in der Fertig-Spalte) */
    window.kuechePositionOffen = function(rowids) {
        var s = (rowids === undefined || rowids === null) ? '' : String(rowids).trim();
        if (!s) return;
        if (!confirm('Position(en) wieder als „nicht fertig“ anzeigen?')) return;
        fetchPost('kueche_position_offen.php', 'rowids=' + encodeURIComponent(s))
            .then(function(r) {
                return r.text().then(function(text) {
                    var j = null;
                    try {
                        j = JSON.parse(text);
                    } catch (e) {
                        j = null;
                    }
                    if (!j || !j.ok) {
                        var msg = (j && j.error) ? j.error : (text && text.length < 200 ? text : 'Antwort ungültig (evtl. abgemeldet?)');
                        alert(msg);
                        if (text && (text.indexOf('login') !== -1 || text.indexOf('Kennwort') !== -1)) {
                            window.location.href = 'login.php';
                        }
                        throw new Error('kueche_position_offen');
                    }
                    return j;
                });
            })
            .then(function() {
                if (getActivePageId() === 'DruckzielAnsicht') DruckzielAnsichtRefresh();
                else if (getActivePageId() === 'Schankansicht') SchankAnsichtRefresh();
                else KuechenansichtRefresh();
            })
            .catch(function(err) {
                if (err && err.message === 'kueche_position_offen') return;
                onError();
            });
    };

    window.SchankFertig = function(rowid) {
        fetchGet('kueche_fertig.php?rowid=' + rowid)
            .then(function() {
                if (getActivePageId() === 'DruckzielAnsicht') DruckzielAnsichtRefresh();
                else SchankAnsichtRefresh();
            })
            .catch(onError);
    };

    window.BestellungBezahlt = function(arrayListe, direktverkauf) {
        if (window._paySubmitInFlight) return;
        if (!Array.isArray(arrayListe) || arrayListe.length === 0) {
            alert('Keine Positionen zum Bezahlen ausgewählt.');
            return;
        }
        var ids = arrayListe.map(function(x) { return parseInt(x, 10); }).filter(function(n) { return !isNaN(n) && n > 0; });
        if (ids.length === 0) {
            alert('Keine gültigen Positionen zum Bezahlen.');
            return;
        }
        window._paySubmitInFlight = true;
        var fd = new FormData();
        ids.forEach(function(p) { fd.append('listePositionen[]', p); });

        function clearPaySelection() {
            ids.forEach(function(r) { if (window._selPay) delete window._selPay[parseInt(r, 10)]; });
            if (window._aggSel) {
                for (var g in window._aggSel) {
                    if (window._aggSel[g] && Array.isArray(window._aggSel[g])) {
                        window._aggSel[g] = window._aggSel[g].filter(function(rid) {
                            return ids.indexOf(rid) === -1 && ids.indexOf(parseInt(rid, 10)) === -1;
                        });
                    }
                }
            }
            if (typeof window.applySelectionToUI === 'function') window.applySelectionToUI();
            else if (typeof window.updateButtonsAndSum === 'function') window.updateButtonsAndSum();
        }

        function finishOk() {
            window._paySubmitInFlight = false;
            clearPaySelection();
            if (direktverkauf === 1) {
                var bonId = typeof getDirektverkaufBonId === 'function' ? getDirektverkaufBonId() : '';
                function dvPdfBonAfterPayEnabled() {
                    if (typeof window.ffDvWantsPdfBonAfterPay === 'function') {
                        return !!window.ffDvWantsPdfBonAfterPay();
                    }
                    try {
                        return localStorage.getItem('dv_abholbon_pdf_browser') === '1';
                    } catch (e) {
                        return false;
                    }
                }
                function dvOpenPdfBon() {
                    if (!bonId) return;
                    window.open('direktverkauf_abholbon.php?bon_id=' + encodeURIComponent(bonId), '_blank', 'width=400,height=600');
                }
                function dvAfterPayUi() {
                    if (typeof window.ffDvToggleCart === 'function') {
                        window.ffDvToggleCart(false);
                    }
                    if (bonId && dvPdfBonAfterPayEnabled()) {
                        dvOpenPdfBon();
                    }
                    if (typeof resetDirektverkaufBonId === 'function') resetDirektverkaufBonId();
                    Direktverkauf();
                }
                if (bonId) {
                    if (dvPdfBonAfterPayEnabled()) {
                        // Nur PDF im Browser – kein Thermo-Job (kein doppelter Bon).
                        dvAfterPayUi();
                    } else {
                        var fdTh = new FormData();
                        fdTh.append('bon_id', bonId);
                        var dvPtSel = document.getElementById('ffDvBonThermoSelect');
                        if (dvPtSel && dvPtSel.value && String(dvPtSel.value).trim() !== '') {
                            fdTh.append('print_target', String(dvPtSel.value).trim());
                        }
                        fetch('direktverkauf_abholbon_thermo_enqueue.php', { method: 'POST', body: fdTh, cache: 'no-store', credentials: 'same-origin' })
                            .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
                            .then(function(x) {
                                if (!x.ok || !x.j || !x.j.ok) {
                                    var err = (x.j && x.j.error) ? x.j.error : 'unbekannt';
                                    if (err !== 'keine_daten') {
                                        alert('Thermo-Abholbon konnte nicht eingereiht werden: ' + err);
                                        if (confirm('Soll der Bon stattdessen als PDF im Browser geöffnet werden?')) {
                                            dvOpenPdfBon();
                                        }
                                    }
                                }
                            })
                            .catch(function() {
                                alert('Thermo-Abholbon: Server nicht erreichbar.');
                                if (confirm('Soll der Bon stattdessen als PDF im Browser geöffnet werden?')) {
                                    dvOpenPdfBon();
                                }
                            })
                            .finally(dvAfterPayUi);
                    }
                } else {
                    dvAfterPayUi();
                }
            } else {
                window._requirePaymentActive = false;
                TischBezahlen();
            }
        }

        function finishFail(msg) {
            window._paySubmitInFlight = false;
            alert(msg || 'Fehler: Der Eintrag konnte nicht gespeichert werden!');
        }

        fetch('BestellungBezahlt.php', { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
            .then(function(r) {
                return r.text().then(function(text) {
                    var j = null;
                    try { j = JSON.parse(text); } catch (e) { j = null; }
                    if (!r.ok || !j) {
                        if (text && (text.indexOf('login') !== -1 || text.indexOf('Kennwort') !== -1)) {
                            window.location.href = 'login.php';
                            return;
                        }
                        finishFail(j && j.error ? j.error : (text && text.length < 300 ? text : 'Serverfehler (' + r.status + ')'));
                        return;
                    }
                    if (!j.ok) {
                        finishFail(j.error || 'Zahlung abgelehnt');
                        return;
                    }
                    finishOk();
                });
            })
            .catch(function() {
                finishFail(null);
            });
    };

    window.EhrengastAbschliessen = function(arrayListe, tischnummer) {
        if (window._paySubmitInFlight) return;
        var ids = [];
        if (Array.isArray(arrayListe)) {
            ids = arrayListe.map(function(x) { return parseInt(x, 10); }).filter(function(n) { return !isNaN(n) && n > 0; });
        }
        if (ids.length === 0) {
            var pdEg = document.getElementById('PayData');
            if (pdEg && pdEg.getAttribute('data-array')) {
                try {
                    var parsed = JSON.parse(pdEg.getAttribute('data-array'));
                    if (Array.isArray(parsed)) {
                        ids = parsed.map(function(x) { return parseInt(x, 10); }).filter(function(n) { return !isNaN(n) && n > 0; });
                    }
                } catch (eEg) {}
            }
        }
        if (ids.length === 0) {
            alert('Keine offenen Positionen zum Abschließen.');
            return;
        }
        var tisch = parseInt(tischnummer, 10) || 0;
        if (!tisch) {
            tisch = parseInt(window.Tischnummer, 10) || 0;
        }
        if (!tisch) {
            var pdT = document.getElementById('PayData');
            if (pdT) tisch = parseInt(pdT.getAttribute('data-tischnummer'), 10) || 0;
        }
        if (!tisch) {
            alert('Tischnummer unbekannt.');
            return;
        }
        window._paySubmitInFlight = true;
        var fdEg = new FormData();
        ids.forEach(function(p) { fdEg.append('listePositionen[]', p); });
        fdEg.append('tischnummer', String(tisch));
        fetch('EhrengastAbschliessen.php', { method: 'POST', body: fdEg, cache: 'no-store', credentials: 'same-origin' })
            .then(function(r) {
                return r.text().then(function(text) {
                    var body = (text || '').trim();
                    if (!r.ok || body !== 'ok') {
                        if (body === 'not-allowed') {
                            alert('Ehrengast-Abschluss nicht erlaubt – ist der Tisch noch als Ehrengast markiert?');
                        } else if (body === 'no-positions') {
                            alert('Keine Positionen übermittelt.');
                        } else if (body && body.indexOf('login') !== -1) {
                            window.location.href = 'login.php';
                        } else {
                            alert(body && body.length < 300 ? body : 'Fehler beim Abschließen (' + r.status + ')');
                        }
                        return;
                    }
                    window._requirePaymentActive = false;
                    if (typeof window.ffLoadTischZahlen === 'function') {
                        window.ffLoadTischZahlen(tisch, false);
                    } else if (typeof window.tisch === 'function') {
                        window.tisch();
                    } else if (typeof window.TischBezahlen === 'function') {
                        window.TischBezahlen(tisch);
                    }
                });
            })
            .catch(function(err) {
                if (typeof onError === 'function') onError();
                else alert('Fehler: ' + (err && err.message ? err.message : err));
            })
            .finally(function() {
                window._paySubmitInFlight = false;
            });
    };

    function closePayMitRechnungModal() {
        var m = document.getElementById('payMitRechnungModal');
        if (m) m.style.display = 'none';
        window._payMitRechnungIds = null;
    }

    window.openPayMitRechnungModal = function(ids) {
        if (!Array.isArray(ids) || ids.length === 0) {
            alert('Keine Positionen ausgewählt.');
            return;
        }
        // Mehrere Bestellnummern auf eine Rechnung sind ausdrücklich erlaubt -
        // das Backend kümmert sich um Laufnummer + Anzeige aller Best.-Nrn.
        // Hier wird nur sichergestellt, dass mindestens eine echte Bestellnummer
        // dabei ist (also die Bestellung wirklich abgeschickt wurde).
        var map = window._ridOrderNr || {};
        var hasOrderNr = false;
        ids.forEach(function(rid) {
            var r = parseInt(rid, 10);
            var o = map[r];
            if (o === undefined || o === null) o = map[String(r)];
            o = parseInt(o, 10) || 0;
            if (o > 0) hasOrderNr = true;
        });
        if (!hasOrderNr) {
            alert('Keine Bestellnummer (Bestellung erst abschicken).');
            return;
        }
        window._payMitRechnungIds = ids.map(function(x) { return parseInt(x, 10); }).filter(function(n) { return !isNaN(n) && n > 0; });
        var modal = document.getElementById('payMitRechnungModal');
        if (!modal) {
            alert('Dialog nicht geladen – Seite kurz neu öffnen.');
            return;
        }
        modal.style.display = 'block';
    };

    window.submitPayMitRechnung = function() {
        if (window._paySubmitInFlight) return;
        var ids = window._payMitRechnungIds;
        if (!Array.isArray(ids) || ids.length === 0) {
            alert('Keine Positionen.');
            return;
        }
        var payMrModal = document.getElementById('payMitRechnungModal');
        var thermoSel = payMrModal ? payMrModal.querySelector('#payMrThermo') : document.getElementById('payMrThermo');
        var thermoZiel = thermoSel ? String(thermoSel.value || '0') : '0';
        var pdfCb = document.getElementById('payMrPdf');
        var openPdf = pdfCb && pdfCb.checked ? '1' : '0';
        var tisch = typeof window.Tischnummer !== 'undefined' ? parseInt(window.Tischnummer, 10) : 0;
        if (!tisch || tisch <= 0) {
            alert('Tischnummer unbekannt.');
            return;
        }
        var payMode = 'after';
        var pd = document.getElementById('PayData');
        if (pd) {
            var pm = pd.getAttribute('data-payment-mode');
            if (pm === 'instant') payMode = 'instant';
        }
        var sessionPt = 0;
        try {
            sessionPt = parseInt(sessionStorage.getItem('lastDruckzielId'), 10) || 0;
        } catch (e1) { sessionPt = 0; }

        window._paySubmitInFlight = true;
        var fd = new FormData();
        ids.forEach(function(p) { fd.append('listePositionen[]', p); });
        fd.append('tischnummer', String(tisch));
        fd.append('thermo_ziel', thermoZiel);
        fd.append('session_print_target', String(sessionPt));
        fd.append('open_pdf', openPdf);
        fd.append('payment_mode', payMode);

        function clearPaySelectionMr() {
            ids.forEach(function(r) { if (window._selPay) delete window._selPay[parseInt(r, 10)]; });
            if (window._aggSel) {
                for (var g in window._aggSel) {
                    if (window._aggSel[g] && Array.isArray(window._aggSel[g])) {
                        window._aggSel[g] = window._aggSel[g].filter(function(rid) {
                            return ids.indexOf(rid) === -1 && ids.indexOf(parseInt(rid, 10)) === -1;
                        });
                    }
                }
            }
            if (typeof window.applySelectionToUI === 'function') window.applySelectionToUI();
            else if (typeof window.updateButtonsAndSum === 'function') window.updateButtonsAndSum();
        }

        function finishOkMr() {
            window._paySubmitInFlight = false;
            closePayMitRechnungModal();
            clearPaySelectionMr();
            window._requirePaymentActive = false;
            if (typeof window.TischBezahlen === 'function') window.TischBezahlen();
        }

        function finishFailMr(msg) {
            window._paySubmitInFlight = false;
            alert(msg || 'Fehler bei Bezahlung mit Rechnung.');
        }

        fetch('bezahlung_mit_rechnung.php', { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
            .then(function(r) {
                return r.text().then(function(text) {
                    var j = null;
                    try { j = JSON.parse(text); } catch (e2) { j = null; }
                    if (!r.ok || !j) {
                        if (text && (text.indexOf('login') !== -1 || text.indexOf('Kennwort') !== -1)) {
                            window.location.href = 'login.php';
                            return;
                        }
                        finishFailMr(j && j.error ? j.error : (text && text.length < 400 ? text : 'Serverfehler (' + r.status + ')'));
                        return;
                    }
                    if (!j.ok) {
                        finishFailMr(j.error || 'Abgelehnt');
                        return;
                    }
                    if (openPdf === '1' && j.pdf_url) {
                        window.open(j.pdf_url, '_blank', 'noopener');
                    }
                    if (thermoZiel !== '0' && thermoZiel !== '' && !j.thermo_enqueued) {
                        var tErr = j.thermo_enqueue_error ? String(j.thermo_enqueue_error) : '';
                        var tid = j.thermo_print_target != null ? String(j.thermo_print_target) : '';
                        var hint = 'Hinweis: Thermo-Auftrag wurde nicht eingereiht.';
                        if (tErr) hint += '\n\nTechnisch: ' + tErr;
                        if (tid) hint += '\n\nDruckziel-ID: ' + tid + ' — der Print-Client muss dieselbe print_target in der config.ini verwenden.';
                        else hint += '\n\nDruckziel / Tabelle printer_jobs / Print-Client prüfen.';
                        alert(hint);
                    }
                    finishOkMr();
                });
            })
            .catch(function() {
                finishFailMr(null);
            });
    };

    window.paySelect = function(rid, betrag) {
        rid = parseInt(rid, 10);
        window._selPay = window._selPay || {};
        window._selPay[rid] = true;
        window._ridAmount = window._ridAmount || {};
        if (typeof betrag === 'number' || (typeof betrag === 'string' && betrag !== '')) {
            if (window._ridAmount[rid] == null || window._ridAmount[rid] === 0) {
                window._ridAmount[rid] = Number(betrag);
            }
        }
        var zeileEl = document.getElementById('zeile' + rid);
        var plusEl = document.getElementById('plus' + rid);
        var minusEl = document.getElementById('minus' + rid);
        if (zeileEl) {
            if (plusEl) plusEl.style.display = 'none';
            if (minusEl) minusEl.style.display = '';
            zeileEl.style.backgroundColor = '#66ff66';
        }
        if (typeof window.updateButtonsAndSum === 'function') window.updateButtonsAndSum();
    };
    window.payUnselect = function(rid) {
        rid = parseInt(rid, 10);
        window._selPay = window._selPay || {};
        delete window._selPay[rid];
        var zeileEl = document.getElementById('zeile' + rid);
        var plusEl = document.getElementById('plus' + rid);
        var minusEl = document.getElementById('minus' + rid);
        if (zeileEl) {
            if (minusEl) minusEl.style.display = 'none';
            if (plusEl) plusEl.style.display = '';
            zeileEl.style.backgroundColor = '';
        }
        if (typeof window.updateButtonsAndSum === 'function') window.updateButtonsAndSum();
    };
    window.aggPlus = function(groupId, preis) {
        groupId = String(groupId);
        var preisNum = Number(preis) || 0;
        var rows = [];
        var btn = document.getElementById('agg_plus_' + groupId);
        if (btn && btn.getAttribute('data-rows')) {
            try { rows = JSON.parse(btn.getAttribute('data-rows')); } catch (eAp) {}
        }
        window._aggSel = window._aggSel || {};
        window._selPay = window._selPay || {};
        if (!window._aggSel[groupId]) window._aggSel[groupId] = [];
        for (var i = 0; i < rows.length; i++) {
            var rid = parseInt(rows[i], 10);
            if (!window._selPay[rid]) {
                window._selPay[rid] = true;
                window._aggSel[groupId].push(rid);
                window._ridAmount = window._ridAmount || {};
                window._ridAmount[rid] = preisNum;
                var countEl = document.getElementById('agg_count_' + groupId);
                if (countEl) countEl.textContent = String(window._aggSel[groupId].length);
                if (typeof window.ffUpdateAggRowSums === 'function') window.ffUpdateAggRowSums();
                if (typeof window.updateButtonsAndSum === 'function') window.updateButtonsAndSum();
                return;
            }
        }
    };
    window.aggMinus = function(groupId) {
        groupId = String(groupId);
        window._aggSel = window._aggSel || {};
        if (!window._aggSel[groupId] || window._aggSel[groupId].length === 0) return;
        var rid = window._aggSel[groupId].pop();
        window._selPay = window._selPay || {};
        delete window._selPay[rid];
        var countEl = document.getElementById('agg_count_' + groupId);
        if (countEl) countEl.textContent = String(window._aggSel[groupId].length);
        if (typeof window.ffUpdateAggRowSums === 'function') window.ffUpdateAggRowSums();
        if (typeof window.updateButtonsAndSum === 'function') window.updateButtonsAndSum();
    };
    /** Alle offenen Positionen (PayData / arrayZahlung). */
    window.ffPayAllRowIds = function() {
        if (typeof window.ensurePayDataFromDom === 'function') window.ensurePayDataFromDom();
        var arr = window.arrayZahlung || [];
        if (!arr.length) {
            var pd = document.getElementById('PayData');
            try {
                if (pd && pd.getAttribute('data-array')) arr = JSON.parse(pd.getAttribute('data-array'));
            } catch (eArr) {}
        }
        var out = [];
        for (var i = 0; i < arr.length; i++) {
            var n = parseInt(arr[i], 10);
            if (!isNaN(n) && n > 0) out.push(n);
        }
        return out;
    };

    /** true = nur Teilauswahl; false = keine oder alle Positionen → Gesamt. */
    window.ffPayIsPartialSelection = function() {
        var selected = window.arrayZahlungGetrennt || [];
        if (!selected.length) return false;
        var all = window.ffPayAllRowIds();
        if (!all.length) return true;
        if (selected.length < all.length) return true;
        var set = {};
        for (var i = 0; i < selected.length; i++) set[parseInt(selected[i], 10)] = true;
        for (var j = 0; j < all.length; j++) {
            if (!set[all[j]]) return true;
        }
        return false;
    };

    /** IDs für Bezahlen / Bezahlen+Rechnung je nach Auswahl. */
    window.ffPayIdsForCheckout = function() {
        if (window.ffPayIsPartialSelection()) {
            return (window.arrayZahlungGetrennt || []).map(function(x) {
                return parseInt(x, 10);
            }).filter(function(n) { return !isNaN(n) && n > 0; });
        }
        return window.ffPayAllRowIds();
    };

    window.paySelectAll = function() {
        var pd = document.getElementById('PayData');
        if (pd) {
            if (pd.getAttribute('data-array')) {
                try { window.arrayZahlung = JSON.parse(pd.getAttribute('data-array')); } catch(e) {}
            }
            if (pd.getAttribute('data-amounts')) {
                try { window._ridAmount = JSON.parse(pd.getAttribute('data-amounts')); } catch(e) {}
            }
        }
        var arr = window.arrayZahlung;
        if (!Array.isArray(arr) || arr.length === 0) return;
        window._ridAmount = window._ridAmount || {};
        window._selPay = window._selPay || {};
        for (var i=0;i<arr.length;i++) { window._selPay[parseInt(arr[i],10)] = true; }
        if (typeof window.applySelectionToUI === 'function') window.applySelectionToUI();
        else if (typeof window.updateButtonsAndSum === 'function') window.updateButtonsAndSum();
    };
    window.payUnselectAll = function() {
        window._selPay = {};
        window._aggSel = {};
        window._payState = window._payState || {};
        window._payState.selPay = window._selPay;
        window._payState.aggSel = window._aggSel;
        document.querySelectorAll('[id^="zeile"]').forEach(function(el) { el.style.backgroundColor = ''; });
        document.querySelectorAll('[id^="minus"]').forEach(function(el) { el.style.display = 'none'; });
        document.querySelectorAll('[id^="plus"]').forEach(function(el) { el.style.display = ''; });
        document.querySelectorAll('.agg-count').forEach(function(el) { el.textContent = '0'; });
        if (typeof window.updateButtonsAndSum === 'function') window.updateButtonsAndSum();
    };
    window.formatEUR = function(num) { num = Number(num||0); return num.toFixed(2).replace(".",",") + " EUR"; };
    window.ffUpdateAggRowSums = function() {
        var aggCounts = document.querySelectorAll('.agg-count');
        var anySelected = false;
        var i;
        for (i = 0; i < aggCounts.length; i++) {
            if ((parseInt(aggCounts[i].textContent, 10) || 0) > 0) {
                anySelected = true;
                break;
            }
        }
        aggCounts.forEach(function(el) {
            var groupId = String(el.getAttribute('data-group') || '');
            if (!groupId) return;
            var count = parseInt(el.textContent, 10) || 0;
            var btn = document.getElementById('agg_plus_' + groupId);
            var preis = btn ? (parseFloat(btn.getAttribute('data-preis')) || 0) : 0;
            var sumEl = document.getElementById('agg_sum_' + groupId);
            if (!sumEl) return;
            var full = parseFloat(sumEl.getAttribute('data-summe-full')) || 0;
            if (!anySelected) {
                sumEl.textContent = window.formatEUR(full);
            } else if (count <= 0) {
                sumEl.textContent = window.formatEUR(0);
            } else {
                sumEl.textContent = window.formatEUR(Math.round(count * preis * 100) / 100);
            }
        });
    };
    window.ensurePayDataFromDom = function() {
        var pd = document.getElementById('PayData');
        if (!pd) return;
        try {
            if (pd.getAttribute('data-array')) window.arrayZahlung = JSON.parse(pd.getAttribute('data-array'));
            if (pd.getAttribute('data-amounts')) window._ridAmount = JSON.parse(pd.getAttribute('data-amounts'));
        } catch (ePd) {}
    };
    window.ensureRidAmountFromDom = function() {
        var pd = document.getElementById('PayData');
        if (!pd || !pd.getAttribute('data-amounts')) return;
        try {
            var data = JSON.parse(pd.getAttribute('data-amounts'));
            if (data && typeof data === 'object') window._ridAmount = data;
        } catch (eRa) {}
    };
    window.recomputeFromState = function() {
        window.ensureRidAmountFromDom();
        window._selPay = window._selPay || {};
        var keys = Object.keys(window._selPay);
        var selected = [];
        var i;
        for (i = 0; i < keys.length; i++) {
            var rid = parseInt(keys[i], 10);
            if (window._selPay[rid]) selected.push(rid);
        }
        window._ridAmount = window._ridAmount || {};
        var sum = 0;
        var j;
        for (j = 0; j < selected.length; j++) {
            var r = selected[j];
            var amt = window._ridAmount[r];
            if (amt == null || amt === '' || Number(amt) === 0) {
                var btn = document.getElementById('plus' + r);
                if (btn && btn.getAttribute('data-betrag')) {
                    amt = parseFloat(btn.getAttribute('data-betrag')) || 0;
                    window._ridAmount[r] = amt;
                } else {
                    amt = 0;
                }
            } else {
                amt = Number(amt);
            }
            sum += amt;
        }
        sum = Math.round(sum * 100) / 100;
        if (selected.length > 0 && sum === 0) {
            window.ensureRidAmountFromDom();
            sum = 0;
            for (j = 0; j < selected.length; j++) {
                sum += Number(window._ridAmount[selected[j]] || 0);
            }
            sum = Math.round(sum * 100) / 100;
        }
        window.arrayZahlungGetrennt = selected;
        window.BetragEinzelnBezahlen = sum;
    };
    window.updateButtonsAndSum = function() {
        if (typeof window.ensurePayDataFromDom === 'function') window.ensurePayDataFromDom();
        if (typeof window.recomputeFromState === 'function') window.recomputeFromState();
        var partial = typeof window.ffPayIsPartialSelection === 'function'
            ? window.ffPayIsPartialSelection()
            : ((window.arrayZahlungGetrennt || []).length > 0);
        var btnGesamt = document.getElementById('btnBezahlenGesamt');
        var btnEinzeln = document.getElementById('btnBezahlenGesamtEinzeln');
        var btnMr = document.getElementById('btnBezahlenMitRechnung');
        var summeEl = document.getElementById('summeZahlung');
        if (partial) {
            if (btnGesamt) btnGesamt.style.display = 'none';
            if (btnEinzeln) btnEinzeln.style.display = '';
            if (btnMr) btnMr.textContent = 'Bezahlen + Rechnung';
            if (summeEl) summeEl.textContent = window.formatEUR(window.BetragEinzelnBezahlen || 0);
        } else {
            if (btnEinzeln) btnEinzeln.style.display = 'none';
            if (btnGesamt) btnGesamt.style.display = '';
            if (btnMr) btnMr.textContent = 'Bezahlen Gesamt + Rechnung';
            if (summeEl) summeEl.textContent = window.formatEUR(window._payTotal || 0);
        }
        if (typeof window.ffUpdateAggRowSums === 'function') window.ffUpdateAggRowSums();
    };
    window.applySelectionToUI = function() {
        window._selPay = window._selPay || {};
        var selected = Object.keys(window._selPay);
        var i;
        for (i = 0; i < selected.length; i++) {
            var rid = parseInt(selected[i], 10);
            if (!window._selPay[rid]) continue;
            var zeileEl = document.getElementById('zeile' + rid);
            var plusEl = document.getElementById('plus' + rid);
            var minusEl = document.getElementById('minus' + rid);
            if (zeileEl) {
                zeileEl.style.backgroundColor = '#66ff66';
                if (plusEl) plusEl.style.display = 'none';
                if (minusEl) minusEl.style.display = '';
            }
        }
        document.querySelectorAll('.agg-count').forEach(function(el) {
            var groupId = String(el.getAttribute('data-group') || '');
            if (!groupId) return;
            var aggPlusBtn = document.getElementById('agg_plus_' + groupId);
            var rows = [];
            if (aggPlusBtn && aggPlusBtn.getAttribute('data-rows')) {
                try { rows = JSON.parse(aggPlusBtn.getAttribute('data-rows')); } catch (eAs) {}
            }
            var picked = [];
            var k;
            for (k = 0; k < rows.length; k++) {
                var rid2 = parseInt(rows[k], 10);
                if (window._selPay[rid2]) picked.push(rid2);
            }
            window._aggSel = window._aggSel || {};
            window._aggSel[groupId] = picked;
            var countEl = document.getElementById('agg_count_' + groupId);
            if (countEl) countEl.textContent = String(picked.length);
        });
        window.updateButtonsAndSum();
    };

    window.schankGesamtFertig = function(arrayListe, tischnummer) {
        var fd = new FormData();
        if (Array.isArray(arrayListe)) arrayListe.forEach(function(p) { fd.append('listePositionen[]', p); });
        fd.append('tischnummer', tischnummer);
        fetch('kueche_fertig_tisch.php', { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
            .then(function() {
                if (getActivePageId() === 'DruckzielAnsicht') DruckzielAnsichtRefresh();
                else SchankAnsichtRefresh();
            })
            .catch(onError);
    };

    window.kuecheGesamtFertig = function(arrayListe, tischnummer) {
        var fd = new FormData();
        if (Array.isArray(arrayListe)) arrayListe.forEach(function(p) { fd.append('listePositionen[]', p); });
        fd.append('tischnummer', tischnummer || 0);
        fetch('kueche_fertig_tisch.php', { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
            .then(function() {
                if (getActivePageId() === 'DruckzielAnsicht') DruckzielAnsichtRefresh();
                else KuechenansichtRefresh();
            })
            .catch(onError);
    };

    window.bestellungAbschliessen = function(arrayListe, tischnummer, mitDruck) {
        if (mitDruck === undefined || mitDruck === null || mitDruck === '') {
            mitDruck = true;
        } else {
            mitDruck = mitDruck === true || mitDruck === 1 || mitDruck === '1';
        }
        var fd = new FormData();
        if (Array.isArray(arrayListe)) arrayListe.forEach(function(p) { fd.append('listePositionen[]', p); });
        fd.append('tischnummer', tischnummer || 0);
        fd.append('mit_druck', mitDruck ? '1' : '0');
        fetch('bestellung_abschliessen.php', { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res && res.ok) {
                    if (getActivePageId() === 'DruckzielAnsicht') DruckzielAnsichtRefresh();
                    else if (getActivePageId() === 'Schankansicht') SchankAnsichtRefresh();
                    else KuechenansichtRefresh();
                } else { onError(); }
            })
            .catch(onError);
    };

    window.stationTeillieferungDruck = function(arrayListe, tischnummer, printTarget) {
        if (!Array.isArray(arrayListe) || arrayListe.length === 0) {
            alert('Keine fertigen Positionen für die Teillieferung.');
            return;
        }
        var fd = new FormData();
        arrayListe.forEach(function(p) { fd.append('listePositionen[]', p); });
        fd.append('tischnummer', String(tischnummer || 0));
        fd.append('print_target', String(printTarget || 0));
        fetch('station_teillieferung_druck.php', { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res && res.ok) {
                    if (getActivePageId() === 'DruckzielAnsicht') DruckzielAnsichtRefresh();
                    else if (getActivePageId() === 'Schankansicht') SchankAnsichtRefresh();
                    else KuechenansichtRefresh();
                } else {
                    alert((res && res.message) ? res.message : 'Teillieferung-Druck fehlgeschlagen.');
                }
            })
            .catch(onError);
    };

    window.bestellungLoeschen = function(rowid, tischnummer, stayInHistory) {
        fetchGet('bestellung_loeschen.php?rowid=' + rowid)
            .then(function() {
                Summe = 0;
                ffSyncTischRequirePaymentFromServer(tischnummer).then(function() {
                    if (stayInHistory) {
                        loadContent('list_Bestellungen.php?tischnummer=' + tischnummer, 'Bestellungen');
                    } else {
                        tisch(tischnummer);
                    }
                });
            })
            .catch(onError);
    };

    window.ffRunBezStorno = function(rowid, tischnummer, reloadFn) {
        fetch('bestellung_bez_storno.php?rowid=' + encodeURIComponent(String(rowid)), { cache: 'no-store', credentials: 'same-origin' })
            .then(function(r) { return r.json().then(function(j) { return { r: r, j: j }; }); })
            .then(function(x) {
                if (!x.r.ok || !x.j || !x.j.ok) {
                    alert((x.j && x.j.message) ? x.j.message : 'Bezahl-Storno nicht möglich.');
                    return;
                }
                if (x.j.message) {
                    alert(x.j.message);
                }
                Summe = 0;
                ffSyncTischRequirePaymentFromServer(tischnummer).then(function() {
                    if (typeof reloadFn === 'function') {
                        reloadFn();
                    } else if (tischnummer) {
                        tisch(tischnummer);
                    }
                });
            })
            .catch(onError);
    };

    window.bestellungBezStorno = function(rowid, tischnummer, stillOpenAtStation) {
        var extra = stillOpenAtStation
            ? '\n\nDie Position ist in Küche/Schank noch nicht ausgeliefert — sie wird dort ebenfalls entfernt.'
            : '';
        if (!confirm('Bezahlung wirklich stornieren (Rückerstattung)?' + extra + '\n\nDie Position zählt danach nicht mehr in der Kellner-Abrechnung.')) {
            return;
        }
        window.ffRunBezStorno(rowid, tischnummer, function() {
            if (typeof loadContent === 'function') {
                loadContent('list_Bestellungen.php?tischnummer=' + tischnummer, 'Bestellungen');
            } else {
                tisch(tischnummer);
            }
        });
    };

    /** Modal: Position(en) auf anderen Tisch verschieben (Historie, Zahlen, Bestell-History). */
    window.ffEnsureTischVerschiebenModal = function() {
        if (document.getElementById('ffTischVerschiebenModal')) {
            return;
        }
        var modal = document.createElement('div');
        modal.id = 'ffTischVerschiebenModal';
        modal.style.cssText = 'display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.55);z-index:10050;padding:20px;';
        modal.innerHTML = '<div style="background:#fff;max-width:440px;margin:50px auto;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.3);">'
            + '<div style="padding:14px 16px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">'
            + '<strong id="ffTischVerschiebenTitle">Tisch ändern</strong>'
            + '<button type="button" id="ffTischVerschiebenClose" style="border:none;background:none;font-size:24px;cursor:pointer;">&times;</button>'
            + '</div><div style="padding:16px;">'
            + '<p id="ffTischVerschiebenInfo" class="mb-2 small text-muted"></p>'
            + '<label class="form-label small">Neuer Tisch:</label>'
            + '<select id="ffTischVerschiebenSelect" class="form-select mb-3" style="font-size:16px;"><option value="">-- Tisch wählen --</option></select>'
            + '<div class="alert alert-warning small py-2 px-2 mb-3">Der bereits gedruckte Bon zeigt weiterhin die alte Tischnummer — bitte ggf. Küche/Schank informieren.</div>'
            + '<div class="d-flex gap-2">'
            + '<button type="button" class="btn btn-secondary flex-fill" id="ffTischVerschiebenCancel">Abbrechen</button>'
            + '<button type="button" class="btn btn-primary flex-fill" id="ffTischVerschiebenOk">Verschieben</button>'
            + '</div></div></div>';
        document.body.appendChild(modal);
        document.getElementById('ffTischVerschiebenClose').addEventListener('click', function() { modal.style.display = 'none'; });
        document.getElementById('ffTischVerschiebenCancel').addEventListener('click', function() { modal.style.display = 'none'; });
    };

    /**
     * @param {object} opts
     * @param {number} opts.currentTisch
     * @param {number[]} [opts.rowids]
     * @param {number} [opts.orderNr]
     * @param {string} [opts.batchTimestamp]
     * @param {string} [opts.label]
     * @param {function} [opts.onSuccess]
     */
    window.ffOpenTischVerschiebenModal = function(opts) {
        opts = opts || {};
        var currentTisch = parseInt(opts.currentTisch, 10) || 0;
        var rowids = Array.isArray(opts.rowids) ? opts.rowids.map(function(x) { return parseInt(x, 10); }).filter(function(x) { return x > 0; }) : [];
        var orderNr = parseInt(opts.orderNr, 10) || 0;
        var batchTimestamp = opts.batchTimestamp ? String(opts.batchTimestamp) : '';
        var label = opts.label ? String(opts.label) : '';

        if (rowids.length === 0 && orderNr <= 0 && batchTimestamp === '') {
            alert('Nichts zum Verschieben ausgewählt.');
            return;
        }

        window.ffEnsureTischVerschiebenModal();
        var modal = document.getElementById('ffTischVerschiebenModal');
        var info = document.getElementById('ffTischVerschiebenInfo');
        var sel = document.getElementById('ffTischVerschiebenSelect');
        var okBtn = document.getElementById('ffTischVerschiebenOk');

        if (info) {
            info.textContent = label || (rowids.length > 1
                ? (rowids.length + ' Position(en) verschieben:')
                : '1 Position verschieben:');
        }

        sel.innerHTML = '<option value="">-- Tische werden geladen ... --</option>';
        fetch('list_tische_json.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                sel.innerHTML = '<option value="">-- Tisch wählen --</option>';
                if (data && data.ok && Array.isArray(data.tische)) {
                    data.tische.forEach(function(t) {
                        var tn = parseInt(t.tischnummer, 10);
                        if (tn === currentTisch || tn === 999999) {
                            return;
                        }
                        var opt = document.createElement('option');
                        opt.value = String(tn);
                        opt.textContent = (t.tischname || ('Tisch ' + tn)) + ' (#' + tn + ')';
                        sel.appendChild(opt);
                    });
                }
            })
            .catch(function() {
                sel.innerHTML = '<option value="">Fehler beim Laden</option>';
            });

        okBtn.onclick = function() {
            var ziel = parseInt(sel.value, 10);
            if (!ziel || ziel <= 0) {
                alert('Bitte einen Zieltisch auswählen.');
                return;
            }
            var fd = new FormData();
            fd.append('ziel_tischnummer', String(ziel));
            fd.append('source_tischnummer', String(currentTisch));
            if (orderNr > 0) {
                fd.append('order_nr', String(orderNr));
            } else if (batchTimestamp !== '') {
                fd.append('batch_timestamp', batchTimestamp);
            } else {
                rowids.forEach(function(rid) { fd.append('listePositionen[]', String(rid)); });
            }
            okBtn.disabled = true;
            fetch('bestellung_verschieben.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    okBtn.disabled = false;
                    if (!res || !res.ok) {
                        alert((res && res.error) ? res.error : 'Verschieben fehlgeschlagen.');
                        return;
                    }
                    if ((res.moved || 0) < 1) {
                        alert('Keine Position konnte verschoben werden (z. B. bereits ausgeliefert oder keine Berechtigung).');
                        return;
                    }
                    var msg = (res.moved || 0) + ' Position(en) verschoben.';
                    if ((res.skipped || 0) > 0) {
                        msg += ' (' + res.skipped + ' übersprungen)';
                    }
                    alert(msg);
                    modal.style.display = 'none';
                    if (typeof opts.onSuccess === 'function') {
                        opts.onSuccess(res);
                    }
                })
                .catch(function() {
                    okBtn.disabled = false;
                    alert('Netzwerkfehler beim Verschieben.');
                });
        };

        modal.style.display = 'block';
    };

    /** Einzelne Position aus Tisch-Historie. */
    window.ffTischHistVerschiebenEine = function(rowid, tischnummer) {
        window.ffOpenTischVerschiebenModal({
            currentTisch: tischnummer,
            rowids: [rowid],
            label: 'Diese Position auf anderen Tisch verschieben:',
            onSuccess: function() {
                if (typeof TischAnsichtHistory === 'function') {
                    TischAnsichtHistory();
                } else if (typeof loadContent === 'function') {
                    loadContent('list_Bestellungen.php?tischnummer=' + tischnummer, 'Bestellungen');
                }
            }
        });
    };

    /** Ganze Bestellungsrunde aus Tisch-Historie. */
    window.ffTischHistVerschiebenBestellung = function(tischnummer, orderNr, batchTimestamp, label) {
        window.ffOpenTischVerschiebenModal({
            currentTisch: tischnummer,
            orderNr: orderNr || 0,
            batchTimestamp: batchTimestamp || '',
            label: label || 'Ganze Bestellung auf anderen Tisch verschieben:',
            onSuccess: function() {
                if (typeof TischAnsichtHistory === 'function') {
                    TischAnsichtHistory();
                } else if (typeof loadContent === 'function') {
                    loadContent('list_Bestellungen.php?tischnummer=' + tischnummer, 'Bestellungen');
                }
            }
        });
    };

    window.ffRunStornoBatch = function(sourceTisch, orderNr, batchTimestamp, confirmText, reloadFn, bonId) {
        if (!confirm(confirmText || 'Ganze Bestellung wirklich stornieren?')) {
            return;
        }
        var fd = new FormData();
        fd.append('source_tischnummer', String(sourceTisch));
        if (bonId && String(bonId).trim() !== '') {
            fd.append('bon_id', String(bonId).trim());
        } else if (parseInt(orderNr, 10) > 0) {
            fd.append('order_nr', String(orderNr));
        } else if (batchTimestamp) {
            fd.append('batch_timestamp', String(batchTimestamp));
        } else {
            alert('Bestellung konnte nicht identifiziert werden.');
            return;
        }
        fetch('bestellung_storno_batch.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json().then(function(j) { return { r: r, j: j }; }); })
            .then(function(x) {
                if (!x.r.ok || !x.j || !x.j.ok) {
                    alert((x.j && (x.j.message || x.j.error)) ? (x.j.message || x.j.error) : 'Storno fehlgeschlagen.');
                    return;
                }
                if (x.j.message) {
                    alert(x.j.message);
                }
                Summe = 0;
                ffSyncTischRequirePaymentFromServer(sourceTisch).then(function() {
                    if (typeof reloadFn === 'function') {
                        reloadFn();
                    }
                });
            })
            .catch(onError);
    };

    /** Einzelposition stornieren (nur Admin). */
    window.ffHistStornierenEine = function(rowid, tischnummer, isPaid, stillOpenAtStation) {
        var msg;
        if (isPaid && !stillOpenAtStation) {
            msg = 'Bezahlung wirklich stornieren (Rückerstattung)?\n\nDie Position war bereits ausgeliefert — nur die Bezahlung wird zurückgesetzt.\n\nZählt danach nicht mehr in der Kellner-Abrechnung.';
        } else if (isPaid) {
            msg = 'Position wirklich stornieren?\n\nBezahlung wird zurückgesetzt und die Position aus Küche/Schank entfernt (noch nicht ausgeliefert).';
        } else {
            msg = 'Position wirklich stornieren?\n\nSie wird aus Küche/Schank und der Druckwarteschlange entfernt.';
        }
        if (!confirm(msg)) {
            return;
        }
        var url = isPaid
            ? 'bestellung_bez_storno.php?rowid=' + encodeURIComponent(String(rowid))
            : 'bestellung_loeschen.php?rowid=' + encodeURIComponent(String(rowid));
        fetch(url, { cache: 'no-store', credentials: 'same-origin' })
            .then(function(r) { return r.json().then(function(j) { return { r: r, j: j }; }); })
            .then(function(x) {
                if (!x.r.ok || !x.j || !x.j.ok) {
                    alert((x.j && x.j.message) ? x.j.message : 'Storno nicht möglich.');
                    return;
                }
                if (x.j.message) {
                    alert(x.j.message);
                }
                Summe = 0;
                ffSyncTischRequirePaymentFromServer(tischnummer).then(function() {
                    if (typeof TischAnsichtHistory === 'function') {
                        TischAnsichtHistory();
                    } else if (typeof loadContent === 'function' && tischnummer) {
                        loadContent('list_Bestellungen.php?tischnummer=' + tischnummer, 'Bestellungen');
                    } else if (tischnummer) {
                        tisch(tischnummer);
                    } else {
                        window.location.reload();
                    }
                });
            })
            .catch(onError);
    };

    /** Ganze Bestellungsrunde stornieren (Kellner: nur wenn unbezahlt). */
    window.ffHistStornierenBestellung = function(sourceTisch, orderNr, batchTimestamp, bonId, label) {
        if (typeof label === 'undefined') {
            label = bonId || '';
            bonId = '';
        }
        window.ffRunStornoBatch(sourceTisch, orderNr, batchTimestamp, label, function() {
            window.location.reload();
        }, bonId || '');
    };

    window.ffTischHistStornierenBestellung = function(tischnummer, orderNr, batchTimestamp, bonId, label) {
        if (typeof label === 'undefined') {
            label = bonId || '';
            bonId = '';
        }
        window.ffRunStornoBatch(tischnummer, orderNr, batchTimestamp, label, function() {
            if (typeof TischAnsichtHistory === 'function') {
                TischAnsichtHistory();
            } else if (typeof loadContent === 'function') {
                loadContent('list_Bestellungen.php?tischnummer=' + tischnummer, 'Bestellungen');
            }
        }, bonId || '');
    };

    window.Direkt_reset = function() {
        var now = Date.now();
        if (window._ffDvResetBusy || (window._ffDvResetLast && (now - window._ffDvResetLast) < 500)) {
            return;
        }
        if (!confirm('Neuer Kunde? Aktuelle Bestellungen werden zurückgesetzt.')) {
            return;
        }
        window._ffDvResetBusy = true;
        window._ffDvResetLast = now;
        fetchGet('Direkt_reset.php?cmd=direkt_reset')
            .then(function(r) {
                if (!r.ok) throw new Error('http');
                return r.text();
            })
            .then(function() {
                var bonPromise = (typeof resetDirektverkaufBonId === 'function')
                    ? resetDirektverkaufBonId()
                    : Promise.resolve();
                return bonPromise;
            })
            .then(function() {
                try { localStorage.removeItem('direktverkauf_bon_id'); } catch (eLs) { /* ignore */ }
                Direktverkauf();
            })
            .catch(onError)
            .finally(function() {
                window._ffDvResetBusy = false;
            });
    };

    window.resetBestellungen = function() {
        alert('ACHTUNG: Alle Bestellungen und die Druck-Warteschlange werden unwiderruflich gelöscht. Nur Super-Admin.');
        if (!confirm('Alle Bestellungen und Druck-Jobs wirklich LÖSCHEN?')) return;
        if (!confirm('Letzte Rückfrage: TRUNCATE — alles weg?')) return;
        fetchGet('reset.php?cmd=reset')
            .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
            .then(function(x) {
                if (!x.ok || !x.j || !x.j.ok) {
                    alert((x.j && x.j.error) ? x.j.error : 'Nicht erlaubt oder Fehler.');
                    return;
                }
                alert(x.j.message || 'Erledigt.');
            })
            .catch(onError);
    };

    window.saveNeuerTisch = function() {
        var name = ($('neuerTischName')||{}).value || '';
        var farbe = ($('neueTischFarbe')||{}).value || '';
        var x = ($('neueTischX')||{}).value || '';
        var y = ($('neueTischY')||{}).value || '';
        if (!name) { alert("Eingabefeld darf nicht leer sein!"); return; }
        var data = "neuerTischName=" + encodeURIComponent(name) + "&neueTischFarbe=" + encodeURIComponent(farbe) + "&neueTischX=" + encodeURIComponent(x) + "&neueTischY=" + encodeURIComponent(y);
        fetchPost('neuerTisch_save.php', data)
            .then(function(r) { return r.text(); })
            .then(function(text) {
                if ($('neuerTischName')) $('neuerTischName').value = "";
                if ($('neueTischFarbe')) $('neueTischFarbe').value = "";
                if ($('neueTischX')) $('neueTischX').value = "";
                if ($('neueTischY')) $('neueTischY').value = "";
                alert(text);
            })
            .catch(onError);
    };

    window.updateKapazitaet = function(position, kapazitaet) {
        var k = prompt("Neue Kapazitaet:", kapazitaet);
        if (k === null) return;
        fetchGet('update_kapazitaet.php?rowid=' + position + '&kapazitaet=' + encodeURIComponent(k))
            .then(function() { AdminAnsicht(); })
            .catch(onError);
    };

    window.updatePW = function(userid) {
        var pw = prompt("Neues Passwort:");
        if (pw == null || pw === "") return;
        fetchPost('update_pw.php', 'pw=' + encodeURIComponent(pw) + '&userid=' + userid)
            .then(function() { alert("Passwort geaendert"); })
            .catch(onError);
    };

    window.printSinglePositionen = function(arrayListe, tischnummer) {
        if (!Array.isArray(arrayListe) || arrayListe.length === 0) {
            alert('Keine fertigen Positionen zum Drucken (Bon ist leer).');
            return;
        }
        var fd = new FormData();
        arrayListe.forEach(function(p) { fd.append('listePositionen[]', p); });
        fd.append('tischnummer', String(tischnummer));
        fetch('kueche_bon_browser.php', { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
            .then(function(r) {
                if (!r.ok) throw new Error('http');
                return r.text();
            })
            .then(function(html) {
                var w = window.open('', '_blank');
                if (!w) {
                    alert('Pop-up wurde blockiert – Bon konnte nicht geöffnet werden. Bitte Pop-ups für diese Seite erlauben.');
                    return;
                }
                w.document.open();
                w.document.write(html);
                w.document.close();
                ffRefreshOperationsAfterThermo();
            })
            .catch(onError);
    };

    var _ffKuecheDruckState = { liste: [], tischnummer: 0, printTarget: 11 };

    function ffRefreshOperationsAfterThermo() {
        try {
            if (window._pauseOperationsPoll) return;
            var pages = document.querySelectorAll('[data-page].active');
            var pid = pages.length ? pages[0].id : '';
            if (pid === 'DruckzielAnsicht' && typeof window.DruckzielAnsichtRefresh === 'function') window.DruckzielAnsichtRefresh();
            else if (pid === 'Schankansicht' && typeof window.SchankAnsichtRefresh === 'function') window.SchankAnsichtRefresh();
            else if (pid === 'Kuechenansicht' && typeof window.KuechenansichtRefresh === 'function') window.KuechenansichtRefresh();
        } catch (e1) {}
    }

    window.ffKuecheDruckDialog = function(arrayListe, tischnummer, printTarget) {
        if (!Array.isArray(arrayListe) || arrayListe.length === 0) {
            alert('Keine fertigen Positionen zum Drucken (Bon ist leer).');
            return;
        }
        var pt = parseInt(String(printTarget), 10);
        if (!pt || pt < 1) pt = 11;
        _ffKuecheDruckState = { liste: arrayListe.slice(), tischnummer: tischnummer | 0, printTarget: pt };
        var modalEl = document.getElementById('ffKuecheDruckModal');
        if (!modalEl || typeof bootstrap === 'undefined') {
            window.printSinglePositionen(arrayListe, tischnummer);
            return;
        }
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    window.refreshAbschickenButton = function(tischnummer) {
        fetchPost('bestellung_has_items.php', { tischnummer: tischnummer })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                var btn = $('btnBestellungAbschicken');
                if (!btn) return;
                var has = resp && resp.ok && resp.has_items === 1;
                var tnum = parseInt(String(tischnummer), 10) || 0;
                if (tnum > 0 && tnum !== 999999 && resp && resp.ok) {
                    window._ffTischUnsentCount = parseInt(resp.count, 10) || (has ? 1 : 0);
                    window._ffTischUnsentTable = tnum;
                }
                btn.disabled = !has;
                if (has) {
                    btn.classList.remove('disabled');
                    btn.style.opacity = '1'; btn.style.cursor = 'pointer';
                    btn.style.backgroundColor = '#00ff04'; btn.style.color = '#000';
                } else {
                    btn.classList.add('disabled');
                    btn.style.opacity = '0.6'; btn.style.cursor = 'not-allowed';
                    btn.style.backgroundColor = '#cccccc'; btn.style.color = '#666';
                }
            });
    };

    window.verifyPwGate = function() {
        var el = $('pw_gate_current');
        var currentPw = el ? el.value : '';
        if (!currentPw) { alert('Bitte aktuelles Passwort eingeben.'); return; }
        fetchPost('pw_verify.php', { current_password: currentPw })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res && res.ok) {
                    if (el) el.value = '';
                    showPage('pwChangePage');
                } else alert('Passwort stimmt nicht.');
            })
            .catch(function(xhr) { alert('Fehler: ' + (xhr.responseText || '')); });
    };

    window.changeOwnPassword = function() {
        var pw1 = ($('pw_new_1')||{}).value || '';
        var pw2 = ($('pw_new_2')||{}).value || '';
        if (!pw1 || !pw2) { alert('Bitte beide Felder ausfüllen.'); return; }
        if (pw1.length < 6) { alert('Passwort zu kurz (min. 6 Zeichen).'); return; }
        if (pw1 !== pw2) { alert('Passwörter stimmen nicht überein.'); return; }
        fetchPost('pw_change_own.php', { new_password: pw1, new_password_again: pw2 })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res && res.ok) {
                    if ($('pw_new_1')) $('pw_new_1').value = '';
                    if ($('pw_new_2')) $('pw_new_2').value = '';
                    window.FF_FORCE_PW_CHANGE = 0;
                    alert('Passwort geändert.');
                    ffNavigateHome();
                } else alert('Fehler: ' + (res && res.error ? res.error : 'unbekannt'));
            })
            .catch(function(xhr) { alert('Fehler: ' + (xhr.responseText || '')); });
    };

    /**
     * 1× Position entfernen (Tisch + Direktverkauf). Aufruf aus onclick: minusPosition(event, tischnummer, positionId, type)
     * type: 1 = Speisen, 0 = Getränke (für ffReloadTischKarteTab)
     */
    window.minusPosition = function(e, tischnummer, position, type) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        var tnum = parseInt(String(tischnummer), 10) || 0;
        var pos = parseInt(String(position), 10) || 0;
        var ty = type === undefined || type === null ? 1 : parseInt(String(type), 10);
        if (isNaN(ty)) ty = 1;

        var formData = new FormData();
        formData.append('tischnummer', String(tnum));
        formData.append('position', String(pos));
        formData.append('type', String(ty));

        fetch('bestellung_minus.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res || !res.ok || !res.removed) return;
                if (tnum === 999999) {
                    ffDvAfterCartChange(res);
                    return;
                }
                if (ffUpdatePosTileFromSave(pos, res)) {
                    if (typeof refreshAbschickenButton === 'function') {
                        refreshAbschickenButton(tnum);
                    }
                    return;
                }
                var cntEl = document.getElementById('cnt-' + pos);
                var btnEl = document.getElementById('btn-pos-' + pos);
                if (!cntEl || !btnEl) return;

                var next = typeof res.open_cnt === 'number'
                    ? Math.max(0, parseInt(String(res.open_cnt), 10) || 0)
                    : Math.max((parseInt(cntEl.getAttribute('data-cnt') || '0', 10) || 0) - 1, 0);
                cntEl.setAttribute('data-cnt', String(next));

                if (next > 0) {
                    cntEl.textContent = ' (' + next + 'x)';
                    btnEl.classList.add('pos-tile--selected');
                    btnEl.style.background = '';
                    btnEl.style.color = '';
                } else {
                    cntEl.textContent = '';
                    btnEl.classList.remove('pos-tile--selected');
                    var wrap = btnEl.closest('.posWrap');
                    var baseBg = wrap ? wrap.getAttribute('data-basebg') || '#ffffff' : '#ffffff';
                    btnEl.style.background = baseBg;
                    btnEl.style.color = '';
                }
                ffUpdatePosTileStockVisual(btnEl, res);
                if (typeof refreshAbschickenButton === 'function') {
                    refreshAbschickenButton(tnum);
                }
            })
            .catch(function() {});
    };

    window.logout = function() {
        window.location.href = "logout.php";
    };

    /** Speise-/Getränkekarte: nur eine Unterkategorie anzeigen (Farben der Kacheln unverändert). */
    window.ffApplyPosSubcatFilter = function(rootSel, key) {
        var root = typeof rootSel === 'string' ? document.querySelector(rootSel) : rootSel;
        if (!root) return;
        var k = key === undefined || key === null ? '_all_' : String(key);
        root.querySelectorAll('.pos-subcat-section').forEach(function(sec) {
            var sk = sec.getAttribute('data-subkey') || '';
            sec.style.display = (k === '_all_' || sk === k) ? '' : 'none';
        });
        var wrap = root.querySelector('.pos-subcat-filter');
        if (wrap) {
            wrap.querySelectorAll('.ff-subcat-chip').forEach(function(btn) {
                var bk = btn.getAttribute('data-subkey');
                if (bk === null || bk === undefined) bk = '_all_';
                var on = (String(bk) === k);
                btn.classList.toggle('btn-primary', on);
                btn.classList.toggle('btn-outline-secondary', !on);
            });
        }
    };

    document.addEventListener('click', function(e) {
        var chip = e.target.closest && e.target.closest('.ff-subcat-chip');
        if (!chip) return;
        var wrap = chip.closest('.pos-subcat-filter');
        if (!wrap) return;
        var rootSel = wrap.getAttribute('data-root');
        if (!rootSel) return;
        e.preventDefault();
        var subkey = chip.getAttribute('data-subkey');
        if (subkey === null || subkey === undefined) subkey = '_all_';
        window.ffApplyPosSubcatFilter(rootSel, subkey);
    });

    // --- Event: Hash / Initialisierung ---
    function init() {
        if (ffGetTischHistorieRequirePayment()) {
            window._requirePaymentActive = true;
            var bootT = ffRequirePaymentTischNummer();
            if (bootT > 0 && typeof ffSyncTischRequirePaymentFromServer === 'function') {
                ffSyncTischRequirePaymentFromServer(bootT);
            }
        }
        /** Wenn PHP die Seite schon mit class="active" ausliefert, wurde kein loadContent ausgeführt → leeres Weiß. */
        function ffShellPageNeedsBootstrapLoad(containerId) {
            var el = document.getElementById(containerId);
            if (!el) return true;
            return String(el.innerHTML || '').replace(/\s/g, '').length < 8;
        }
        function applyHash() {
            var rawHash = window.location.hash.slice(1) || '';
            var qpBoot = ffGetTischFromUrl();
            if (rawHash === '' && qpBoot > 0) {
                rawHash = 'listTischBestellungen';
                ffSetTischInUrl(qpBoot, 'listTischBestellungen');
            }
            if (rawHash === '') rawHash = 'indexPage';
            var druckzielHistPtFromHash = 0;
            if (/^DruckzielHistory_\d+$/i.test(rawHash)) {
                druckzielHistPtFromHash = parseInt(rawHash.replace(/^DruckzielHistory_/i, ''), 10) || 0;
                rawHash = 'DruckzielHistory';
            }
            if (window.FF_USER_COMPACT_MENU && window.FF_COMPACT_HOME_HASH && window.FF_COMPACT_HOME_HASH !== 'indexPage') {
                if (rawHash === 'indexPage' || rawHash === '') {
                    rawHash = window.FF_COMPACT_HOME_HASH;
                    try { history.replaceState(null, '', '#' + rawHash); } catch (eCompactHome) {}
                }
            }
            var dCanon = normalizeDruckzielHash(rawHash);
            var isDruckzielHash = dCanon !== null;
            var hash = rawHash;
            if (isDruckzielHash) {
                hash = dCanon;
                if (dCanon !== rawHash) {
                    try { history.replaceState(null, '', '#' + dCanon); } catch (e0) {}
                }
            } else if (rawHash !== 'indexPage') {
                var resolved = resolveDataPageIdFromHash(rawHash);
                if (resolved !== rawHash && document.getElementById(resolved) && document.getElementById(resolved).hasAttribute('data-page')) {
                    hash = resolved;
                    try { history.replaceState(null, '', '#' + resolved); } catch (e1) {}
                }
            }

            if (ffIsRequirePaymentLocked() && hash !== 'listTischBestellungen') {
                ffRedirectToRequiredPayment();
                return;
            }
            if (ffIsRequirePaymentLocked() && hash === 'listTischBestellungen') {
                window._requirePaymentActive = true;
            }
            var page = document.getElementById(hash);
            var activeNow = (page && page.classList && page.classList.contains('active')) ? hash : null;
            if (page && page.hasAttribute('data-page')) {
                showPage(hash);
            } else if (isDruckzielHash) {
                showPage('DruckzielAnsicht', hash);
            } else {
                showPage('indexPage');
            }
            if (hash === 'adminPage') { if (activeNow !== 'adminPage') AdminAnsicht(); }
            else if (hash === 'financePage') {
                if (activeNow !== 'financePage' || ffShellPageNeedsBootstrapLoad('financePage')) FinanzAnsicht();
            } else if (hash === 'Kuechenansicht') {
                DruckzielAnsicht(11, 'Küche');
            } else if (hash === 'Schankansicht') {
                DruckzielAnsicht(12, 'Schank');
            } else if (hash === 'listTische') {
                if (window.FF_DV_ONLY_UI === 1) {
                    if (activeNow !== 'Direktverkauf' || ffShellPageNeedsBootstrapLoad('Direktverkauf')) Direktverkauf();
                } else if (activeNow !== 'listTische' || ffShellPageNeedsBootstrapLoad('listTische')) TischAnsicht();
            } else if (hash === 'Direktverkauf') {
                if (activeNow !== 'Direktverkauf' || ffShellPageNeedsBootstrapLoad('Direktverkauf')) Direktverkauf();
            } else if (hash === 'myOrdersPage') {
                if (activeNow !== 'myOrdersPage' || ffShellPageNeedsBootstrapLoad('myOrdersPage')) myOrdersAnsicht();
            } else if (hash === 'MitarbeiterVerpflegungPage') {
                if (activeNow !== 'MitarbeiterVerpflegungPage' || ffShellPageNeedsBootstrapLoad('MitarbeiterVerpflegungPage')) MitarbeiterVerpflegungAnsicht();
            } else if (hash === 'listTischBestellungen') {
                var qpTisch = ffGetTischFromUrl();
                if (qpTisch <= 0) qpTisch = ffGetLastTischFromStorage();
                if (qpTisch > 0) {
                    var restoreView = ffGetTischViewFromUrl() || 'bestellen';
                    var shellEmptyTb = ffShellPageNeedsBootstrapLoad('listTischBestellungen');
                    var tnumMismatch = (parseInt(String(window.Tischnummer), 10) || 0) !== qpTisch;
                    var needsRestore = shellEmptyTb || activeNow !== 'listTischBestellungen' || tnumMismatch;
                    if (needsRestore) {
                        ffRestoreTischPage(qpTisch, restoreView);
                    }
                } else if (typeof window.TischAnsicht === 'function') {
                    showPage('listTische');
                    window.TischAnsicht();
                }
            }
            if (isDruckzielHash) {
                var tid = window.currentDruckzielId;
                if (!tid && hash.indexOf('DruckzielAnsicht_') === 0) {
                    tid = parseInt(hash.replace(/^DruckzielAnsicht_/, ''), 10) || 11;
                }
                if (!tid) tid = (function(){ try { return parseInt(sessionStorage.getItem('lastDruckzielId'), 10) || 11; } catch(e){ return 11; }})();
                if (getActivePageId() === 'DruckzielAnsicht' && window.currentDruckzielId === tid && !ffShellPageNeedsBootstrapLoad('DruckzielContent')) {
                    syncBodySubviewClass();
                    return;
                }
                DruckzielAnsicht(tid, '');
            }
            if (hash === 'DirektHistory' || hash.indexOf('DirektHistory') === 0) {
                if (activeNow !== 'DirektHistory' || ffShellPageNeedsBootstrapLoad('DirektHistoryContent')) {
                    var dvHistQs = '';
                    try {
                        var uDh = new URL(window.location.href);
                        var dhBon = uDh.searchParams.get('bon_id') || uDh.searchParams.get('dv_bon');
                        if (dhBon && String(dhBon).trim() !== '') {
                            dvHistQs = '?bon_id=' + encodeURIComponent(String(dhBon).trim());
                        }
                    } catch (eDh) { /* ignore */ }
                    DirektHistory(dvHistQs);
                }
            }
            if (hash === 'DruckzielHistory') {
                var ptHist = druckzielHistPtFromHash || window.currentDruckzielId;
                if (!ptHist) {
                    try { ptHist = parseInt(sessionStorage.getItem('lastDruckzielId'), 10) || 0; } catch (ePt) { ptHist = 0; }
                }
                if (ptHist > 0) {
                    if (activeNow !== 'DruckzielHistory' || ffShellPageNeedsBootstrapLoad('DruckzielHistoryContent')) {
                        DruckzielHistory(ptHist);
                    }
                }
            }
            if (hash === 'KuecheHistory') {
                if (activeNow !== 'KuecheHistory' || ffShellPageNeedsBootstrapLoad('KuecheHistoryContent')) {
                    KuecheHistory();
                }
            }
            if (hash === 'SchankHistory') {
                if (activeNow !== 'SchankHistory' || ffShellPageNeedsBootstrapLoad('SchankHistoryContent')) {
                    SchankHistory();
                }
            }
            if (!document.querySelector('[data-page].active')) {
                showPage('indexPage');
            }
            syncBodySubviewClass();
        }
        applyHash();
        window.addEventListener('hashchange', applyHash);

        document.addEventListener('click', function (ev) {
            if (!ffIsRequirePaymentLocked()) {
                return;
            }
            var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
            if (!a) {
                return;
            }
            var href = String(a.getAttribute('href') || '').trim();
            if (!href || href === '#') {
                return;
            }
            if (href.indexOf('javascript:') === 0) {
                return;
            }
            if (a.getAttribute('onclick')) {
                return;
            }
            if (href.charAt(0) === '#') {
                ev.preventDefault();
                ev.stopPropagation();
                ffRedirectToRequiredPayment();
                return;
            }
            ev.preventDefault();
            ev.stopPropagation();
            ffRedirectToRequiredPayment();
        }, true);

        /* Kompakt-Menü (☰): nach Link-Klick auf schmalen Viewports einklappen */
        var ffCompactMainNav = document.getElementById('ffCompactMainNav');
        if (ffCompactMainNav && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
            ffCompactMainNav.addEventListener('click', function(ev) {
                var a = ev.target.closest('a');
                if (!a) return;
                var href = a.getAttribute('href') || '';
                if (href === '' || href === '#') return;
                try {
                    if (window.matchMedia('(max-width: 1199.98px)').matches) {
                        var inst = bootstrap.Collapse.getInstance(ffCompactMainNav);
                        if (inst) inst.hide();
                    }
                } catch (eNav) {}
            });
        }

        /* ↩ „Wieder offen“: Delegation – funktioniert zuverlässig bei per AJAX nachgeladenem HTML (ohne Inline-onclick). */
        document.addEventListener('click', function(ev) {
            var wbtn = ev.target.closest('.kueche-btn-wieder-offen');
            if (!wbtn) return;
            ev.preventDefault();
            var ids = wbtn.getAttribute('data-rowids');
            if (typeof window.kuechePositionOffen === 'function') {
                window.kuechePositionOffen(ids);
            }
        });

        var thermoDruckBtn = document.getElementById('ffKuecheDruckThermo');
        if (thermoDruckBtn) {
            thermoDruckBtn.addEventListener('click', function() {
                var s = _ffKuecheDruckState;
                var fd = new FormData();
                s.liste.forEach(function(p) { fd.append('rowids[]', String(p)); });
                fd.append('print_target', String(s.printTarget));
                fetch('kueche_thermo_enqueue.php', { method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res && res.ok) {
                            var mel = document.getElementById('ffKuecheDruckModal');
                            if (mel && typeof bootstrap !== 'undefined') {
                                var mi = bootstrap.Modal.getInstance(mel);
                                if (mi) mi.hide();
                            }
                            alert(res.message || 'In Warteschlange für Thermodrucker.');
                            ffRefreshOperationsAfterThermo();
                        } else {
                            alert(res && res.error ? res.error : 'Thermo-Warteschlange: Fehler');
                        }
                    })
                    .catch(onError);
            });
        }
        var browserDruckBtn = document.getElementById('ffKuecheDruckBrowser');
        if (browserDruckBtn) {
            browserDruckBtn.addEventListener('click', function() {
                var s = _ffKuecheDruckState;
                var mel = document.getElementById('ffKuecheDruckModal');
                if (mel && typeof bootstrap !== 'undefined') {
                    var mi = bootstrap.Modal.getInstance(mel);
                    if (mi) mi.hide();
                }
                window.printSinglePositionen(s.liste, s.tischnummer);
            });
        }

        document.addEventListener('keypress', function(e) {
            if (e.which !== 13) return;
            var active = getActivePageId();
            if (active === 'DruckzielAnsicht' && bestellungListe) {
                if (confirm("Bestellung von Tisch \n \n" + bestellungTischnr + "\n\nvollstaendig?")) {
                    if (window.currentDruckzielId === 12) schankGesamtFertig(bestellungListe, bestellungTischnr);
                    else kuecheGesamtFertig(bestellungListe, bestellungTischnr);
                }
            } else if (active === 'Kuechenansicht' && bestellungListe) {
                if (confirm("Bestellung von Tisch \n \n" + bestellungTischnr + "\n\nvollstaendig?")) {
                    kuecheGesamtFertig(bestellungListe, bestellungTischnr);
                }
            } else if (active === 'Schankansicht' && bestellungListe) {
                if (confirm("Bestellung von Tisch \n \n" + bestellungTischnr + "\n\nvollstaendig?")) {
                    schankGesamtFertig(bestellungListe, bestellungTischnr);
                }
            }
        });

        document.addEventListener('click', function(e) {
            var teilBtn = e.target.closest('[data-ff-teillieferung]');
            if (teilBtn && typeof window.stationTeillieferungDruck === 'function') {
                e.preventDefault();
                e.stopPropagation();
                var rowidsRawT = teilBtn.getAttribute('data-rowids') || '[]';
                var rowidsT = [];
                try { rowidsT = JSON.parse(rowidsRawT); } catch (errT) { rowidsT = []; }
                if (!Array.isArray(rowidsT)) rowidsT = [];
                var tischT = parseInt(teilBtn.getAttribute('data-tisch'), 10) || 0;
                var ptT = parseInt(teilBtn.getAttribute('data-print-target'), 10) || 0;
                window.stationTeillieferungDruck(rowidsT, tischT, ptT);
                return;
            }
            var absBtn = e.target.closest('[data-ff-abschliessen]');
            if (absBtn && typeof window.bestellungAbschliessen === 'function') {
                e.preventDefault();
                e.stopPropagation();
                var rowidsRaw = absBtn.getAttribute('data-rowids') || '[]';
                var rowids = [];
                try { rowids = JSON.parse(rowidsRaw); } catch (err) { rowids = []; }
                if (!Array.isArray(rowids)) rowids = [];
                var tisch = parseInt(absBtn.getAttribute('data-tisch'), 10) || 0;
                var mitDruck = absBtn.getAttribute('data-ff-abschliessen') === '1';
                window.bestellungAbschliessen(rowids, tisch, mitDruck);
                return;
            }
            /* Zahlmaske: ein Handler für #BestellungZahlen (überlebt partial=1 / innerHTML ohne Script-Ausführung). */
            var zahlWrap = e.target.closest('#BestellungZahlen');
            if (zahlWrap) {
                var payCtrl = e.target.closest('.pay-plus, .pay-minus');
                if (payCtrl) {
                    e.preventDefault();
                    e.stopPropagation();
                    var ridPc = parseInt(payCtrl.getAttribute('data-rid'), 10);
                    var betragPc = parseFloat(payCtrl.getAttribute('data-betrag')) || 0;
                    if (payCtrl.classList.contains('pay-plus') && typeof window.paySelect === 'function') {
                        window.paySelect(ridPc, betragPc);
                    } else if (payCtrl.classList.contains('pay-minus') && typeof window.payUnselect === 'function') {
                        window.payUnselect(ridPc);
                    }
                    return;
                }
                var aggMinusBtn = e.target.closest('.ff-agg-minus');
                if (aggMinusBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    var gidM = aggMinusBtn.getAttribute('data-group') || '';
                    if (typeof window.aggMinus === 'function') window.aggMinus(gidM);
                    return;
                }
                var aggPlusBtn = e.target.closest('.ff-agg-plus, [id^="agg_plus_"]');
                if (aggPlusBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    var gid = aggPlusBtn.getAttribute('data-group') || (aggPlusBtn.id ? aggPlusBtn.id.replace('agg_plus_', '') : '');
                    var preisAp = parseFloat(aggPlusBtn.getAttribute('data-preis')) || 0;
                    if (typeof window.aggPlus === 'function') window.aggPlus(gid, preisAp);
                    return;
                }
                var payMrAct = e.target.closest('#payMrConfirm, #payMrCancel, #payMrClose');
                if (payMrAct) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (payMrAct.id === 'payMrConfirm') {
                        if (typeof window.submitPayMitRechnung === 'function') window.submitPayMitRechnung();
                    } else {
                        closePayMitRechnungModal();
                }
                return;
                }
                var payBtn = e.target.closest('#btnBezahlenGesamtEinzeln, #btnBezahlenGesamt, #btnEhrengast, #btnBezahlenMitRechnung');
                if (payBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (payBtn.id === 'btnBezahlenGesamtEinzeln') {
                        var arrE = typeof window.ffPayIdsForCheckout === 'function'
                            ? window.ffPayIdsForCheckout()
                            : (window.arrayZahlungGetrennt || []);
                        if (arrE.length && typeof window.BestellungBezahlt === 'function') window.BestellungBezahlt(arrE, 0);
                    } else if (payBtn.id === 'btnBezahlenGesamt') {
                        var arrG = typeof window.ffPayIdsForCheckout === 'function'
                            ? window.ffPayIdsForCheckout()
                            : (window.arrayZahlung || []);
                        if (arrG.length === 0) {
                            var pd0 = document.getElementById('PayData');
                            try {
                                if (pd0 && pd0.getAttribute('data-array')) arrG = JSON.parse(pd0.getAttribute('data-array'));
                            } catch (err1) {}
                        }
                        if (arrG.length && typeof window.BestellungBezahlt === 'function') window.BestellungBezahlt(arrG, 0);
                    } else if (payBtn.id === 'btnBezahlenMitRechnung') {
                        var arrMr = typeof window.ffPayIdsForCheckout === 'function'
                            ? window.ffPayIdsForCheckout()
                            : (window.arrayZahlungGetrennt || []);
                        if (typeof window.openPayMitRechnungModal === 'function') window.openPayMitRechnungModal(arrMr);
                    } else if (payBtn.id === 'btnEhrengast') {
                        var arrH = window.arrayZahlung || [];
                        if (arrH.length === 0) {
                            var pd1 = document.getElementById('PayData');
                            try {
                                if (pd1 && pd1.getAttribute('data-array')) arrH = JSON.parse(pd1.getAttribute('data-array'));
                            } catch (err2) {}
                        }
                        var tischEg = parseInt(window.Tischnummer, 10) || 0;
                        if (!tischEg) {
                            var pdEg2 = document.getElementById('PayData');
                            if (pdEg2) tischEg = parseInt(pdEg2.getAttribute('data-tischnummer'), 10) || 0;
                        }
                        window.EhrengastAbschliessen(arrH, tischEg);
                    }
                    return;
                }
            }
            if (e.target.closest('#Bestellungen') || e.target.closest('#BestellungZahlen')) return;
            var a = e.target.closest('a[href^="#"]');
            if (!a) return;
            var href = a.getAttribute('href');
            if (href === '#') return;
            var targetId = href.slice(1);
            if (targetId === 'indexPage') {
                e.preventDefault();
                ffNavigateHome();
                return;
            }
            if (targetId && targetId.indexOf('DruckzielAnsicht_') === 0) {
                e.preventDefault();
                var ptId = parseInt(targetId.replace('DruckzielAnsicht_', ''), 10) || 11;
                DruckzielAnsicht(ptId, '');
                return;
            }
            if (targetId && document.getElementById(targetId)) {
                var handlers = { adminPage: AdminAnsicht, myOrdersPage: myOrdersAnsicht };
                if (handlers[targetId]) {
                    e.preventDefault();
                    handlers[targetId]();
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
