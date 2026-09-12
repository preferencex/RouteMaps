<?php

declare(strict_types=1);

namespace RouteMaps\Core\Maps;

final class MapSourceSelection {
    public function __construct(
        private ?MapSourceProviderInterface $provider,
        private MapSourceHealth $health
    ) {
    }

    public function provider(): ?MapSourceProviderInterface { return $this->provider; }
    public function health(): MapSourceHealth { return $this->health; }
}
