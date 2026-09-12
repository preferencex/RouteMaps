<?php

declare(strict_types=1);

namespace RouteMaps\Core\Import;

use InvalidArgumentException;
use RouteMaps\Core\Domain\Routes\RouteDraftData;
use RouteMaps\Core\Support\Uuid;

final class PreviewDraftFactory {
    public function create(ImportPreview $preview, ImportMapping $mapping, string $fallbackTitle): RouteDraftData {
        $geometry = $preview->geometry();
        if (!isset($geometry['type'], $geometry['coordinates']) || [] === $geometry['coordinates']) {
            throw new InvalidArgumentException('import_route_geometry_missing');
        }

        $stops = [];
        foreach ($preview->points() as $index => $point) {
            $sourceCategory = isset($point['source_category']) && is_string($point['source_category'])
                ? $point['source_category']
                : '';
            $stop = $point;
            $stop['entity_uuid'] = Uuid::v4();
            $stop['position'] = $index + 1;
            if ('' !== $sourceCategory) {
                $categoryId = $mapping->categoryIdFor($sourceCategory);
                if (null !== $categoryId) {
                    $stop['category_id'] = $categoryId;
                }
            }
            $stops[] = $stop;
        }

        $metadata = $preview->metadata();
        $style = isset($metadata['route_style']) && is_array($metadata['route_style'])
            ? $metadata['route_style']
            : ['color' => '#00A099', 'width' => 4.0];
        $categories = [];
        foreach ($preview->categories() as $category) {
            if (!isset($category['name']) || !is_string($category['name'])) {
                continue;
            }
            $item = $category;
            $mappedId = $mapping->categoryIdFor($category['name']);
            if (null !== $mappedId) {
                $item['category_id'] = $mappedId;
            }
            $categories[] = $item;
        }

        $title = trim((string) ($preview->title() ?? ''));
        if ('' === $title) {
            $title = trim($fallbackTitle);
        }
        if ('' === $title) {
            $title = 'Rota importada';
        }

        return new RouteDraftData(
            $title,
            $geometry,
            $stops,
            $style,
            $this->viewport($geometry, $stops),
            [],
            null,
            [],
            $categories,
            [
                'source_format' => $preview->format(),
                'import_warnings' => $preview->warnings(),
                'imported_polygons' => is_array($metadata['polygons'] ?? null) ? $metadata['polygons'] : [],
            ]
        );
    }

    /**
     * @param array<string,mixed> $geometry
     * @param list<array<string,mixed>> $stops
     * @return array{center:array{0:float,1:float},zoom:int}
     */
    private function viewport(array $geometry, array $stops): array {
        $points = [];
        $this->collectGeometryPoints($geometry['coordinates'] ?? [], $points);
        foreach ($stops as $stop) {
            if (isset($stop['coordinates']) && is_array($stop['coordinates']) && 2 <= count($stop['coordinates'])) {
                $points[] = [(float) $stop['coordinates'][0], (float) $stop['coordinates'][1]];
            }
        }
        if ([] === $points) {
            return ['center' => [-8.0, 39.5], 'zoom' => 6];
        }

        $longitudes = array_column($points, 0);
        $latitudes = array_column($points, 1);
        $minLon = min($longitudes);
        $maxLon = max($longitudes);
        $minLat = min($latitudes);
        $maxLat = max($latitudes);
        $span = max($maxLon - $minLon, $maxLat - $minLat);

        return [
            'center' => [round(($minLon + $maxLon) / 2, 7), round(($minLat + $maxLat) / 2, 7)],
            'zoom' => $this->zoomForSpan($span),
        ];
    }

    /** @param mixed $coordinates @param list<array{0:float,1:float}> $points */
    private function collectGeometryPoints(mixed $coordinates, array &$points): void {
        if (!is_array($coordinates) || [] === $coordinates) {
            return;
        }
        if (isset($coordinates[0], $coordinates[1]) && is_numeric($coordinates[0]) && is_numeric($coordinates[1])) {
            $points[] = [(float) $coordinates[0], (float) $coordinates[1]];
            return;
        }
        foreach ($coordinates as $nested) {
            $this->collectGeometryPoints($nested, $points);
        }
    }

    private function zoomForSpan(float $span): int {
        return match (true) {
            $span > 10.0 => 5,
            $span > 5.0 => 6,
            $span > 2.0 => 7,
            $span > 1.0 => 8,
            $span > 0.5 => 9,
            $span > 0.2 => 10,
            $span > 0.1 => 11,
            $span > 0.05 => 12,
            default => 13,
        };
    }
}
