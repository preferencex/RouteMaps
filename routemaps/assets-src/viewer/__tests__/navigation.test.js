import { describe, expect, it } from 'vitest';
import { buildGoogleMapsUrl, buildWazeUrl } from '../navigation.js';

describe('external navigation URLs', () => {
  it('builds an HTTPS Google Maps navigation URL with exact coordinates and encoded label', () => {
    const url = buildGoogleMapsUrl(41.14961, -8.61099, 'Sé do Porto / Centro');
    expect(url.startsWith('https://www.google.com/maps/dir/?')).toBe(true);
    expect(url).toContain('destination=41.14961%2C-8.61099');
    expect(url).toContain('dir_action=navigate');
    expect(url).toContain('#S%C3%A9%20do%20Porto%20%2F%20Centro');
  });

  it('builds an HTTPS Waze navigation URL with finite coordinates', () => {
    expect(buildWazeUrl(41.14961, -8.61099)).toBe(
      'https://waze.com/ul?ll=41.14961%2C-8.61099&navigate=yes',
    );
  });

  it('rejects invalid or non-finite coordinates', () => {
    expect(() => buildGoogleMapsUrl(Number.NaN, -8, 'X')).toThrow();
    expect(() => buildWazeUrl(91, -8)).toThrow();
    expect(() => buildWazeUrl(41, -181)).toThrow();
  });

  it('never allows a label to change the HTTPS scheme', () => {
    const url = buildGoogleMapsUrl(41, -8, 'javascript:alert(1)');
    expect(url.startsWith('https://')).toBe(true);
    expect(url).not.toContain('#javascript:alert(1)');
  });
});
