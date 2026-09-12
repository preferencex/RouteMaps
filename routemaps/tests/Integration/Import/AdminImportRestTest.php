<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Import;

use RouteMaps\Core\Admin\Capabilities;
use RouteMaps\Core\Domain\Routes\RouteDraftService;
use RouteMaps\Core\Import\GeoJsonRouteImporter;
use RouteMaps\Core\Import\ImporterRegistry;
use RouteMaps\Core\Import\Security\ImportFileValidator;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbCategoryRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteVersionRepository;
use RouteMaps\Core\Rest\AdminImportController;
use WP_REST_Request;
use WP_UnitTestCase;

final class AdminImportRestTest extends WP_UnitTestCase {
    private WpdbRouteRepository $routes;
    /** @var list<string> */
    private array $uploads = [];

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [new Migration001RoutesVersions(), new Migration002PoisCategories()]))->migrate();
        Capabilities::grantToAdministrator();
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_route_versions');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_routes');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_categories');

        $this->routes = new WpdbRouteRepository($wpdb);
        $versions = new WpdbRouteVersionRepository($wpdb);
        $validator = new ImportFileValidator();
        $registry = new ImporterRegistry([new GeoJsonRouteImporter($validator)]);
        $categories = new WpdbCategoryRepository($wpdb);
        (new AdminImportController(
            $validator,
            $registry,
            $this->routes,
            new RouteDraftService($versions),
            $categories
        ))->registerRoutes();
    }

    protected function tearDown(): void {
        foreach ($this->uploads as $path) {
            @unlink($path);
        }
        $this->uploads = [];
        parent::tearDown();
    }

    public function test_import_endpoints_require_valid_rest_nonce(): void {
        $this->authenticateEditor();
        $request = new WP_REST_Request('POST', '/routemaps/v1/admin/import/inspect');
        $request->set_file_params(['file' => $this->fixtureUpload('route.geojson')]);

        $response = rest_get_server()->dispatch($request);

        self::assertSame(403, $response->get_status());
        self::assertSame('rest_cookie_invalid_nonce', $response->get_data()['code']);
    }

    public function test_import_endpoints_require_edit_routes_capability(): void {
        $user = self::factory()->user->create_and_get(['role' => 'subscriber']);
        wp_set_current_user($user->ID);
        $request = $this->inspectRequest($this->fixtureUpload('route.geojson'));

        $response = rest_get_server()->dispatch($request);

        self::assertSame(403, $response->get_status());
    }

    public function test_inspect_returns_temporary_id_and_never_creates_route(): void {
        $this->authenticateEditor();
        $before = count($this->routes->list());
        $request = $this->inspectRequest($this->fixtureUpload('route.geojson'));

        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $data['import_id']);
        self::assertSame('geojson', $data['preview']['format']);
        self::assertSame($before, count($this->routes->list()));
        self::assertSame(1800, $data['expires_in']);
        self::assertArrayNotHasKey('staged', $data);
        self::assertArrayNotHasKey('staged_basename', $data);
    }

    public function test_inspect_rejects_unsupported_file_type_and_oversized_upload(): void {
        $this->authenticateEditor();
        $txt = $this->temporaryUpload('route.txt', 'not supported', 'text/plain');
        $response = rest_get_server()->dispatch($this->inspectRequest($txt));
        self::assertSame(400, $response->get_status());
        self::assertSame('import_extension_unsupported', $response->get_data()['code']);

        $huge = $this->temporaryUpload('route.geojson', str_repeat('x', ImportFileValidator::DEFAULT_MAX_BYTES + 1), 'application/json');
        $response = rest_get_server()->dispatch($this->inspectRequest($huge));
        self::assertSame(400, $response->get_status());
        self::assertSame('import_file_too_large', $response->get_data()['code']);
    }


    public function test_commit_rejects_expired_stage_and_removes_staged_file(): void {
        $this->authenticateEditor();
        $inspect = rest_get_server()->dispatch($this->inspectRequest($this->fixtureUpload('route.geojson')));
        $importId = (string) $inspect->get_data()['import_id'];
        $key = 'routemaps_import_' . $importId;
        $stage = get_transient($key);
        self::assertIsArray($stage);
        self::assertFileExists((string) $stage['path']);
        $stage['expires_at'] = time() - 1;
        set_transient($key, $stage, 1800);

        $response = rest_get_server()->dispatch($this->commitRequest($importId, []));

        self::assertSame(404, $response->get_status());
        self::assertSame('import_not_found_or_expired', $response->get_data()['code']);
        self::assertFalse(get_transient($key));
        self::assertFileDoesNotExist((string) $stage['path']);
    }

    public function test_commit_revalidates_ownership_creates_route_draft_and_consumes_stage(): void {
        $owner = $this->authenticateEditor();
        $categoryRepository = new WpdbCategoryRepository($GLOBALS['wpdb']);
        $category = $categoryRepository->create('Restaurantes', 'food', '#00A099', 10, true);
        $inspect = rest_get_server()->dispatch($this->inspectRequest($this->fixtureUpload('route.geojson')));
        $importId = (string) $inspect->get_data()['import_id'];

        $other = self::factory()->user->create_and_get(['role' => 'administrator']);
        wp_set_current_user($other->ID);
        $wrongOwner = $this->commitRequest($importId, ['Restaurantes' => $category->id()]);
        $wrongOwnerResponse = rest_get_server()->dispatch($wrongOwner);
        self::assertSame(403, $wrongOwnerResponse->get_status());

        wp_set_current_user($owner);
        $response = rest_get_server()->dispatch($this->commitRequest($importId, ['Restaurantes' => $category->id()]));
        $data = $response->get_data();

        self::assertSame(201, $response->get_status());
        self::assertSame('Rota GeoJSON', $data['route']['title']);
        self::assertSame(1, $data['draft']['version_number']);
        self::assertCount(1, $this->routes->list());
        self::assertFalse(get_transient('routemaps_import_' . $importId));

        $secondCommit = rest_get_server()->dispatch($this->commitRequest($importId, []));
        self::assertSame(404, $secondCommit->get_status());
    }

    private function authenticateEditor(): int {
        $user = self::factory()->user->create_and_get(['role' => 'administrator']);
        wp_set_current_user($user->ID);
        return $user->ID;
    }

    /** @param array{name:string,tmp_name:string,type:string,size:int,error:int} $file */
    private function inspectRequest(array $file): WP_REST_Request {
        $request = new WP_REST_Request('POST', '/routemaps/v1/admin/import/inspect');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_file_params(['file' => $file]);
        return $request;
    }

    /** @param array<string,int> $categoryMap */
    private function commitRequest(string $importId, array $categoryMap): WP_REST_Request {
        $request = new WP_REST_Request('POST', '/routemaps/v1/admin/import/commit');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_body_params([
            'import_id' => $importId,
            'mapping' => ['category_map' => $categoryMap, 'options' => []],
        ]);
        return $request;
    }

    /** @return array{name:string,tmp_name:string,type:string,size:int,error:int} */
    private function fixtureUpload(string $name): array {
        return $this->temporaryUpload(
            $name,
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/import/' . $name),
            'application/json'
        );
    }

    /** @return array{name:string,tmp_name:string,type:string,size:int,error:int} */
    private function temporaryUpload(string $name, string $contents, string $type): array {
        $path = tempnam(sys_get_temp_dir(), 'routemaps-upload-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->uploads[] = $path;
        return ['name' => $name, 'tmp_name' => $path, 'type' => $type, 'size' => strlen($contents), 'error' => UPLOAD_ERR_OK];
    }
}
