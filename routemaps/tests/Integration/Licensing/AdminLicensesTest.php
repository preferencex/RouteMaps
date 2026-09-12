<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Licensing;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\LicenseStatusService;
use RouteMaps\Core\Domain\Licensing\LicenseValidityService;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration003Licenses;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseRepository;
use WP_UnitTestCase;

final class AdminLicensesTest extends WP_UnitTestCase {
    public function test_allowed_manual_transitions_fire_one_event_each_and_revoked_is_terminal(): void {
        [$repo, $service, $licenseId] = $this->scenario('2099-01-01 00:00:00');
        $events = 0;
        $listener = static function () use (&$events): void { ++$events; };
        add_action('routemaps_license_status_changed', $listener, 10, 3);
        $now = new DateTimeImmutable('2026-09-11 22:00:00', new DateTimeZone('UTC'));

        $suspended = $service->suspend($licenseId, $now);
        self::assertSame(LicenseStatus::SUSPENDED, $suspended->status());
        self::assertSame('2026-09-11 22:00:00', $suspended->suspendedAt());

        $active = $service->reactivate($licenseId, $now->modify('+1 minute'));
        self::assertSame(LicenseStatus::ACTIVE, $active->status());
        self::assertNull($active->suspendedAt());

        $revoked = $service->revoke($licenseId, $now->modify('+2 minutes'));
        self::assertSame(LicenseStatus::REVOKED, $revoked->status());
        self::assertSame('2026-09-11 22:02:00', $revoked->revokedAt());
        self::assertSame(3, $events);

        try {
            $service->reactivate($licenseId, $now->modify('+3 minutes'));
            self::fail('Revoked licenses must be terminal.');
        } catch (LogicException $exception) {
            self::assertSame('license_revoked_terminal', $exception->getMessage());
        }
        self::assertSame(3, $events);
        self::assertSame(LicenseStatus::REVOKED, $repo->find($licenseId)?->status());

        remove_action('routemaps_license_status_changed', $listener, 10);
    }

    public function test_expiry_is_derived_and_only_explicit_revoke_changes_persisted_status(): void {
        [$repo, $service, $licenseId] = $this->scenario('2026-09-10 00:00:00');
        $now = new DateTimeImmutable('2026-09-11 22:00:00', new DateTimeZone('UTC'));
        $license = $repo->find($licenseId);
        self::assertNotNull($license);
        self::assertSame(LicenseStatus::ACTIVE, $license->status());
        self::assertSame(LicenseStatus::EXPIRED, $service->effectiveStatus($license, $now));
        self::assertSame(LicenseStatus::ACTIVE, $repo->find($licenseId)?->status());

        try {
            $service->suspend($licenseId, $now);
            self::fail('Derived-expired license must not be persisted as suspended.');
        } catch (LogicException $exception) {
            self::assertSame('license_not_active', $exception->getMessage());
        }

        $revoked = $service->revoke($licenseId, $now);
        self::assertSame(LicenseStatus::REVOKED, $revoked->status());
    }

    /** @return array{WpdbLicenseRepository,LicenseStatusService,int} */
    private function scenario(string $validUntil): array {
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [
            new Migration001RoutesVersions(),
            new Migration002PoisCategories(),
            new Migration003Licenses(),
        ]))->migrate();
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_licenses');

        $repo = new WpdbLicenseRepository($wpdb);
        $license = $repo->create([
            'public_token_hash' => hash('sha256', str_repeat('a', 64)),
            'order_id' => 100,
            'order_item_id' => 200,
            'product_id' => 300,
            'route_id' => 400,
            'owner_user_id' => 500,
            'status' => LicenseStatus::ACTIVE,
            'validity_mode' => ValidityMode::FIXED_RANGE,
            'valid_from' => '2026-01-01 00:00:00',
            'valid_until' => $validUntil,
            'max_openings' => 10,
            'openings_used' => 0,
            'sharing_enabled' => false,
            'max_shares' => 0,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        return [$repo, new LicenseStatusService($repo, new LicenseValidityService()), $license->id()];
    }
}
