<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Activation;

use RouteMaps\Core\Bootstrap\Activation;
use WP_UnitTestCase;

final class UninstallPolicyTest extends WP_UnitTestCase {
    /** @var list<string> */
    private array $tableSuffixes = [
        'routemaps_access_events',
        'routemaps_access_sessions',
        'routemaps_license_users',
        'routemaps_licenses',
        'routemaps_pois',
        'routemaps_categories',
        'routemaps_route_versions',
        'routemaps_routes',
    ];

    protected function setUp(): void {
        parent::setUp();
        Activation::activate();
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
    }

    protected function tearDown(): void {
        delete_option('routemaps_delete_data_on_uninstall');
        Activation::activate();
        parent::tearDown();
    }

    public function test_default_uninstall_preserves_tables_options_and_media(): void {
        global $wpdb;
        update_option('routemaps_map_settings', ['primary_provider' => 'pmtiles']);
        $attachmentId = wp_insert_post([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'RouteMaps media must survive uninstall',
        ]);

        require dirname(__DIR__, 3) . '/uninstall.php';

        foreach ($this->tableSuffixes as $suffix) {
            self::assertSame($wpdb->prefix . $suffix, $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $suffix)
            ));
        }
        self::assertIsArray(get_option('routemaps_map_settings'));
        self::assertNotNull(get_post($attachmentId));
    }

    public function test_explicit_delete_removes_only_plugin_owned_data_and_preserves_media(): void {
        global $wpdb;
        update_option('routemaps_map_settings', ['primary_provider' => 'pmtiles']);
        update_option('routemaps_recent_errors', [['event' => 'test']]);
        update_option('routemaps_debug_logging', 1);
        update_option('routemaps_delete_data_on_uninstall', 1);
        set_transient('routemaps_rl_test', 1, HOUR_IN_SECONDS);
        set_transient('routemaps_import_test', ['state' => 'staged'], HOUR_IN_SECONDS);
        $attachmentId = wp_insert_post([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Media survives destructive RouteMaps uninstall',
        ]);

        try {
            require dirname(__DIR__, 3) . '/uninstall.php';

            foreach ($this->tableSuffixes as $suffix) {
                self::assertNull($wpdb->get_var(
                    $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $suffix)
                ));
            }
            self::assertFalse(get_option('routemaps_map_settings', false));
            self::assertFalse(get_option('routemaps_recent_errors', false));
            self::assertFalse(get_option('routemaps_debug_logging', false));
            self::assertFalse(get_option('routemaps_db_version', false));
            self::assertFalse(get_transient('routemaps_rl_test'));
            self::assertFalse(get_transient('routemaps_import_test'));
            self::assertNotNull(get_post($attachmentId));
        } finally {
            delete_option('routemaps_db_version');
            Activation::activate();
        }
    }
}
