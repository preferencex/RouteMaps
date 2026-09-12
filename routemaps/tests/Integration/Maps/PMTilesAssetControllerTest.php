<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Maps;

use RouteMaps\Core\Maps\PMTilesAssetController;
use WP_UnitTestCase;

final class PMTilesAssetControllerTest extends WP_UnitTestCase {
    public function test_rewrite_matches_only_fixed_pmtiles_asset_path(): void {
        PMTilesAssetController::registerRewriteRules();

        global $wp_rewrite;
        $rules = $wp_rewrite->extra_rules_top;
        self::assertArrayHasKey('^routemaps/maps/base\\.pmtiles$', $rules);
        $pattern = '#^routemaps/maps/base\\.pmtiles$#';
        self::assertSame(1, preg_match($pattern, 'routemaps/maps/base.pmtiles'));
        self::assertSame(0, preg_match($pattern, 'routemaps/maps/other.pmtiles'));
        self::assertSame(0, preg_match($pattern, 'routemaps/maps/base.pmtiles/../secret'));
    }

    public function test_range_parser_supports_single_range_and_rejects_invalid_or_multi_range(): void {
        $controller = new PMTilesAssetController();

        self::assertSame([0, 9], $controller->parseRange('bytes=0-9', 100));
        self::assertSame([90, 99], $controller->parseRange('bytes=-10', 100));
        self::assertNull($controller->parseRange(null, 100));
        self::assertFalse($controller->parseRange('bytes=0-9,20-29', 100));
        self::assertFalse($controller->parseRange('bytes=200-300', 100));
    }
}
