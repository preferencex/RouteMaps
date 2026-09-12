import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';
import {
  env,
  importFixtureRoute,
  loginWordPress,
  openRouteMapsAdmin,
  requiredEnv,
} from './helpers.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const importFixture = path.resolve(here, '../Fixtures/import/google-my-maps.kml');
const prerequisites = [
  'ROUTEMAPS_E2E_ADMIN_USER',
  'ROUTEMAPS_E2E_ADMIN_PASSWORD',
];

test('keeps imported route data visible when the external basemap cannot load', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Basemap degradation only needs one browser viewport.');

  const missing = requiredEnv(prerequisites);
  if (missing.length > 0 && process.env.CI) throw new Error(`Missing release E2E environment: ${missing.join(', ')}`);
  test.skip(missing.length > 0, `Missing E2E environment: ${missing.join(', ')}`);

  await page.route('https://tiles.openfreemap.org/**', (route) => route.abort());

  await loginWordPress(page, env('ROUTEMAPS_E2E_ADMIN_USER'), env('ROUTEMAPS_E2E_ADMIN_PASSWORD'));
  await openRouteMapsAdmin(page);
  await importFixtureRoute(page, importFixture);

  await expect(page.locator('[data-role="empty-state"]')).toBeHidden();
  await expect(page.locator('[data-role="editor"]')).toBeVisible();
  await expect.poll(() => page.evaluate(() => globalThis.RouteMapsAdminApp?.draft?.stops?.length || 0)).toBeGreaterThan(0);
  await expect.poll(() => page.evaluate(() => globalThis.RouteMapsAdminApp?.draft?.geometry?.type || '')).toMatch(/LineString/);
  await expect(page.locator('[data-role="stop-count"]')).not.toHaveText('0');
  await expect(page.locator('.routemaps-stop-marker').first()).toBeVisible();
  await expect.poll(
    () => page.evaluate(() => Boolean(globalThis.RouteMapsAdminApp?.mapEditor?.isDegraded?.())),
    { timeout: 25_000 },
  ).toBe(true);
  await expect.poll(() => page.evaluate(() => Boolean(
    globalThis.RouteMapsAdminApp?.mapEditor?.map?.getSource?.('routemaps-route-preview'),
  )), {
    timeout: 10_000,
  }).toBe(true);
});


test('keeps editor zoom, fit and route deletion controls functional', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Admin editor controls only need one browser viewport.');

  const missing = requiredEnv(prerequisites);
  if (missing.length > 0 && process.env.CI) throw new Error(`Missing release E2E environment: ${missing.join(', ')}`);
  test.skip(missing.length > 0, `Missing E2E environment: ${missing.join(', ')}`);

  await loginWordPress(page, env('ROUTEMAPS_E2E_ADMIN_USER'), env('ROUTEMAPS_E2E_ADMIN_PASSWORD'));
  await openRouteMapsAdmin(page);
  const imported = await importFixtureRoute(page, importFixture);

  await expect.poll(() => page.evaluate(() => globalThis.RouteMapsAdminApp?.mapEditor?.map?.getMaxZoom?.() || 0)).toBe(18);

  await page.evaluate(() => {
    globalThis.RouteMapsAdminApp.mapEditor.map.jumpTo({ center: [0, 0], zoom: 18 });
    globalThis.RouteMapsAdminApp.mapEditor.map.setZoom(22);
  });
  await expect.poll(() => page.evaluate(() => globalThis.RouteMapsAdminApp.mapEditor.map.getZoom())).toBeLessThanOrEqual(18);

  await page.locator('[data-action="fit-route"]').click();
  await expect(page.locator('[data-role="status"]')).toContainText('Percurso enquadrado no mapa.');
  await expect.poll(() => page.evaluate(() => {
    const geometry = globalThis.RouteMapsAdminApp?.draft?.geometry;
    const map = globalThis.RouteMapsAdminApp?.mapEditor?.map;
    if (!geometry || !map) return false;

    const coordinates = geometry.type === 'LineString'
      ? geometry.coordinates
      : geometry.coordinates.flat();
    if (!Array.isArray(coordinates) || !coordinates.length) return false;

    const lngs = coordinates.map((pair) => Number(pair?.[0])).filter(Number.isFinite);
    const lats = coordinates.map((pair) => Number(pair?.[1])).filter(Number.isFinite);
    if (!lngs.length || !lats.length) return false;

    const center = map.getCenter();
    return center.lng >= Math.min(...lngs) && center.lng <= Math.max(...lngs)
      && center.lat >= Math.min(...lats) && center.lat <= Math.max(...lats);
  })).toBe(true);

  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('.routemaps-route-row.is-active .routemaps-route-delete').click();

  await expect.poll(() => page.evaluate((routeId) => (
    globalThis.RouteMapsAdminApp?.routes?.some((route) => Number(route.id) === Number(routeId))
  ), imported.id)).toBe(false);
  await expect(page.locator('[data-role="editor"]')).toBeHidden();
  await expect(page.locator('[data-role="empty-state"]')).toBeVisible();
});


