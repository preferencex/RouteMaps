import { describe, expect, it } from 'vitest';
import {
  lineCoordinatesToGeoJson,
  mapPoiCategoryVisibility,
  reorderStopUuids,
} from '../state.js';

describe('reorderStopUuids', () => {
  it('returns a reordered copy without mutating the original list', () => {
    const source = ['stop-a', 'stop-b', 'stop-c', 'stop-d'];

    const result = reorderStopUuids(source, 3, 1);

    expect(result).toEqual(['stop-a', 'stop-d', 'stop-b', 'stop-c']);
    expect(source).toEqual(['stop-a', 'stop-b', 'stop-c', 'stop-d']);
    expect(result).not.toBe(source);
  });

  it('rejects indexes outside the list', () => {
    expect(() => reorderStopUuids(['stop-a'], 1, 0)).toThrow('stop_index_out_of_range');
    expect(() => reorderStopUuids(['stop-a'], 0, -1)).toThrow('stop_index_out_of_range');
  });
});

describe('mapPoiCategoryVisibility', () => {
  it('maps category toggles to POI visibility without mutating POIs', () => {
    const pois = [
      { uuid: 'poi-1', category_id: 10, visible: true },
      { uuid: 'poi-2', category_id: 20, visible: true },
      { uuid: 'poi-3', category_id: null, visible: true },
    ];

    const result = mapPoiCategoryVisibility(pois, { 10: false, 20: true });

    expect(result.map((poi) => poi.visible)).toEqual([false, true, true]);
    expect(pois.map((poi) => poi.visible)).toEqual([true, true, true]);
  });
});

describe('lineCoordinatesToGeoJson', () => {
  it('creates a LineString with normalized numeric coordinates', () => {
    expect(lineCoordinatesToGeoJson([
      ['-8.611', '41.149'],
      [-7.791, 41.162],
    ])).toEqual({
      type: 'LineString',
      coordinates: [
        [-8.611, 41.149],
        [-7.791, 41.162],
      ],
    });
  });

  it('requires at least two valid coordinate pairs', () => {
    expect(() => lineCoordinatesToGeoJson([[-8.611, 41.149]])).toThrow('route_line_requires_two_points');
    expect(() => lineCoordinatesToGeoJson([[-181, 41.149], [-7.791, 41.162]])).toThrow('invalid_route_coordinate');
  });
});
