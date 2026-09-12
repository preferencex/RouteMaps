<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database\Repositories;

use InvalidArgumentException;
use RouteMaps\Core\Domain\Categories\Category;
use RouteMaps\Core\Domain\Categories\CategoryRepositoryInterface;
use RouteMaps\Core\Support\Uuid;
use RuntimeException;
use wpdb;

final class WpdbCategoryRepository implements CategoryRepositoryInterface {
    private string $table;

    public function __construct(private wpdb $db) {
        $this->table = $db->prefix . 'routemaps_categories';
    }

    public function create(string $name, string $icon = '', string $color = '', int $sortOrder = 0, bool $active = true): Category {
        $name = sanitize_text_field($name);
        if ('' === $name) {
            throw new InvalidArgumentException('category_name_required');
        }
        $inserted = $this->db->insert($this->table, [
            'uuid' => Uuid::v4(),
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'icon' => sanitize_key($icon),
            'color' => $this->sanitizeColor($color),
            'sort_order' => $sortOrder,
            'is_active' => $active ? 1 : 0,
        ]);
        if (false === $inserted) {
            throw new RuntimeException('category_insert_failed');
        }

        return $this->requireById((int) $this->db->insert_id);
    }

    public function update(int $id, string $name, string $icon, string $color, int $sortOrder, bool $active): Category {
        $existing = $this->requireById($id);
        $name = sanitize_text_field($name);
        if ('' === $name) {
            throw new InvalidArgumentException('category_name_required');
        }
        $slug = $existing->name() === $name ? $existing->slug() : $this->uniqueSlug($name, $id);
        $updated = $this->db->update($this->table, [
            'name' => $name,
            'slug' => $slug,
            'icon' => sanitize_key($icon),
            'color' => $this->sanitizeColor($color),
            'sort_order' => $sortOrder,
            'is_active' => $active ? 1 : 0,
        ], ['id' => $id]);
        if (false === $updated) {
            throw new RuntimeException('category_update_failed');
        }

        return $this->requireById($id);
    }

    public function find(int $id): ?Category {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByUuid(string $uuid): ?Category {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE uuid = %s LIMIT 1", $uuid), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?Category {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE slug = %s LIMIT 1", sanitize_title($slug)), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function search(array $filters, int $page, int $perPage): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = ['1=1'];
        $args = [];
        if (array_key_exists('active', $filters)) {
            $where[] = 'is_active = %d';
            $args[] = (bool) $filters['active'] ? 1 : 0;
        }
        if (isset($filters['query']) && '' !== trim((string) $filters['query'])) {
            $where[] = 'name LIKE %s';
            $args[] = '%' . $this->db->esc_like(sanitize_text_field((string) $filters['query'])) . '%';
        }
        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM {$this->table} WHERE {$whereSql}";
        if ([] !== $args) {
            $countSql = $this->db->prepare($countSql, ...$args);
        }
        $total = (int) $this->db->get_var($countSql);
        $offset = ($page - 1) * $perPage;
        $listArgs = [...$args, $perPage, $offset];
        $listSql = "SELECT * FROM {$this->table} WHERE {$whereSql} ORDER BY sort_order ASC, name ASC LIMIT %d OFFSET %d";
        $listSql = $this->db->prepare($listSql, ...$listArgs);
        $rows = $this->db->get_results($listSql, ARRAY_A);
        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $items[] = $this->hydrate($row);
                }
            }
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function setActive(int $id, bool $active): Category {
        $this->requireById($id);
        if (false === $this->db->update($this->table, ['is_active' => $active ? 1 : 0], ['id' => $id])) {
            throw new RuntimeException('category_update_failed');
        }
        return $this->requireById($id);
    }

    public function delete(int $id): void {
        $this->requireById($id);
        if (false === $this->db->delete($this->table, ['id' => $id])) {
            throw new RuntimeException('category_delete_failed');
        }
    }

    private function requireById(int $id): Category {
        $category = $this->find($id);
        if (null === $category) {
            throw new RuntimeException('category_not_found');
        }
        return $category;
    }

    private function uniqueSlug(string $name, ?int $excludeId = null): string {
        $base = sanitize_title($name);
        if ('' === $base) {
            $base = 'category';
        }
        $slug = $base;
        $suffix = 2;
        while (true) {
            if (null === $excludeId) {
                $id = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->table} WHERE slug = %s LIMIT 1", $slug));
            } else {
                $id = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->table} WHERE slug = %s AND id <> %d LIMIT 1", $slug, $excludeId));
            }
            if (null === $id) {
                return $slug;
            }
            $slug = $base . '-' . $suffix++;
        }
    }

    private function sanitizeColor(string $color): string {
        $sanitized = sanitize_hex_color($color);
        return is_string($sanitized) ? $sanitized : '';
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Category {
        return new Category(
            (int) $row['id'],
            (string) $row['uuid'],
            (string) $row['name'],
            (string) $row['slug'],
            (string) $row['icon'],
            (string) $row['color'],
            (int) $row['sort_order'],
            (bool) $row['is_active']
        );
    }
}
