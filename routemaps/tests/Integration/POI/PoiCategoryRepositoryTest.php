<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\POI;

use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbCategoryRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbPoiRepository;
use WP_UnitTestCase;

final class PoiCategoryRepositoryTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [new Migration001RoutesVersions(), new Migration002PoisCategories()]))->migrate();
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_pois');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_categories');
    }

    public function test_migration_creates_required_poi_and_category_columns(): void {
        global $wpdb;
        $poiColumns = array_column($wpdb->get_results('DESCRIBE ' . $wpdb->prefix . 'routemaps_pois', ARRAY_A), 'Field');
        $categoryColumns = array_column($wpdb->get_results('DESCRIBE ' . $wpdb->prefix . 'routemaps_categories', ARRAY_A), 'Field');

        foreach (['uuid', 'name', 'category_id', 'latitude', 'longitude', 'description', 'address', 'phone', 'website', 'opening_hours', 'route_note', 'main_attachment_id', 'gallery_json', 'icon', 'color', 'cta_json', 'status', 'created_by', 'updated_by', 'created_at', 'updated_at'] as $column) {
            self::assertContains($column, $poiColumns);
        }
        foreach (['uuid', 'name', 'slug', 'icon', 'color', 'sort_order', 'is_active'] as $column) {
            self::assertContains($column, $categoryColumns);
        }
        self::assertSame(2, (int) get_option('routemaps_db_version'));
    }

    public function test_categories_have_unique_uuid_slug_and_active_sort_order(): void {
        global $wpdb;
        $repository = new WpdbCategoryRepository($wpdb);
        $third = $repository->create('Miradouros', 'viewpoint', '#00A099', 30, true);
        $first = $repository->create('Farmácias', 'pharmacy', '#D93F3F', 10, true);
        $second = $repository->create('Miradouros', 'viewpoint', '#00A099', 20, true);
        $repository->setActive($third->id(), false);

        self::assertNotSame($first->uuid(), $second->uuid());
        self::assertSame('miradouros', $third->slug());
        self::assertSame('miradouros-2', $second->slug());

        $result = $repository->search(['active' => true], 1, 20);
        self::assertSame(['Farmácias', 'Miradouros'], array_map(static fn ($category) => $category->name(), $result['items']));
    }

    public function test_poi_round_trips_coordinates_gallery_cta_and_sanitized_fields(): void {
        global $wpdb;
        $categories = new WpdbCategoryRepository($wpdb);
        $pois = new WpdbPoiRepository($wpdb);
        $category = $categories->create('Miradouros', 'viewpoint', '#00A099', 10, true);

        $poi = $pois->create([
            'name' => 'São Leonardo <script>alert(1)</script>',
            'category_id' => $category->id(),
            'latitude' => 41.1234567,
            'longitude' => -8.7654321,
            'description' => '<p>Vista <strong>excelente</strong><script>bad()</script></p>',
            'address' => 'Estrada Nacional',
            'phone' => '+351 254 123 456',
            'website' => 'https://example.com/poi',
            'opening_hours' => '09:00-18:00',
            'route_note' => 'Paragem recomendada',
            'main_attachment_id' => 12,
            'gallery' => [12, 34],
            'icon' => 'viewpoint',
            'color' => '#00A099',
            'cta' => ['label' => 'Website', 'url' => 'https://example.com/poi'],
            'status' => 'active',
        ], 42);

        $loaded = $pois->findByUuid($poi->uuid());
        self::assertNotNull($loaded);
        self::assertSame('São Leonardo', $loaded->name());
        self::assertEqualsWithDelta(41.1234567, $loaded->latitude(), 0.00000001);
        self::assertEqualsWithDelta(-8.7654321, $loaded->longitude(), 0.00000001);
        self::assertSame([12, 34], $loaded->gallery());
        self::assertSame(['label' => 'Website', 'url' => 'https://example.com/poi'], $loaded->cta());
        self::assertStringNotContainsString('<script>', $loaded->description());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $loaded->uuid());
    }
}