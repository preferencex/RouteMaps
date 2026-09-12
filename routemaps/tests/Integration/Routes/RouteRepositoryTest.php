<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Routes;

use RouteMaps\Core\Domain\Routes\RouteDraftData;
use RouteMaps\Core\Domain\Routes\RouteDraftService;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteVersionRepository;
use WP_UnitTestCase;

final class RouteRepositoryTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();

        global $wpdb;
        (new Migration001RoutesVersions())->up($wpdb);
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_route_versions');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_routes');
    }

    public function test_route_round_trips_with_stable_uuid_and_unique_slug(): void {
        global $wpdb;

        $repository = new WpdbRouteRepository($wpdb);
        $route      = $repository->create('Douro Premium', 42);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $route->uuid()
        );
        self::assertSame('douro-premium', $route->slug());
        self::assertSame('draft', $route->status());
        self::assertSame(42, $route->createdBy());
        self::assertEquals($route, $repository->find($route->id()));
        self::assertEquals($route, $repository->findByUuid($route->uuid()));

        $second = $repository->create('Douro Premium', 43);
        self::assertSame('douro-premium-2', $second->slug());
    }

    public function test_draft_save_reuses_draft_but_never_mutates_published_version(): void {
        global $wpdb;

        $routes   = new WpdbRouteRepository($wpdb);
        $versions = new WpdbRouteVersionRepository($wpdb);
        $service  = new RouteDraftService($versions);
        $route    = $routes->create('Douro Premium', 42);
        $draft    = $this->draftData('Rota do Douro');

        $first = $service->save($route->id(), $draft, 42);
        $again = $service->save($route->id(), $draft, 42);

        self::assertSame($first->id(), $again->id());
        self::assertSame(1, $again->versionNumber());

        $wpdb->update(
            $wpdb->prefix . 'routemaps_route_versions',
            ['state' => 'published'],
            ['id' => $first->id()],
            ['%s'],
            ['%d']
        );

        $next = $service->save($route->id(), $draft, 42);
        self::assertNotSame($first->id(), $next->id());
        self::assertSame(2, $next->versionNumber());
        self::assertSame('draft', $next->state());
    }

    public function test_draft_data_rejects_invalid_title_and_geometry(): void {
        $this->expectException(\InvalidArgumentException::class);

        new RouteDraftData(
            '',
            ['type' => 'Point', 'coordinates' => [-8.61, 41.15]],
            [],
            ['color' => '#00A099'],
            ['center' => [-8.2, 41.16], 'zoom' => 9]
        );
    }

    private function draftData(string $title): RouteDraftData {
        return new RouteDraftData(
            $title,
            [
                'type' => 'LineString',
                'coordinates' => [[-8.61, 41.15], [-7.79, 41.16]],
            ],
            [
                [
                    'entity_uuid' => '11111111-1111-4111-8111-111111111111',
                    'name' => 'Porto',
                    'coordinates' => [-8.61, 41.15],
                ],
            ],
            ['color' => '#00A099', 'width' => 4],
            ['center' => [-8.2, 41.16], 'zoom' => 9]
        );
    }
}
