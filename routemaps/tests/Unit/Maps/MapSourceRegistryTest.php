<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\Maps;

use PHPUnit\Framework\TestCase;
use RouteMaps\Core\Maps\MapSourceHealth;
use RouteMaps\Core\Maps\MapSourceProviderInterface;
use RouteMaps\Core\Maps\MapSourceRegistry;

final class MapSourceRegistryTest extends TestCase {
    public function test_healthy_primary_is_selected_first(): void {
        $registry = new MapSourceRegistry([
            $this->provider('primary', true, true),
            $this->provider('fallback', true, true),
        ]);

        $selection = $registry->resolve('primary', 'fallback');

        self::assertTrue($selection->health()->ok());
        self::assertSame('primary', $selection->provider()?->get_id());
    }

    public function test_unhealthy_primary_falls_back_without_mutating_route_data(): void {
        $routeData = ['route_uuid' => 'route-1', 'geometry' => ['type' => 'LineString']];
        $registry = new MapSourceRegistry([
            $this->provider('primary', true, false),
            $this->provider('fallback', true, true),
        ]);

        $selection = $registry->resolve('primary', 'fallback');

        self::assertSame('fallback', $selection->provider()?->get_id());
        self::assertSame(['route_uuid' => 'route-1', 'geometry' => ['type' => 'LineString']], $routeData);
    }

    public function test_no_healthy_provider_returns_explicit_unavailable_health(): void {
        $registry = new MapSourceRegistry([
            $this->provider('primary', false, false),
            $this->provider('fallback', true, false),
        ]);

        $selection = $registry->resolve('primary', 'fallback');

        self::assertNull($selection->provider());
        self::assertFalse($selection->health()->ok());
        self::assertSame('map_source_unavailable', $selection->health()->code());
    }

    private function provider(string $id, bool $configured, bool $healthy): MapSourceProviderInterface {
        return new class($id, $configured, $healthy) implements MapSourceProviderInterface {
            public function __construct(private string $id, private bool $configured, private bool $healthy) {}
            public function get_id(): string { return $this->id; }
            public function get_style_definition(): array { return ['version' => 8, 'sources' => []]; }
            public function is_configured(): bool { return $this->configured; }
            public function health_check(): MapSourceHealth {
                return new MapSourceHealth($this->healthy, $this->healthy ? 'ok' : 'unhealthy', $this->healthy ? 'OK' : 'Unavailable');
            }
        };
    }
}
