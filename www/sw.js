/* Budget Tracker service worker.
 * Conservative for a multi-user, session-authenticated app:
 *   - Only static, non-personalised assets are cached (CSS/JS/fonts/icons under /assets/).
 *   - HTML pages, /api/*, and every OAuth/auth route ALWAYS hit the network
 *     (never cache one user's page and serve it to another; never cache auth).
 *   - Failed navigations fall back to a small offline page.
 * Bump CACHE when the precache list or strategy changes to retire old caches. */
const CACHE = 'bt-static-v1';

const PRECACHE = [
  '/offline.html',
  '/assets/style.css',
  '/assets/app.js',
  '/assets/icon-192.png',
  '/assets/icon-512.png',
  '/assets/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((c) => Promise.allSettled(PRECACHE.map((u) => c.add(u))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

/* Same-origin static assets under /assets/ are safe to serve cache-first. */
function isStatic(url) {
  return url.origin === location.origin && url.pathname.startsWith('/assets/');
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;              // never touch POST/mutations
  const url = new URL(req.url);

  // Static assets: cache-first, refresh in the background.
  if (isStatic(url)) {
    event.respondWith(
      caches.match(req).then((hit) => {
        const net = fetch(req).then((res) => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
          }
          return res;
        }).catch(() => hit);
        return hit || net;
      })
    );
    return;
  }

  // Navigations: network-only, offline page as fallback.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    return;
  }

  // Everything else (API calls, CDN scripts, etc.): straight to network.
});
