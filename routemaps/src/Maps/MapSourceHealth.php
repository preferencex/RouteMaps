<?php

declare(strict_types=1);

namespace RouteMaps\Core\Maps;

final class MapSourceHealth {
    public function __construct(
        private bool $ok,
        private string $code,
        private string $message
    ) {
    }

    public function ok(): bool { return $this->ok; }
    public function code(): string { return $this->code; }
    public function message(): string { return $this->message; }
}
