<?php
/**
 * RouteMaps E2E fixture seeder.
 * Run only inside the wp-env CI installation using WP-CLI eval-file.
 */


use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Maps\MapSettings;

if (!defined('ABSPATH') || !defined('ROUTEMAPS_CI_E2E') || true !== ROUTEMAPS_CI_E2E) {
    fwrite(STDERR, "RouteMaps CI fixture seeder requires ROUTEMAPS_CI_E2E.\n");
    exit(1);
}

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is not active.\n");
    exit(1);
}

global $wpdb;

$baseUrl = home_url();
$password = 'RouteMaps-E2E-2026!';

/** @return WP_User */
function routemaps_ci_user(string $login, string $email, string $password, string $role = 'subscriber'): WP_User {
    $user = get_user_by('login', $login);
    if (!$user instanceof WP_User) {
        $id = wp_create_user($login, $password, $email);
        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }
        $user = get_user_by('id', (int) $id);
    }
    if (!$user instanceof WP_User) {
        throw new RuntimeException('user_create_failed:' . $login);
    }
    wp_set_password($password, $user->ID);
    wp_update_user(['ID' => $user->ID, 'user_email' => $email]);
    $user->set_role($role);
    return $user;
}

/** @return array{route_id:int,route_uuid:string,version_id:int} */
function routemaps_ci_route(string $title, int $adminId, string $mapSourceId): array {
    global $wpdb;
    $routes = new WpdbRouteRepository($wpdb);
    $route = $routes->create($title, $adminId);
    $now = gmdate('Y-m-d H:i:s');
    $categoryUuid = '00000000-0000-4000-8000-000000000001';
    $snapshot = [
        'route_uuid' => $route->uuid(),
        'title' => $title,
        'geometry' => [
            'type' => 'LineString',
            'coordinates' => [[-8.6400, 41.1500], [-8.6291, 41.1579], [-8.6150, 41.1650]],
        ],
        'stops' => [[
            'entity_uuid' => '00000000-0000-4000-8000-000000000002',
            'name' => 'E2E Paragem',
            'coordinates' => [-8.6291, 41.1579],
            'position' => 1,
        ]],
        'pois' => [[
            'entity_uuid' => '00000000-0000-4000-8000-000000000003',
            'source_poi_uuid' => '00000000-0000-4000-8000-000000000004',
            'position' => 1,
            'required' => false,
            'coordinates' => [-8.6291, 41.1579],
            'category' => [
                'uuid' => $categoryUuid,
                'name' => 'Miradouros',
                'icon' => 'viewpoint',
                'color' => '#00A099',
            ],
            'media' => ['main_attachment_id' => null, 'gallery_attachment_ids' => []],
            'display' => [
                'name' => 'E2E Miradouro',
                'description' => 'POI de aceitação RouteMaps.',
                'address' => 'Porto',
                'phone' => '',
                'website' => 'https://example.com/',
                'opening_hours' => '',
                'route_note' => '',
                'icon' => 'viewpoint',
                'color' => '#00A099',
                'cta' => ['label' => 'Saber mais', 'url' => 'https://example.com/'],
            ],
        ]],
        'categories' => [[
            'uuid' => $categoryUuid,
            'name' => 'Miradouros',
            'icon' => 'viewpoint',
            'color' => '#00A099',
        ]],
        'display' => [],
        'viewport' => ['center' => [-8.6291, 41.1579], 'zoom' => 12],
        'route_style' => ['color' => '#00A099', 'width' => 4],
        'support_overrides' => [],
        'map_source_id' => $mapSourceId,
    ];
    $json = wp_json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $wpdb->insert($wpdb->prefix . 'routemaps_route_versions', [
        'route_id' => $route->id(),
        'version_number' => 1,
        'state' => 'published',
        'is_critical' => 0,
        'snapshot_json' => $json,
        'change_summary' => 'CI fixture',
        'content_hash' => hash('sha256', (string) $json),
        'created_by' => $adminId,
        'created_at' => $now,
        'published_at' => $now,
    ]);
    $versionId = (int) $wpdb->insert_id;
    if ($versionId <= 0) {
        throw new RuntimeException('route_version_seed_failed');
    }
    $routes->updatePublishedVersion($route->id(), $versionId);
    return ['route_id' => $route->id(), 'route_uuid' => $route->uuid(), 'version_id' => $versionId];
}

