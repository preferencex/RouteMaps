<?php

declare(strict_types=1);

namespace RouteMaps\Core\Maps;

interface MapSourceProviderInterface {
    public function get_id(): string;

    /** @return array<string,mixed> */
    public function get_style_definition(): array;

    public function is_configured(): bool;
    public function health_check(): MapSourceHealth;
}
