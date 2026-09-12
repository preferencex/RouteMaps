<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Routes;

use LogicException;
use RouteMaps\Core\Domain\Routes\RouteDraftData;
use RouteMaps\Core\Domain\Routes\RouteDraftService;
use RouteMaps\Core\Domain\Versions\RoutePublisher;
use RouteMaps\Core\Domain\Versions\RouteSnapshotBuilder;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteVersionRepository;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use WP_UnitTestCase;

final class RoutePublisherTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        (new Migration001RoutesVersions())->up($wpdb);
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_route_versions');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_routes');
    }

    public function test_publication_is_atomic_updates_current_pointer_and_fires_event_once(): void {
        global $wpdb;
        $routes = new WpdbRouteRepository($wpdb);
        $versions = new WpdbRouteVersionRepository($wpdb);
        $route = $routes->create('Douro', 7);
        (new RouteDraftService($versions))->save($route->id(), $this->draftData(), 7);
        $publisher = new RoutePublisher($routes, $versions, new RouteSnapshotBuilder(), new TransactionManager($wpdb));

        $events = 0;
        $listener = static function () use (&$events): void { ++$events; };
        add_action('routemaps_route_published', $listener, 10, 2);

        $published = $publisher->publish($route->id(), 7, true, 'Atualização crítica');
        remove_action('routemaps_route_published', $listener, 10);

        self::assertSame('published', $published->state());
        self::assertTrue($published->isCritical());
        self::assertNotNull($published->publishedAt());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $published->contentHash());
        self::assertSame($published->id(), $routes->find($route->id())?->currentPublishedVersionId());
        self::assertSame('published', $routes->find($route->id())?->status());
        self::assertSame(1, $events);
    }

    public function test_published_snapshot_cannot_be_modified(): void {
        global $wpdb;
        $routes = new WpdbRouteRepository($wpdb);
        $versions = new WpdbRouteVersionRepository($wpdb);
        $route = $routes->create('Douro', 7);
        (new RouteDraftService($versions))->save($route->id(), $this->draftData(), 7);
        $publisher = new RoutePublisher($routes, $versions, new RouteSnapshotBuilder(), new TransactionManager($wpdb));
        $published = $publisher->publish($route->id(), 7, false, null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('published_version_immutable');
        $versions->updateSnapshot($published->id(), '{}', str_repeat('a', 64));
    }

    private function draftData(): RouteDraftData {
        return new RouteDraftData(
            'Douro',
            ['type' => 'LineString', 'coordinates' => [[-8.6, 41.1], [-7.8, 41.2]]],
            [['entity_uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Porto', 'coordinates' => [-8.6, 41.1]]],
            ['color' => '#00A099', 'width' => 4],
            ['center' => [-8.2, 41.15], 'zoom' => 9]
        );
    }
}
