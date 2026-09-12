<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Versions;

interface RouteVersionRepositoryInterface {
    public function find(int $id): ?RouteVersion;
    public function findDraftForRoute(int $routeId): ?RouteVersion;
    public function findPublishedForRoute(int $routeId, ?int $versionId = null): ?RouteVersion;
    /** @return list<RouteVersion> */
    public function listPublishedForRoute(int $routeId): array;
    public function nextVersionNumber(int $routeId): int;
    public function createDraft(int $routeId, int $versionNumber, string $snapshotJson, int $userId): RouteVersion;
    public function updateDraft(int $versionId, string $snapshotJson, int $userId): RouteVersion;
    public function updateSnapshot(int $versionId, string $snapshotJson, string $contentHash): RouteVersion;
    public function markPublished(int $versionId, bool $critical, ?string $summary, string $publishedAt): RouteVersion;
    public function deleteForRoute(int $routeId): void;
}
