<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use RouteMaps\Core\Bootstrap\Requirements;

final class RequirementsTest extends TestCase {
    public function test_it_reports_unsupported_php_version(): void {
        $requirements = new Requirements('8.1.0', '6.8.1', '10.0.0', true);

        self::assertArrayHasKey('php', $requirements->check());
    }

    public function test_it_reports_unsupported_wordpress_version(): void {
        $requirements = new Requirements('8.2.0', '6.7.9', '10.0.0', true);

        self::assertArrayHasKey('wordpress', $requirements->check());
    }

    public function test_it_reports_unsupported_woocommerce_version(): void {
        $requirements = new Requirements('8.2.0', '6.8.1', '9.9.9', true);

        self::assertArrayHasKey('woocommerce', $requirements->check());
    }

    public function test_it_reports_missing_https(): void {
        $requirements = new Requirements('8.2.0', '6.8.1', '10.0.0', false);

        self::assertArrayHasKey('https', $requirements->check());
    }

    public function test_supported_versions_return_no_errors(): void {
        $requirements = new Requirements('8.2.0', '6.8.1', '10.0.0', true);

        self::assertSame([], $requirements->check());
    }
}
