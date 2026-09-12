<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database\Repositories;

use InvalidArgumentException;
use RouteMaps\Core\Domain\Routes\Route;
use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use RouteMaps\Core\Support\Uuid;
use RuntimeException;
use wpdb;

final class WpdbRouteRepository implements RouteRepositoryInterface {
    private string $table;

    public function __construct(private wpdb $db) {
        $this->table = $db->prefix . 'routemaps_routes';
    }

    public function create(string $title, int $userId): Route {
        $title = trim($title);
        if ('' === $title) {
            throw new InvalidArgumentException('route_title_required');
        }

        $uuid = Uuid::v4();
        $slug = $this->uniqueSlug($title);
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $this->db->insert(
            $this->table,
            [
                'uuid' => $uuid,
                'title' => $title,
                'slug' => $slug,
                'status' => 'draft',
                'cover_attachment_id' => null,
                'current_published_version_id' => null,
                'map_source_id' => null,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        if (false === $inserted) {
            throw new RuntimeException('route_insert_failed');
        }

        $route = $this->find((int) $this->db->insert_id);
        if (null === $route) {
            throw new RuntimeException('route_insert_not_found');
        }

        return $route;
    }

    public function find(int $id): ?Route {
        $query = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id);
        $row = $this->db->get_row($query, ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByUuid(string $uuid): ?Route {
        $query = $this->db->prepare("SELECT * FROM {$this->table} WHERE uuid = %s LIMIT 1", $uuid);
        $row = $this->db->get_row($query, ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<Route> */
    public function list(int $limit = 100, int $offset = 0): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $query = $this->db->prepare(
            "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        );
        $rows = $this->db->get_results($query, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(fn (array $row): Route => $this->hydrate($row), $rows));
    }

    public function updatePublishedVersion(int $routeId, int $versionId): Route {
        $updated = $this->db->update(
            $this->table,
            [
                'status' => 'published',
                'current_published_version_id' => $versionId,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $routeId]
        );

        if (false === $updated) {
            throw new RuntimeException('route_publish_pointer_update_failed');
        }

        $route = $this->find($routeId);
        if (null === $route) {
            throw new RuntimeException('route_not_found');
        }

        return $route;
    }

    public function delete(int $routeId): void {
        $deleted = $this->db->delete($this->table, ['id' => $routeId], ['%d']);

        if (false === $deleted) {
            throw new RuntimeException('route_delete_failed');
        }
        if (0 === $deleted) {
            throw new RuntimeException('route_not_found');
        }
    }

    private function uniqueSlug(string $title): string {
        $base = sanitize_title($title);
        if ('' === $base) {
            $base = 'route';
        }

        $slug = $base;
        $suffix = 2;
        while (null !== $this->db->get_var($this->db->prepare("SELECT id FROM {$this->table} WHERE slug = %s LIMIT 1", $slug))) {
            $slug = $base . '-' . $suffix;
            ++$suffix;
        }

        return $slug;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Route {
        return new Route(
            (int) $row['id'],
            (string) $row['uuid'],
            (string) $row['title'],
            (string) $row['slug'],
            (string) $row['status'],
            null === $row['cover_attachment_id'] ? null : (int) $row['cover_attachment_id'],
            null === $row['current_published_version_id'] ? null : (int) $row['current_published_version_id'],
            null === $row['map_source_id'] ? null : (string) $row['map_source_id'],
            (int) $row['created_by'],
            (string) $row['created_at'],
            (string) $row['updated_at']
        );
    }
}
