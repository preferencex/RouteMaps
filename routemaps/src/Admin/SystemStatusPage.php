<?php

declare(strict_types=1);

namespace RouteMaps\Core\Admin;

use RouteMaps\Core\Support\SystemHealthItem;
use RouteMaps\Core\Support\SystemHealthService;

final class SystemStatusPage {
    private const PARENT_SLUG = 'routemaps-routes';
    private const MENU_SLUG = 'routemaps-system-status';

    public function __construct(private ?SystemHealthService $health = null) {
        $this->health ??= new SystemHealthService();
    }

    public function registerHooks(): void {
        add_action('admin_menu', [$this, 'registerMenu'], 40);
    }

    public function capability(): string {
        return 'manage_routemaps_settings';
    }

    public function registerMenu(): void {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Estado do sistema', 'routemaps'),
            __('Estado do sistema', 'routemaps'),
            $this->capability(),
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void {
        if (!current_user_can($this->capability())) {
            return;
        }
        $items = $this->health->check();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('RouteMaps — Estado do sistema', 'routemaps'); ?></h1>
            <p><?php echo esc_html__('Diagnóstico técnico sem tokens, palavras-passe ou chaves de fornecedor.', 'routemaps'); ?></p>
            <table class="widefat striped" role="presentation">
                <thead><tr><th><?php echo esc_html__('Verificação', 'routemaps'); ?></th><th><?php echo esc_html__('Estado', 'routemaps'); ?></th><th><?php echo esc_html__('Valor', 'routemaps'); ?></th><th><?php echo esc_html__('Nota', 'routemaps'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($items as $item) : if (!$item instanceof SystemHealthItem) { continue; } ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($item->label()); ?></th>
                        <td><code><?php echo esc_html($item->status()); ?></code></td>
                        <td><?php echo $this->renderValue($item->value()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderValue escapes. ?></td>
                        <td><?php echo esc_html($item->message()); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function renderValue(mixed $value): string {
        if (is_array($value)) {
            $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return '<pre style="white-space:pre-wrap;margin:0">' . esc_html(is_string($encoded) ? $encoded : '') . '</pre>';
        }
        if (is_bool($value)) {
            return esc_html($value ? __('Sim', 'routemaps') : __('Não', 'routemaps'));
        }
        return esc_html((string) $value);
    }
}
