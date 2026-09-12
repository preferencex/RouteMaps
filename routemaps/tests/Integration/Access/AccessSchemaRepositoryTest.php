<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Access;

use DateTimeImmutable;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration003Licenses;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration004AccessSharing;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessEventRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessSessionRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseUserRepository;
use WP_UnitTestCase;

final class AccessSchemaRepositoryTest extends WP_UnitTestCase {
    public function test_access_tables_have_required_columns_and_indexes(): void {
        global $wpdb;
        $this->migrate();

        $licenseUsers = $wpdb->prefix . 'routemaps_license_users';
        $sessions = $wpdb->prefix . 'routemaps_access_sessions';
        $events = $wpdb->prefix . 'routemaps_access_events';

        $this->assertColumns($licenseUsers, ['id','license_id','user_id','email','role','status','invite_token_hash','created_at','updated_at']);
        $this->assertColumns($sessions, ['id','uuid','license_id','user_id','started_at','last_seen_at','expires_at','ended_at']);
        $this->assertColumns($events, ['id','license_id','user_id','event_type','reason_code','metadata_json','created_at']);

        $this->assertIndexColumns($licenseUsers, ['license_id','user_id','status']);
        $this->assertIndexColumns($sessions, ['license_id','user_id','last_seen_at']);
        $this->assertIndexColumns($events, ['license_id','created_at']);
    }

    public function test_migration_backfills_owner_and_repositories_support_membership_session_and_events(): void {
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [
            new Migration001RoutesVersions(),
            new Migration002PoisCategories(),
            new Migration003Licenses(),
        ]))->migrate();

        $ownerId = self::factory()->user->create(['user_email' => 'Owner@Example.test']);
        $licenses = new WpdbLicenseRepository($wpdb);
        $license = $licenses->create([
            'public_token_hash' => hash('sha256', str_repeat('a', 64)),
            'order_id' => 101,
            'order_item_id' => 201,
            'product_id' => 301,
            'route_id' => 401,
            'owner_user_id' => $ownerId,
            'status' => LicenseStatus::ACTIVE,
            'validity_mode' => ValidityMode::UNLIMITED,
            'max_openings' => 2,
            'openings_used' => 0,
            'sharing_enabled' => true,
            'max_shares' => 1,
            'created_at' => '2026-09-11 20:00:00',
            'updated_at' => '2026-09-11 20:00:00',
        ]);

        (new Migration004AccessSharing())->up($wpdb);
        update_option('routemaps_db_version', 4);

        $members = new WpdbLicenseUserRepository($wpdb);
        $owner = $members->findMembership($license->id(), $ownerId);
        self::assertNotNull($owner);
        self::assertSame('owner', $owner->role());
        self::assertSame('active', $owner->status());
        self::assertSame('owner@example.test', $owner->email());
        self::assertSame(0, $members->countGuestsByStatuses($license->id(), ['pending','active']));

        $sessions = new WpdbAccessSessionRepository($wpdb);
        $session = $sessions->create([
            'license_id' => $license->id(),
            'user_id' => $ownerId,
            'started_at' => '2026-09-11 21:00:00',
            'last_seen_at' => '2026-09-11 21:00:00',
            'expires_at' => '2026-09-11 21:30:00',
        ]);
        self::assertSame($session->uuid(), $sessions->findActive($license->id(), $ownerId, new DateTimeImmutable('2026-09-11 21:29:00'))?->uuid());
        self::assertNull($sessions->findActive($license->id(), $ownerId, new DateTimeImmutable('2026-09-11 21:31:00')));

        $events = new WpdbAccessEventRepository($wpdb);
        $events->record($license->id(), $ownerId, 'access_granted', null, ['route_id' => 401], new DateTimeImmutable('2026-09-11 21:00:00'));
        $recent = $events->forLicense($license->id(), 10);
        self::assertCount(1, $recent);
        self::assertSame('access_granted', $recent[0]['event_type']);
    }

    private function migrate(): void {
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [
            new Migration001RoutesVersions(),
            new Migration002PoisCategories(),
            new Migration003Licenses(),
            new Migration004AccessSharing(),
        ]))->migrate();
    }

    /** @param list<string> $expected */
    private function assertColumns(string $table, array $expected): void {
        global $wpdb;
        $columns = array_column($wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A), 'Field');
        foreach ($expected as $column) {
            self::assertContains($column, $columns, "Missing {$table}.{$column}");
        }
    }

    /** @param list<string> $expected */
    private function assertIndexColumns(string $table, array $expected): void {
        global $wpdb;
        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row['Key_name']][(int) $row['Seq_in_index']] = $row['Column_name'];
        }
        $found = false;
        foreach ($indexes as $columns) {
            ksort($columns);
            if (array_values($columns) === $expected) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Missing composite index: ' . implode(',', $expected));
    }
}
