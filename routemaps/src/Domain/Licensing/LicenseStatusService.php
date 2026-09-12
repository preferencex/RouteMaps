<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Licensing;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use RuntimeException;

final class LicenseStatusService {
    public function __construct(
        private LicenseRepositoryInterface $licenses,
        private LicenseValidityService $validity
    ) {
    }

    public function effectiveStatus(License $license, DateTimeImmutable $now): LicenseStatus {
        return $this->validity->evaluate($license, $now);
    }

    public function suspend(int $licenseId, ?DateTimeImmutable $now = null): License {
        $now = $this->utc($now);
        $license = $this->required($licenseId);
        if (LicenseStatus::REVOKED === $license->status()) {
            throw new LogicException('license_revoked_terminal');
        }
        if (LicenseStatus::ACTIVE !== $this->effectiveStatus($license, $now)) {
            throw new LogicException('license_not_active');
        }

        return $this->transition($license, LicenseStatus::SUSPENDED, $now, $this->format($now), $license->revokedAt());
    }

    public function reactivate(int $licenseId, ?DateTimeImmutable $now = null): License {
        $now = $this->utc($now);
        $license = $this->required($licenseId);
        if (LicenseStatus::REVOKED === $license->status() || null !== $license->revokedAt()) {
            throw new LogicException('license_revoked_terminal');
        }
        if (LicenseStatus::SUSPENDED !== $license->status() && null === $license->suspendedAt()) {
            throw new LogicException('license_not_suspended');
        }

        return $this->transition($license, LicenseStatus::ACTIVE, $now, null, null);
    }

    public function revoke(int $licenseId, ?DateTimeImmutable $now = null): License {
        $now = $this->utc($now);
        $license = $this->required($licenseId);
        if (LicenseStatus::REVOKED === $license->status() || null !== $license->revokedAt()) {
            throw new LogicException('license_revoked_terminal');
        }
        if (!in_array($license->status(), [LicenseStatus::ACTIVE, LicenseStatus::SUSPENDED], true)) {
            throw new LogicException('license_not_revocable');
        }

        return $this->transition(
            $license,
            LicenseStatus::REVOKED,
            $now,
            $license->suspendedAt(),
            $this->format($now)
        );
    }

    private function required(int $licenseId): License {
        $license = $this->licenses->find($licenseId);
        if (null === $license) {
            throw new RuntimeException('license_not_found');
        }
        return $license;
    }

    private function transition(
        License $before,
        LicenseStatus $target,
        DateTimeImmutable $now,
        ?string $suspendedAt,
        ?string $revokedAt
    ): License {
        $after = $this->licenses->updateLifecycleStatus(
            $before->id(),
            $target,
            $suspendedAt,
            $revokedAt,
            $this->format($now)
        );

        do_action('routemaps_license_status_changed', $after, $before->status(), $target);
        return $after;
    }

    private function utc(?DateTimeImmutable $now): DateTimeImmutable {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    private function format(DateTimeImmutable $date): string {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
