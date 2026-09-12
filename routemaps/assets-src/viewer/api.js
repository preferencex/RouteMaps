import { sprintf, __ } from '../shared/i18n.js';

export class ViewerApiError extends Error {
  constructor(message, status = 0, payload = null) {
    super(message);
    this.name = 'ViewerApiError';
    this.status = status;
    this.payload = payload;
  }
}

const request = async (url, nonce, options = {}) => {
  const headers = { Accept: 'application/json', ...(options.headers || {}) };
  if (nonce) headers['X-WP-Nonce'] = nonce;
  const init = { method: options.method || 'GET', credentials: 'same-origin', headers };
  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(options.body);
  }
  const response = await fetch(url, init);
  const type = response.headers.get('content-type') || '';
  const payload = type.includes('application/json') ? await response.json() : await response.text();
  if (!response.ok) {
    const message = payload && typeof payload === 'object' && payload.message
      ? payload.message
      : sprintf(__('Erro da API RouteMaps (%s)'), response.status);
    throw new ViewerApiError(message, response.status, payload);
  }
  return payload;
};

export const createViewerApi = (bootstrap) => ({
  resolveAccess: () => request(bootstrap.endpoints.access_resolve, bootstrap.rest_nonce, {
    method: 'POST',
    body: {
      route_uuid: bootstrap.route_uuid,
      license_uuid: bootstrap.license_uuid || '',
    },
  }),
  getRoute: (licenseUuid, sessionUuid) => {
    const url = new URL(bootstrap.endpoints.viewer_route, globalThis.location?.href || 'https://localhost/');
    url.searchParams.set('license_uuid', licenseUuid);
    url.searchParams.set('session_uuid', sessionUuid);
    return request(url.toString(), bootstrap.rest_nonce);
  },
  heartbeat: (sessionUuid) => request(bootstrap.endpoints.heartbeat, bootstrap.rest_nonce, {
    method: 'POST',
    body: { session_uuid: sessionUuid },
  }),
});
