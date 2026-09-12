<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Sharing;

interface LicenseUserRepositoryInterface {
    /** @param array<string,mixed> $data */
    public function create(array $data): LicenseUser;
    public function find(int $id): ?LicenseUser;
    public function findMembership(int $licenseId, int $userId): ?LicenseUser;
    public function findPendingOrActiveByEmail(int $licenseId, string $email): ?LicenseUser;
    public function findByInviteTokenHash(string $tokenHash): ?LicenseUser;
    /** @param list<string> $statuses */
    public function countGuestsByStatuses(int $licenseId, array $statuses): int;
    /** @return list<LicenseUser> */
    public function listGuests(int $licenseId): array;
    public function activate(int $id, int $userId, string $updatedAt): LicenseUser;
    public function revoke(int $id, string $updatedAt): LicenseUser;
}
