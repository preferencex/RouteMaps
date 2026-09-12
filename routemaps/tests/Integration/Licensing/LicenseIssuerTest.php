<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Licensing;

use RouteMaps\Core\Commerce\Product\RouteProductMeta;
use RouteMaps\Core\Domain\Licensing\LicenseIssuer;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration003Licenses;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use RouteMaps\Core\Security\TokenService;
use WC_Order;
use WC_Product_Simple;
use WP_UnitTestCase;

final class LicenseIssuerTest extends WP_UnitTestCase {
    public function test_same_paid_order_item_is_issued_once_and_event_fires_once(): void {
        [$issuer, $licenses, $order, $itemId] = $this->scenario();
        $events = 0;
        $listener = static function () use (&$events): void { ++$events; };
        add_action('routemaps_license_created', $listener, 10, 1);

        $first = $issuer->issue_for_order_item($order->get_id(), $itemId);
        $second = $issuer->issue_for_order_item($order->get_id(), $itemId);

        remove_action('routemaps_license_created', $listener, 10);
        self::assertSame($first->license()->id(), $second->license()->id());
        self::assertTrue($first->wasCreated());
        self::assertNotNull($first->plainToken());
        self::assertFalse($second->wasCreated());
        self::assertNull($second->plainToken());
        self::assertSame(1, $events);
        self::assertSame(1, $licenses->search(['order_id' => $order->get_id()], 1, 20)['total']);
    }

    public function test_issued_license_keeps_original_commercial_policy_after_product_change(): void {
        [$issuer, $licenses, $order, $itemId, $product, $meta] = $this->scenario();
        $result = $issuer->issue_for_order_item($order->get_id(), $itemId);
        $issued = $result->license();

        $meta->save($product, [
            RouteProductMeta::ENABLED => 'yes',
            RouteProductMeta::ROUTE_ID => $issued->routeId(),
            RouteProductMeta::VALIDITY_MODE => 'days_from_purchase',
            RouteProductMeta::VALIDITY_DAYS => 7,
            RouteProductMeta::MAX_OPENINGS => 1,
            RouteProductMeta::SHARING_ENABLED => 'no',
            RouteProductMeta::MAX_SHARES => 0,
        ]);

        $persisted = $licenses->find($issued->id());
        self::assertNotNull($persisted);
        self::assertSame(30, $persisted->validityDays());
        self::assertSame(10, $persisted->maxOpenings());
        self::assertTrue($persisted->sharingEnabled());
        self::assertSame(2, $persisted->maxShares());
    }

    /** @return array{LicenseIssuer,WpdbLicenseRepository,WC_Order,int,WC_Product_Simple,RouteProductMeta} */
    private function scenario(): array {
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [new Migration001RoutesVersions(), new Migration002PoisCategories(), new Migration003Licenses()]))->migrate();
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_licenses');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_routes');

        $routes = new WpdbRouteRepository($wpdb);
        $licenses = new WpdbLicenseRepository($wpdb);
        $meta = new RouteProductMeta($routes);
        $route = $routes->create('Douro', 1);
        $product = new WC_Product_Simple();
        $product->set_name('Douro');
        $product->set_regular_price('50');
        $product->set_virtual(true);
        $product->save();
        $meta->save($product, [
            RouteProductMeta::ENABLED => 'yes',
            RouteProductMeta::ROUTE_ID => $route->id(),
            RouteProductMeta::VALIDITY_MODE => 'days_from_purchase',
            RouteProductMeta::VALIDITY_DAYS => 30,
            RouteProductMeta::MAX_OPENINGS => 10,
            RouteProductMeta::SHARING_ENABLED => 'yes',
            RouteProductMeta::MAX_SHARES => 2,
        ]);

        $userId = self::factory()->user->create(['role' => 'customer']);
        $order = wc_create_order(['customer_id' => $userId]);
        $itemId = $order->add_product($product, 1);
        $order->set_status('processing');
        $order->save();

        $issuer = new LicenseIssuer($licenses, $meta, new TokenService(), new TransactionManager($wpdb));
        return [$issuer, $licenses, $order, $itemId, $product, $meta];
    }
}
