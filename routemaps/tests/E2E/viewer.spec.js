import { test, expect } from '@playwright/test';

const accessUrl = process.env.ROUTEMAPS_E2E_ACCESS_URL || '';
const username = process.env.ROUTEMAPS_E2E_USER || '';
const password = process.env.ROUTEMAPS_E2E_PASSWORD || '';
const pmtilesAccessUrl = process.env.ROUTEMAPS_E2E_PMTILES_ACCESS_URL || '';
const fallbackAccessUrl = process.env.ROUTEMAPS_E2E_FALLBACK_ACCESS_URL || '';

const loginIfRequired = async (page) => {
  if (!page.url().includes('/routemaps/login')) return;
  if (!username || !password) throw new Error('ROUTEMAPS_E2E_USER and ROUTEMAPS_E2E_PASSWORD are required for the login fixture.');
  await page.locator('#routemaps-login-user').fill(username);
  await page.locator('#routemaps-login-password').fill(password);
  await Promise.all([
    page.waitForURL(/\/routemaps\/(?:access|app)\//),
    page.locator('form').getByRole('button', { name: /entrar/i }).click(),
  ]);
};

const openLicensedViewer = async (page, url) => {
  const payloads = [];
  page.on('response', async (response) => {
    if (!response.url().includes('/wp-json/routemaps/v1/viewer/routes/')) return;
    try { payloads.push(await response.json()); } catch { /* response assertion handles invalid JSON */ }
  });
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await loginIfRequired(page);
  if (page.url().includes('/routemaps/access/')) {
    await page.waitForURL(/\/routemaps\/app\//);
  }
  await expect(page.locator('.routemaps-viewer-app')).toBeVisible();
  await expect(page.locator('.maplibregl-canvas')).toBeVisible();
  await expect.poll(() => payloads.length).toBeGreaterThan(0);
  return payloads.at(-1);
};

test.describe('RouteMaps viewer responsive acceptance', () => {
  test.beforeEach(() => {
    if (!accessUrl && process.env.CI) throw new Error('ROUTEMAPS_E2E_ACCESS_URL is required by the release gate.');
    test.skip(!accessUrl, 'Set ROUTEMAPS_E2E_ACCESS_URL to a licensed fixture access URL.');
  });

  test('renders route, filters and POI detail without horizontal overflow', async ({ page }, testInfo) => {
    const payload = await openLicensedViewer(page, accessUrl);
    expect(payload?.geometry?.type).toBe('LineString');
    expect(Array.isArray(payload?.pois)).toBe(true);
    expect(payload.pois.length).toBeGreaterThan(0);

    const title = page.locator('[data-role="route-title"]');
    await expect(title).not.toHaveText('');

    const filters = page.locator('.routemaps-viewer-filter input[type="checkbox"]');
    expect(await filters.count()).toBeGreaterThan(0);
    const before = await page.locator('.routemaps-viewer-poi-row').count();
    await filters.first().uncheck();
    const after = await page.locator('.routemaps-viewer-poi-row').count();
    expect(after).toBeLessThanOrEqual(before);
    await filters.first().check();

    const poi = page.locator('.routemaps-viewer-poi-row').first();
    await expect(poi).toBeVisible();
    await poi.click();
    await expect(page.locator('[data-role="detail"]')).toBeVisible();
    await expect(page.locator('[data-role="detail"] a', { hasText: /Google Maps|Waze/ }).first()).toBeVisible();

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);

    if (testInfo.project.name === 'mobile') {
      const panel = page.locator('.routemaps-viewer-panel');
      await expect(panel).toBeVisible();
      const layout = await panel.evaluate((node) => ({
        position: getComputedStyle(node).position,
        bottom: getComputedStyle(node).bottom,
      }));
      expect(layout.position).toBe('absolute');
      expect(layout.bottom).not.toBe('auto');
    }
  });
});

test.describe('RouteMaps map-provider acceptance', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Provider acceptance runs once on desktop.');
    if ((!pmtilesAccessUrl || !fallbackAccessUrl) && process.env.CI) {
      throw new Error('ROUTEMAPS_E2E_PMTILES_ACCESS_URL and ROUTEMAPS_E2E_FALLBACK_ACCESS_URL are required by the release gate.');
    }
    test.skip(!pmtilesAccessUrl || !fallbackAccessUrl, 'Set PMTiles and fallback fixture access URLs.');
  });

  test('uses PMTiles when healthy and OpenFreeMap fallback without changing route payload', async ({ browser }) => {
    const pmContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const pmPage = await pmContext.newPage();
    const pmtilesRequests = [];
    pmPage.on('request', (request) => {
      if (request.url().includes('.pmtiles') || request.url().includes('/routemaps/maps/base.pmtiles')) pmtilesRequests.push(request.url());
    });
    const primaryPayload = await openLicensedViewer(pmPage, pmtilesAccessUrl);
    expect(primaryPayload?.map?.provider_id).toBe('pmtiles');
    expect(primaryPayload?.geometry?.type).toBe('LineString');
    await expect.poll(() => pmtilesRequests.length).toBeGreaterThan(0);

    const fallbackContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const fallbackPage = await fallbackContext.newPage();
    const fallbackPayload = await openLicensedViewer(fallbackPage, fallbackAccessUrl);
    expect(fallbackPayload?.map?.provider_id).toBe('openfreemap');
    expect(fallbackPayload?.geometry).toEqual(primaryPayload?.geometry);
    expect(fallbackPayload?.stops).toEqual(primaryPayload?.stops);
    expect(fallbackPayload?.pois).toEqual(primaryPayload?.pois);

    await pmContext.close();
    await fallbackContext.close();
  });
});
