import { describe, expect, it, vi } from 'vitest';
import { startGeolocation, coordinatesFromPosition } from '../geolocation.js';

describe('viewer geolocation', () => {
  it('starts watchPosition only when explicitly invoked', () => {
    const watchPosition = vi.fn(() => 17);
    const navigatorObject = { geolocation: { watchPosition } };
    expect(watchPosition).not.toHaveBeenCalled();
    const id = startGeolocation({ navigatorObject, onPosition: () => {}, onError: () => {} });
    expect(id).toBe(17);
    expect(watchPosition).toHaveBeenCalledTimes(1);
  });

  it('normalizes a browser position to [lng, lat]', () => {
    expect(coordinatesFromPosition({ coords: { latitude: 41.1, longitude: -8.6 } })).toEqual([-8.6, 41.1]);
  });
});
