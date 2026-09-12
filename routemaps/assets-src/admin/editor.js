import { Geoman } from '@geoman-io/maplibre-geoman-free';
import { Map, Marker, NavigationControl, setWorkerUrl } from 'maplibre-gl';
import mapLibreWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

setWorkerUrl(mapLibreWorkerUrl);

const ROUTE_SOURCE_ID = 'routemaps-route-preview';
const ROUTE_LAYER_ID = 'routemaps-route-preview-line';

const FALLBACK_STYLE = {
  version: 8,
  sources: {},
  layers: [
    {
      id: 'routemaps-fallback-background',
      type: 'background',
      paint: { 'background-color': '#eef2f3' },
    },
  ],
};

const waitForStyle = (map, timeoutMs = 8000) => new Promise((resolve, reject) => {
  if (map.isStyleLoaded()) {
    resolve();
    return;
  }

  let settled = false;
  const cleanup = () => {
    map.off('style.load', onLoad);
    clearTimeout(timer);
  };
  const onLoad = () => {
    if (settled) return;
    settled = true;
    cleanup();
    resolve();
  };
  const timer = setTimeout(() => {
    if (settled) return;
    settled = true;
    cleanup();
    reject(new Error('basemap_style_timeout'));
  }, timeoutMs);

  map.on('style.load', onLoad);
});

const featureGeometry = (featureData) => {
  if (!featureData || typeof featureData.getGeoJson !== 'function') {
    return null;
  }

  const feature = featureData.getGeoJson();
  const geometry = feature?.geometry;
  if (!geometry || !['LineString', 'MultiLineString'].includes(geometry.type)) {
    return null;
  }
  return geometry;
};

export class RouteMapEditor {
  constructor(container, options = {}) {
    this.container = container;
    this.options = options;
    this.map = null;
    this.geoman = null;
    this.stopMarkers = [];
    this.poiMarkers = [];
    this.stopPlacementHandler = null;
    this.geometry = null;
    this.routeStyle = { color: '#00A099', width: 4 };
    this.degraded = false;
    this.loadingGeometry = false;
  }

  async mount() {
    this.map = new Map({
      container: this.container,
      style: this.options.styleUrl || 'https://tiles.openfreemap.org/styles/liberty',
      center: this.options.center || [-8.2, 39.7],
      zoom: Number.isFinite(this.options.zoom) ? this.options.zoom : 6,
      attributionControl: true,
    });
    this.map.addControl(new NavigationControl({ visualizePitch: true }), 'top-right');

    try {
      await waitForStyle(this.map);
    } catch (_) {
      this.degraded = true;
      this.map.setStyle(FALLBACK_STYLE);
      await waitForStyle(this.map, 3000);
    }

    this.geoman = new Geoman(this.map, {
      settings: { snapDistance: 18 },
    });
    try {
      await Promise.race([
        this.geoman.waitForGeomanLoaded(),
        new Promise((_, reject) => setTimeout(() => reject(new Error('geoman_load_timeout')), 12000)),
      ]);
    } catch (_) {
      this.degraded = true;
      this.geoman?.destroy?.();
      this.geoman = null;
    }

    this.map.on('gm:create', (event) => this.handleFeatureChange(event.feature));
    this.map.on('gm:changeend', (event) => this.handleFeatureChange(event.feature));
    this.map.on('gm:dragend', (event) => this.handleFeatureChange(event.feature));
    this.map.on('gm:remove', () => {
      if (this.loadingGeometry) return;
      const collection = this.geoman?.features?.exportGeoJson?.();
      const line = collection?.features?.find((feature) => ['LineString', 'MultiLineString'].includes(feature?.geometry?.type));
      this.setGeometry(line?.geometry || null, true);
    });

    return this;
  }

  isDegraded() {
    return this.degraded;
  }

  async drawRoute() {
    if (!this.geoman) return;
    await this.geoman.options.enableMode('draw', 'line');
  }

  async editRoute() {
    if (!this.geoman) return;
    await this.geoman.options.enableMode('edit', 'change');
  }

  async loadGeometry(geometry) {
    this.loadingGeometry = true;
    try {
      if (this.geoman) {
        await this.geoman.features.deleteAll();
      }

      if (this.geoman && geometry && ['LineString', 'MultiLineString'].includes(geometry.type)) {
        await this.geoman.features.importGeoJson({
          type: 'Feature',
          properties: { routemaps_role: 'route' },
          geometry,
        });
      }

      // Geoman can emit remove/create events while resetting its feature store.
      // Reassert the canonical RouteMaps geometry after that cycle has completed.
      this.setGeometry(geometry || null, false);
    } finally {
      this.loadingGeometry = false;
    }
  }

  setRouteStyle(style = {}) {
    this.routeStyle = {
      color: typeof style.color === 'string' ? style.color : '#00A099',
      width: Number.isFinite(Number(style.width)) ? Math.max(1, Number(style.width)) : 4,
    };
    this.syncRouteLayer();
  }

