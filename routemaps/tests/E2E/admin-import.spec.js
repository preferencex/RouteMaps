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

  await expect.poll(() => page.evaluate(() => globalThis.RouteMapsAdminApp?.draft?.stops?.length || 0)).toBeGreaterThan(0);
  await expect.poll(() => page.evaluate(() => globalThis.RouteMapsAdminApp?.draft?.geometry?.type || '')).toMatch(/LineString/);
  await expect(page.locator('[data-role="stop-count"]')).not.toHaveText('0');
  await expect(page.locator('[data-role="status"]')).toContainText(/Rota carregada/i);
});
