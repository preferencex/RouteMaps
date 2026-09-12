# RouteMaps Core — Release Checklist

This checklist is the final release gate for RouteMaps Core. A release is not approved while any automated gate is unavailable, skipped unexpectedly, or failing.

## 1. Reproducible build prerequisites

- PHP 8.2+ with `dom`, `zip`, `json`, `mbstring` and the extensions required by WordPress/WooCommerce.
- Composer installed and a committed `composer.lock` matching `composer.json`.
- Node.js/npm installed and a committed `package-lock.json` matching `package.json`.
- `rsync`, `zip`, `unzip` available.
- Clean Git worktree at the intended release commit.
- WordPress 6.8+ and supported WooCommerce installed for integration/E2E.
- HTTPS or localhost for geolocation/PWA testing.

Initial preparation must be done once on a networked development/release machine:

```bash
# Generates both lockfiles in a temporary directory and copies neither if one resolver fails.
bash build/bootstrap-release-env.sh --generate-locks

# Review and commit the pair together.
git add composer.lock package-lock.json
git commit -m "build: lock RouteMaps release dependencies"

# Install exactly the locked dependency graph and Chromium for Playwright.
bash build/bootstrap-release-env.sh --install
```

CI and subsequent release machines must consume the committed locks; they must not regenerate them. Verify the environment and execute the complete gate with:

```bash
bash build/bootstrap-release-env.sh --check
bash build/release-gate.sh
```

`release-gate.sh` runs, in order, the five approved commands:

```bash
composer quality
npm test -- --run
npm run build
npx playwright test
bash build/package.sh
```

Expected result: every command exits `0`, `dist/routemaps-<version>.zip` exists, and the gate prints the release commit SHA plus the ZIP SHA-256. Inspect `dist/routemaps-<version>.contents.txt` and confirm that `tests/`, `assets-src/`, `node_modules/`, `build/`, `.git/` and development configuration are absent.

## 2. E2E fixture configuration

Core E2E tests never create production-only backdoors. Use an ephemeral WordPress/WooCommerce environment with disposable accounts and Mailpit.

Required for purchase/access:

- `ROUTEMAPS_E2E_BASE_URL`
- `ROUTEMAPS_E2E_ADMIN_USER`, `ROUTEMAPS_E2E_ADMIN_PASSWORD`
- `ROUTEMAPS_E2E_BUYER_USER`, `ROUTEMAPS_E2E_BUYER_PASSWORD`, `ROUTEMAPS_E2E_BUYER_EMAIL`
- `ROUTEMAPS_E2E_WC_KEY`, `ROUTEMAPS_E2E_WC_SECRET`
- `ROUTEMAPS_E2E_MAILPIT_URL`

Required for sharing/PWA:

- `ROUTEMAPS_E2E_SHARE_ACCESS_URL` — active owner license, sharing enabled, at least one free share slot
- `ROUTEMAPS_E2E_OWNER_USER`, `ROUTEMAPS_E2E_OWNER_PASSWORD`
- `ROUTEMAPS_E2E_GUEST_USER`, `ROUTEMAPS_E2E_GUEST_PASSWORD`, `ROUTEMAPS_E2E_GUEST_EMAIL`

Required for responsive viewer/provider release acceptance:

- `ROUTEMAPS_E2E_ACCESS_URL`
- `ROUTEMAPS_E2E_USER`, `ROUTEMAPS_E2E_PASSWORD`
- `ROUTEMAPS_E2E_PMTILES_ACCESS_URL`
- `ROUTEMAPS_E2E_FALLBACK_ACCESS_URL`
- `ROUTEMAPS_E2E_EXPIRED_SESSION_ACCESS_URL` — `openings_used=1`, `max_openings=2`, previous session expired/no reusable active session
- `ROUTEMAPS_E2E_EXHAUSTED_ACCESS_URL` — max-openings reached with no reusable active session

For local partial development runs these fixtures may be omitted and the corresponding scenario may skip. In CI/release mode the tests convert missing required fixtures into failures; a skipped required scenario is never a release PASS.

## 3. Installation and migration

- Install the release ZIP into a clean WordPress/WooCommerce site.
- Activate RouteMaps with no PHP warning/fatal.
- Confirm all RouteMaps tables exist and schema version is current.
- Confirm administrator capabilities were created.
- Visit **RouteMaps → Estado do sistema** and confirm WordPress/PHP/WooCommerce, HTTPS, rewrites, PWA and map-provider checks are healthy.
- Deactivate/reactivate: data remains intact and rewrites continue to work.
- Default uninstall in a disposable clone: data/media remain intact.
- Explicit delete-data uninstall: only RouteMaps-owned tables/options/transients/capabilities are removed; Media Library attachments remain.

