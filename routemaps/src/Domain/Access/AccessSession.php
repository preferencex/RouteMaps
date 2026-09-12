<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Access;

final class AccessSession {
    public function __construct(
        private int $id,
        private string $uuid,
        private int $licenseId,
        private int $userId,
        private string $startedAt,
        private string $lastSeenAt,
        private string $expiresAt,
        private ?string $endedAt
    ) {
    }

    public function id(): int { return $this->id; }
    public function uuid(): string { return $this->uuid; }
    public function licenseId(): int { return $this->licenseId; }
    public function userId(): int { return $this->userId; }
    public function startedAt(): string { return $this->startedAt; }
    public function lastSeenAt(): string { return $this->lastSeenAt; }
    public function expiresAt(): string { return $this->expiresAt; }
    public function endedAt(): ?string { return $this->endedAt; }
}
