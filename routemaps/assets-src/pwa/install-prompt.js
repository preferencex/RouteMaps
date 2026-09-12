export const DEFAULT_DISMISS_COOLDOWN_MS = 7 * 24 * 60 * 60 * 1000;
const DISMISS_KEY = 'routemaps:pwa-install-dismissed-at';

export const isIosDevice = (navigatorObject = globalThis.navigator) => {
  const userAgent = String(navigatorObject?.userAgent || '');
  const platform = String(navigatorObject?.platform || '');
  const maxTouchPoints = Number(navigatorObject?.maxTouchPoints || 0);
  return /iPad|iPhone|iPod/i.test(userAgent)
    || /iPad|iPhone|iPod/i.test(platform)
    || (platform === 'MacIntel' && maxTouchPoints > 1);
};

export const isStandaloneDisplay = (windowObject = globalThis.window, navigatorObject = globalThis.navigator) => {
  const mediaStandalone = Boolean(windowObject?.matchMedia?.('(display-mode: standalone)')?.matches);
  return mediaStandalone || navigatorObject?.standalone === true;
};

export const createInstallPromptManager = ({
  windowObject = globalThis.window,
  navigatorObject = globalThis.navigator,
  storage = globalThis.localStorage,
  now = () => Date.now(),
  cooldownMs = DEFAULT_DISMISS_COOLDOWN_MS,
} = {}) => {
  let deferredPrompt = null;
  let currentMode = 'hidden';
  const listeners = new Set();

  const isCoolingDown = () => {
    try {
      const raw = storage?.getItem?.(DISMISS_KEY);
      if (!raw) return false;
      const dismissedAt = Number(raw);
      return Number.isFinite(dismissedAt) && now() - dismissedAt < cooldownMs;
    } catch {
      return false;
    }
  };

  const emit = (mode) => {
    if (mode === currentMode) return;
    currentMode = mode;
    listeners.forEach((listener) => listener(mode));
  };

  const refreshMode = () => {
    if (isStandaloneDisplay(windowObject, navigatorObject) || isCoolingDown()) {
      emit('hidden');
      return currentMode;
    }
    if (deferredPrompt) {
      emit('prompt');
      return currentMode;
    }
    if (isIosDevice(navigatorObject)) {
      emit('ios');
      return currentMode;
    }
    emit('hidden');
    return currentMode;
  };

  return {
    bind() {
      windowObject?.addEventListener?.('beforeinstallprompt', (event) => {
        event?.preventDefault?.();
        deferredPrompt = event;
        refreshMode();
      });
      windowObject?.addEventListener?.('appinstalled', () => {
        deferredPrompt = null;
        emit('hidden');
      });
      refreshMode();
    },
    mode() {
      return currentMode;
    },
    onChange(listener) {
      if (typeof listener !== 'function') return () => {};
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    async prompt() {
      if (!deferredPrompt || 'prompt' !== currentMode) return false;
      const event = deferredPrompt;
      deferredPrompt = null;
      await event.prompt();
      const choice = await event.userChoice;
      if (choice?.outcome === 'dismissed') {
        this.dismiss();
        return false;
      }
      emit('hidden');
      return choice?.outcome === 'accepted';
    },
    dismiss() {
      try {
        storage?.setItem?.(DISMISS_KEY, String(now()));
      } catch {
        // Storage can be unavailable in private/restricted browsing.
      }
      deferredPrompt = null;
      emit('hidden');
    },
  };
};

export const registerRouteMapsServiceWorker = async ({
  navigatorObject = globalThis.navigator,
  serviceWorkerUrl = '/routemaps/service-worker.js',
  scope = '/routemaps/',
} = {}) => {
  if (!navigatorObject?.serviceWorker?.register) return null;
  return navigatorObject.serviceWorker.register(serviceWorkerUrl, { scope });
};
