(function () {
    var list = document.getElementById('ssList');
    var chips = document.getElementById('ssChips');
    var dot = document.getElementById('ssDot');
    var ageEl = document.getElementById('ssAge');
    if (!list || !chips || !dot || !ageEl) return;

    var station = list.getAttribute('data-station') || '';
    var accessToken = list.getAttribute('data-access-token') || '';
    var csrfToken = list.getAttribute('data-csrf-token') || '';
    var url = 'substation.php?data=1&station=' + encodeURIComponent(station);
    if (accessToken) url += '&access=' + encodeURIComponent(accessToken);

    var lastOk = 0;
    var lastError = '';
    var prev = null;
    ageEl.textContent = 'JavaScript aktiv · verbinde…';

    var qrOpen = document.getElementById('ssQrOpen');
    if (qrOpen) {
        var qrPanel = document.getElementById('ssQrPanel');
        var qrCode = document.getElementById('ssQrCode');
        var qrStatus = document.getElementById('ssQrStatus');
        document.getElementById('ssQrClose').addEventListener('click', function () {
            qrPanel.hidden = true;
            qrCode.textContent = '';
        });
        qrOpen.addEventListener('click', function () {
            qrPanel.hidden = false;
            qrCode.textContent = '';
            qrStatus.textContent = 'QR-Code wird erstellt…';
            fetch('substation_qr.php?action=create', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ station: station })
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data || !data.ok) throw new Error('QR-Zugang konnte nicht erstellt werden.');
                    return data;
                });
            }).then(function (data) {
                if (typeof QRCode === 'undefined') throw new Error('Der QR-Code-Generator konnte nicht geladen werden.');
                new QRCode(qrCode, {
                    text: new URL(data.url, window.location.href).toString(),
                    width: 240,
                    height: 240,
                    correctLevel: QRCode.CorrectLevel.M
                });
                qrStatus.textContent = 'Der QR-Code läuft in 2 Minuten ab und kann nur einmal verwendet werden.';
            }).catch(function (error) {
                qrStatus.textContent = error.message || 'QR-Zugang konnte nicht erstellt werden.';
            });
        });
        qrPanel.addEventListener('click', function (event) {
            if (event.target === qrPanel) {
                qrPanel.hidden = true;
                qrCode.textContent = '';
            }
        });
    }

    function cls(qty) {
        return qty >= 10 ? 'high' : (qty >= 5 ? 'mid' : '');
    }

    function render(data) {
        chips.textContent = '';
        var lead = document.createElement('span');
        lead.className = 'ss-status align-self-center';
        lead.textContent = 'Nächste ' + data.limit + ' Bestellungen'
            + (data.open_orders_total > data.considered ? ' (' + data.open_orders_total + ' offen)' : '') + ':';
        chips.appendChild(lead);

        data.orders.forEach(function (order) {
            var chip = document.createElement('span');
            chip.className = 'ss-chip' + (order.age_min >= 15 ? ' crit' : (order.age_min >= 8 ? ' warn' : ''));
            chip.textContent = order.label + ' · ' + order.age_min + ' min';
            chips.appendChild(chip);
        });

        list.textContent = '';
        var now = {};
        if (!data.items.length) {
            var empty = document.createElement('div');
            empty.className = 'ss-empty';
            empty.textContent = '✓ Alles erledigt';
            list.appendChild(empty);
        }
        data.items.forEach(function (item) {
            now[item.name] = item.qty;
            var changed = prev !== null && prev[item.name] !== undefined && prev[item.name] !== item.qty;
            var tile = document.createElement('div');
            tile.className = 'ss-tile ' + cls(item.qty) + (changed ? ' changed' : '');
            var name = document.createElement('div');
            name.className = 'nm';
            name.textContent = item.name;
            var quantity = document.createElement('div');
            quantity.className = 'q';
            quantity.textContent = item.qty + '×';
            tile.appendChild(name);
            tile.appendChild(quantity);
            list.appendChild(tile);
            if (changed) {
                setTimeout(function () { tile.classList.remove('changed'); }, 1500);
            }
        });
        prev = now;
    }

    function tick() {
        var seconds = lastOk ? Math.round((Date.now() - lastOk) / 1000) : null;
        var ok = seconds !== null && seconds < 10;
        dot.className = 'ss-dot' + (ok ? '' : ' off');
        ageEl.textContent = seconds === null
            ? (lastError ? 'Verbindung fehlgeschlagen · ' + lastError : 'verbinde…')
            : (ok ? 'live · vor ' + seconds + ' s'
                : 'keine Verbindung · vor ' + seconds + ' s' + (lastError ? ' · ' + lastError : ''));
    }

    function load() {
        var request = new XMLHttpRequest();
        request.open('GET', url, true);
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.timeout = 10000;
        request.onload = function () {
            var data;
            if (request.status < 200 || request.status >= 300) {
                lastError = 'HTTP ' + request.status;
                tick();
                return;
            }
            try {
                data = JSON.parse(request.responseText);
            } catch (error) {
                lastError = 'ungültige Serverantwort';
                tick();
                return;
            }
            if (!data || !data.ok) {
                lastError = data && data.error ? data.error : 'ungültige Serverantwort';
                tick();
                return;
            }
            lastError = '';
            lastOk = Date.now();
            render(data);
            tick();
        };
        request.onerror = function () {
            lastError = 'Netzwerkfehler';
            tick();
        };
        request.ontimeout = function () {
            lastError = 'Zeitüberschreitung';
            tick();
        };
        try {
            request.send();
        } catch (error) {
            lastError = error && error.message ? error.message : 'Anfrage konnte nicht gestartet werden';
            tick();
        }
    }

    window.addEventListener('error', function (event) {
        lastError = 'JavaScript-Fehler' + (event.message ? ': ' + event.message : '');
        tick();
    });

    document.getElementById('ssFs').addEventListener('click', function () {
        if (document.fullscreenElement) {
            document.exitFullscreen();
            return;
        }
        document.documentElement.requestFullscreen().catch(function () {});
        if (navigator.wakeLock) navigator.wakeLock.request('screen').catch(function () {});
    });

    tick();
    load();
    setInterval(function () {
        if (!document.hidden) load();
    }, 3000);
    setInterval(tick, 1000);
})();
