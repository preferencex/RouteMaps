<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Versions;

use InvalidArgumentException;
use JsonException;
use RouteMaps\Core\Domain\Categories\CategoryRepositoryInterface;
use RouteMaps\Core\Domain\POI\Poi;
use RouteMaps\Core\Domain\POI\PoiRepositoryInterface;
use RouteMaps\Core\Domain\Routes\Route;

final class RouteSnapshotBuilder {
    public function __construct(
        private ?PoiRepositoryInterface $pois = null,
        private ?CategoryRepositoryInterface $categories = null
    ) {
    }

    /** @throws JsonException */
    public function build(Route $route, RouteVersion $draft): RouteSnapshot {
        $draftData = json_decode($draft->snapshotJson(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($draftData)) {
            throw new InvalidArgumentException('invalid_draft_snapshot');
        }

        $geometry = $draftData['geometry'] ?? null;
        if (!is_array($geometry) || !isset($geometry['type'], $geometry['coordinates'])) {
            throw new InvalidArgumentException('invalid_route_geometry');
        }

        $stops = $draftData['stops'] ?? null;
        if (!is_array($stops)) {
            throw new InvalidArgumentException('invalid_route_stops');
        }
        foreach ($stops as $stop) {
            if (!is_array($stop) || !$this->hasStableEntityUuid($stop['entity_uuid'] ?? null)) {
                throw new InvalidArgumentException('snapshot_stop_entity_uuid_required');
            }
        }

        $snapshot = [
            'route_uuid' => $route->uuid(),
            'title' => isset($draftData['title']) && is_string($draftData['title']) ? $draftData['title'] : $route->title(),
            'geometry' => $geometry,
            'stops' => array_values($stops),
            'pois' => $this->buildPois(is_array($draftData['pois'] ?? null) ? $draftData['pois'] : []),
            'categories' => is_array($draftData['categories'] ?? null) ? $draftData['categories'] : [],
            'display' => is_array($draftData['display'] ?? null) ? $draftData['display'] : [],
            'viewport' => is_array($draftData['viewport'] ?? null) ? $draftData['viewport'] : [],
            'route_style' => is_array($draftData['style'] ?? null) ? $draftData['style'] : [],
            'support_overrides' => is_array($draftData['support_overrides'] ?? null) ? $draftData['support_overrides'] : [],
            'map_source_id' => isset($draftData['map_source_override']) && is_string($draftData['map_source_override'])
                ? $draftData['map_source_override']
                : $route->mapSourceId(),
        ];

        $canonical = $this->normalize($snapshot);
        if (!is_array($canonical)) {
            throw new InvalidArgumentException('invalid_canonical_snapshot');
        }
        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new RouteSnapshot($canonical, $json, hash('sha256', $json));
    }

    /**
     * @param list<array<string,mixed>> $linkedPois
     * @return list<array<string,mixed>>
     */
    private function buildPois(array $linkedPois): array {
        if ([] === $linkedPois) {
            return [];
        }
        if (null === $this->pois || null === $this->categories) {
            throw new InvalidArgumentException('poi_snapshot_repositories_required');
        }

        $snapshotPois = [];
        foreach ($linkedPois as $index => $linked) {
            if (!is_array($linked) || !$this->hasStableEntityUuid($linked['entity_uuid'] ?? null)) {
                throw new InvalidArgumentException('snapshot_poi_entity_uuid_required');
            }
            if (!$this->hasStableEntityUuid($linked['source_poi_uuid'] ?? null)) {
                throw new InvalidArgumentException('snapshot_source_poi_uuid_required');
            }

            $poi = $this->pois->findByUuid((string) $linked['source_poi_uuid']);
            if (null === $poi) {
                throw new InvalidArgumentException('snapshot_source_poi_not_found');
            }
            $category = $this->categories->find($poi->categoryId());
            if (null === $category) {
                throw new InvalidArgumentException('snapshot_poi_category_not_found');
            }

            $snapshotPois[] = $this->snapshotPoi(
                $poi,
                (string) $linked['entity_uuid'],
                isset($linked['position']) ? max(1, (int) $linked['position']) : $index + 1,
                (bool) ($linked['required'] ?? false),
                [
                    'uuid' => $category->uuid(),
                    'name' => $category->name(),
                    'icon' => $category->icon(),
                    'color' => $category->color(),
                ]
            );
        }

        usort(
            $snapshotPois,
            static fn (array $left, array $right): int => $left['position'] <=> $right['position']
        );

        return $snapshotPois;
    }

    /** @param array{uuid:string,name:string,icon:string,color:string} $category */
    private function snapshotPoi(Poi $poi, string $entityUuid, int $position, bool $required, array $category): array {
        return [
            'entity_uuid' => $entityUuid,
            'source_poi_uuid' => $poi->uuid(),
            'position' => $position,
            'required' => $required,
            'coordinates' => [$poi->longitude(), $poi->latitude()],
            'category' => $category,
            'media' => [
                'main_attachment_id' => $poi->mainAttachmentId(),
                'gallery_attachment_ids' => $poi->gallery(),
            ],
            'display' => [
                'name' => $poi->name(),
                'description' => $poi->description(),
                'address' => $poi->address(),
                'phone' => $poi->phone(),
                'website' => $poi->website(),
                'opening_hours' => $poi->openingHours(),
                'route_note' => $poi->routeNote(),
                'icon' => $poi->icon(),
                'color' => $poi->color(),
                'cta' => $poi->cta(),
            ],
        ];
    }

    private function hasStableEntityUuid(mixed $value): bool {
        return is_string($value)
            && 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }

    private function normalize(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
