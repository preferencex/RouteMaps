<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\PWA;

use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\PWA\PwaManifestController;
use RouteMaps\Core\Viewer\RouteRewriteManager;
use WP_UnitTestCase;

final class PwaManifestTest extends WP_UnitTestCase {
    private const ROUTE_UUID_PATTERN = '/^[0-9a-f-]{36}$/';

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [new Migration001RoutesVersions()]))->migrate();
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_route_versions');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_routes');
    }

    public function test_manifest_is_route_scoped_standalone_and_contains_no_access_token(): void {
        global $wpdb;

        $owner = self::factory()->user->create();
        $route = (new WpdbRouteRepository($wpdb))->create('Douro Premium', $owner);
        self::assertMatchesRegularExpression(self::ROUTE_UUID_PATTERN, $route->uuid());

        $iconsFilter = static fn (array $icons): array => [
            [
                'src' => 'https://example.test/wp-content/uploads/routemaps-icon-192.png',
                'sizes' => '192x192',
                'type' => 'image/png',
            ],
            [
                'src' => 'https://example.test/wp-content/uploads/routemaps-icon-512.png',
                'sizes' => '512x512',
                'type' => 'image/png',
            ],
        ];
        add_filter('routemaps_pwa_icons', $iconsFilter);

        try {
            $controller = new PwaManifestController(new WpdbRouteRepository($wpdb));
            $manifest = $controller->manifestData($route->uuid());
        } finally {
            remove_filter('routemaps_pwa_icons', $iconsFilter);
        }

        self::assertSame('Douro Premium · RouteMaps', $manifest['name']);
        self::assertSame('Douro Premium', $manifest['short_name']);
        self::assertSame('standalone', $manifest['display']);
        self::assertSame('/routemaps/app/' . $route->uuid(), $manifest['id']);
        self::assertSame('/routemaps/app/' . $route->uuid(), $manifest['start_url']);
        self::assertSame('#173f59', $manifest['theme_color']);
        self::assertSame('#f3f6f8', $manifest['background_color']);
        self::assertSame('https://example.test/wp-content/uploads/routemaps-icon-192.png', $manifest['icons'][0]['src']);

        $encoded = wp_json_encode($manifest);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('token', strtolower($encoded));
        self::assertStringNotContainsString('/routemaps/access/', $encoded);
        self::assertStringNotContainsString('/routemaps/invite/', $encoded);
    }

    public function test_authorized_license_context_adds_only_non_secret_license_uuid_to_start_url(): void {
        global $wpdb;

        $owner = self::factory()->user->create();
        $route = (new WpdbRouteRepository($wpdb))->create('Rota Teste', $owner);
        $licenseUuid = '22222222-2222-4222-8222-222222222222';

        $manifest = (new PwaManifestController(new WpdbRouteRepository($wpdb)))
            ->manifestData($route->uuid(), $licenseUuid);

        self::assertSame('/routemaps/app/' . $route->uuid(), $manifest['id']);
        self::assertSame(
            '/routemaps/app/' . $route->uuid() . '?license=' . $licenseUuid,
            $manifest['start_url']
        );
        self::assertStringNotContainsString('token', strtolower((string) wp_json_encode($manifest)));
    }

    public function test_manifest_rewrite_is_specific_to_route_uuid_and_registers_query_var(): void {
        $manager = new RouteRewriteManager();
        $manager->registerRules();

        global $wp_rewrite;
        $rules = $wp_rewrite->extra_rules_top;

        self::assertArrayHasKey('^routemaps/manifest/([0-9a-f-]{36})\\.webmanifest$', $rules);
        self::assertStringContainsString('routemaps_view=manifest', $rules['^routemaps/manifest/([0-9a-f-]{36})\\.webmanifest$']);
        self::assertStringContainsString(RouteRewriteManager::QUERY_ROUTE_UUID, $rules['^routemaps/manifest/([0-9a-f-]{36})\\.webmanifest$']);
    }
}