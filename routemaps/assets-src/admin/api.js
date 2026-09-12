import { sprintf, __ } from '../shared/i18n.js';

export class RouteMapsApiError extends Error {
  constructor(message, status = 0, payload = null) {
    super(message);
    this.name = 'RouteMapsApiError';
    this.status = status;
    this.payload = payload;
  }
}

const joinUrl = (base, path) => `${String(base).replace(/\/$/, '')}/${String(path).replace(/^\//, '')}`;

export const createApi = (config) => {
  const request = async (path, options = {}) => {
    const method = options.method || 'GET';
    const headers = {
      Accept: 'application/json',
      'X-WP-Nonce': config.nonce,
      ...(options.headers || {}),
    };
    const init = { method, credentials: 'same-origin', headers };

    if (options.formData instanceof FormData) {
      init.body = options.formData;
    } else if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }

    const response = await fetch(joinUrl(config.restBase, path), init);
    const contentType = response.headers.get('content-type') || '';
    const payload = contentType.includes('application/json') ? await response.json() : await response.text();

    if (!response.ok) {
      const message = payload && typeof payload === 'object' && payload.message
        ? payload.message
        : sprintf(__('Erro da API RouteMaps (%s)'), response.status);
      throw new RouteMapsApiError(message, response.status, payload);
    }

    return payload;
  };

  return {
    listRoutes: () => request('/admin/routes?per_page=100&offset=0'),
    getRoute: (id) => request(`/admin/routes/${id}`),
    createRoute: (title) => request('/admin/routes', { method: 'POST', body: { title } }),
    saveDraft: (id, draft) => request(`/admin/routes/${id}/draft`, { method: 'PUT', body: draft }),
    publishRoute: (id, critical = false, summary = null) => request(`/admin/routes/${id}/publish`, {
      method: 'POST',
      body: { critical, summary },
    }),
    duplicateRoute: (id, title = null) => request(`/admin/routes/${id}/duplicate`, { method: 'POST', body: { title } }),
    searchPois: (query = '') => request(`/admin/pois?page=1&per_page=100&query=${encodeURIComponent(query)}`),
    listCategories: () => request('/admin/categories?page=1&per_page=100&active=1'),
    inspectImport: (file) => {
      const formData = new FormData();
      formData.append('file', file);
      return request('/admin/import/inspect', { method: 'POST', formData });
    },
    commitImport: (importId, categoryMap = {}, options = {}) => request('/admin/import/commit', {
      method: 'POST',
      body: {
        import_id: importId,
        mapping: { category_map: categoryMap, options },
      },
    }),
    cancelImport: (importId) => request(`/admin/import/${importId}`, { method: 'DELETE' }),
  };
};
