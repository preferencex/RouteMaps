import { describe, expect, it, vi } from 'vitest';
import { fullscreenSupported, requestViewerFullscreen } from '../fullscreen.js';

describe('viewer fullscreen', () => {
  it('reports support only when the browser exposes the fullscreen API', () => {
    expect(fullscreenSupported({ fullscreenEnabled: true, documentElement: { requestFullscreen() {} } })).toBe(true);
    expect(fullscreenSupported({ fullscreenEnabled: false, documentElement: { requestFullscreen() {} } })).toBe(false);
  });

  it('requests fullscreen only when explicitly called', async () => {
    const requestFullscreen = vi.fn(async () => {});
    const documentObject = { fullscreenEnabled: true, documentElement: { requestFullscreen } };
    expect(requestFullscreen).not.toHaveBeenCalled();
    await requestViewerFullscreen(documentObject);
    expect(requestFullscreen).toHaveBeenCalledTimes(1);
  });
});
