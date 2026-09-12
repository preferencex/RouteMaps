<?php

declare(strict_types=1);

namespace RouteMaps\Core\Rest;

use LogicException;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;
use RouteMaps\Core\Domain\Sharing\LicenseUser;
use RouteMaps\Core\Domain\Sharing\ShareInviteService;
use RouteMaps\Core\Domain\Sharing\ShareRevocationService;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ViewerSharesController {
    private const NAMESPACE = 'routemaps/v1';

    public function __construct(
        private LicenseRepositoryInterface $licenses,
        private ShareInviteService $invite,
        private ShareRevocationService $revoke
    ) {
    }

    public function registerRoutes(): void {
        register_rest_route(self::NAMESPACE, '/viewer/shares', [
            'methods' => 'POST',
            'callback' => [$this, 'create'],
            'permission_callback' => [$this, 'requireLogin'],
        ]);
        register_rest_route(self::NAMESPACE, '/viewer/shares/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete'],
            'permission_callback' => [$this, 'requireLogin'],
        ]);
    }

    public function requireLogin(): true|WP_Error {
        return is_user_logged_in()
            ? true
            : new WP_Error('rest_not_logged_in', __('Authentication required.', 'routemaps'), ['status' => 401]);
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $uuid = strtolower(trim((string) ($request->get_param('license_uuid') ?? '')));
        $license = $this->licenses->findByUuid($uuid);
        if (null === $license) {
            return new WP_Error('license_not_found', __('RouteMaps license not found.', 'routemaps'), ['status' => 404]);
        }
        try {
            $member = $this->invite->invite(
                $license->id(),
                get_current_user_id(),
                (string) ($request->get_param('email') ?? '')
            );
        } catch (LogicException|RuntimeException $error) {
            return $this->error($error->getMessage());
        }
        return new WP_REST_Response($this->memberData($member), 201);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $this->revoke->revoke((int) $request->get_param('id'), get_current_user_id());
        } catch (LogicException|RuntimeException $error) {
            return $this->error($error->getMessage());
        }
        return new WP_REST_Response(null, 204);
    }

    /** @return array{id:int,email:string,status:string,user_id:?int} */
    private function memberData(LicenseUser $member): array {
        return ['id' => $member->id(), 'email' => $member->email(), 'status' => $member->status(), 'user_id' => $member->userId()];
    }

    private function error(string $code): WP_Error {
        $status = match ($code) {
            'license_not_found', 'share_not_found' => 404,
            'share_forbidden' => 403,
            'share_email_invalid' => 400,
            default => 409,
        };
        return new WP_Error($code, __('The RouteMaps share request could not be completed.', 'routemaps'), ['status' => $status]);
    }
}