## 4. Route authoring and versioning

- Import a Google My Maps KML and one KMZ; preview counts/content before commit.
- Import GeoJSON.
- Create route from scratch and edit geometry with MapLibre/Geoman.
- Create reusable category/POI, attach it to a route, publish v1.
- Edit and publish v2; verify v1 remains byte/logically reproducible through the admin versions API.
- Mark a version critical and confirm metadata persists.
- Confirm stable `entity_uuid` values for unchanged logical stops/POIs.

## 5. Commerce and licensing

- Configure a WooCommerce virtual product as RouteMaps.
- Verify normal non-RouteMaps products keep the site's guest-checkout behavior.
- RouteMaps product requires account/login.
- Complete a paid test order; exactly one license is issued for each applicable order item even when order/payment hooks run repeatedly.
- Confirm product policy is snapshotted into the license and later product edits do not rewrite acquired conditions.
- Confirm access email contains a working `/routemaps/access/<opaque-token>` URL and no token is persisted in plaintext.

## 6. Access/session/share matrix

- Logged-out access link → minimal RouteMaps login → return to requested route.
- “Manter sessão iniciada” behaves according to WordPress authentication.
- First valid session increments openings once.
- Refresh and heartbeat do not increment openings.
- After 30 minutes with no activity, a new session consumes the next opening (covered by deterministic integration tests; E2E may use a pre-seeded expired-session fixture to avoid a 30-minute wall-clock wait).
- Exhausted license denies a new session while an already-active valid session remains usable until expiry.
- Invite guest within limit; duplicate/owner/over-limit invitations are rejected.
- Guest accepts only with matching e-mail account.
- Revocation terminates guest sessions and subsequent access is denied.
- Suspend/reactivate/revoke owner license and verify access decisions.

## 7. Viewer and maps

- Desktop 1440×900, tablet 834×1194 and mobile 390×844 have no horizontal overflow.
- Full-viewport viewer contains no WordPress theme header/footer.
- Protected GeoJSON is absent from initial HTML.
- POI filtering, detail, gallery, CTA, route note and category metadata render correctly.
- Geolocation is requested only after user action.
- Fullscreen is requested only after user action and only where supported.
- Google Maps and Waze links use HTTPS and the selected coordinates.
- PMTiles self-hosted source returns `206` for Range requests.
- Force PMTiles health failure: OpenFreeMap becomes fallback while route/stops/POIs remain identical.
- Optional MapTiler provider works when configured without making it a required dependency.

## 8. PWA/cache matrix

- Manifest URL is route-scoped, `display: standalone`, and never includes the secret access token.
- Chromium install prompt appears only after successful authenticated viewer load and requires explicit click.
- Installed/standalone mode does not keep showing install UI.
- iOS/iPadOS displays “Partilhar → Adicionar ao ecrã principal” guidance.
- Service worker scope is `/routemaps/`.
- Cache Storage contains only versioned static shell assets and explicitly allowed PWA icons.
- Cache Storage contains no `/access/resolve`, `/viewer/routes/`, login, invite, access-token URL, POI/gallery private payload, or response marked `no-store`.
- Sign out/expire session: installed shortcut returns through RouteMaps login and back to the same route/license context.

## 9. Security/privacy/observability

- Protected responses are `private, no-store`, `nosniff` and `no-referrer`.
- Tokens, invitation tokens, passwords, raw rate-limit identifiers and MapTiler **service tokens** do not appear in HTML, JS bootstrap, logs, diagnostics or REST payloads. A MapTiler browser API key may appear in browser map requests by design and must be restricted with Allowed HTTP origins.
- External website/CTA URLs reject executable/unsafe schemes and private/reserved hosts where server fetching is involved.
- WordPress personal-data exporter includes relevant owner/share/access data without token hashes.
- Eraser anonymizes/revokes guest-share data and detaches non-commercial access-event user IDs while preserving WooCommerce/order retention data.
- **RouteMaps → Estado do sistema** does not expose secrets.
- Browser console and PHP/WooCommerce RouteMaps logs contain no unexpected error/warning during the full matrix.

## 10. Final archive/release decision

- Install the generated ZIP, not the source worktree, into a second clean site and repeat activation + one purchase/viewer smoke.
- Confirm plugin header version equals ZIP version.
- Confirm `vendor/autoload.php`, compiled admin/viewer/PWA assets, templates, `src/`, `languages/routemaps.pot` and `uninstall.php` are present.
- Confirm source tests, `assets-src`, build scripts and dependency directories are absent.
- Record checksum of the release ZIP and release commit SHA.
- Only tag/publish after every applicable item above is PASS. A missing tool, missing lockfile or skipped required E2E scenario blocks release approval.
