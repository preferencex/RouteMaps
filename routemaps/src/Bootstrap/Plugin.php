<?php

declare(strict_types=1);

namespace RouteMaps\Core\Bootstrap;

use RouteMaps\Core\Admin\RoutesPage;
use RouteMaps\Core\Admin\LicensesPage;
use RouteMaps\Core\Admin\MapSettingsPage;
use RouteMaps\Core\Admin\SystemStatusPage;
use RouteMaps\Core\Commerce\Checkout\RouteProductCartGuard;
use RouteMaps\Core\Security\TokenService;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseRepository;
use RouteMaps\Core\Domain\Licensing\LicenseIssuer;
use RouteMaps\Core\Domain\Licensing\LicenseStatusService;
use RouteMaps\Core\Domain\Licensing\LicenseValidityService;
use RouteMaps\Core\Commerce\Orders\LicenseOrderListener;
use RouteMaps\Core\Commerce\Email\RouteAccessEmail;
use RouteMaps\Core\Commerce\Email\RouteShareInviteEmail;
use RouteMaps\Core\Support\RouteUrl;
use RouteMaps\Core\Commerce\Product\RouteProductMeta;
use RouteMaps\Core\Commerce\Product\RouteProductPanel;
use RouteMaps\Core\Domain\Routes\RouteDraftService;
use RouteMaps\Core\Domain\Routes\RouteDuplicateService;
use RouteMaps\Core\Domain\Versions\RoutePublisher;
use RouteMaps\Core\Domain\Versions\RouteSnapshotBuilder;
use RouteMaps\Core\Import\GeoJsonRouteImporter;
use RouteMaps\Core\Import\ImporterRegistry;
use RouteMaps\Core\Import\KmlRouteImporter;
use RouteMaps\Core\Import\KmzRouteImporter;
use RouteMaps\Core\Import\Security\ImportFileValidator;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbCategoryRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbPoiRepository;
use RouteMaps\Core\Rest\AdminCategoriesController;
use RouteMaps\Core\Rest\AdminImportController;
use RouteMaps\Core\Rest\AdminPoisController;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteVersionRepository;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use RouteMaps\Core\Rest\AdminRoutesController;
use RouteMaps\Core\Rest\AdminLicensesController;
use RouteMaps\Core\Domain\Access\AccessDecisionService;
use RouteMaps\Core\Domain\Access\AccessSessionService;
use RouteMaps\Core\Domain\Sharing\ShareInviteService;
use RouteMaps\Core\Domain\Sharing\ShareAcceptanceService;
use RouteMaps\Core\Domain\Sharing\ShareRevocationService;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessEventRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessSessionRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseUserRepository;
use RouteMaps\Core\Rest\ViewerAccessController;
use RouteMaps\Core\Rest\ViewerSharesController;
use RouteMaps\Core\Viewer\RouteRewriteManager;
use RouteMaps\Core\Viewer\LoginController;
use RouteMaps\Core\Security\RateLimiter;
use RouteMaps\Core\Security\UrlPolicy;
use RouteMaps\Core\Security\ViewerHeaders;
use RouteMaps\Core\Maps\PMTilesAssetController;
use RouteMaps\Core\Maps\MapSettings;
use RouteMaps\Core\Maps\MapSourceRegistry;
use RouteMaps\Core\Maps\Providers\PMTilesMapSourceProvider;
use RouteMaps\Core\Maps\Providers\OpenFreeMapSourceProvider;
use RouteMaps\Core\Maps\Providers\MapTilerMapSourceProvider;
use RouteMaps\Core\Viewer\ExtensionRegistry;
use RouteMaps\Core\Viewer\ViewerPayloadFactory;
use RouteMaps\Core\Viewer\ViewerController;
use RouteMaps\Core\Rest\ViewerRouteController;
use RouteMaps\Core\PWA\PwaManifestController;
use RouteMaps\Core\PWA\ServiceWorkerController;
use RouteMaps\Core\Privacy\PersonalDataExporter;
use RouteMaps\Core\Privacy\PersonalDataEraser;

final class Plugin {
    private static bool $booted = false;

