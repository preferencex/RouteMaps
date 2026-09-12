import 'maplibre-gl/dist/maplibre-gl.css';
import './viewer.css';
import { createViewerApi } from './api.js';
import { createViewerMap, updatePoiVisibility, updateUserLocation } from './map.js';
import { visiblePois } from './filters.js';
import { createViewerState, mapAccessDenied, orderedStops, selectPoi, toggleCategory } from './state.js';
import { renderPoiDetail, renderPoiList, renderStops } from './pois.js';
import { coordinatesFromPosition, startGeolocation } from './geolocation.js';
import { fullscreenSupported, requestViewerFullscreen } from './fullscreen.js';
import { createInstallPromptManager, registerRouteMapsServiceWorker } from '../pwa/install-prompt.js';
import { __ } from '../shared/i18n.js';

const readBootstrap = () => {
  const node = document.getElementById('routemaps-viewer-bootstrap');
  if (!node) throw new Error('viewer_bootstrap_missing');
  return JSON.parse(node.textContent || '{}');
};

class RouteMapsViewerApp {
  constructor(root, bootstrap) {
    this.root = root;
    this.bootstrap = bootstrap;
    this.api = createViewerApi(bootstrap);
    this.state = createViewerState();
    this.map = null;
    this.access = null;
    this.heartbeatTimer = null;
    this.geolocationWatchId = null;
    this.viewerReady = false;
    this.installPrompt = createInstallPromptManager();
    this.installPrompt.bind();
    this.installPrompt.onChange(() => this.renderPwaInstall());
  }

  async mount() {
    this.renderShell();
    try {
      const access = await this.api.resolveAccess();
      if (!access?.allowed) {
        this.renderDenied(access?.reason);
        return;
      }
      this.access = access;
      const payload = await this.api.getRoute(access.license_uuid, access.session_uuid);
      this.state = createViewerState(payload);
      this.renderPayload();
      this.viewerReady = true;
      this.activatePwaExperience();
      this.startHeartbeat();
      if (access.canonical_url && globalThis.history?.replaceState) {
        globalThis.history.replaceState({}, '', access.canonical_url);
      }
    } catch (error) {
      this.renderFatal(error?.message || __('Não foi possível abrir o roteiro.'));
    }
  }

  renderShell() {
    this.root.innerHTML = `
      <div class="routemaps-viewer-app">
        <header class="routemaps-viewer-header"><strong>RouteMaps</strong><span data-role="route-title"></span><div class="routemaps-viewer-actions"><button type="button" data-role="locate">${__('A minha localização')}</button><button type="button" data-role="fullscreen" hidden>${__('Ecrã inteiro')}</button><span data-role="location-status" aria-live="polite"></span></div></header>
        <main class="routemaps-viewer-main">
          <aside class="routemaps-viewer-panel">
            <div class="routemaps-viewer-filters" data-role="filters"></div>
            <section><h2>${__('Paragens')}</h2><div data-role="stops"></div></section>
            <section><h2>${__('Pontos de interesse')}</h2><div data-role="pois"></div></section>
          </aside>
          <section class="routemaps-viewer-map-wrap"><div id="routemaps-viewer-map"></div><div class="routemaps-viewer-status" data-role="status" hidden></div></section>
          <aside class="routemaps-viewer-detail" data-role="detail" hidden></aside>
          <aside class="routemaps-pwa-install" data-role="pwa-install" hidden aria-live="polite"></aside>
        </main>
      </div>`;
  }

  renderPayload() {
    this.root.querySelector('[data-role="route-title"]').textContent = this.state.route?.title || '';
    this.renderFilters();
    renderStops(this.root.querySelector('[data-role="stops"]'), orderedStops(this.state));
    this.renderPois();
    this.map = createViewerMap(this.root.querySelector('#routemaps-viewer-map'), {
      ...this.state,
      route_style: this.state.routeStyle,
    }, { onPoiSelect: (uuid) => this.selectPoi(uuid) });
    this.bindViewerControls();
  }

  bindViewerControls() {
    const locate = this.root.querySelector('[data-role="locate"]');
    const locationStatus = this.root.querySelector('[data-role="location-status"]');
    if (!globalThis.navigator?.geolocation) locate.hidden = true;
    locate.addEventListener('click', () => {
      if (this.geolocationWatchId !== null) return;
      try {
        this.geolocationWatchId = startGeolocation({
          onPosition: (position) => {
            try {
              const coordinates = coordinatesFromPosition(position);
              updateUserLocation(this.map, coordinates);
              locationStatus.textContent = __('Localização ativa');
              locate.textContent = __('Localização ativa');
            } catch {
              locationStatus.textContent = __('Localização indisponível');
            }
          },
          onError: () => { locationStatus.textContent = __('Não foi possível obter a localização'); },
        });
      } catch {
        locationStatus.textContent = __('Geolocalização não suportada');
      }
    });

    const fullscreen = this.root.querySelector('[data-role="fullscreen"]');
    if (fullscreenSupported(document)) {
      fullscreen.hidden = false;
      fullscreen.addEventListener('click', () => {
        requestViewerFullscreen(document).catch(() => {});
      });
    }
  }

