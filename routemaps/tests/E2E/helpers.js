import { expect, request as playwrightRequest } from '@playwright/test';
import { createWooRequestAuth } from './woo-oauth.js';

export const env = (name, fallback = '') => process.env[name] || fallback;
export const requiredEnv = (names) => names.filter((name) => !env(name));

export const loginWordPress = async (page, username, password) => {
  await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(username);
  await page.locator('#user_pass').fill(password);
  await Promise.all([
    page.waitForURL(/\/wp-admin\//),
    page.locator('#wp-submit').click(),
  ]);
};

export const adminRest = async (page, path, { method = 'GET', body } = {}) => page.evaluate(async ({ path, method, body }) => {
  const config = globalThis.RouteMapsAdmin;
  if (!config?.restBase || !config?.nonce) throw new Error('routemaps_admin_bootstrap_missing');
  const response = await fetch(`${String(config.restBase).replace(/\/$/, '')}/${String(path).replace(/^\//, '')}`, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-WP-Nonce': config.nonce,
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok) throw new Error(payload?.message || `admin_rest_${response.status}`);
  return payload;
}, { path, method, body });

export const openRouteMapsAdmin = async (page) => {
  await page.goto('/wp-admin/admin.php?page=routemaps-routes', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#routemaps-admin-root')).toBeVisible();
  await expect.poll(() => page.evaluate(() => Boolean(globalThis.RouteMapsAdmin))).toBe(true);
};

export const importFixtureRoute = async (page, fixturePath) => {
  await page.locator('[data-action="import"]').click();
  const dialog = page.locator('[data-role="import-dialog"]');
  await expect(dialog).toBeVisible();
  await dialog.locator('[data-field="import-file"]').setInputFiles(fixturePath);
  await dialog.locator('[data-action="inspect-import"]').click();
  await expect(dialog.locator('[data-role="import-preview"]')).toBeVisible();
  await dialog.locator('[data-action="commit-import"]').click();
  await expect.poll(() => page.evaluate(() => globalThis.RouteMapsAdminApp?.currentRoute?.id || 0)).toBeGreaterThan(0);
  return page.evaluate(() => ({
    id: globalThis.RouteMapsAdminApp.currentRoute.id,
    uuid: globalThis.RouteMapsAdminApp.currentRoute.uuid,
  }));
};

export const viewerBootstrap = async (page) => page.evaluate(() => {
  const node = document.getElementById('routemaps-viewer-bootstrap');
  return node ? JSON.parse(node.textContent || '{}') : null;
});

export const loginRouteMapsIfRequired = async (page, username, password) => {
  if (!page.url().includes('/routemaps/login')) return;
  await page.locator('#routemaps-login-user').fill(username);
  await page.locator('#routemaps-login-password').fill(password);
  await Promise.all([
    page.waitForURL(/\/routemaps\/(?:access|app)\//),
    page.getByRole('button', { name: /entrar/i }).click(),
  ]);
};

export const openLicensedViewer = async (page, accessUrl, username, password) => {
  const routePayloads = [];
  const accessPayloads = [];
  page.on('response', async (response) => {
    try {
      if (response.url().includes('/wp-json/routemaps/v1/access/resolve')) accessPayloads.push(await response.json());
      if (response.url().includes('/wp-json/routemaps/v1/viewer/routes/')) routePayloads.push(await response.json());
    } catch {
      // Assertions below report malformed responses.
    }
  });
  await page.goto(accessUrl, { waitUntil: 'domcontentloaded' });
  await loginRouteMapsIfRequired(page, username, password);
  if (page.url().includes('/routemaps/access/')) await page.waitForURL(/\/routemaps\/app\//);
  await expect(page.locator('.routemaps-viewer-app')).toBeVisible();
  await expect.poll(() => accessPayloads.length).toBeGreaterThan(0);
  await expect.poll(() => routePayloads.length).toBeGreaterThan(0);
  return { access: accessPayloads.at(-1), route: routePayloads.at(-1) };
};

const wcPath = (path) => `/wp-json/wc/v3/${String(path).replace(/^\//, '')}`;

export const wooRequest = async (method, path, data) => {
  const auth = createWooRequestAuth(
    method,
    env('ROUTEMAPS_E2E_BASE_URL'),
    wcPath(path).replace(/^\/wp-json\/wc\/v3\//, ''),
    env('ROUTEMAPS_E2E_WC_KEY'),
    env('ROUTEMAPS_E2E_WC_SECRET'),
  );
  const context = await playwrightRequest.newContext({
    baseURL: env('ROUTEMAPS_E2E_BASE_URL'),
    httpCredentials: auth.httpCredentials,
  });
  try {
    const response = await context.fetch(auth.url, {
      method,
      data,
      headers: { Accept: 'application/json' },
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok()) throw new Error(payload?.message || `woocommerce_${response.status()}`);
    return payload;
  } finally {
    await context.dispose();
  }
};

export const resolveWooCustomerId = async (email) => {
  const customers = await wooRequest('GET', `customers?email=${encodeURIComponent(email)}&per_page=1`);
  if (!Array.isArray(customers) || !customers[0]?.id) throw new Error(`woocommerce_customer_not_found:${email}`);
  return Number(customers[0].id);
};

export const createRouteMapsProduct = async (routeId, { maxOpenings = 2, maxShares = 1 } = {}) => wooRequest('POST', 'products', {
  name: `RouteMaps E2E ${Date.now()}`,
  type: 'simple',
  status: 'publish',
  virtual: true,
  regular_price: '1.00',
  meta_data: [
    { key: '_routemaps_enabled', value: 'yes' },
    { key: '_routemaps_route_id', value: routeId },
    { key: '_routemaps_validity_mode', value: 'unlimited' },
    { key: '_routemaps_max_openings', value: maxOpenings },
    { key: '_routemaps_sharing_enabled', value: maxShares > 0 ? 'yes' : 'no' },
    { key: '_routemaps_max_shares', value: maxShares },
  ],
});

export const createPaidOrder = async (customerId, email, productId) => wooRequest('POST', 'orders', {
  customer_id: customerId,
  set_paid: true,
  status: 'processing',
  billing: { first_name: 'RouteMaps', last_name: 'E2E', email },
  line_items: [{ product_id: productId, quantity: 1 }],
});

const decodeHtmlEntities = (value) => String(value || '').replaceAll('&amp;', '&');

const mailpitMessages = async (context, recipient) => {
  const mailpit = env('ROUTEMAPS_E2E_MAILPIT_URL').replace(/\/$/, '');
  if (!mailpit) throw new Error('ROUTEMAPS_E2E_MAILPIT_URL is required');
  const search = await context.get(`${mailpit}/api/v1/search?query=${encodeURIComponent(`to:${recipient}`)}`);
  if (!search.ok()) return [];
  const data = await search.json();
  return data.messages || data.Messages || [];
};

export const mailpitMessageIds = async (recipient) => {
  const context = await playwrightRequest.newContext();
  try {
    const messages = await mailpitMessages(context, recipient);
    return messages
      .map((message) => message.ID || message.Id || message.id)
      .filter(Boolean)
      .map(String);
  } finally {
    await context.dispose();
  }
};

export const waitForMailpitLink = async (recipient, pathFragment, timeoutMs = 20_000, { excludeIds = [] } = {}) => {
  const mailpit = env('ROUTEMAPS_E2E_MAILPIT_URL').replace(/\/$/, '');
  if (!mailpit) throw new Error('ROUTEMAPS_E2E_MAILPIT_URL is required');
  const excluded = new Set(excludeIds.map(String));
  const context = await playwrightRequest.newContext();
  const deadline = Date.now() + timeoutMs;
  try {
    while (Date.now() < deadline) {
      const messages = await mailpitMessages(context, recipient);
      for (const message of messages) {
        const id = message.ID || message.Id || message.id;
        if (!id || excluded.has(String(id))) continue;
        const detail = await context.get(`${mailpit}/api/v1/message/${encodeURIComponent(id)}`);
        if (!detail.ok()) continue;
        const body = await detail.json();
        const haystack = `${body.HTML || body.Html || ''}\n${body.Text || body.text || ''}`;
        const links = [...haystack.matchAll(/https?:\/\/[^\s"'<>]+/g)].map((match) => decodeHtmlEntities(match[0]));
        const found = links.find((link) => link.includes(pathFragment));
        if (found) return found;
      }
      await new Promise((resolve) => setTimeout(resolve, 500));
    }
  } finally {
    await context.dispose();
  }
  throw new Error(`mailpit_link_not_found:${recipient}:${pathFragment}`);
};
