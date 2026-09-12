import 'maplibre-gl/dist/maplibre-gl.css';
import '@geoman-io/maplibre-geoman-free/dist/maplibre-geoman.css';
import './admin.css';

import { createApi, RouteMapsApiError } from './api.js';
import { RouteMapEditor } from './editor.js';
import {
  CREATE_CATEGORY_VALUE,
  defaultCategorySelection,
  humanImportWarnings,
  importSummary,
} from './import-preview.js';
import { mapPoiCategoryVisibility, reorderStopUuids } from './state.js';
import { __, sprintf } from '../shared/i18n.js';

const uuid = () => globalThis.crypto?.randomUUID?.() || `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx`.replace(/[xy]/g, (char) => {
  const value = Math.random() * 16 | 0;
  return (char === 'x' ? value : (value & 0x3) | 0x8).toString(16);
});

const emptyDraft = (title = '') => ({
  title,
  geometry: null,
  stops: [],
  style: { color: '#00A099', width: 4 },
  viewport: { center: [-8.2, 39.7], zoom: 6 },
  support_overrides: {},
  map_source_override: null,
  pois: [],
  categories: [],
  display: {},
});

class RouteMapsAdminApp {
  constructor(root, config) {
    this.root = root;
    this.config = config;
    this.api = createApi(config);
    this.routes = [];
    this.categories = [];
    this.poiCatalog = [];
    this.currentRoute = null;
    this.draft = emptyDraft();
    this.mapEditor = null;
    this.importState = null;
  }

  async mount() {
    this.renderShell();
    this.bindShellEvents();
    await Promise.all([this.loadRoutes(), this.loadCategories()]);
  }

