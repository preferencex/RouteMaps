<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Viewer;

use RouteMaps\Core\Viewer\RouteRewriteManager;
use RouteMaps\Core\Viewer\LoginController;
use RouteMaps\Core\Security\RateLimiter;
use RouteMaps\Core\Viewer\ViewerController;
use WP_UnitTestCase;

final class ViewerShellTest extends WP_UnitTestCase {
    public function test_app_rewrite_captures_only_route_uuid(): void {
        $manager = new RouteRewriteManager();
        $manager->registerRules();

        global $wp_rewrite;
        $rules = $wp_rewrite->extra_rules_top;
        self::assertArrayHasKey('^routemaps/app/([0-9a-f-]{36})/?$', $rules);
        self::assertStringContainsString(RouteRewriteManager::QUERY_ROUTE_UUID, $rules['^routemaps/app/([0-9a-f-]{36})/?$']);
    }

    public function test_shell_bootstrap_contains_no_private_route_payload(): void {
        wp_set_current_user(self::factory()->user->create());
        $routeUuid = '11111111-1111-4111-8111-111111111111';
        $licenseUuid = '22222222-2222-4222-8222-222222222222';
        $login = new LoginController(new RateLimiter());
        $controller = new ViewerController($login);
        $bootstrap = $controller->bootstrapData($routeUuid, $licenseUuid);
        self::assertArrayNotHasKey('user_id', $bootstrap);
        self::assertArrayHasKey('login_url', $bootstrap);
        self::assertArrayHasKey('pwa', $bootstrap);
        self::assertSame('/routemaps/', $bootstrap['pwa']['scope']);
        self::assertStringContainsString('/routemaps/manifest/' . $routeUuid . '.webmanifest', $bootstrap['pwa']['manifest_url']);
        self::assertStringContainsString('license=' . $licenseUuid, $bootstrap['pwa']['manifest_url']);
        self::assertStringContainsString('/routemaps/service-worker.js', $bootstrap['pwa']['service_worker_url']);
        self::assertSame(
            '/routemaps/app/' . $routeUuid . '?license=' . $licenseUuid,
            $login->decodeContinuation((string) wp_parse_url((string) $bootstrap['login_url'], PHP_URL_QUERY) !== ''
                ? (string) (wp_parse_args((string) wp_parse_url((string) $bootstrap['login_url'], PHP_URL_QUERY))['continue'] ?? '')
                : '')
        );

        $GLOBALS['routemaps_viewer_bootstrap'] = $bootstrap;
        ob_start();
        include dirname(__DIR__, 3) . '/templates/viewer-shell.php';
        $html = (string) ob_get_clean();
        unset($GLOBALS['routemaps_viewer_bootstrap']);

        self::assertStringContainsString($routeUuid, $html);
        self::assertStringContainsString($licenseUuid, $html);
        self::assertStringContainsString('rel="manifest"', $html);
        self::assertStringContainsString('/routemaps/manifest/' . $routeUuid . '.webmanifest', $html);
        self::assertStringNotContainsString('snapshot_json', $html);
        self::assertStringNotContainsString('public_token_hash', $html);
        self::assertStringNotContainsString('order_id', $html);
        self::assertStringNotContainsString('[-8.6,41.1]', str_replace(' ', '', $html));
    }
}
