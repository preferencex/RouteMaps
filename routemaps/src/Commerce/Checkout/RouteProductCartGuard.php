<?php

declare(strict_types=1);

namespace RouteMaps\Core\Commerce\Checkout;

use RouteMaps\Core\Commerce\Product\RouteProductMeta;
use WC_Cart;
use WC_Product;

final class RouteProductCartGuard {
    public function __construct(private RouteProductMeta $products) {
    }

    public function registerHooks(): void {
        add_filter('woocommerce_checkout_registration_required', [$this, 'registrationRequired'], 20, 1);
        add_filter('woocommerce_checkout_registration_enabled', [$this, 'registrationEnabled'], 20, 1);
    }

    public function containsRouteProduct(WC_Cart $cart): bool {
        foreach ($cart->get_cart() as $item) {
            if (!is_array($item)) {
                continue;
            }
            $product = $item['data'] ?? null;
            if (!$product instanceof WC_Product && isset($item['product_id']) && function_exists('wc_get_product')) {
                $product = wc_get_product((int) $item['product_id']);
            }
            if ($product instanceof WC_Product && $this->products->isRouteProduct($product)) {
                return true;
            }
        }

        return false;
    }

    public function registrationRequired(bool $required, ?WC_Cart $cart = null): bool {
        if (is_user_logged_in()) {
            return $required;
        }
        $cart = $cart ?? $this->currentCart();
        return null !== $cart && $this->containsRouteProduct($cart) ? true : $required;
    }

    public function registrationEnabled(bool $enabled, ?WC_Cart $cart = null): bool {
        if (is_user_logged_in()) {
            return $enabled;
        }
        $cart = $cart ?? $this->currentCart();
        return null !== $cart && $this->containsRouteProduct($cart) ? true : $enabled;
    }

    private function currentCart(): ?WC_Cart {
        if (!function_exists('WC')) {
            return null;
        }
        $woocommerce = WC();
        return isset($woocommerce->cart) && $woocommerce->cart instanceof WC_Cart
            ? $woocommerce->cart
            : null;
    }
}
