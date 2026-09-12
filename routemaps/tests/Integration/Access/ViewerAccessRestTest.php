<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Access;

use DateTimeImmutable;
use RouteMaps\Core\Commerce\Email\RouteShareInviteEmail;
use RouteMaps\Core\Domain\Access\AccessDecisionService;
use RouteMaps\Core\Domain\Access\AccessSessionService;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\LicenseValidityService;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Domain\Sharing\ShareInviteService;
use RouteMaps\Core\Domain\Sharing\ShareRevocationService;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessEventRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessSessionRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseUserRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteVersionRepository;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration003Licenses;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration004AccessSharing;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use RouteMaps\Core\Rest\ViewerAccessController;
use RouteMaps\Core\Rest\ViewerSharesController;
use RouteMaps\Core\Security\TokenService;
use RouteMaps\Core\Support\RouteUrl;
use WP_REST_Request;
use WP_UnitTestCase;

final class ViewerAccessRestTest extends WP_UnitTestCase {
    public function test_resolve_returns_safe_contract_and_replaces_token_url(): void {
        [$controller, , $license, $route, $plainToken] = $this->scenario(ValidityMode::UNLIMITED);
        wp_set_current_user($license->ownerUserId());

        $request = new WP_REST_Request('POST', '/routemaps/v1/access/resolve');
        $request->set_param('token', $plainToken);
        $response = $controller->resolve($request);
        $data = $response->get_data();

        self::assertTrue($data['allowed']);
        self::assertSame($route->uuid(), $data['route_uuid']);
        self::assertSame($license->uuid(), $data['license_uuid']);
        self::assertSame($route->currentPublishedVersionId(), $data['version_id']);
        self::assertNotEmpty($data['session_uuid']);
        self::assertStringNotContainsString($plainToken, $data['canonical_url']);
        self::assertStringContainsString('/routemaps/app/' . $route->uuid(), $data['canonical_url']);
        self::assertArrayNotHasKey('public_token_hash', $data['license']);
    }

    public function test_token_resolution_is_rate_limited_with_hashed_bucket_and_private_headers(): void {
        [$controller, , $license, , $plainToken] = $this->scenario(ValidityMode::UNLIMITED);
        wp_set_current_user($license->ownerUserId());
        $captured = null;
        $filter = static function (mixed $pre, string $bucket) use (&$captured): bool {
            if (str_starts_with($bucket, 'access_token:')) {
                $captured = $bucket;
                return false;
            }
            return true;
        };
        add_filter('routemaps_rate_limiter_pre_consume', $filter, 10, 2);
        try {
            $request = new WP_REST_Request('POST', '/routemaps/v1/access/resolve');
            $request->set_param('token', $plainToken);
            $response = $controller->resolve($request);
        } finally {
            remove_filter('routemaps_rate_limiter_pre_consume', $filter, 10);
        }

        self::assertSame(['allowed' => false, 'reason' => 'rate_limited'], $response->get_data());
        self::assertMatchesRegularExpression('/^access_token:[0-9a-f]{64}$/', (string) $captured);
        self::assertStringNotContainsString($plainToken, (string) $captured);
        self::assertStringContainsString('private', strtolower((string) $response->get_headers()['Cache-Control']));
        self::assertStringContainsString('no-store', strtolower((string) $response->get_headers()['Cache-Control']));
    }

    public function test_denied_contract_contains_stable_reason_only(): void {
        [$controller, , $license] = $this->scenario(ValidityMode::UNLIMITED);
        wp_set_current_user($license->ownerUserId());
        $request = new WP_REST_Request('POST', '/routemaps/v1/access/resolve');
        $request->set_param('token', 'bad');

        $data = $controller->resolve($request)->get_data();
        self::assertSame(['allowed' => false, 'reason' => 'invalid_token'], $data);
    }

    public function test_first_use_is_activated_before_session_and_heartbeat_is_owner_scoped(): void {
        [$controller, , $license, , $plainToken, $licenses] = $this->scenario(ValidityMode::DAYS_FROM_FIRST_USE);
        wp_set_current_user($license->ownerUserId());
        $resolve = new WP_REST_Request('POST', '/routemaps/v1/access/resolve');
        $resolve->set_param('token', $plainToken);
        $data = $controller->resolve($resolve)->get_data();

        self::assertNotNull($licenses->find($license->id())?->firstAccessAt());
        $heartbeat = new WP_REST_Request('POST', '/routemaps/v1/viewer/sessions/heartbeat');
        $heartbeat->set_param('session_uuid', $data['session_uuid']);
        $beat = $controller->heartbeat($heartbeat)->get_data();
        self::assertSame($data['session_uuid'], $beat['session_uuid']);
    }

