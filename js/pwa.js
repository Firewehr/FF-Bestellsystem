(function () {
    'use strict';

    if ('serviceWorker' in navigator) {
        var hasReloadedForSw = false;
        var hadController = !!navigator.serviceWorker.controller;

        window.addEventListener('load', function () {
            var swUrl = window.FF_PWA_SW_URL || './sw.js';

            navigator.serviceWorker.addEventListener('controllerchange', function () {
                if (hasReloadedForSw || !hadController || window.FF_PWA_NO_RELOAD) {
                    return;
                }
                hasReloadedForSw = true;
                window.location.reload();
            });

            navigator.serviceWorker.register(swUrl, {
                scope: './',
                updateViaCache: 'none'
            }).then(function (registration) {
                registration.update().catch(function () {});
            }).catch(function () {});
        });
    }
}());

(function () {
    'use strict';

    var POLL_INTERVAL_MS = 30000;
    var STORAGE_KEY = 'ff_admin_msg_last_id';
    var timer = null;
    var running = false;

    function loadLastId() {
        try {
            var value = localStorage.getItem(STORAGE_KEY);
            return value !== null && /^\d+$/.test(value) ? value : null;
        } catch (e) {
            return null;
        }
    }

    function saveLastId(id) {
        try { localStorage.setItem(STORAGE_KEY, String(id)); } catch (e) {}
    }

    function showInPage(message) {
        var box = document.getElementById('ffAdminMsgToasts');
        if (!box) {
            box = document.createElement('div');
            box.id = 'ffAdminMsgToasts';
            box.style.cssText = 'position:fixed;top:0.5rem;right:0.5rem;z-index:2000;max-width:22rem;';
            document.body.appendChild(box);
        }
        var toast = document.createElement('div');
        toast.className = 'alert alert-warning shadow-sm mb-2';
        toast.setAttribute('role', 'alert');
        toast.style.cursor = 'pointer';
        var title = document.createElement('strong');
        title.textContent = message.title;
        toast.appendChild(title);
        toast.appendChild(document.createElement('br'));
        toast.appendChild(document.createTextNode(message.body));
        toast.addEventListener('click', function () { toast.remove(); });
        box.appendChild(toast);
        setTimeout(function () { toast.remove(); }, 15000);
    }

    function playSound() {
        try {
            var audio = new Audio('./doorbell-1.ogg');
            var played = audio.play();
            if (played && played.catch) { played.catch(function () {}); }
        } catch (e) {}
        try {
            if (navigator.vibrate) { navigator.vibrate([300, 150, 300, 150, 600]); }
        } catch (e) {}
    }

    function showMessage(message) {
        playSound();
        var options = {
            body: message.body,
            icon: './pwa-icon-192.png',
            badge: './pwa-icon-192.png',
            tag: message.tag || 'ff-admin-notification',
            renotify: true,
            requireInteraction: true,
            silent: false,
            vibrate: [300, 150, 300, 150, 600],
            data: { url: './admin_message.php?id=' + encodeURIComponent(message.id) }
        };
        if ('Notification' in window && Notification.permission === 'granted') {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.ready.then(function (registration) {
                    return registration.showNotification(message.title, options);
                }).catch(function () { showInPage(message); });
                return;
            }
            try {
                new Notification(message.title, options);
                return;
            } catch (e) {}
        }
        showInPage(message);
    }

    function stop() {
        running = false;
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function poll() {
        if (!running) {
            return;
        }
        var lastId = loadLastId();
        var url = 'admin_messages_poll.php' + (lastId !== null ? '?after_id=' + encodeURIComponent(lastId) : '');
        fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (response.status === 401 || response.status === 403) {
                stop();
                return null;
            }
            return response.ok ? response.json() : null;
        }).then(function (data) {
            if (!data || data.ok !== true) {
                return;
            }
            (data.messages || []).forEach(showMessage);
            if (lastId === null || Number(data.last_id) > Number(lastId)) {
                saveLastId(data.last_id);
            }
        }).catch(function () {});
    }

    function start() {
        if (running || !window.fetch) {
            return;
        }
        running = true;
        poll();
        timer = setInterval(poll, POLL_INTERVAL_MS);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                poll();
            }
        });
    }

    window.ffAdminMessagesRequestPermission = function () {
        if (!('Notification' in window)) {
            return Promise.resolve('unsupported');
        }
        return Promise.resolve(Notification.requestPermission());
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());