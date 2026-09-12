<?php

declare(strict_types=1);

namespace RouteMaps\Core\PWA;

use RouteMaps\Core\Domain\Routes\Route;
use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use RouteMaps\Core\Viewer\RouteRewriteManager;

final class PwaManifestController {
    public function __construct(private RouteRepositoryInterface $routes) {
    }

    public function registerHooks(): void {
        add_action('template_redirect', [$this, 'serve'], 0);
    }

    public function serve(): void {
        if ('manifest' !== (string) get_query_var(RouteRewriteManager::QUERY_VIEW)) {
            return;
        }

        $routeUuid = strtolower(trim((string) get_query_var(RouteRewriteManager::QUERY_ROUTE_UUID)));
        $licenseUuid = '';
        if (isset($_GET['license']) && is_string($_GET['license'])) {
            $candidate = strtolower(trim((string) wp_unslash($_GET['license'])));
            if ($this->isUuid($candidate)) {
                $licenseUuid = $candidate;
            }
        }

        try {
            $manifest = $this->manifestData($routeUuid, $licenseUuid);
        } catch (\InvalidArgumentException) {
            status_header(404);
            exit;
        }

        nocache_headers();
        header('Content-Type: application/manifest+json; charset=' . get_option('blog_charset', 'UTF-8'));
        header('Cache-Control: private, no-store, max-age=0, must-revalidate');
        echo wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response.
        exit;
    }

    /** @return array<string,mixed> */
    public function manifestData(string $routeUuid, string $licenseUuid = ''): array {
        $routeUuid = strtolower(trim($routeUuid));
        if (!$this->isUuid($routeUuid)) {
            throw new \InvalidArgumentException('invalid_route_uuid');
        }

        $route = $this->routes->findByUuid($routeUuid);
        if (!$route instanceof Route) {
            throw new \InvalidArgumentException('route_not_found');
        }

        $licenseUuid = strtolower(trim($licenseUuid));
        if (!$this->isUuid($licenseUuid)) {
            $licenseUuid = '';
        }

        $appPath = '/routemaps/app/' . $route->uuid();
        $startUrl = $appPath;
        if ('' !== $licenseUuid) {
            $startUrl .= '?license=' . rawurlencode($licenseUuid);
        }

        $icons = apply_filters('routemaps_pwa_icons', $this->defaultIcons(), $route);
        if (!is_array($icons)) {
            $icons = [];
        }

        return [
            'name' => $route->title() . ' · RouteMaps',
            'short_name' => $route->title(),
            'id' => $appPath,
            'start_url' => $startUrl,
            'scope' => '/routemaps/',
            'display' => 'standalone',
            'background_color' => '#f3f6f8',
            'theme_color' => '#173f59',
            'icons' => $this->sanitizeIcons($icons),
        ];
    }

    /** @return list<array{src:string,sizes:string,type:string,purpose?:string}> */
    private function defaultIcons(): array {
        $icons = [];
        foreach ([192, 512] as $size) {
            $url = function_exists('get_site_icon_url') ? (string) get_site_icon_url($size) : '';
            if ('' === $url) {
                continue;
            }
            $icons[] = [
                'src' => add_query_arg('routemaps_pwa_icon', '1', $url),
                'sizes' => $size . 'x' . $size,
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ];
        }
        return $icons;
    }

    /** @param array<mixed> $icons @return list<array{src:string,sizes:string,type:string,purpose?:string}> */
    private function sanitizeIcons(array $icons): array {
        $sanitized = [];
        foreach ($icons as $icon) {
            if (!is_array($icon)) {
                continue;
            }
            $src = esc_url_raw((string) ($icon['src'] ?? ''), ['https', 'http']);
            $sizes = sanitize_text_field((string) ($icon['sizes'] ?? ''));
            $type = sanitize_mime_type((string) ($icon['type'] ?? ''));
            if ('' === $src || 1 !== preg_match('/^\d+x\d+$/', $sizes) || '' === $type) {
                continue;
            }
            $entry = ['src' => $src, 'sizes' => $sizes, 'type' => $type];
            $purpose = sanitize_text_field((string) ($icon['purpose'] ?? ''));
            if ('' !== $purpose) {
                $entry['purpose'] = $purpose;
            }
            $sanitized[] = $entry;
        }
        return $sanitized;
    }

    private function isUuid(string $value): bool {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }
}
