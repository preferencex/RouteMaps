<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Commerce;

use RouteMaps\Core\Commerce\Checkout\RouteProductCartGuard;
use RouteMaps\Core\Commerce\Product\RouteProductMeta;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use WC_Cart;
use WC_Product_Simple;
use WP_UnitTestCase;

final class RouteProductCartGuardTest extends WP_UnitTestCase {
    private WpdbRouteRepository $routes;
    private RouteProductMeta $meta;
    private RouteProductCartGuard $guard;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        (new Migration001RoutesVersions())->up($wpdb);
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_routes');
        $this->routes = new WpdbRouteRepository($wpdb);
        $this->meta = new RouteProductMeta($this->routes);
        $this->guard = new RouteProductCartGuard($this->meta);
    }

    public function test_normal_product_preserves_existing_guest_checkout_settings(): void {
        $cart = $this->cartWith($this->product(false));
        self::assertFalse($this->guard->containsRouteProduct($cart));
        self::assertFalse($this->guard->registrationRequired(false, $cart));
        self::assertFalse($this->guard->registrationEnabled(false, $cart));
    }

    public function test_route_only_cart_forces_account_creation_for_guest(): void {
        wp_set_current_user(0);
        $cart = $this->cartWith($this->product(true));
        self::assertTrue($this->guard->containsRouteProduct($cart));
        self::assertTrue($this->guard->registrationRequired(false, $cart));
        self::assertTrue($this->guard->registrationEnabled(false, $cart));
    }

    public function test_mixed_cart_also_forces_account_creation(): void {
        wp_set_current_user(0);
        $cart = $this->cartWith($this->product(false), $this->product(true));
        self::assertTrue($this->guard->registrationRequired(false, $cart));
    }

    public function test_logged_in_user_does_not_have_registration_flags_overridden(): void {
        $userId = self::factory()->user->create(['role' => 'customer']);
        wp_set_current_user($userId);
        $cart = $this->cartWith($this->product(true));
        self::assertFalse($this->guard->registrationRequired(false, $cart));
        self::assertFalse($this->guard->registrationEnabled(false, $cart));
    }

    public function test_guard_does_not_replace_woocommerce_email_validation(): void {
        self::assertFalse(method_exists($this->guard, 'validateEmail'));
    }

    private function product(bool $routeProduct): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name($routeProduct ? 'Rota' : 'Produto normal');
        $product->set_regular_price('10');
        $product->set_virtual($routeProduct);
        $product->save();
        if ($routeProduct) {
            $route = $this->routes->create('Rota ' . $product->get_id(), 1);
            $this->meta->save($product, [
                RouteProductMeta::ENABLED => 'yes',
                RouteProductMeta::ROUTE_ID => $route->id(),
                RouteProductMeta::VALIDITY_MODE => 'unlimited',
            ]);
        }
        return $product;
    }

    private function cartWith(WC_Product_Simple ...$products): WC_Cart {
        $cart = new WC_Cart();
        foreach ($products as $product) {
            $cart->add_to_cart($product->get_id(), 1);
        }
        return $cart;
    }
}
