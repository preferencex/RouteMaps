<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database\Repositories;

use InvalidArgumentException;
use JsonException;
use RouteMaps\Core\Domain\POI\Poi;
use RouteMaps\Core\Domain\POI\PoiRepositoryInterface;
use RouteMaps\Core\Support\Uuid;
use RuntimeException;
use wpdb;

final class WpdbPoiRepository implements PoiRepositoryInterface {
    private string $table;

    public function __construct(private wpdb $db) {
        $this->table = $db->prefix . 'routemaps_pois';
    }

    public function create(array $data, int $userId): Poi {
        $clean = $this->sanitizeData($data);
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $this->db->insert($this->table, [
            'uuid' => Uuid::v4(),
            ...$clean,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (false === $inserted) {
            throw new RuntimeException('poi_insert_failed');
        }
        return $this->requireById((int) $this->db->insert_id);
    }

    public function update(int $id, array $data, int $userId): Poi {
        $this->requireById($id);
        $clean = $this->sanitizeData($data);
        $clean['updated_by'] = $userId;
        $clean['updated_at'] = gmdate('Y-m-d H:i:s');
        if (false === $this->db->update($this->table, $clean, ['id' => $id])) {
            throw new RuntimeException('poi_update_failed');
        }
        return $this->requireById($id);
    }

    public function find(int $id): ?Poi {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByUuid(string $uuid): ?Poi {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE uuid = %s LIMIT 1", $uuid), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function search(array $filters, int $page, int $perPage): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = ['1=1'];
        $args = [];
        if (isset($filters['category_id'])) {
            $where[] = 'category_id = %d';
            $args[] = (int) $filters['category_id'];
        }
        if (isset($filters['status']) && '' !== trim((string) $filters['status'])) {
            $where[] = 'status = %s';
            $args[] = sanitize_key((string) $filters['status']);
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
        $listSql = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE {$whereSql} ORDER BY name ASC LIMIT %d OFFSET %d",
            ...[...$args, $perPage, $offset]
        );
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

    public function delete(int $id): void {
        $this->requireById($id);
        if (false === $this->db->delete($this->table, ['id' => $id])) {
            throw new RuntimeException('poi_delete_failed');
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> @throws JsonException */
    private function sanitizeData(array $data): array {
        $name = sanitize_text_field((string) ($data['name'] ?? ''));
        $categoryId = (int) ($data['category_id'] ?? 0);
        $latitude = filter_var($data['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($data['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        if ('' === $name) {
            throw new InvalidArgumentException('poi_name_required');
        }
        if ($categoryId <= 0) {
            throw new InvalidArgumentException('poi_category_required');
        }
        if (false === $latitude || $latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException('invalid_poi_latitude');
        }
        if (false === $longitude || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('invalid_poi_longitude');
        }
        $gallery = $this->sanitizeGallery($data['gallery'] ?? []);
        $cta = $this->sanitizeCta($data['cta'] ?? []);
        $status = sanitize_key((string) ($data['status'] ?? 'active'));
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        $mainAttachmentId = isset($data['main_attachment_id']) ? (int) $data['main_attachment_id'] : null;
        if (null !== $mainAttachmentId && $mainAttachmentId <= 0) {
            $mainAttachmentId = null;
        }

        return [
            'name' => $name,
            'category_id' => $categoryId,
            'latitude' => number_format((float) $latitude, 7, '.', ''),
            'longitude' => number_format((float) $longitude, 7, '.', ''),
            'description' => wp_kses_post((string) ($data['description'] ?? '')),
            'address' => sanitize_textarea_field((string) ($data['address'] ?? '')),
            'phone' => sanitize_text_field((string) ($data['phone'] ?? '')),
            'website' => esc_url_raw((string) ($data['website'] ?? '')),
            'opening_hours' => sanitize_textarea_field((string) ($data['opening_hours'] ?? '')),
            'route_note' => sanitize_textarea_field((string) ($data['route_note'] ?? '')),
            'main_attachment_id' => $mainAttachmentId,
            'gallery_json' => json_encode($gallery, JSON_THROW_ON_ERROR),
            'icon' => sanitize_key((string) ($data['icon'] ?? '')),
            'color' => $this->sanitizeColor((string) ($data['color'] ?? '')),
            'cta_json' => json_encode($cta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => $status,
        ];
    }

    /** @return list<int> */
    private function sanitizeGallery(mixed $gallery): array {
        if (!is_array($gallery)) {
            return [];
        }
        $ids = [];
        foreach ($gallery as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /** @return array<string,string> */
    private function sanitizeCta(mixed $cta): array {
        if (!is_array($cta)) {
            return [];
        }
        $result = [];
        if (isset($cta['label'])) {
            $result['label'] = sanitize_text_field((string) $cta['label']);
        }
        if (isset($cta['url'])) {
            $result['url'] = esc_url_raw((string) $cta['url']);
        }
        return $result;
    }

    private function sanitizeColor(string $color): string {
        $sanitized = sanitize_hex_color($color);
        return is_string($sanitized) ? $sanitized : '';
    }

    private function requireById(int $id): Poi {
        $poi = $this->find($id);
        if (null === $poi) {
            throw new RuntimeException('poi_not_found');
        }
        return $poi;
    }

    /** @param array<string,mixed> $row @throws JsonException */
    private function hydrate(array $row): Poi {
        $gallery = json_decode((string) $row['gallery_json'], true, 512, JSON_THROW_ON_ERROR);
        $cta = json_decode((string) $row['cta_json'], true, 512, JSON_THROW_ON_ERROR);
        return new Poi(
            (int) $row['id'],
            (string) $row['uuid'],
            (string) $row['name'],
            (int) $row['category_id'],
            (float) $row['latitude'],
            (float) $row['longitude'],
            (string) $row['description'],
            (string) $row['address'],
            (string) $row['phone'],
            (string) $row['website'],
            (string) $row['opening_hours'],
            (string) $row['route_note'],
            null === $row['main_attachment_id'] ? null : (int) $row['main_attachment_id'],
            is_array($gallery) ? array_values(array_map('intval', $gallery)) : [],
            (string) $row['icon'],
            (string) $row['color'],
            is_array($cta) ? $cta : [],
            (string) $row['status'],
            (int) $row['created_by'],
            (int) $row['updated_by'],
            (string) $row['created_at'],
            (string) $row['updated_at']
        );
    }
}
