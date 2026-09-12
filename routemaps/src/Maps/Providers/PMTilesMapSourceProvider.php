<?php

declare(strict_types=1);

namespace RouteMaps\Core\Maps\Providers;

use RouteMaps\Core\Maps\MapSettings;
use RouteMaps\Core\Maps\MapSourceHealth;
use RouteMaps\Core\Maps\MapSourceProviderInterface;

final class PMTilesMapSourceProvider implements MapSourceProviderInterface {
    public const ID = 'pmtiles';

    public function __construct(private MapSettings $settings) {
    }

    public function get_id(): string { return self::ID; }

    public function is_configured(): bool {
        $config = $this->settings->load();
        $path = trim((string) ($config['pmtiles_path'] ?? ''));
        if ('' !== $path) {
            return null !== $this->resolveLocalPath($path);
        }

        $url = trim((string) ($config['pmtiles_url'] ?? ''));
        return '' !== $url && 'https' === strtolower((string) parse_url($url, PHP_URL_SCHEME));
    }

    public function health_check(): MapSourceHealth {
        $config = $this->settings->load();
        $path = trim((string) ($config['pmtiles_path'] ?? ''));
        if ('' !== $path) {
            if (null === $this->resolveLocalPath($path)) {
                return new MapSourceHealth(false, 'pmtiles_path_unavailable', __('O ficheiro PMTiles local não está disponível ou não está numa localização permitida.', 'routemaps'));
            }
            return new MapSourceHealth(true, 'ok', __('PMTiles local disponível.', 'routemaps'));
        }

        $url = trim((string) ($config['pmtiles_url'] ?? ''));
        if ('' === $url) {
            return new MapSourceHealth(false, 'pmtiles_not_configured', __('Configure uma URL ou caminho PMTiles.', 'routemaps'));
        }
        if ('https' !== strtolower((string) parse_url($url, PHP_URL_SCHEME))) {
            return new MapSourceHealth(false, 'pmtiles_https_required', __('A origem PMTiles externa tem de utilizar HTTPS.', 'routemaps'));
        }
        if ($this->isUnsafeLiteralHost($url)) {
            return new MapSourceHealth(false, 'pmtiles_url_unsafe', __('A origem PMTiles externa não é permitida.', 'routemaps'));
        }

        $response = wp_remote_request($url, [
            'method' => 'GET',
            'headers' => ['Range' => 'bytes=0-0'],
            'timeout' => 3,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'limit_response_size' => 1,
        ]);
        if (is_wp_error($response)) {
            return new MapSourceHealth(false, 'pmtiles_unreachable', __('Não foi possível validar a origem PMTiles.', 'routemaps'));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $contentRange = strtolower((string) wp_remote_retrieve_header($response, 'content-range'));
        if (206 !== $code || !str_starts_with($contentRange, 'bytes 0-0/')) {
            return new MapSourceHealth(false, 'pmtiles_range_unsupported', __('A origem PMTiles não confirmou suporte a byte-range.', 'routemaps'));
        }

        return new MapSourceHealth(true, 'ok', __('Origem PMTiles disponível.', 'routemaps'));
    }

    /** @return array<string,mixed> */
    public function get_style_definition(): array {
        $sourceUrl = $this->sourceUrl();
        $config = $this->settings->load();
        $custom = trim((string) ($config['style_json'] ?? ''));
        if ('' !== $custom) {
            $style = json_decode($custom, true);
            if (is_array($style)) {
                $style['version'] = 8;
                $style['sources'] = is_array($style['sources'] ?? null) ? $style['sources'] : [];
                $style['sources']['routemaps-basemap'] = [
                    'type' => 'vector',
                    'url' => 'pmtiles://' . $sourceUrl,
                ];
                return $style;
            }
        }

        return [
            'version' => 8,
            'sources' => [
                'routemaps-basemap' => [
                    'type' => 'vector',
                    'url' => 'pmtiles://' . $sourceUrl,
                ],
            ],
            'layers' => [
                ['id' => 'background', 'type' => 'background', 'paint' => ['background-color' => '#f5f5f0']],
                ['id' => 'water', 'type' => 'fill', 'source' => 'routemaps-basemap', 'source-layer' => 'water', 'paint' => ['fill-color' => '#c9e6ef']],
                ['id' => 'landuse', 'type' => 'fill', 'source' => 'routemaps-basemap', 'source-layer' => 'landuse', 'paint' => ['fill-color' => '#e6eedf', 'fill-opacity' => 0.55]],
                ['id' => 'buildings', 'type' => 'fill', 'source' => 'routemaps-basemap', 'source-layer' => 'buildings', 'paint' => ['fill-color' => '#dedbd4', 'fill-opacity' => 0.8]],
                ['id' => 'roads', 'type' => 'line', 'source' => 'routemaps-basemap', 'source-layer' => 'roads', 'paint' => ['line-color' => '#ffffff', 'line-width' => 1.2]],
            ],
        ];
    }

    public function sourceUrl(): string {
        $config = $this->settings->load();
        $path = trim((string) ($config['pmtiles_path'] ?? ''));
        if ('' !== $path && null !== $this->resolveLocalPath($path)) {
            return home_url('/routemaps/maps/base.pmtiles');
        }
        return trim((string) ($config['pmtiles_url'] ?? ''));
    }

    public function configuredLocalPath(): ?string {
        $config = $this->settings->load();
        $path = trim((string) ($config['pmtiles_path'] ?? ''));
        return '' === $path ? null : $this->resolveLocalPath($path);
    }

    private function resolveLocalPath(string $path): ?string {
        $real = realpath($path);
        if (false === $real || !is_file($real) || !is_readable($real)) {
            return null;
        }

        $defaultRoots = defined('WP_CONTENT_DIR') ? [WP_CONTENT_DIR] : [];
        $roots = apply_filters('routemaps_pmtiles_allowed_roots', $defaultRoots);
        if (!is_array($roots)) {
            return null;
        }
        foreach ($roots as $root) {
            if (!is_string($root) || '' === trim($root)) {
                continue;
            }
            $realRoot = realpath($root);
            if (false === $realRoot || !is_dir($realRoot)) {
                continue;
            }
            $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with($real, $prefix)) {
                return $real;
            }
        }
        return null;
    }

    private function isUnsafeLiteralHost(string $url): bool {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ('' === $host || 'localhost' === strtolower($host)) {
            return true;
        }
        if (false === filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        return false === filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
