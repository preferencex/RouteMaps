<?php

declare(strict_types=1);

namespace RouteMaps\Core\Admin;

final class Capabilities {
    /**
     * @return list<string>
     */
    public static function all(): array {
        return [
            'manage_routemaps',
            'edit_routemaps_routes',
            'publish_routemaps_routes',
            'manage_routemaps_pois',
            'manage_routemaps_licenses',
            'manage_routemaps_settings',
        ];
    }

    public static function grantToAdministrator(): void {
        $role = get_role('administrator');

        if (false === $role || null === $role) {
            return;
        }

        foreach (self::all() as $capability) {
            $role->add_cap($capability);
        }
    }
}
