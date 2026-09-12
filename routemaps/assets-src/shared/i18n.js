import { __ as wordpressTranslate, sprintf as wordpressSprintf } from '@wordpress/i18n';

const bootstrapCatalogue = () => {
  const admin = globalThis.RouteMapsAdmin?.i18n;
  if (admin && typeof admin === 'object') return admin;

  const node = globalThis.document?.getElementById?.('routemaps-viewer-bootstrap');
  if (!node) return {};
  try {
    const payload = JSON.parse(node.textContent || '{}');
    return payload?.i18n && typeof payload.i18n === 'object' ? payload.i18n : {};
  } catch {
    return {};
  }
};

export const __ = (message) => {
  const source = String(message ?? '');
  const translated = bootstrapCatalogue()[source];
  if (typeof translated === 'string' && translated !== '') return translated;
  return wordpressTranslate(source, 'routemaps');
};

export const sprintf = (format, ...args) => wordpressSprintf(__(format), ...args);
