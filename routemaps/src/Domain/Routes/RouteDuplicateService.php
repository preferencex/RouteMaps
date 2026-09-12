<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Routes;

use JsonException;
use RouteMaps\Core\Domain\Versions\RouteVersion;
use RouteMaps\Core\Domain\Versions\RouteVersionRepositoryInterface;
use RouteMaps\Core\Support\Uuid;
use RuntimeException;

final class RouteDuplicateService {
    public function __construct(
        private RouteRepositoryInterface $routes,
        private RouteVersionRepositoryInterface $versions,
        private RouteDraftService $drafts
    ) {
    }

    /** @throws JsonException */
    public function duplicate(int $routeId, int $userId, ?string $title = null): Route {
        $source = $this->routes->find($routeId);
        if (null === $source) {
            throw new RuntimeException('route_not_found');
        }

        $sourceVersion = $this->resolveSourceVersion($source);
        $data = json_decode($sourceVersion->snapshotJson(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('route_snapshot_invalid');
        }

        $copyTitle = null !== $title && '' !== trim($title)
            ? trim($title)
            : $source->title() . ' — Cópia';
        $draftData = $this->toDraftData($data, $copyTitle, 'published' === $sourceVersion->state());
        $copy = $this->routes->create($copyTitle, $userId);
        $this->drafts->save($copy->id(), $draftData, $userId);

        return $copy;
    }

    private function resolveSourceVersion(Route $source): RouteVersion {
        $draft = $this->versions->findDraftForRoute($source->id());
        if (null !== $draft) {
            return $draft;
        }

        $published = $this->versions->findPublishedForRoute(
            $source->id(),
            $source->currentPublishedVersionId()
        );
        if (null === $published) {
            throw new RuntimeException('route_version_not_found');
        }

        return $published;
    }

    /**
     * @param array<string,mixed> $data
     * @throws JsonException
     */
    private function toDraftData(array $data, string $title, bool $publishedSnapshot): RouteDraftData {
        $stops = $this->regenerateEntityUuids($this->listValue($data, 'stops'));
        $pois = $this->regenerateEntityUuids($this->listValue($data, 'pois'));

        return new RouteDraftData(
            $title,
            $this->arrayValue($data, 'geometry'),
            $stops,
            $publishedSnapshot ? $this->arrayValue($data, 'route_style') : $this->arrayValue($data, 'style'),
            $this->arrayValue($data, 'viewport'),
            $this->arrayValue($data, 'support_overrides'),
            $publishedSnapshot
                ? $this->nullableString($data['map_source_id'] ?? null)
                : $this->nullableString($data['map_source_override'] ?? null),
            $pois,
            $this->regenerateEntityUuids($this->listValue($data, 'categories')),
            $this->arrayValue($data, 'display')
        );
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function arrayValue(array $data, string $key): array {
        $value = $data[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $data @return list<array<string,mixed>> */
    private function listValue(array $data, string $key): array {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function regenerateEntityUuids(array $items): array {
        foreach ($items as &$item) {
            if (array_key_exists('entity_uuid', $item)) {
                $item['entity_uuid'] = Uuid::v4();
            }
            $item = $this->regenerateNestedEntityUuids($item);
        }
        unset($item);

        return $items;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function regenerateNestedEntityUuids(array $value): array {
        foreach ($value as $key => $item) {
            if ('entity_uuid' === $key) {
                continue;
            }
            if (is_array($item)) {
                if (array_key_exists('entity_uuid', $item)) {
                    $item['entity_uuid'] = Uuid::v4();
                }
                $value[$key] = $this->regenerateNestedEntityUuids($item);
            }
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
