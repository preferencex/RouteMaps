<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\POI;

use RouteMaps\Core\Admin\Capabilities;
use RouteMaps\Core\Bootstrap\Plugin;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use WP_REST_Request;
use WP_UnitTestCase;

final class PoiCategoryRestTest extends WP_UnitTestCase {
    private int $managerUserId;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [new Migration001RoutesVersions(), new Migration002PoisCategories()]))->migrate();
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_pois');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_categories');
        Capabilities::grantToAdministrator();
        add_role('routemaps_poi_manager', 'RouteMaps POI Manager', ['read' => true, 'manage_routemaps_pois' => true]);
        $this->managerUserId = self::factory()->user->create(['role' => 'routemaps_poi_manager']);
        Plugin::boot();
        do_action('rest_api_init');
    }

    protected function tearDown(): void {
        wp_set_current_user(0);
        remove_role('routemaps_poi_manager');
        parent::tearDown();
    }

    public function test_poi_and_category_endpoints_require_capability(): void {
        wp_set_current_user(0);
        self::assertSame(401, rest_do_request(new WP_REST_Request('GET', '/routemaps/v1/admin/pois'))->get_status());
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        self::assertSame(403, rest_do_request(new WP_REST_Request('GET', '/routemaps/v1/admin/categories'))->get_status());
    }

    public function test_poi_validation_rejects_coordinates_category_website_and_missing_attachment(): void {
        wp_set_current_user($this->managerUserId);
        $category = $this->createCategory();

        foreach ([
            ['latitude' => 91, 'longitude' => -8, 'category_id' => $category['id'], 'website' => 'https://example.com'],
            ['latitude' => 41, 'longitude' => -181, 'category_id' => $category['id'], 'website' => 'https://example.com'],
            ['latitude' => 41, 'longitude' => -8, 'category_id' => 999999, 'website' => 'https://example.com'],
            ['latitude' => 41, 'longitude' => -8, 'category_id' => $category['id'], 'website' => 'javascript:alert(1)'],
            ['latitude' => 41, 'longitude' => -8, 'category_id' => $category['id'], 'website' => 'https://example.com', 'main_attachment_id' => 999999],
        ] as $overrides) {
            $request = new WP_REST_Request('POST', '/routemaps/v1/admin/pois');
            $request->set_body_params(array_merge($this->validPoiPayload($category['id']), $overrides));
            self::assertSame(400, rest_do_request($request)->get_status());
        }
    }

    public function test_manager_can_create_update_list_and_delete_poi_and_deactivate_category(): void {
        wp_set_current_user($this->managerUserId);
        $category = $this->createCategory();
        $attachmentId = wp_insert_attachment(['post_title' => 'POI', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit']);
        self::assertIsInt($attachmentId);

        $create = new WP_REST_Request('POST', '/routemaps/v1/admin/pois');
        $create->set_body_params(array_merge($this->validPoiPayload($category['id']), ['main_attachment_id' => $attachmentId, 'gallery' => [$attachmentId]]));
        $created = rest_do_request($create);
        self::assertSame(201, $created->get_status());
        $poi = $created->get_data();
        self::assertArrayHasKey('uuid', $poi);
        self::assertSame($attachmentId, $poi['main_attachment_id']);
        self::assertArrayHasKey('main_image_url', $poi);

        $update = new WP_REST_Request('PUT', '/routemaps/v1/admin/pois/' . $poi['id']);
        $update->set_body_params(array_merge($this->validPoiPayload($category['id']), ['name' => 'Miradouro atualizado']));
        self::assertSame('Miradouro atualizado', rest_do_request($update)->get_data()['name']);

        self::assertSame(1, rest_do_request(new WP_REST_Request('GET', '/routemaps/v1/admin/pois'))->get_data()['total']);

        $deactivate = new WP_REST_Request('DELETE', '/routemaps/v1/admin/categories/' . $category['id']);
        $deactivated = rest_do_request($deactivate);
        self::assertSame(200, $deactivated->get_status());
        self::assertFalse($deactivated->get_data()['is_active']);

        $delete = new WP_REST_Request('DELETE', '/routemaps/v1/admin/pois/' . $poi['id']);
        self::assertSame(204, rest_do_request($delete)->get_status());
    }

    /** @return array<string,mixed> */
    private function createCategory(): array {
        $request = new WP_REST_Request('POST', '/routemaps/v1/admin/categories');
        $request->set_body_params(['name' => 'Miradouros', 'icon' => 'viewpoint', 'color' => '#00A099', 'sort_order' => 10]);
        $response = rest_do_request($request);
        self::assertSame(201, $response->get_status());
        return $response->get_data();
    }

    /** @return array<string,mixed> */
    private function validPoiPayload(int $categoryId): array {
        return [
            'name' => 'Miradouro de São Leonardo',
            'category_id' => $categoryId,
            'latitude' => 41.1234567,
            'longitude' => -8.7654321,
            'description' => '<p>Vista do Douro</p>',
            'address' => 'Lamego',
            'phone' => '+351 254 123 456',
            'website' => 'https://example.com',
            'opening_hours' => '09:00-18:00',
            'route_note' => 'Paragem recomendada',
            'gallery' => [],
            'icon' => 'viewpoint',
            'color' => '#00A099',
            'cta' => ['label' => 'Website', 'url' => 'https://example.com'],
            'status' => 'active',
        ];
    }
}
