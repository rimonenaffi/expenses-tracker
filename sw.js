// Service worker: makes the tracker installable and keeps its look available offline.
// Pages and data always come from the network (they need the login session); only static
// files are cached. Bump VERSION when assets change so old caches are cleared.
const VERSION = 'v1';
const CACHE = 'expenses-' + VERSION;
const PRECACHE = [
    'offline.html',
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png'
];
const CDN_HOSTS = ['cdn.jsdelivr.net', 'cdnjs.cloudflare.com', 'fonts.googleapis.com', 'fonts.gstatic.com'];

self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(key => key.startsWith('expenses-') && key !== CACHE).map(key => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

// Serve from cache straight away, refresh the cached copy in the background
function staleWhileRevalidate(request) {
    return caches.open(CACHE).then(cache => cache.match(request).then(cached => {
        const fresh = fetch(request).then(response => {
            if (response.ok || response.type === 'opaque') cache.put(request, response.clone());
            return response;
        }).catch(() => cached);
        return cached || fresh;
    }));
}

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('offline.html')));
        return;
    }
    if (url.origin === self.location.origin && url.pathname.includes('/assets/')) {
        event.respondWith(staleWhileRevalidate(request));
        return;
    }
    if (CDN_HOSTS.includes(url.hostname)) {
        event.respondWith(staleWhileRevalidate(request));
    }
    // Everything else (process.php, export, reports) goes straight to the network
});
