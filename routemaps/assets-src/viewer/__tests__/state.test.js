import { describe, expect, it } from 'vitest';
import {
  createViewerState,
  selectPoi,
  toggleCategory,
  orderedStops,
  mapAccessDenied,
  chooseProvider,
} from '../state.js';

describe('viewer state', () => {
  it('toggles categories by stable UUID without mutating previous state', () => {
    const initial = createViewerState({ categories: [{ uuid: 'cat-a' }, { uuid: 'cat-b' }] });
    const next = toggleCategory(initial, 'cat-a');
    expect(next.categoryVisibility['cat-a']).toBe(false);
    expect(initial.categoryVisibility['cat-a']).toBe(true);
  });

  it('selects a POI and returns stops ordered by position', () => {
    const initial = createViewerState({ stops: [{ entity_uuid: 'b', position: 2 }, { entity_uuid: 'a', position: 1 }] });
    expect(selectPoi(initial, 'poi-1').selectedPoiUuid).toBe('poi-1');
    expect(orderedStops(initial).map((stop) => stop.entity_uuid)).toEqual(['a', 'b']);
  });

  it('maps access denial to stable UI states', () => {
    expect(mapAccessDenied('license_expired').kind).toBe('expired');
    expect(mapAccessDenied('openings_exhausted').kind).toBe('exhausted');
    expect(mapAccessDenied('not_authenticated').kind).toBe('login');
  });

  it('selects healthy primary provider and falls back otherwise', () => {
    expect(chooseProvider({ primary: { id: 'pmtiles', ok: true }, fallback: { id: 'openfreemap', ok: true } })).toBe('pmtiles');
    expect(chooseProvider({ primary: { id: 'pmtiles', ok: false }, fallback: { id: 'openfreemap', ok: true } })).toBe('openfreemap');
    expect(chooseProvider({ primary: { id: 'pmtiles', ok: false }, fallback: { id: 'openfreemap', ok: false } })).toBe(null);
  });
});
