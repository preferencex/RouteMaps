<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database\Migrations;

use RouteMaps\Core\Infrastructure\Database\MigrationInterface;
use wpdb;

final class Migration003Licenses implements MigrationInterface {
    public function version(): int {
        return 3;
    }

    public function up(wpdb $db): void {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charsetCollate = $db->get_charset_collate();
        $table = $db->prefix . 'routemaps_licenses';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            public_token_hash char(64) NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            route_id bigint(20) unsigned NOT NULL,
            owner_user_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            validity_mode varchar(30) NOT NULL DEFAULT 'unlimited',
            validity_days int(11) NULL,
            valid_from datetime NULL,
            valid_until datetime NULL,
            first_access_at datetime NULL,
            max_openings int(11) NULL,
            openings_used int(11) NOT NULL DEFAULT 0,
            sharing_enabled tinyint(1) NOT NULL DEFAULT 0,
            max_shares int(11) NOT NULL DEFAULT 0,
            suspended_at datetime NULL,
            revoked_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uuid (uuid),
            UNIQUE KEY public_token_hash (public_token_hash),
            UNIQUE KEY order_item_id (order_item_id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY route_id (route_id),
            KEY owner_user_id (owner_user_id),
            KEY status (status),
            KEY valid_until (valid_until)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
}
