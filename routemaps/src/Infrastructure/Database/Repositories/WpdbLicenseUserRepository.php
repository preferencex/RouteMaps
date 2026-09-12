<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database\Repositories;

use InvalidArgumentException;
use RouteMaps\Core\Domain\Sharing\LicenseUser;
use RouteMaps\Core\Domain\Sharing\LicenseUserRepositoryInterface;
use RuntimeException;
use wpdb;

final class WpdbLicenseUserRepository implements LicenseUserRepositoryInterface {
    private string $table;

    public function __construct(private wpdb $db) {
        $this->table = $db->prefix . 'routemaps_license_users';
    }

    public function create(array $data): LicenseUser {
        $licenseId = (int) ($data['license_id'] ?? 0);
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $role = sanitize_key((string) ($data['role'] ?? 'guest'));
        $status = sanitize_key((string) ($data['status'] ?? 'pending'));
        if ($licenseId <= 0 || '' === $email || !is_email($email)) {
            throw new InvalidArgumentException('license_user_invalid');
        }
        if (!in_array($role, ['owner', 'guest'], true) || !in_array($status, ['active', 'pending', 'revoked'], true)) {
            throw new InvalidArgumentException('license_user_state_invalid');
        }
        $tokenHash = isset($data['invite_token_hash'])
            ? strtolower(trim((string) $data['invite_token_hash']))
            : null;
        if (null !== $tokenHash && 1 !== preg_match('/^[0-9a-f]{64}$/', $tokenHash)) {
            throw new InvalidArgumentException('license_user_token_invalid');
        }
        $now = current_time('mysql', true);
        $inserted = $this->db->insert($this->table, [
            'license_id' => $licenseId,
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'email' => $email,
            'role' => $role,
            'status' => $status,
            'invite_token_hash' => $tokenHash,
            'created_at' => (string) ($data['created_at'] ?? $now),
            'updated_at' => (string) ($data['updated_at'] ?? $now),
        ]);
        if (false === $inserted) {
            throw new RuntimeException('license_user_insert_failed');
        }
        return $this->required((int) $this->db->insert_id);
    }

    public function find(int $id): ?LicenseUser {
        return $this->one($this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id));
    }

    public function findMembership(int $licenseId, int $userId): ?LicenseUser {
        return $this->one($this->db->prepare(
            "SELECT * FROM {$this->table} WHERE license_id = %d AND user_id = %d AND status = 'active' LIMIT 1",
            $licenseId,
            $userId
        ));
    }

    public function findPendingOrActiveByEmail(int $licenseId, string $email): ?LicenseUser {
        return $this->one($this->db->prepare(
            "SELECT * FROM {$this->table} WHERE license_id = %d AND email = %s AND status IN ('pending','active') LIMIT 1",
            $licenseId,
            strtolower(trim($email))
        ));
    }

    public function findByInviteTokenHash(string $tokenHash): ?LicenseUser {
        $tokenHash = strtolower(trim($tokenHash));
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $tokenHash)) {
            return null;
        }
        return $this->one($this->db->prepare(
            "SELECT * FROM {$this->table} WHERE invite_token_hash = %s AND status = 'pending' LIMIT 1",
            $tokenHash
        ));
    }

    public function countGuestsByStatuses(int $licenseId, array $statuses): int {
        $statuses = array_values(array_intersect(['pending', 'active', 'revoked'], array_map('sanitize_key', $statuses)));
        if ([] === $statuses) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $query = $this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE license_id = %d AND role = 'guest' AND status IN ({$placeholders})",
            ...[$licenseId, ...$statuses]
        );
        return (int) $this->db->get_var($query);
    }

    public function listGuests(int $licenseId): array {
        $rows = $this->db->get_results(
            $this->db->prepare("SELECT * FROM {$this->table} WHERE license_id = %d AND role = 'guest' ORDER BY id ASC", $licenseId),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_map(fn (array $row): LicenseUser => $this->hydrate($row), $rows));
    }

    public function activate(int $id, int $userId, string $updatedAt): LicenseUser {
        $updated = $this->db->query(
            $this->db->prepare(
                "UPDATE {$this->table}
                 SET user_id = %d, status = 'active', invite_token_hash = NULL, updated_at = %s
                 WHERE id = %d AND status = 'pending' AND invite_token_hash IS NOT NULL",
                $userId,
                $updatedAt,
                $id
            )
        );
        if (1 !== $updated) {
            throw new RuntimeException('license_user_not_pending');
        }
        return $this->required($id);
    }

    public function revoke(int $id, string $updatedAt): LicenseUser {
        $this->required($id);
        if (false === $this->db->update($this->table, [
            'status' => 'revoked',
            'invite_token_hash' => null,
            'updated_at' => $updatedAt,
        ], ['id' => $id])) {
            throw new RuntimeException('license_user_update_failed');
        }
        return $this->required($id);
    }

    private function required(int $id): LicenseUser {
        $member = $this->find($id);
        if (null === $member) {
            throw new RuntimeException('license_user_not_found');
        }
        return $member;
    }

    private function one(string $sql): ?LicenseUser {
        $row = $this->db->get_row($sql, ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): LicenseUser {
        return new LicenseUser(
            (int) $row['id'],
            (int) $row['license_id'],
            null === $row['user_id'] ? null : (int) $row['user_id'],
            (string) $row['email'],
            (string) $row['role'],
            (string) $row['status'],
            null === $row['invite_token_hash'] ? null : (string) $row['invite_token_hash'],
            (string) $row['created_at'],
            (string) $row['updated_at']
        );
    }
}
