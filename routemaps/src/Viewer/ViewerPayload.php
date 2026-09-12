<?php

declare(strict_types=1);

namespace RouteMaps\Core\Viewer;

final class ViewerPayload {
    /** @param array<string,mixed> $data */
    public function __construct(private array $data) {
    }

    /** @return array<string,mixed> */
    public function data(): array { return $this->data; }

    public function get(string $key, mixed $default = null): mixed {
        return $this->data[$key] ?? $default;
    }

    public function with(string $key, mixed $value): self {
        $data = $this->data;
        $data[$key] = $value;
        return new self($data);
    }
}
