<?php

declare(strict_types=1);

namespace RouteMaps\Core\Bootstrap;

use RouteMaps\Core\Admin\Capabilities;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration003Licenses;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration004AccessSharing;
use RouteMaps\Core\Viewer\RouteRewriteManager;
use RouteMaps\Core\Maps\PMTilesAssetController;

final class Activation {
    public static function activate(): void {
        global $wpdb;

        $manager = new MigrationManager(
            $wpdb,
            [new Migration001RoutesVersions(), new Migration002PoisCategories(), new Migration003Licenses(), new Migration004AccessSharing()]
        );
        $manager->migrate();

        Capabilities::grantToAdministrator();
        (new RouteRewriteManager())->registerRules();
        PMTilesAssetController::registerRewriteRules();
        flush_rewrite_rules(false);
    }
}
