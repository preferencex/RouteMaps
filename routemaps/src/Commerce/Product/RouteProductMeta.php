<?php

declare(strict_types=1);

namespace RouteMaps\Core\Commerce\Product;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RouteMaps\Core\Domain\Licensing\LicensePolicy;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use Throwable;
use WC_Product;

final class RouteProductMeta {
    public const ENABLED = '_routemaps_enabled';
    public const ROUTE_ID = '_routemaps_route_id';
    public const VALIDITY_MODE = '_routemaps_validity_mode';
    public const VALIDITY_DAYS = '_routemaps_validity_days';
    public const VALID_FROM = '_routemaps_valid_from';
    public const VALID_UNTIL = '_routemaps_valid_until';
    public const MAX_OPENINGS = '_routemaps_max_openings';
    public const SHARING_ENABLED = '_routemaps_sharing_enabled';
    public const MAX_SHARES = '_routemaps_max_shares';

    private const POLICY_KEYS = [
        self::ROUTE_ID,
        self::VALIDITY_MODE,
        self::VALIDITY_DAYS,
        self::VALID_FROM,
        self::VALID_UNTIL,
        self::MAX_OPENINGS,
        self::SHARING_ENABLED,
        self::MAX_SHARES,
    ];

    public function __construct(private RouteRepositoryInterface $routes) {
    }

    public function isRouteProduct(WC_Product $product): bool {
        return $this->truthy($product->get_meta(self::ENABLED, true));
    }

    /** @param array<string,mixed> $input */
    public function save(WC_Product $product, array $input): void {
        if (!$this->truthy($input[self::ENABLED] ?? null)) {
            $product->update_meta_data(self::ENABLED, 'no');
            foreach (self::POLICY_KEYS as $key) {
                $product->delete_meta_data($key);
            }
            $product->save();
            return;
        }

        $routeId = (int) ($input[self::ROUTE_ID] ?? 0);
        if ($routeId <= 0 || null === $this->routes->find($routeId)) {
            throw new InvalidArgumentException('route_product_route_invalid');
        }

        $mode = ValidityMode::tryFrom((string) ($input[self::VALIDITY_MODE] ?? 'unlimited'));
        if (null === $mode) {
            throw new InvalidArgumentException('route_product_validity_mode_invalid');
        }

        $days = $this->nullableNonNegativeInt($input[self::VALIDITY_DAYS] ?? null, 'route_product_validity_days_invalid');
        if (in_array($mode, [ValidityMode::DAYS_FROM_PURCHASE, ValidityMode::DAYS_FROM_FIRST_USE], true)
            && (null === $days || $days <= 0)) {
            throw new InvalidArgumentException('route_product_validity_days_invalid');
        }

        $validFrom = $this->date($input[self::VALID_FROM] ?? null);
        $validUntil = $this->date($input[self::VALID_UNTIL] ?? null);
        if (ValidityMode::FIXED_RANGE === $mode
            && (null === $validFrom || null === $validUntil || $validFrom > $validUntil)) {
            throw new InvalidArgumentException('route_product_fixed_range_invalid');
        }

        $maxOpenings = $this->nullableNonNegativeInt($input[self::MAX_OPENINGS] ?? null, 'route_product_max_openings_invalid');
        $sharing = $this->truthy($input[self::SHARING_ENABLED] ?? null);
        $maxShares = $this->nullableNonNegativeInt($input[self::MAX_SHARES] ?? null, 'route_product_max_shares_invalid') ?? 0;
        if (!$sharing) {
            $maxShares = 0;
        }

        $policy = new LicensePolicy($routeId, $mode, $days, $validFrom, $validUntil, $maxOpenings, $sharing, $maxShares);

        $product->update_meta_data(self::ENABLED, 'yes');
        $product->update_meta_data(self::ROUTE_ID, $policy->routeId());
        $product->update_meta_data(self::VALIDITY_MODE, $policy->validityMode()->value);
        $this->setNullable($product, self::VALIDITY_DAYS, $policy->validityDays());
        $this->setNullable($product, self::VALID_FROM, $this->formatDate($policy->validFrom()));
        $this->setNullable($product, self::VALID_UNTIL, $this->formatDate($policy->validUntil()));
        $this->setNullable($product, self::MAX_OPENINGS, $policy->maxOpenings());
        $product->update_meta_data(self::SHARING_ENABLED, $policy->sharingEnabled() ? 'yes' : 'no');
        $product->update_meta_data(self::MAX_SHARES, $policy->maxShares());
        $product->save();
    }

    public function policyForProduct(WC_Product $product): ?LicensePolicy {
        if (!$this->isRouteProduct($product)) {
            return null;
        }

        $routeId = (int) $product->get_meta(self::ROUTE_ID, true);
        if ($routeId <= 0 || null === $this->routes->find($routeId)) {
            throw new InvalidArgumentException('route_product_route_invalid');
        }
        $mode = ValidityMode::tryFrom((string) $product->get_meta(self::VALIDITY_MODE, true));
        if (null === $mode) {
            throw new InvalidArgumentException('route_product_validity_mode_invalid');
        }

        $days = $this->nullableNonNegativeInt($product->get_meta(self::VALIDITY_DAYS, true), 'route_product_validity_days_invalid');
        $validFrom = $this->date($product->get_meta(self::VALID_FROM, true));
        $validUntil = $this->date($product->get_meta(self::VALID_UNTIL, true));
        $maxOpenings = $this->nullableNonNegativeInt($product->get_meta(self::MAX_OPENINGS, true), 'route_product_max_openings_invalid');
        $sharing = $this->truthy($product->get_meta(self::SHARING_ENABLED, true));
        $maxShares = $this->nullableNonNegativeInt($product->get_meta(self::MAX_SHARES, true), 'route_product_max_shares_invalid') ?? 0;

        return new LicensePolicy(
            $routeId,
            $mode,
            $days,
            $validFrom,
            $validUntil,
            $maxOpenings,
            $sharing,
            $sharing ? $maxShares : 0
        );
    }

    private function truthy(mixed $value): bool {
        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }

    private function nullableNonNegativeInt(mixed $value, string $error): ?int {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }
        if (false === filter_var($value, FILTER_VALIDATE_INT)) {
            throw new InvalidArgumentException($error);
        }
        $number = (int) $value;
        if ($number < 0) {
            throw new InvalidArgumentException($error);
        }
        return $number;
    }

    private function date(mixed $value): ?DateTimeImmutable {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }
        try {
            return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new InvalidArgumentException('route_product_date_invalid');
        }
    }

    private function formatDate(?DateTimeImmutable $date): ?string {
        return null === $date ? null : $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function setNullable(WC_Product $product, string $key, mixed $value): void {
        if (null === $value || '' === $value) {
            $product->delete_meta_data($key);
            return;
        }
        $product->update_meta_data($key, $value);
    }
}
