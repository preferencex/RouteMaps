<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Viewer;

use DateTimeImmutable;
use DateTimeZone;
use RouteMaps\Core\Domain\Access\AccessDecisionService;
use RouteMaps\Core\Domain\Access\AccessSession;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\LicenseValidityService;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Domain\Versions\PublishedRouteSnapshotProvider;
use RouteMaps\Core\Domain\Versions\RouteSnapshot;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessEventRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbAccessSessionRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseUserRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Maps\MapSettings;
use RouteMaps\Core\Maps\MapSourceHealth;
use RouteMaps\Core\Maps\MapSourceProviderInterface;
use RouteMaps\Core\Maps\MapSourceRegistry;
use RouteMaps\Core\Rest\ViewerRouteController;
use RouteMaps\Core\Security\TokenService;
use RouteMaps\Core\Viewer\ExtensionRegistry;
use RouteMaps\Core\Viewer\ViewerContext;
use RouteMaps\Core\Viewer\ViewerPayload;
use RouteMaps\Core\Viewer\ViewerPayloadDecorator;
use RouteMaps\Core\Viewer\ViewerPayloadFactory;
use WP_REST_Request;
use WP_UnitTestCase;

final class ViewerPayloadTest extends WP_UnitTestCase {
    protected function tearDown(): void {
        delete_option(MapSettings::OPTION_NAME);
        parent::tearDown();
    }

    public function test_route_payload_is_never_loaded_without_current_session(): void {
        [$controller, $provider, $route, $license] = $this->scenario(false);
        wp_set_current_user($license->ownerUserId());

        $request = new WP_REST_Request('GET', '/routemaps/v1/viewer/routes/' . $route->uuid());
        $request->set_param('route_uuid', $route->uuid());
        $request->set_param('license_uuid', $license->uuid());
        $request->set_param('session_uuid', 'missing-session');

        $response = $controller->route($request);

        self::assertSame(403, $response->get_status());
        self::assertFalse($provider->called);
    }

    public function test_authorized_payload_contains_only_explicit_route_fields(): void {
        [$controller, $provider, $route, $license, $session] = $this->scenario(true);
        wp_set_current_user($license->ownerUserId());

        $request = new WP_REST_Request('GET', '/routemaps/v1/viewer/routes/' . $route->uuid());
        $request->set_param('route_uuid', $route->uuid());
        $request->set_param('license_uuid', $license->uuid());
        $request->set_param('session_uuid', $session->uuid());
        $response = $controller->route($request);
        $data = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertStringContainsString('private', strtolower((string) $response->get_headers()['Cache-Control']));
        self::assertStringContainsString('no-store', strtolower((string) $response->get_headers()['Cache-Control']));
        self::assertSame('no-referrer', $response->get_headers()['Referrer-Policy']);
        self::assertTrue($provider->called);
        self::assertSame($route->uuid(), $data['route']['uuid']);
        self::assertSame('LineString', $data['geometry']['type']);
        self::assertSame('fake-map', $data['map']['provider_id']);
        self::assertArrayNotHasKey('public_token_hash', $data);
        self::assertArrayNotHasKey('order_id', $data);
        self::assertArrayNotHasKey('guest_emails', $data);
        self::assertArrayNotHasKey('website', $data['pois'][0]['display']);
        self::assertArrayNotHasKey('cta', $data['pois'][0]['display']);
        self::assertArrayNotHasKey('url', $data['support']);
    }

    public function test_decorators_run_in_priority_order(): void {
        $registry = new ExtensionRegistry();
        $registry->registerViewerDecorator('late', new class implements ViewerPayloadDecorator {
            public function decorate(ViewerPayload $payload, ViewerContext $context): ViewerPayload {
                return $payload->with('sequence', array_merge($payload->get('sequence', []), ['late']));
            }
        }, 20);
        $registry->registerViewerDecorator('early', new class implements ViewerPayloadDecorator {
            public function decorate(ViewerPayload $payload, ViewerContext $context): ViewerPayload {
                return $payload->with('sequence', array_merge($payload->get('sequence', []), ['early']));
            }
        }, 5);

        $payload = $registry->decorate(new ViewerPayload(['sequence' => []]), new ViewerContext(1, 2, 3, 4, 'session'));
        self::assertSame(['early', 'late'], $payload->get('sequence'));
    }

