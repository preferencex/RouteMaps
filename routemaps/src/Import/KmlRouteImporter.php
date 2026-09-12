<?php

declare(strict_types=1);

namespace RouteMaps\Core\Import;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use RouteMaps\Core\Domain\Routes\RouteDraftData;
use RouteMaps\Core\Import\Geo\CoordinateNormalizer;
use RouteMaps\Core\Import\Security\ImportFileValidator;
use RuntimeException;

final class KmlRouteImporter implements RouteImporterInterface {
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
        return 'kml' === $file->extension();
    }

    public function inspect(ImportFile $file): ImportPreview {
        $this->validator->validate($file);
        return $this->inspectXml($file->contents());
    }

    public function import(ImportFile $file, ImportMapping $mapping): RouteDraftData {
        $preview = $this->inspect($file);
        return $this->draftFactory->create($preview, $mapping, pathinfo($file->originalName(), PATHINFO_FILENAME));
    }

    /** @param array<string,mixed> $extraMetadata */
    public function inspectXml(string $xml, string $format = 'kml', array $extraMetadata = []): ImportPreview {
        if (1 === preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) {
            throw new InvalidArgumentException('import_kml_external_entity');
        }
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('dom_extension_required');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
            if (!$loaded) {
                throw new InvalidArgumentException('import_kml_invalid');
            }
            $xpath = new DOMXPath($document);
            return $this->buildPreview($xpath, $format, $extraMetadata);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @param array<string,mixed> $extraMetadata */
    public function importXml(
        string $xml,
        ImportMapping $mapping,
        string $format = 'kml',
        array $extraMetadata = [],
        string $fallbackTitle = 'Rota importada'
    ): RouteDraftData {
        return $this->draftFactory->create(
            $this->inspectXml($xml, $format, $extraMetadata),
            $mapping,
            $fallbackTitle
        );
    }

    /** @param array<string,mixed> $extraMetadata */
    private function buildPreview(DOMXPath $xpath, string $format, array $extraMetadata): ImportPreview {
        $warnings = [];
        [$styles, $styleAliases, $styleWarnings] = $this->styles($xpath);
        $warnings = array_merge($warnings, $styleWarnings);
        $title = $this->firstText($xpath, null, '//*[local-name()="Document"]/*[local-name()="name"][1]');
        $points = [];
        $lines = [];
        $lineFeatures = [];
        $polygons = [];
        $categories = [];
        $routeStyle = [];

        $placemarks = $xpath->query('//*[local-name()="Placemark"]');
        if (false === $placemarks) {
            throw new InvalidArgumentException('import_kml_invalid');
        }

        foreach ($placemarks as $placemark) {
            if (!$placemark instanceof DOMElement) {
                continue;
            }
            $name = $this->firstText($xpath, $placemark, './*[local-name()="name"][1]');
            $description = $this->sanitizeDescription($this->firstText($xpath, $placemark, './*[local-name()="description"][1]'));
            $styleKey = ltrim($this->firstText($xpath, $placemark, './*[local-name()="styleUrl"][1]'), '#');
            if (isset($styleAliases[$styleKey])) {
                $styleKey = $styleAliases[$styleKey];
            }
            $style = $styles[$styleKey] ?? [];
            $sourceCategory = $this->folderName($xpath, $placemark);
            if ('' !== $sourceCategory && !isset($categories[$sourceCategory])) {
                $categories[$sourceCategory] = ['name' => $sourceCategory];
            }
            $extendedData = $this->extendedData($xpath, $placemark);

            $pointNodes = $xpath->query('.//*[local-name()="Point"]/*[local-name()="coordinates"]', $placemark);
            if (false !== $pointNodes && 0 < $pointNodes->length) {
                $coordinate = $this->coordinateTuple((string) $pointNodes->item(0)?->textContent);
                $point = [
                    'name' => $name,
                    'description' => $description,
                    'coordinates' => $coordinate,
                    'extended_data' => $extendedData,
                    'style' => $style,
                ];
                if ('' !== $sourceCategory) {
                    $point['source_category'] = $sourceCategory;
                }
                $points[] = $point;
            }

            $lineNodes = $xpath->query('.//*[local-name()="LineString"]/*[local-name()="coordinates"]', $placemark);
            if (false !== $lineNodes) {
                foreach ($lineNodes as $coordinatesNode) {
                    $line = $this->coordinateList((string) $coordinatesNode->textContent);
                    if (2 <= count($line)) {
                        $lines[] = $line;
                        $lineStyle = is_array($style['line'] ?? null) ? $style['line'] : [];
                        if ([] === $routeStyle && [] !== $lineStyle) {
                            $routeStyle = $lineStyle;
                        }
                        $lineFeatures[] = [
                            'name' => $name,
                            'description' => $description,
                            'style' => $lineStyle,
                            'extended_data' => $extendedData,
                        ];
                    }
                }
            }

            $polygonNodes = $xpath->query('.//*[local-name()="Polygon"]', $placemark);
            if (false !== $polygonNodes) {
                foreach ($polygonNodes as $polygonNode) {
                    $coordinateNodes = $xpath->query('.//*[local-name()="LinearRing"]/*[local-name()="coordinates"]', $polygonNode);
                    if (false === $coordinateNodes || 0 === $coordinateNodes->length) {
                        continue;
                    }
                    $rings = [];
                    foreach ($coordinateNodes as $coordinatesNode) {
                        $rings[] = $this->coordinateList((string) $coordinatesNode->textContent);
                    }
                    $polygons[] = [
                        'name' => $name,
                        'description' => $description,
                        'coordinates' => $rings,
                        'extended_data' => $extendedData,
                    ];
                }
            }
        }

        $geometry = $this->routeGeometry($lines, $points, $warnings);
        if ([] === $routeStyle) {
            $routeStyle = ['color' => '#00A099', 'width' => 4.0];
        }
        $metadata = array_merge($extraMetadata, [
            'route_style' => $routeStyle,
            'line_features' => $lineFeatures,
            'polygons' => $polygons,
        ]);

        return new ImportPreview(
            $format,
            '' !== $title ? $title : null,
            $geometry,
            $points,
            array_values($categories),
            array_values(array_unique($warnings)),
            $metadata
        );
    }

    /** @return array{0:array<string,array<string,mixed>>,1:array<string,string>,2:list<string>} */
    private function styles(DOMXPath $xpath): array {
        $styles = [];
        $aliases = [];
        $warnings = [];
        $nodes = $xpath->query('//*[local-name()="Style"][@id]');
        if (false !== $nodes) {
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $id = trim($node->getAttribute('id'));
                if ('' === $id) {
                    continue;
                }
                $style = [];
                foreach ($node->childNodes as $child) {
                    if (!$child instanceof DOMElement) {
                        continue;
                    }
                    $type = $child->localName;
                    if (!in_array($type, ['LineStyle', 'IconStyle', 'PolyStyle'], true)) {
                        $warnings[] = 'unsupported_kml_style:' . $type;
                        continue;
                    }
                    $color = $this->firstText($xpath, $child, './*[local-name()="color"][1]');
                    $mappedColor = $this->kmlColor($color);
                    if ('LineStyle' === $type) {
                        $line = [];
                        if (null !== $mappedColor) {
                            $line['color'] = $mappedColor;
                        }
                        $width = $this->firstText($xpath, $child, './*[local-name()="width"][1]');
                        if (is_numeric($width)) {
                            $line['width'] = (float) $width;
                        }
                        $style['line'] = $line;
                    } elseif (null !== $mappedColor) {
                        $style[strtolower(str_replace('Style', '', $type))]['color'] = $mappedColor;
                    }
                }
                $styles[$id] = $style;
            }
        }

        $maps = $xpath->query('//*[local-name()="StyleMap"][@id]');
        if (false !== $maps) {
            foreach ($maps as $map) {
                if (!$map instanceof DOMElement) {
                    continue;
                }
                $id = trim($map->getAttribute('id'));
                $pairs = $xpath->query('./*[local-name()="Pair"]', $map);
                if (false === $pairs) {
                    continue;
                }
                foreach ($pairs as $pair) {
                    $key = $this->firstText($xpath, $pair, './*[local-name()="key"][1]');
                    $url = ltrim($this->firstText($xpath, $pair, './*[local-name()="styleUrl"][1]'), '#');
                    if (('normal' === $key || '' === $key) && '' !== $url) {
                        $aliases[$id] = $url;
                        break;
                    }
                }
            }
        }
        return [$styles, $aliases, array_values(array_unique($warnings))];
    }

    /** @return array<string,string> */
    private function extendedData(DOMXPath $xpath, DOMElement $placemark): array {
        $data = [];
        $nodes = $xpath->query('.//*[local-name()="ExtendedData"]//*[local-name()="Data"][@name]', $placemark);
        if (false !== $nodes) {
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $name = trim($node->getAttribute('name'));
                $value = $this->firstText($xpath, $node, './*[local-name()="value"][1]');
                if ('' !== $name) {
                    $data[$name] = $value;
                }
            }
        }
        $simple = $xpath->query('.//*[local-name()="ExtendedData"]//*[local-name()="SimpleData"][@name]', $placemark);
        if (false !== $simple) {
            foreach ($simple as $node) {
                if ($node instanceof DOMElement && '' !== trim($node->getAttribute('name'))) {
                    $data[trim($node->getAttribute('name'))] = trim((string) $node->textContent);
                }
            }
        }
        return $data;
    }

    private function folderName(DOMXPath $xpath, DOMElement $placemark): string {
        $parent = $placemark->parentNode;
        while ($parent instanceof DOMNode) {
            if ($parent instanceof DOMElement && 'Folder' === $parent->localName) {
                return $this->firstText($xpath, $parent, './*[local-name()="name"][1]');
            }
            $parent = $parent->parentNode;
        }
        return '';
    }

    /** @return array{0:float,1:float} */
    private function coordinateTuple(string $value): array {
        $parts = preg_split('/\s*,\s*/', trim($value));
        if (!is_array($parts)) {
            throw new InvalidArgumentException('import_coordinate_invalid');
        }
        return $this->coordinates->point($parts);
    }

    /** @return list<array{0:float,1:float}> */
    private function coordinateList(string $value): array {
        $tuples = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tuples)) {
            throw new InvalidArgumentException('import_coordinate_invalid');
        }
        $points = [];
        foreach ($tuples as $tuple) {
            $points[] = $this->coordinateTuple($tuple);
        }
        return $points;
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

    private function firstText(DOMXPath $xpath, ?DOMNode $context, string $query): string {
        $nodes = null === $context ? $xpath->query($query) : $xpath->query($query, $context);
        if (false === $nodes || 0 === $nodes->length) {
            return '';
        }
        return trim((string) $nodes->item(0)?->textContent);
    }

    private function kmlColor(string $value): ?string {
        $value = strtolower(trim($value));
        if (1 !== preg_match('/^[0-9a-f]{8}$/', $value)) {
            return null;
        }
        $blue = substr($value, 2, 2);
        $green = substr($value, 4, 2);
        $red = substr($value, 6, 2);
        return '#' . strtoupper($red . $green . $blue);
    }

    private function sanitizeDescription(string $description): string {
        return function_exists('wp_kses_post') ? wp_kses_post($description) : strip_tags($description, '<p><strong><em><a><br>');
    }
}