/** @return array{access_url:string,license_id:int,license_uuid:string,order_id:int} */
function routemaps_ci_license(array $route, WP_User $owner, ?int $maxOpenings, int $maxShares): array {
    global $wpdb;
    $product = new WC_Product_Simple();
    $product->set_name('RouteMaps CI Fixture ' . wp_generate_uuid4());
    $product->set_status('publish');
    $product->set_virtual(true);
    $product->set_regular_price('1.00');
    $product->update_meta_data('_routemaps_enabled', 'yes');
    $product->update_meta_data('_routemaps_route_id', (int) $route['route_id']);
    $product->update_meta_data('_routemaps_validity_mode', 'unlimited');
    $product->update_meta_data('_routemaps_max_openings', null === $maxOpenings ? '' : $maxOpenings);
    $product->update_meta_data('_routemaps_sharing_enabled', $maxShares > 0 ? 'yes' : 'no');
    $product->update_meta_data('_routemaps_max_shares', $maxShares);
    $productId = $product->save();

    $order = wc_create_order(['customer_id' => $owner->ID]);
    if (is_wp_error($order)) {
        throw new RuntimeException($order->get_error_message());
    }
    $order->add_product(wc_get_product($productId), 1);
    $order->set_billing_first_name('RouteMaps');
    $order->set_billing_last_name('CI');
    $order->set_billing_email($owner->user_email);
    $order->calculate_totals();
    $order->save();
    $order->payment_complete('routemaps-ci');
    do_action('woocommerce_payment_complete', $order->get_id());

    $license = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}routemaps_licenses WHERE order_id = %d ORDER BY id DESC LIMIT 1",
        $order->get_id()
    ), ARRAY_A);
    if (!is_array($license)) {
        throw new RuntimeException('license_seed_failed');
    }

    $messages = get_option('routemaps_ci_mail_messages', []);
    $accessUrl = '';
    foreach (array_reverse(is_array($messages) ? $messages : []) as $message) {
        $to = is_array($message['To'] ?? null) ? $message['To'] : [];
        if (!in_array(strtolower($owner->user_email), $to, true)) {
            continue;
        }
        $html = (string) ($message['HTML'] ?? '');
        if (1 === preg_match('#https?://[^\s"\'<>]+/routemaps/access/[a-f0-9]{64}#i', $html, $match)) {
            $accessUrl = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5);
            break;
        }
    }
    if ('' === $accessUrl) {
        throw new RuntimeException('license_access_url_missing');
    }

    return [
        'access_url' => $accessUrl,
        'license_id' => (int) $license['id'],
        'license_uuid' => (string) $license['uuid'],
        'order_id' => $order->get_id(),
    ];
}

function routemaps_ci_wc_keys(int $userId): array {
    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_api_keys';
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT key_id FROM {$table} WHERE user_id = %d AND description = %s LIMIT 1",
        $userId,
        'RouteMaps E2E'
    ), ARRAY_A);
    if (is_array($existing)) {
        $wpdb->delete($table, ['key_id' => (int) $existing['key_id']], ['%d']);
    }
    $consumerKey = 'ck_' . wc_rand_hash();
    $consumerSecret = 'cs_' . wc_rand_hash();
    $wpdb->insert($table, [
        'user_id' => $userId,
        'description' => 'RouteMaps E2E',
        'permissions' => 'read_write',
        'consumer_key' => wc_api_hash($consumerKey),
        'consumer_secret' => $consumerSecret,
        'truncated_key' => substr($consumerKey, -7),
    ]);
    if ((int) $wpdb->insert_id <= 0) {
        throw new RuntimeException('woocommerce_api_key_seed_failed');
    }
    return [$consumerKey, $consumerSecret];
}

update_option('routemaps_ci_mail_messages', [], false);

$admin = routemaps_ci_user('admin', 'admin@example.test', 'password', 'administrator');
$buyer = routemaps_ci_user('routemaps_buyer', 'buyer@example.test', $password);
$owner = routemaps_ci_user('routemaps_owner', 'owner@example.test', $password);
$guest = routemaps_ci_user('routemaps_guest', 'guest@example.test', $password);

