/* ============================================================
   MagDyn — Service Worker
   Created: 20260515_060024_IST

   Strategy
   --------
   - Pre-cache only the icons + design-system base CSS (stable assets)
   - Network-first for HTML pages (so logins, data updates always
     reach the server first) with a cached fallback offline page
   - Network-first with cache fallback for /assets/* code (CSS/JS) —
     so app.js / app.css / shortcuts.js never get stuck on a stale
     version after a deploy. Images stay cache-first.
   - push event -> show notification (title/body/url come from
     the JSON payload sent by the server using Web Push)
   - notificationclick -> focus existing window or open the URL
   ============================================================ */

// Bump this on every release that ships JS/CSS changes. The previous
// cache version is wiped on activate, so users get the new shell on
// their next visit without a manual hard refresh.
var CACHE_VERSION = 'magdyn-v3-20260515-0835';

// Only the truly stable bits are precached. App JS/CSS (mutable) is
// served network-first to avoid the "old code keeps coming back" trap.
var SHELL_ASSETS = [
    './',
    'mobile/',
    'assets/img/logo.png',
    'assets/img/icon-192.png',
    'assets/img/icon-512.png',
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_VERSION).then(function (cache) {
            return cache.addAll(SHELL_ASSETS).catch(function () {
                // Some absolute paths might 404 in subdirectory installs; that's OK.
            });
        }).then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (k) {
                return k !== CACHE_VERSION;
            }).map(function (k) { return caches.delete(k); }));
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    var req = event.request;
    if (req.method !== 'GET') return;
    var url = new URL(req.url);

    // Same-origin only
    if (url.origin !== self.location.origin) return;

    var path = url.pathname;
    var isImage = /\/assets\/(img|screenshots)\//.test(path)
               || /\.(png|jpg|jpeg|gif|webp|svg|ico)$/.test(path);
    var isCode  = /\/assets\/(css|js)\//.test(path)
               || /\.(js|css)$/.test(path);

    // Images: cache-first (they change rarely; bandwidth-friendly)
    if (isImage) {
        event.respondWith(
            caches.match(req).then(function (hit) {
                return hit || fetch(req).then(function (res) {
                    var copy = res.clone();
                    caches.open(CACHE_VERSION).then(function (c) { c.put(req, copy); });
                    return res;
                }).catch(function () { return hit; });
            })
        );
        return;
    }

    // Code (JS/CSS): network-first so deploys take effect immediately,
    // with cache fallback when offline.
    if (isCode) {
        event.respondWith(
            fetch(req).then(function (res) {
                // Only cache successful responses
                if (res && res.status === 200) {
                    var copy = res.clone();
                    caches.open(CACHE_VERSION).then(function (c) { c.put(req, copy); });
                }
                return res;
            }).catch(function () {
                return caches.match(req);
            })
        );
        return;
    }

    // HTML / PHP pages: network-first with cached fallback
    event.respondWith(
        fetch(req).then(function (res) {
            return res;
        }).catch(function () {
            return caches.match(req).then(function (hit) {
                if (hit) return hit;
                return new Response(
                    '<h1>Offline</h1><p>You appear to be offline. Try again when you have a connection.</p>',
                    { headers: { 'Content-Type': 'text/html' }, status: 503 }
                );
            });
        })
    );
});

// ---- Push notifications ------------------------------------
self.addEventListener('push', function (event) {
    var data = {};
    try { data = event.data ? event.data.json() : {}; }
    catch (_) { data = { title: 'MagDyn', body: event.data ? event.data.text() : '' }; }

    var title = data.title || 'MagDyn';
    var options = {
        body: data.body || '',
        icon: data.icon  || 'assets/img/icon-192.png',
        badge: data.badge || 'assets/img/icon-192.png',
        data: { url: data.url || './' },
        tag:  data.tag || 'magdyn-default',
        renotify: !!data.renotify,
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var target = (event.notification.data && event.notification.data.url) || './';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
                var c = clientList[i];
                if ('focus' in c) { c.navigate(target); return c.focus(); }
            }
            if (self.clients.openWindow) return self.clients.openWindow(target);
        })
    );
});
