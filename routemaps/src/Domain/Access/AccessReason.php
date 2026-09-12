<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Access;

enum AccessReason: string {
    case INVALID_TOKEN = 'invalid_token';
    case NOT_AUTHENTICATED = 'not_authenticated';
    case NOT_AUTHORIZED = 'not_authorized';
    case LICENSE_SUSPENDED = 'license_suspended';
    case LICENSE_REVOKED = 'license_revoked';
    case LICENSE_EXPIRED = 'license_expired';
    case OPENINGS_EXHAUSTED = 'openings_exhausted';
    case ROUTE_MISMATCH = 'route_mismatch';
}
