<?php

declare(strict_types=1);

namespace RouteMaps\Core\Viewer;

use LogicException;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;
use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use RouteMaps\Core\Domain\Sharing\ShareAcceptanceService;
use RouteMaps\Core\Security\RateLimiter;

final class LoginController {
    private const LOGIN_LIMIT = 5;
    private const LOGIN_WINDOW = 900;
    private const ACCESS_TOKEN_LIMIT = 20;
    private const ACCESS_TOKEN_WINDOW = 300;
    private const INVITE_TOKEN_LIMIT = 10;
    private const INVITE_TOKEN_WINDOW = 900;

    public function __construct(
        private RateLimiter $rateLimiter,
        private ?ShareAcceptanceService $shareAcceptance = null,
        private ?LicenseRepositoryInterface $licenses = null,
        private ?RouteRepositoryInterface $routes = null
    ) {
    }

    public function registerHooks(): void {
        add_action('template_redirect', [$this, 'handleTemplateRedirect'], 0);
    }

    public function handleTemplateRedirect(): void {
        $view = (string) get_query_var(RouteRewriteManager::QUERY_VIEW);
        if (!in_array($view, ['access', 'invite', 'login'], true)) {
            return;
        }

        if (in_array($view, ['access', 'invite'], true)) {
            $token = strtolower(trim((string) get_query_var(RouteRewriteManager::QUERY_TOKEN)));
            $loggedIn = is_user_logged_in();
            $protectedRedirect = $this->protectedRouteRedirect($view, $token, $loggedIn);
            if (null !== $protectedRedirect) {
                wp_safe_redirect($protectedRedirect);
                exit;
            }
            if ('access' === $view && $loggedIn) {
                try {
                    $redirect = $this->accessRedirect($token);
                } catch (LogicException $error) {
                    $redirect = add_query_arg(['routemaps_error' => sanitize_key($error->getMessage())], home_url('/'));
                }
                wp_safe_redirect($redirect);
                exit;
            }
            if ('invite' === $view && $loggedIn) {
                try {
                    $redirect = $this->acceptInvite($token, get_current_user_id());
                } catch (LogicException $error) {
                    $redirect = add_query_arg(['routemaps_error' => sanitize_key($error->getMessage())], home_url('/'));
                }
                wp_safe_redirect($redirect);
                exit;
            }
        }

        $requestMethod = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD'])))
            : 'GET';
        if ('login' !== $view || 'POST' !== $requestMethod) {
            return;
        }

        $nonce = isset($_POST['_routemaps_login_nonce'])
            ? (string) wp_unslash($_POST['_routemaps_login_nonce'])
            : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified immediately below.
        if ('' === $nonce || !wp_verify_nonce($nonce, 'routemaps_login')) {
            wp_safe_redirect($this->loginUrl('', 'login_csrf'));
            exit;
        }

        $request = is_array($_POST) ? wp_unslash($_POST) : [];
        try {
            $redirect = $this->authenticate($request);
        } catch (LogicException $error) {
            $continue = $this->stringValue($request['continue'] ?? '');
            $redirect = $this->loginUrl($continue, $error->getMessage());
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /** @param array<string,mixed> $request */
    public function authenticate(array $request): string {
        $nonce = $this->stringValue($request['_routemaps_login_nonce'] ?? '');
        if ('' === $nonce || !wp_verify_nonce($nonce, 'routemaps_login')) {
            throw new LogicException('login_csrf');
        }

        $login = trim($this->stringValue($request['log'] ?? ''));
        $password = $this->stringValue($request['pwd'] ?? '');
        if ('' === $login || '' === $password) {
            throw new LogicException('login_invalid');
        }

        if (!$this->rateLimiter->consume($this->rateLimiter->bucket('login', $login), self::LOGIN_LIMIT, self::LOGIN_WINDOW)) {
            throw new LogicException('login_rate_limited');
        }

        $remember = !empty($request['remember']);
        $user = wp_signon(
            [
                'user_login' => $login,
                'user_password' => $password,
                'remember' => $remember,
            ],
            function_exists('is_ssl') && is_ssl()
        );

        if (is_wp_error($user)) {
            throw new LogicException('login_failed');
        }

        $target = $this->decodeContinuation($this->stringValue($request['continue'] ?? ''));
        return home_url($target);
    }

    public function accessRedirect(string $plainToken): string {
        if (null === $this->licenses || null === $this->routes) {
            throw new LogicException('route_access_unavailable');
        }
        $plainToken = strtolower(trim($plainToken));
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $plainToken)) {
            throw new LogicException('invalid_token');
        }
        if (!$this->rateLimiter->consume(
            $this->rateLimiter->bucket('access_token', $plainToken),
            self::ACCESS_TOKEN_LIMIT,
            self::ACCESS_TOKEN_WINDOW
        )) {
            throw new LogicException('access_rate_limited');
        }
        $license = $this->licenses->findByTokenHash(hash('sha256', $plainToken));
        if (null === $license) {
            throw new LogicException('invalid_token');
        }
        $route = $this->routes->find($license->routeId());
        if (null === $route) {
            throw new LogicException('route_not_found');
        }
        return add_query_arg(
            ['license' => $license->uuid()],
            home_url('/routemaps/app/' . rawurlencode($route->uuid()))
        );
    }

