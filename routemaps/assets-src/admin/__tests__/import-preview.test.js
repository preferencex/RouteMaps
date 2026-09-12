import { describe, expect, it } from 'vitest';
import {
  CREATE_CATEGORY_VALUE,
  defaultCategorySelection,
  humanImportWarnings,
  importSummary,
} from '../import-preview.js';

describe('importSummary', () => {
  it('summarizes route lines, points, categories, polygons and warnings', () => {
    expect(importSummary({
      geometry: { type: 'MultiLineString', coordinates: [[[1, 2], [3, 4]], [[5, 6], [7, 8]]] },
      points: [{}, {}, {}],
      categories: [{}, {}],
      warnings: ['unsupported_kml_style:LabelStyle'],
      metadata: { polygons: [{}, {}] },
    })).toEqual({
      routeLines: 2,
      points: 3,
      categories: 2,
      polygons: 2,
      warnings: 1,
    });
  });
});

describe('defaultCategorySelection', () => {
  it('reuses an existing category with the same name ignoring case', () => {
    expect(defaultCategorySelection('Miradouros', [{ id: 12, name: 'miradouros' }], true)).toBe('12');
  });

  it('defaults to create category when no match exists and creation is allowed', () => {
    expect(defaultCategorySelection('Gastro Tips', [], true)).toBe(CREATE_CATEGORY_VALUE);
  });

  it('leaves unmatched categories unmapped when creation is not allowed', () => {
    expect(defaultCategorySelection('Gastro Tips', [], false)).toBe('');
  });
});

describe('humanImportWarnings', () => {
  it('groups unsupported KML styles into a readable warning', () => {
    expect(humanImportWarnings([
      'unsupported_kml_style:LabelStyle',
      'unsupported_kml_style:BalloonStyle',
    ])).toEqual([
      'Estilos visuais KML ignorados: LabelStyle, BalloonStyle. O percurso e os pontos não são afetados.',
    ]);
  });
});
