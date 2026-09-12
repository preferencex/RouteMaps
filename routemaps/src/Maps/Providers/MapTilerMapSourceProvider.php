<?php

declare(strict_types=1);

namespace RouteMaps\Core\Maps\Providers;

use RouteMaps\Core\Maps\MapSettings;
use RouteMaps\Core\Maps\MapSourceHealth;
use RouteMaps\Core\Maps\MapSourceProviderInterface;

final class MapTilerMapSourceProvider implements MapSourceProviderInterface {
    public const ID = 'maptiler';

    public function __construct(private MapSettings $settings) {
    }

    public function get_id(): string { return self::ID; }
    public function is_configured(): bool { return '' !== $this->key(); }

    /** @return array<string,mixed> */
    public function get_style_definition(): array {
        return ['kind' => 'style_url', 'url' => 'https://api.maptiler.com/maps/streets-v2/style.json?key=' . rawurlencode($this->key())];
    }

    public function health_check(): MapSourceHealth {
        if (!$this->is_configured()) {
            return new MapSourceHealth(false, 'maptiler_key_missing', __('Configure a chave MapTiler.', 'routemaps'));
        }
        return new MapSourceHealth(true, 'ok', __('MapTiler configurado.', 'routemaps'));
    }

    private function key(): string {
        return trim((string) ($this->settings->load()['maptiler_key'] ?? ''));
    }
}
