import { __ } from '../shared/i18n.js';

const categoryVisibilityFrom = (categories = []) => Object.fromEntries(
  (Array.isArray(categories) ? categories : [])
    .filter((category) => category && typeof category.uuid === 'string' && category.uuid)
    .map((category) => [category.uuid, true]),
);

export const createViewerState = (payload = {}) => ({
  route: payload.route || null,
  geometry: payload.geometry || null,
  stops: Array.isArray(payload.stops) ? [...payload.stops] : [],
  pois: Array.isArray(payload.pois) ? [...payload.pois] : [],
  categories: Array.isArray(payload.categories) ? [...payload.categories] : [],
  display: payload.display || {},
  viewport: payload.viewport || {},
  routeStyle: payload.route_style || {},
  support: payload.support || {},
  map: payload.map || null,
  capabilities: payload.capabilities || {},
  categoryVisibility: categoryVisibilityFrom(payload.categories),
  selectedPoiUuid: null,
  accessError: null,
});

export const toggleCategory = (state, categoryUuid) => {
  const key = String(categoryUuid || '');
  if (!key) return state;
  const current = Object.prototype.hasOwnProperty.call(state.categoryVisibility || {}, key)
    ? Boolean(state.categoryVisibility[key])
    : true;
  return {
    ...state,
    categoryVisibility: {
      ...(state.categoryVisibility || {}),
      [key]: !current,
    },
  };
};

export const selectPoi = (state, poiUuid) => ({
  ...state,
  selectedPoiUuid: poiUuid ? String(poiUuid) : null,
});

export const orderedStops = (state) => [...(Array.isArray(state?.stops) ? state.stops : [])]
  .sort((left, right) => Number(left?.position || 0) - Number(right?.position || 0));

const denialCopy = () => ({
  not_authenticated: { kind: 'login', title: __('Inicie sessão'), message: __('É necessário iniciar sessão para abrir este roteiro.') },
  license_expired: { kind: 'expired', title: __('Acesso expirado'), message: __('A validade deste acesso terminou.') },
  openings_exhausted: { kind: 'exhausted', title: __('Limite atingido'), message: __('O número máximo de aberturas foi atingido.') },
  license_suspended: { kind: 'suspended', title: __('Acesso suspenso'), message: __('Este acesso encontra-se temporariamente suspenso.') },
  license_revoked: { kind: 'revoked', title: __('Acesso revogado'), message: __('Este acesso já não está disponível.') },
});

export const mapAccessDenied = (reason) => denialCopy()[String(reason || '')] || {
  kind: 'denied',
  title: __('Acesso indisponível'),
  message: __('Não foi possível abrir este roteiro.'),
};

export const chooseProvider = ({ primary, fallback } = {}) => {
  if (primary?.ok && primary?.id) return primary.id;
  if (fallback?.ok && fallback?.id) return fallback.id;
  return null;
};