  setStops(stops = []) {
    this.stopMarkers.forEach((marker) => marker.remove());
    this.stopMarkers = [];
    if (!this.map) return;

    stops.forEach((stop, index) => {
      if (!Array.isArray(stop.coordinates) || stop.coordinates.length < 2) return;
      const marker = new Marker({ color: '#00A099', draggable: true })
        .setLngLat([Number(stop.coordinates[0]), Number(stop.coordinates[1])])
        .addTo(this.map);
      marker.getElement().dataset.stopIndex = String(index + 1);
      marker.getElement().classList.add('routemaps-stop-marker');
      marker.on('dragend', () => {
        const point = marker.getLngLat();
        this.options.onStopMove?.(stop.entity_uuid, [point.lng, point.lat]);
      });
      this.stopMarkers.push(marker);
    });
  }

  setPois(pois = []) {
    this.poiMarkers.forEach((marker) => marker.remove());
    this.poiMarkers = [];
    if (!this.map) return;

    pois.filter((poi) => poi.visible !== false).forEach((poi) => {
      if (!Array.isArray(poi.coordinates) || poi.coordinates.length < 2) return;
      const marker = new Marker({ color: poi.color || '#D8A600', scale: 0.8 })
        .setLngLat([Number(poi.coordinates[0]), Number(poi.coordinates[1])])
        .addTo(this.map);
      marker.getElement().classList.add('routemaps-poi-marker');
      marker.getElement().title = poi.name || 'POI';
      this.poiMarkers.push(marker);
    });
  }

  startStopPlacement() {
    if (!this.map) return;
    this.stopPlacementHandler?.();
    this.map.getCanvas().classList.add('routemaps-map-crosshair');

    const handler = (event) => {
      this.map.getCanvas().classList.remove('routemaps-map-crosshair');
      this.map.off('click', handler);
      this.stopPlacementHandler = null;
      this.options.onStopAdd?.([event.lngLat.lng, event.lngLat.lat]);
    };

    this.map.on('click', handler);
    this.stopPlacementHandler = () => {
      this.map.off('click', handler);
      this.map.getCanvas().classList.remove('routemaps-map-crosshair');
    };
  }

  viewport() {
    if (!this.map) {
      return { center: [-8.2, 39.7], zoom: 6 };
    }
    const center = this.map.getCenter();
    return {
      center: [Number(center.lng.toFixed(7)), Number(center.lat.toFixed(7))],
      zoom: Number(this.map.getZoom().toFixed(2)),
    };
  }

  fitToGeometry(geometry = this.geometry) {
    if (!this.map || !geometry) return;
    const coordinates = geometry.type === 'LineString'
      ? geometry.coordinates
      : geometry.coordinates.flat();
    if (!Array.isArray(coordinates) || coordinates.length === 0) return;

    let minLng = Infinity;
    let minLat = Infinity;
    let maxLng = -Infinity;
    let maxLat = -Infinity;
    coordinates.forEach((pair) => {
      if (!Array.isArray(pair) || pair.length < 2) return;
      minLng = Math.min(minLng, Number(pair[0]));
      minLat = Math.min(minLat, Number(pair[1]));
      maxLng = Math.max(maxLng, Number(pair[0]));
      maxLat = Math.max(maxLat, Number(pair[1]));
    });
    if (Number.isFinite(minLng)) {
      this.map.fitBounds([[minLng, minLat], [maxLng, maxLat]], { padding: 70, maxZoom: 14, duration: 500 });
    }
  }

  destroy() {
    this.stopPlacementHandler?.();
    this.stopMarkers.forEach((marker) => marker.remove());
    this.poiMarkers.forEach((marker) => marker.remove());
    this.stopMarkers = [];
    this.poiMarkers = [];
    this.geoman?.destroy?.();
    this.map?.remove();
    this.geoman = null;
    this.map = null;
    this.degraded = false;
    this.loadingGeometry = false;
  }

  handleFeatureChange(featureData) {
    const geometry = featureGeometry(featureData);
    if (geometry) {
      this.setGeometry(geometry, true);
    }
  }

  setGeometry(geometry, notify) {
    this.geometry = geometry;
    this.syncRouteLayer();
    if (notify) {
      this.options.onGeometryChange?.(geometry);
    }
  }

  syncRouteLayer() {
    if (!this.map || !this.map.isStyleLoaded()) {
      if (this.map) {
        this.map.once('style.load', () => this.syncRouteLayer());
      }
      return;
    }

    if (!this.geometry) {
      if (this.map.getLayer(ROUTE_LAYER_ID)) this.map.removeLayer(ROUTE_LAYER_ID);
      if (this.map.getSource(ROUTE_SOURCE_ID)) this.map.removeSource(ROUTE_SOURCE_ID);
      return;
    }

    const data = {
      type: 'Feature',
      properties: {},
      geometry: this.geometry,
    };
    const source = this.map.getSource(ROUTE_SOURCE_ID);
    if (source && typeof source.setData === 'function') {
      source.setData(data);
    } else {
      this.map.addSource(ROUTE_SOURCE_ID, { type: 'geojson', data });
      this.map.addLayer({
        id: ROUTE_LAYER_ID,
        type: 'line',
        source: ROUTE_SOURCE_ID,
        paint: {
          'line-color': this.routeStyle.color,
          'line-width': this.routeStyle.width,
          'line-opacity': 0.8,
        },
      });
    }

    if (this.map.getLayer(ROUTE_LAYER_ID)) {
      this.map.setPaintProperty(ROUTE_LAYER_ID, 'line-color', this.routeStyle.color);
      this.map.setPaintProperty(ROUTE_LAYER_ID, 'line-width', this.routeStyle.width);
    }
  }
}
