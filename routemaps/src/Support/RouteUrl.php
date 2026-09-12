<?php

declare(strict_types=1);

namespace RouteMaps\Core\Support;

use InvalidArgumentException;

final class RouteUrl {
    public function invite(string $plainToken): string {
        $plainToken = trim($plainToken);
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $plainToken)) {
            throw new InvalidArgumentException('route_invite_token_invalid');
        }

        return home_url('/routemaps/invite/' . rawurlencode($plainToken));
    }

    public function access(string $plainToken): string {
        $plainToken = trim($plainToken);
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $plainToken)) {
            throw new InvalidArgumentException('route_access_token_invalid');
        }

        return home_url('/routemaps/access/' . rawurlencode($plainToken));
    }
}
