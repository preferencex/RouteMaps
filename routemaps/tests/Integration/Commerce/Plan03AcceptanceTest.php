<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Commerce;

use RouteMaps\Core\Commerce\Email\RouteAccessEmail;
use RouteMaps\Core\Commerce\Orders\LicenseOrderListener;
use RouteMaps\Core\Commerce\Product\RouteProductMeta;
use RouteMaps\Core\Domain\Licensing\LicenseIssuer;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration002PoisCategories;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration003Licenses;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbLicenseRepository;
use RouteMaps\Core\Infrastructure\Database\Repositories\WpdbRouteRepository;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use RouteMaps\Core\Security\TokenService;
use RouteMaps\Core\Support\RouteUrl;
use WC_Product_Simple;
use WP_UnitTestCase;

final class Plan03AcceptanceTest extends WP_UnitTestCase {
    public function test_paid_route_product_creates_one_immutable_license_and_one_secure_email(): void {
        global $wpdb;

        delete_option('routemaps_db_version');
        (new MigrationManager($wpdb, [
            new Migration001RoutesVersions(),
            new Migration002PoisCategories(),
            new Migration003Licenses(),
        ]))->migrate();
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_licenses');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'routemaps_routes');

        $routes = new WpdbRouteRepository($wpdb);
        $licenses = new WpdbLicenseRepository($wpdb);
        $route = $routes->create('Douro Premium', 1);
        $product = new WC_Product_Simple();
        $product->set_name('Douro Premium');
        $product->set_regular_price('50');
        $product->set_virtual(true);
        $product->save();

        $productMeta = new RouteProductMeta($routes);
        $productMeta->save($product, [
            RouteProductMeta::ENABLED => 'yes',
            RouteProductMeta::ROUTE_ID => $route->id(),
            RouteProductMeta::VALIDITY_MODE => 'days_from_purchase',
            RouteProductMeta::VALIDITY_DAYS => 30,
            RouteProductMeta::MAX_OPENINGS => 10,
            RouteProductMeta::SHARING_ENABLED => 'yes',
            RouteProductMeta::MAX_SHARES => 2,
        ]);

        $customerId = self::factory()->user->create([
            'role' => 'customer',
            'user_email' => 'cliente-plan03@example.test',
        ]);
        $order = wc_create_order(['customer_id' => $customerId]);
        $order->set_billing_email('cliente-plan03@example.test');
        $itemId = $order->add_product($product, 1);
        $order->save();

        $issuer = new LicenseIssuer($licenses, $productMeta, new TokenService(), new TransactionManager($wpdb));
        $listener = new LicenseOrderListener($issuer, new RouteAccessEmail($routes, new RouteUrl()));
        $listener->registerHooks();

        $mail = [];
        $capture = static function (mixed $return, array $atts) use (&$mail): bool {
            if (str_contains((string) ($atts['message'] ?? ''), '/routemaps/access/')) {
                $mail[] = $atts;
            }
            return true;
        };
        add_filter('pre_wp_mail', $capture, 10, 2);

        $order->payment_complete('routemaps-plan03-test');
        do_action('woocommerce_payment_complete', $order->get_id());

        remove_filter('pre_wp_mail', $capture, 10);

        $result = $licenses->search(['order_id' => $order->get_id()], 1, 20);
        self::assertSame(1, $result['total']);
        self::assertCount(1, $mail);

        $license = $licenses->findByOrderItemId($itemId);
        self::assertNotNull($license);
        self::assertSame(30, $license->validityDays());
        self::assertSame(10, $license->maxOpenings());
        self::assertTrue($license->sharingEnabled());
        self::assertSame(2, $license->maxShares());

        self::assertMatchesRegularExpression('#/routemaps/access/[0-9a-f]{64}#', $mail[0]['message']);
        preg_match('#/routemaps/access/([0-9a-f]{64})#', $mail[0]['message'], $match);
        self::assertArrayHasKey(1, $match);
        $plainToken = $match[1];
        self::assertSame(hash('sha256', $plainToken), $license->publicTokenHash());

        $rawRow = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'routemaps_licenses WHERE id = %d',
                $license->id()
            ),
            ARRAY_A
        );
        self::assertIsArray($rawRow);
        self::assertStringNotContainsString($plainToken, wp_json_encode($rawRow));

        $productMeta->save($product, [
            RouteProductMeta::ENABLED => 'yes',
            RouteProductMeta::ROUTE_ID => $route->id(),
            RouteProductMeta::VALIDITY_MODE => 'days_from_purchase',
            RouteProductMeta::VALIDITY_DAYS => 7,
            RouteProductMeta::MAX_OPENINGS => 1,
            RouteProductMeta::SHARING_ENABLED => 'no',
            RouteProductMeta::MAX_SHARES => 0,
        ]);

        $persisted = $licenses->find($license->id());
        self::assertNotNull($persisted);
        self::assertSame(30, $persisted->validityDays());
        self::assertSame(10, $persisted->maxOpenings());
        self::assertSame(2, $persisted->maxShares());
    }
}