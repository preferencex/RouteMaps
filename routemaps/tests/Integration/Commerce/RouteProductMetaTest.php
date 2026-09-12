<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Commerce;

use InvalidArgumentException;
use RouteMaps\Core\Commerce\Product\RouteProductMeta;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use WC_Product_Simple;
use WP_UnitTestCase;

final class RouteProductMetaTest extends WP_UnitTestCase {
    private WpdbRouteRepository $routes;
    private RouteProductMeta $meta;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        (new Migration001RoutesVersions())->up($wpdb);
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_routes');
        $this->routes = new WpdbRouteRepository($wpdb);
        $this->meta = new RouteProductMeta($this->routes);
    }

    public function test_disabled_product_returns_no_policy_and_clears_route_fields(): void {
        $route = $this->routes->create('Douro', 1);
        $product = $this->product();
        $this->meta->save($product, [
            '_routemaps_enabled' => 'yes',
            '_routemaps_route_id' => $route->id(),
            '_routemaps_validity_mode' => 'unlimited',
        ]);
        self::assertNotNull($this->meta->policyForProduct($product));

        $this->meta->save($product, ['_routemaps_enabled' => 'no']);

        self::assertNull($this->meta->policyForProduct($product));
        self::assertSame('', (string) $product->get_meta('_routemaps_route_id', true));
    }

    public function test_enabled_product_requires_existing_route(): void {
        $product = $this->product();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('route_product_route_invalid');
        $this->meta->save($product, [
            '_routemaps_enabled' => 'yes',
            '_routemaps_route_id' => 999999,
            '_routemaps_validity_mode' => 'unlimited',
        ]);
    }

    public function test_limits_are_nullable_or_non_negative(): void {
        $route = $this->routes->create('Douro', 1);
        $product = $this->product();
        $this->meta->save($product, [
            '_routemaps_enabled' => 'yes',
            '_routemaps_route_id' => $route->id(),
            '_routemaps_validity_mode' => 'unlimited',
            '_routemaps_max_openings' => '',
            '_routemaps_sharing_enabled' => 'yes',
            '_routemaps_max_shares' => '2',
        ]);
        $policy = $this->meta->policyForProduct($product);
        self::assertNotNull($policy);
        self::assertNull($policy->maxOpenings());
        self::assertSame(2, $policy->maxShares());

        $this->expectException(InvalidArgumentException::class);
        $this->meta->save($product, [
            '_routemaps_enabled' => 'yes',
            '_routemaps_route_id' => $route->id(),
            '_routemaps_validity_mode' => 'unlimited',
            '_routemaps_max_openings' => '-1',
        ]);
    }

    public function test_fixed_range_requires_both_dates_in_order(): void {
        $route = $this->routes->create('Douro', 1);
        $product = $this->product();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('route_product_fixed_range_invalid');
        $this->meta->save($product, [
            '_routemaps_enabled' => 'yes',
            '_routemaps_route_id' => $route->id(),
            '_routemaps_validity_mode' => 'fixed_range',
            '_routemaps_valid_from' => '2026-10-01',
            '_routemaps_valid_until' => '2026-09-01',
        ]);
    }

    public function test_policy_is_immutable_value_object_read_from_product_meta(): void {
        $route = $this->routes->create('Douro', 1);
        $product = $this->product();
        $this->meta->save($product, [
            '_routemaps_enabled' => 'yes',
            '_routemaps_route_id' => $route->id(),
            '_routemaps_validity_mode' => 'days_from_purchase',
            '_routemaps_validity_days' => '30',
            '_routemaps_max_openings' => '10',
            '_routemaps_sharing_enabled' => 'yes',
            '_routemaps_max_shares' => '2',
        ]);

        $policy = $this->meta->policyForProduct($product);
        self::assertNotNull($policy);
        self::assertSame($route->id(), $policy->routeId());
        self::assertSame(ValidityMode::DAYS_FROM_PURCHASE, $policy->validityMode());
        self::assertSame(30, $policy->validityDays());
        self::assertSame(10, $policy->maxOpenings());
        self::assertTrue($policy->sharingEnabled());
        self::assertSame(2, $policy->maxShares());
    }

    private function product(): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name('Rota teste');
        $product->set_regular_price('50');
        $product->set_virtual(true);
        $product->save();
        return $product;
    }
}
