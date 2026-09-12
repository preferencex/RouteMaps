<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\POI;

use RouteMaps\Core\Domain\Routes\RouteDraftData;
use RouteMaps\Core\Domain\Routes\RouteDraftService;
use RouteMaps\Core\Domain\Versions\RoutePublisher;
use RouteMaps\Core\Domain\Versions\RouteSnapshotBuilder;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbCategoryRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbPoiRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteVersionRepository;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use WP_UnitTestCase;

final class PublishedPoiSnapshotTest extends WP_UnitTestCase {
    public function test_published_snapshot_keeps_historical_poi_copy_after_library_edit(): void {
        global $wpdb;

        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [new Migration001RoutesVersions(), new Migration002PoisCategories()]))->migrate();
        foreach (['routemaps_route_versions', 'routemaps_routes', 'routemaps_pois', 'routemaps_categories'] as $suffix) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $suffix);
        }

        $routes = new WpdbRouteRepository($wpdb);
        $versions = new WpdbRouteVersionRepository($wpdb);
        $categories = new WpdbCategoryRepository($wpdb);
        $pois = new WpdbPoiRepository($wpdb);
        $drafts = new RouteDraftService($versions);
        $publisher = new RoutePublisher(
            $routes,
            $versions,
            new RouteSnapshotBuilder($pois, $categories),
            new TransactionManager($wpdb)
        );

        $originalCategory = $categories->create('Miradouros', 'viewpoint', '#00A099', 10, true);
        $replacementCategory = $categories->create('Natureza', 'nature', '#2E7D32', 20, true);
        $poi = $pois->create([
            'name' => 'Miradouro Original',
            'category_id' => $originalCategory->id(),
            'latitude' => 41.1600000,
            'longitude' => -7.7900000,
            'description' => '<strong>Vista original</strong>',
            'address' => 'Estrada original',
            'phone' => '+351 200 000 000',
            'website' => 'https://example.test/original',
            'opening_hours' => '09:00-18:00',
            'route_note' => 'Levar água',
            'main_attachment_id' => 101,
            'gallery' => [102, 103],
            'icon' => 'camera',
            'color' => '#00A099',
            'cta' => ['label' => 'Saber mais', 'url' => 'https://example.test/original'],
            'status' => 'active',
        ], 7);

        $route = $routes->create('Douro', 7);
        $routePoiUuid = '33333333-3333-4333-8333-333333333333';
        $drafts->save($route->id(), $this->draft($routePoiUuid, $poi->uuid()), 7);
        $v1 = $publisher->publish($route->id(), 7, false, 'Original');

        $pois->update($poi->id(), [
            'name' => 'Miradouro Atualizado',
            'category_id' => $replacementCategory->id(),
            'latitude' => 41.1700000,
            'longitude' => -7.7800000,
            'description' => '<em>Vista atualizada</em>',
            'address' => 'Estrada nova',
            'phone' => '+351 211 111 111',
            'website' => 'https://example.test/novo',
            'opening_hours' => '10:00-19:00',
            'route_note' => 'Novo acesso',
            'main_attachment_id' => 201,
            'gallery' => [202],
            'icon' => 'tree',
            'color' => '#2E7D32',
            'cta' => ['label' => 'Visitar', 'url' => 'https://example.test/novo'],
            'status' => 'active',
        ], 7);

        $drafts->save($route->id(), $this->draft($routePoiUuid, $poi->uuid()), 7);
        $v2 = $publisher->publish($route->id(), 7, false, 'Atualizada');

        $historical = json_decode((string) $versions->findPublishedForRoute($route->id(), $v1->id())?->snapshotJson(), true, 512, JSON_THROW_ON_ERROR);
        $current = json_decode((string) $versions->findPublishedForRoute($route->id(), $v2->id())?->snapshotJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Miradouro Original', $historical['pois'][0]['display']['name']);
        self::assertSame(101, $historical['pois'][0]['media']['main_attachment_id']);
        self::assertSame('Miradouros', $historical['pois'][0]['category']['name']);
        self::assertSame([-7.79, 41.16], $historical['pois'][0]['coordinates']);
        self::assertSame($routePoiUuid, $historical['pois'][0]['entity_uuid']);
        self::assertSame($poi->uuid(), $historical['pois'][0]['source_poi_uuid']);
        self::assertSame(1, $historical['pois'][0]['position']);
        self::assertTrue($historical['pois'][0]['required']);

        self::assertSame('Miradouro Atualizado', $current['pois'][0]['display']['name']);
        self::assertSame(201, $current['pois'][0]['media']['main_attachment_id']);
        self::assertSame('Natureza', $current['pois'][0]['category']['name']);
        self::assertSame([-7.78, 41.17], $current['pois'][0]['coordinates']);

        self::assertSame('Miradouro Original', $historical['pois'][0]['display']['name']);
        self::assertSame(101, $historical['pois'][0]['media']['main_attachment_id']);
    }

    private function draft(string $entityUuid, string $sourcePoiUuid): RouteDraftData {
        return new RouteDraftData(
            'Douro',
            ['type' => 'LineString', 'coordinates' => [[-8.61, 41.15], [-7.79, 41.16]]],
            [['entity_uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Porto', 'coordinates' => [-8.61, 41.15]]],
            ['color' => '#00A099', 'width' => 4],
            ['center' => [-8.2, 41.16], 'zoom' => 9],
            [],
            null,
            [[
                'entity_uuid' => $entityUuid,
                'source_poi_uuid' => $sourcePoiUuid,
                'position' => 1,
                'required' => true,
            ]]
        );
    }
}
