<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database\Migrations;

use RouteMaps\Core\Infrastructure\Database\MigrationInterface;
use wpdb;

final class Migration002PoisCategories implements MigrationInterface {
    public function version(): int {
        return 2;
    }

    public function up(wpdb $db): void {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charsetCollate = $db->get_charset_collate();
        $categoriesTable = $db->prefix . 'routemaps_categories';
        $poisTable = $db->prefix . 'routemaps_pois';

        $categoriesSql = "CREATE TABLE {$categoriesTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            name varchar(255) NOT NULL,
            slug varchar(200) NOT NULL,
            icon varchar(100) NOT NULL DEFAULT '',
            color varchar(20) NOT NULL DEFAULT '',
            sort_order int(11) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY uuid (uuid),
            UNIQUE KEY slug (slug),
            KEY active_sort (is_active,sort_order)
        ) {$charsetCollate};";

        $poisSql = "CREATE TABLE {$poisTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            name varchar(255) NOT NULL,
            category_id bigint(20) unsigned NOT NULL,
            latitude decimal(10,7) NOT NULL,
            longitude decimal(10,7) NOT NULL,
            description longtext NOT NULL,
            address text NOT NULL,
            phone varchar(100) NOT NULL DEFAULT '',
            website text NOT NULL,
            opening_hours text NOT NULL,
            route_note text NOT NULL,
            main_attachment_id bigint(20) unsigned NULL,
            gallery_json longtext NOT NULL,
            icon varchar(100) NOT NULL DEFAULT '',
            color varchar(20) NOT NULL DEFAULT '',
            cta_json longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_by bigint(20) unsigned NOT NULL,
            updated_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uuid (uuid),
            KEY category_id (category_id),
            KEY status (status)
        ) {$charsetCollate};";

        dbDelta($categoriesSql);
        dbDelta($poisSql);
    }
}
