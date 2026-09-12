<?php

declare(strict_types=1);

namespace RouteMaps\Core\Import;

use InvalidArgumentException;
use JsonException;
use RouteMaps\Core\Domain\Routes\RouteDraftData;
use RouteMaps\Core\Import\Geo\CoordinateNormalizer;
use RouteMaps\Core\Import\Security\ImportFileValidator;

final class GeoJsonRouteImporter implements RouteImporterInterface {
    public function __construct(
        private ?ImportFileValidator $validator = null,
        private ?CoordinateNormalizer $coordinates = null,
        private ?PreviewDraftFactory $draftFactory = null
    ) {
        $this->validator ??= new ImportFileValidator();
        $this->coordinates ??= new CoordinateNormalizer();
        $this->draftFactory ??= new PreviewDraftFactory();
    }

    public function supports(ImportFile $file): bool {
        return 'geojson' === $file->extension();
    }

    public function inspect(ImportFile $file): ImportPreview {
        $this->validator->validate($file);
        try {
            $document = json_decode($file->contents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('import_geojson_invalid', 0, $exception);
        }
        if (!is_array($document)) {
            throw new InvalidArgumentException('import_geojson_invalid');
        }
        return $this->inspectDocument($document);
    }

    public function import(ImportFile $file, ImportMapping $mapping): RouteDraftData {
        $preview = $this->inspect($file);
        return $this->draftFactory->create($preview, $mapping, pathinfo($file->originalName(), PATHINFO_FILENAME));
    }

    /** @param array<string,mixed> $document */
    private function inspectDocument(array $document): ImportPreview {
        $features = $this->features($document);
        $title = isset($document['name']) && is_string($document['name']) ? trim($document['name']) : null;
        $lines = [];
        $lineFeatures = [];
        $points = [];
        $polygons = [];
        $categories = [];
        $warnings = [];
        $routeStyle = [];

        foreach ($features as $feature) {
            $geometry = $feature['geometry'] ?? null;
            if (!is_array($geometry) || !is_string($geometry['type'] ?? null)) {
                $warnings[] = 'geojson_feature_without_geometry';
                continue;
            }
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $name = $this->propertyString($properties, 'name');
            $description = $this->sanitizeDescription($this->propertyString($properties, 'description'));
            $category = $this->propertyString($properties, 'category');
            if ('' !== $category && !isset($categories[$category])) {
                $categories[$category] = ['name' => $category];
            }

            switch ($geometry['type']) {
                case 'LineString':
                    $line = $this->coordinates->line($this->coordinateArray($geometry));
                    $lines[] = $line;
                    $style = $this->styleFromProperties($properties);
                    if ([] === $routeStyle && [] !== $style) {
                        $routeStyle = $style;
                    }
                    $lineFeatures[] = ['name' => $name, 'description' => $description, 'style' => $style];
                    break;
                case 'MultiLineString':
                    $multi = $this->coordinates->multiLine($this->coordinateArray($geometry));
                    foreach ($multi as $line) {
                        $lines[] = $line;
                    }
                    $style = $this->styleFromProperties($properties);
                    if ([] === $routeStyle && [] !== $style) {
                        $routeStyle = $style;
                    }
                    $lineFeatures[] = ['name' => $name, 'description' => $description, 'style' => $style];
                    break;
                case 'Point':
                    $point = [
                        'name' => $name,
                        'description' => $description,
                        'coordinates' => $this->coordinates->point($this->coordinateArray($geometry)),
                        'extended_data' => $properties,
                    ];
                    if ('' !== $category) {
                        $point['source_category'] = $category;
                    }
                    $points[] = $point;
                    break;
                case 'Polygon':
                    $polygons[] = [
                        'name' => $name,
                        'description' => $description,
                        'coordinates' => $this->coordinates->polygon($this->coordinateArray($geometry)),
                        'properties' => $properties,
                    ];
                    break;
                default:
                    $warnings[] = 'unsupported_geojson_geometry:' . $geometry['type'];
                    break;
            }
        }

        $geometry = $this->routeGeometry($lines, $points, $warnings);
        if ([] === $routeStyle) {
            $routeStyle = ['color' => '#00A099', 'width' => 4.0];
        }

        return new ImportPreview(
            'geojson',
            null !== $title && '' !== $title ? $title : null,
            $geometry,
            $points,
            array_values($categories),
            array_values(array_unique($warnings)),
            ['route_style' => $routeStyle, 'line_features' => $lineFeatures, 'polygons' => $polygons]
        );
    }

    /** @param array<string,mixed> $document @return list<array<string,mixed>> */
    private function features(array $document): array {
        $type = $document['type'] ?? null;
        if ('FeatureCollection' === $type) {
            $features = $document['features'] ?? null;
            if (!is_array($features)) {
                throw new InvalidArgumentException('import_geojson_invalid');
            }
            return array_values(array_filter($features, 'is_array'));
        }
        if ('Feature' === $type) {
            return [$document];
        }
        if (is_string($type) && in_array($type, ['LineString', 'MultiLineString', 'Point', 'Polygon'], true)) {
            return [['type' => 'Feature', 'properties' => [], 'geometry' => $document]];
        }
        throw new InvalidArgumentException('import_geojson_type_unsupported');
    }

    /** @param array<string,mixed> $geometry @return array<mixed> */
    private function coordinateArray(array $geometry): array {
        $coordinates = $geometry['coordinates'] ?? null;
        if (!is_array($coordinates)) {
            throw new InvalidArgumentException('import_coordinate_invalid');
        }
        return $coordinates;
    }

    /**
     * @param list<list<array{0:float,1:float}>> $lines
     * @param list<array<string,mixed>> $points
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function routeGeometry(array $lines, array $points, array &$warnings): array {
        if (1 === count($lines)) {
            return ['type' => 'LineString', 'coordinates' => $lines[0]];
        }
        if (1 < count($lines)) {
            return ['type' => 'MultiLineString', 'coordinates' => $lines];
        }
        if (2 <= count($points)) {
            $warnings[] = 'route_geometry_built_from_points';
            return ['type' => 'LineString', 'coordinates' => array_column($points, 'coordinates')];
        }
        return [];
    }

    /** @param array<string,mixed> $properties @return array<string,mixed> */
    private function styleFromProperties(array $properties): array {
        $style = [];
        if (isset($properties['stroke']) && is_string($properties['stroke']) && 1 === preg_match('/^#[0-9a-f]{6}$/i', $properties['stroke'])) {
            $style['color'] = strtoupper($properties['stroke']);
        }
        if (isset($properties['stroke-width']) && is_numeric($properties['stroke-width'])) {
            $style['width'] = (float) $properties['stroke-width'];
        }
        return $style;
    }

    /** @param array<string,mixed> $properties */
    private function propertyString(array $properties, string $key): string {
        $value = $properties[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function sanitizeDescription(string $description): string {
        return function_exists('wp_kses_post') ? wp_kses_post($description) : strip_tags($description, '<p><strong><em><a><br>');
    }
}
