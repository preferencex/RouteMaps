<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Access;

use RouteMaps\Core\Domain\Licensing\License;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\LicenseValidityService;
use RouteMaps\Core\Domain\Sharing\LicenseUserRepositoryInterface;
use RouteMaps\Core\Security\TokenService;

final class AccessDecisionService {
    public function __construct(
        private LicenseRepositoryInterface $licenses,
        private LicenseUserRepositoryInterface $members,
        private AccessSessionRepositoryInterface $sessions,
        private AccessEventRepositoryInterface $events,
        private LicenseValidityService $validity,
        private TokenService $tokens
    ) {
    }

    public function decide(AccessRequestContext $context): AccessDecision {
        $license = $this->resolveLicense($context);
        if ($license instanceof AccessDecision) {
            return $license;
        }

        if ($context->userId() <= 0) {
            return $this->deny(AccessReason::NOT_AUTHENTICATED, $context, $license);
        }

        if (!$this->isAuthorizedUser($license, $context->userId())) {
            return $this->deny(AccessReason::NOT_AUTHORIZED, $context, $license);
        }

        if ($context->routeId() <= 0 || $license->routeId() !== $context->routeId()) {
            return $this->deny(AccessReason::ROUTE_MISMATCH, $context, $license);
        }

        if (LicenseStatus::REVOKED === $license->status() || null !== $license->revokedAt()) {
            return $this->deny(AccessReason::LICENSE_REVOKED, $context, $license);
        }

        if (LicenseStatus::SUSPENDED === $license->status() || null !== $license->suspendedAt()) {
            return $this->deny(AccessReason::LICENSE_SUSPENDED, $context, $license);
        }

        $effective = $this->validity->evaluate($license, $context->now());
        if (LicenseStatus::EXPIRED === $effective || LicenseStatus::PENDING === $effective) {
            return $this->deny(AccessReason::LICENSE_EXPIRED, $context, $license);
        }
        if (LicenseStatus::EXHAUSTED === $effective) {
            $active = $this->sessions->findActive($license->id(), $context->userId(), $context->now());
            if (null === $active) {
                return $this->deny(AccessReason::OPENINGS_EXHAUSTED, $context, $license);
            }
        }

        return $this->allow($context, $license);
    }

    private function resolveLicense(AccessRequestContext $context): License|AccessDecision {
        $plainToken = $context->publicToken();
        if (null !== $plainToken) {
            if (!$this->tokens->isValidPlainToken($plainToken)) {
                return $this->deny(AccessReason::INVALID_TOKEN, $context, null);
            }
            $license = $this->licenses->findByTokenHash($this->tokens->hash($plainToken));
            return $license ?? $this->deny(AccessReason::INVALID_TOKEN, $context, null);
        }

        $uuid = $context->licenseUuid();
        if (null === $uuid || '' === trim($uuid)) {
            return $this->deny(AccessReason::INVALID_TOKEN, $context, null);
        }
        if ($context->userId() <= 0) {
            return $this->deny(AccessReason::NOT_AUTHENTICATED, $context, null);
        }

        $license = $this->licenses->findByUuid(strtolower(trim($uuid)));
        return $license ?? $this->deny(AccessReason::INVALID_TOKEN, $context, null);
    }

    private function isAuthorizedUser(License $license, int $userId): bool {
        if ($license->ownerUserId() === $userId) {
            return true;
        }
        return null !== $this->members->findMembership($license->id(), $userId);
    }

    private function allow(AccessRequestContext $context, License $license): AccessDecision {
        $this->events->record(
            $license->id(),
            $context->userId(),
            'access_granted',
            null,
            ['route_id' => $context->routeId()],
            $context->now()
        );
        do_action('routemaps_access_granted', $license, $context->userId());
        return AccessDecision::allow($license);
    }

    private function deny(AccessReason $reason, AccessRequestContext $context, ?License $license): AccessDecision {
        $this->events->record(
            $license?->id(),
            $context->userId() > 0 ? $context->userId() : null,
            'access_denied',
            $reason->value,
            [],
            $context->now()
        );
        do_action('routemaps_access_denied', $reason->value, $license?->id(), $context->userId());
        return AccessDecision::deny($reason);
    }
}
