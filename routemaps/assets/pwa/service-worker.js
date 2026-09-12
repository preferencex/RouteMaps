const CACHE_NAME = 'routemaps-static-v1';
const PRIVATE_PATH_PREFIXES = [
  '/wp-json/routemaps/v1/',
  '/routemaps/access/',
  '/routemaps/login',
  '/routemaps/invite/',
  '/routemaps/app/',
  '/routemaps/manifest/',
];

const isCacheableRequest = (request, origin) => {
  if (!request || String(request.method || 'GET').toUpperCase() !== 'GET') return false;
  let url;
  try { url = new URL(request.url, origin); } catch { return false; }
  if (url.origin !== origin) return false;
  if (PRIVATE_PATH_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) return false;
  const destination = String(request.destination || '');
  if (['script', 'style'].includes(destination) && url.pathname.includes('/routemaps/assets/viewer/')) {
    const file = url.pathname.split('/').pop() || '';
    if (/^routemaps-viewer-[a-z0-9_-]+\.(?:js|css)$/i.test(file)) return true;
    if (/^routemaps-viewer\.(?:js|css)$/i.test(file) && url.searchParams.has('ver')) return true;
  }
  return destination === 'image' && url.searchParams.get('routemaps_pwa_icon') === '1';
};

const isNoStoreResponse = (response) => {
  const value = String(response?.headers?.get?.('cache-control') || '').toLowerCase();
  return value.split(',').some((directive) => {
    const normalized = directive.trim();
    return normalized === 'no-store' || normalized === 'private' || normalized.startsWith('private=');
  });
};

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.filter((name) => name.startsWith('routemaps-static-') && name !== CACHE_NAME).map((name) => caches.delete(name)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (!isCacheableRequest(request, self.location.origin)) return;

  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);
    if (cached) return cached;
    const response = await fetch(request);
    if (response.ok && !isNoStoreResponse(response)) {
      await cache.put(request, response.clone());
    }
    return response;
  })());
});
