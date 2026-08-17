/**
 * Systemnachricht: adaptives Poll + Vollbild-Overlay.
 * - Kein Intervall ohne ungelesene aktive Nachricht (nur Events: Fokus, loadContent, …).
 * - Schnelles Intervall (2 s) nur bei ungelesener Nachricht und sichtbarem Tab.
 * - Hintergrund-Tabs: kein Poll.
 */
(function () {
    'use strict';

    var LS_KEY = 'ff_system_broadcast_seen_id';
    var POLL_FAST_MS = 2000;
    var MIN_GAP_MS = 500;

    var overlay = document.getElementById('ffSystemBroadcastOverlay');
    var bodyEl = document.getElementById('ffSystemBroadcastBody');
    var okBtn = document.getElementById('ffSystemBroadcastOkBtn');
    var currentId = 0;
    var timer = null;
    var inFlight = false;
    var lastPollAt = 0;
    var pendingForce = false;
    /** Intervall nur bei ungelesener Server-Nachricht. */
    var fastPollActive = false;

    function getSeenId() {
        try {
            return localStorage.getItem(LS_KEY) || '';
        } catch (e) {
            return '';
        }
    }

    function serverHasUnread(d) {
        return !!(d && d.active && d.text && String(d.id) !== getSeenId());
    }

    function hideOverlay() {
        if (!overlay) return;
        overlay.hidden = true;
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ff-system-broadcast-open');
    }

    function showOverlay(text, id) {
        if (!overlay || !bodyEl) return;
        currentId = parseInt(id, 10) || 0;
        bodyEl.textContent = text;
        overlay.hidden = false;
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ff-system-broadcast-open');
        if (okBtn) {
            okBtn.focus();
        }
    }

    function schedulePolling() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
        if (document.hidden || !fastPollActive) {
            return;
        }
        timer = setInterval(function () {
            doPoll(false);
        }, POLL_FAST_MS);
    }

    function setFastPoll(active) {
        if (fastPollActive === active) {
            return;
        }
        fastPollActive = active;
        schedulePolling();
    }

    function onOk() {
        if (currentId > 0) {
            try {
                localStorage.setItem(LS_KEY, String(currentId));
            } catch (e) { /* ignore */ }
        }
        hideOverlay();
        setFastPoll(false);
    }

    function handlePayload(d) {
        setFastPoll(serverHasUnread(d));

        if (!d || !d.active || !d.text) {
            hideOverlay();
            return;
        }
        if (getSeenId() === String(d.id)) {
            hideOverlay();
            return;
        }
        showOverlay(d.text, d.id);
    }

    function doPoll(force) {
        if (!overlay) return;
        if (document.hidden) {
            return;
        }
        var now = Date.now();
        if (!force && (inFlight || now - lastPollAt < MIN_GAP_MS)) {
            if (force) pendingForce = true;
            return;
        }
        inFlight = true;
        lastPollAt = now;
        pendingForce = false;

        fetch('system_broadcast.php', {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (r) {
                if (r.status === 401) {
                    return null;
                }
                return r.ok ? r.json() : null;
            })
            .then(handlePayload)
            .catch(function () { /* still */ })
            .finally(function () {
                inFlight = false;
                if (pendingForce && !document.hidden) {
                    pendingForce = false;
                    doPoll(true);
                } else {
                    pendingForce = false;
                }
            });
    }

    function onTabVisible() {
        doPoll(true);
        schedulePolling();
    }

    function onTabHidden() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    /** Von app.js nach loadContent – nur wenn Tab sichtbar. */
    window.ffSystemBroadcastPoll = function (force) {
        if (document.hidden) {
            return;
        }
        doPoll(!!force);
    };

    window.ffSystemBroadcastIsOpen = function () {
        return !!(overlay && !overlay.hidden);
    };

    function start() {
        if (!overlay) return;
        if (okBtn) {
            okBtn.addEventListener('click', onOk);
        }
        if (!document.hidden) {
            doPoll(true);
        }
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                onTabHidden();
            } else {
                onTabVisible();
            }
        });
        window.addEventListener('focus', function () {
            if (!document.hidden) onTabVisible();
        });
        window.addEventListener('pageshow', function () {
            if (!document.hidden) onTabVisible();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