    public function acceptInvite(string $plainToken, int $userId): string {
        if (null === $this->shareAcceptance || null === $this->licenses || null === $this->routes) {
            throw new LogicException('share_invite_unavailable');
        }
        $plainToken = strtolower(trim($plainToken));
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $plainToken)) {
            throw new LogicException('share_invite_invalid');
        }
        if (!$this->rateLimiter->consume(
            $this->rateLimiter->bucket('invite_token', $plainToken),
            self::INVITE_TOKEN_LIMIT,
            self::INVITE_TOKEN_WINDOW
        )) {
            throw new LogicException('share_invite_rate_limited');
        }
        $member = $this->shareAcceptance->accept($plainToken, $userId);
        $license = $this->licenses->find($member->licenseId());
        if (null === $license) {
            throw new LogicException('license_not_found');
        }
        $route = $this->routes->find($license->routeId());
        if (null === $route) {
            throw new LogicException('route_not_found');
        }
        return add_query_arg(
            ['license' => $license->uuid()],
            home_url('/routemaps/app/' . rawurlencode($route->uuid()))
        );
    }

    public function loginUrlForPath(string $path): string {
        return $this->loginUrl($this->encodeContinuation($path));
    }

    public function protectedRouteRedirect(string $view, string $token, bool $isLoggedIn): ?string {
        if ($isLoggedIn) {
            return null;
        }
        if (!in_array($view, ['access', 'invite'], true) || 1 !== preg_match('/^[0-9a-f]{64}$/', $token)) {
            return home_url('/');
        }
        return $this->loginUrlForPath('/routemaps/' . $view . '/' . $token);
    }

    public function encodeContinuation(string $path): string {
        if (!$this->isAllowedContinuation($path)) {
            throw new LogicException('login_redirect_invalid');
        }
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    }

    public function decodeContinuation(string $opaque): string {
        $opaque = trim($opaque);
        if ('' === $opaque || 1 !== preg_match('/^[A-Za-z0-9_-]+$/', $opaque)) {
            return '/';
        }
        $padding = (4 - (strlen($opaque) % 4)) % 4;
        $decoded = base64_decode(strtr($opaque . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded) || !$this->isAllowedContinuation($decoded)) {
            return '/';
        }
        return $decoded;
    }

    private function loginUrl(string $continue = '', ?string $error = null): string {
        $url = home_url('/routemaps/login/');
        $query = [];
        if ('' !== $continue) {
            $query['continue'] = $continue;
        }
        if (null !== $error && '' !== $error) {
            $query['error'] = sanitize_key($error);
        }
        return [] === $query ? $url : add_query_arg($query, $url);
    }

    private function isAllowedContinuation(string $path): bool {
        if (1 === preg_match('#^/routemaps/(?:access|invite)/[0-9a-f]{64}/?$#', $path)) {
            return true;
        }

        return 1 === preg_match(
            '#^/routemaps/app/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/?\?license=[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#i',
            $path
        );
    }

    private function stringValue(mixed $value): string {
        if (!is_string($value)) {
            return '';
        }
        return function_exists('wp_unslash') ? (string) wp_unslash($value) : stripslashes($value);
    }
}
