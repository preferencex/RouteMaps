<?php

declare(strict_types=1);

namespace RouteMaps\Core\Rest;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use RouteMaps\Core\Domain\Access\AccessDecisionService;
use RouteMaps\Core\Domain\Access\AccessReason;
use RouteMaps\Core\Domain\Access\AccessRequestContext;
use RouteMaps\Core\Domain\Access\AccessSessionService;
use RouteMaps\Core\Domain\Licensing\License;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\LicenseValidityService;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use RouteMaps\Core\Domain\Sharing\LicenseUserRepositoryInterface;
use RouteMaps\Core\Security\RateLimiter;
use RouteMaps\Core\Security\TokenService;
use RouteMaps\Core\Security\ViewerHeaders;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ViewerAccessController {
    private const NAMESPACE = 'routemaps/v1';
    private const ACCESS_TOKEN_LIMIT = 20;
    private const ACCESS_TOKEN_WINDOW = 300;

    private RateLimiter $rateLimiter;
    private ViewerHeaders $headers;

    public function __construct(
        private AccessDecisionService $access,
        private AccessSessionService $sessions,
        private LicenseRepositoryInterface $licenses,
        private RouteRepositoryInterface $routes,
        private LicenseUserRepositoryInterface $members,
        private LicenseValidityService $validity,
        private TokenService $tokens,
        ?RateLimiter $rateLimiter = null,
        ?ViewerHeaders $headers = null
    ) {
        $this->rateLimiter = $rateLimiter ?? new RateLimiter();
        $this->headers = $headers ?? new ViewerHeaders();
    }

    public function registerRoutes(): void {
        register_rest_route(self::NAMESPACE, '/access/resolve', [
            'methods' => 'POST',
            'callback' => [$this, 'resolve'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE, '/viewer/sessions/heartbeat', [
            'methods' => 'POST',
            'callback' => [$this, 'heartbeat'],
            'permission_callback' => [$this, 'requireLogin'],
        ]);
        register_rest_route(self::NAMESPACE, '/viewer/license', [
            'methods' => 'GET',
            'callback' => [$this, 'license'],
            'permission_callback' => [$this, 'requireLogin'],
        ]);
    }

    public function requireLogin(): true|WP_Error {
        return is_user_logged_in()
            ? true
            : new WP_Error('rest_not_logged_in', __('Authentication required.', 'routemaps'), ['status' => 401]);
    }

    public function resolve(WP_REST_Request $request): WP_REST_Response {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $userId = get_current_user_id();
        $token = strtolower(trim((string) ($request->get_param('token') ?? '')));
        $licenseUuid = strtolower(trim((string) ($request->get_param('license_uuid') ?? '')));
        $routeUuid = strtolower(trim((string) ($request->get_param('route_uuid') ?? '')));
        if ('' !== $token && !$this->rateLimiter->consume(
            $this->rateLimiter->bucket('access_token', $token),
            self::ACCESS_TOKEN_LIMIT,
            self::ACCESS_TOKEN_WINDOW
        )) {
            return $this->denied('rate_limited');
        }
        $routeId = $this->resolveRouteId($token, $licenseUuid, $routeUuid, $userId);

        $decision = $this->access->decide(new AccessRequestContext(
            $userId,
            '' !== $token ? $token : null,
            '' !== $licenseUuid ? $licenseUuid : null,
            $routeId,
            $now
        ));
        if (!$decision->allowed() || null === $decision->license()) {
            return $this->denied($decision->reason()->value ?? AccessReason::INVALID_TOKEN->value);
        }

        $license = $decision->license();
        if (ValidityMode::DAYS_FROM_FIRST_USE === $license->validityMode() && null === $license->firstAccessAt()) {
            $stamp = $now->format('Y-m-d H:i:s');
            $license = $this->licenses->setFirstAccessIfEmpty($license->id(), $stamp, $stamp);
            $effective = $this->validity->evaluate($license, $now);
            if (LicenseStatus::ACTIVE !== $effective) {
                return $this->denied($this->reasonForStatus($effective));
            }
        }

        try {
            $session = $this->sessions->openOrReuse($license, $userId, $now);
        } catch (LogicException $error) {
            return $this->denied('openings_exhausted' === $error->getMessage() ? 'openings_exhausted' : 'not_authorized');
        }

        $route = $this->routes->find($license->routeId());
        if (null === $route || null === $route->currentPublishedVersionId()) {
            return $this->denied('route_mismatch');
        }
        $fresh = $this->licenses->find($license->id()) ?? $license;

        return $this->headers->applyToRestResponse(new WP_REST_Response([
            'allowed' => true,
            'route_uuid' => $route->uuid(),
            'license_uuid' => $fresh->uuid(),
            'version_id' => $route->currentPublishedVersionId(),
            'session_uuid' => $session->uuid(),
            'canonical_url' => add_query_arg(
                ['license' => $fresh->uuid()],
                home_url('/routemaps/app/' . rawurlencode($route->uuid()))
            ),
            'license' => $this->safeLicense($fresh, $now),
        ], 200));
    }

    public function heartbeat(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $session = $this->sessions->heartbeat(
                strtolower(trim((string) ($request->get_param('session_uuid') ?? ''))),
                get_current_user_id(),
                new DateTimeImmutable('now', new DateTimeZone('UTC'))
            );
        } catch (LogicException $error) {
            return new WP_Error('access_session_not_active', __('The RouteMaps session is no longer active.', 'routemaps'), ['status' => 409]);
        }
        return $this->headers->applyToRestResponse(new WP_REST_Response(
            ['session_uuid' => $session->uuid(), 'expires_at' => $session->expiresAt()],
            200
        ));
    }

    public function license(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $uuid = strtolower(trim((string) ($request->get_param('license_uuid') ?? '')));
        $license = $this->licenses->findByUuid($uuid);
        if (null === $license) {
            return new WP_Error('license_not_found', __('RouteMaps license not found.', 'routemaps'), ['status' => 404]);
        }
        $userId = get_current_user_id();
        $isOwner = $license->ownerUserId() === $userId;
        if (!$isOwner && null === $this->members->findMembership($license->id(), $userId)) {
            return new WP_Error('not_authorized', __('You cannot access this RouteMaps license.', 'routemaps'), ['status' => 403]);
        }
        $route = $this->routes->find($license->routeId());
        $data = [
            'license_uuid' => $license->uuid(),
            'route_uuid' => $route?->uuid(),
            'version_id' => $route?->currentPublishedVersionId(),
            'license' => $this->safeLicense($license, new DateTimeImmutable('now', new DateTimeZone('UTC'))),
        ];
        if ($isOwner) {
            $data['shares'] = array_map(
                static fn ($member): array => [
                    'id' => $member->id(),
                    'email' => $member->email(),
                    'status' => $member->status(),
                    'user_id' => $member->userId(),
                ],
                $this->members->listGuests($license->id())
            );
        }
        return $this->headers->applyToRestResponse(new WP_REST_Response($data, 200));
    }

    private function resolveRouteId(string $token, string $licenseUuid, string $routeUuid, int $userId): int {
        if ('' !== $routeUuid) {
            return $this->routes->findByUuid($routeUuid)?->id() ?? 0;
        }
        if ('' !== $token && $this->tokens->isValidPlainToken($token)) {
            return $this->licenses->findByTokenHash($this->tokens->hash($token))?->routeId() ?? 0;
        }
        if ($userId > 0 && '' !== $licenseUuid) {
            return $this->licenses->findByUuid($licenseUuid)?->routeId() ?? 0;
        }
        return 0;
    }

    /** @return array<string,mixed> */
    private function safeLicense(License $license, DateTimeImmutable $now): array {
        return [
            'status' => $this->validity->evaluate($license, $now)->value,
            'validity_mode' => $license->validityMode()->value,
            'validity_days' => $license->validityDays(),
            'valid_from' => $license->validFrom(),
            'valid_until' => $license->validUntil(),
            'first_access_at' => $license->firstAccessAt(),
            'max_openings' => $license->maxOpenings(),
            'openings_used' => $license->openingsUsed(),
            'sharing_enabled' => $license->sharingEnabled(),
            'max_shares' => $license->maxShares(),
        ];
    }

    private function reasonForStatus(LicenseStatus $status): string {
        return match ($status) {
            LicenseStatus::SUSPENDED => 'license_suspended',
            LicenseStatus::REVOKED => 'license_revoked',
            LicenseStatus::EXHAUSTED => 'openings_exhausted',
            default => 'license_expired',
        };
    }

    private function denied(string $reason): WP_REST_Response {
        return $this->headers->applyToRestResponse(
            new WP_REST_Response(['allowed' => false, 'reason' => $reason], 200)
        );
    }
}
