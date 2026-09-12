import maplibregl from 'maplibre-gl';
import { Protocol } from 'pmtiles';
import { visiblePois } from './filters.js';
import { __, sprintf } from '../shared/i18n.js';

let pmtilesReady = false;
const ensurePmtilesProtocol = () => {
  if (pmtilesReady) return;
  const protocol = new Protocol();
  maplibregl.addProtocol('pmtiles', protocol.tile);
  pmtilesReady = true;
};

const mapStyle = (mapPayload) => {
  const style = mapPayload?.style;
  if (style?.kind === 'style_url' && typeof style.url === 'string') return style.url;
  if (style && typeof style === 'object' && Number(style.version) === 8) return style;
  return { version: 8, sources: {}, layers: [{ id: 'background', type: 'background', paint: { 'background-color': '#f3f6f8' } }] };
};

const featureCollection = (features = []) => ({ type: 'FeatureCollection', features });
const point = (coordinates, properties) => ({ type: 'Feature', geometry: { type: 'Point', coordinates }, properties });

const stopFeatures = (stops = []) => featureCollection(stops
  .filter((stop) => Array.isArray(stop?.coordinates) && stop.coordinates.length >= 2)
  .map((stop, index) => point(stop.coordinates, {
    entity_uuid: String(stop.entity_uuid || ''),
    name: String(stop.name || sprintf(__('Paragem %s'), index + 1)),
    position: Number(stop.position || index + 1),
  })));

const poiFeatures = (pois = []) => featureCollection(pois
  .filter((poi) => Array.isArray(poi?.coordinates) && poi.coordinates.length >= 2)
  .map((poi) => point(poi.coordinates, {
    entity_uuid: String(poi.entity_uuid || ''),
    name: String(poi.display?.name || ''),
    category_uuid: String(poi.category?.uuid || ''),
    color: String(poi.display?.color || poi.category?.color || '#00A099'),
  })));

const geometryBounds = (geometry) => {
  if (geometry?.type !== 'LineString' || !Array.isArray(geometry.coordinates) || !geometry.coordinates.length) return null;
  const bounds = new maplibregl.LngLatBounds();
  geometry.coordinates.forEach((coordinate) => {
    if (Array.isArray(coordinate) && coordinate.length >= 2) bounds.extend(coordinate);
  });
  return bounds.isEmpty() ? null : bounds;
};

export const createViewerMap = (container, payload, options = {}) => {
  ensurePmtilesProtocol();
  const viewport = payload.viewport || {};
  const map = new maplibregl.Map({
    container,
    style: mapStyle(payload.map),
    center: Array.isArray(viewport.center) ? viewport.center : [-8.2, 39.7],
    zoom: Number.isFinite(Number(viewport.zoom)) ? Number(viewport.zoom) : 6,
    attributionControl: true,
  });
  map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');

  map.on('load', () => {
    map.addSource('routemaps-route', { type: 'geojson', data: { type: 'Feature', geometry: payload.geometry, properties: {} } });
    map.addLayer({
      id: 'routemaps-route-line', type: 'line', source: 'routemaps-route',
      paint: {
        'line-color': payload.route_style?.color || '#00A099',
        'line-width': Number(payload.route_style?.width || 4),
        'line-opacity': 0.95,
      },
    });
    map.addSource('routemaps-stops', { type: 'geojson', data: stopFeatures(payload.stops) });
    map.addLayer({ id: 'routemaps-stop-circles', type: 'circle', source: 'routemaps-stops', paint: { 'circle-radius': 9, 'circle-color': '#00A099', 'circle-stroke-color': '#fff', 'circle-stroke-width': 2 } });
    map.addLayer({ id: 'routemaps-stop-labels', type: 'symbol', source: 'routemaps-stops', layout: { 'text-field': ['to-string', ['get', 'position']], 'text-size': 10 }, paint: { 'text-color': '#fff' } });
    map.addSource('routemaps-pois', { type: 'geojson', data: poiFeatures(payload.pois) });
    map.addLayer({ id: 'routemaps-poi-circles', type: 'circle', source: 'routemaps-pois', paint: { 'circle-radius': 7, 'circle-color': ['coalesce', ['get', 'color'], '#d6a900'], 'circle-stroke-color': '#fff', 'circle-stroke-width': 2 } });
    map.addSource('routemaps-user-location', { type: 'geojson', data: featureCollection([]) });
    map.addLayer({ id: 'routemaps-user-location', type: 'circle', source: 'routemaps-user-location', paint: { 'circle-radius': 8, 'circle-color': '#173f59', 'circle-stroke-color': '#fff', 'circle-stroke-width': 3 } });

    map.on('click', 'routemaps-poi-circles', (event) => {
      const uuid = event.features?.[0]?.properties?.entity_uuid;
      if (uuid && typeof options.onPoiSelect === 'function') options.onPoiSelect(uuid);
    });
    map.on('mouseenter', 'routemaps-poi-circles', () => { map.getCanvas().style.cursor = 'pointer'; });
    map.on('mouseleave', 'routemaps-poi-circles', () => { map.getCanvas().style.cursor = ''; });

    const bounds = geometryBounds(payload.geometry);
    if (bounds) map.fitBounds(bounds, { padding: 60, maxZoom: 14, duration: 0 });
  });
  return map;
};

export const updatePoiVisibility = (map, pois, categoryVisibility) => {
  const source = map?.getSource?.('routemaps-pois');
  if (source?.setData) source.setData(poiFeatures(visiblePois(pois, categoryVisibility)));
};

export const updateUserLocation = (map, coordinates) => {
  if (!Array.isArray(coordinates) || coordinates.length < 2) return false;
  const lng = Number(coordinates[0]);
  const lat = Number(coordinates[1]);
  if (!Number.isFinite(lng) || !Number.isFinite(lat)) return false;
  const data = featureCollection([point([lng, lat], { kind: 'user' })]);
  const apply = () => {
    const source = map?.getSource?.('routemaps-user-location');
    if (!source?.setData) return false;
    source.setData(data);
    return true;
  };
  if (apply()) return true;
  if (typeof map?.once === 'function') map.once('load', apply);
  return false;
};
