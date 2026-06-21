/* Service worker for the SM Restaurant Hub PWA.
 *
 * Goal: let the installed app launch with no network by caching the app
 * shell (index.html + the hashed JS/WASM/font assets Metro emits). The
 * app's *data* is already offline-first via SQLite + the sync queue, so the
 * worker deliberately never caches API traffic — it only handles the shell.
 *
 * Cached same-origin responses retain their COOP/COEP/CORP headers, so
 * cross-origin isolation (required by expo-sqlite's web worker) survives an
 * offline launch as long as the server set those headers when caching.
 *
 * Mount-agnostic: BASE is derived from this script's own location, so the same
 * file works whether served at /sw.js or /pos/sw.js.
 */
const CACHE = 'rhub-shell-v2';

// e.g. "/pos/sw.js" -> "/pos" ; "/sw.js" -> ""
const BASE = self.location.pathname.replace(/\/sw\.js$/, '');
const SHELL_URL = `${BASE}/`;

self.addEventListener('install', (event) => {
  // Precache the full app shell (index + hashed JS/WASM/fonts/icons) from the
  // build-time manifest so the very first offline launch has everything it
  // needs. Falls back to caching just the shell if the manifest is missing.
  event.waitUntil(
    (async () => {
      const cache = await caches.open(CACHE);
      try {
        const res = await fetch(`${BASE}/precache-manifest.json`, { cache: 'no-cache' });
        const { urls } = await res.json();
        await cache.addAll(urls);
      } catch (err) {
        await cache.add(SHELL_URL).catch(() => {});
      }
    })()
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
      )
      .then(() => self.clients.claim())
  );
});

function isShellAsset(url) {
  const p = url.pathname;
  return (
    p.startsWith(`${BASE}/_expo/`) ||
    p.startsWith(`${BASE}/assets/`) ||
    p === `${BASE}/manifest.json` ||
    p === `${BASE}/favicon.ico` ||
    p.endsWith('.png') ||
    p.endsWith('.wasm')
  );
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Never touch cross-origin or API calls — straight to network.
  // (The API lives at /api/* at the site root, outside BASE.)
  if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
    return;
  }

  // Only handle requests within our own mount path.
  if (!url.pathname.startsWith(`${BASE}/`)) return;

  // Navigations: network-first, fall back to the cached shell when offline.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE).then((cache) => cache.put(SHELL_URL, copy));
          return res;
        })
        .catch(() => caches.match(SHELL_URL))
    );
    return;
  }

  // Static shell assets: cache-first, populate on miss.
  if (isShellAsset(url)) {
    event.respondWith(
      caches.match(request).then(
        (cached) =>
          cached ||
          fetch(request).then((res) => {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(request, copy));
            return res;
          })
      )
    );
  }
});
