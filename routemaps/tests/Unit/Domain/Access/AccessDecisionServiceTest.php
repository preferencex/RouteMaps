<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\Domain\Access;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RouteMaps\Core\Domain\Access\AccessDecisionService;
use RouteMaps\Core\Domain\Access\AccessEventRepositoryInterface;
use RouteMaps\Core\Domain\Access\AccessReason;
use RouteMaps\Core\Domain\Access\AccessRequestContext;
use RouteMaps\Core\Domain\Access\AccessSession;
use RouteMaps\Core\Domain\Access\AccessSessionRepositoryInterface;
use RouteMaps\Core\Domain\Licensing\License;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\LicenseValidityService;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Domain\Sharing\LicenseUser;
use RouteMaps\Core\Domain\Sharing\LicenseUserRepositoryInterface;
use RouteMaps\Core\Security\TokenService;

final class AccessDecisionServiceTest extends TestCase {
    /** @dataProvider denialProvider */
    public function test_denial_order_and_reason_codes(string $case, AccessReason $expected): void {
        $fixture = new AccessDecisionFixture();
        $context = $fixture->context();

        match ($case) {
            'invalid_token' => $context = $context->withPublicToken('bad'),
            'not_authenticated' => $context = $context->withUserId(0),
            'not_authorized' => $context = $context->withUserId(88),
            'route_mismatch' => $context = $context->withRouteId(999),
            'license_revoked' => $fixture->license = $fixture->licenseWith(status: LicenseStatus::REVOKED),
            'license_suspended' => $fixture->license = $fixture->licenseWith(status: LicenseStatus::SUSPENDED),
            'license_expired' => $fixture->license = $fixture->licenseWith(validUntil: '2026-09-10 00:00:00'),
            'openings_exhausted' => $fixture->license = $fixture->licenseWith(maxOpenings: 1, openingsUsed: 1),
            default => null,
        };

        $decision = $fixture->service()->decide($context);
        self::assertFalse($decision->allowed());
        self::assertSame($expected, $decision->reason());
    }

    public static function denialProvider(): array {
        return [
            'invalid token' => ['invalid_token', AccessReason::INVALID_TOKEN],
            'anonymous after valid token' => ['not_authenticated', AccessReason::NOT_AUTHENTICATED],
            'authenticated non-member' => ['not_authorized', AccessReason::NOT_AUTHORIZED],
            'route mismatch' => ['route_mismatch', AccessReason::ROUTE_MISMATCH],
            'revoked' => ['license_revoked', AccessReason::LICENSE_REVOKED],
            'suspended' => ['license_suspended', AccessReason::LICENSE_SUSPENDED],
            'expired' => ['license_expired', AccessReason::LICENSE_EXPIRED],
            'openings exhausted' => ['openings_exhausted', AccessReason::OPENINGS_EXHAUSTED],
        ];
    }

    public function test_invalid_token_wins_before_anonymous_or_route_details(): void {
        $fixture = new AccessDecisionFixture();
        $decision = $fixture->service()->decide(
            $fixture->context()->withPublicToken('not-a-token')->withUserId(0)->withRouteId(999)
        );
        self::assertSame(AccessReason::INVALID_TOKEN, $decision->reason());
        self::assertNull($decision->license());
    }

    public function test_license_uuid_is_resolved_only_for_authenticated_active_member(): void {
        $fixture = new AccessDecisionFixture();
        $context = new AccessRequestContext(77, null, $fixture->license->uuid(), 9, $fixture->now);
        $decision = $fixture->service()->decide($context);
        self::assertTrue($decision->allowed());
        self::assertSame($fixture->license->id(), $decision->license()?->id());
    }

    public function test_exhausted_counter_still_allows_current_active_session(): void {
        $fixture = new AccessDecisionFixture();
        $fixture->license = $fixture->licenseWith(maxOpenings: 1, openingsUsed: 1);
        $fixture->activeSession = new AccessSession(1, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 1, 77, '2026-09-11 21:30:00', '2026-09-11 21:50:00', '2026-09-11 22:20:00', null);
        $decision = $fixture->service()->decide($fixture->context());
        self::assertTrue($decision->allowed());
    }
}

final class AccessDecisionFixture {
    public DateTimeImmutable $now;
    public License $license;
    public ?AccessSession $activeSession = null;
    private string $plainToken;

    public function __construct() {
        $this->now = new DateTimeImmutable('2026-09-11 22:00:00');
        $this->plainToken = str_repeat('a', 64);
        $this->license = $this->licenseWith();
    }

