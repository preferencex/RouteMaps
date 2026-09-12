<?php

declare(strict_types=1);

namespace RouteMaps\Core\Maps;

use InvalidArgumentException;

final class MapSettings {
    public const OPTION_NAME = 'routemaps_map_settings';

    /** @return array<string,mixed> */
    public function load(): array {
        $stored = get_option(self::OPTION_NAME, []);
        return array_replace($this->defaults(), is_array($stored) ? $stored : []);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(array $input): array {
        $clean = $this->sanitize($input);
        update_option(self::OPTION_NAME, $clean, false);
        return $clean;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function sanitize(array $input): array {
        $current = $this->load();
        $primary = sanitize_key((string) ($input['primary_provider'] ?? $current['primary_provider']));
        $fallback = sanitize_key((string) ($input['fallback_provider'] ?? $current['fallback_provider']));
        if ('' === $primary) {
            $primary = 'pmtiles';
        }
        if ('' === $fallback) {
            $fallback = 'openfreemap';
        }

        $styleJson = trim((string) ($input['style_json'] ?? $current['style_json']));
        if ('' !== $styleJson) {
            $decoded = json_decode($styleJson, true);
            if (!is_array($decoded) || JSON_ERROR_NONE !== json_last_error()) {
                throw new InvalidArgumentException('map_style_json_invalid');
            }
            $styleJson = (string) wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return [
            'primary_provider' => $primary,
            'fallback_provider' => $fallback,
            'pmtiles_url' => esc_url_raw((string) ($input['pmtiles_url'] ?? $current['pmtiles_url'])),
            'pmtiles_path' => sanitize_text_field((string) ($input['pmtiles_path'] ?? $current['pmtiles_path'])),
            'style_json' => $styleJson,
            'maptiler_key' => $this->sanitizeMapTilerKey((string) ($input['maptiler_key'] ?? $current['maptiler_key'])),
            'openfreemap_style_url' => esc_url_raw((string) ($input['openfreemap_style_url'] ?? $current['openfreemap_style_url'])),
        ];
    }

    private function sanitizeMapTilerKey(string $value): string {
        $key = trim(sanitize_text_field($value));
        if ('' !== $key && 1 === preg_match('/^[a-f0-9]{32}_[a-f0-9]{64}$/i', $key)) {
            throw new InvalidArgumentException('maptiler_service_token_not_allowed');
        }
        return $key;
    }

    /** @return array<string,mixed> */
    private function defaults(): array {
        return [
            'primary_provider' => 'pmtiles',
            'fallback_provider' => 'openfreemap',
            'pmtiles_url' => '',
            'pmtiles_path' => '',
            'style_json' => '',
            'maptiler_key' => '',
            'openfreemap_style_url' => '',
        ];
    }
}
