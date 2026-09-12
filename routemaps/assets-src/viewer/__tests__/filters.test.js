import { describe, expect, it } from 'vitest';
import { visiblePois } from '../filters.js';

describe('visiblePois', () => {
  it('filters POIs by category UUID while preserving uncategorized points', () => {
    const pois = [
      { entity_uuid: 'p1', category: { uuid: 'food' } },
      { entity_uuid: 'p2', category: { uuid: 'view' } },
      { entity_uuid: 'p3', category: null },
    ];
    expect(visiblePois(pois, { food: false, view: true }).map((poi) => poi.entity_uuid)).toEqual(['p2', 'p3']);
  });
});
