<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Routes;

use RouteMaps\Core\Admin\Capabilities;
use RouteMaps\Core\Bootstrap\Plugin;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use WP_REST_Request;
use WP_UnitTestCase;

final class AdminRoutesRestTest extends WP_UnitTestCase {
    private int $editorUserId;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        (new Migration001RoutesVersions())->up($wpdb);
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_route_versions');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_routes');
        Capabilities::grantToAdministrator();

        add_role('routemaps_test_editor', 'RouteMaps Test Editor', ['read' => true, 'edit_routemaps_routes' => true]);
        $this->editorUserId = self::factory()->user->create(['role' => 'routemaps_test_editor']);

        Plugin::boot();
        do_action('rest_api_init');
    }

    protected function tearDown(): void {
        wp_set_current_user(0);
        remove_role('routemaps_test_editor');
        parent::tearDown();
    }

    public function test_route_endpoints_enforce_authentication_and_capabilities(): void {
        wp_set_current_user(0);
        $anonymousRequest = new WP_REST_Request('POST', '/routemaps/v1/admin/routes');
        $anonymousRequest->set_param('title', 'Permission probe');
        $anonymous = rest_do_request($anonymousRequest);
        self::assertSame(401, $anonymous->get_status());

        $subscriberId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriberId);
        $subscriberRequest = new WP_REST_Request('POST', '/routemaps/v1/admin/routes');
        $subscriberRequest->set_param('title', 'Permission probe');
        $subscriber = rest_do_request($subscriberRequest);
        self::assertSame(403, $subscriber->get_status());
    }

    public function test_editor_can_create_save_and_duplicate_but_needs_publish_capability_to_publish(): void {
        wp_set_current_user($this->editorUserId);

        $create = new WP_REST_Request('POST', '/routemaps/v1/admin/routes');
        $create->set_param('title', 'Douro Premium');
        $created = rest_do_request($create);
        self::assertSame(201, $created->get_status());
        $route = $created->get_data();
        foreach (['id', 'uuid', 'title', 'slug', 'status', 'current_published_version_id'] as $field) {
            self::assertArrayHasKey($field, $route);
        }

        $draft = new WP_REST_Request('PUT', '/routemaps/v1/admin/routes/' . $route['id'] . '/draft');
        $draft->set_body_params($this->draftPayload());
        self::assertSame(200, rest_do_request($draft)->get_status());

        $show = rest_do_request(new WP_REST_Request('GET', '/routemaps/v1/admin/routes/' . $route['id']));
        self::assertSame(200, $show->get_status());
        self::assertSame('Douro Premium', $show->get_data()['draft']['data']['title'] ?? null);
        self::assertSame('draft', $show->get_data()['draft']['version']['state'] ?? null);

        $publish = new WP_REST_Request('POST', '/routemaps/v1/admin/routes/' . $route['id'] . '/publish');
        self::assertSame(403, rest_do_request($publish)->get_status());

        wp_get_current_user()->add_cap('publish_routemaps_routes');
        $publish->set_param('critical', true);
        $publish->set_param('summary', 'Primeira publicação');
        $published = rest_do_request($publish);
        self::assertSame(200, $published->get_status());
        foreach (['version_number', 'is_critical', 'content_hash', 'published_at'] as $field) {
            self::assertArrayHasKey($field, $published->get_data());
        }

        $showPublished = rest_do_request(new WP_REST_Request('GET', '/routemaps/v1/admin/routes/' . $route['id']));
        self::assertNull($showPublished->get_data()['draft']);
        self::assertSame('published', $showPublished->get_data()['editor_source']['version']['state'] ?? null);
        self::assertSame('Douro Premium', $showPublished->get_data()['editor_source']['data']['title'] ?? null);

        $duplicate = new WP_REST_Request('POST', '/routemaps/v1/admin/routes/' . $route['id'] . '/duplicate');
        $copied = rest_do_request($duplicate);
        self::assertSame(201, $copied->get_status());
        self::assertNotSame($route['uuid'], $copied->get_data()['uuid']);
        self::assertNotSame($route['slug'], $copied->get_data()['slug']);

        global $wpdb;
        $copyDraftJson = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT snapshot_json FROM ' . $wpdb->prefix . 'routemaps_route_versions WHERE route_id = %d AND state = %s LIMIT 1',
                (int) $copied->get_data()['id'],
                'draft'
            )
        );
        self::assertIsString($copyDraftJson);
        $copyDraft = json_decode($copyDraftJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame(
            '11111111-1111-4111-8111-111111111111',
            $copyDraft['stops'][0]['entity_uuid'] ?? null
        );
    }


    public function test_editor_can_delete_route_and_its_versions_when_not_in_use(): void {
        wp_set_current_user($this->editorUserId);
        wp_get_current_user()->add_cap('publish_routemaps_routes');

        $create = new WP_REST_Request('POST', '/routemaps/v1/admin/routes');
        $create->set_param('title', 'Disposable Route');
        $route = rest_do_request($create)->get_data();
        $routeId = (int) $route['id'];

        $draft = new WP_REST_Request('PUT', '/routemaps/v1/admin/routes/' . $routeId . '/draft');
        $draft->set_body_params($this->draftPayload());
        self::assertSame(200, rest_do_request($draft)->get_status());
        self::assertSame(
            200,
            rest_do_request(new WP_REST_Request('POST', '/routemaps/v1/admin/routes/' . $routeId . '/publish'))->get_status()
        );

        $delete = rest_do_request(new WP_REST_Request('DELETE', '/routemaps/v1/admin/routes/' . $routeId));
        self::assertSame(200, $delete->get_status());
        self::assertTrue((bool) ($delete->get_data()['deleted'] ?? false));

        self::assertSame(
            404,
            rest_do_request(new WP_REST_Request('GET', '/routemaps/v1/admin/routes/' . $routeId))->get_status()
        );

        global $wpdb;
        self::assertSame(
            0,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'routemaps_route_versions WHERE route_id = %d',
                    $routeId
                )
            )
        );
    }

    public function test_editor_can_list_and_read_immutable_published_versions(): void {
        wp_set_current_user($this->editorUserId);
        wp_get_current_user()->add_cap('publish_routemaps_routes');

        $create = new WP_REST_Request('POST', '/routemaps/v1/admin/routes');
        $create->set_param('title', 'Versioned Route');
        $route = rest_do_request($create)->get_data();
        $routeId = (int) $route['id'];

        $draftV1 = new WP_REST_Request('PUT', '/routemaps/v1/admin/routes/' . $routeId . '/draft');
        $payloadV1 = $this->draftPayload();
        $payloadV1['title'] = 'Versioned Route v1';
        $draftV1->set_body_params($payloadV1);
        self::assertSame(200, rest_do_request($draftV1)->get_status());
        $publishedV1 = rest_do_request(new WP_REST_Request('POST', '/routemaps/v1/admin/routes/' . $routeId . '/publish'));
        self::assertSame(200, $publishedV1->get_status());
        $v1Id = (int) $publishedV1->get_data()['id'];

        $draftV2 = new WP_REST_Request('PUT', '/routemaps/v1/admin/routes/' . $routeId . '/draft');
        $payloadV2 = $this->draftPayload();
        $payloadV2['title'] = 'Versioned Route v2';
        $payloadV2['style']['color'] = '#173F59';
        $draftV2->set_body_params($payloadV2);
        self::assertSame(200, rest_do_request($draftV2)->get_status());
        self::assertSame(200, rest_do_request(new WP_REST_Request('POST', '/routemaps/v1/admin/routes/' . $routeId . '/publish'))->get_status());

        $versions = rest_do_request(new WP_REST_Request('GET', '/routemaps/v1/admin/routes/' . $routeId . '/versions'));
        self::assertSame(200, $versions->get_status());
        self::assertSame([2, 1], array_column($versions->get_data()['items'], 'version_number'));

        $versionV1 = rest_do_request(new WP_REST_Request('GET', '/routemaps/v1/admin/routes/' . $routeId . '/versions/' . $v1Id));
        self::assertSame(200, $versionV1->get_status());
        self::assertSame(1, $versionV1->get_data()['version']['version_number']);
        self::assertSame('Versioned Route v1', $versionV1->get_data()['data']['title']);
        self::assertSame('#00A099', $versionV1->get_data()['data']['route_style']['color'] ?? $versionV1->get_data()['data']['style']['color'] ?? null);
    }

    /** @return array<string,mixed> */
    private function draftPayload(): array {
        return [
            'title' => 'Douro Premium',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[-8.6, 41.1], [-7.8, 41.2]]],
            'stops' => [['entity_uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Porto', 'coordinates' => [-8.6, 41.1]]],
            'style' => ['color' => '#00A099', 'width' => 4],
            'viewport' => ['center' => [-8.2, 41.15], 'zoom' => 9],
        ];
    }
}