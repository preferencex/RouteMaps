<?php

declare(strict_types=1);

namespace RouteMaps\Core\Import;

final class ImportMapping {
    /**
     * @param array<string,int>   $categoryMap Source category/style key => RouteMaps category ID.
     * @param array<string,mixed> $options
     */
    public function __construct(
        private array $categoryMap = [],
        private array $options = []
    ) {
    }

    /** @return array<string,int> */
    public function categoryMap(): array { return $this->categoryMap; }

    public function categoryIdFor(string $sourceKey): ?int {
        return isset($this->categoryMap[$sourceKey]) ? (int) $this->categoryMap[$sourceKey] : null;
    }

    /** @return array<string,mixed> */
    public function options(): array { return $this->options; }

    public function option(string $key, mixed $default = null): mixed {
        return $this->options[$key] ?? $default;
    }
}
