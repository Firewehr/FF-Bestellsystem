const CACHE_NAME = 'ff-bestellsystem-static-v1';
const STATIC_ASSETS = [
    './style.css',
    './admin.css',
    './js/app.js',
    './offline_notfall.html',
    './pwa-icon-192.png',
    './pwa-icon-512.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) =>
            Promise.all(STATIC_ASSETS.map((asset) =>
                cache.add(asset).catch(() => undefined)
            ))
        ).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => Promise.all(
            cacheNames
                .filter((cacheName) => cacheName !== CACHE_NAME)
                .map((cacheName) => caches.delete(cacheName))
        )).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    const isBackupNavigation = request.mode === 'navigate'
        && (url.pathname.includes('backup_download.php') || url.pathname.includes('offline_notfall.html'));
    if (isBackupNavigation) {
        event.respondWith(
            fetch(request).then((response) => {
                if (response.ok) {
                    const responseForCache = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, responseForCache));
                }
                return response;
            }).catch(() => caches.match(request).then((cachedResponse) =>
                cachedResponse || caches.match('./offline_notfall.html')
            ))
        );
        return;
    }

    const isStaticAsset = /\.(?:css|js|png|jpg|jpeg|svg|woff2?)$/i.test(url.pathname);
    if (!isStaticAsset) {
        return;
    }

    event.respondWith(
        caches.match(request).then((cachedResponse) => {
            const networkResponse = fetch(request).then((response) => {
                if (response.ok) {
                    const responseForCache = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, responseForCache));
                }
                return response;
            });
            return cachedResponse || networkResponse;
        })
    );
});