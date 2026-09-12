<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Privacy;

use DateTimeImmutable;
use DateTimeZone;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration003Licenses;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration004AccessSharing;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessEventRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseUserRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Privacy\PersonalDataEraser;
use RouteMaps\Core\Privacy\PersonalDataExporter;
use WP_UnitTestCase;

final class PrivacyToolsTest extends WP_UnitTestCase {
    private int $ownerUserId;
    private int $guestUserId;
    private int $otherUserId;
    private string $ownerEmail = 'privacy-owner@example.test';
    private string $guestEmail = 'privacy-guest@example.test';
    private string $otherEmail = 'other-guest@example.test';
    private int $ownerLicenseId;
    private int $sharedLicenseId;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [
            new Migration001RoutesVersions(),
            new Migration002PoisCategories(),
            new Migration003Licenses(),
            new Migration004AccessSharing(),
        ]))->migrate();

        $this->ownerUserId = self::factory()->user->create(['user_email' => $this->ownerEmail]);
        $this->guestUserId = self::factory()->user->create(['user_email' => $this->guestEmail]);
        $this->otherUserId = self::factory()->user->create(['user_email' => $this->otherEmail]);

        $routes = new WpdbRouteRepository($wpdb);
        $routeA = $routes->create('Rota Privada A', $this->ownerUserId);
        $routeB = $routes->create('Rota Privada B', $this->otherUserId);
        $licenses = new WpdbLicenseRepository($wpdb);
        $ownerLicense = $licenses->create($this->licenseData(101, 201, $routeA->id(), $this->ownerUserId, str_repeat('a', 64)));
        $sharedLicense = $licenses->create($this->licenseData(102, 202, $routeB->id(), $this->otherUserId, str_repeat('b', 64)));
        $this->ownerLicenseId = $ownerLicense->id();
        $this->sharedLicenseId = $sharedLicense->id();

        $members = new WpdbLicenseUserRepository($wpdb);
        $members->create([
            'license_id' => $sharedLicense->id(),
            'user_id' => $this->guestUserId,
            'email' => $this->guestEmail,
            'role' => 'guest',
            'status' => 'active',
            'invite_token_hash' => null,
        ]);
        $members->create([
            'license_id' => $sharedLicense->id(),
            'user_id' => $this->otherUserId,
            'email' => $this->otherEmail,
            'role' => 'guest',
            'status' => 'active',
            'invite_token_hash' => null,
        ]);

        (new WpdbAccessEventRepository($wpdb))->record(
            $sharedLicense->id(),
            $this->guestUserId,
            'access_granted',
            null,
            ['route_id' => $routeB->id()],
            new DateTimeImmutable('2026-09-12 00:00:00', new DateTimeZone('UTC'))
        );
    }

    protected function tearDown(): void {
        delete_option('routemaps_db_version');
        parent::tearDown();
    }

    public function test_exporter_returns_only_subject_data_without_token_hashes_or_other_guest_email(): void {
        global $wpdb;
        $exporter = new PersonalDataExporter($wpdb);
        $owner = $exporter->export($this->ownerEmail, 1);
        $guest = $exporter->export($this->guestEmail, 1);

        $ownerJson = wp_json_encode($owner);
        self::assertIsString($ownerJson);
        self::assertStringContainsString('Rota Privada A', $ownerJson);
        self::assertStringContainsString('active', $ownerJson);
        self::assertStringNotContainsString(str_repeat('a', 64), $ownerJson);
        self::assertStringNotContainsString('public_token_hash', $ownerJson);

        $guestJson = wp_json_encode($guest);
        self::assertIsString($guestJson);
        self::assertStringContainsString('Rota Privada B', $guestJson);
        self::assertStringContainsString('access_granted', $guestJson);
        self::assertStringContainsString($this->guestEmail, $guestJson);
        self::assertStringNotContainsString($this->otherEmail, $guestJson);
        self::assertStringNotContainsString('invite_token_hash', $guestJson);
        self::assertStringNotContainsString(str_repeat('b', 64), $guestJson);
    }

    public function test_eraser_anonymizes_guest_share_and_detaches_non_owner_events_but_retains_owner_license(): void {
        global $wpdb;
        $eraser = new PersonalDataEraser($wpdb);

        $guestResult = $eraser->erase($this->guestEmail, 1);
        self::assertTrue($guestResult['items_removed']);
        self::assertFalse($guestResult['items_retained']);

        $guestRow = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}routemaps_license_users WHERE license_id = %d AND role = 'guest' ORDER BY id ASC LIMIT 1",
            $this->sharedLicenseId
        ), ARRAY_A);
        self::assertIsArray($guestRow);
        self::assertSame('revoked', $guestRow['status']);
        self::assertNull($guestRow['user_id']);
        self::assertNotSame($this->guestEmail, $guestRow['email']);
        self::assertNull($guestRow['invite_token_hash']);

        $eventUserId = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}routemaps_access_events WHERE license_id = %d AND event_type = 'access_granted' LIMIT 1",
            $this->sharedLicenseId
        ));
        self::assertNull($eventUserId);

        $ownerResult = $eraser->erase($this->ownerEmail, 1);
        self::assertTrue($ownerResult['items_retained']);
        self::assertNotEmpty($ownerResult['messages']);
        self::assertSame(
            $this->ownerUserId,
            (int) $wpdb->get_var($wpdb->prepare("SELECT owner_user_id FROM {$wpdb->prefix}routemaps_licenses WHERE id = %d", $this->ownerLicenseId))
        );
    }

    public function test_handlers_register_with_wordpress_privacy_filters(): void {
        global $wpdb;
        $exporter = new PersonalDataExporter($wpdb);
        $eraser = new PersonalDataEraser($wpdb);

        $exporters = $exporter->register([]);
        $erasers = $eraser->register([]);

        self::assertArrayHasKey('routemaps', $exporters);
        self::assertArrayHasKey('routemaps', $erasers);
        self::assertIsCallable($exporters['routemaps']['callback']);
        self::assertIsCallable($erasers['routemaps']['callback']);
    }

    /** @return array<string,mixed> */
    private function licenseData(int $orderId, int $orderItemId, int $routeId, int $ownerUserId, string $tokenHash): array {
        return [
            'public_token_hash' => $tokenHash,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'product_id' => 301 + $orderItemId,
            'route_id' => $routeId,
            'owner_user_id' => $ownerUserId,
            'status' => 'active',
            'validity_mode' => 'unlimited',
            'validity_days' => null,
            'valid_from' => null,
            'valid_until' => null,
            'first_access_at' => null,
            'max_openings' => null,
            'openings_used' => 0,
            'sharing_enabled' => true,
            'max_shares' => 2,
            'suspended_at' => null,
            'revoked_at' => null,
        ];
    }
}
