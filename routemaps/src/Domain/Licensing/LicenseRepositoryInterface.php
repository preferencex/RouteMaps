<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Licensing;

interface LicenseRepositoryInterface {
    /** @param array<string,mixed> $data */
    public function create(array $data): License;

    public function find(int $id): ?License;
    public function findForUpdate(int $id): ?License;
    public function findByUuid(string $uuid): ?License;
    public function findByOrderItemId(int $orderItemId): ?License;
    public function findByTokenHash(string $tokenHash): ?License;
    public function incrementOpenings(int $licenseId, string $updatedAt): License;
    public function setFirstAccessIfEmpty(int $licenseId, string $firstAccessAt, string $updatedAt): License;

    public function updateLifecycleStatus(
        int $licenseId,
        LicenseStatus $status,
        ?string $suspendedAt,
        ?string $revokedAt,
        string $updatedAt
    ): License;

    /** @return list<License> */
    public function findByOwner(int $ownerUserId, int $limit = 100, int $offset = 0): array;

    /** @param array<string,mixed> $filters @return array{items:list<License>,total:int,page:int,per_page:int} */
    public function search(array $filters, int $page, int $perPage): array;
}
