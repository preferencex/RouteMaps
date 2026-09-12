<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Categories;

interface CategoryRepositoryInterface {
    public function create(string $name, string $icon = '', string $color = '', int $sortOrder = 0, bool $active = true): Category;
    public function update(int $id, string $name, string $icon, string $color, int $sortOrder, bool $active): Category;
    public function find(int $id): ?Category;
    public function findByUuid(string $uuid): ?Category;
    public function findBySlug(string $slug): ?Category;

    /** @param array<string,mixed> $filters @return array{items:list<Category>,total:int,page:int,per_page:int} */
    public function search(array $filters, int $page, int $perPage): array;

    public function setActive(int $id, bool $active): Category;
    public function delete(int $id): void;
}