$pmtilesPath = WP_PLUGIN_DIR . '/routemaps/tests/E2E/Fixtures/maps/fixture.pmtiles';
(new MapSettings())->save([
    'primary_provider' => 'pmtiles',
    'fallback_provider' => 'openfreemap',
    'pmtiles_path' => $pmtilesPath,
    'pmtiles_url' => '',
    'style_json' => '',
    'maptiler_key' => '',
    'openfreemap_style_url' => '',
]);

$defaultRoute = routemaps_ci_route('RouteMaps E2E Viewer', $admin->ID, 'openfreemap');
$shareRoute = routemaps_ci_route('RouteMaps E2E Sharing', $admin->ID, 'openfreemap');
$pmtilesRoute = routemaps_ci_route('RouteMaps E2E PMTiles', $admin->ID, 'pmtiles');
$fallbackRoute = routemaps_ci_route('RouteMaps E2E Fallback', $admin->ID, 'missing-primary');

$defaultLicense = routemaps_ci_license($defaultRoute, $buyer, 10, 0);
$shareLicense = routemaps_ci_license($shareRoute, $owner, 10, 1);
$expiredLicense = routemaps_ci_license($defaultRoute, $buyer, 2, 0);
$exhaustedLicense = routemaps_ci_license($defaultRoute, $buyer, 2, 0);
$pmtilesLicense = routemaps_ci_license($pmtilesRoute, $buyer, 10, 0);
$fallbackLicense = routemaps_ci_license($fallbackRoute, $buyer, 10, 0);

$wpdb->update($wpdb->prefix . 'routemaps_licenses', ['openings_used' => 1], ['id' => $expiredLicense['license_id']], ['%d'], ['%d']);
$wpdb->update($wpdb->prefix . 'routemaps_licenses', ['openings_used' => 2], ['id' => $exhaustedLicense['license_id']], ['%d'], ['%d']);
$wpdb->delete($wpdb->prefix . 'routemaps_access_sessions', ['license_id' => $expiredLicense['license_id']], ['%d']);
$wpdb->delete($wpdb->prefix . 'routemaps_access_sessions', ['license_id' => $exhaustedLicense['license_id']], ['%d']);

[$wcKey, $wcSecret] = routemaps_ci_wc_keys($admin->ID);

$vars = [
    'ROUTEMAPS_E2E_BASE_URL' => $baseUrl,
    'ROUTEMAPS_E2E_ADMIN_USER' => 'admin',
    'ROUTEMAPS_E2E_ADMIN_PASSWORD' => 'password',
    'ROUTEMAPS_E2E_BUYER_USER' => $buyer->user_login,
    'ROUTEMAPS_E2E_BUYER_PASSWORD' => $password,
    'ROUTEMAPS_E2E_BUYER_EMAIL' => $buyer->user_email,
    'ROUTEMAPS_E2E_OWNER_USER' => $owner->user_login,
    'ROUTEMAPS_E2E_OWNER_PASSWORD' => $password,
    'ROUTEMAPS_E2E_GUEST_USER' => $guest->user_login,
    'ROUTEMAPS_E2E_GUEST_PASSWORD' => $password,
    'ROUTEMAPS_E2E_GUEST_EMAIL' => $guest->user_email,
    'ROUTEMAPS_E2E_WC_KEY' => $wcKey,
    'ROUTEMAPS_E2E_WC_SECRET' => $wcSecret,
    'ROUTEMAPS_E2E_MAILPIT_URL' => $baseUrl,
    'ROUTEMAPS_E2E_ACCESS_URL' => $defaultLicense['access_url'],
    'ROUTEMAPS_E2E_SHARE_ACCESS_URL' => $shareLicense['access_url'],
    'ROUTEMAPS_E2E_EXPIRED_SESSION_ACCESS_URL' => $expiredLicense['access_url'],
    'ROUTEMAPS_E2E_EXHAUSTED_ACCESS_URL' => $exhaustedLicense['access_url'],
    'ROUTEMAPS_E2E_PMTILES_ACCESS_URL' => $pmtilesLicense['access_url'],
    'ROUTEMAPS_E2E_FALLBACK_ACCESS_URL' => $fallbackLicense['access_url'],
    'ROUTEMAPS_E2E_USER' => $buyer->user_login,
    'ROUTEMAPS_E2E_PASSWORD' => $password,
];

foreach ($vars as $key => $value) {
    printf("%s=%s\n", $key, escapeshellarg((string) $value));
}
