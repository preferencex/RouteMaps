<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\PWA;

use RouteMaps\Core\PWA\ServiceWorkerController;
use RouteMaps\Core\Viewer\RouteRewriteManager;
use WP_UnitTestCase;

final class ServiceWorkerControllerTest extends WP_UnitTestCase {
    public function test_service_worker_rewrite_is_registered_under_routemaps_scope(): void {
        $manager = new RouteRewriteManager();
        $manager->registerRules();

        global $wp_rewrite;
        $rules = $wp_rewrite->extra_rules_top;

        self::assertArrayHasKey('^routemaps/service-worker\.js$', $rules);
        self::assertSame(
            'index.php?' . RouteRewriteManager::QUERY_VIEW . '=service-worker',
            $rules['^routemaps/service-worker\.js$']
        );
    }

    public function test_controller_exposes_service_worker_file_and_scope(): void {
        $controller = new ServiceWorkerController();

        self::assertSame('/routemaps/', $controller->scope());
        self::assertFileExists($controller->assetPath());
        self::assertStringContainsString('routemaps-static-v1', (string) file_get_contents($controller->assetPath()));
        self::assertStringContainsString('/wp-json/routemaps/v1/', (string) file_get_contents($controller->assetPath()));
    }
}
