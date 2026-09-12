<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RouteMaps\Core\Domain\Access\AccessSession;
use RouteMaps\Core\Domain\Access\AccessSessionRepositoryInterface;
use RouteMaps\Core\Support\Uuid;
use RuntimeException;
use wpdb;

final class WpdbAccessSessionRepository implements AccessSessionRepositoryInterface {
    private string $table;

    public function __construct(private wpdb $db) {
        $this->table = $db->prefix . 'routemaps_access_sessions';
    }

    public function create(array $data): AccessSession {
        foreach (['license_id','user_id'] as $field) {
            if ((int) ($data[$field] ?? 0) <= 0) {
                throw new InvalidArgumentException('access_session_' . $field . '_required'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal field name and machine-readable error code.
            }
        }
        $now = $this->format(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $inserted = $this->db->insert($this->table, [
            'uuid' => isset($data['uuid']) ? (string) $data['uuid'] : Uuid::v4(),
            'license_id' => (int) $data['license_id'],
            'user_id' => (int) $data['user_id'],
            'started_at' => (string) ($data['started_at'] ?? $now),
            'last_seen_at' => (string) ($data['last_seen_at'] ?? $now),
            'expires_at' => (string) ($data['expires_at'] ?? $now),
            'ended_at' => $data['ended_at'] ?? null,
        ]);
        if (false === $inserted) {
            throw new RuntimeException('access_session_insert_failed');
        }
        return $this->required((int) $this->db->insert_id);
    }

    public function find(int $id): ?AccessSession {
        return $this->one($this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id));
    }

    public function findByUuid(string $uuid): ?AccessSession {
        return $this->one($this->db->prepare("SELECT * FROM {$this->table} WHERE uuid = %s LIMIT 1", strtolower(trim($uuid))));
    }

    public function findActive(int $licenseId, int $userId, DateTimeImmutable $now): ?AccessSession {
        return $this->one($this->db->prepare(
            "SELECT * FROM {$this->table} WHERE license_id = %d AND user_id = %d AND ended_at IS NULL AND expires_at > %s ORDER BY last_seen_at DESC LIMIT 1",
            $licenseId,
            $userId,
            $this->format($now)
        ));
    }

    public function touch(string $uuid, int $userId, DateTimeImmutable $lastSeenAt, DateTimeImmutable $expiresAt): AccessSession {
        $session = $this->findByUuid($uuid);
        if (null === $session || $session->userId() !== $userId || null !== $session->endedAt()) {
            throw new RuntimeException('access_session_not_active');
        }
        if (false === $this->db->update($this->table, [
            'last_seen_at' => $this->format($lastSeenAt),
            'expires_at' => $this->format($expiresAt),
        ], ['id' => $session->id()])) {
            throw new RuntimeException('access_session_update_failed');
        }
        return $this->required($session->id());
    }

    public function end(int $id, DateTimeImmutable $endedAt): void {
        if (false === $this->db->update($this->table, ['ended_at' => $this->format($endedAt)], ['id' => $id])) {
            throw new RuntimeException('access_session_end_failed');
        }
    }

    public function endForLicenseUser(int $licenseId, int $userId, DateTimeImmutable $endedAt): int {
        $updated = $this->db->query($this->db->prepare(
            "UPDATE {$this->table} SET ended_at = %s WHERE license_id = %d AND user_id = %d AND ended_at IS NULL",
            $this->format($endedAt),
            $licenseId,
            $userId
        ));
        if (false === $updated) {
            throw new RuntimeException('access_session_end_failed');
        }
        return (int) $updated;
    }

    private function required(int $id): AccessSession {
        $session = $this->find($id);
        if (null === $session) {
            throw new RuntimeException('access_session_not_found');
        }
        return $session;
    }

    private function one(string $sql): ?AccessSession {
        $row = $this->db->get_row($sql, ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): AccessSession {
        return new AccessSession(
            (int) $row['id'],
            (string) $row['uuid'],
            (int) $row['license_id'],
            (int) $row['user_id'],
            (string) $row['started_at'],
            (string) $row['last_seen_at'],
            (string) $row['expires_at'],
            null === $row['ended_at'] ? null : (string) $row['ended_at']
        );
    }

    private function format(DateTimeImmutable $date): string {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
