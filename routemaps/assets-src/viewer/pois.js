import { buildGoogleMapsUrl, buildWazeUrl } from './navigation.js';
import { __, sprintf } from '../shared/i18n.js';

const text = (tag, value, className = '') => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  node.textContent = value == null ? '' : String(value);
  return node;
};

export const safeHttpUrl = (value) => {
  try {
    const url = new URL(String(value));
    return ['http:', 'https:'].includes(url.protocol) ? url.toString() : null;
  } catch {
    return null;
  }
};


export const poiDetailModel = (poi = {}) => {
  const category = poi?.category && typeof poi.category === 'object'
    ? {
        name: String(poi.category.name || ''),
        icon: String(poi.category.icon || ''),
        color: String(poi.category.color || ''),
      }
    : { name: '', icon: '', color: '' };
  const rawCta = poi?.display?.cta && typeof poi.display.cta === 'object' ? poi.display.cta : null;
  const ctaUrl = rawCta ? safeHttpUrl(rawCta.url) : null;
  const cta = ctaUrl
    ? { label: String(rawCta.label || __('Abrir')), url: ctaUrl }
    : null;
  return {
    category,
    requiredLabel: poi?.required === true ? __('Paragem obrigatória') : __('Paragem opcional'),
    cta,
  };
};

const navigationLinks = (coordinates, label) => {
  if (!Array.isArray(coordinates) || coordinates.length < 2) return [];
  const lng = Number(coordinates[0]);
  const lat = Number(coordinates[1]);
  try {
    return [
      { label: 'Google Maps', url: buildGoogleMapsUrl(lat, lng, label) },
      { label: 'Waze', url: buildWazeUrl(lat, lng) },
    ];
  } catch {
    return [];
  }
};

const externalLink = (label, url, className = '') => {
  const link = text('a', label, className);
  link.href = url;
  link.target = '_blank';
  link.rel = 'noopener noreferrer';
  return link;
};

export const renderStops = (container, stops = []) => {
  container.replaceChildren();
  stops.forEach((stop, index) => {
    const row = document.createElement('div');
    row.className = 'routemaps-viewer-stop';
    row.append(text('span', stop.position || index + 1, 'routemaps-viewer-stop__number'));
    const content = document.createElement('div');
    content.append(text('span', stop.name || sprintf(__('Paragem %s'), index + 1)));
    const links = navigationLinks(stop.coordinates, stop.name || sprintf(__('Paragem %s'), index + 1));
    if (links.length) {
      const actions = document.createElement('div');
      actions.className = 'routemaps-viewer-nav-links';
      links.forEach((item) => actions.append(externalLink(item.label, item.url)));
      content.append(actions);
    }
    row.append(content);
    container.append(row);
  });
};

export const renderPoiList = (container, pois = [], onSelect = () => {}) => {
  container.replaceChildren();
  pois.forEach((poi) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'routemaps-viewer-poi-row';
    button.append(text('strong', poi.display?.name || __('Ponto de interesse')));
    if (poi.category?.name) button.append(text('span', poi.category.name));
    button.addEventListener('click', () => onSelect(poi.entity_uuid));
    container.append(button);
  });
};

export const renderPoiDetail = (container, poi, onClose = () => {}) => {
  container.replaceChildren();
  if (!poi) {
    container.hidden = true;
    return;
  }
  container.hidden = false;
  const close = document.createElement('button'); close.type = 'button'; close.className = 'routemaps-viewer-detail__close'; close.textContent = '×'; close.setAttribute('aria-label', __('Fechar')); close.addEventListener('click', onClose); container.append(close);
  const mainUrl = safeHttpUrl(poi.media?.main_url);
  if (mainUrl) {
    const image = document.createElement('img'); image.src = mainUrl; image.alt = String(poi.display?.name || ''); image.loading = 'lazy'; container.append(image);
  }
  const galleryUrls = Array.isArray(poi.media?.gallery_urls) ? poi.media.gallery_urls.map(safeHttpUrl).filter(Boolean) : [];
  if (galleryUrls.length) {
    const gallery = document.createElement('div'); gallery.className = 'routemaps-viewer-detail__gallery';
    galleryUrls.forEach((url) => { const image = document.createElement('img'); image.src = url; image.alt = ''; image.loading = 'lazy'; gallery.append(image); });
    container.append(gallery);
  }
  container.append(text('h2', poi.display?.name || __('Ponto de interesse')));
  const detail = poiDetailModel(poi);
  if (detail.category.name) {
    const category = text('div', `${detail.category.icon ? `${detail.category.icon} · ` : ''}${detail.category.name}`, 'routemaps-viewer-detail__category');
    if (detail.category.color) category.style.borderColor = detail.category.color;
    container.append(category);
  }
  container.append(text('div', detail.requiredLabel, 'routemaps-viewer-detail__required'));
  if (poi.display?.description) container.append(text('p', poi.display.description));
  if (poi.display?.route_note) container.append(text('p', poi.display.route_note, 'routemaps-viewer-detail__note'));
  const meta = document.createElement('dl');
  [[__('Morada'), poi.display?.address], [__('Horário'), poi.display?.opening_hours], [__('Telefone'), poi.display?.phone]].forEach(([label, value]) => {
    if (!value) return; meta.append(text('dt', label), text('dd', value));
  });
  if (meta.children.length) container.append(meta);
  const website = safeHttpUrl(poi.display?.website);
  if (website) { const link = text('a', __('Website')); link.href = website; link.target = '_blank'; link.rel = 'noopener noreferrer'; container.append(link); }
  if (detail.cta) {
    const cta = externalLink(detail.cta.label, detail.cta.url, 'routemaps-viewer-detail__cta');
    container.append(cta);
  }
  const links = navigationLinks(poi.coordinates, poi.display?.name || __('Destino'));
  if (links.length) {
    const actions = document.createElement('div');
    actions.className = 'routemaps-viewer-detail__navigation';
    links.forEach((item) => actions.append(externalLink(sprintf(__('Abrir no %s'), item.label), item.url, 'routemaps-viewer-detail__nav-link')));
    container.append(actions);
  }
};
