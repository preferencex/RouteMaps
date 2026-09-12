const PRIVATE_PATH_PREFIXES = [
  '/wp-json/routemaps/v1/',
  '/routemaps/access/',
  '/routemaps/login',
  '/routemaps/invite/',
  '/routemaps/app/',
  '/routemaps/manifest/',
];

const isSameOrigin = (url, origin) => url.origin === origin;

const isVersionedViewerAsset = (url, destination) => {
  if (!['script', 'style'].includes(destination)) return false;
  if (!url.pathname.includes('/routemaps/assets/viewer/')) return false;
  const file = url.pathname.split('/').pop() || '';
  const fingerprinted = /^routemaps-viewer-[a-z0-9_-]+\.(?:js|css)$/i.test(file);
  const versionedFallback = /^routemaps-viewer\.(?:js|css)$/i.test(file) && url.searchParams.has('ver');
  return fingerprinted || versionedFallback;
};

const isMarkedPwaIcon = (url, destination) => destination === 'image'
  && url.searchParams.get('routemaps_pwa_icon') === '1';

export const isCacheableRequest = (request, origin = globalThis.location?.origin || '') => {
  if (!request || String(request.method || 'GET').toUpperCase() !== 'GET') return false;

  let url;
  try {
    url = new URL(request.url, origin || undefined);
  } catch {
    return false;
  }

  if (!origin || !isSameOrigin(url, origin)) return false;
  if (PRIVATE_PATH_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) return false;

  const destination = String(request.destination || '');
  return isVersionedViewerAsset(url, destination) || isMarkedPwaIcon(url, destination);
};

export const isNoStoreResponse = (response) => {
  const value = String(response?.headers?.get?.('cache-control') || '').toLowerCase();
  return value.split(',').some((directive) => {
    const normalized = directive.trim();
    return normalized === 'no-store' || normalized === 'private' || normalized.startsWith('private=');
  });
};
