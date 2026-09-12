<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Access;

use DateTimeImmutable;

interface AccessSessionRepositoryInterface {
    /** @param array<string,mixed> $data */
    public function create(array $data): AccessSession;
    public function find(int $id): ?AccessSession;
    public function findByUuid(string $uuid): ?AccessSession;
    public function findActive(int $licenseId, int $userId, DateTimeImmutable $now): ?AccessSession;
    public function touch(string $uuid, int $userId, DateTimeImmutable $lastSeenAt, DateTimeImmutable $expiresAt): AccessSession;
    public function end(int $id, DateTimeImmutable $endedAt): void;
    public function endForLicenseUser(int $licenseId, int $userId, DateTimeImmutable $endedAt): int;
}
