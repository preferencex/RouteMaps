<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Sharing;

use LogicException;
use RouteMaps\Core\Commerce\Email\RouteShareInviteEmail;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;
use RouteMaps\Core\Security\TokenService;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;

final class ShareInviteService {
    public function __construct(
        private LicenseRepositoryInterface $licenses,
        private LicenseUserRepositoryInterface $members,
        private TokenService $tokens,
        private RouteShareInviteEmail $email,
        private TransactionManager $transactions
    ) {
    }

    public function invite(int $licenseId, int $actorUserId, string $email): LicenseUser {
        $normalizedEmail = strtolower(trim($email));
        if ('' === $normalizedEmail || !is_email($normalizedEmail)) {
            throw new LogicException('share_email_invalid');
        }

        /** @var array{0:\RouteMaps\Core\Domain\Licensing\License,1:LicenseUser,2:array{plain:string,hash:string}} $created */
        $created = $this->transactions->run(function () use ($licenseId, $actorUserId, $normalizedEmail): array {
            $license = $this->licenses->findForUpdate($licenseId);
            if (null === $license) {
                throw new LogicException('license_not_found');
            }
            if (!$this->canManage($license->ownerUserId(), $actorUserId)) {
                throw new LogicException('share_forbidden');
            }
            if (!$license->sharingEnabled() || $license->maxShares() <= 0) {
                throw new LogicException('sharing_disabled');
            }

            $owner = get_userdata($license->ownerUserId());
            $ownerEmail = $owner ? strtolower(trim((string) $owner->user_email)) : '';
            if ('' !== $ownerEmail && hash_equals($ownerEmail, $normalizedEmail)) {
                throw new LogicException('share_owner_email');
            }

            if (null !== $this->members->findPendingOrActiveByEmail($licenseId, $normalizedEmail)) {
                throw new LogicException('share_duplicate_email');
            }
            if ($this->members->countGuestsByStatuses($licenseId, ['pending', 'active']) >= $license->maxShares()) {
                throw new LogicException('share_limit_reached');
            }

            $token = $this->tokens->generate();
            $member = $this->members->create([
                'license_id' => $licenseId,
                'user_id' => null,
                'email' => $normalizedEmail,
                'role' => 'guest',
                'status' => 'pending',
                'invite_token_hash' => $token['hash'],
            ]);

            return [$license, $member, $token];
        });

        [$license, $member, $token] = $created;

        try {
            $this->email->send($license, $member, $token['plain']);
        } catch (\Throwable $error) {
            // The plain token is intentionally never persisted. A failed delivery
            // invalidates this pending row and frees the share slot.
            $this->members->revoke($member->id(), $this->now());
            throw $error;
        }

        do_action('routemaps_share_created', $member, $license);

        return $member;
    }

    private function canManage(int $ownerUserId, int $actorUserId): bool {
        if ($ownerUserId === $actorUserId) {
            return true;
        }
        return function_exists('user_can') && user_can($actorUserId, 'manage_routemaps_licenses');
    }

    private function now(): string {
        return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
    }
}
