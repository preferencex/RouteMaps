<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Sharing;

use LogicException;
use RouteMaps\Core\Security\TokenService;
use RuntimeException;

final class ShareAcceptanceService {
    public function __construct(
        private LicenseUserRepositoryInterface $members,
        private TokenService $tokens
    ) {
    }

    public function accept(string $plainToken, int $userId): LicenseUser {
        if (!$this->tokens->isValidPlainToken($plainToken)) {
            throw new LogicException('share_invite_invalid');
        }

        $member = $this->members->findByInviteTokenHash($this->tokens->hash($plainToken));
        if (null === $member || 'pending' !== $member->status()) {
            throw new LogicException('share_invite_invalid');
        }

        $user = get_userdata($userId);
        if (!$user) {
            throw new LogicException('share_user_invalid');
        }
        $userEmail = strtolower(trim((string) $user->user_email));
        if ('' === $userEmail || !hash_equals($member->email(), $userEmail)) {
            throw new LogicException('share_email_mismatch');
        }

        try {
            $accepted = $this->members->activate($member->id(), $userId, $this->now());
        } catch (RuntimeException $error) {
            if ('license_user_not_pending' === $error->getMessage()) {
                throw new LogicException('share_invite_invalid', 0, $error);
            }
            throw $error;
        }
        do_action('routemaps_share_accepted', $accepted);

        return $accepted;
    }

    private function now(): string {
        return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
    }
}
