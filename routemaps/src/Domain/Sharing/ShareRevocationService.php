<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Sharing;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use RouteMaps\Core\Domain\Access\AccessSessionRepositoryInterface;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;

final class ShareRevocationService {
    public function __construct(
        private LicenseRepositoryInterface $licenses,
        private LicenseUserRepositoryInterface $members,
        private AccessSessionRepositoryInterface $sessions
    ) {
    }

    public function revoke(int $licenseUserId, int $actorUserId): void {
        $member = $this->members->find($licenseUserId);
        if (null === $member || 'guest' !== $member->role()) {
            throw new LogicException('share_not_found');
        }
        $license = $this->licenses->find($member->licenseId());
        if (null === $license) {
            throw new LogicException('license_not_found');
        }
        if (!$this->canManage($license->ownerUserId(), $actorUserId)) {
            throw new LogicException('share_forbidden');
        }
        if ('revoked' === $member->status()) {
            return;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $revoked = $this->members->revoke($member->id(), $now->format('Y-m-d H:i:s'));
        if (null !== $member->userId()) {
            $this->sessions->endForLicenseUser($member->licenseId(), $member->userId(), $now);
        }

        do_action('routemaps_share_revoked', $revoked, $license);
    }

    private function canManage(int $ownerUserId, int $actorUserId): bool {
        if ($ownerUserId === $actorUserId) {
            return true;
        }
        return function_exists('user_can') && user_can($actorUserId, 'manage_routemaps_licenses');
    }
}
