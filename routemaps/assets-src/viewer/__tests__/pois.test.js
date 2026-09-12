import { describe, expect, it } from 'vitest';
import { poiDetailModel } from '../pois.js';

describe('poiDetailModel', () => {
  it('normalizes category, required marker and safe CTA for the detail panel', () => {
    const model = poiDetailModel({
      required: true,
      category: { name: 'Miradouros', icon: 'viewpoint', color: '#00A099' },
      display: {
        name: 'São Leonardo',
        cta: { label: 'Reservar', url: 'https://example.test/reservar' },
      },
    });

    expect(model.requiredLabel).toBe('Paragem obrigatória');
    expect(model.category).toEqual({ name: 'Miradouros', icon: 'viewpoint', color: '#00A099' });
    expect(model.cta).toEqual({ label: 'Reservar', url: 'https://example.test/reservar' });
  });

  it('rejects unsafe CTA URLs and labels optional points', () => {
    const model = poiDetailModel({
      required: false,
      display: { cta: { label: 'Abrir', url: 'javascript:alert(1)' } },
    });

    expect(model.requiredLabel).toBe('Paragem opcional');
    expect(model.cta).toBeNull();
  });
});
