import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';
import {
  adminRest,
  createPaidOrder,
  createRouteMapsProduct,
  env,
  importFixtureRoute,
  loginWordPress,
  mailpitMessageIds,
  openLicensedViewer,
  openRouteMapsAdmin,
  requiredEnv,
  resolveWooCustomerId,
  viewerBootstrap,
  waitForMailpitLink,
} from './helpers.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const importFixture = path.resolve(here, '../Fixtures/import/google-my-maps.kml');
const prerequisites = [
  'ROUTEMAPS_E2E_BASE_URL', 'ROUTEMAPS_E2E_ADMIN_USER', 'ROUTEMAPS_E2E_ADMIN_PASSWORD',
  'ROUTEMAPS_E2E_BUYER_USER', 'ROUTEMAPS_E2E_BUYER_PASSWORD', 'ROUTEMAPS_E2E_BUYER_EMAIL',
  'ROUTEMAPS_E2E_WC_KEY', 'ROUTEMAPS_E2E_WC_SECRET', 'ROUTEMAPS_E2E_MAILPIT_URL',
];

const createPublishedFixture = async (page) => {
  await loginWordPress(page, env('ROUTEMAPS_E2E_ADMIN_USER'), env('ROUTEMAPS_E2E_ADMIN_PASSWORD'));
  await openRouteMapsAdmin(page);
  const route = await importFixtureRoute(page, importFixture);

  const category = await adminRest(page, '/admin/categories', {
    method: 'POST',
    body: { name: `E2E Miradouros ${Date.now()}`, icon: 'viewpoint', color: '#00A099', is_active: true },
  });
  const poi = await adminRest(page, '/admin/pois', {
    method: 'POST',
    body: {
      name: 'E2E Miradouro', category_id: category.id, latitude: 41.1579, longitude: -8.6291,
      description: 'POI criado pelo cenário de aceitação RouteMaps.', status: 'active',
    },
  });

  const detail = await adminRest(page, `/admin/routes/${route.id}`);
  const draft = structuredClone(detail.draft?.data || detail.editor_source?.data || {});
  draft.pois = Array.isArray(draft.pois) ? draft.pois : [];
  draft.pois.push({
    entity_uuid: crypto.randomUUID(), source_poi_uuid: poi.uuid, position: draft.pois.length + 1,
    required: false, category_id: category.id, name: poi.name, coordinates: [poi.longitude, poi.latitude], color: poi.color,
  });
  await adminRest(page, `/admin/routes/${route.id}/draft`, { method: 'PUT', body: draft });
  const v1 = await adminRest(page, `/admin/routes/${route.id}/publish`, { method: 'POST', body: { critical: false, summary: 'E2E v1' } });
  return { ...route, v1 };
};

