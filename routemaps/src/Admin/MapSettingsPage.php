<?php

declare(strict_types=1);

namespace RouteMaps\Core\Admin;

use InvalidArgumentException;
use RouteMaps\Core\Maps\MapSettings;

final class MapSettingsPage {
    private const PARENT_SLUG = 'routemaps-routes';
    private const MENU_SLUG = 'routemaps-map-settings';

    public function __construct(private ?MapSettings $settings = null) {
        $this->settings ??= new MapSettings();
    }

    public function registerHooks(): void {
        add_action('admin_menu', [$this, 'registerMenu'], 30);
        add_action('admin_post_routemaps_save_map_settings', [$this, 'save']);
    }

    public function registerMenu(): void {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Mapas', 'routemaps'),
            __('Mapas', 'routemaps'),
            'manage_routemaps_settings',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function save(): void {
        if (!current_user_can('manage_routemaps_settings')) {
            wp_die(esc_html__('Não tem permissões para alterar as definições de mapas.', 'routemaps'), 403);
        }
        check_admin_referer('routemaps_map_settings');

        try {
            $this->settings->save(wp_unslash($_POST));
            $status = 'updated';
        } catch (InvalidArgumentException $error) {
            $status = $error->getMessage();
        }

        wp_safe_redirect(add_query_arg([
            'page' => self::MENU_SLUG,
            'routemaps-map-status' => rawurlencode($status),
        ], admin_url('admin.php')));
        exit;
    }

    public function render(): void {
        if (!current_user_can('manage_routemaps_settings')) {
            return;
        }
        $values = $this->settings->load();
        $status = isset($_GET['routemaps-map-status']) ? sanitize_key((string) $_GET['routemaps-map-status']) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('RouteMaps — Mapas', 'routemaps'); ?></h1>
            <?php if ('updated' === $status) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Definições guardadas.', 'routemaps'); ?></p></div>
            <?php elseif ('map_style_json_invalid' === $status) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__('O JSON de estilo não é válido.', 'routemaps'); ?></p></div>
            <?php elseif ('maptiler_service_token_not_allowed' === $status) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__('Use uma browser API key da MapTiler, não um service token. Proteja a chave configurando os Allowed HTTP origins na MapTiler.', 'routemaps'); ?></p></div>
            <?php endif; ?>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="routemaps_save_map_settings">
                <?php wp_nonce_field('routemaps_map_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="rm-primary"><?php echo esc_html__('Fonte principal', 'routemaps'); ?></label></th><td><?php $this->providerSelect('primary_provider', 'rm-primary', (string) $values['primary_provider']); ?></td></tr>
                    <tr><th><label for="rm-fallback"><?php echo esc_html__('Fallback', 'routemaps'); ?></label></th><td><?php $this->providerSelect('fallback_provider', 'rm-fallback', (string) $values['fallback_provider']); ?></td></tr>
                    <tr><th><label for="rm-pmtiles-url"><?php echo esc_html__('PMTiles — URL HTTPS', 'routemaps'); ?></label></th><td><input class="regular-text" id="rm-pmtiles-url" name="pmtiles_url" type="url" value="<?php echo esc_attr((string) $values['pmtiles_url']); ?>"></td></tr>
                    <tr><th><label for="rm-pmtiles-path"><?php echo esc_html__('PMTiles — caminho local', 'routemaps'); ?></label></th><td><input class="regular-text" id="rm-pmtiles-path" name="pmtiles_path" type="text" value="<?php echo esc_attr((string) $values['pmtiles_path']); ?>"><p class="description"><?php echo esc_html__('O caminho nunca é enviado ao browser; quando usado, o ficheiro é servido por um endpoint RouteMaps controlado.', 'routemaps'); ?></p></td></tr>
                    <tr><th><label for="rm-openfree-style"><?php echo esc_html__('OpenFreeMap — estilo', 'routemaps'); ?></label></th><td><input class="regular-text" id="rm-openfree-style" name="openfreemap_style_url" type="url" value="<?php echo esc_attr((string) $values['openfreemap_style_url']); ?>"></td></tr>
                    <tr><th><label for="rm-maptiler-key"><?php echo esc_html__('MapTiler — browser API key', 'routemaps'); ?></label></th><td><input class="regular-text" id="rm-maptiler-key" name="maptiler_key" type="password" autocomplete="new-password" value="<?php echo esc_attr((string) $values['maptiler_key']); ?>"><p class="description"><?php echo esc_html__('Esta chave é usada pelo browser e pode ficar visível nos pedidos ao mapa. Restrinja-a aos domínios autorizados na MapTiler. Não introduza aqui um service token.', 'routemaps'); ?></p></td></tr>
                    <tr><th><label for="rm-style-json"><?php echo esc_html__('Override de estilo JSON', 'routemaps'); ?></label></th><td><textarea class="large-text code" id="rm-style-json" name="style_json" rows="10"><?php echo esc_textarea((string) $values['style_json']); ?></textarea></td></tr>
                </table>
                <?php submit_button(__('Guardar definições', 'routemaps')); ?>
            </form>
        </div>
        <?php
    }

    private function providerSelect(string $name, string $id, string $selected): void {
        $providers = [
            'pmtiles' => 'RouteMaps Self Hosted (PMTiles)',
            'openfreemap' => 'OpenFreeMap',
            'maptiler' => 'MapTiler',
        ];
        printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($name));
        foreach ($providers as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($selected, $value, false), esc_html($label));
        }
        echo '</select>';
    }
}
