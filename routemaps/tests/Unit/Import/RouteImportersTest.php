<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\Import;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RouteMaps\Core\Import\GeoJsonRouteImporter;
use RouteMaps\Core\Import\ImportFile;
use RouteMaps\Core\Import\ImportMapping;
use RouteMaps\Core\Import\KmlRouteImporter;
use RouteMaps\Core\Import\KmzRouteImporter;

final class RouteImportersTest extends TestCase {
    public function test_kml_imports_google_my_maps_point_line_extended_data_and_style(): void {
        self::assertTrue(class_exists(\DOMDocument::class), 'ext-dom is required for KML imports.');
        $file = $this->fixture('google-my-maps.kml', 'application/vnd.google-earth.kml+xml');
        $importer = new KmlRouteImporter();

        $preview = $importer->inspect($file);

        self::assertSame('kml', $preview->format());
        self::assertSame('Douro My Maps', $preview->title());
        self::assertSame('LineString', $preview->geometry()['type']);
        self::assertSame([[-8.61, 41.15], [-8.1, 41.12], [-7.79, 41.16]], $preview->geometry()['coordinates']);
        self::assertSame('Miradouro de São Leonardo', $preview->points()[0]['name']);
        self::assertSame([-7.79, 41.16], $preview->points()[0]['coordinates']);
        self::assertSame('Miradouros', $preview->points()[0]['source_category']);
        self::assertSame('+351 254 000 000', $preview->points()[0]['extended_data']['phone']);
        self::assertStringNotContainsString('<script', $preview->points()[0]['description']);
        self::assertSame('Percurso principal', $preview->metadata()['line_features'][0]['name']);
        self::assertSame('#00A099', $preview->metadata()['route_style']['color']);
        self::assertSame(5.0, $preview->metadata()['route_style']['width']);
        self::assertContains('unsupported_kml_style:BalloonStyle', $preview->warnings());

        $draft = $importer->import($file, new ImportMapping(['Miradouros' => 9]));
        self::assertSame('Douro My Maps', $draft->title());
        self::assertSame(9, $draft->stops()[0]['category_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $draft->stops()[0]['entity_uuid']);
        self::assertContains('unsupported_kml_style:BalloonStyle', $draft->display()['import_warnings']);
    }

    public function test_kmz_reads_kml_entry_without_extracting_archive(): void {
        self::assertTrue(class_exists(\ZipArchive::class), 'ext-zip is required for KMZ imports.');
        self::assertTrue(class_exists(\DOMDocument::class), 'ext-dom is required for KML imports.');
        $file = $this->fixture('google-my-maps.kmz', 'application/vnd.google-earth.kmz');
        $importer = new KmzRouteImporter();

        $preview = $importer->inspect($file);

        self::assertSame('kmz', $preview->format());
        self::assertSame('Douro My Maps', $preview->title());
        self::assertSame('Miradouro de São Leonardo', $preview->points()[0]['name']);
        self::assertSame('doc.kml', $preview->metadata()['kmz_entry']);
    }

    public function test_geojson_imports_line_point_polygon_and_generates_stable_draft_identity(): void {
        $file = $this->fixture('route.geojson', 'application/geo+json');
        $importer = new GeoJsonRouteImporter();

        $preview = $importer->inspect($file);

        self::assertSame('geojson', $preview->format());
        self::assertSame('Rota GeoJSON', $preview->title());
        self::assertSame('LineString', $preview->geometry()['type']);
        self::assertSame('Restaurante Ribeira', $preview->points()[0]['name']);
        self::assertSame('Restaurantes', $preview->points()[0]['source_category']);
        self::assertCount(1, $preview->metadata()['polygons']);

        $draft = $importer->import($file, new ImportMapping(['Restaurantes' => 3]));
        self::assertSame(3, $draft->stops()[0]['category_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $draft->stops()[0]['entity_uuid']);
        self::assertSame('#00A099', $draft->style()['color']);
        self::assertCount(1, $draft->display()['imported_polygons']);
    }

    public function test_geojson_rejects_coordinates_outside_world_bounds(): void {
        $path = tempnam(sys_get_temp_dir(), 'routemaps-invalid-geojson-');
        self::assertIsString($path);
        file_put_contents($path, '{"type":"LineString","coordinates":[[181,41],[-8,41]]}');

        try {
            $file = new ImportFile($path, 'invalid.geojson', 'application/json');
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('import_coordinate_out_of_bounds');
            (new GeoJsonRouteImporter())->inspect($file);
        } finally {
            @unlink($path);
        }
    }

    private function fixture(string $name, string $mime): ImportFile {
        return new ImportFile(dirname(__DIR__, 2) . '/Fixtures/import/' . $name, $name, $mime);
    }
}