  renderShell() {
    this.root.innerHTML = `
      <div class="routemaps-admin-app">
        <header class="routemaps-admin-header">
          <div>
            <span class="routemaps-eyebrow">RouteMaps</span>
            <h1>${__('Editor de rotas')}</h1>
            <p>${__('Crie, importe, organize e publique roteiros digitais.')}</p>
          </div>
          <div class="routemaps-header-actions">
            <button type="button" class="button" data-action="import">${__('Importar KML/KMZ/GeoJSON')}</button>
            <button type="button" class="button button-primary" data-action="new-route">${__('Nova rota')}</button>
          </div>
        </header>
        <div class="routemaps-admin-grid">
          <aside class="routemaps-route-sidebar">
            <div class="routemaps-sidebar-title">${__('Rotas')}</div>
            <div class="routemaps-route-list" data-role="route-list"></div>
          </aside>
          <main class="routemaps-editor-panel">
            <div class="routemaps-empty-state" data-role="empty-state">
              <strong>${__('Selecione uma rota')}</strong>
              <span>${__('ou crie uma nova para começar a desenhar.')}</span>
            </div>
            <section class="routemaps-editor" data-role="editor" hidden>
              <div class="routemaps-editor-topbar">
                <label class="routemaps-field routemaps-title-field">
                  <span>${__('Nome da rota')}</span>
                  <input type="text" data-field="title" autocomplete="off" />
                </label>
                <div class="routemaps-editor-actions">
                  <button type="button" class="button" data-action="preview">${__('Pré-visualizar')}</button>
                  <button type="button" class="button" data-action="save">${__('Guardar rascunho')}</button>
                  <button type="button" class="button button-primary" data-action="publish">${__('Publicar')}</button>
                </div>
              </div>
              <div class="routemaps-workspace">
                <div class="routemaps-map-column">
                  <div class="routemaps-map-toolbar">
                    <button type="button" class="button" data-action="draw-route">${__('Desenhar percurso')}</button>
                    <button type="button" class="button" data-action="edit-route">${__('Editar percurso')}</button>
                    <button type="button" class="button" data-action="add-stop">${__('Adicionar paragem')}</button>
                    <button type="button" class="button" data-action="fit-route">${__('Enquadrar')}</button>
                  </div>
                  <div id="routemaps-editor-map" class="routemaps-editor-map"></div>
                </div>
                <aside class="routemaps-properties">
                  <details open>
                    <summary>${__('Percurso')}</summary>
                    <div class="routemaps-property-body">
                      <div class="routemaps-inline-fields">
                        <label class="routemaps-field"><span>${__('Cor')}</span><input type="color" data-field="route-color" value="#00A099" /></label>
                        <label class="routemaps-field"><span>${__('Espessura')}</span><input type="number" data-field="route-width" min="1" max="16" step="1" value="4" /></label>
                      </div>
                    </div>
                  </details>
                  <details open>
                    <summary>${__('Paragens')} <span class="routemaps-count" data-role="stop-count">0</span></summary>
                    <div class="routemaps-property-body">
                      <div class="routemaps-stop-list" data-role="stop-list"></div>
                    </div>
                  </details>
                  <details>
                    <summary>${__('Pontos de interesse')}</summary>
                    <div class="routemaps-property-body">
                      <div class="routemaps-search-row">
                        <input type="search" data-field="poi-search" placeholder="${__('Pesquisar POI…')}" />
                        <button type="button" class="button" data-action="search-poi">${__('Pesquisar')}</button>
                      </div>
                      <div class="routemaps-poi-results" data-role="poi-results"></div>
                      <div class="routemaps-selected-pois" data-role="selected-pois"></div>
                    </div>
                  </details>
                  <details>
                    <summary>${__('Filtros de categorias')}</summary>
                    <div class="routemaps-property-body" data-role="category-filters"></div>
                  </details>
                </aside>
              </div>
              <div class="routemaps-editor-status" data-role="status" aria-live="polite"></div>
            </section>
          </main>
        </div>
      </div>
      <dialog class="routemaps-dialog" data-role="new-route-dialog">
        <form method="dialog" class="routemaps-dialog-card" data-form="new-route">
          <h2>${__('Nova rota')}</h2>
          <label class="routemaps-field"><span>${__('Nome')}</span><input name="title" required maxlength="180" /></label>
          <div class="routemaps-dialog-actions"><button value="cancel" class="button">${__('Cancelar')}</button><button value="create" class="button button-primary">${__('Criar rota')}</button></div>
        </form>
      </dialog>
      <dialog class="routemaps-dialog routemaps-import-dialog" data-role="import-dialog">
        <div class="routemaps-dialog-card">
          <div class="routemaps-dialog-heading"><div><span class="routemaps-eyebrow">${__('Importação')}</span><h2>${__('Importar roteiro')}</h2></div><button type="button" class="button-link" data-action="close-import">${__('Fechar')}</button></div>
          <div class="routemaps-import-step" data-role="import-upload">
            <label class="routemaps-file-drop"><span>${__('Selecione KML, KMZ ou GeoJSON')}</span><input type="file" data-field="import-file" accept=".kml,.kmz,.geojson" /></label>
            <button type="button" class="button button-primary" data-action="inspect-import">${__('Analisar ficheiro')}</button>
          </div>
          <div class="routemaps-import-preview" data-role="import-preview" hidden></div>
          <div class="routemaps-dialog-actions" data-role="import-commit-actions" hidden><button type="button" class="button button-primary" data-action="commit-import">${__('Criar rascunho')}</button></div>
          <div class="routemaps-editor-status" data-role="import-status"></div>
        </div>
      </dialog>
    `;
  }

