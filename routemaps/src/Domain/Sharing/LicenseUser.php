<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Sharing;

final class LicenseUser {
    public function __construct(
        private int $id,
        private int $licenseId,
        private ?int $userId,
        private string $email,
        private string $role,
        private string $status,
        private ?string $inviteTokenHash,
        private string $createdAt,
        private string $updatedAt
    ) {
    }

    public function id(): int { return $this->id; }
    public function licenseId(): int { return $this->licenseId; }
    public function userId(): ?int { return $this->userId; }
    public function email(): string { return $this->email; }
    public function role(): string { return $this->role; }
    public function status(): string { return $this->status; }
    public function inviteTokenHash(): ?string { return $this->inviteTokenHash; }
    public function createdAt(): string { return $this->createdAt; }
    public function updatedAt(): string { return $this->updatedAt; }
}
