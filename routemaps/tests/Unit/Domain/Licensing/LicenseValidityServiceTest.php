<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\Domain\Licensing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RouteMaps\Core\Domain\Licensing\License;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\LicenseValidityService;
use RouteMaps\Core\Domain\Licensing\ValidityMode;

final class LicenseValidityServiceTest extends TestCase {
    private LicenseValidityService $service;

    protected function setUp(): void {
        $this->service = new LicenseValidityService();
    }

    public function test_unlimited_active_license_stays_active(): void {
        self::assertSame(LicenseStatus::ACTIVE, $this->service->evaluate($this->license(), $this->time('2026-10-01 12:00:00')));
    }

    public function test_days_from_purchase_expires_at_exact_valid_until_boundary(): void {
        $license = $this->license(
            mode: ValidityMode::DAYS_FROM_PURCHASE,
            days: 30,
            validFrom: '2026-09-01 10:00:00',
            validUntil: '2026-10-01 10:00:00'
        );

        self::assertSame(LicenseStatus::ACTIVE, $this->service->evaluate($license, $this->time('2026-10-01 09:59:59')));
        self::assertSame(LicenseStatus::EXPIRED, $this->service->evaluate($license, $this->time('2026-10-01 10:00:00')));
    }

    public function test_days_from_purchase_can_derive_end_from_creation_when_not_persisted(): void {
        $license = $this->license(mode: ValidityMode::DAYS_FROM_PURCHASE, days: 7, createdAt: '2026-09-01 10:00:00');

        self::assertSame(LicenseStatus::ACTIVE, $this->service->evaluate($license, $this->time('2026-09-08 09:59:59')));
        self::assertSame(LicenseStatus::EXPIRED, $this->service->evaluate($license, $this->time('2026-09-08 10:00:00')));
    }

    public function test_days_from_first_use_does_not_expire_before_first_access(): void {
        $license = $this->license(mode: ValidityMode::DAYS_FROM_FIRST_USE, days: 7, firstAccessAt: null);

        self::assertSame(LicenseStatus::ACTIVE, $this->service->evaluate($license, $this->time('2027-01-01 00:00:00')));
    }

    public function test_days_from_first_use_expires_from_first_access(): void {
        $license = $this->license(
            mode: ValidityMode::DAYS_FROM_FIRST_USE,
            days: 7,
            firstAccessAt: '2026-09-10 09:00:00'
        );

        self::assertSame(LicenseStatus::ACTIVE, $this->service->evaluate($license, $this->time('2026-09-17 08:59:59')));
        self::assertSame(LicenseStatus::EXPIRED, $this->service->evaluate($license, $this->time('2026-09-17 09:00:00')));
    }

    public function test_fixed_range_is_pending_before_start_active_inside_and_expired_at_end(): void {
        $license = $this->license(
            mode: ValidityMode::FIXED_RANGE,
            validFrom: '2026-09-10 09:00:00',
            validUntil: '2026-09-20 18:00:00'
        );

        self::assertSame(LicenseStatus::PENDING, $this->service->evaluate($license, $this->time('2026-09-10 08:59:59')));
        self::assertSame(LicenseStatus::ACTIVE, $this->service->evaluate($license, $this->time('2026-09-10 09:00:00')));
        self::assertSame(LicenseStatus::EXPIRED, $this->service->evaluate($license, $this->time('2026-09-20 18:00:00')));
    }

    public function test_revoked_and_suspended_take_precedence_over_time_and_openings(): void {
        $revoked = $this->license(status: LicenseStatus::ACTIVE, revokedAt: '2026-09-01 00:00:00', maxOpenings: 1, openingsUsed: 1);
        $suspended = $this->license(status: LicenseStatus::ACTIVE, suspendedAt: '2026-09-01 00:00:00', maxOpenings: 1, openingsUsed: 1);

        self::assertSame(LicenseStatus::REVOKED, $this->service->evaluate($revoked, $this->time('2026-10-01 00:00:00')));
        self::assertSame(LicenseStatus::SUSPENDED, $this->service->evaluate($suspended, $this->time('2026-10-01 00:00:00')));
    }

    public function test_opening_limit_is_exhausted_when_used_reaches_configured_maximum(): void {
        $license = $this->license(maxOpenings: 3, openingsUsed: 3);
        self::assertSame(LicenseStatus::EXHAUSTED, $this->service->evaluate($license, $this->time('2026-09-12 00:00:00')));
    }

    public function test_persisted_pending_status_remains_pending_when_other_rules_allow_access(): void {
        $license = $this->license(status: LicenseStatus::PENDING);
        self::assertSame(LicenseStatus::PENDING, $this->service->evaluate($license, $this->time('2026-09-12 00:00:00')));
    }

    private function license(
        LicenseStatus $status = LicenseStatus::ACTIVE,
        ValidityMode $mode = ValidityMode::UNLIMITED,
        ?int $days = null,
        ?string $validFrom = null,
        ?string $validUntil = null,
        ?string $firstAccessAt = null,
        ?int $maxOpenings = null,
        int $openingsUsed = 0,
        ?string $suspendedAt = null,
        ?string $revokedAt = null,
        string $createdAt = '2026-09-01 10:00:00'
    ): License {
        return new License(
            1,
            '11111111-1111-4111-8111-111111111111',
            str_repeat('a', 64),
            100,
            101,
            102,
            103,
            104,
            $status,
            $mode,
            $days,
            $validFrom,
            $validUntil,
            $firstAccessAt,
            $maxOpenings,
            $openingsUsed,
            true,
            2,
            $suspendedAt,
            $revokedAt,
            $createdAt,
            $createdAt
        );
    }

    private function time(string $value): DateTimeImmutable {
        return new DateTimeImmutable($value . ' UTC');
    }
}