test.describe.serial('RouteMaps purchase, access and version acceptance', () => {
  test.beforeEach(() => {
    const missing = requiredEnv(prerequisites);
    if (missing.length > 0 && process.env.CI) throw new Error(`Missing release E2E environment: ${missing.join(', ')}`);
    test.skip(missing.length > 0, `Missing E2E environment: ${missing.join(', ')}`);
  });

  test('imports/publishes, sells one license, opens email link and preserves opening on refresh/heartbeat', async ({ browser }) => {
    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    const route = await createPublishedFixture(adminPage);

    const product = await createRouteMapsProduct(route.id, { maxOpenings: 2, maxShares: 1 });
    const customerId = await resolveWooCustomerId(env('ROUTEMAPS_E2E_BUYER_EMAIL'));
    const existingMailIds = await mailpitMessageIds(env('ROUTEMAPS_E2E_BUYER_EMAIL'));
    const order = await createPaidOrder(customerId, env('ROUTEMAPS_E2E_BUYER_EMAIL'), product.id);

    await expect.poll(async () => {
      const result = await adminRest(adminPage, `/admin/licenses?order=${order.id}&per_page=10`);
      return result.total;
    }).toBe(1);

    const accessUrl = await waitForMailpitLink(
      env('ROUTEMAPS_E2E_BUYER_EMAIL'),
      '/routemaps/access/',
      20_000,
      { excludeIds: existingMailIds },
    );
    const buyerContext = await browser.newContext();
    const buyerPage = await buyerContext.newPage();
    const first = await openLicensedViewer(buyerPage, accessUrl, env('ROUTEMAPS_E2E_BUYER_USER'), env('ROUTEMAPS_E2E_BUYER_PASSWORD'));
    expect(first.access.allowed).toBe(true);
    expect(first.route.route.uuid).toBe(route.uuid);
    expect(first.route.pois.some((item) => item.display?.name === 'E2E Miradouro')).toBe(true);

    let licenseResult = await adminRest(adminPage, `/admin/licenses?order=${order.id}&per_page=10`);
    expect(licenseResult.items).toHaveLength(1);
    expect(licenseResult.items[0].openings_used).toBe(1);

    await buyerPage.reload({ waitUntil: 'domcontentloaded' });
    await expect(buyerPage.locator('.routemaps-viewer-app')).toBeVisible();
    licenseResult = await adminRest(adminPage, `/admin/licenses?order=${order.id}&per_page=10`);
    expect(licenseResult.items[0].openings_used).toBe(1);

    const bootstrap = await viewerBootstrap(buyerPage);
    const heartbeat = await buyerPage.evaluate(async ({ endpoint, nonce, sessionUuid }) => {
      const response = await fetch(endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify({ session_uuid: sessionUuid }),
      });
      return { status: response.status, data: await response.json().catch(() => null) };
    }, { endpoint: bootstrap.endpoints.heartbeat, nonce: bootstrap.rest_nonce, sessionUuid: first.access.session_uuid });
    expect(heartbeat.status).toBe(200);
    licenseResult = await adminRest(adminPage, `/admin/licenses?order=${order.id}&per_page=10`);
    expect(licenseResult.items[0].openings_used).toBe(1);

    await buyerContext.close();
    await adminContext.close();
  });

  test('keeps v1 reproducible after v2 and resolves the current official version', async ({ browser }) => {
    const adminContext = await browser.newContext();
    const page = await adminContext.newPage();
    const route = await createPublishedFixture(page);
    const v1 = await adminRest(page, `/admin/routes/${route.id}/versions/${route.v1.id}`);

    const product = await createRouteMapsProduct(route.id, { maxOpenings: 2, maxShares: 0 });
    const customerId = await resolveWooCustomerId(env('ROUTEMAPS_E2E_BUYER_EMAIL'));
    const existingMailIds = await mailpitMessageIds(env('ROUTEMAPS_E2E_BUYER_EMAIL'));
    const order = await createPaidOrder(customerId, env('ROUTEMAPS_E2E_BUYER_EMAIL'), product.id);
    await expect.poll(async () => {
      const result = await adminRest(page, `/admin/licenses?order=${order.id}&per_page=10`);
      return result.total;
    }).toBe(1);

    const detail = await adminRest(page, `/admin/routes/${route.id}`);
    const source = structuredClone(detail.editor_source.data);
    const v2Draft = {
      ...source,
      title: `${source.title} v2`,
      style: { ...(source.style || source.route_style || {}), color: '#173F59' },
      map_source_override: source.map_source_override ?? source.map_source_id ?? null,
    };
    await adminRest(page, `/admin/routes/${route.id}/draft`, { method: 'PUT', body: v2Draft });
    const v2 = await adminRest(page, `/admin/routes/${route.id}/publish`, { method: 'POST', body: { critical: false, summary: 'E2E v2' } });

    const versions = await adminRest(page, `/admin/routes/${route.id}/versions`);
    expect(versions.items.map((item) => item.version_number).slice(0, 2)).toEqual([2, 1]);
    const v1Again = await adminRest(page, `/admin/routes/${route.id}/versions/${route.v1.id}`);
    expect(v1Again.data).toEqual(v1.data);
    expect(v2.version_number).toBe(2);
    expect(v1Again.version.content_hash).toBe(route.v1.content_hash);

    const accessUrl = await waitForMailpitLink(
      env('ROUTEMAPS_E2E_BUYER_EMAIL'),
      '/routemaps/access/',
      20_000,
      { excludeIds: existingMailIds },
    );
    const buyerContext = await browser.newContext();
    const buyerPage = await buyerContext.newPage();
    const current = await openLicensedViewer(buyerPage, accessUrl, env('ROUTEMAPS_E2E_BUYER_USER'), env('ROUTEMAPS_E2E_BUYER_PASSWORD'));
    expect(current.route.route.version_id).toBe(v2.id);
    expect(current.route.route.title).toContain('v2');
    await buyerContext.close();

    await adminContext.close();
  });

  test('consumes the second opening when a pre-seeded previous session is expired', async ({ page }) => {
    const expiredSessionUrl = env('ROUTEMAPS_E2E_EXPIRED_SESSION_ACCESS_URL');
    if (!expiredSessionUrl && process.env.CI) throw new Error('ROUTEMAPS_E2E_EXPIRED_SESSION_ACCESS_URL is required by the release gate.');
    test.skip(!expiredSessionUrl, 'Set ROUTEMAPS_E2E_EXPIRED_SESSION_ACCESS_URL to a fixture with openings_used=1, max_openings=2 and no reusable active session.');

    const result = await openLicensedViewer(
      page,
      expiredSessionUrl,
      env('ROUTEMAPS_E2E_BUYER_USER'),
      env('ROUTEMAPS_E2E_BUYER_PASSWORD'),
    );
    expect(result.access.allowed).toBe(true);
    expect(result.access.license.max_openings).toBe(2);
    expect(result.access.license.openings_used).toBe(2);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('.routemaps-viewer-app')).toBeVisible();
    const bootstrap = await viewerBootstrap(page);
    const license = await page.evaluate(async ({ endpoint, nonce, licenseUuid }) => {
      const url = new URL(endpoint, location.origin);
      url.searchParams.set('license_uuid', licenseUuid);
      const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } });
      return response.json();
    }, { endpoint: bootstrap.endpoints.license, nonce: bootstrap.rest_nonce, licenseUuid: result.access.license_uuid });
    expect(license.license.openings_used).toBe(2);
  });

  test('shows limit denial for a pre-seeded exhausted license fixture', async ({ page }) => {
    const exhaustedUrl = env('ROUTEMAPS_E2E_EXHAUSTED_ACCESS_URL');
    if (!exhaustedUrl && process.env.CI) throw new Error('ROUTEMAPS_E2E_EXHAUSTED_ACCESS_URL is required by the release gate.');
    test.skip(!exhaustedUrl, 'Set ROUTEMAPS_E2E_EXHAUSTED_ACCESS_URL to a max-openings fixture with no active session.');
    await page.goto(exhaustedUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('#routemaps-login-user').fill(env('ROUTEMAPS_E2E_BUYER_USER'));
    await page.locator('#routemaps-login-password').fill(env('ROUTEMAPS_E2E_BUYER_PASSWORD'));
    await page.getByRole('button', { name: /entrar/i }).click();
    await expect(page.getByText(/limite atingido/i)).toBeVisible();
  });
});
