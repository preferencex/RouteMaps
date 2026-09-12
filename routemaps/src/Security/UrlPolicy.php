<?php

declare(strict_types=1);

namespace RouteMaps\Core\Security;

final class UrlPolicy {
    /** @var list<string> */
    private array $schemes;

    /** @param list<string> $schemes */
    public function __construct(array $schemes = ['https', 'http']) {
        $this->schemes = array_values(array_unique(array_map('strtolower', $schemes)));
    }

    public function external(?string $value): ?string {
        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, $this->schemes, true) || '' === $host) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if ('localhost' === $host || str_ends_with($host, '.localhost')) {
            return null;
        }
        if (false !== filter_var($host, FILTER_VALIDATE_IP)
            && false === filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        ) {
            return null;
        }

        $safe = function_exists('esc_url_raw') ? esc_url_raw($value, $this->schemes) : $value;
        if ('' === $safe) {
            return null;
        }

        if (function_exists('apply_filters')) {
            $allowed = apply_filters('routemaps_external_url_allowed', true, $safe, $scheme, $host);
            if (false === $allowed) {
                return null;
            }
        }
        return $safe;
    }
}
