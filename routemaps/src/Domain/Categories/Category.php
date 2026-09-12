<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Categories;

final class Category {
    public function __construct(
        private int $id,
        private string $uuid,
        private string $name,
        private string $slug,
        private string $icon,
        private string $color,
        private int $sortOrder,
        private bool $active
    ) {
    }

    public function id(): int { return $this->id; }
    public function uuid(): string { return $this->uuid; }
    public function name(): string { return $this->name; }
    public function slug(): string { return $this->slug; }
    public function icon(): string { return $this->icon; }
    public function color(): string { return $this->color; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function isActive(): bool { return $this->active; }
}