    public static function boot(): void {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        add_action('rest_api_init', [self::class, 'registerRestRoutes']);
        (new RoutesPage())->registerHooks();
        (new LicensesPage())->registerHooks();
        (new MapSettingsPage())->registerHooks();
        (new SystemStatusPage())->registerHooks();
        (new PMTilesAssetController())->registerHooks();
        (new RouteRewriteManager())->registerHooks();

        global $wpdb;
        $routes = new WpdbRouteRepository($wpdb);
        (new PwaManifestController($routes))->registerHooks();
        (new ServiceWorkerController())->registerHooks();
        (new PersonalDataExporter($wpdb))->registerHooks();
        (new PersonalDataEraser($wpdb))->registerHooks();
        $licenses = new WpdbLicenseRepository($wpdb);
        $members = new WpdbLicenseUserRepository($wpdb);
        $shareAcceptance = new ShareAcceptanceService($members, new TokenService());
        $loginController = new LoginController(new RateLimiter(), $shareAcceptance, $licenses, $routes);
        $loginController->registerHooks();
        (new ViewerController($loginController, new ViewerHeaders()))->registerHooks();

        if (class_exists('WC_Product')) {
            $productMeta = new RouteProductMeta($routes);
            (new RouteProductPanel($productMeta, $routes))->registerHooks();
            (new RouteProductCartGuard($productMeta))->registerHooks();
            $issuer = new LicenseIssuer($licenses, $productMeta, new TokenService(), new TransactionManager($wpdb));
            (new LicenseOrderListener($issuer, new RouteAccessEmail($routes, new RouteUrl())))->registerHooks();
        }

        do_action('routemaps_booted');
    }

    public static function registerRestRoutes(): void {
        global $wpdb;

        $routes = new WpdbRouteRepository($wpdb);
        $versions = new WpdbRouteVersionRepository($wpdb);
        $categories = new WpdbCategoryRepository($wpdb);
        $pois = new WpdbPoiRepository($wpdb);
        $drafts = new RouteDraftService($versions);
        $publisher = new RoutePublisher(
            $routes,
            $versions,
            new RouteSnapshotBuilder($pois, $categories),
            new TransactionManager($wpdb)
        );
        $duplicator = new RouteDuplicateService($routes, $versions, $drafts);

        $importValidator = new ImportFileValidator();
        $kmlImporter = new KmlRouteImporter($importValidator);
        $importers = new ImporterRegistry([
            $kmlImporter,
            new KmzRouteImporter($importValidator, $kmlImporter),
            new GeoJsonRouteImporter($importValidator),
        ]);

        (new AdminRoutesController($routes, $versions, $drafts, $publisher, $duplicator))->registerRoutes();
        (new AdminImportController($importValidator, $importers, $routes, $drafts, $categories))->registerRoutes();
        (new AdminPoisController($pois, $categories))->registerRoutes();
        (new AdminCategoriesController($categories))->registerRoutes();
        $licenses = new WpdbLicenseRepository($wpdb);
        $licenseValidity = new LicenseValidityService();
        $licenseStatuses = new LicenseStatusService($licenses, $licenseValidity);
        (new AdminLicensesController($licenses, $licenseStatuses))->registerRoutes();

        $members = new WpdbLicenseUserRepository($wpdb);
        $sessions = new WpdbAccessSessionRepository($wpdb);
        $events = new WpdbAccessEventRepository($wpdb);
        $tokens = new TokenService();
        $transactionManager = new TransactionManager($wpdb);
        $accessDecision = new AccessDecisionService($licenses, $members, $sessions, $events, $licenseValidity, $tokens);
        $accessSessions = new AccessSessionService($licenses, $sessions, $transactionManager);
        $viewerHeaders = new ViewerHeaders();
        (new ViewerAccessController(
            $accessDecision,
            $accessSessions,
            $licenses,
            $routes,
            $members,
            $licenseValidity,
            $tokens,
            new RateLimiter(),
            $viewerHeaders
        ))->registerRoutes();

        $mapSettings = new MapSettings();
        $mapRegistry = new MapSourceRegistry([
            new PMTilesMapSourceProvider($mapSettings),
            new OpenFreeMapSourceProvider($mapSettings),
            new MapTilerMapSourceProvider($mapSettings),
        ]);
        $extensions = new ExtensionRegistry();
        $payloadFactory = new ViewerPayloadFactory($mapRegistry, $mapSettings, $extensions, new UrlPolicy());
        (new ViewerRouteController(
            $routes,
            $licenses,
            $sessions,
            $accessDecision,
            $versions,
            $payloadFactory,
            $extensions,
            $viewerHeaders
        ))->registerRoutes();

        $shareInvite = new ShareInviteService($licenses, $members, $tokens, new RouteShareInviteEmail($routes, new RouteUrl()), $transactionManager);
        $shareRevoke = new ShareRevocationService($licenses, $members, $sessions);
        (new ViewerSharesController($licenses, $shareInvite, $shareRevoke))->registerRoutes();
    }
}
