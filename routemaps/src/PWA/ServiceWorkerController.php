<?php

declare(strict_types=1);

namespace RouteMaps\Core\PWA;

use RouteMaps\Core\Viewer\RouteRewriteManager;

final class ServiceWorkerController {
    public function registerHooks(): void {
        add_action('template_redirect', [$this, 'serve'], 0);
    }

    public function serve(): void {
        if ('service-worker' !== (string) get_query_var(RouteRewriteManager::QUERY_VIEW)) {
            return;
        }

        $path = $this->assetPath();
        if (!is_readable($path)) {
            status_header(404);
            exit;
        }

        header('Content-Type: application/javascript; charset=UTF-8');
        header('Service-Worker-Allowed: ' . $this->scope());
        header('Cache-Control: no-cache, max-age=0, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        echo (string) file_get_contents($path); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript asset response.
        exit;
    }

    public function scope(): string {
        return '/routemaps/';
    }

    public function assetPath(): string {
        return dirname(__DIR__, 2) . '/assets/pwa/service-worker.js';
    }
}
