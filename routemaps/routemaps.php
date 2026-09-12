<?php
/**
 * Plugin Name: RouteMaps
 * Description: Plataforma WordPress para criação, venda e utilização de roteiros digitais interativos.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * Author: RouteMaps
 * Text Domain: routemaps
 */

declare(strict_types=1);

use RouteMaps\Core\Bootstrap\Activation;
use RouteMaps\Core\Bootstrap\Plugin;
use RouteMaps\Core\Bootstrap\Requirements;

if (!defined('ABSPATH')) {
    exit;
}

define('ROUTEMAPS_FILE', __FILE__);
define('ROUTEMAPS_VERSION', '0.1.0');

$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_readable($autoload)) {
    return;
}

require_once $autoload;

register_activation_hook(ROUTEMAPS_FILE, [Activation::class, 'activate']);

add_action(
    'plugins_loaded',
    static function (): void {
        global $wp_version;

        $woocommerceVersion = defined('WC_VERSION') ? (string) WC_VERSION : '0.0.0';
        $requirements       = new Requirements(
            PHP_VERSION,
            isset($wp_version) ? (string) $wp_version : '0.0.0',
            $woocommerceVersion,
            is_ssl()
        );
        $errors             = $requirements->check();

        if ([] !== $errors) {
            add_action(
                'admin_notices',
                static function () use ($errors): void {
                    if (!current_user_can('activate_plugins')) {
                        return;
                    }

                    printf(
                        '<div class="notice notice-error"><p>%s</p></div>',
                        esc_html(
                            sprintf(
                                /* translators: %s: comma-separated requirement list. */
                                __('RouteMaps requer: %s.', 'routemaps'),
                                implode(', ', $errors)
                            )
                        )
                    );
                }
            );

            return;
        }

        Plugin::boot();
    },
    20
);
