<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Licensing;

final class License {
    public function __construct(
        private int $id,
        private string $uuid,
        private string $publicTokenHash,
        private int $orderId,
        private int $orderItemId,
        private int $productId,
        private int $routeId,
        private int $ownerUserId,
        private LicenseStatus $status,
        private ValidityMode $validityMode,
        private ?int $validityDays,
        private ?string $validFrom,
        private ?string $validUntil,
        private ?string $firstAccessAt,
        private ?int $maxOpenings,
        private int $openingsUsed,
        private bool $sharingEnabled,
        private int $maxShares,
        private ?string $suspendedAt,
        private ?string $revokedAt,
        private string $createdAt,
        private string $updatedAt
    ) {
    }

    public function id(): int { return $this->id; }
    public function uuid(): string { return $this->uuid; }
    public function publicTokenHash(): string { return $this->publicTokenHash; }
    public function orderId(): int { return $this->orderId; }
    public function orderItemId(): int { return $this->orderItemId; }
    public function productId(): int { return $this->productId; }
    public function routeId(): int { return $this->routeId; }
    public function ownerUserId(): int { return $this->ownerUserId; }
    public function status(): LicenseStatus { return $this->status; }
    public function validityMode(): ValidityMode { return $this->validityMode; }
    public function validityDays(): ?int { return $this->validityDays; }
    public function validFrom(): ?string { return $this->validFrom; }
    public function validUntil(): ?string { return $this->validUntil; }
    public function firstAccessAt(): ?string { return $this->firstAccessAt; }
    public function maxOpenings(): ?int { return $this->maxOpenings; }
    public function openingsUsed(): int { return $this->openingsUsed; }
    public function sharingEnabled(): bool { return $this->sharingEnabled; }
    public function maxShares(): int { return $this->maxShares; }
    public function suspendedAt(): ?string { return $this->suspendedAt; }
    public function revokedAt(): ?string { return $this->revokedAt; }
    public function createdAt(): string { return $this->createdAt; }
    public function updatedAt(): string { return $this->updatedAt; }
}