test('toggles route draw and edit modes with explicit toolbar guidance', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Admin map modes only need one browser viewport.');

  const workerResponses = [];
  page.on('response', (response) => {
    if (response.url().includes('maplibre-gl-worker-')) {
      workerResponses.push({ url: response.url(), status: response.status() });
    }
  });

  const missing = requiredEnv(prerequisites);
  if (missing.length > 0 && process.env.CI) throw new Error(`Missing release E2E environment: ${missing.join(', ')}`);
  test.skip(missing.length > 0, `Missing E2E environment: ${missing.join(', ')}`);

  await loginWordPress(page, env('ROUTEMAPS_E2E_ADMIN_USER'), env('ROUTEMAPS_E2E_ADMIN_PASSWORD'));
  await openRouteMapsAdmin(page);
  await importFixtureRoute(page, importFixture);

  await expect.poll(() => workerResponses.some((response) => (
    response.status === 200
    && response.url.includes('/wp-content/plugins/routemaps/assets/admin/assets/maplibre-gl-worker-')
  )), { timeout: 15_000 }).toBe(true);

  await expect.poll(() => page.evaluate(() => Boolean(globalThis.RouteMapsAdminApp?.mapEditor?.geoman))).toBe(true);

  await page.locator('[data-action="edit-route"]').click();
  await expect.poll(() => page.evaluate(() => globalThis.RouteMapsAdminApp?.mapEditor?.isEditingRoute?.() || false)).toBe(true);
  await expect(page.locator('[data-action="edit-route"]')).toHaveText('Terminar edição');
  await expect(page.locator('[data-role="map-mode-hint"]')).toContainText('Arraste os vértices');

  await page.locator('[data-action="edit-route"]').click();
  await expect.poll(() => page.evaluate(() => globalThis.RouteMapsAdminApp?.mapEditor?.isEditingRoute?.() || false)).toBe(false);
  await expect(page.locator('[data-action="edit-route"]')).toHaveText('Editar percurso');

  await page.locator('[data-action="draw-route"]').click();
  await expect.poll(() => page.evaluate(() => globalThis.RouteMapsAdminApp?.mapEditor?.isDrawingRoute?.() || false)).toBe(true);
  await expect(page.locator('[data-action="draw-route"]')).toHaveText('Cancelar desenho');
  await expect(page.locator('[data-role="map-mode-hint"]')).toContainText('duplo clique');

  await page.locator('[data-action="draw-route"]').click();
  await expect.poll(() => page.evaluate(() => globalThis.RouteMapsAdminApp?.mapEditor?.isDrawingRoute?.() || false)).toBe(false);
  await expect(page.locator('[data-action="draw-route"]')).toHaveText('Desenhar percurso');
  await expect(page.locator('[data-role="map-mode-hint"]')).toBeHidden();
});
