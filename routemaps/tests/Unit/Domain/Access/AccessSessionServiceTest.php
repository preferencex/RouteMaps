<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\Domain\Access;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use RouteMaps\Core\Domain\Access\AccessSession;
use RouteMaps\Core\Domain\Access\AccessSessionRepositoryInterface;
use RouteMaps\Core\Domain\Access\AccessSessionService;
use RouteMaps\Core\Domain\Licensing\License;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;

final class AccessSessionServiceTest extends TestCase {
    public function test_session_reuse_expiry_and_heartbeat_extend_inactivity_window(): void {
        global $wpdb;
        $licenses = new InMemoryOpeningLicenseRepository($this->license(maxOpenings: 5));
        $sessions = new InMemoryAccessSessionRepository();
        $service = new AccessSessionService($licenses, $sessions, new TransactionManager($wpdb));
        $t0 = new DateTimeImmutable('2026-09-11 20:00:00');

        $first = $service->openOrReuse($licenses->license, 77, $t0);
        self::assertSame(1, $licenses->license->openingsUsed());
        $at29 = $service->openOrReuse($licenses->license, 77, $t0->modify('+29 minutes'));
        self::assertSame($first->uuid(), $at29->uuid());
        self::assertSame(1, $licenses->license->openingsUsed());

        $expiredLicenses = new InMemoryOpeningLicenseRepository($this->license(maxOpenings: 5));
        $expiredSessions = new InMemoryAccessSessionRepository();
        $expired = new AccessSessionService($expiredLicenses, $expiredSessions, new TransactionManager($wpdb));
        $expiredFirst = $expired->openOrReuse($expiredLicenses->license, 77, $t0);
        $at31 = $expired->openOrReuse($expiredLicenses->license, 77, $t0->modify('+31 minutes'));
        self::assertNotSame($expiredFirst->uuid(), $at31->uuid());
        self::assertSame(2, $expiredLicenses->license->openingsUsed());

        $freshLicenses = new InMemoryOpeningLicenseRepository($this->license(maxOpenings: 5));
        $freshSessions = new InMemoryAccessSessionRepository();
        $fresh = new AccessSessionService($freshLicenses, $freshSessions, new TransactionManager($wpdb));
        $session = $fresh->openOrReuse($freshLicenses->license, 77, $t0);
        $fresh->heartbeat($session->uuid(), 77, $t0->modify('+25 minutes'));
        $at50 = $fresh->openOrReuse($freshLicenses->license, 77, $t0->modify('+50 minutes'));
        self::assertSame($session->uuid(), $at50->uuid());
        self::assertSame(1, $freshLicenses->license->openingsUsed());
    }

    public function test_expired_or_foreign_session_cannot_be_heartbeated(): void {
        global $wpdb;
        $licenses = new InMemoryOpeningLicenseRepository($this->license(maxOpenings: 2));
        $sessions = new InMemoryAccessSessionRepository();
        $service = new AccessSessionService($licenses, $sessions, new TransactionManager($wpdb));
        $t0 = new DateTimeImmutable('2026-09-11 20:00:00');
        $session = $service->openOrReuse($licenses->license, 77, $t0);

        foreach ([[88, $t0->modify('+5 minutes')], [77, $t0->modify('+31 minutes')]] as [$userId, $at]) {
            try {
                $service->heartbeat($session->uuid(), $userId, $at);
                self::fail('Heartbeat must reject foreign/expired session.');
            } catch (LogicException $exception) {
                self::assertSame('access_session_not_active', $exception->getMessage());
            }
        }
    }

    private function license(int $maxOpenings): License {
        return new License(1,'11111111-1111-4111-8111-111111111111',str_repeat('a',64),10,20,30,9,77,LicenseStatus::ACTIVE,ValidityMode::UNLIMITED,null,null,null,null,$maxOpenings,0,false,0,null,null,'2026-01-01 00:00:00','2026-01-01 00:00:00');
    }
}

final class InMemoryOpeningLicenseRepository implements LicenseRepositoryInterface {
    public function __construct(public License $license) {}
    public function create(array $data): License { return $this->license; }
    public function find(int $id): ?License { return $id === $this->license->id() ? $this->license : null; }
    public function findForUpdate(int $id): ?License { return $this->find($id); }
    public function findByUuid(string $uuid): ?License { return null; }
    public function findByOrderItemId(int $orderItemId): ?License { return null; }
    public function findByTokenHash(string $tokenHash): ?License { return null; }
    public function setFirstAccessIfEmpty(int $licenseId, string $firstAccessAt, string $updatedAt): License { return $this->find($licenseId) ?? $this->license; }
    public function incrementOpenings(int $licenseId, string $updatedAt): License {
        $l=$this->license;
        $this->license=new License($l->id(),$l->uuid(),$l->publicTokenHash(),$l->orderId(),$l->orderItemId(),$l->productId(),$l->routeId(),$l->ownerUserId(),$l->status(),$l->validityMode(),$l->validityDays(),$l->validFrom(),$l->validUntil(),$l->firstAccessAt(),$l->maxOpenings(),$l->openingsUsed()+1,$l->sharingEnabled(),$l->maxShares(),$l->suspendedAt(),$l->revokedAt(),$l->createdAt(),$updatedAt);
        return $this->license;
    }
    public function updateLifecycleStatus(int $licenseId, LicenseStatus $status, ?string $suspendedAt, ?string $revokedAt, string $updatedAt): License { return $this->license; }
    public function findByOwner(int $ownerUserId, int $limit = 100, int $offset = 0): array { return []; }
    public function search(array $filters, int $page, int $perPage): array { return ['items'=>[],'total'=>0,'page'=>$page,'per_page'=>$perPage]; }
}

final class InMemoryAccessSessionRepository implements AccessSessionRepositoryInterface {
    /** @var array<int,AccessSession> */ private array $items=[]; private int $next=1;
    public function create(array $data): AccessSession {
        $id=$this->next++; $uuid=sprintf('00000000-0000-4000-8000-%012d',$id);
        return $this->items[$id]=new AccessSession($id,$uuid,(int)$data['license_id'],(int)$data['user_id'],(string)$data['started_at'],(string)$data['last_seen_at'],(string)$data['expires_at'],null);
    }
    public function find(int $id): ?AccessSession { return $this->items[$id]??null; }
    public function findByUuid(string $uuid): ?AccessSession { foreach($this->items as $i) if($i->uuid()===$uuid)return $i; return null; }
    public function findActive(int $licenseId,int $userId,DateTimeImmutable $now):?AccessSession { foreach(array_reverse($this->items,true) as $i) if($i->licenseId()===$licenseId&&$i->userId()===$userId&&null===$i->endedAt()&&new DateTimeImmutable($i->expiresAt())>$now)return $i; return null; }
    public function touch(string $uuid,int $userId,DateTimeImmutable $lastSeenAt,DateTimeImmutable $expiresAt):AccessSession { $old=$this->findByUuid($uuid); if(!$old||$old->userId()!==$userId)throw new \RuntimeException(); $new=new AccessSession($old->id(),$old->uuid(),$old->licenseId(),$old->userId(),$old->startedAt(),$lastSeenAt->format('Y-m-d H:i:s'),$expiresAt->format('Y-m-d H:i:s'),$old->endedAt()); $this->items[$old->id()]=$new; return $new; }
    public function end(int $id,DateTimeImmutable $endedAt):void{}
    public function endForLicenseUser(int $licenseId,int $userId,DateTimeImmutable $endedAt):int{return 0;}
}
