// SSShare service worker: offline app shell + Web Share Target.
// Shared files are stored locally (Cache API) and handed to the uploader, so the user still
// confirms privacy settings before anything is uploaded.
const SHELL = 'sss-shell-v1';
const SHELL_URLS = ['/assets/css/app.css', '/assets/js/app.js', '/assets/js/theme.js', '/assets/js/uploader.js', '/assets/js/editor.js', '/assets/images/logo.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(SHELL).then((c) => c.addAll(SHELL_URLS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('sss-shell-') && k !== SHELL).map((k) => caches.delete(k)))).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (url.origin !== location.origin) return;

    if (event.request.method === 'POST' && url.pathname === '/share-target') {
        event.respondWith((async () => {
            const data = await event.request.formData();
            const cache = await caches.open('sss-handoff');
            let i = 0;
            for (const file of data.getAll('files')) {
                if (file && typeof file === 'object') {
                    await cache.put('/handoff/' + i++, new Response(file, { headers: { 'content-type': file.type, 'x-name': encodeURIComponent(file.name || 'shared') } }));
                }
            }
            const text = [data.get('title'), data.get('text'), data.get('url')].filter(Boolean).join('\n');
            if (i === 0 && text) {
                await cache.put('/handoff/0', new Response(new Blob([text], { type: 'text/plain' }), { headers: { 'content-type': 'text/plain', 'x-name': 'shared.txt' } }));
            }
            return Response.redirect('/?shared=1', 303);
        })());
        return;
    }

    // Static assets: cache-first. Never cache share pages, media or API responses.
    if (event.request.method === 'GET' && url.pathname.startsWith('/assets/')) {
        event.respondWith(caches.match(event.request, { ignoreSearch: true }).then((hit) => hit || fetch(event.request)));
        return;
    }
    // Offline fallback for navigations.
    if (event.request.mode === 'navigate') {
        event.respondWith(fetch(event.request).catch(() => new Response(
            '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Offline</title><body style="font-family:system-ui;padding:2rem"><h1>You are offline</h1><p>SSShare needs a connection to share. Your screenshots stay on your device until you are back online.</p>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        )));
    }
});