  bindShellEvents() {
    this.root.querySelector('[data-action="new-route"]').addEventListener('click', () => this.openNewRouteDialog());
    this.root.querySelector('[data-action="import"]').addEventListener('click', () => this.openImportDialog());
    this.root.querySelector('[data-action="draw-route"]').addEventListener('click', () => this.mapEditor?.drawRoute());
    this.root.querySelector('[data-action="edit-route"]').addEventListener('click', () => this.mapEditor?.editRoute());
    this.root.querySelector('[data-action="add-stop"]').addEventListener('click', () => this.mapEditor?.startStopPlacement());
    this.root.querySelector('[data-action="fit-route"]').addEventListener('click', () => this.mapEditor?.fitToGeometry());
    this.root.querySelector('[data-action="save"]').addEventListener('click', () => this.saveDraft());
    this.root.querySelector('[data-action="publish"]').addEventListener('click', () => this.publishRoute());
    this.root.querySelector('[data-action="preview"]').addEventListener('click', () => this.togglePreview());
    this.root.querySelector('[data-action="search-poi"]').addEventListener('click', () => this.searchPois());
    this.root.querySelector('[data-field="poi-search"]').addEventListener('keydown', (event) => {
      if (event.key === 'Enter') { event.preventDefault(); this.searchPois(); }
    });
    this.root.querySelector('[data-field="route-color"]').addEventListener('input', (event) => {
      this.draft.style.color = event.target.value;
      this.mapEditor?.setRouteStyle(this.draft.style);
    });
    this.root.querySelector('[data-field="route-width"]').addEventListener('input', (event) => {
      this.draft.style.width = Number(event.target.value) || 4;
      this.mapEditor?.setRouteStyle(this.draft.style);
    });
    this.root.querySelector('[data-field="title"]').addEventListener('input', (event) => { this.draft.title = event.target.value; });

    const newRouteDialog = this.root.querySelector('[data-role="new-route-dialog"]');
    this.root.querySelector('[data-form="new-route"]').addEventListener('submit', (event) => {
      event.preventDefault();
      const submitterValue = event.submitter?.value;
      if (submitterValue === 'create') {
        const form = new FormData(event.currentTarget);
        this.createRoute(String(form.get('title') || ''));
      }
      newRouteDialog.close();
    });

    this.root.querySelector('[data-action="close-import"]').addEventListener('click', () => this.closeImportDialog());
    this.root.querySelector('[data-action="inspect-import"]').addEventListener('click', () => this.inspectImport());
    this.root.querySelector('[data-action="commit-import"]').addEventListener('click', () => this.commitImport());
  }

  async loadRoutes() {
    try {
      this.routes = await this.api.listRoutes();
      this.renderRouteList();
    } catch (error) {
      this.showStatus(this.message(error), 'error');
    }
  }

  async loadCategories() {
    try {
      const response = await this.api.listCategories();
      this.categories = response.items || [];
      this.renderCategoryFilters();
    } catch (error) {
      this.categories = [];
    }
  }

