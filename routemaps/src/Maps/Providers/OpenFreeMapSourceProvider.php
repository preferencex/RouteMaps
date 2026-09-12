<?php

declare(strict_types=1);

namespace RouteMaps\Core\Maps\Providers;

use RouteMaps\Core\Maps\MapSettings;
use RouteMaps\Core\Maps\MapSourceHealth;
use RouteMaps\Core\Maps\MapSourceProviderInterface;

final class OpenFreeMapSourceProvider implements MapSourceProviderInterface {
    public const ID = 'openfreemap';
    private const DEFAULT_STYLE = 'https://tiles.openfreemap.org/styles/liberty';

    public function __construct(private MapSettings $settings) {
    }

    public function get_id(): string { return self::ID; }
    public function is_configured(): bool { return 'https' === strtolower((string) parse_url($this->styleUrl(), PHP_URL_SCHEME)); }

    /** @return array<string,mixed> */
    public function get_style_definition(): array {
        return ['kind' => 'style_url', 'url' => $this->styleUrl()];
    }

    public function health_check(): MapSourceHealth {
        if (!$this->is_configured()) {
            return new MapSourceHealth(false, 'openfreemap_style_invalid', __('O URL de estilo OpenFreeMap não é válido.', 'routemaps'));
        }
        return new MapSourceHealth(true, 'ok', __('OpenFreeMap configurado.', 'routemaps'));
    }

    public function styleUrl(): string {
        $value = trim((string) ($this->settings->load()['openfreemap_style_url'] ?? ''));
        return '' !== $value ? $value : self::DEFAULT_STYLE;
    }
}
