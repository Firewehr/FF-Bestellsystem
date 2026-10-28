/**
 * Service Worker für das FeuerwehrBestellsystem Pilot Frontend.
 * Cacht die vollständige App-Shell (HTML, CSS, JS, Vue.js), damit
 * die App auch bei unterbrochenem Festzelt-WLAN sofort geöffnet werden kann.
 */

const CACHE_NAME = 'ff-pilot-v14';
const APP_SHELL = [
  './index.html',
  './app.js',
  './app.js?v=9',
  './style.css',
  './style.css?v=9',
  './station.html',
  './station.js',
  './station.js?v=5',
  './station.css',
  './station.css?v=2',
  './kueche.html',
  './schank.html',
  './manifest.webmanifest',
  './assets/vue.esm-browser.prod.js',
  '../pwa-icon-192.png',
  '../pwa-icon-512.png',
];

// Install: App Shell cachen
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(APP_SHELL);
    }).then(() => self.skipWaiting())
  );
});

// Activate: Veraltete Caches bereinigen
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch: App-Shell & Navigation verarbeiten
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // 1. Nur GET-Requests behandeln
  if (event.request.method !== 'GET') {
    return;
  }

  // 2. WICHTIG: API-Endpunkte, dynamische PHP-Dateien und Realtime SSE-Streams
  // NIEMALS im Service Worker abfangen! Sie müssen nativ übers Netzwerk laufen,
  // damit Live-Bestellungen aktuell bleiben und Verbindungsabbrüche im Frontend
  // via try/catch & Toast sauber gemeldet werden (kein SW-TypeError).
  if (
    url.pathname.includes('/api/') ||
    url.pathname.endsWith('.php') ||
    url.pathname.includes('station_events') ||
    url.pathname.includes('print_events')
  ) {
    return;
  }

  // 3. HTML-Navigation (z. B. pilot/station.html?target=11): Network First mit sicherem Cache-Fallback
  if (event.request.mode === 'navigate' || url.pathname.endsWith('.html') || url.pathname.endsWith('/pilot/')) {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          if (response && response.status === 200) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
          }
          return response;
        })
        .catch(async () => {
          // ignoreSearch: true erlaubt Match von ./station.html bei Anfragen wie ./station.html?target=11
          const matched = await caches.match(event.request, { ignoreSearch: true });
          if (matched) {
            return matched;
          }
          if (url.pathname.includes('station.html') || url.pathname.includes('kueche.html') || url.pathname.includes('schank.html')) {
            const stationFallback = await caches.match('./station.html');
            if (stationFallback) return stationFallback;
          }
          const indexFallback = await caches.match('./index.html');
          if (indexFallback) return indexFallback;

          return new Response('Offline – keine Verbindung zum Server.', {
            status: 503,
            statusText: 'Service Unavailable',
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
          });
        })
    );
    return;
  }

  // 4. Statische Assets (CSS, JS, Icons, Fonts, Vue): Cache First mit Background Update
  event.respondWith(
    caches.match(event.request).then((cachedResponse) => {
      const fetchPromise = fetch(event.request)
        .then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200) {
            const clone = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
          }
          return networkResponse;
        })
        .catch(() => null);

      if (cachedResponse) {
        return cachedResponse;
      }

      return fetchPromise.then((netResp) => {
        if (netResp) return netResp;
        return new Response('Asset offline nicht verfügbar', {
          status: 503,
          headers: { 'Content-Type': 'text/plain; charset=utf-8' }
        });
      });
    })
  );
});
