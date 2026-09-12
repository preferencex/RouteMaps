<?php

declare(strict_types=1);

namespace RouteMaps\Core\Rest;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use RouteMaps\Core\Domain\Licensing\License;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\LicenseStatusService;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminLicensesController {
    private const NAMESPACE = 'routemaps/v1';

    public function __construct(
        private LicenseRepositoryInterface $licenses,
        private LicenseStatusService $statuses
    ) {
    }

    public function registerRoutes(): void {
        register_rest_route(self::NAMESPACE, '/admin/licenses', [
            'methods' => 'GET',
            'callback' => [$this, 'index'],
            'permission_callback' => [$this, 'canManage'],
            'args' => [
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                'owner' => ['type' => 'integer', 'minimum' => 1],
                'email' => ['type' => 'string', 'sanitize_callback' => 'sanitize_email'],
                'route' => ['type' => 'integer', 'minimum' => 1],
                'order' => ['type' => 'integer', 'minimum' => 1],
                'status' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                    'enum' => ['active', 'pending', 'suspended', 'expired', 'exhausted', 'revoked'],
                ],
                'validity' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                    'enum' => ['unlimited', 'days_from_purchase', 'days_from_first_use', 'fixed_range'],
                ],
            ],
        ]);

        foreach (['suspend', 'reactivate', 'revoke'] as $action) {
            register_rest_route(self::NAMESPACE, '/admin/licenses/(?P<id>\d+)/' . $action, [
                'methods' => 'POST',
                'callback' => [$this, $action],
                'permission_callback' => [$this, 'canManage'],
            ]);
        }
    }

    public function canManage(): bool|WP_Error {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_not_logged_in', __('Authentication required.', 'routemaps'), ['status' => 401]);
        }
        if (!current_user_can('manage_routemaps_licenses')) {
            return new WP_Error('rest_forbidden', __('You cannot manage RouteMaps licenses.', 'routemaps'), ['status' => 403]);
        }
        return true;
    }

    public function index(WP_REST_Request $request): WP_REST_Response {
        $page = max(1, (int) ($request->get_param('page') ?? 1));
        $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?? 20)));
        $filters = array_filter([
            'owner_user_id' => (int) ($request->get_param('owner') ?? 0),
            'email' => (string) ($request->get_param('email') ?? ''),
            'route_id' => (int) ($request->get_param('route') ?? 0),
            'order_id' => (int) ($request->get_param('order') ?? 0),
            'validity' => (string) ($request->get_param('validity') ?? ''),
        ], static fn (mixed $value): bool => !in_array($value, [0, ''], true));
        $status = sanitize_key((string) ($request->get_param('status') ?? ''));

        if ('' === $status) {
            $result = $this->licenses->search($filters, $page, $perPage);
            $items = array_map(fn (License $license): array => $this->licenseData($license), $result['items']);
            return new WP_REST_Response([
                'items' => $items,
                'total' => $result['total'],
                'page' => $page,
                'per_page' => $perPage,
            ], 200);
        }

        $matched = [];
        $scanPage = 1;
        do {
            $result = $this->licenses->search($filters, $scanPage, 100);
            foreach ($result['items'] as $license) {
                if ($this->effectiveStatus($license)->value === $status) {
                    $matched[] = $license;
                }
            }
            ++$scanPage;
        } while (($scanPage - 1) * 100 < $result['total']);

        $offset = ($page - 1) * $perPage;
        $items = array_slice($matched, $offset, $perPage);
        return new WP_REST_Response([
            'items' => array_map(fn (License $license): array => $this->licenseData($license), $items),
            'total' => count($matched),
            'page' => $page,
            'per_page' => $perPage,
        ], 200);
    }

    public function suspend(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return $this->transition('suspend', (int) $request->get_param('id'));
    }

    public function reactivate(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return $this->transition('reactivate', (int) $request->get_param('id'));
    }

    public function revoke(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return $this->transition('revoke', (int) $request->get_param('id'));
    }

    private function transition(string $action, int $licenseId): WP_REST_Response|WP_Error {
        try {
            $license = match ($action) {
                'suspend' => $this->statuses->suspend($licenseId),
                'reactivate' => $this->statuses->reactivate($licenseId),
                'revoke' => $this->statuses->revoke($licenseId),
                default => throw new LogicException('license_transition_invalid'),
            };
        } catch (RuntimeException|LogicException $exception) {
            $status = 'license_not_found' === $exception->getMessage() ? 404 : 409;
            return new WP_Error(
                $exception->getMessage(),
                __('The RouteMaps license status could not be changed.', 'routemaps'),
                ['status' => $status]
            );
        }

        return new WP_REST_Response($this->licenseData($license), 200);
    }

    /** @return array<string,mixed> */
    private function licenseData(License $license): array {
        $user = get_userdata($license->ownerUserId());
        return [
            'id' => $license->id(),
            'uuid' => $license->uuid(),
            'owner_user_id' => $license->ownerUserId(),
            'owner_email' => $user ? (string) $user->user_email : '',
            'route_id' => $license->routeId(),
            'order_id' => $license->orderId(),
            'order_item_id' => $license->orderItemId(),
            'product_id' => $license->productId(),
            'status' => $this->effectiveStatus($license)->value,
            'stored_status' => $license->status()->value,
            'validity_mode' => $license->validityMode()->value,
            'validity_days' => $license->validityDays(),
            'valid_from' => $license->validFrom(),
            'valid_until' => $license->validUntil(),
            'first_access_at' => $license->firstAccessAt(),
            'max_openings' => $license->maxOpenings(),
            'openings_used' => $license->openingsUsed(),
            'sharing_enabled' => $license->sharingEnabled(),
            'max_shares' => $license->maxShares(),
            'suspended_at' => $license->suspendedAt(),
            'revoked_at' => $license->revokedAt(),
            'created_at' => $license->createdAt(),
            'updated_at' => $license->updatedAt(),
        ];
    }

    private function effectiveStatus(License $license): LicenseStatus {
        return $this->statuses->effectiveStatus(
            $license,
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );
    }
}
