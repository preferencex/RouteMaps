<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database\Migrations;

use RouteMaps\Core\Infrastructure\Database\MigrationInterface;
use wpdb;

final class Migration001RoutesVersions implements MigrationInterface {
    public function version(): int {
        return 1;
    }

    public function up(wpdb $db): void {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charsetCollate = $db->get_charset_collate();
        $routesTable    = $db->prefix . 'routemaps_routes';
        $versionsTable  = $db->prefix . 'routemaps_route_versions';

        $routesSql = "CREATE TABLE {$routesTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            title varchar(255) NOT NULL,
            slug varchar(200) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            cover_attachment_id bigint(20) unsigned NULL,
            current_published_version_id bigint(20) unsigned NULL,
            map_source_id varchar(50) NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uuid (uuid),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY current_published_version_id (current_published_version_id)
        ) {$charsetCollate};";

        $versionsSql = "CREATE TABLE {$versionsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            route_id bigint(20) unsigned NOT NULL,
            version_number int(10) unsigned NOT NULL,
            state varchar(20) NOT NULL DEFAULT 'draft',
            is_critical tinyint(1) NOT NULL DEFAULT 0,
            snapshot_json longtext NOT NULL,
            change_summary text NULL,
            content_hash char(64) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            published_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY route_version (route_id,version_number),
            KEY route_id (route_id),
            KEY state (state)
        ) {$charsetCollate};";

        dbDelta($routesSql);
        dbDelta($versionsSql);
    }
}
