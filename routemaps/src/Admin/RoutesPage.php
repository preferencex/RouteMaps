<?php

declare(strict_types=1);

namespace RouteMaps\Core\Admin;

use RouteMaps\Core\Support\JavaScriptTranslations;

final class RoutesPage {
    private const MENU_SLUG = 'routemaps-routes';
    private const SCRIPT_HANDLE = 'routemaps-admin';

    private string $hookSuffix = '';

    public function registerHooks(): void {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function registerMenu(): void {
        $hook = add_menu_page(
            __('RouteMaps', 'routemaps'),
            __('RouteMaps', 'routemaps'),
            'edit_routemaps_routes',
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-location-alt',
            56
        );

        if (is_string($hook)) {
            $this->hookSuffix = $hook;
        }

        add_submenu_page(
            self::MENU_SLUG,
            __('Rotas', 'routemaps'),
            __('Rotas', 'routemaps'),
            'edit_routemaps_routes',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function enqueue(string $hookSuffix): void {
        if ('' === $this->hookSuffix || $hookSuffix !== $this->hookSuffix) {
            return;
        }

        $baseUrl = plugin_dir_url(ROUTEMAPS_FILE);
        wp_enqueue_style(
            self::SCRIPT_HANDLE,
            $baseUrl . 'assets/admin/routemaps-admin.css',
            [],
            ROUTEMAPS_VERSION
        );
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $baseUrl . 'assets/admin/routemaps-admin.js',
            [],
            ROUTEMAPS_VERSION,
            ['in_footer' => true]
        );
        wp_localize_script(self::SCRIPT_HANDLE, 'RouteMapsAdmin', $this->bootstrapConfig());
    }

    public function render(): void {
        if (!current_user_can('edit_routemaps_routes')) {
            return;
        }
        ?>
        <div class="wrap routemaps-admin-wrap">
            <div id="routemaps-admin-root" class="routemaps-admin-root" aria-live="polite">
                <div class="routemaps-admin-loading">
                    <span class="spinner is-active" aria-hidden="true"></span>
                    <span><?php echo esc_html__('A carregar o editor RouteMaps…', 'routemaps'); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    /** @return array<string,mixed> */
    private function bootstrapConfig(): array {
        $settings = get_option('routemaps_map_settings', []);
        $settings = is_array($settings) ? $settings : [];

        $openFreeMapStyle = isset($settings['openfreemap_style_url']) && is_string($settings['openfreemap_style_url'])
            ? $settings['openfreemap_style_url']
            : 'https://tiles.openfreemap.org/styles/liberty';
        $adminStyleUrl = isset($settings['admin_style_url']) && is_string($settings['admin_style_url'])
            ? $settings['admin_style_url']
            : $openFreeMapStyle;

        return [
            'restBase' => esc_url_raw(rest_url('routemaps/v1')),
            'i18n' => JavaScriptTranslations::catalogue(),
            'nonce' => wp_create_nonce('wp_rest'),
            'capabilities' => [
                'edit' => current_user_can('edit_routemaps_routes'),
                'publish' => current_user_can('publish_routemaps_routes'),
                'managePois' => current_user_can('manage_routemaps_pois'),
                'settings' => current_user_can('manage_routemaps_settings'),
            ],
            'map' => [
                'primaryProvider' => isset($settings['primary_provider']) && is_string($settings['primary_provider'])
                    ? $settings['primary_provider']
                    : 'pmtiles',
                'fallbackProvider' => isset($settings['fallback_provider']) && is_string($settings['fallback_provider'])
                    ? $settings['fallback_provider']
                    : 'openfreemap',
                'pmtilesUrl' => isset($settings['pmtiles_url']) && is_string($settings['pmtiles_url'])
                    ? esc_url_raw($settings['pmtiles_url'])
                    : '',
                'styleUrl' => esc_url_raw((string) apply_filters('routemaps_admin_map_style_url', $adminStyleUrl)),
                'openFreeMapStyleUrl' => esc_url_raw($openFreeMapStyle),
            ],
        ];
    }
}