    /** @return array{0:ViewerRouteController,1:object,2:mixed,3:mixed,4?:AccessSession} */
    private function scenario(bool $withSession): array {
        global $wpdb;
        $routes = new WpdbRouteRepository($wpdb);
        $licenses = new WpdbLicenseRepository($wpdb);
        $members = new WpdbLicenseUserRepository($wpdb);
        $sessions = new WpdbAccessSessionRepository($wpdb);
        $events = new WpdbAccessEventRepository($wpdb);
        $owner = self::factory()->user->create(['user_email' => 'viewer-owner@example.test']);
        $route = $routes->create('Protected Viewer Route', $owner);
        $route = $routes->updatePublishedVersion($route->id(), 77);
        $license = $licenses->create([
            'public_token_hash' => hash('sha256', str_repeat('a', 64)),
            'order_id' => 10,
            'order_item_id' => random_int(10000, 9999999),
            'product_id' => 20,
            'route_id' => $route->id(),
            'owner_user_id' => $owner,
            'status' => LicenseStatus::ACTIVE,
            'validity_mode' => ValidityMode::UNLIMITED,
            'max_openings' => 5,
            'openings_used' => 1,
            'sharing_enabled' => false,
            'max_shares' => 0,
        ]);
        $members->create(['license_id' => $license->id(), 'user_id' => $owner, 'email' => 'viewer-owner@example.test', 'role' => 'owner', 'status' => 'active']);

        $session = null;
        if ($withSession) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $session = $sessions->create([
                'license_id' => $license->id(),
                'user_id' => $owner,
                'started_at' => $now->format('Y-m-d H:i:s'),
                'last_seen_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $now->modify('+30 minutes')->format('Y-m-d H:i:s'),
            ]);
        }

        $provider = new class implements PublishedRouteSnapshotProvider {
            public bool $called = false;
            public function get_published_snapshot(int $route_id, ?int $version_id = null): RouteSnapshot {
                $this->called = true;
                $data = [
                    'route_uuid' => 'ignored-by-route-object',
                    'title' => 'Protected Viewer Route',
                    'geometry' => ['type' => 'LineString', 'coordinates' => [[-8.6, 41.1], [-7.8, 41.2]]],
                    'stops' => [],
                    'pois' => [[
                        'entity_uuid' => '33333333-3333-4333-8333-333333333333',
                        'coordinates' => [-8.1, 41.1],
                        'display' => [
                            'name' => 'Unsafe URL POI',
                            'website' => 'javascript:alert(1)',
                            'cta' => ['label' => 'Abrir', 'url' => 'data:text/html,unsafe'],
                        ],
                        'media' => [],
                    ]],
                    'categories' => [],
                    'display' => [],
                    'viewport' => ['center' => [-8.2, 41.15], 'zoom' => 8],
                    'route_style' => ['color' => '#00A099'],
                    'support_overrides' => ['email' => 'support@example.test', 'url' => 'javascript:alert(1)'],
                    'map_source_id' => 'fake-map',
                    'secret' => ['order_id' => 123, 'guest_emails' => ['hidden@example.test']],
                ];
                $json = wp_json_encode($data);
                return new RouteSnapshot($data, $json, hash('sha256', $json));
            }
        };
        $mapProvider = new class implements MapSourceProviderInterface {
            public function get_id(): string { return 'fake-map'; }
            public function get_style_definition(): array { return ['version' => 8, 'sources' => [], 'layers' => []]; }
            public function is_configured(): bool { return true; }
            public function health_check(): MapSourceHealth { return new MapSourceHealth(true, 'ok', 'ok'); }
        };
        $maps = new MapSourceRegistry([$mapProvider]);
        $settings = new MapSettings();
        $settings->save(['primary_provider' => 'fake-map', 'fallback_provider' => 'fake-map']);
        $extensions = new ExtensionRegistry();
        $factory = new ViewerPayloadFactory($maps, $settings, $extensions);
        $decision = new AccessDecisionService($licenses, $members, $sessions, $events, new LicenseValidityService(), new TokenService());
        $controller = new ViewerRouteController($routes, $licenses, $sessions, $decision, $provider, $factory, $extensions);

        return $withSession
            ? [$controller, $provider, $route, $license, $session]
            : [$controller, $provider, $route, $license];
    }
}
