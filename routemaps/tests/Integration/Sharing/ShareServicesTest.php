<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Sharing;

use DateTimeImmutable;
use LogicException;
use RouteMaps\Core\Commerce\Email\RouteShareInviteEmail;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Domain\Sharing\ShareAcceptanceService;
use RouteMaps\Core\Domain\Sharing\ShareInviteService;
use RouteMaps\Core\Domain\Sharing\ShareRevocationService;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration003Licenses;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration004AccessSharing;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessSessionRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseUserRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Security\TokenService;
use RouteMaps\Core\Support\RouteUrl;
use WP_UnitTestCase;

final class ShareServicesTest extends WP_UnitTestCase {
    public function test_limits_duplicates_owner_email_acceptance_and_revocation(): void {
        [$license, $ownerId, $routes, $licenses, $members, $sessions] = $this->scenario(true, 2);
        $email = new RouteShareInviteEmail($routes, new RouteUrl());
        $invite = new ShareInviteService($licenses, $members, new TokenService(), $email, new TransactionManager($GLOBALS['wpdb']));
        $accept = new ShareAcceptanceService($members, new TokenService());
        $revoke = new ShareRevocationService($licenses, $members, $sessions);

        try { $invite->invite($license->id(), $ownerId, get_userdata($ownerId)->user_email); self::fail('Owner cannot invite self.'); }
        catch (LogicException $e) { self::assertSame('share_owner_email', $e->getMessage()); }

        $mail = [];
        $capture = static function (mixed $return, array $atts) use (&$mail): bool { $mail[]=$atts; return true; };
        add_filter('pre_wp_mail', $capture, 10, 2);
        $guest1 = $invite->invite($license->id(), $ownerId, 'Guest@One.test');
        self::assertSame('guest@one.test', $guest1->email());
        try { $invite->invite($license->id(), $ownerId, 'GUEST@ONE.TEST'); self::fail('Duplicate invite must fail.'); }
        catch (LogicException $e) { self::assertSame('share_duplicate_email', $e->getMessage()); }
        $guest2 = $invite->invite($license->id(), $ownerId, 'guest2@example.test');
        self::assertSame(2, $members->countGuestsByStatuses($license->id(), ['pending','active']));
        try { $invite->invite($license->id(), $ownerId, 'third@example.test'); self::fail('Third share must exceed max=2.'); }
        catch (LogicException $e) { self::assertSame('share_limit_reached', $e->getMessage()); }
        remove_filter('pre_wp_mail', $capture, 10);
        self::assertCount(2, $mail);

        preg_match('#/routemaps/invite/([0-9a-f]{64})#', $mail[0]['message'], $tokenMatch);
        self::assertArrayHasKey(1, $tokenMatch);
        $plainToken = $tokenMatch[1];
        $wrongUser = self::factory()->user->create(['user_email'=>'wrong@example.test']);
        try { $accept->accept($plainToken, $wrongUser); self::fail('Wrong email cannot accept invite.'); }
        catch (LogicException $e) { self::assertSame('share_email_mismatch', $e->getMessage()); }

        $guestUser = self::factory()->user->create(['user_email'=>'guest@one.test']);
        $accepted = $accept->accept($plainToken, $guestUser);
        self::assertSame('active', $accepted->status());
        self::assertSame($guestUser, $accepted->userId());
        self::assertNull($accepted->inviteTokenHash());
        try { $accept->accept($plainToken, $guestUser); self::fail('Invite token replay must fail.'); }
        catch (LogicException $e) { self::assertSame('share_invite_invalid', $e->getMessage()); }

        $session = $sessions->create(['license_id'=>$license->id(),'user_id'=>$guestUser,'started_at'=>'2026-09-11 20:00:00','last_seen_at'=>'2026-09-11 20:00:00','expires_at'=>'2099-01-01 00:00:00']);
        $revoke->revoke($accepted->id(), $ownerId);
        self::assertSame('revoked', $members->find($accepted->id())?->status());
        self::assertNotNull($sessions->find($session->id())?->endedAt());
        try { $revoke->revoke($accepted->id(), $wrongUser); self::fail('Revoked share must still require an authorized actor.'); }
        catch (LogicException $e) { self::assertSame('share_forbidden', $e->getMessage()); }
        self::assertSame(1, $members->countGuestsByStatuses($license->id(), ['pending','active']));
        self::assertSame('pending', $members->find($guest2->id())?->status());
    }

    public function test_sharing_disabled_rejects_invite(): void {
        [$license, $ownerId, $routes, $licenses, $members] = $this->scenario(false, 0);
        $invite = new ShareInviteService($licenses, $members, new TokenService(), new RouteShareInviteEmail($routes, new RouteUrl()), new TransactionManager($GLOBALS['wpdb']));
        try { $invite->invite($license->id(), $ownerId, 'guest@example.test'); self::fail('Disabled sharing must reject.'); }
        catch (LogicException $e) { self::assertSame('sharing_disabled', $e->getMessage()); }
    }

    private function scenario(bool $sharing, int $maxShares): array {
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb,[new Migration001RoutesVersions(),new Migration002PoisCategories(),new Migration003Licenses(),new Migration004AccessSharing()]))->migrate();
        foreach (['routemaps_access_sessions','routemaps_license_users','routemaps_licenses','routemaps_routes'] as $suffix) $wpdb->query('DELETE FROM '.$wpdb->prefix.$suffix);
        $ownerId=self::factory()->user->create(['user_email'=>'owner@example.test']);
        $routes=new WpdbRouteRepository($wpdb); $route=$routes->create('Douro',$ownerId);
        $licenses=new WpdbLicenseRepository($wpdb);
        $license=$licenses->create(['public_token_hash'=>hash('sha256',str_repeat('a',64)),'order_id'=>1,'order_item_id'=>2,'product_id'=>3,'route_id'=>$route->id(),'owner_user_id'=>$ownerId,'status'=>LicenseStatus::ACTIVE,'validity_mode'=>ValidityMode::UNLIMITED,'max_openings'=>10,'openings_used'=>0,'sharing_enabled'=>$sharing,'max_shares'=>$maxShares]);
        $members=new WpdbLicenseUserRepository($wpdb);
        $members->create(['license_id'=>$license->id(),'user_id'=>$ownerId,'email'=>'owner@example.test','role'=>'owner','status'=>'active']);
        return [$license,$ownerId,$routes,$licenses,$members,new WpdbAccessSessionRepository($wpdb)];
    }
}
