<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Activation;

use RouteMaps\Core\Admin\Capabilities;
use WP_UnitTestCase;

final class CapabilitiesTest extends WP_UnitTestCase {
    public function test_route_maps_capabilities_are_granted_to_administrator(): void {
        Capabilities::grantToAdministrator();
        $role = get_role('administrator');

        self::assertNotNull($role);

        foreach (Capabilities::all() as $capability) {
            self::assertTrue($role->has_cap($capability), $capability);
        }
    }
}
