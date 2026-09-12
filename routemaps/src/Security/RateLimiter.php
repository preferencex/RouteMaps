<?php

declare(strict_types=1);

namespace RouteMaps\Core\Security;

use InvalidArgumentException;

final class RateLimiter {
    public function bucket(string $namespace, string $identifier): string {
        $namespace = strtolower(trim($namespace));
        $namespace = (string) preg_replace('/[^a-z0-9_\-]+/', '_', $namespace);
        $namespace = trim($namespace, '_');
        if ('' === $namespace || '' === trim($identifier)) {
            throw new InvalidArgumentException('rate_limit_bucket_invalid');
        }
        return $namespace . ':' . hash('sha256', strtolower(trim($identifier)));
    }

    public function consume(string $bucket, int $limit, int $windowSeconds): bool {
        $bucket = strtolower(trim($bucket));
        if ('' === $bucket || $limit <= 0 || $windowSeconds <= 0) {
            throw new InvalidArgumentException('rate_limit_invalid');
        }

        $pre = apply_filters('routemaps_rate_limiter_pre_consume', null, $bucket, $limit, $windowSeconds);
        if (is_bool($pre)) {
            return $pre;
        }

        $key = 'routemaps_rl_' . hash('sha256', $bucket);
        $current = get_transient($key);
        $count = is_numeric($current) ? max(0, (int) $current) : 0;
        $allowed = $count < $limit;

        if ($allowed) {
            set_transient($key, $count + 1, $windowSeconds);
        }

        return (bool) apply_filters(
            'routemaps_rate_limiter_result',
            $allowed,
            $bucket,
            $count + ($allowed ? 1 : 0),
            $limit,
            $windowSeconds
        );
    }
}
