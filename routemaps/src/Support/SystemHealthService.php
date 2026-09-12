<?php

declare(strict_types=1);

namespace RouteMaps\Core\Support;

use RouteMaps\Core\Maps\MapSettings;
use RouteMaps\Core\Maps\Providers\PMTilesMapSourceProvider;
use RouteMaps\Core\PWA\ServiceWorkerController;

final class SystemHealthService {
    private MapSettings $mapSettings;
    private PMTilesMapSourceProvider $pmtiles;
    private Logger $logger;

    public function __construct(
        ?MapSettings $mapSettings = null,
        ?PMTilesMapSourceProvider $pmtiles = null,
        ?Logger $logger = null
    ) {
        $this->mapSettings = $mapSettings ?? new MapSettings();
        $this->pmtiles = $pmtiles ?? new PMTilesMapSourceProvider($this->mapSettings);
        $this->logger = $logger ?? new Logger();
    }

    /** @return list<SystemHealthItem> */
    public function check(): array {
        global $wp_version;

        $map = $this->mapSettings->load();
        $primary = sanitize_key((string) ($map['primary_provider'] ?? 'pmtiles'));
        $fallback = sanitize_key((string) ($map['fallback_provider'] ?? 'openfreemap'));
        $pmtilesHealth = $this->pmtiles->health_check();
        $serviceWorker = new ServiceWorkerController();
        $workerAvailable = is_readable($serviceWorker->assetPath());
        $https = function_exists('is_ssl') ? is_ssl() : false;
        $permalink = (string) get_option('permalink_structure', '');
        $recentErrors = $this->logger->recentErrors();

        return [
            new SystemHealthItem('plugin_version', __('Versão RouteMaps', 'routemaps'), 'info', defined('ROUTEMAPS_VERSION') ? ROUTEMAPS_VERSION : 'unknown'),
            new SystemHealthItem('schema_version', __('Schema de base de dados', 'routemaps'), 'info', (int) get_option('routemaps_db_version', 0)),
            new SystemHealthItem('wordpress_version', __('WordPress', 'routemaps'), 'info', isset($wp_version) ? (string) $wp_version : 'unknown'),
            new SystemHealthItem('php_version', __('PHP', 'routemaps'), 'info', PHP_VERSION),
            new SystemHealthItem('woocommerce_version', __('WooCommerce', 'routemaps'), defined('WC_VERSION') ? 'ok' : 'warning', defined('WC_VERSION') ? WC_VERSION : __('Não detetado', 'routemaps')),
            new SystemHealthItem(
                'permalinks',
                __('Permalinks', 'routemaps'),
                '' !== $permalink ? 'ok' : 'warning',
                '' !== $permalink ? $permalink : __('Estrutura simples', 'routemaps'),
                '' !== $permalink ? '' : __('RouteMaps necessita de rewrite rules ativas para os URLs da aplicação.', 'routemaps')
            ),
            new SystemHealthItem(
                'https',
                __('HTTPS', 'routemaps'),
                $https ? 'ok' : 'warning',
                $https ? __('Ativo', 'routemaps') : __('Inativo', 'routemaps'),
                $https ? '' : __('PWA e geolocalização exigem contexto seguro no browser.', 'routemaps')
            ),
            new SystemHealthItem(
                'map_provider',
                __('Fontes do mapa', 'routemaps'),
                'info',
                ['primary' => $primary, 'fallback' => $fallback]
            ),
            new SystemHealthItem(
                'pmtiles',
                __('PMTiles', 'routemaps'),
                $pmtilesHealth->ok() ? 'ok' : 'warning',
                $pmtilesHealth->code(),
                $pmtilesHealth->message()
            ),
            new SystemHealthItem(
                'service_worker',
                __('Service worker', 'routemaps'),
                $workerAvailable && $https ? 'ok' : 'warning',
                home_url('/routemaps/service-worker.js'),
                $workerAvailable
                    ? ($https ? __('Asset disponível e contexto HTTPS ativo.', 'routemaps') : __('Asset disponível; o browser necessita de HTTPS ou localhost para o registar.', 'routemaps'))
                    : __('O asset do service worker não foi encontrado.', 'routemaps')
            ),
            new SystemHealthItem(
                'rewrite_samples',
                __('URLs RouteMaps', 'routemaps'),
                'info',
                [
                    home_url('/routemaps/login/'),
                    home_url('/routemaps/app/{route_uuid}'),
                    home_url('/routemaps/manifest/{route_uuid}.webmanifest'),
                    home_url('/routemaps/service-worker.js'),
                ]
            ),
            new SystemHealthItem(
                'recent_errors',
                __('Erros RouteMaps recentes', 'routemaps'),
                [] === $recentErrors ? 'ok' : 'warning',
                $recentErrors,
                [] === $recentErrors ? __('Sem erros RouteMaps recentes.', 'routemaps') : __('Os valores sensíveis são removidos antes de serem apresentados.', 'routemaps')
            ),
        ];
    }
}
