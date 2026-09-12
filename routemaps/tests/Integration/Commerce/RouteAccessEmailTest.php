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

final class RouteAccessEmailTest extends WP_UnitTestCase {
    public function test_first_paid_hook_sends_one_secure_access_email_and_repeat_hook_sends_none(): void {
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
        $route = $routes->create('Douro & Vinhas', 1);
        $product = new WC_Product_Simple();
        $product->set_name('Roteiro Douro');
        $product->set_regular_price('50');
        $product->set_virtual(true);
        $product->save();

        $meta = new RouteProductMeta($routes);
        $meta->save($product, [
            RouteProductMeta::ENABLED => 'yes',
            RouteProductMeta::ROUTE_ID => $route->id(),
            RouteProductMeta::VALIDITY_MODE => 'days_from_purchase',
            RouteProductMeta::VALIDITY_DAYS => 30,
            RouteProductMeta::MAX_OPENINGS => 10,
            RouteProductMeta::SHARING_ENABLED => 'no',
            RouteProductMeta::MAX_SHARES => 0,
        ]);

        $userId = self::factory()->user->create([
            'role' => 'customer',
            'user_email' => 'cliente@example.test',
        ]);
        $order = wc_create_order(['customer_id' => $userId]);
        $order->set_billing_email('cliente@example.test');
        $itemId = $order->add_product($product, 1);
        $order->set_status('processing');
        $order->save();

        $issuer = new LicenseIssuer($licenses, $meta, new TokenService(), new TransactionManager($wpdb));
        $email = new RouteAccessEmail($routes, new RouteUrl());
        $listener = new LicenseOrderListener($issuer, $email);

        $mail = [];
        $capture = static function (mixed $return, array $atts) use (&$mail): bool {
            $mail[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $capture, 10, 2);

        $listener->handle($order->get_id());
        $listener->handle($order->get_id());

        remove_filter('pre_wp_mail', $capture, 10);

        self::assertCount(1, $mail);
        self::assertSame('cliente@example.test', $mail[0]['to']);
        self::assertStringContainsString('Douro &amp; Vinhas', $mail[0]['message']);
        self::assertStringContainsString('Abrir roteiro', $mail[0]['message']);
        self::assertMatchesRegularExpression('#/routemaps/access/[0-9a-f]{64}#', $mail[0]['message']);

        preg_match('#/routemaps/access/([0-9a-f]{64})#', $mail[0]['message'], $match);
        self::assertArrayHasKey(1, $match);
        $plainToken = $match[1];

        $license = $licenses->findByOrderItemId($itemId);
        self::assertNotNull($license);
        self::assertSame(hash('sha256', $plainToken), $license->publicTokenHash());
        self::assertNotSame($plainToken, $license->publicTokenHash());

        $stored = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT public_token_hash FROM ' . $wpdb->prefix . 'routemaps_licenses WHERE order_item_id = %d',
                $itemId
            )
        );
        self::assertSame(hash('sha256', $plainToken), $stored);
        self::assertStringNotContainsString($plainToken, $stored);
    }
}
