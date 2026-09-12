<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Access;

use DateTimeImmutable;
use LogicException;
use RouteMaps\Core\Domain\Access\AccessSessionService;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration003Licenses;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration004AccessSharing;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessSessionRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseRepository;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use WP_UnitTestCase;

final class OpeningConcurrencyTest extends WP_UnitTestCase {
    public function test_max_one_opening_cannot_be_exceeded_by_competing_users(): void {
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb,[new Migration001RoutesVersions(),new Migration002PoisCategories(),new Migration003Licenses(),new Migration004AccessSharing()]))->migrate();
        $wpdb->query('DELETE FROM '.$wpdb->prefix.'routemaps_access_sessions');
        $wpdb->query('DELETE FROM '.$wpdb->prefix.'routemaps_licenses');
        $licenses=new WpdbLicenseRepository($wpdb);
        $license=$licenses->create(['public_token_hash'=>hash('sha256',str_repeat('a',64)),'order_id'=>10,'order_item_id'=>20,'product_id'=>30,'route_id'=>40,'owner_user_id'=>50,'status'=>LicenseStatus::ACTIVE,'validity_mode'=>ValidityMode::UNLIMITED,'max_openings'=>1,'openings_used'=>0,'sharing_enabled'=>true,'max_shares'=>1]);
        $service=new AccessSessionService($licenses,new WpdbAccessSessionRepository($wpdb),new TransactionManager($wpdb));
        $now=new DateTimeImmutable('2026-09-11 20:00:00');
        $service->openOrReuse($license,50,$now);
        try { $service->openOrReuse($license,51,$now); self::fail('Second user must not exceed max_openings=1.'); }
        catch(LogicException $e){ self::assertSame('openings_exhausted',$e->getMessage()); }
        self::assertSame(1,$licenses->find($license->id())?->openingsUsed());
    }
}
