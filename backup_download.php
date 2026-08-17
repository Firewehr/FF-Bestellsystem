<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/ff_favicon_helpers.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offline-Sicherung &amp; Notfall</title>
    <?php echo ff_favicon_link_tags(null); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f1f5f9; padding: 1rem 1rem 2rem; }
        .card { border: none; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
        .status-pill { font-size: .85rem; padding: .35rem .65rem; border-radius: 999px; display: inline-block; }
        .status-ok { background: #d1fae5; color: #065f46; }
        .status-warn { background: #fef3c7; color: #92400e; }
        .status-bad { background: #fee2e2; color: #991b1b; }
        #snapshotMirror { width: 100%; min-height: 420px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; }
        .mono { font-family: ui-monospace, monospace; font-size: .8rem; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 720px;">
        <h1 class="h3 mb-2">Offline-Sicherung</h1>
        <p class="text-muted small mb-3">
            <strong>Wichtig:</strong> Diese Seite während des Festes <strong>offen lassen</strong> (eigenes Fenster oder zweiter Monitor).
            Dann hast du bei Internet- oder Serverausfall automatisch den letzten funktionierenden Stand im Browser – ohne auf einen Download-Knopf zu warten.
        </p>
        <div class="alert alert-warning py-2 small mb-3">
            <strong>F5 ohne Internet?</strong> Der Browser kann diese PHP-Seite dann nicht neu laden.
            Nutze stattdessen <a href="offline_notfall.html"><strong>Offline-Notfall (letzter Stand)</strong></a>
            – als Lesezeichen speichern. Zeigt den gleichen Cache aus dem Browser-Speicher, auch nach Neuladen ohne Netz.
        </div>

        <div class="card mb-3 border-success">
            <div class="card-body">
                <h2 class="h6 text-success mb-2">1. Automatik (alle 30&nbsp;Sekunden)</h2>
                <p class="small text-muted mb-2">
                    Holt die komplette Sicherung (alle Druckziele + offene Zahlungen) vom Server und legt sie lokal im Browser ab.
                    Bei Ausfall wird der <strong>letzte erfolgreiche Stand</strong> angezeigt.
                    <strong>Lesezeichen für Notfall:</strong>
                    <a href="offline_notfall.html">offline_notfall.html</a> – funktioniert auch nach <strong>F5 ohne Internet</strong> (siehe gelber Hinweis oben und Betriebshandbuch Kapitel&nbsp;7).
                </p>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="autoToggle" checked>
                    <label class="form-check-label" for="autoToggle">Automatik aktiv</label>
                </div>
                <div class="mb-2">
                    <span id="statusPill" class="status-pill status-warn">Starte …</span>
                    <span id="statusDetail" class="small text-muted ms-2"></span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshNow">Jetzt aktualisieren</button>
                <button type="button" class="btn btn-sm btn-outline-primary ms-1" id="btnToggleMirror">Letzten Stand ein-/ausblenden</button>
            </div>
        </div>

        <div class="card mb-3 border-info">
            <div class="card-body">
                <h2 class="h6 text-info mb-2">Python / Skript (ohne Browser-Tab)</h2>
                <p class="small text-muted mb-2">
                    Im Admin unter <strong>Rechnungsdaten</strong> den <strong>Offline-Backup-Token</strong> setzen (wie Drucker-Token).
                    Dann kann <code>tools/fest_offline_backup.py</code> dieselbe API im Hintergrund ansprechen (Taskplaner). Siehe
                    <code>documentation/OFFLINE_SICHERUNG.md</code>.
                </p>
            </div>
        </div>

        <div class="card mb-3 border-primary" id="folderCard">
            <div class="card-body">
                <h2 class="h6 text-primary mb-2">2. Zuverlässig auf der Festplatte (Chrome / Edge)</h2>
                <p class="small text-muted mb-2">
                    Der Browser legt heruntergeladene HTML-Dateien oft nur temporär ab. Besser: du wählst <strong>einmal</strong> einen Ordner (z.&nbsp;B. Desktop oder USB-Stick).
                    Dann wird dort bei jedem erfolgreichen Abruf die Datei <code class="mono">Fest_Letzter_Stand.html</code> <strong>überschrieben</strong> – ohne Download-Dialog, bleibt auch nach Browser-Neustart.
                </p>
                <button type="button" class="btn btn-primary btn-sm" id="btnPickFolder">Backup-Ordner wählen …</button>
                <span id="folderStatus" class="small text-muted ms-2"></span>
                <p class="small text-danger mb-0 mt-2 d-none" id="folderNoSupport">In diesem Browser ist die Ordner-Funktion nicht verfügbar (z.&nbsp;B. Safari/Firefox). Nutze Automatik + eingeblendeten Stand, oder Chrome/Edge.</p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6 mb-2">3. Manuell</h2>
                <p class="small text-muted mb-2">Klassischer Download oder Vorschau (wie bisher).</p>
                <a href="fest_offline_snapshot.php" class="btn btn-success btn-sm">HTML herunterladen</a>
                <a href="fest_offline_snapshot.php?inline=1" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">Vorschau (neuer Tab)</a>
                <div class="mt-2 d-flex flex-wrap gap-2">
                    <a class="btn btn-sm btn-outline-secondary" href="backup.php">Speisen (alt)</a>
                    <a class="btn btn-sm btn-outline-secondary" href="backup_getraenke.php">Getränke (alt)</a>
                </div>
            </div>
        </div>

        <div id="mirrorWrap" class="mb-3 d-none">
            <h3 class="h6">Letzter gesicherter Stand</h3>
            <iframe id="snapshotMirror" title="Sicherungskopie" sandbox="allow-same-origin"></iframe>
        </div>

        <a href="index.php" class="btn btn-link ps-0">← Zurück zum Menü</a>
    </div>

        <script>
(function() {
    var DB_NAME = 'ff_fest_offline_v1';
    var STORE = 'snap';
    var KEY = 'latest';
    var API = 'fest_offline_snapshot_api.php';
    var INTERVAL_MS = 30000;

    var dirHandle = null;
    var autoTimer = null;
    var lastBlobUrl = null;

    function $(id) { return document.getElementById(id); }

    function setPill(kind, text) {
        var el = $('statusPill');
        el.className = 'status-pill ' + (kind === 'ok' ? 'status-ok' : (kind === 'bad' ? 'status-bad' : 'status-warn'));
        el.textContent = text;
    }

    function idbOpen() {
        return new Promise(function(resolve, reject) {
            var req = indexedDB.open(DB_NAME, 1);
            req.onerror = function() { reject(req.error); };
            req.onupgradeneeded = function() {
                var db = req.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE);
                }
            };
            req.onsuccess = function() { resolve(req.result); };
        });
    }

    function idbPut(record) {
        return idbOpen().then(function(db) {
            return new Promise(function(resolve, reject) {
                var tx = db.transaction(STORE, 'readwrite');
                tx.oncomplete = function() { resolve(); };
                tx.onerror = function() { reject(tx.error); };
                tx.objectStore(STORE).put(record, KEY);
            });
        });
    }

    function idbGet() {
        return idbOpen().then(function(db) {
            return new Promise(function(resolve, reject) {
                var tx = db.transaction(STORE, 'readonly');
                var req = tx.objectStore(STORE).get(KEY);
                req.onsuccess = function() { resolve(req.result || null); };
                req.onerror = function() { reject(req.error); };
            });
        });
    }

    function showInIframe(html) {
        var iframe = $('snapshotMirror');
        if (lastBlobUrl) {
            try { URL.revokeObjectURL(lastBlobUrl); } catch (e) {}
            lastBlobUrl = null;
        }
        var blob = new Blob([html], { type: 'text/html;charset=utf-8' });
        lastBlobUrl = URL.createObjectURL(blob);
        iframe.src = lastBlobUrl;
    }

    function writeToFolder(html) {
        if (!dirHandle || typeof dirHandle.getFileHandle !== 'function') return Promise.resolve();
        return dirHandle.getFileHandle('Fest_Letzter_Stand.html', { create: true }).then(function(fh) {
            return fh.createWritable();
        }).then(function(w) {
            return w.write(html).then(function() { return w.close(); });
        });
    }

    function loadCachedFirst() {
        return idbGet().then(function(rec) {
            if (!rec || !rec.html) return;
            $('mirrorWrap').classList.remove('d-none');
            showInIframe(rec.html);
            setPill('warn', 'Zwischenstand · Cache vom ' + (rec.generated_label || new Date(rec.savedAt).toLocaleString('de-AT')));
            $('statusDetail').textContent = 'Lokal geladen – Verbindung zum Server wird geprüft …';
        });
    }

    function tick() {
        return fetch(API, { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r) {
                if (!r.ok) throw new Error('http');
                return r.json();
            })
            .then(function(j) {
                if (!j || !j.ok || !j.html) throw new Error('badjson');
                var record = {
                    html: j.html,
                    generated_label: j.generated_label || '',
                    savedAt: Date.now()
                };
                return idbPut(record).then(function() {
                    return writeToFolder(j.html).then(function() {
                        return record;
                    }, function() {
                        return record;
                    });
                });
            })
            .then(function(record) {
                setPill('ok', 'Verbunden · Stand ' + (record.generated_label || ''));
                $('statusDetail').textContent = 'Lokal gespeichert: ' + new Date(record.savedAt).toLocaleString('de-AT');
                if (!$('mirrorWrap').classList.contains('d-none')) {
                    showInIframe(record.html);
                }
            })
            .catch(function() {
                return idbGet().then(function(rec) {
                    if (rec && rec.html) {
                        $('mirrorWrap').classList.remove('d-none');
                        showInIframe(rec.html);
                        setPill('warn', 'Offline · Cache vom ' + (rec.generated_label || new Date(rec.savedAt).toLocaleString('de-AT')));
                        $('statusDetail').innerHTML = 'Kein Server – letzter Stand aus dem Browser-Speicher. Bei F5 ohne Netz: <a href="offline_notfall.html">Offline-Notfall öffnen</a>.';
                    } else {
                        setPill('bad', 'Keine Verbindung / Fehler');
                        $('statusDetail').textContent = 'Noch kein lokaler Stand – einmal online „Jetzt aktualisieren“, dann offline_notfall.html als Lesezeichen.';
                    }
                });
            });
    }

    function startAuto() {
        stopAuto();
        tick();
        autoTimer = setInterval(tick, INTERVAL_MS);
    }

    function stopAuto() {
        if (autoTimer) {
            clearInterval(autoTimer);
            autoTimer = null;
        }
    }

    $('autoToggle').addEventListener('change', function() {
        if (this.checked) startAuto(); else stopAuto();
    });

    $('btnRefreshNow').addEventListener('click', function() { tick(); });

    $('btnToggleMirror').addEventListener('click', function() {
        var w = $('mirrorWrap');
        if (w.classList.contains('d-none')) {
            w.classList.remove('d-none');
            idbGet().then(function(rec) {
                if (rec && rec.html) showInIframe(rec.html);
            });
        } else {
            w.classList.add('d-none');
        }
    });

    $('btnPickFolder').addEventListener('click', function() {
        if (typeof window.showDirectoryPicker !== 'function') {
            alert('Ordner-Auswahl wird in diesem Browser nicht unterstützt. Bitte Chrome oder Edge verwenden.');
            return;
        }
        window.showDirectoryPicker({ mode: 'readwrite' }).then(function(handle) {
            dirHandle = handle;
            $('folderStatus').textContent = 'Ordner aktiv – Datei wird bei jedem erfolgreichen Abruf aktualisiert.';
            return tick();
        }).catch(function() {
            $('folderStatus').textContent = 'Abgebrochen.';
        });
    });

    if (typeof window.showDirectoryPicker !== 'function') {
        $('folderNoSupport').classList.remove('d-none');
    }

    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible' && $('autoToggle').checked) {
            tick();
        }
    });

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('js/ff-offline-backup-sw.js', { scope: './' }).catch(function() {});
    }

    loadCachedFirst().then(function() {
        if ($('autoToggle').checked) startAuto();
    });
})();
        </script>
    </body>
</html>
