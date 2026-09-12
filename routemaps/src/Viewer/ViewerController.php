<?php

declare(strict_types=1);

namespace RouteMaps\Core\Viewer;

use RouteMaps\Core\Maps\MapSettings;
use RouteMaps\Core\Security\ViewerHeaders;
use RouteMaps\Core\Support\JavaScriptTranslations;

final class ViewerController {
    private ViewerHeaders $headers;

    public function __construct(private ?LoginController $login = null, ?ViewerHeaders $headers = null) {
        $this->headers = $headers ?? new ViewerHeaders();
    }

    public function registerHooks(): void {
        add_action('template_redirect', [$this, 'prepareShell'], 1);
    }

    public function prepareShell(): void {
        if ('app' !== (string) get_query_var(RouteRewriteManager::QUERY_VIEW)) {
            return;
        }

        $routeUuid = strtolower(trim((string) get_query_var(RouteRewriteManager::QUERY_ROUTE_UUID)));
        if (!$this->isUuid($routeUuid)) {
            status_header(404);
            return;
        }

        $licenseUuid = '';
        if (isset($_GET['license']) && is_string($_GET['license'])) {
            $candidate = strtolower(trim((string) wp_unslash($_GET['license'])));
            if ($this->isUuid($candidate)) {
                $licenseUuid = $candidate;
            }
        }

        nocache_headers();
        $this->headers->send();
        header('X-Robots-Tag: noindex, nofollow', true);
        $GLOBALS['routemaps_viewer_bootstrap'] = $this->bootstrapData($routeUuid, $licenseUuid);
    }

    /** @return array<string,mixed> */
    public function bootstrapData(string $routeUuid, string $licenseUuid = ''): array {
        $routeUuid = strtolower(trim($routeUuid));
        if (!$this->isUuid($routeUuid)) {
            return [];
        }
        $licenseUuid = strtolower(trim($licenseUuid));
        if (!$this->isUuid($licenseUuid)) {
            $licenseUuid = '';
        }

        $settings = (new MapSettings())->load();
        return [
            'route_uuid' => $routeUuid,
            'i18n' => JavaScriptTranslations::catalogue(),
            'license_uuid' => $licenseUuid,
            'logged_in' => is_user_logged_in(),
            'login_url' => $this->loginUrl($routeUuid, $licenseUuid),
            'rest_nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'endpoints' => [
                'access_resolve' => rest_url('routemaps/v1/access/resolve'),
                'viewer_route' => rest_url('routemaps/v1/viewer/routes/' . rawurlencode($routeUuid)),
                'heartbeat' => rest_url('routemaps/v1/viewer/sessions/heartbeat'),
                'license' => rest_url('routemaps/v1/viewer/license'),
                'shares' => rest_url('routemaps/v1/viewer/shares'),
            ],
            'branding' => [
                'name' => sanitize_text_field((string) get_bloginfo('name')),
                'app_name' => 'RouteMaps',
            ],
            'map_defaults' => [
                'primary_provider' => sanitize_key((string) ($settings['primary_provider'] ?? 'pmtiles')),
                'fallback_provider' => sanitize_key((string) ($settings['fallback_provider'] ?? 'openfreemap')),
            ],
            'pwa' => [
                'manifest_url' => $this->manifestUrl($routeUuid, $licenseUuid),
                'service_worker_url' => home_url('/routemaps/service-worker.js'),
                'scope' => '/routemaps/',
            ],
            'assets' => $this->assetUrls(),
        ];
    }

    private function manifestUrl(string $routeUuid, string $licenseUuid): string {
        $url = home_url('/routemaps/manifest/' . rawurlencode($routeUuid) . '.webmanifest');
        if ('' !== $licenseUuid) {
            $url = add_query_arg('license', $licenseUuid, $url);
        }
        return $url;
    }

    private function loginUrl(string $routeUuid, string $licenseUuid): string {
        if (null === $this->login || '' === $licenseUuid) {
            return home_url('/routemaps/login/');
        }
        return $this->login->loginUrlForPath(
            '/routemaps/app/' . rawurlencode($routeUuid) . '?license=' . rawurlencode($licenseUuid)
        );
    }

    /** @return array{script:string,styles:list<string>} */
    private function assetUrls(): array {
        $baseDir = dirname(__DIR__, 2);
        $baseUrl = plugin_dir_url(ROUTEMAPS_FILE);
        foreach ([$baseDir . '/assets/viewer/.vite/manifest.json', $baseDir . '/assets/viewer/manifest.json'] as $manifestPath) {
            if (!is_readable($manifestPath)) {
                continue;
            }
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $entry = is_array($manifest) ? ($manifest['assets-src/viewer/index.js'] ?? null) : null;
            if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
                continue;
            }
            $styles = [];
            foreach (is_array($entry['css'] ?? null) ? $entry['css'] : [] as $css) {
                if (is_string($css) && '' !== $css) {
                    $styles[] = $baseUrl . 'assets/viewer/' . ltrim($css, '/');
                }
            }
            return [
                'script' => $baseUrl . 'assets/viewer/' . ltrim((string) $entry['file'], '/'),
                'styles' => $styles,
            ];
        }

        return [
            'script' => $baseUrl . 'assets/viewer/routemaps-viewer.js?ver=' . rawurlencode((string) ROUTEMAPS_VERSION),
            'styles' => [$baseUrl . 'assets/viewer/routemaps-viewer.css?ver=' . rawurlencode((string) ROUTEMAPS_VERSION)],
        ];
    }

    private function isUuid(string $value): bool {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }
}
