<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Access;

use DateTimeImmutable;
use RouteMaps\Core\Commerce\Email\RouteShareInviteEmail;
use RouteMaps\Core\Domain\Access\AccessDecisionService;
use RouteMaps\Core\Domain\Access\AccessReason;
use RouteMaps\Core\Domain\Access\AccessRequestContext;
use RouteMaps\Core\Domain\Access\AccessSessionService;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\LicenseValidityService;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Domain\Sharing\ShareAcceptanceService;
use RouteMaps\Core\Domain\Sharing\ShareInviteService;
use RouteMaps\Core\Domain\Sharing\ShareRevocationService;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration003Licenses;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration004AccessSharing;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessEventRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessSessionRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseUserRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteVersionRepository;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use RouteMaps\Core\Security\RateLimiter;
use RouteMaps\Core\Security\TokenService;
use RouteMaps\Core\Support\RouteUrl;
use RouteMaps\Core\Viewer\LoginController;
use WP_UnitTestCase;

final class Plan04AcceptanceTest extends WP_UnitTestCase {
    public function test_two_openings_are_session_based_and_third_session_is_denied(): void {
        [$license, $route, $plain, $licenses, $members, $sessions, $decision, $sessionService] = $this->scenario('owner-openings@example.test');
        $t0 = new DateTimeImmutable('2026-09-12 08:00:00');
        $owner = $license->ownerUserId();

        $anonymous = $decision->decide(new AccessRequestContext(0, $plain, null, $route->id(), $t0));
        self::assertSame(AccessReason::NOT_AUTHENTICATED, $anonymous->reason());

        $firstDecision = $decision->decide(new AccessRequestContext($owner, $plain, null, $route->id(), $t0));
        self::assertTrue($firstDecision->allowed());
        $first = $sessionService->openOrReuse($firstDecision->license(), $owner, $t0);
        $sessionService->heartbeat($first->uuid(), $owner, $t0->modify('+10 minutes'));
        $reused = $sessionService->openOrReuse($licenses->find($license->id()), $owner, $t0->modify('+20 minutes'));
        self::assertSame($first->uuid(), $reused->uuid());
        self::assertSame(1, $licenses->find($license->id())?->openingsUsed());

        $secondDecision = $decision->decide(new AccessRequestContext($owner, null, $license->uuid(), $route->id(), $t0->modify('+51 minutes')));
        self::assertTrue($secondDecision->allowed());
        $second = $sessionService->openOrReuse($secondDecision->license(), $owner, $t0->modify('+51 minutes'));
        self::assertNotSame($first->uuid(), $second->uuid());
        self::assertSame(2, $licenses->find($license->id())?->openingsUsed());

        $third = $decision->decide(new AccessRequestContext($owner, null, $license->uuid(), $route->id(), $t0->modify('+82 minutes')));
        self::assertSame(AccessReason::OPENINGS_EXHAUSTED, $third->reason());
        unset($members, $sessions);
    }

    public function test_guest_invite_accept_access_and_revoke_terminates_session(): void {
        [$license, $route, , $licenses, $members, $sessions, $decision, $sessionService, $routes] = $this->scenario('owner-sharing@example.test');
        $tokens = new TokenService();
        $mail = [];
        $capture = static function (mixed $return, array $atts) use (&$mail): bool { $mail[] = $atts; return true; };
        add_filter('pre_wp_mail', $capture, 10, 2);
        try {
            $invite = new ShareInviteService($licenses, $members, $tokens, new RouteShareInviteEmail($routes, new RouteUrl()), new TransactionManager($GLOBALS['wpdb']));
            $acceptance = new ShareAcceptanceService($members, $tokens);
            $revoke = new ShareRevocationService($licenses, $members, $sessions);
            $guest = $invite->invite($license->id(), $license->ownerUserId(), 'guest-plan04@example.test');
            self::assertSame('pending', $guest->status());
            preg_match('#/routemaps/invite/([0-9a-f]{64})#', $mail[0]['message'], $match);
            self::assertArrayHasKey(1, $match);

            $guestId = self::factory()->user->create(['user_email' => 'guest-plan04@example.test']);
            $login = new LoginController(new RateLimiter(), $acceptance, $licenses, $routes);
            $appUrl = $login->acceptInvite($match[1], $guestId);
            self::assertStringContainsString('/routemaps/app/' . $route->uuid(), $appUrl);
            self::assertStringContainsString($license->uuid(), $appUrl);

            $at = new DateTimeImmutable('2026-09-12 10:00:00');
            $access = $decision->decide(new AccessRequestContext($guestId, null, $license->uuid(), $route->id(), $at));
            self::assertTrue($access->allowed());
            $session = $sessionService->openOrReuse($access->license(), $guestId, $at);
            self::assertNull($sessions->find($session->id())?->endedAt());

            $revoke->revoke($guest->id(), $license->ownerUserId());
            self::assertNotNull($sessions->find($session->id())?->endedAt());
            $after = $decision->decide(new AccessRequestContext($guestId, null, $license->uuid(), $route->id(), $at->modify('+1 minute')));
            self::assertFalse($after->allowed());
        } finally {
            remove_filter('pre_wp_mail', $capture, 10);
        }
    }

    private function scenario(string $ownerEmail): array {
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [new Migration001RoutesVersions(),new Migration002PoisCategories(),new Migration003Licenses(),new Migration004AccessSharing()]))->migrate();
        foreach (['routemaps_access_events','routemaps_access_sessions','routemaps_license_users','routemaps_licenses','routemaps_route_versions','routemaps_routes'] as $suffix) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $suffix);
        }
        $owner = self::factory()->user->create(['user_email' => $ownerEmail]);
        $routes = new WpdbRouteRepository($wpdb);
        $versions = new WpdbRouteVersionRepository($wpdb);
        $route = $routes->create('Plan 04 Route', $owner);
        $draft = $versions->createDraft($route->id(), 1, '{"geometry":null,"stops":[]}', $owner);
        $versions->updateSnapshot($draft->id(), $draft->snapshotJson(), hash('sha256', $draft->snapshotJson()));
        $published = $versions->markPublished($draft->id(), false, null, '2026-09-12 00:00:00');
        $route = $routes->updatePublishedVersion($route->id(), $published->id());
        $plain = bin2hex(random_bytes(32));
        $licenses = new WpdbLicenseRepository($wpdb);
        $license = $licenses->create([
            'public_token_hash'=>hash('sha256',$plain),'order_id'=>801,'order_item_id'=>random_int(100000,999999),'product_id'=>803,
            'route_id'=>$route->id(),'owner_user_id'=>$owner,'status'=>LicenseStatus::ACTIVE,'validity_mode'=>ValidityMode::UNLIMITED,
            'max_openings'=>2,'openings_used'=>0,'sharing_enabled'=>true,'max_shares'=>1,
        ]);
        $members = new WpdbLicenseUserRepository($wpdb);
        $members->create(['license_id'=>$license->id(),'user_id'=>$owner,'email'=>$ownerEmail,'role'=>'owner','status'=>'active']);
        $sessions = new WpdbAccessSessionRepository($wpdb);
        $events = new WpdbAccessEventRepository($wpdb);
        $decision = new AccessDecisionService($licenses,$members,$sessions,$events,new LicenseValidityService(),new TokenService());
        $sessionService = new AccessSessionService($licenses,$sessions,new TransactionManager($wpdb));
        return [$license,$route,$plain,$licenses,$members,$sessions,$decision,$sessionService,$routes];
    }
}
