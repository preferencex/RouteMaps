<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\POI;

interface PoiRepositoryInterface {
    /** @param array<string,mixed> $data */
    public function create(array $data, int $userId): Poi;

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, int $userId): Poi;

    public function find(int $id): ?Poi;
    public function findByUuid(string $uuid): ?Poi;

    /** @param array<string,mixed> $filters @return array{items:list<Poi>,total:int,page:int,per_page:int} */
    public function search(array $filters, int $page, int $perPage): array;

    public function delete(int $id): void;
}