    public function test_owner_can_invite_and_revoke_through_share_endpoint(): void {
        [$access, $shares, $license] = $this->scenario(ValidityMode::UNLIMITED);
        unset($access);
        wp_set_current_user($license->ownerUserId());
        $captured = [];
        $mail = static function (mixed $return, array $atts) use (&$captured): bool { $captured[] = $atts; return true; };
        add_filter('pre_wp_mail', $mail, 10, 2);
        try {
            $request = new WP_REST_Request('POST', '/routemaps/v1/viewer/shares');
            $request->set_param('license_uuid', $license->uuid());
            $request->set_param('email', 'guest@example.test');
            $created = $shares->create($request)->get_data();
            self::assertSame('pending', $created['status']);
            self::assertSame('guest@example.test', $created['email']);
            self::assertCount(1, $captured);

            $delete = new WP_REST_Request('DELETE', '/routemaps/v1/viewer/shares/' . $created['id']);
            $delete->set_param('id', $created['id']);
            self::assertSame(204, $shares->delete($delete)->get_status());
        } finally {
            remove_filter('pre_wp_mail', $mail, 10);
        }
    }

    /** @return array{0:ViewerAccessController,1:ViewerSharesController,2:mixed,3:mixed,4:string,5:WpdbLicenseRepository} */
    private function scenario(ValidityMode $mode): array {
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [
            new Migration001RoutesVersions(),
            new Migration002PoisCategories(),
            new Migration003Licenses(),
            new Migration004AccessSharing(),
        ]))->migrate();
        foreach ([
            'routemaps_access_events',
            'routemaps_access_sessions',
            'routemaps_license_users',
            'routemaps_licenses',
            'routemaps_route_versions',
            'routemaps_routes',
        ] as $suffix) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $suffix);
        }

        $routes = new WpdbRouteRepository($wpdb);
        $versions = new WpdbRouteVersionRepository($wpdb);
        $licenses = new WpdbLicenseRepository($wpdb);
        $members = new WpdbLicenseUserRepository($wpdb);
        $sessions = new WpdbAccessSessionRepository($wpdb);
        $events = new WpdbAccessEventRepository($wpdb);
        $owner = self::factory()->user->create(['user_email' => 'owner-rest@example.test']);
        $route = $routes->create('REST Route', $owner);
        $draft = $versions->createDraft($route->id(), 1, '{"geometry":null,"stops":[]}', $owner);
        $versions->updateSnapshot($draft->id(), $draft->snapshotJson(), hash('sha256', $draft->snapshotJson()));
        $published = $versions->markPublished($draft->id(), false, null, '2026-09-12 00:00:00');
        $route = $routes->updatePublishedVersion($route->id(), $published->id());
        $plain = str_repeat('c', 64);
        $license = $licenses->create([
            'public_token_hash' => hash('sha256', $plain), 'order_id' => 101, 'order_item_id' => random_int(1000, 999999),
            'product_id' => 303, 'route_id' => $route->id(), 'owner_user_id' => $owner, 'status' => LicenseStatus::ACTIVE,
            'validity_mode' => $mode, 'validity_days' => $mode === ValidityMode::DAYS_FROM_FIRST_USE ? 7 : null,
            'max_openings' => 3, 'openings_used' => 0, 'sharing_enabled' => true, 'max_shares' => 1,
        ]);
        $members->create(['license_id'=>$license->id(),'user_id'=>$owner,'email'=>'owner-rest@example.test','role'=>'owner','status'=>'active']);
        $validity = new LicenseValidityService();
        $tokens = new TokenService();
        $decision = new AccessDecisionService($licenses, $members, $sessions, $events, $validity, $tokens);
        $sessionService = new AccessSessionService($licenses, $sessions, new TransactionManager($wpdb));
        $access = new ViewerAccessController($decision, $sessionService, $licenses, $routes, $members, $validity, $tokens);
        $invite = new ShareInviteService($licenses, $members, $tokens, new RouteShareInviteEmail($routes, new RouteUrl()), new TransactionManager($GLOBALS['wpdb']));
        $revoke = new ShareRevocationService($licenses, $members, $sessions);
        $shares = new ViewerSharesController($licenses, $invite, $revoke);
        return [$access, $shares, $license, $route, $plain, $licenses];
    }
}