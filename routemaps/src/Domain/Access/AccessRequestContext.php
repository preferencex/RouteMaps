<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Access;

use DateTimeImmutable;

final class AccessRequestContext {
    public function __construct(
        private int $userId,
        private ?string $publicToken,
        private ?string $licenseUuid,
        private int $routeId,
        private DateTimeImmutable $now
    ) {
    }

    public function userId(): int { return $this->userId; }
    public function publicToken(): ?string { return $this->publicToken; }
    public function licenseUuid(): ?string { return $this->licenseUuid; }
    public function routeId(): int { return $this->routeId; }
    public function now(): DateTimeImmutable { return $this->now; }

    public function withUserId(int $userId): self {
        return new self($userId, $this->publicToken, $this->licenseUuid, $this->routeId, $this->now);
    }

    public function withPublicToken(?string $token): self {
        return new self($this->userId, $token, $this->licenseUuid, $this->routeId, $this->now);
    }

    public function withLicenseUuid(?string $uuid): self {
        return new self($this->userId, $this->publicToken, $uuid, $this->routeId, $this->now);
    }

    public function withRouteId(int $routeId): self {
        return new self($this->userId, $this->publicToken, $this->licenseUuid, $routeId, $this->now);
    }
}
