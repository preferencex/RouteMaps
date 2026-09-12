<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Licensing;

enum LicenseStatus: string {
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case EXPIRED = 'expired';
    case EXHAUSTED = 'exhausted';
    case REVOKED = 'revoked';
}
