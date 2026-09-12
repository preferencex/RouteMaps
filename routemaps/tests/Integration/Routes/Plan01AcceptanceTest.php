<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Routes;

use RouteMaps\Core\Bootstrap\Activation;
use RouteMaps\Core\Domain\Routes\RouteDraftData;
use RouteMaps\Core\Domain\Routes\RouteDraftService;
use RouteMaps\Core\Domain\Versions\RoutePublisher;
use RouteMaps\Core\Domain\Versions\RouteSnapshotBuilder;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteVersionRepository;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use WP_UnitTestCase;

final class Plan01AcceptanceTest extends WP_UnitTestCase {
    public function test_foundation_preserves_published_versions_across_critical_update(): void {
        global $wpdb;

        delete_option('routemaps_db_version');
        Activation::activate();
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_route_versions');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_routes');

        $routes = new WpdbRouteRepository($wpdb);
        $versions = new WpdbRouteVersionRepository($wpdb);
        $drafts = new RouteDraftService($versions);
        $publisher = new RoutePublisher(
            $routes,
            $versions,
            new RouteSnapshotBuilder(),
            new TransactionManager($wpdb)
        );

        $route = $routes->create('Douro Premium', 42);
        $stableEntityUuid = '11111111-1111-4111-8111-111111111111';

        $drafts->save($route->id(), $this->draft('Douro v1', $stableEntityUuid, [-8.61, 41.15]), 42);
        $v1 = $publisher->publish($route->id(), 42, false, 'Versão inicial');
        $v1SnapshotBefore = $v1->snapshotJson();
        $v1HashBefore = $v1->contentHash();

        $drafts->save($route->id(), $this->draft('Douro v2', $stableEntityUuid, [-8.50, 41.20]), 42);
        $v2 = $publisher->publish($route->id(), 42, true, 'Atualização crítica');

        $persistedV1 = $versions->findPublishedForRoute($route->id(), $v1->id());
        $persistedV2 = $versions->findPublishedForRoute($route->id(), $v2->id());
        self::assertNotNull($persistedV1);
        self::assertNotNull($persistedV2);

        $snapshot1 = json_decode($persistedV1->snapshotJson(), true, 512, JSON_THROW_ON_ERROR);
        $snapshot2 = json_decode($persistedV2->snapshotJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $persistedV1->versionNumber());
        self::assertSame(2, $persistedV2->versionNumber());
        self::assertFalse($persistedV1->isCritical());
        self::assertTrue($persistedV2->isCritical());
        self::assertSame('Douro v1', $snapshot1['title']);
        self::assertSame('Douro v2', $snapshot2['title']);
        self::assertSame([-8.61, 41.15], $snapshot1['stops'][0]['coordinates']);
        self::assertSame([-8.50, 41.20], $snapshot2['stops'][0]['coordinates']);
        self::assertSame($stableEntityUuid, $snapshot1['stops'][0]['entity_uuid']);
        self::assertSame($stableEntityUuid, $snapshot2['stops'][0]['entity_uuid']);
        self::assertSame($v1SnapshotBefore, $persistedV1->snapshotJson());
        self::assertSame($v1HashBefore, $persistedV1->contentHash());
        self::assertNotSame($persistedV1->contentHash(), $persistedV2->contentHash());
        self::assertSame($persistedV2->id(), $routes->find($route->id())?->currentPublishedVersionId());
    }

    /** @param array{0:float,1:float} $coordinates */
    private function draft(string $title, string $entityUuid, array $coordinates): RouteDraftData {
        return new RouteDraftData(
            $title,
            ['type' => 'LineString', 'coordinates' => [[-8.61, 41.15], [-7.79, 41.16]]],
            [[
                'entity_uuid' => $entityUuid,
                'name' => 'Paragem principal',
                'coordinates' => $coordinates,
            ]],
            ['color' => '#00A099', 'width' => 4],
            ['center' => [-8.2, 41.16], 'zoom' => 9]
        );
    }
}