    public function context(): AccessRequestContext {
        return new AccessRequestContext(77, $this->plainToken, null, 9, $this->now);
    }

    public function licenseWith(
        LicenseStatus $status = LicenseStatus::ACTIVE,
        string $validUntil = '2099-01-01 00:00:00',
        ?int $maxOpenings = 2,
        int $openingsUsed = 0
    ): License {
        return new License(1, '11111111-1111-4111-8111-111111111111', hash('sha256', $this->plainToken ?? str_repeat('a', 64)), 10, 20, 30, 9, 77, $status, ValidityMode::FIXED_RANGE, null, '2026-01-01 00:00:00', $validUntil, null, $maxOpenings, $openingsUsed, true, 1, $status === LicenseStatus::SUSPENDED ? '2026-09-11 20:00:00' : null, $status === LicenseStatus::REVOKED ? '2026-09-11 20:00:00' : null, '2026-01-01 00:00:00', '2026-01-01 00:00:00');
    }

    public function service(): AccessDecisionService {
        $licenseRepo = new class($this) implements LicenseRepositoryInterface {
            public function __construct(private AccessDecisionFixture $f) {}
            public function create(array $data): License { return $this->f->license; }
            public function find(int $id): ?License { return $id === 1 ? $this->f->license : null; }
            public function findForUpdate(int $id): ?License { return $this->find($id); }
            public function findByUuid(string $uuid): ?License { return $uuid === $this->f->license->uuid() ? $this->f->license : null; }
            public function findByOrderItemId(int $orderItemId): ?License { return null; }
            public function findByTokenHash(string $tokenHash): ?License { return hash_equals($this->f->license->publicTokenHash(), $tokenHash) ? $this->f->license : null; }
            public function setFirstAccessIfEmpty(int $licenseId, string $firstAccessAt, string $updatedAt): License { return $this->find($licenseId) ?? $this->f->license; }
    public function incrementOpenings(int $licenseId, string $updatedAt): License { return $this->f->license; }
            public function updateLifecycleStatus(int $licenseId, LicenseStatus $status, ?string $suspendedAt, ?string $revokedAt, string $updatedAt): License { return $this->f->license; }
            public function findByOwner(int $ownerUserId, int $limit = 100, int $offset = 0): array { return []; }
            public function search(array $filters, int $page, int $perPage): array { return ['items'=>[],'total'=>0,'page'=>$page,'per_page'=>$perPage]; }
        };
        $members = new class implements LicenseUserRepositoryInterface {
            public function create(array $data): LicenseUser { throw new \RuntimeException(); }
            public function find(int $id): ?LicenseUser { return null; }
            public function findMembership(int $licenseId, int $userId): ?LicenseUser { return null; }
            public function findPendingOrActiveByEmail(int $licenseId, string $email): ?LicenseUser { return null; }
            public function findByInviteTokenHash(string $tokenHash): ?LicenseUser { return null; }
            public function countGuestsByStatuses(int $licenseId, array $statuses): int { return 0; }
            public function listGuests(int $licenseId): array { return []; }
            public function activate(int $id, int $userId, string $updatedAt): LicenseUser { throw new \RuntimeException(); }
            public function revoke(int $id, string $updatedAt): LicenseUser { throw new \RuntimeException(); }
        };
        $sessions = new class($this) implements AccessSessionRepositoryInterface {
            public function __construct(private AccessDecisionFixture $f) {}
            public function create(array $data): AccessSession { throw new \RuntimeException(); }
            public function find(int $id): ?AccessSession { return null; }
            public function findByUuid(string $uuid): ?AccessSession { return null; }
            public function findActive(int $licenseId, int $userId, DateTimeImmutable $now): ?AccessSession { return $this->f->activeSession; }
            public function touch(string $uuid, int $userId, DateTimeImmutable $lastSeenAt, DateTimeImmutable $expiresAt): AccessSession { throw new \RuntimeException(); }
            public function end(int $id, DateTimeImmutable $endedAt): void {}
            public function endForLicenseUser(int $licenseId, int $userId, DateTimeImmutable $endedAt): int { return 0; }
        };
        $events = new class implements AccessEventRepositoryInterface {
            public array $items=[];
            public function record(?int $licenseId, ?int $userId, string $eventType, ?string $reasonCode, array $metadata, DateTimeImmutable $createdAt): void { $this->items[] = compact('licenseId','userId','eventType','reasonCode','metadata'); }
            public function forLicense(int $licenseId, int $limit = 100): array { return $this->items; }
        };
        return new AccessDecisionService($licenseRepo, $members, $sessions, $events, new LicenseValidityService(), new TokenService());
    }
}