  activatePwaExperience() {
    const pwa = this.bootstrap?.pwa || {};
    registerRouteMapsServiceWorker({
      serviceWorkerUrl: pwa.service_worker_url || '/routemaps/service-worker.js',
      scope: pwa.scope || '/routemaps/',
    }).catch(() => {});
    this.renderPwaInstall();
  }

  renderPwaInstall() {
    const host = this.root.querySelector('[data-role="pwa-install"]');
    if (!host || !this.viewerReady) return;

    const mode = this.installPrompt.mode();
    host.replaceChildren();
    host.hidden = mode === 'hidden';
    if (mode === 'hidden') return;

    const title = document.createElement('strong');
    title.textContent = __('Acesso rápido ao RouteMaps');
    const message = document.createElement('p');
    const actions = document.createElement('div');
    actions.className = 'routemaps-pwa-install__actions';

    if (mode === 'prompt') {
      message.textContent = __('Adicione este roteiro ao ecrã principal para o abrir mais rapidamente.');
      const install = document.createElement('button');
      install.type = 'button';
      install.textContent = __('Adicionar atalho');
      install.addEventListener('click', async () => {
        await this.installPrompt.prompt();
        this.renderPwaInstall();
      });
      actions.append(install);
    } else {
      message.textContent = __('No Safari, toque em Partilhar e escolha “Adicionar ao ecrã principal”.');
    }

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'routemaps-pwa-install__dismiss';
    dismiss.textContent = __('Agora não');
    dismiss.addEventListener('click', () => {
      this.installPrompt.dismiss();
      this.renderPwaInstall();
    });
    actions.append(dismiss);
    host.append(title, message, actions);
  }

  renderFilters() {
    const host = this.root.querySelector('[data-role="filters"]');
    host.replaceChildren();
    this.state.categories.forEach((category) => {
      if (!category?.uuid) return;
      const label = document.createElement('label'); label.className = 'routemaps-viewer-filter';
      const input = document.createElement('input'); input.type = 'checkbox'; input.checked = this.state.categoryVisibility[category.uuid] !== false;
      input.addEventListener('change', () => {
        this.state = toggleCategory(this.state, category.uuid);
        updatePoiVisibility(this.map, this.state.pois, this.state.categoryVisibility);
        this.renderPois();
      });
      const text = document.createElement('span'); text.textContent = category.name || __('Categoria');
      label.append(input, text); host.append(label);
    });
  }

  renderPois() {
    renderPoiList(this.root.querySelector('[data-role="pois"]'), visiblePois(this.state.pois, this.state.categoryVisibility), (uuid) => this.selectPoi(uuid));
  }

  selectPoi(uuid) {
    this.state = selectPoi(this.state, uuid);
    const poi = this.state.pois.find((item) => item.entity_uuid === uuid) || null;
    renderPoiDetail(this.root.querySelector('[data-role="detail"]'), poi, () => {
      this.state = selectPoi(this.state, null);
      renderPoiDetail(this.root.querySelector('[data-role="detail"]'), null);
    });
  }

  renderDenied(reason) {
    const denial = mapAccessDenied(reason);
    const status = this.root.querySelector('[data-role="status"]'); status.hidden = false; status.replaceChildren();
    const title = document.createElement('h1'); title.textContent = denial.title;
    const message = document.createElement('p'); message.textContent = denial.message;
    status.append(title, message);
    if (denial.kind === 'login') {
      const link = document.createElement('a'); link.href = this.bootstrap.login_url || '/routemaps/login/'; link.textContent = __('Iniciar sessão'); status.append(link);
    }
  }

  renderFatal(message) {
    const status = this.root.querySelector('[data-role="status"]'); status.hidden = false; status.textContent = message;
  }

  startHeartbeat() {
    if (!this.access?.session_uuid) return;
    const beat = () => {
      if (document.visibilityState !== 'visible') return;
      this.api.heartbeat(this.access.session_uuid).catch(() => {});
    };
    this.heartbeatTimer = globalThis.setInterval(beat, 5 * 60 * 1000);
  }
}

const root = document.getElementById('routemaps-viewer');
if (root) {
  const app = new RouteMapsViewerApp(root, readBootstrap());
  app.mount();
}
