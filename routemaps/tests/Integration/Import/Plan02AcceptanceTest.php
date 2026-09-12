<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Import;

use RouteMaps\Core\Admin\Capabilities;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbCategoryRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbPoiRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteVersionRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class Plan02AcceptanceTest extends WP_UnitTestCase {
    /** @var list<string> */
    private array $uploads = [];

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;

        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [new Migration001RoutesVersions(), new Migration002PoisCategories()]))->migrate();
        Capabilities::grantToAdministrator();
        foreach (['routemaps_route_versions', 'routemaps_routes', 'routemaps_pois', 'routemaps_categories'] as $suffix) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $suffix);
        }

        do_action('rest_api_init');
        $user = self::factory()->user->create_and_get(['role' => 'administrator']);
        wp_set_current_user($user->ID);
    }

    protected function tearDown(): void {
        foreach ($this->uploads as $path) {
            @unlink($path);
        }
        $this->uploads = [];
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_google_my_maps_import_can_be_edited_enriched_and_published(): void {
        global $wpdb;

        $categories = new WpdbCategoryRepository($wpdb);
        $pois = new WpdbPoiRepository($wpdb);
        $routes = new WpdbRouteRepository($wpdb);
        $versions = new WpdbRouteVersionRepository($wpdb);

        $importCategory = $categories->create('Miradouros', 'viewpoint', '#00A099', 10, true);
        $restaurantCategory = $categories->create('Restaurantes', 'food', '#FFB020', 20, true);
        $poi = $pois->create([
            'name' => 'Restaurante da Régua',
            'category_id' => $restaurantCategory->id(),
            'latitude' => 41.162,
            'longitude' => -7.789,
            'description' => '<p>Paragem para almoço.</p>',
            'address' => 'Peso da Régua',
            'phone' => '+351 254 000 001',
            'website' => 'https://example.test/restaurante',
            'opening_hours' => '12:00-22:00',
            'route_note' => 'Reservar com antecedência',
            'main_attachment_id' => 501,
            'gallery' => [502, 503],
            'icon' => 'food',
            'color' => '#FFB020',
            'cta' => ['label' => 'Reservar', 'url' => 'https://example.test/restaurante'],
            'status' => 'active',
        ], get_current_user_id());

        $inspect = rest_do_request($this->inspectRequest($this->fixtureUpload('google-my-maps.kml')));
        self::assertSame(200, $inspect->get_status(), (string) wp_json_encode($inspect->get_data()));
        self::assertSame('kml', $inspect->get_data()['preview']['format']);
        self::assertSame('Douro My Maps', $inspect->get_data()['preview']['title']);

        $commit = new WP_REST_Request('POST', '/routemaps/v1/admin/import/commit');
        $commit->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $commit->set_body_params([
            'import_id' => $inspect->get_data()['import_id'],
            'mapping' => ['category_map' => ['Miradouros' => $importCategory->id()], 'options' => []],
        ]);
        $committed = rest_do_request($commit);
        self::assertSame(201, $committed->get_status());
        $routeId = (int) $committed->get_data()['route']['id'];

        $show = rest_do_request(new WP_REST_Request('GET', '/routemaps/v1/admin/routes/' . $routeId));
        self::assertSame(200, $show->get_status());
        $draft = $show->get_data()['draft']['data'];
        self::assertSame('kml', $draft['display']['source_format']);
        self::assertNotEmpty($draft['stops']);

        $draft['geometry']['coordinates'][] = [-7.70, 41.18];
        $draft['stops'][0]['name'] = 'Miradouro editado';
        $draft['pois'] = [[
            'entity_uuid' => '55555555-5555-4555-8555-555555555555',
            'source_poi_uuid' => $poi->uuid(),
            'position' => 1,
            'required' => true,
        ]];

        $save = new WP_REST_Request('PUT', '/routemaps/v1/admin/routes/' . $routeId . '/draft');
        $save->set_body_params($draft);
        self::assertSame(200, rest_do_request($save)->get_status());

        $publish = new WP_REST_Request('POST', '/routemaps/v1/admin/routes/' . $routeId . '/publish');
        $publish->set_body_params(['critical' => false, 'summary' => 'Importação validada']);
        $published = rest_do_request($publish);
        self::assertSame(200, $published->get_status());

        $route = $routes->find($routeId);
        self::assertNotNull($route);
        self::assertNotNull($route->currentPublishedVersionId());
        $version = $versions->findPublishedForRoute($routeId, (int) $route->currentPublishedVersionId());
        self::assertNotNull($version);
        $snapshot = json_decode($version->snapshotJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Douro My Maps', $snapshot['title']);
        self::assertSame([-7.70, 41.18], $snapshot['geometry']['coordinates'][3]);
        self::assertSame('Miradouro editado', $snapshot['stops'][0]['name']);
        self::assertSame('kml', $snapshot['display']['source_format']);
        self::assertSame('Restaurante da Régua', $snapshot['pois'][0]['display']['name']);
        self::assertSame('Restaurantes', $snapshot['pois'][0]['category']['name']);
        self::assertSame([502, 503], $snapshot['pois'][0]['media']['gallery_attachment_ids']);
        self::assertTrue($snapshot['pois'][0]['required']);
    }

    /** @return array{name:string,tmp_name:string,type:string,size:int,error:int} */
    private function fixtureUpload(string $name): array {
        $source = dirname(__DIR__, 2) . '/Fixtures/import/' . $name;
        $path = tempnam(sys_get_temp_dir(), 'routemaps-plan02-');
        self::assertIsString($path);
        copy($source, $path);
        $this->uploads[] = $path;

        return [
            'name' => $name,
            'tmp_name' => $path,
            'type' => 'application/vnd.google-earth.kml+xml',
            'size' => (int) filesize($path),
            'error' => UPLOAD_ERR_OK,
        ];
    }

    /** @param array{name:string,tmp_name:string,type:string,size:int,error:int} $file */
    private function inspectRequest(array $file): WP_REST_Request {
        $request = new WP_REST_Request('POST', '/routemaps/v1/admin/import/inspect');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_file_params(['file' => $file]);
        return $request;
    }
}