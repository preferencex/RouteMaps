<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Access;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use RouteMaps\Core\Domain\Licensing\License;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use RuntimeException;

final class AccessSessionService {
    private const TTL_MINUTES = 30;

    public function __construct(
        private LicenseRepositoryInterface $licenses,
        private AccessSessionRepositoryInterface $sessions,
        private TransactionManager $transactions
    ) {
    }

    public function openOrReuse(License $license, int $userId, DateTimeImmutable $now): AccessSession {
        if ($userId <= 0) {
            throw new LogicException('access_session_user_required');
        }
        $now = $this->utc($now);

        return $this->transactions->run(function () use ($license, $userId, $now): AccessSession {
            $locked = $this->licenses->findForUpdate($license->id());
            if (null === $locked) {
                throw new RuntimeException('license_not_found');
            }

            $active = $this->sessions->findActive($locked->id(), $userId, $now);
            if (null !== $active) {
                return $this->sessions->touch($active->uuid(), $userId, $now, $this->expiresAt($now));
            }

            if (null !== $locked->maxOpenings() && $locked->openingsUsed() >= $locked->maxOpenings()) {
                throw new LogicException('openings_exhausted');
            }

            $this->licenses->incrementOpenings($locked->id(), $this->format($now));
            return $this->sessions->create([
                'license_id' => $locked->id(),
                'user_id' => $userId,
                'started_at' => $this->format($now),
                'last_seen_at' => $this->format($now),
                'expires_at' => $this->format($this->expiresAt($now)),
            ]);
        });
    }

    public function heartbeat(string $sessionUuid, int $userId, DateTimeImmutable $now): AccessSession {
        if ($userId <= 0) {
            throw new LogicException('access_session_not_active');
        }
        $now = $this->utc($now);
        $session = $this->sessions->findByUuid(strtolower(trim($sessionUuid)));
        if (null === $session || $session->userId() !== $userId || null !== $session->endedAt()) {
            throw new LogicException('access_session_not_active');
        }

        $expiresAt = new DateTimeImmutable($session->expiresAt(), new DateTimeZone('UTC'));
        if ($expiresAt <= $now) {
            throw new LogicException('access_session_not_active');
        }

        return $this->sessions->touch($session->uuid(), $userId, $now, $this->expiresAt($now));
    }

    private function expiresAt(DateTimeImmutable $now): DateTimeImmutable {
        return $now->add(new DateInterval('PT' . self::TTL_MINUTES . 'M'));
    }

    private function utc(DateTimeImmutable $date): DateTimeImmutable {
        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private function format(DateTimeImmutable $date): string {
        return $this->utc($date)->format('Y-m-d H:i:s');
    }
}
