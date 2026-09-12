import { describe, expect, it } from 'vitest';
import { isCacheableRequest, isNoStoreResponse } from '../service-worker-policy.js';

const request = (url, { method = 'GET', destination = '' } = {}) => ({
  url,
  method,
  destination,
});

const response = (cacheControl = '') => ({
  headers: { get: (name) => name.toLowerCase() === 'cache-control' ? cacheControl : null },
});

describe('RouteMaps service worker cache policy', () => {
  it('never caches private RouteMaps API or auth/access routes', () => {
    const blocked = [
      'https://example.test/wp-json/routemaps/v1/viewer/routes/abc',
      'https://example.test/wp-json/routemaps/v1/access/resolve',
      'https://example.test/routemaps/access/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      'https://example.test/routemaps/login/',
      'https://example.test/routemaps/invite/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      'https://example.test/routemaps/app/11111111-1111-4111-8111-111111111111?license=22222222-2222-4222-8222-222222222222',
    ];

    blocked.forEach((url) => expect(isCacheableRequest(request(url, { destination: 'document' }), 'https://example.test')).toBe(false));
  });

  it('caches only same-origin versioned viewer assets and image icons', () => {
    expect(isCacheableRequest(request('https://example.test/wp-content/plugins/routemaps/assets/viewer/routemaps-viewer-ab12cd.js', { destination: 'script' }), 'https://example.test')).toBe(true);
    expect(isCacheableRequest(request('https://example.test/wp-content/plugins/routemaps/assets/viewer/routemaps-viewer-ab12cd.css', { destination: 'style' }), 'https://example.test')).toBe(true);
    expect(isCacheableRequest(request('https://example.test/wp-content/uploads/site-icon-192.png?routemaps_pwa_icon=1', { destination: 'image' }), 'https://example.test')).toBe(true);
    expect(isCacheableRequest(request('https://example.test/wp-content/uploads/poi-photo.jpg', { destination: 'image' }), 'https://example.test')).toBe(false);
    expect(isCacheableRequest(request('https://cdn.example.net/icon.png', { destination: 'image' }), 'https://example.test')).toBe(false);
    expect(isCacheableRequest(request('https://example.test/wp-json/wp/v2/posts', { destination: '' }), 'https://example.test')).toBe(false);
  });

  it('never stores responses marked no-store or private', () => {
    expect(isNoStoreResponse(response('private, no-store, max-age=0'))).toBe(true);
    expect(isNoStoreResponse(response('private, max-age=0'))).toBe(true);
    expect(isNoStoreResponse(response('public, max-age=31536000'))).toBe(false);
  });

  it('never caches non-GET requests', () => {
    expect(isCacheableRequest(request('https://example.test/wp-content/plugins/routemaps/assets/viewer/x.js', { method: 'POST', destination: 'script' }), 'https://example.test')).toBe(false);
  });
});
