<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\Maps;

use RouteMaps\Core\Maps\MapSettings;
use RouteMaps\Core\Maps\Providers\MapTilerMapSourceProvider;
use RouteMaps\Core\Maps\Providers\OpenFreeMapSourceProvider;
use RouteMaps\Core\Maps\Providers\PMTilesMapSourceProvider;
use WP_UnitTestCase;

final class MapProvidersTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option(MapSettings::OPTION_NAME);
        parent::tearDown();
    }

    public function test_pmtiles_rejects_insecure_external_url(): void {
        (new MapSettings())->save(['pmtiles_url' => 'http://maps.example.test/portugal.pmtiles']);
        $provider = new PMTilesMapSourceProvider(new MapSettings());

        self::assertFalse($provider->is_configured());
        self::assertSame('pmtiles_https_required', $provider->health_check()->code());
    }

    public function test_maptiler_requires_key_and_openfreemap_does_not(): void {
        $settings = new MapSettings();
        self::assertFalse((new MapTilerMapSourceProvider($settings))->is_configured());
        self::assertTrue((new OpenFreeMapSourceProvider($settings))->is_configured());

        $settings->save(['maptiler_key' => 'public-key']);
        self::assertTrue((new MapTilerMapSourceProvider($settings))->is_configured());
    }

    public function test_pmtiles_remote_health_requires_partial_content(): void {
        (new MapSettings())->save(['pmtiles_url' => 'https://maps.example.test/portugal.pmtiles']);
        add_filter('pre_http_request', static fn () => [
            'headers' => ['content-range' => 'bytes 0-0/100', 'accept-ranges' => 'bytes'],
            'body' => 'x',
            'response' => ['code' => 206, 'message' => 'Partial Content'],
            'cookies' => [],
            'filename' => null,
        ], 10, 3);

        $health = (new PMTilesMapSourceProvider(new MapSettings()))->health_check();

        remove_all_filters('pre_http_request');
        self::assertTrue($health->ok());
        self::assertSame('ok', $health->code());
    }
}
