<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Admin;

use RouteMaps\Core\Admin\SystemStatusPage;
use RouteMaps\Core\Maps\MapSettings;
use RouteMaps\Core\Support\Logger;
use RouteMaps\Core\Support\SystemHealthService;
use WP_UnitTestCase;

final class SystemStatusTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option(MapSettings::OPTION_NAME);
        delete_option('routemaps_db_version');
        delete_option('routemaps_recent_errors');
        parent::tearDown();
    }

    public function test_health_report_contains_expected_sections_without_secrets(): void {
        update_option('routemaps_db_version', 4);
        (new MapSettings())->save([
            'primary_provider' => 'pmtiles',
            'fallback_provider' => 'openfreemap',
            'pmtiles_url' => '',
            'pmtiles_path' => '',
            'style_json' => '',
            'maptiler_key' => 'secret-maptiler-key',
            'openfreemap_style_url' => '',
        ]);

        $sink = new class() {
            public function info(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
        };
        (new Logger($sink))->error('diagnostic_test', ['token' => 'must-never-appear', 'route_id' => 7]);

        $items = (new SystemHealthService())->check();
        $ids = array_map(static fn ($item): string => $item->id(), $items);
        foreach (['plugin_version', 'schema_version', 'wordpress_version', 'php_version', 'woocommerce_version', 'permalinks', 'https', 'map_provider', 'pmtiles', 'service_worker', 'rewrite_samples', 'recent_errors'] as $required) {
            self::assertContains($required, $ids);
        }

        $encoded = wp_json_encode(array_map(static fn ($item): array => $item->toArray(), $items));
        self::assertIsString($encoded);
        self::assertStringNotContainsString('secret-maptiler-key', $encoded);
        self::assertStringNotContainsString('must-never-appear', $encoded);
        self::assertStringContainsString('[redacted]', $encoded);
    }

    public function test_status_page_requires_routemaps_settings_capability(): void {
        $page = new SystemStatusPage(new SystemHealthService());
        self::assertSame('manage_routemaps_settings', $page->capability());
    }
}
