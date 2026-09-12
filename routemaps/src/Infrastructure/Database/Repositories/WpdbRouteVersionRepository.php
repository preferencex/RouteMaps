<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database\Repositories;

use LogicException;
use RouteMaps\Core\Domain\Versions\RouteVersion;
use RouteMaps\Core\Domain\Versions\RouteVersionRepositoryInterface;
use RouteMaps\Core\Domain\Versions\PublishedRouteSnapshotProvider;
use RouteMaps\Core\Domain\Versions\RouteSnapshot;
use RuntimeException;
use wpdb;

final class WpdbRouteVersionRepository implements RouteVersionRepositoryInterface, PublishedRouteSnapshotProvider {
    private string $table;

    public function __construct(private wpdb $db) {
        $this->table = $db->prefix . 'routemaps_route_versions';
    }

    public function find(int $id): ?RouteVersion {
        $query = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id);
        $row = $this->db->get_row($query, ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function get_published_snapshot(int $route_id, ?int $version_id = null): RouteSnapshot {
        $version = $this->findPublishedForRoute($route_id, $version_id);
        if (null === $version) {
            throw new RuntimeException('published_route_snapshot_not_found');
        }
        try {
            $data = json_decode($version->snapshotJson(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('published_route_snapshot_invalid', 0, $error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained JSON exception is not rendered.
        }
        if (!is_array($data)) {
            throw new RuntimeException('published_route_snapshot_invalid');
        }
        $hash = '' !== $version->contentHash() ? $version->contentHash() : hash('sha256', $version->snapshotJson());
        return new RouteSnapshot($data, $version->snapshotJson(), $hash);
    }

    public function findDraftForRoute(int $routeId): ?RouteVersion {
        $query = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE route_id = %d AND state = 'draft' ORDER BY version_number DESC LIMIT 1",
            $routeId
        );
        $row = $this->db->get_row($query, ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }


    public function findPublishedForRoute(int $routeId, ?int $versionId = null): ?RouteVersion {
        if (null !== $versionId) {
            $query = $this->db->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d AND route_id = %d AND state = 'published' LIMIT 1",
                $versionId,
                $routeId
            );
        } else {
            $query = $this->db->prepare(
                "SELECT * FROM {$this->table} WHERE route_id = %d AND state = 'published' ORDER BY version_number DESC LIMIT 1",
                $routeId
            );
        }

        $row = $this->db->get_row($query, ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<RouteVersion> */
    public function listPublishedForRoute(int $routeId): array {
        $query = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE route_id = %d AND state = 'published' ORDER BY version_number DESC",
            $routeId
        );
        $rows = $this->db->get_results($query, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(fn(array $row): RouteVersion => $this->hydrate($row), $rows));
    }

    public function nextVersionNumber(int $routeId): int {
        $query = $this->db->prepare("SELECT MAX(version_number) FROM {$this->table} WHERE route_id = %d", $routeId);
        $current = (int) $this->db->get_var($query);

        return $current + 1;
    }

    public function createDraft(int $routeId, int $versionNumber, string $snapshotJson, int $userId): RouteVersion {
        $inserted = $this->db->insert(
            $this->table,
            [
                'route_id' => $routeId,
                'version_number' => $versionNumber,
                'state' => 'draft',
                'is_critical' => 0,
                'snapshot_json' => $snapshotJson,
                'change_summary' => null,
                'content_hash' => '',
                'created_by' => $userId,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'published_at' => null,
            ]
        );

        if (false === $inserted) {
            throw new RuntimeException('route_version_insert_failed');
        }

        $version = $this->find((int) $this->db->insert_id);
        if (null === $version) {
            throw new RuntimeException('route_version_insert_not_found');
        }

        return $version;
    }

    public function updateDraft(int $versionId, string $snapshotJson, int $userId): RouteVersion {
        $existing = $this->find($versionId);
        if (null === $existing) {
            throw new RuntimeException('route_version_not_found');
        }
        if ('published' === $existing->state()) {
            throw new LogicException('published_version_immutable');
        }

        $updated = $this->db->update(
            $this->table,
            [
                'snapshot_json' => $snapshotJson,
                'created_by' => $userId,
            ],
            ['id' => $versionId]
        );

        if (false === $updated) {
            throw new RuntimeException('route_version_update_failed');
        }

        $version = $this->find($versionId);
        if (null === $version) {
            throw new RuntimeException('route_version_not_found');
        }

        return $version;
    }

    public function updateSnapshot(int $versionId, string $snapshotJson, string $contentHash): RouteVersion {
        $existing = $this->find($versionId);
        if (null === $existing) {
            throw new RuntimeException('route_version_not_found');
        }
        if ('published' === $existing->state()) {
            throw new LogicException('published_version_immutable');
        }

        $updated = $this->db->update(
            $this->table,
            [
                'snapshot_json' => $snapshotJson,
                'content_hash' => $contentHash,
            ],
            ['id' => $versionId]
        );

        if (false === $updated) {
            throw new RuntimeException('route_version_update_failed');
        }

        $version = $this->find($versionId);
        if (null === $version) {
            throw new RuntimeException('route_version_not_found');
        }

        return $version;
    }

    public function markPublished(int $versionId, bool $critical, ?string $summary, string $publishedAt): RouteVersion {
        $existing = $this->find($versionId);
        if (null === $existing) {
            throw new RuntimeException('route_version_not_found');
        }
        if ('published' === $existing->state()) {
            throw new LogicException('published_version_immutable');
        }

        $updated = $this->db->update(
            $this->table,
            [
                'state' => 'published',
                'is_critical' => $critical ? 1 : 0,
                'change_summary' => $summary,
                'published_at' => $publishedAt,
            ],
            ['id' => $versionId]
        );

        if (false === $updated) {
            throw new RuntimeException('route_version_publish_failed');
        }

        $version = $this->find($versionId);
        if (null === $version) {
            throw new RuntimeException('route_version_not_found');
        }

        return $version;
    }

    public function deleteForRoute(int $routeId): void {
        $deleted = $this->db->delete($this->table, ['route_id' => $routeId], ['%d']);

        if (false === $deleted) {
            throw new RuntimeException('route_versions_delete_failed');
        }
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): RouteVersion {
        return new RouteVersion(
            (int) $row['id'],
            (int) $row['route_id'],
            (int) $row['version_number'],
            (string) $row['state'],
            (bool) $row['is_critical'],
            (string) $row['snapshot_json'],
            null === $row['change_summary'] ? null : (string) $row['change_summary'],
            (string) $row['content_hash'],
            (int) $row['created_by'],
            (string) $row['created_at'],
            null === $row['published_at'] ? null : (string) $row['published_at']
        );
    }
}
