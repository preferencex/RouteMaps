<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Licensing;

enum ValidityMode: string {
    case UNLIMITED = 'unlimited';
    case DAYS_FROM_PURCHASE = 'days_from_purchase';
    case DAYS_FROM_FIRST_USE = 'days_from_first_use';
    case FIXED_RANGE = 'fixed_range';
}
