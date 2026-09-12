<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database\Migrations;

use RouteMaps\Core\Infrastructure\Database\MigrationInterface;
use wpdb;

final class Migration004AccessSharing implements MigrationInterface {
    public function version(): int {
        return 4;
    }

    public function up(wpdb $db): void {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charsetCollate = $db->get_charset_collate();
        $licenseUsers = $db->prefix . 'routemaps_license_users';
        $sessions = $db->prefix . 'routemaps_access_sessions';
        $events = $db->prefix . 'routemaps_access_events';
        $licenses = $db->prefix . 'routemaps_licenses';

        dbDelta("CREATE TABLE {$licenseUsers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            license_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NULL,
            email varchar(320) NOT NULL,
            role varchar(20) NOT NULL DEFAULT 'guest',
            status varchar(20) NOT NULL DEFAULT 'pending',
            invite_token_hash char(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY invite_token_hash (invite_token_hash),
            KEY license_user_status (license_id,user_id,status),
            KEY license_status (license_id,status),
            KEY license_email_status (license_id,email,status)
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$sessions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            license_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            started_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            ended_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uuid (uuid),
            KEY license_user_seen (license_id,user_id,last_seen_at),
            KEY license_user_expires (license_id,user_id,expires_at),
            KEY expires_at (expires_at)
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            license_id bigint(20) unsigned NULL,
            user_id bigint(20) unsigned NULL,
            event_type varchar(50) NOT NULL,
            reason_code varchar(50) NULL,
            metadata_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY license_created (license_id,created_at),
            KEY user_created (user_id,created_at),
            KEY event_created (event_type,created_at)
        ) {$charsetCollate};");

        $users = $db->users;
        $owners = $db->get_results(
            "SELECT l.id AS license_id,l.owner_user_id,LOWER(COALESCE(u.user_email,'')) AS email,l.created_at,l.updated_at
             FROM {$licenses} l
             LEFT JOIN {$users} u ON u.ID = l.owner_user_id",
            ARRAY_A
        );

        if (!is_array($owners)) {
            return;
        }

        /*
         * Deliberately avoid an INSERT ... SELECT that reads $licenseUsers while
         * inserting into it. WordPress' PHPUnit base case rewrites plugin CREATE
         * TABLE statements to CREATE TEMPORARY TABLE, and MySQL cannot reopen the
         * same temporary table as both the write target and a subquery source.
         * The row-by-row backfill is migration-only work and is idempotent.
         */
        foreach ($owners as $owner) {
            $licenseId = (int) ($owner['license_id'] ?? 0);
            if ($licenseId <= 0) {
                continue;
            }

            $existingOwnerId = $db->get_var(
                $db->prepare(
                    "SELECT id FROM {$licenseUsers} WHERE license_id = %d AND role = %s LIMIT 1",
                    $licenseId,
                    'owner'
                )
            );
            if (null !== $existingOwnerId) {
                continue;
            }

            $db->insert(
                $licenseUsers,
                [
                    'license_id' => $licenseId,
                    'user_id' => (int) ($owner['owner_user_id'] ?? 0),
                    'email' => (string) ($owner['email'] ?? ''),
                    'role' => 'owner',
                    'status' => 'active',
                    'invite_token_hash' => null,
                    'created_at' => (string) ($owner['created_at'] ?? ''),
                    'updated_at' => (string) ($owner['updated_at'] ?? ''),
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
            );
        }
    }
}