<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$autoload = $pluginRoot . '/vendor/autoload.php';

if (!is_readable($autoload)) {
    throw new RuntimeException('Run composer install before the test suite.');
}

require_once $autoload;

$polyfillsPath = $pluginRoot . '/vendor/yoast/phpunit-polyfills';
if (!is_dir($polyfillsPath)) {
    throw new RuntimeException('PHPUnit Polyfills not found. Run composer install before the test suite.');
}
if (!defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfillsPath);
}

$wpTestsDir = getenv('WP_TESTS_DIR');
if (false === $wpTestsDir || '' === $wpTestsDir) {
    $wpTestsDir = '/tmp/wordpress-tests-lib';
}

$functionsFile = rtrim($wpTestsDir, '/\\') . '/includes/functions.php';
$bootstrapFile = rtrim($wpTestsDir, '/\\') . '/includes/bootstrap.php';

if (!is_readable($functionsFile) || !is_readable($bootstrapFile)) {
    throw new RuntimeException(
        'WordPress Test Suite not found. Set WP_TESTS_DIR to the wordpress-tests-lib directory.'
    );
}

require_once $functionsFile;

tests_add_filter(
    'muplugins_loaded',
    static function () use ($pluginRoot): void {
        $wooFile = getenv('WC_PLUGIN_FILE');
        if (false === $wooFile || '' === $wooFile) {
            $wooFile = defined('WP_PLUGIN_DIR')
                ? WP_PLUGIN_DIR . '/woocommerce/woocommerce.php'
                : '';
        }

        if (!is_string($wooFile) || !is_readable($wooFile)) {
            throw new RuntimeException(
                'WooCommerce test plugin not found. Set WC_PLUGIN_FILE to woocommerce.php.'
            );
        }

        require_once $wooFile;
        require_once $pluginRoot . '/routemaps.php';
    },
    5
);

require_once $bootstrapFile;
