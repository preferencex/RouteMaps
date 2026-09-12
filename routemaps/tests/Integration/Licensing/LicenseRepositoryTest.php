<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Licensing;

use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration003Licenses;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseRepository;
use RuntimeException;
use WP_UnitTestCase;

final class LicenseRepositoryTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [
            new Migration001RoutesVersions(),
            new Migration002PoisCategories(),
            new Migration003Licenses(),
        ]))->migrate();
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_licenses');
    }

    public function test_create_and_lookup_preserve_commercial_policy_snapshot(): void {
        global $wpdb;
        $repository = new WpdbLicenseRepository($wpdb);
        $hash = hash('sha256', 'plain-token');

        $license = $repository->create([
            'public_token_hash' => $hash,
            'order_id' => 100,
            'order_item_id' => 101,
            'product_id' => 102,
            'route_id' => 103,
            'owner_user_id' => 104,
            'status' => LicenseStatus::ACTIVE,
            'validity_mode' => ValidityMode::DAYS_FROM_PURCHASE,
            'validity_days' => 30,
            'valid_from' => '2026-09-11 12:00:00',
            'valid_until' => '2026-10-11 12:00:00',
            'max_openings' => 10,
            'sharing_enabled' => true,
            'max_shares' => 2,
        ]);

        self::assertSame($license->id(), $repository->findByOrderItemId(101)?->id());
        self::assertSame($license->id(), $repository->findByTokenHash($hash)?->id());
        self::assertSame($license->id(), $repository->findByUuid($license->uuid())?->id());
        self::assertCount(1, $repository->findByOwner(104));
        self::assertSame(30, $license->validityDays());
        self::assertSame(10, $license->maxOpenings());
        self::assertTrue($license->sharingEnabled());
        self::assertSame(2, $license->maxShares());

        $search = $repository->search(['route_id' => 103, 'status' => 'active'], 1, 20);
        self::assertSame(1, $search['total']);
        self::assertSame($license->id(), $search['items'][0]->id());
    }

    public function test_first_access_timestamp_is_set_once_and_never_moved_forward(): void {
        global $wpdb;
        $repository = new WpdbLicenseRepository($wpdb);
        $license = $repository->create([
            'public_token_hash' => hash('sha256', 'first-use-token'),
            'order_id' => 601,
            'order_item_id' => 602,
            'product_id' => 603,
            'route_id' => 604,
            'owner_user_id' => 605,
            'status' => LicenseStatus::ACTIVE,
            'validity_mode' => ValidityMode::DAYS_FROM_FIRST_USE,
            'validity_days' => 7,
            'max_openings' => 3,
            'sharing_enabled' => false,
            'max_shares' => 0,
        ]);

        $first = $repository->setFirstAccessIfEmpty($license->id(), '2026-09-12 08:00:00', '2026-09-12 08:00:00');
        $second = $repository->setFirstAccessIfEmpty($license->id(), '2026-09-12 09:00:00', '2026-09-12 09:00:00');

        self::assertSame('2026-09-12 08:00:00', $first->firstAccessAt());
        self::assertSame('2026-09-12 08:00:00', $second->firstAccessAt());
    }

    public function test_order_item_id_is_unique_for_future_idempotent_issuance(): void {
        global $wpdb;
        $repository = new WpdbLicenseRepository($wpdb);
        $repository->create($this->data(501, hash('sha256', 'first')));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('license_insert_failed');
        $repository->create($this->data(501, hash('sha256', 'second')));
    }

    /** @return array<string,mixed> */
    private function data(int $orderItemId, string $hash): array {
        return [
            'public_token_hash' => $hash,
            'order_id' => 500,
            'order_item_id' => $orderItemId,
            'product_id' => 502,
            'route_id' => 503,
            'owner_user_id' => 504,
            'status' => LicenseStatus::ACTIVE,
            'validity_mode' => ValidityMode::UNLIMITED,
            'max_openings' => null,
            'sharing_enabled' => false,
            'max_shares' => 0,
        ];
    }
}
