<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Licensing;

final class LicenseIssueResult {
    public function __construct(
        private License $license,
        private ?string $plainToken,
        private bool $created
    ) {
    }

    public function license(): License { return $this->license; }
    public function plainToken(): ?string { return $this->plainToken; }
    public function wasCreated(): bool { return $this->created; }
}
