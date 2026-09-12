<?php

declare(strict_types=1);

namespace RouteMaps\Core\Viewer;

final class RouteRewriteManager {
    public const QUERY_VIEW = 'routemaps_view';
    public const QUERY_TOKEN = 'routemaps_token';
    public const QUERY_ROUTE_UUID = 'routemaps_route_uuid';

    public function registerHooks(): void {
        add_action('init', [$this, 'registerRules']);
        add_filter('query_vars', [$this, 'queryVars']);
        add_filter('template_include', [$this, 'template'], 99);
    }

    public function registerRules(): void {
        add_rewrite_rule(
            '^routemaps/access/([0-9a-f]{64})/?$',
            'index.php?' . self::QUERY_VIEW . '=access&' . self::QUERY_TOKEN . '=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^routemaps/login/?$',
            'index.php?' . self::QUERY_VIEW . '=login',
            'top'
        );
        add_rewrite_rule(
            '^routemaps/invite/([0-9a-f]{64})/?$',
            'index.php?' . self::QUERY_VIEW . '=invite&' . self::QUERY_TOKEN . '=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^routemaps/app/([0-9a-f-]{36})/?$',
            'index.php?' . self::QUERY_VIEW . '=app&' . self::QUERY_ROUTE_UUID . '=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^routemaps/manifest/([0-9a-f-]{36})\.webmanifest$',
            'index.php?' . self::QUERY_VIEW . '=manifest&' . self::QUERY_ROUTE_UUID . '=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^routemaps/service-worker\.js$',
            'index.php?' . self::QUERY_VIEW . '=service-worker',
            'top'
        );
    }

    /** @param list<string> $vars @return list<string> */
    public function queryVars(array $vars): array {
        foreach ([self::QUERY_VIEW, self::QUERY_TOKEN, self::QUERY_ROUTE_UUID] as $var) {
            if (!in_array($var, $vars, true)) {
                $vars[] = $var;
            }
        }
        return $vars;
    }

    public function template(string $template): string {
        $view = (string) get_query_var(self::QUERY_VIEW);
        if ('login' === $view) {
            $loginTemplate = dirname(__DIR__, 2) . '/templates/login.php';
            return is_readable($loginTemplate) ? $loginTemplate : $template;
        }
        if ('app' === $view) {
            $viewerTemplate = dirname(__DIR__, 2) . '/templates/viewer-shell.php';
            return is_readable($viewerTemplate) ? $viewerTemplate : $template;
        }
        return $template;
    }
}
