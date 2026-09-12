<?php

declare(strict_types=1);

namespace RouteMaps\Core\Rest;

use DateTimeImmutable;
use DateTimeZone;
use RouteMaps\Core\Domain\Access\AccessDecisionService;
use RouteMaps\Core\Domain\Access\AccessRequestContext;
use RouteMaps\Core\Domain\Access\AccessSessionRepositoryInterface;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;
use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use RouteMaps\Core\Domain\Versions\PublishedRouteSnapshotProvider;
use RouteMaps\Core\Security\ViewerHeaders;
use RouteMaps\Core\Viewer\ExtensionRegistry;
use RouteMaps\Core\Viewer\ViewerContext;
use RouteMaps\Core\Viewer\ViewerPayloadFactory;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ViewerRouteController {
    private const NAMESPACE = 'routemaps/v1';

    private ViewerHeaders $headers;

    public function __construct(
        private RouteRepositoryInterface $routes,
        private LicenseRepositoryInterface $licenses,
        private AccessSessionRepositoryInterface $sessions,
        private AccessDecisionService $access,
        private PublishedRouteSnapshotProvider $snapshots,
        private ViewerPayloadFactory $payloads,
        private ExtensionRegistry $extensions,
        ?ViewerHeaders $headers = null
    ) {
        $this->headers = $headers ?? new ViewerHeaders();
    }

    public function registerRoutes(): void {
        register_rest_route(self::NAMESPACE, '/viewer/routes/(?P<route_uuid>[0-9a-f-]{36})', [
            'methods' => 'GET',
            'callback' => [$this, 'route'],
            'permission_callback' => [$this, 'requireLogin'],
            'args' => [
                'route_uuid' => ['type' => 'string', 'required' => true],
                'license_uuid' => ['type' => 'string', 'required' => true],
                'session_uuid' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function requireLogin(): true|WP_Error {
        return is_user_logged_in()
            ? true
            : new WP_Error('rest_not_logged_in', __('Authentication required.', 'routemaps'), ['status' => 401]);
    }

    public function route(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $userId = get_current_user_id();
        $routeUuid = strtolower(trim((string) ($request->get_param('route_uuid') ?? '')));
        $licenseUuid = strtolower(trim((string) ($request->get_param('license_uuid') ?? '')));
        $sessionUuid = strtolower(trim((string) ($request->get_param('session_uuid') ?? '')));
        $route = $this->routes->findByUuid($routeUuid);
        $license = $this->licenses->findByUuid($licenseUuid);
        if (null === $route || null === $license || $license->routeId() !== $route->id()) {
            return $this->forbidden('not_authorized');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $session = $this->sessions->findByUuid($sessionUuid);
        if (null === $session
            || $session->licenseId() !== $license->id()
            || $session->userId() !== $userId
            || null !== $session->endedAt()
            || new DateTimeImmutable($session->expiresAt(), new DateTimeZone('UTC')) <= $now
        ) {
            return $this->forbidden('access_session_not_active');
        }

        $decision = $this->access->decide(new AccessRequestContext($userId, null, $licenseUuid, $route->id(), $now));
        if (!$decision->allowed()) {
            return $this->forbidden($decision->reason()->value ?? 'not_authorized');
        }

        $versionId = (int) ($route->currentPublishedVersionId() ?? 0);
        $filteredVersion = apply_filters('routemaps_route_version_resolved', $versionId, $route, $license, $userId);
        $versionId = is_numeric($filteredVersion) ? (int) $filteredVersion : 0;
        if ($versionId <= 0) {
            return new WP_Error('route_version_unavailable', __('Published RouteMaps version unavailable.', 'routemaps'), ['status' => 404]);
        }

        $context = new ViewerContext($userId, $license->id(), $route->id(), $versionId, $session->uuid());
        $this->extensions->bootExtensions();
        try {
            $snapshot = $this->snapshots->get_published_snapshot($route->id(), $versionId);
        } catch (RuntimeException $error) {
            return new WP_Error('route_version_unavailable', __('Published RouteMaps version unavailable.', 'routemaps'), ['status' => 404]);
        }
        $payload = $this->payloads->build($route, $snapshot, $context);
        return $this->headers->applyToRestResponse(new WP_REST_Response($payload->data(), 200));
    }

    private function forbidden(string $reason): WP_REST_Response {
        return $this->headers->applyToRestResponse(
            new WP_REST_Response(['allowed' => false, 'reason' => $reason], 403)
        );
    }
}
