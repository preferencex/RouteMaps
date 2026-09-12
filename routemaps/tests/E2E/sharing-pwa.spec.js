import { test, expect } from '@playwright/test';
import {
  env,
  mailpitMessageIds,
  openLicensedViewer,
  requiredEnv,
  viewerBootstrap,
  waitForMailpitLink,
} from './helpers.js';

const prerequisites = [
  'ROUTEMAPS_E2E_SHARE_ACCESS_URL', 'ROUTEMAPS_E2E_OWNER_USER', 'ROUTEMAPS_E2E_OWNER_PASSWORD',
  'ROUTEMAPS_E2E_GUEST_USER', 'ROUTEMAPS_E2E_GUEST_PASSWORD', 'ROUTEMAPS_E2E_GUEST_EMAIL',
  'ROUTEMAPS_E2E_MAILPIT_URL',
];

const viewerRest = async (page, url, nonce, method, body) => page.evaluate(async ({ url, nonce, method, body }) => {
  const response = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  return { status: response.status, data: await response.json().catch(() => null) };
}, { url, nonce, method, body });

const privateCacheEntries = async (page) => page.evaluate(async () => {
  if (!globalThis.caches) return [];
  const names = await caches.keys();
  const urls = [];
  for (const name of names) {
    const cache = await caches.open(name);
    const requests = await cache.keys();
    requests.forEach((request) => urls.push(request.url));
  }
  return urls.filter((url) => /\/wp-json\/routemaps\/v1\/|\/routemaps\/(?:access|login|invite)\//.test(url));
});

test.describe.serial('RouteMaps sharing and PWA acceptance', () => {
  test.beforeEach(() => {
    const missing = requiredEnv(prerequisites);
    if (missing.length > 0 && process.env.CI) throw new Error(`Missing release E2E environment: ${missing.join(', ')}`);
    test.skip(missing.length > 0, `Missing E2E environment: ${missing.join(', ')}`);
  });

  test('owner invites guest, guest accepts, owner revokes and guest is denied', async ({ browser }) => {
    const ownerContext = await browser.newContext();
    const ownerPage = await ownerContext.newPage();
    const owner = await openLicensedViewer(ownerPage, env('ROUTEMAPS_E2E_SHARE_ACCESS_URL'), env('ROUTEMAPS_E2E_OWNER_USER'), env('ROUTEMAPS_E2E_OWNER_PASSWORD'));
    const bootstrap = await viewerBootstrap(ownerPage);

    const existingMailIds = await mailpitMessageIds(env('ROUTEMAPS_E2E_GUEST_EMAIL'));
    const invite = await viewerRest(ownerPage, bootstrap.endpoints.shares, bootstrap.rest_nonce, 'POST', {
      license_uuid: owner.access.license_uuid,
      email: env('ROUTEMAPS_E2E_GUEST_EMAIL'),
    });
    expect(invite.status).toBe(201);
    expect(invite.data.status).toBe('pending');

    const inviteUrl = await waitForMailpitLink(
      env('ROUTEMAPS_E2E_GUEST_EMAIL'),
      '/routemaps/invite/',
      20_000,
      { excludeIds: existingMailIds },
    );
    const guestContext = await browser.newContext();
    const guestPage = await guestContext.newPage();
    const guest = await openLicensedViewer(guestPage, inviteUrl, env('ROUTEMAPS_E2E_GUEST_USER'), env('ROUTEMAPS_E2E_GUEST_PASSWORD'));
    expect(guest.access.allowed).toBe(true);

    const revoke = await viewerRest(ownerPage, `${bootstrap.endpoints.shares}/${invite.data.id}`, bootstrap.rest_nonce, 'DELETE');
    expect(revoke.status).toBe(204);

    await guestPage.reload({ waitUntil: 'domcontentloaded' });
    await expect(guestPage.getByText(/acesso indisponível|acesso revogado/i)).toBeVisible();

    await guestContext.close();
    await ownerContext.close();
  });

  test('Chromium install prompt is user-driven and protected payload never enters Cache Storage', async ({ page }) => {
    await openLicensedViewer(page, env('ROUTEMAPS_E2E_SHARE_ACCESS_URL'), env('ROUTEMAPS_E2E_OWNER_USER'), env('ROUTEMAPS_E2E_OWNER_PASSWORD'));
    const swSupported = await page.evaluate(() => Boolean(navigator.serviceWorker && globalThis.caches));
    if (!swSupported && process.env.CI) throw new Error('Service Worker/Cache Storage is required by the release gate.');
    test.skip(!swSupported, 'Service Worker/Cache Storage unavailable on this origin. Use HTTPS or localhost.');

    await page.evaluate(() => {
      const event = new Event('beforeinstallprompt', { cancelable: true });
      Object.defineProperty(event, 'prompt', { value: async () => {} });
      Object.defineProperty(event, 'userChoice', { value: Promise.resolve({ outcome: 'accepted', platform: 'web' }) });
      window.dispatchEvent(event);
    });
    const install = page.locator('[data-role="pwa-install"]');
    await expect(install).toBeVisible();
    await expect(install.getByRole('button', { name: /adicionar atalho/i })).toBeVisible();
    await install.getByRole('button', { name: /adicionar atalho/i }).click();

    await expect.poll(() => privateCacheEntries(page)).toEqual([]);
  });

  test('iOS branch displays Add to Home Screen guidance', async ({ browser }) => {
    const context = await browser.newContext({
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1',
      viewport: { width: 390, height: 844 },
      isMobile: true,
      hasTouch: true,
    });
    await context.addInitScript(() => {
      Object.defineProperty(navigator, 'platform', { configurable: true, get: () => 'iPhone' });
      Object.defineProperty(navigator, 'maxTouchPoints', { configurable: true, get: () => 5 });
    });
    const page = await context.newPage();
    await openLicensedViewer(page, env('ROUTEMAPS_E2E_SHARE_ACCESS_URL'), env('ROUTEMAPS_E2E_OWNER_USER'), env('ROUTEMAPS_E2E_OWNER_PASSWORD'));
    await expect(page.getByText(/Safari.*Partilhar.*Adicionar ao ecrã principal/i)).toBeVisible();
    await context.close();
  });
});
