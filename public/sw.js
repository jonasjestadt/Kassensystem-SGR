// Service Worker: macht die Seite als App installierbar und lädt Design, Skripte
// und Symbole aus dem Zwischenspeicher. Seiten und Bestellungen gehen immer ans Netz.
const CACHE = 'theke-static-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;

  // Statische Dateien (mit Versionsnummer in der URL): erst Cache, sonst Netz
  if (url.pathname.includes('/assets/')) {
    event.respondWith(
      caches.open(CACHE).then((cache) =>
        cache.match(req).then((hit) => hit || fetch(req).then((res) => {
          if (res.ok) cache.put(req, res.clone());
          return res;
        }))
      )
    );
    return;
  }

  // Alles andere (PHP-Seiten) immer frisch vom Server
  event.respondWith(fetch(req));
});
