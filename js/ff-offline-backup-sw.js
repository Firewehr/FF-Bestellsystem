/**
 * Service Worker: Offline-Sicherung nach F5 ohne Netz.
 * Cached backup_download.php (nach erstem Besuch) und offline_notfall.html.
 */
'use strict';

var CACHE_NAME = 'ff-offline-backup-v1';
/** App-Root (ein Verzeichnis über js/) – funktioniert auch in Unterordnern auf dem Server */
var APP_ROOT = new URL('..', self.location);
var PRECACHE = [new URL('offline_notfall.html', APP_ROOT).href];

self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(PRECACHE);
        }).then(function() {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function(event) {
    event.waitUntil(self.clients.claim());
});

function isBackupNavigation(url) {
    var path = url.pathname || '';
    return path.indexOf('backup_download.php') !== -1
        || path.indexOf('offline_notfall.html') !== -1;
}

function fallbackNotfall() {
    return caches.match(new URL('offline_notfall.html', APP_ROOT).href);
}

self.addEventListener('fetch', function(event) {
    if (event.request.method !== 'GET') {
        return;
    }
    var url = new URL(event.request.url);
    if (event.request.mode !== 'navigate' || !isBackupNavigation(url)) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then(function(response) {
                if (response && response.ok) {
                    var clone = response.clone();
                    caches.open(CACHE_NAME).then(function(cache) {
                        cache.put(event.request, clone);
                    });
                }
                return response;
            })
            .catch(function() {
                return caches.match(event.request).then(function(cached) {
                    if (cached) {
                        return cached;
                    }
                    return fallbackNotfall();
                });
            })
    );
});
