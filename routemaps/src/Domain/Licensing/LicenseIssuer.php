<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Licensing;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RouteMaps\Core\Commerce\Product\RouteProductMeta;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use RouteMaps\Core\Security\TokenService;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

final class LicenseIssuer {
    public function __construct(
        private LicenseRepositoryInterface $licenses,
        private RouteProductMeta $products,
        private TokenService $tokens,
        private TransactionManager $transactions
    ) {
    }

    public function issue_for_order_item(int $order_id, int $order_item_id): LicenseIssueResult {
        $existing = $this->licenses->findByOrderItemId($order_item_id);
        if (null !== $existing) {
            return new LicenseIssueResult($existing, null, false);
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order || $order->get_id() !== $order_id) {
            throw new InvalidArgumentException('license_order_not_found');
        }
        if (!$order->is_paid()) {
            throw new InvalidArgumentException('license_order_not_paid');
        }
        $ownerUserId = (int) $order->get_user_id();
        if ($ownerUserId <= 0) {
            throw new InvalidArgumentException('license_owner_required');
        }
        $item = $order->get_item($order_item_id);
        if (!$item instanceof WC_Order_Item_Product) {
            throw new InvalidArgumentException('license_order_item_not_found');
        }
        $product = $item->get_product();
        if (!$product instanceof WC_Product) {
            throw new InvalidArgumentException('license_product_not_found');
        }
        $policy = $this->products->policyForProduct($product);
        if (null === $policy) {
            throw new InvalidArgumentException('not_routemaps_product');
        }

        $purchaseAt = $this->purchaseTime($order);
        [$validFrom, $validUntil] = $this->licenseDates($policy, $purchaseAt);
        $token = $this->tokens->generate();

        try {
            $result = $this->transactions->run(function () use (
                $order,
                $order_item_id,
                $product,
                $ownerUserId,
                $policy,
                $purchaseAt,
                $validFrom,
                $validUntil,
                $token
            ): LicenseIssueResult {
                $inside = $this->licenses->findByOrderItemId($order_item_id);
                if (null !== $inside) {
                    return new LicenseIssueResult($inside, null, false);
                }

                $license = $this->licenses->create([
                    'public_token_hash' => $token['hash'],
                    'order_id' => $order->get_id(),
                    'order_item_id' => $order_item_id,
                    'product_id' => $product->get_id(),
                    'route_id' => $policy->routeId(),
                    'owner_user_id' => $ownerUserId,
                    'status' => LicenseStatus::ACTIVE,
                    'validity_mode' => $policy->validityMode(),
                    'validity_days' => $policy->validityDays(),
                    'valid_from' => $validFrom?->format('Y-m-d H:i:s'),
                    'valid_until' => $validUntil?->format('Y-m-d H:i:s'),
                    'first_access_at' => null,
                    'max_openings' => $policy->maxOpenings(),
                    'openings_used' => 0,
                    'sharing_enabled' => $policy->sharingEnabled(),
                    'max_shares' => $policy->maxShares(),
                    'created_at' => $purchaseAt->format('Y-m-d H:i:s'),
                    'updated_at' => $purchaseAt->format('Y-m-d H:i:s'),
                ]);

                return new LicenseIssueResult($license, $token['plain'], true);
            });
        } catch (RuntimeException $exception) {
            if ('license_insert_failed' !== $exception->getMessage()) {
                throw $exception;
            }
            $concurrent = $this->licenses->findByOrderItemId($order_item_id);
            if (null === $concurrent) {
                throw $exception;
            }
            return new LicenseIssueResult($concurrent, null, false);
        }

        if ($result->wasCreated()) {
            do_action('routemaps_license_created', $result->license());
        }

        return $result;
    }

    private function purchaseTime(WC_Order $order): DateTimeImmutable {
        $date = $order->get_date_paid() ?? $order->get_date_created();
        if ($date instanceof DateTimeInterface) {
            return (new DateTimeImmutable('@' . $date->getTimestamp()))->setTimezone(new DateTimeZone('UTC'));
        }
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @return array{0:?DateTimeImmutable,1:?DateTimeImmutable} */
    private function licenseDates(LicensePolicy $policy, DateTimeImmutable $purchaseAt): array {
        return match ($policy->validityMode()) {
            ValidityMode::UNLIMITED => [null, null],
            ValidityMode::DAYS_FROM_PURCHASE => [
                $purchaseAt,
                $purchaseAt->add(new DateInterval('P' . (int) $policy->validityDays() . 'D')),
            ],
            ValidityMode::DAYS_FROM_FIRST_USE => [null, null],
            ValidityMode::FIXED_RANGE => [$policy->validFrom(), $policy->validUntil()],
        };
    }
}
