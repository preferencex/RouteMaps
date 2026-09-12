<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Licensing;

use DateTimeImmutable;
use InvalidArgumentException;

final class LicensePolicy {
    public function __construct(
        private int $routeId,
        private ValidityMode $validityMode,
        private ?int $validityDays = null,
        private ?DateTimeImmutable $validFrom = null,
        private ?DateTimeImmutable $validUntil = null,
        private ?int $maxOpenings = null,
        private bool $sharingEnabled = false,
        private int $maxShares = 0
    ) {
        if ($this->routeId <= 0) {
            throw new InvalidArgumentException('license_route_required');
        }
        if (in_array($this->validityMode, [ValidityMode::DAYS_FROM_PURCHASE, ValidityMode::DAYS_FROM_FIRST_USE], true)
            && (null === $this->validityDays || $this->validityDays <= 0)) {
            throw new InvalidArgumentException('license_validity_days_required');
        }
        if (ValidityMode::FIXED_RANGE === $this->validityMode) {
            if (null === $this->validFrom || null === $this->validUntil || $this->validFrom > $this->validUntil) {
                throw new InvalidArgumentException('license_fixed_range_invalid');
            }
        }
        if (null !== $this->maxOpenings && $this->maxOpenings < 0) {
            throw new InvalidArgumentException('license_max_openings_invalid');
        }
        if ($this->maxShares < 0) {
            throw new InvalidArgumentException('license_max_shares_invalid');
        }
    }

    public function routeId(): int { return $this->routeId; }
    public function validityMode(): ValidityMode { return $this->validityMode; }
    public function validityDays(): ?int { return $this->validityDays; }
    public function validFrom(): ?DateTimeImmutable { return $this->validFrom; }
    public function validUntil(): ?DateTimeImmutable { return $this->validUntil; }
    public function maxOpenings(): ?int { return $this->maxOpenings; }
    public function sharingEnabled(): bool { return $this->sharingEnabled; }
    public function maxShares(): int { return $this->maxShares; }
}
