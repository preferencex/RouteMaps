<?php

declare(strict_types=1);

namespace RouteMaps\Core\Commerce\Orders;

use InvalidArgumentException;
use RouteMaps\Core\Commerce\Email\RouteAccessEmail;
use RouteMaps\Core\Domain\Licensing\LicenseIssuer;
use Throwable;
use WC_Order;

final class LicenseOrderListener {
    public function __construct(
        private LicenseIssuer $issuer,
        private RouteAccessEmail $email
    ) {
    }

    public function registerHooks(): void {
        add_action('woocommerce_payment_complete', [$this, 'handle'], 20, 1);
        add_action('woocommerce_order_status_processing', [$this, 'handle'], 20, 1);
        add_action('woocommerce_order_status_completed', [$this, 'handle'], 20, 1);
    }

    public function handle(int $orderId): void {
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : false;
        if (!$order instanceof WC_Order) {
            return;
        }

        foreach ($order->get_items('line_item') as $itemId => $item) {
            try {
                $result = $this->issuer->issue_for_order_item($orderId, (int) $itemId);
                if ($result->wasCreated() && null !== $result->plainToken()) {
                    $this->email->send($order, $result->license(), $result->plainToken());
                }
            } catch (InvalidArgumentException $exception) {
                if ('not_routemaps_product' === $exception->getMessage()) {
                    continue;
                }
                $this->log($exception, $orderId, (int) $itemId);
            } catch (Throwable $exception) {
                $this->log($exception, $orderId, (int) $itemId);
            }
        }
    }

    private function log(Throwable $exception, int $orderId, int $itemId): void {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        wc_get_logger()->error(
            $exception->getMessage(),
            ['source' => 'routemaps', 'order_id' => $orderId, 'order_item_id' => $itemId]
        );
    }
}
