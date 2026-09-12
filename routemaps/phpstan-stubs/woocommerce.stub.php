<?php

declare(strict_types=1);

/**
 * Minimal WooCommerce 10.x contracts consumed by RouteMaps.
 *
 * These declarations are for PHPStan only. Runtime classes/functions are
 * supplied by WooCommerce itself.
 */

class WC_Cart {
    /** @return array<int,array<string,mixed>> */
    public function get_cart(): array {}
}

class WC_Product {
    public function get_meta(string $key = '', bool $single = true): mixed {}
    public function update_meta_data(string $key, mixed $value): void {}
    public function delete_meta_data(string $key): void {}
    public function save(): int {}
    public function get_id(): int {}
}

class WC_Order {
    public function get_billing_email(): string {}
    /**
     * @param string|array<int|string,string> $types
     * @return array<int,WC_Order_Item_Product>
     */
    public function get_items(string|array $types = 'line_item'): array {}
    public function get_id(): int {}
    public function is_paid(): bool {}
    public function get_user_id(): int {}
    public function get_item(int $item_id, bool $load_from_db = true): WC_Order_Item_Product|false {}
    public function get_date_created(): ?DateTimeInterface {}
    public function get_date_paid(): ?DateTimeInterface {}
}

class WC_Order_Item_Product {
    public function get_product(): WC_Product|false {}
}

class WC_Admin_Meta_Boxes {
    public static function add_error(string $text): void {}
}

function wc_get_product(mixed $the_product = false): WC_Product|false {}
function wc_get_order(mixed $the_order = false): WC_Order|false {}

/** @param array<string,mixed> $field */
function woocommerce_wp_checkbox(array $field): void {}
/** @param array<string,mixed> $field */
function woocommerce_wp_select(array $field): void {}
/** @param array<string,mixed> $field */
function woocommerce_wp_text_input(array $field): void {}
