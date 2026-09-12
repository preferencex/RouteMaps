<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Access;

use RouteMaps\Core\Domain\Licensing\License;

final class AccessDecision {
    private function __construct(
        private bool $allowed,
        private ?License $license,
        private ?AccessReason $reason
    ) {
    }

    public static function allow(License $license): self {
        return new self(true, $license, null);
    }

    public static function deny(AccessReason $reason): self {
        return new self(false, null, $reason);
    }

    public function allowed(): bool { return $this->allowed; }
    public function license(): ?License { return $this->license; }
    public function reason(): ?AccessReason { return $this->reason; }
}
