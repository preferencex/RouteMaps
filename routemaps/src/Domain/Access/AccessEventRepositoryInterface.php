<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Access;

use DateTimeImmutable;

interface AccessEventRepositoryInterface {
    /** @param array<string,mixed> $metadata */
    public function record(
        ?int $licenseId,
        ?int $userId,
        string $eventType,
        ?string $reasonCode,
        array $metadata,
        DateTimeImmutable $createdAt
    ): void;

    /** @return list<array<string,mixed>> */
    public function forLicense(int $licenseId, int $limit = 100): array;
}
