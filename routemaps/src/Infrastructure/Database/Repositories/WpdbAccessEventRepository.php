<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RouteMaps\Core\Domain\Access\AccessEventRepositoryInterface;
use RuntimeException;
use wpdb;

final class WpdbAccessEventRepository implements AccessEventRepositoryInterface {
    private string $table;

    public function __construct(private wpdb $db) {
        $this->table = $db->prefix . 'routemaps_access_events';
    }

    public function record(
        ?int $licenseId,
        ?int $userId,
        string $eventType,
        ?string $reasonCode,
        array $metadata,
        DateTimeImmutable $createdAt
    ): void {
        try {
            $metadataJson = [] === $metadata
                ? null
                : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('access_event_metadata_invalid', 0, $exception); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained JSON exception is not rendered.
        }
        $inserted = $this->db->insert($this->table, [
            'license_id' => $licenseId,
            'user_id' => $userId,
            'event_type' => sanitize_key($eventType),
            'reason_code' => null === $reasonCode ? null : sanitize_key($reasonCode),
            'metadata_json' => $metadataJson,
            'created_at' => $createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
        if (false === $inserted) {
            throw new RuntimeException('access_event_insert_failed');
        }
    }

    public function forLicense(int $licenseId, int $limit = 100): array {
        $limit = max(1, min(500, $limit));
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table} WHERE license_id = %d ORDER BY created_at DESC,id DESC LIMIT %d",
                $licenseId,
                $limit
            ),
            ARRAY_A
        );
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