  renderRouteList() {
    const list = this.root.querySelector('[data-role="route-list"]');
    list.replaceChildren();
    if (!this.routes.length) {
      const empty = document.createElement('p');
      empty.className = 'routemaps-muted';
      empty.textContent = __('Ainda não existem rotas.');
      list.append(empty);
      return;
    }

    this.routes.forEach((route) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `routemaps-route-item${this.currentRoute?.id === route.id ? ' is-active' : ''}`;
      const title = document.createElement('strong');
      title.textContent = route.title;
      const meta = document.createElement('span');
      meta.textContent = route.status === 'published' ? __('Publicada') : __('Rascunho');
      button.append(title, meta);
      button.addEventListener('click', () => this.selectRoute(route.id));
      list.append(button);
    });
  }

  async createRoute(title) {
    const clean = title.trim();
    if (!clean) return;
    try {
      const route = await this.api.createRoute(clean);
      await this.loadRoutes();
      await this.selectRoute(route.id);
    } catch (error) {
      this.showStatus(this.message(error), 'error');
    }
  }

  async selectRoute(routeId) {
    try {
      const route = await this.api.getRoute(routeId);
      this.currentRoute = route;
      const editorData = route.draft?.data || route.editor_source?.data;
      this.draft = editorData ? this.normalizeDraft(editorData, route.title) : emptyDraft(route.title);
      this.renderRouteList();
      await this.showEditor();
    } catch (error) {
      this.showStatus(this.message(error), 'error');
    }
  }

  normalizeDraft(data, fallbackTitle) {
    return {
      ...emptyDraft(fallbackTitle),
      ...data,
      title: data.title || fallbackTitle,
      stops: Array.isArray(data.stops) ? data.stops : [],
      pois: Array.isArray(data.pois) ? data.pois : [],
      categories: Array.isArray(data.categories) ? data.categories : [],
      style: { ...emptyDraft().style, ...(data.style || data.route_style || {}) },
      map_source_override: data.map_source_override ?? data.map_source_id ?? null,
    };
  }

  async showEditor() {
    this.root.querySelector('[data-role="empty-state"]').hidden = true;
    this.root.querySelector('[data-role="editor"]').hidden = false;
    this.root.querySelector('[data-field="title"]').value = this.draft.title;
    this.root.querySelector('[data-field="route-color"]').value = this.draft.style.color || '#00A099';
    this.root.querySelector('[data-field="route-width"]').value = this.draft.style.width || 4;

    if (this.mapEditor) {
      this.mapEditor.destroy();
      this.mapEditor = null;
    }

    // Render imported content before the map starts. A basemap problem must
    // never make valid imported route data look empty.
    this.renderStops(false);
    this.renderSelectedPois();
    this.renderCategoryFilters();

    const mapConfig = this.config.map || {};
    this.mapEditor = new RouteMapEditor(this.root.querySelector('#routemaps-editor-map'), {
      styleUrl: mapConfig.styleUrl || mapConfig.openFreeMapStyleUrl,
      center: this.draft.viewport?.center,
      zoom: Number(this.draft.viewport?.zoom),
      onGeometryChange: (geometry) => { this.draft.geometry = geometry; },
      onStopAdd: (coordinates) => this.addStop(coordinates),
      onStopMove: (stopUuid, coordinates) => this.moveStop(stopUuid, coordinates),
    });

    try {
      await this.mapEditor.mount();
      await this.mapEditor.loadGeometry(this.draft.geometry);
      this.mapEditor.setRouteStyle(this.draft.style);
      this.mapEditor.setStops(this.draft.stops);
      this.renderSelectedPois();
      if (this.draft.geometry) this.mapEditor.fitToGeometry(this.draft.geometry);

      if (this.mapEditor.isDegraded()) {
        this.showStatus(__('Rota carregada. O mapa base externo não respondeu; a geometria continua disponível em modo simplificado.'), 'warning');
      } else {
        this.showStatus(__('Rota carregada.'), 'success');
      }
    } catch (error) {
      this.showStatus(sprintf(__('Rota carregada, mas o mapa não pôde ser iniciado: %s'), this.message(error)), 'warning');
    }
  }

  addStop(coordinates) {
    this.draft.stops = [...this.draft.stops, {
      entity_uuid: uuid(),
      name: sprintf(__('Paragem %s'), this.draft.stops.length + 1),
      coordinates,
    }];
    this.renderStops();
  }

  moveStop(stopUuid, coordinates) {
    this.draft.stops = this.draft.stops.map((stop) => stop.entity_uuid === stopUuid ? { ...stop, coordinates } : stop);
    this.renderStops(false);
  }

  removeStop(stopUuid) {
    this.draft.stops = this.draft.stops.filter((stop) => stop.entity_uuid !== stopUuid);
    this.renderStops();
  }

  reorderStop(from, to) {
    const orderedUuids = reorderStopUuids(this.draft.stops.map((stop) => stop.entity_uuid), from, to);
    const byUuid = new Map(this.draft.stops.map((stop) => [stop.entity_uuid, stop]));
    this.draft.stops = orderedUuids.map((id) => byUuid.get(id));
    this.renderStops();
  }

  renderStops(syncMap = true) {
    const list = this.root.querySelector('[data-role="stop-list"]');
    list.replaceChildren();
    this.root.querySelector('[data-role="stop-count"]').textContent = String(this.draft.stops.length);
    this.draft.stops.forEach((stop, index) => {
      const row = document.createElement('div');
      row.className = 'routemaps-stop-row';
      const number = document.createElement('span'); number.className = 'routemaps-stop-number'; number.textContent = String(index + 1);
      const input = document.createElement('input'); input.type = 'text'; input.value = stop.name || sprintf(__('Paragem %s'), index + 1);
      input.addEventListener('input', () => { stop.name = input.value; });
      const up = this.iconButton('↑', __('Mover para cima'), () => index > 0 && this.reorderStop(index, index - 1));
      const down = this.iconButton('↓', __('Mover para baixo'), () => index < this.draft.stops.length - 1 && this.reorderStop(index, index + 1));
      const remove = this.iconButton('×', __('Remover paragem'), () => this.removeStop(stop.entity_uuid));
      row.append(number, input, up, down, remove);
      list.append(row);
    });
    if (syncMap) this.mapEditor?.setStops(this.draft.stops);
  }

  iconButton(text, label, handler) {
    const button = document.createElement('button');
    button.type = 'button'; button.className = 'button button-small'; button.textContent = text; button.title = label;
    button.addEventListener('click', handler);
    return button;
  }

  async searchPois() {
    const query = this.root.querySelector('[data-field="poi-search"]').value.trim();
    try {
      const response = await this.api.searchPois(query);
      this.poiCatalog = response.items || [];
      this.renderPoiResults();
    } catch (error) {
      this.showStatus(this.message(error), 'error');
    }
  }

  renderPoiResults() {
    const container = this.root.querySelector('[data-role="poi-results"]');
    container.replaceChildren();
    this.poiCatalog.forEach((poi) => {
      const row = document.createElement('div'); row.className = 'routemaps-poi-row';
      const label = document.createElement('span'); label.textContent = poi.name;
      const add = document.createElement('button'); add.type = 'button'; add.className = 'button button-small'; add.textContent = __('Adicionar');
      add.disabled = this.draft.pois.some((item) => item.source_poi_uuid === poi.uuid);
      add.addEventListener('click', () => this.addPoi(poi));
      row.append(label, add); container.append(row);
    });
  }

  addPoi(poi) {
    if (this.draft.pois.some((item) => item.source_poi_uuid === poi.uuid)) return;
    this.draft.pois = [...this.draft.pois, {
      entity_uuid: uuid(),
      source_poi_uuid: poi.uuid,
      position: this.draft.pois.length + 1,
      required: false,
      category_id: poi.category_id,
      name: poi.name,
      coordinates: [poi.longitude, poi.latitude],
      color: poi.color,
    }];
    this.renderPoiResults();
    this.renderSelectedPois();
  }

  removePoi(entityUuid) {
    this.draft.pois = this.draft.pois.filter((poi) => poi.entity_uuid !== entityUuid).map((poi, index) => ({ ...poi, position: index + 1 }));
    this.renderPoiResults();
    this.renderSelectedPois();
  }

  renderSelectedPois() {
    const container = this.root.querySelector('[data-role="selected-pois"]');
    container.replaceChildren();
    const visibility = Object.fromEntries(this.categories.map((category) => [String(category.id), category.visible !== false]));
    const visiblePois = mapPoiCategoryVisibility(this.draft.pois, visibility);
    visiblePois.forEach((poi) => {
      const row = document.createElement('div'); row.className = 'routemaps-poi-row is-selected';
      const label = document.createElement('span'); label.textContent = poi.name || 'POI';
      const remove = this.iconButton('×', __('Remover POI'), () => this.removePoi(poi.entity_uuid));
      row.append(label, remove); container.append(row);
    });
    this.mapEditor?.setPois(visiblePois);
  }

  renderCategoryFilters() {
    const container = this.root.querySelector('[data-role="category-filters"]');
    if (!container) return;
    container.replaceChildren();
    this.categories.forEach((category) => {
      if (category.visible === undefined) category.visible = true;
      const label = document.createElement('label'); label.className = 'routemaps-category-toggle';
      const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.checked = category.visible !== false;
      checkbox.addEventListener('change', () => { category.visible = checkbox.checked; this.renderSelectedPois(); });
      const text = document.createElement('span'); text.textContent = category.name;
      label.append(checkbox, text); container.append(label);
    });
  }

  buildDraftPayload() {
    if (!this.draft.geometry || !['LineString', 'MultiLineString'].includes(this.draft.geometry.type)) {
      throw new Error(__('Desenhe primeiro o percurso da rota.'));
    }
    if (!this.draft.title.trim()) {
      throw new Error(__('Defina o nome da rota.'));
    }
    return {
      ...this.draft,
      viewport: this.mapEditor?.viewport() || this.draft.viewport,
      style: { color: this.draft.style.color || '#00A099', width: Number(this.draft.style.width) || 4 },
    };
  }

  async saveDraft() {
    if (!this.currentRoute) return;
    try {
      const response = await this.api.saveDraft(this.currentRoute.id, this.buildDraftPayload());
      this.showStatus(sprintf(__('Rascunho guardado · versão %s.'), response.version_number), 'success');
      return response;
    } catch (error) {
      this.showStatus(this.message(error), 'error');
      throw error;
    }
  }

  async publishRoute() {
    if (!this.currentRoute || !this.config.capabilities?.publish) {
      this.showStatus(__('Não tem permissão para publicar rotas.'), 'error');
      return;
    }
    try {
      await this.saveDraft();
      const critical = window.confirm(__('Marcar esta atualização como crítica?\n\nOK = crítica · Cancelar = atualização normal'));
      const result = await this.api.publishRoute(this.currentRoute.id, critical, null);
      const hash = result.content_hash ? ` · ${result.content_hash.slice(0, 12)}…` : '';
      this.showStatus(sprintf(__('Versão %1$s publicada%2$s'), result.version_number, hash), 'success');
      await this.loadRoutes();
      await this.selectRoute(this.currentRoute.id);
    } catch (error) {
      if (!(error instanceof RouteMapsApiError)) return;
    }
  }

  togglePreview() {
    const editor = this.root.querySelector('[data-role="editor"]');
    const preview = !editor.classList.contains('is-preview');
    editor.classList.toggle('is-preview', preview);
    this.root.querySelector('[data-action="preview"]').textContent = preview ? __('Sair da pré-visualização') : __('Pré-visualizar');
    this.showStatus(preview ? __('Modo de pré-visualização ativo.') : __('Modo de edição ativo.'), 'success');
  }

  openNewRouteDialog() {
    const dialog = this.root.querySelector('[data-role="new-route-dialog"]');
    dialog.querySelector('input[name="title"]').value = '';
    dialog.showModal();
  }

  openImportDialog() {
    this.importState = null;
    this.root.querySelector('[data-role="import-preview"]').hidden = true;
    this.root.querySelector('[data-role="import-commit-actions"]').hidden = true;
    this.root.querySelector('[data-role="import-status"]').textContent = '';
    this.root.querySelector('[data-role="import-dialog"]').showModal();
  }

  async closeImportDialog() {
    if (this.importState?.import_id) {
      try { await this.api.cancelImport(this.importState.import_id); } catch (_) { /* best effort cleanup */ }
    }
    this.importState = null;
    this.root.querySelector('[data-role="import-dialog"]').close();
  }

  async inspectImport() {
    const file = this.root.querySelector('[data-field="import-file"]').files?.[0];
    if (!file) {
      this.showImportStatus(__('Selecione um ficheiro.'), 'error'); return;
    }
    this.showImportStatus(__('A analisar…'));
    try {
      this.importState = await this.api.inspectImport(file);
      this.renderImportPreview();
      this.showImportStatus(__('Ficheiro analisado.'), 'success');
    } catch (error) {
      this.showImportStatus(this.message(error), 'error');
    }
  }

  renderImportPreview() {
    const preview = this.importState?.preview;
    const container = this.root.querySelector('[data-role="import-preview"]');
    container.replaceChildren();
    if (!preview) { container.hidden = true; return; }
    container.hidden = false;

    const heading = document.createElement('h3');
    heading.textContent = preview.title || __('Rota importada');
    container.append(heading);

    const summary = importSummary(preview);
    const summaryWrap = document.createElement('div');
    summaryWrap.className = 'routemaps-import-summary';
    const summaryRows = [
      [__('Formato'), String(preview.format || '').toUpperCase() || '—'],
      [__('Percurso'), sprintf(__('%s linha(s) de rota'), summary.routeLines)],
      [__('Pontos'), sprintf(__('%s ponto(s)'), summary.points)],
      [__('Categorias encontradas'), String(summary.categories)],
    ];
    if (summary.polygons) summaryRows.push([__('Áreas/polígonos'), String(summary.polygons)]);
    summaryRows.forEach(([label, value]) => {
      const item = document.createElement('div');
      item.className = 'routemaps-import-summary-item';
      const strong = document.createElement('strong'); strong.textContent = label;
      const span = document.createElement('span'); span.textContent = value;
      item.append(strong, span);
      summaryWrap.append(item);
    });
    container.append(summaryWrap);

    const readableWarnings = humanImportWarnings(preview.warnings);
    if (readableWarnings.length) {
      const warningWrap = document.createElement('div');
      warningWrap.className = 'routemaps-import-warnings';
      const warningTitle = document.createElement('strong'); warningTitle.textContent = __('Avisos de importação');
      const list = document.createElement('ul');
      readableWarnings.forEach((warning) => {
        const li = document.createElement('li'); li.textContent = warning; list.append(li);
      });
      warningWrap.append(warningTitle, list);
      container.append(warningWrap);
    }

    if (Array.isArray(preview.categories) && preview.categories.length) {
      const mappingTitle = document.createElement('strong');
      mappingTitle.textContent = __('Categorias do ficheiro');
      container.append(mappingTitle);

      const mapWrap = document.createElement('div');
      mapWrap.className = 'routemaps-import-mapping';
      preview.categories.forEach((source) => {
        const sourceLabel = source.name || __('Categoria');
        const row = document.createElement('label'); row.className = 'routemaps-import-map-row';
        const sourceName = document.createElement('span'); sourceName.textContent = sourceLabel;
        const select = document.createElement('select'); select.dataset.sourceCategory = sourceLabel;

        const none = document.createElement('option');
        none.value = ''; none.textContent = __('Sem correspondência'); select.append(none);

        if (this.config.capabilities?.managePois) {
          const create = document.createElement('option');
          create.value = CREATE_CATEGORY_VALUE;
          create.textContent = sprintf(__('Criar nova categoria “%s”'), sourceLabel);
          select.append(create);
        }

        this.categories.forEach((category) => {
          const option = document.createElement('option');
          option.value = String(category.id); option.textContent = category.name; select.append(option);
        });

        select.value = defaultCategorySelection(sourceLabel, this.categories, Boolean(this.config.capabilities?.managePois));
        row.append(sourceName, select);
        mapWrap.append(row);
      });
      container.append(mapWrap);
    }
    this.root.querySelector('[data-role="import-commit-actions"]').hidden = false;
  }

  async commitImport() {
    if (!this.importState?.import_id) return;
    const categoryMap = {};
    const createCategories = [];
    this.root.querySelectorAll('[data-source-category]').forEach((select) => {
      const source = select.dataset.sourceCategory;
      if (!source) return;
      if (select.value === CREATE_CATEGORY_VALUE) {
        createCategories.push(source);
      } else if (select.value) {
        categoryMap[source] = Number(select.value);
      }
    });
    this.showImportStatus(__('A criar rascunho…'));
    try {
      const result = await this.api.commitImport(this.importState.import_id, categoryMap, createCategories);
      this.importState = null;
      this.root.querySelector('[data-role="import-dialog"]').close();
      await this.loadCategories();
      await this.loadRoutes();
      await this.selectRoute(result.route.id);
      this.showStatus(__('Rota importada para rascunho.'), 'success');
    } catch (error) {
      this.showImportStatus(this.message(error), 'error');
    }
  }

  showStatus(message, type = '') {
    const node = this.root.querySelector('[data-role="status"]');
    if (!node) return;
    node.textContent = message || '';
    node.dataset.type = type;
  }

  showImportStatus(message, type = '') {
    const node = this.root.querySelector('[data-role="import-status"]');
    node.textContent = message || '';
    node.dataset.type = type;
  }

  message(error) {
    if (error instanceof RouteMapsApiError) return error.message;
    return error?.message || __('Ocorreu um erro no RouteMaps.');
  }
}

const boot = async () => {
  const root = document.getElementById('routemaps-admin-root');
  const config = globalThis.RouteMapsAdmin;
  if (!root || !config) return;
  const app = new RouteMapsAdminApp(root, config);
  await app.mount();
  globalThis.RouteMapsAdminApp = app;
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => { void boot(); });
} else {
  void boot();
}
