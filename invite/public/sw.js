/* Service worker: caches static assets (stale-while-revalidate) and shows an offline page for navigations.
   Never caches HTML pages, API calls or anything personalised. */
const CACHE = 'invite-static-v1';
const OFFLINE_URL = '/assets/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll([OFFLINE_URL, '/assets/css/app.css', '/assets/img/icon.svg'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') { return; }
    const url = new URL(req.url);
    if (url.origin !== location.origin) { return; }

    if (url.pathname.startsWith('/assets/') || url.pathname === '/manifest.webmanifest') {
        event.respondWith(
            caches.open(CACHE).then(async (cache) => {
                const hit = await cache.match(req);
                const network = fetch(req).then((res) => { if (res.ok) { cache.put(req, res.clone()); } return res; }).catch(() => hit);
                return hit || network;
            })
        );
        return;
    }

    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL)));
    }
});
