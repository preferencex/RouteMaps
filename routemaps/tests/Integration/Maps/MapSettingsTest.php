<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Maps;

use InvalidArgumentException;
use RouteMaps\Core\Maps\MapSettings;
use WP_UnitTestCase;

final class MapSettingsTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option(MapSettings::OPTION_NAME);
        parent::tearDown();
    }

    public function test_settings_are_sanitized_and_persisted_as_one_option(): void {
        $settings = new MapSettings();
        $saved = $settings->save([
            'primary_provider' => ' PMTiles ',
            'fallback_provider' => 'OpenFreeMap',
            'pmtiles_url' => 'https://maps.example.test/portugal.pmtiles',
            'pmtiles_path' => '/srv/maps/portugal.pmtiles',
            'style_json' => '{"version":8,"layers":[]}',
            'maptiler_key' => ' key-value ',
        ]);

        self::assertSame('pmtiles', $saved['primary_provider']);
        self::assertSame('openfreemap', $saved['fallback_provider']);
        self::assertSame('/srv/maps/portugal.pmtiles', $saved['pmtiles_path']);
        self::assertSame('key-value', $saved['maptiler_key']);
        self::assertSame($saved, get_option(MapSettings::OPTION_NAME));
    }

    public function test_maptiler_service_token_is_rejected_for_browser_provider(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maptiler_service_token_not_allowed');

        (new MapSettings())->save([
            'maptiler_key' => 'cd43c591d8404400a11e2fee48afedc9_f80f4be2adf86dfb6bc489229669877d6cfcad293f48ffe6e77898f25ab65607',
        ]);
    }

    public function test_invalid_style_json_is_rejected(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('map_style_json_invalid');

        (new MapSettings())->save(['style_json' => '{broken']);
    }
}
