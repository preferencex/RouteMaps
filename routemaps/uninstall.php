<?php
/**
 * RouteMaps uninstall handler.
 *
 * Data is preserved by default. Destructive cleanup only runs after an
 * administrator explicitly enables routemaps_delete_data_on_uninstall.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!(bool) get_option('routemaps_delete_data_on_uninstall', false)) {
    return;
}

global $wpdb;

$tableSuffixes = [
    'routemaps_access_events',
    'routemaps_access_sessions',
    'routemaps_license_users',
    'routemaps_licenses',
    'routemaps_pois',
    'routemaps_categories',
    'routemaps_route_versions',
    'routemaps_routes',
];

foreach ($tableSuffixes as $suffix) {
    $table = $wpdb->prefix . $suffix;
    // Table names are composed exclusively from WordPress' trusted prefix and fixed plugin-owned suffixes.
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

foreach ([
    'routemaps_db_version',
    'routemaps_map_settings',
    'routemaps_debug_logging',
    'routemaps_recent_errors',
    'routemaps_delete_data_on_uninstall',
] as $option) {
    delete_option($option);
}

$transientPatterns = [
    '_transient_routemaps_rl_',
    '_transient_timeout_routemaps_rl_',
    '_transient_routemaps_import_',
    '_transient_timeout_routemaps_import_',
];
$where = [];
$params = [];
foreach ($transientPatterns as $pattern) {
    $where[] = 'option_name LIKE %s';
    $params[] = $wpdb->esc_like($pattern) . '%';
}
if ([] !== $where) {
    $sql = "DELETE FROM {$wpdb->options} WHERE " . implode(' OR ', $where);
    $wpdb->query($wpdb->prepare($sql, ...$params)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$capabilities = [
    'manage_routemaps',
    'edit_routemaps_routes',
    'publish_routemaps_routes',
    'manage_routemaps_pois',
    'manage_routemaps_licenses',
    'manage_routemaps_settings',
];

if (function_exists('wp_roles')) {
    foreach (array_keys(wp_roles()->roles) as $roleName) {
        $role = get_role((string) $roleName);
        if (null === $role) {
            continue;
        }
        foreach ($capabilities as $capability) {
            $role->remove_cap($capability);
        }
    }
}
