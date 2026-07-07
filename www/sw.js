/* Budget Tracker service worker.
 * Conservative for a multi-user, session-authenticated app:
 *   - Only static, non-personalised assets are cached (CSS/JS/fonts/icons under /assets/).
 *   - HTML pages, /api/*, and every OAuth/auth route ALWAYS hit the network
 *     (never cache one user's page and serve it to another; never cache auth).
 *   - Failed navigations fall back to a small offline page.
 * Bump CACHE when the precache list or strategy changes to retire old caches. */
const CACHE = 'bt-static-v2';

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

/* Store a fresh response and drop any OTHER cached revisions of the same asset. With no
 * build step the URLs carry a ?v=<mtime> query, so every deploy would otherwise leave the
 * previous revision behind forever (unbounded growth). Keep only the latest per pathname. */
function putAndPrune(req, res) {
  return caches.open(CACHE).then((c) =>
    c.put(req, res).then(() =>
      c.keys().then((keys) => {
        const base = new URL(req.url).pathname;
        return Promise.all(
          keys.filter((k) => k.url !== req.url && new URL(k.url).pathname === base)
              .map((k) => c.delete(k))
        );
      })
    )
  );
}

/* A minimal offline page synthesised when /offline.html was never precached (so we never
 * respondWith(undefined), which would throw and surface as a broken navigation). */
function offlineFallback() {
  return caches.match('/offline.html').then((r) => r || new Response(
    '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<title>Offline</title><body style="font-family:system-ui,sans-serif;padding:2rem;max-width:32rem;margin:auto">' +
    '<h1>You’re offline</h1><p>This page needs a connection. Reconnect and try again.</p>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
  ));
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
            putAndPrune(req, copy);   // store + retire older ?v= revisions of this asset
          }
          return res;
        }).catch(() => hit);
        return hit || net;
      })
    );
    return;
  }

  // Navigations: network-only, offline page as fallback (guarded so it never resolves undefined).
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => offlineFallback()));
    return;
  }

  // Everything else (API calls, CDN scripts, etc.): straight to network.
});
