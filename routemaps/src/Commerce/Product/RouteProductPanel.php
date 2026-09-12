<?php

declare(strict_types=1);

namespace RouteMaps\Core\Commerce\Product;

use InvalidArgumentException;
use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use WC_Product;

final class RouteProductPanel {
    public function __construct(
        private RouteProductMeta $meta,
        private RouteRepositoryInterface $routes
    ) {
    }

    public function registerHooks(): void {
        add_filter('woocommerce_product_data_tabs', [$this, 'addTab']);
        add_action('woocommerce_product_data_panels', [$this, 'renderPanel']);
        add_action('woocommerce_process_product_meta', [$this, 'saveProduct']);
    }

    /** @param array<string,mixed> $tabs @return array<string,mixed> */
    public function addTab(array $tabs): array {
        $tabs['routemaps'] = [
            'label' => __('RouteMaps', 'routemaps'),
            'target' => 'routemaps_product_data',
            'class' => ['show_if_simple'],
            'priority' => 70,
        ];
        return $tabs;
    }

    public function renderPanel(): void {
        global $post;
        $product = isset($post->ID) ? wc_get_product((int) $post->ID) : false;
        if (!$product instanceof WC_Product) {
            return;
        }

        $routeOptions = ['' => __('Selecionar rota', 'routemaps')];
        foreach ($this->routes->list(100, 0) as $route) {
            $routeOptions[(string) $route->id()] = $route->title();
        }
        ?>
        <div id="routemaps_product_data" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox([
                    'id' => RouteProductMeta::ENABLED,
                    'label' => __('Ativar RouteMaps', 'routemaps'),
                    'description' => __('Vende uma licença autenticada de acesso ao roteiro.', 'routemaps'),
                ]);
                woocommerce_wp_select([
                    'id' => RouteProductMeta::ROUTE_ID,
                    'label' => __('Rota', 'routemaps'),
                    'options' => $routeOptions,
                ]);
                woocommerce_wp_select([
                    'id' => RouteProductMeta::VALIDITY_MODE,
                    'label' => __('Validade', 'routemaps'),
                    'options' => [
                        'unlimited' => __('Ilimitada', 'routemaps'),
                        'days_from_purchase' => __('Dias desde a compra', 'routemaps'),
                        'days_from_first_use' => __('Dias desde a primeira utilização', 'routemaps'),
                        'fixed_range' => __('Intervalo de datas', 'routemaps'),
                    ],
                ]);
                woocommerce_wp_text_input([
                    'id' => RouteProductMeta::VALIDITY_DAYS,
                    'label' => __('Dias de validade', 'routemaps'),
                    'type' => 'number',
                    'custom_attributes' => ['min' => '1', 'step' => '1'],
                ]);
                woocommerce_wp_text_input([
                    'id' => RouteProductMeta::VALID_FROM,
                    'label' => __('Válido desde', 'routemaps'),
                    'type' => 'datetime-local',
                    'value' => $this->dateInputValue((string) $product->get_meta(RouteProductMeta::VALID_FROM, true)),
                ]);
                woocommerce_wp_text_input([
                    'id' => RouteProductMeta::VALID_UNTIL,
                    'label' => __('Válido até', 'routemaps'),
                    'type' => 'datetime-local',
                    'value' => $this->dateInputValue((string) $product->get_meta(RouteProductMeta::VALID_UNTIL, true)),
                ]);
                woocommerce_wp_text_input([
                    'id' => RouteProductMeta::MAX_OPENINGS,
                    'label' => __('Máximo de aberturas', 'routemaps'),
                    'type' => 'number',
                    'custom_attributes' => ['min' => '0', 'step' => '1'],
                    'description' => __('Deixe vazio para ilimitado.', 'routemaps'),
                ]);
                woocommerce_wp_checkbox([
                    'id' => RouteProductMeta::SHARING_ENABLED,
                    'label' => __('Permitir partilhas', 'routemaps'),
                ]);
                woocommerce_wp_text_input([
                    'id' => RouteProductMeta::MAX_SHARES,
                    'label' => __('Máximo de partilhas', 'routemaps'),
                    'type' => 'number',
                    'custom_attributes' => ['min' => '0', 'step' => '1'],
                ]);
                ?>
            </div>
        </div>
        <?php
    }

    public function saveProduct(int $productId): void {
        if (!current_user_can('edit_post', $productId)) {
            return;
        }
        $product = wc_get_product($productId);
        if (!$product instanceof WC_Product) {
            return;
        }

        $input = [];
        foreach ([
            RouteProductMeta::ENABLED,
            RouteProductMeta::ROUTE_ID,
            RouteProductMeta::VALIDITY_MODE,
            RouteProductMeta::VALIDITY_DAYS,
            RouteProductMeta::VALID_FROM,
            RouteProductMeta::VALID_UNTIL,
            RouteProductMeta::MAX_OPENINGS,
            RouteProductMeta::SHARING_ENABLED,
            RouteProductMeta::MAX_SHARES,
        ] as $key) {
            if (isset($_POST[$key])) {
                $input[$key] = wp_unslash($_POST[$key]);
            }
        }
        $input[RouteProductMeta::ENABLED] = $input[RouteProductMeta::ENABLED] ?? 'no';
        $input[RouteProductMeta::SHARING_ENABLED] = $input[RouteProductMeta::SHARING_ENABLED] ?? 'no';

        try {
            $this->meta->save($product, $input);
        } catch (InvalidArgumentException $exception) {
            \WC_Admin_Meta_Boxes::add_error(
                sprintf(
                    /* translators: %s: RouteMaps product configuration error code. */
                    __('A configuração RouteMaps é inválida: %s', 'routemaps'),
                    esc_html($exception->getMessage())
                )
            );
        }
    }

    private function dateInputValue(string $value): string {
        if ('' === trim($value)) {
            return '';
        }
        return str_replace(' ', 'T', substr($value, 0, 16));
    }
}
