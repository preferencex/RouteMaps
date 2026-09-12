<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Routes;

interface RouteRepositoryInterface {
    public function create(string $title, int $userId): Route;
    public function find(int $id): ?Route;
    public function findByUuid(string $uuid): ?Route;

    /** @return list<Route> */
    public function list(int $limit = 100, int $offset = 0): array;

    public function updatePublishedVersion(int $routeId, int $versionId): Route;
    public function delete(int $routeId): void;
}
