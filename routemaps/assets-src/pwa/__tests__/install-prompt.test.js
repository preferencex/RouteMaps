import { describe, expect, it, vi } from 'vitest';
import {
  DEFAULT_DISMISS_COOLDOWN_MS,
  createInstallPromptManager,
  isIosDevice,
  isStandaloneDisplay,
} from '../install-prompt.js';

const fakeStorage = (initial = {}) => {
  const values = new Map(Object.entries(initial));
  return {
    getItem: vi.fn((key) => values.has(key) ? values.get(key) : null),
    setItem: vi.fn((key, value) => values.set(key, String(value))),
    removeItem: vi.fn((key) => values.delete(key)),
  };
};

const fakeWindow = ({ standalone = false } = {}) => {
  const listeners = new Map();
  return {
    addEventListener: vi.fn((name, listener) => listeners.set(name, listener)),
    matchMedia: vi.fn(() => ({ matches: standalone })),
    dispatchCaptured: (name, event) => listeners.get(name)?.(event),
  };
};

describe('RouteMaps PWA install prompt', () => {
  it('captures Chromium beforeinstallprompt but waits for explicit UI action before prompting', async () => {
    const windowObject = fakeWindow();
    const storage = fakeStorage();
    const manager = createInstallPromptManager({
      windowObject,
      navigatorObject: { userAgent: 'Mozilla/5.0 Chrome/152.0.0.0' },
      storage,
      now: () => 1_000_000,
    });
    const event = {
      preventDefault: vi.fn(),
      prompt: vi.fn(async () => {}),
      userChoice: Promise.resolve({ outcome: 'accepted' }),
    };

    manager.bind();
    windowObject.dispatchCaptured('beforeinstallprompt', event);

    expect(event.preventDefault).toHaveBeenCalledTimes(1);
    expect(event.prompt).not.toHaveBeenCalled();
    expect(manager.mode()).toBe('prompt');

    await manager.prompt();

    expect(event.prompt).toHaveBeenCalledTimes(1);
  });

  it('uses iOS add-to-home-screen instructions when programmable install is unavailable', () => {
    const manager = createInstallPromptManager({
      windowObject: fakeWindow(),
      navigatorObject: {
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
        platform: 'iPhone',
      },
      storage: fakeStorage(),
      now: () => 1_000_000,
    });

    manager.bind();

    expect(isIosDevice({ userAgent: 'iPhone', platform: 'iPhone' })).toBe(true);
    expect(manager.mode()).toBe('ios');
  });

  it('suppresses all installation UI in standalone mode', () => {
    const windowObject = fakeWindow({ standalone: true });
    const navigatorObject = { userAgent: 'Mozilla/5.0 Chrome/152.0.0.0', standalone: true };
    const manager = createInstallPromptManager({
      windowObject,
      navigatorObject,
      storage: fakeStorage(),
      now: () => 1_000_000,
    });

    manager.bind();

    expect(isStandaloneDisplay(windowObject, navigatorObject)).toBe(true);
    expect(manager.mode()).toBe('hidden');
  });

  it('persists dismiss per device and suppresses the prompt during the seven-day cooldown', () => {
    const now = 2_000_000_000;
    const storage = fakeStorage();
    const windowObject = fakeWindow();
    const event = {
      preventDefault: vi.fn(),
      prompt: vi.fn(async () => {}),
      userChoice: Promise.resolve({ outcome: 'dismissed' }),
    };
    const manager = createInstallPromptManager({
      windowObject,
      navigatorObject: { userAgent: 'Mozilla/5.0 Chrome/152.0.0.0' },
      storage,
      now: () => now,
    });

    manager.bind();
    windowObject.dispatchCaptured('beforeinstallprompt', event);
    expect(manager.mode()).toBe('prompt');
    manager.dismiss();
    expect(manager.mode()).toBe('hidden');

    const withinCooldownWindow = fakeWindow();
    const withinCooldown = createInstallPromptManager({
      windowObject: withinCooldownWindow,
      navigatorObject: { userAgent: 'Mozilla/5.0 Chrome/152.0.0.0' },
      storage,
      now: () => now + DEFAULT_DISMISS_COOLDOWN_MS - 1,
    });
    withinCooldown.bind();
    withinCooldownWindow.dispatchCaptured('beforeinstallprompt', event);
    expect(withinCooldown.mode()).toBe('hidden');

    const afterCooldownWindow = fakeWindow();
    const afterCooldown = createInstallPromptManager({
      windowObject: afterCooldownWindow,
      navigatorObject: { userAgent: 'Mozilla/5.0 Chrome/152.0.0.0' },
      storage,
      now: () => now + DEFAULT_DISMISS_COOLDOWN_MS + 1,
    });
    afterCooldown.bind();
    afterCooldownWindow.dispatchCaptured('beforeinstallprompt', event);
    expect(afterCooldown.mode()).toBe('prompt');
  });
});
