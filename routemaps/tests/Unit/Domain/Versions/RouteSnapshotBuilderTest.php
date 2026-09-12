<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\Domain\Versions;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RouteMaps\Core\Domain\Routes\Route;
use RouteMaps\Core\Domain\Versions\RouteSnapshotBuilder;
use RouteMaps\Core\Domain\Versions\RouteVersion;

final class RouteSnapshotBuilderTest extends TestCase {
    public function test_associative_key_order_does_not_change_hash_and_stop_order_is_preserved(): void {
        $builder = new RouteSnapshotBuilder();
        $route = $this->route();

        $first = $this->draft(json_encode([
            'title' => 'Douro',
            'geometry' => ['coordinates' => [[-8.6, 41.1], [-7.8, 41.2]], 'type' => 'LineString'],
            'stops' => [
                ['name' => 'Porto', 'entity_uuid' => '11111111-1111-4111-8111-111111111111', 'coordinates' => [-8.6, 41.1]],
                ['name' => 'Pinhão', 'entity_uuid' => '22222222-2222-4222-8222-222222222222', 'coordinates' => [-7.5, 41.2]],
            ],
            'style' => ['width' => 4, 'color' => '#00A099'],
            'viewport' => ['zoom' => 9, 'center' => [-8.2, 41.15]],
            'support_overrides' => [],
            'map_source_override' => null,
        ], JSON_THROW_ON_ERROR));

        $second = $this->draft(json_encode([
            'viewport' => ['center' => [-8.2, 41.15], 'zoom' => 9],
            'map_source_override' => null,
            'style' => ['color' => '#00A099', 'width' => 4],
            'support_overrides' => [],
            'stops' => [
                ['coordinates' => [-8.6, 41.1], 'entity_uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Porto'],
                ['coordinates' => [-7.5, 41.2], 'name' => 'Pinhão', 'entity_uuid' => '22222222-2222-4222-8222-222222222222'],
            ],
            'geometry' => ['type' => 'LineString', 'coordinates' => [[-8.6, 41.1], [-7.8, 41.2]]],
            'title' => 'Douro',
        ], JSON_THROW_ON_ERROR));

        $snapshotA = $builder->build($route, $first);
        $snapshotB = $builder->build($route, $second);

        self::assertSame($snapshotA->contentHash(), $snapshotB->contentHash());
        self::assertSame(['Porto', 'Pinhão'], array_column($snapshotA->data()['stops'], 'name'));
        self::assertSame('11111111-1111-4111-8111-111111111111', $snapshotA->data()['stops'][0]['entity_uuid']);
    }

    public function test_stop_without_entity_uuid_is_rejected(): void {
        $builder = new RouteSnapshotBuilder();
        $draft = $this->draft(json_encode([
            'title' => 'Douro',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[0, 0], [1, 1]]],
            'stops' => [['name' => 'Sem UUID', 'coordinates' => [0, 0]]],
            'style' => [],
            'viewport' => [],
            'support_overrides' => [],
            'map_source_override' => null,
        ], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $builder->build($this->route(), $draft);
    }

    private function route(): Route {
        return new Route(1, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Douro', 'douro', 'draft', null, null, null, 1, '2026-09-11 10:00:00', '2026-09-11 10:00:00');
    }

    private function draft(string $json): RouteVersion {
        return new RouteVersion(1, 1, 1, 'draft', false, $json, null, '', 1, '2026-09-11 10:00:00', null);
    }
}
