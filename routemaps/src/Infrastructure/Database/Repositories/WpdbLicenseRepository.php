<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database\Repositories;

use InvalidArgumentException;
use RouteMaps\Core\Domain\Licensing\License;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Support\Uuid;
use RuntimeException;
use wpdb;

final class WpdbLicenseRepository implements LicenseRepositoryInterface {
    private string $table;

    public function __construct(private wpdb $db) {
        $this->table = $db->prefix . 'routemaps_licenses';
    }

    public function create(array $data): License {
        $clean = $this->cleanCreateData($data);
        $now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $inserted = $this->db->insert($this->table, [
            'uuid' => isset($data['uuid']) && is_string($data['uuid']) ? $data['uuid'] : Uuid::v4(),
            ...$clean,
            'created_at' => isset($data['created_at']) && is_string($data['created_at']) ? $data['created_at'] : $now,
            'updated_at' => isset($data['updated_at']) && is_string($data['updated_at']) ? $data['updated_at'] : $now,
        ]);
        if (false === $inserted) {
            throw new RuntimeException('license_insert_failed');
        }

        $license = $this->find((int) $this->db->insert_id);
        if (null === $license) {
            throw new RuntimeException('license_insert_not_found');
        }

        return $license;
    }

    public function find(int $id): ?License {
        return $this->one($this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id));
    }

    public function findForUpdate(int $id): ?License {
        return $this->one($this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1 FOR UPDATE", $id));
    }

    public function findByUuid(string $uuid): ?License {
        return $this->one($this->db->prepare("SELECT * FROM {$this->table} WHERE uuid = %s LIMIT 1", strtolower(trim($uuid))));
    }

    public function findByOrderItemId(int $orderItemId): ?License {
        return $this->one($this->db->prepare("SELECT * FROM {$this->table} WHERE order_item_id = %d LIMIT 1", $orderItemId));
    }

    public function findByTokenHash(string $tokenHash): ?License {
        $tokenHash = strtolower(trim($tokenHash));
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $tokenHash)) {
            return null;
        }
        return $this->one($this->db->prepare("SELECT * FROM {$this->table} WHERE public_token_hash = %s LIMIT 1", $tokenHash));
    }

    public function setFirstAccessIfEmpty(int $licenseId, string $firstAccessAt, string $updatedAt): License {
        $updated = $this->db->query(
            $this->db->prepare(
                "UPDATE {$this->table} SET first_access_at = %s, updated_at = %s WHERE id = %d AND first_access_at IS NULL",
                $firstAccessAt,
                $updatedAt,
                $licenseId
            )
        );
        if (false === $updated) {
            throw new RuntimeException('license_first_access_update_failed');
        }

        $license = $this->find($licenseId);
        if (null === $license) {
            throw new RuntimeException('license_not_found');
        }
        return $license;
    }

    public function incrementOpenings(int $licenseId, string $updatedAt): License {
        $updated = $this->db->query(
            $this->db->prepare(
                "UPDATE {$this->table} SET openings_used = openings_used + 1, updated_at = %s WHERE id = %d",
                $updatedAt,
                $licenseId
            )
        );
        if (1 !== $updated) {
            throw new RuntimeException('license_openings_increment_failed');
        }
        $license = $this->find($licenseId);
        if (null === $license) {
            throw new RuntimeException('license_not_found');
        }
        return $license;
    }

    public function updateLifecycleStatus(
        int $licenseId,
        LicenseStatus $status,
        ?string $suspendedAt,
        ?string $revokedAt,
        string $updatedAt
    ): License {
        $updated = $this->db->update(
            $this->table,
            [
                'status' => $status->value,
                'suspended_at' => $suspendedAt,
                'revoked_at' => $revokedAt,
                'updated_at' => $updatedAt,
            ],
            ['id' => $licenseId],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        if (false === $updated) {
            throw new RuntimeException('license_status_update_failed');
        }

        $license = $this->find($licenseId);
        if (null === $license) {
            throw new RuntimeException('license_not_found');
        }

        return $license;
    }

    public function findByOwner(int $ownerUserId, int $limit = 100, int $offset = 0): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table} WHERE owner_user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
                $ownerUserId,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return $this->hydrateRows($rows);
    }

    public function search(array $filters, int $page, int $perPage): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = ['1=1'];
        $args = [];
        $join = '';

        foreach ([
            'owner_user_id' => 'owner_user_id',
            'route_id' => 'route_id',
            'order_id' => 'order_id',
            'product_id' => 'product_id',
        ] as $filter => $column) {
            if (isset($filters[$filter]) && (int) $filters[$filter] > 0) {
                $where[] = 'l.' . $column . ' = %d';
                $args[] = (int) $filters[$filter];
            }
        }

        if (isset($filters['email']) && '' !== trim((string) $filters['email'])) {
            $join = " LEFT JOIN {$this->db->users} u ON u.ID = l.owner_user_id";
            $where[] = 'u.user_email LIKE %s';
            $args[] = '%' . $this->db->esc_like(trim((string) $filters['email'])) . '%';
        }
        if (isset($filters['status']) && '' !== (string) $filters['status']) {
            $where[] = 'l.status = %s';
            $args[] = sanitize_key((string) $filters['status']);
        }
        $validity = $filters['validity_mode'] ?? $filters['validity'] ?? null;
        if (is_string($validity) && '' !== $validity) {
            $where[] = 'l.validity_mode = %s';
            $args[] = sanitize_key($validity);
        }

        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM {$this->table} l{$join} WHERE {$whereSql}";
        if ([] !== $args) {
            $countSql = $this->db->prepare($countSql, ...$args);
        }
        $total = (int) $this->db->get_var($countSql);
        $offset = ($page - 1) * $perPage;
        $listSql = "SELECT l.* FROM {$this->table} l{$join} WHERE {$whereSql} ORDER BY l.id DESC LIMIT %d OFFSET %d";
        $listSql = $this->db->prepare($listSql, ...[...$args, $perPage, $offset]);
        $rows = $this->db->get_results($listSql, ARRAY_A);

        return ['items' => $this->hydrateRows($rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function cleanCreateData(array $data): array {
        $tokenHash = strtolower(trim((string) ($data['public_token_hash'] ?? '')));
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $tokenHash)) {
            throw new InvalidArgumentException('license_token_hash_invalid');
        }
        foreach (['order_id', 'order_item_id', 'product_id', 'route_id', 'owner_user_id'] as $field) {
            if ((int) ($data[$field] ?? 0) <= 0) {
                throw new InvalidArgumentException('license_' . $field . '_required'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal field name and machine-readable error code.
            }
        }

        $status = $data['status'] instanceof LicenseStatus ? $data['status'] : LicenseStatus::tryFrom((string) ($data['status'] ?? 'active'));
        $mode = $data['validity_mode'] instanceof ValidityMode ? $data['validity_mode'] : ValidityMode::tryFrom((string) ($data['validity_mode'] ?? 'unlimited'));
        if (null === $status || null === $mode) {
            throw new InvalidArgumentException('license_policy_invalid');
        }
        $days = isset($data['validity_days']) ? (int) $data['validity_days'] : null;
        $maxOpenings = array_key_exists('max_openings', $data) && null !== $data['max_openings'] ? (int) $data['max_openings'] : null;
        $openingsUsed = max(0, (int) ($data['openings_used'] ?? 0));
        $maxShares = max(0, (int) ($data['max_shares'] ?? 0));
        if (null !== $days && $days < 0) {
            throw new InvalidArgumentException('license_validity_days_invalid');
        }
        if (null !== $maxOpenings && $maxOpenings < 0) {
            throw new InvalidArgumentException('license_max_openings_invalid');
        }

        return [
            'public_token_hash' => $tokenHash,
            'order_id' => (int) $data['order_id'],
            'order_item_id' => (int) $data['order_item_id'],
            'product_id' => (int) $data['product_id'],
            'route_id' => (int) $data['route_id'],
            'owner_user_id' => (int) $data['owner_user_id'],
            'status' => $status->value,
            'validity_mode' => $mode->value,
            'validity_days' => $days,
            'valid_from' => $this->nullableDate($data['valid_from'] ?? null),
            'valid_until' => $this->nullableDate($data['valid_until'] ?? null),
            'first_access_at' => $this->nullableDate($data['first_access_at'] ?? null),
            'max_openings' => $maxOpenings,
            'openings_used' => $openingsUsed,
            'sharing_enabled' => !empty($data['sharing_enabled']) ? 1 : 0,
            'max_shares' => $maxShares,
            'suspended_at' => $this->nullableDate($data['suspended_at'] ?? null),
            'revoked_at' => $this->nullableDate($data['revoked_at'] ?? null),
        ];
    }

    private function nullableDate(mixed $value): ?string {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }
        return (string) $value;
    }

    private function one(string $query): ?License {
        $row = $this->db->get_row($query, ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param mixed $rows @return list<License> */
    private function hydrateRows(mixed $rows): array {
        if (!is_array($rows)) {
            return [];
        }
        $items = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $this->hydrate($row);
            }
        }
        return $items;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): License {
        return new License(
            (int) $row['id'],
            (string) $row['uuid'],
            (string) $row['public_token_hash'],
            (int) $row['order_id'],
            (int) $row['order_item_id'],
            (int) $row['product_id'],
            (int) $row['route_id'],
            (int) $row['owner_user_id'],
            LicenseStatus::from((string) $row['status']),
            ValidityMode::from((string) $row['validity_mode']),
            null === $row['validity_days'] ? null : (int) $row['validity_days'],
            null === $row['valid_from'] ? null : (string) $row['valid_from'],
            null === $row['valid_until'] ? null : (string) $row['valid_until'],
            null === $row['first_access_at'] ? null : (string) $row['first_access_at'],
            null === $row['max_openings'] ? null : (int) $row['max_openings'],
            (int) $row['openings_used'],
            (bool) $row['sharing_enabled'],
            (int) $row['max_shares'],
            null === $row['suspended_at'] ? null : (string) $row['suspended_at'],
            null === $row['revoked_at'] ? null : (string) $row['revoked_at'],
            (string) $row['created_at'],
            (string) $row['updated_at']
        );
    }
}
