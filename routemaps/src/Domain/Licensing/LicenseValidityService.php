<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Licensing;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class LicenseValidityService {
    public function evaluate(License $license, DateTimeImmutable $now): LicenseStatus {
        if (LicenseStatus::REVOKED === $license->status() || null !== $license->revokedAt()) {
            return LicenseStatus::REVOKED;
        }
        if (LicenseStatus::SUSPENDED === $license->status() || null !== $license->suspendedAt()) {
            return LicenseStatus::SUSPENDED;
        }

        $temporal = $this->temporalStatus($license, $now);
        if (null !== $temporal) {
            return $temporal;
        }

        if (null !== $license->maxOpenings() && $license->openingsUsed() >= $license->maxOpenings()) {
            return LicenseStatus::EXHAUSTED;
        }

        return LicenseStatus::PENDING === $license->status()
            ? LicenseStatus::PENDING
            : LicenseStatus::ACTIVE;
    }

    private function temporalStatus(License $license, DateTimeImmutable $now): ?LicenseStatus {
        return match ($license->validityMode()) {
            ValidityMode::UNLIMITED => null,
            ValidityMode::DAYS_FROM_PURCHASE => $this->boundedByStartAndEnd(
                $now,
                $this->date($license->validFrom()) ?? $this->date($license->createdAt()),
                $this->date($license->validUntil()) ?? $this->endAfterDays($license->createdAt(), $license->validityDays())
            ),
            ValidityMode::DAYS_FROM_FIRST_USE => $this->firstUseStatus($license, $now),
            ValidityMode::FIXED_RANGE => $this->boundedByStartAndEnd(
                $now,
                $this->date($license->validFrom()),
                $this->date($license->validUntil())
            ),
        };
    }

    private function firstUseStatus(License $license, DateTimeImmutable $now): ?LicenseStatus {
        if (null === $license->firstAccessAt()) {
            return null;
        }

        $start = $this->date($license->validFrom()) ?? $this->date($license->firstAccessAt());
        $end = $this->date($license->validUntil()) ?? $this->endAfterDays($license->firstAccessAt(), $license->validityDays());

        return $this->boundedByStartAndEnd($now, $start, $end);
    }

    private function boundedByStartAndEnd(
        DateTimeImmutable $now,
        ?DateTimeImmutable $start,
        ?DateTimeImmutable $end
    ): ?LicenseStatus {
        if (null === $start || null === $end || $end < $start) {
            return LicenseStatus::EXPIRED;
        }
        if ($now < $start) {
            return LicenseStatus::PENDING;
        }
        if ($now >= $end) {
            return LicenseStatus::EXPIRED;
        }

        return null;
    }

    private function endAfterDays(?string $start, ?int $days): ?DateTimeImmutable {
        if (null === $start || null === $days || $days <= 0) {
            return null;
        }
        $date = $this->date($start);
        if (null === $date) {
            return null;
        }

        return $date->add(new DateInterval('P' . $days . 'D'));
    }

    private function date(?string $value): ?DateTimeImmutable {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
