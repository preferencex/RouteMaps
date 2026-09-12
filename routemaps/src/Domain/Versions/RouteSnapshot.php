<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Versions;

final class RouteSnapshot {
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        private array $data,
        private string $canonicalJson,
        private string $contentHash
    ) {
    }

    /** @return array<string,mixed> */
    public function data(): array {
        return $this->data;
    }

    public function canonicalJson(): string {
        return $this->canonicalJson;
    }

    public function contentHash(): string {
        return $this->contentHash;
    }
}
