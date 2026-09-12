<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Access;

use LogicException;
use RouteMaps\Core\Security\RateLimiter;
use RouteMaps\Core\Security\TokenService;
use RouteMaps\Core\Domain\Licensing\License;
use RouteMaps\Core\Domain\Licensing\LicenseRepositoryInterface;
use RouteMaps\Core\Domain\Licensing\LicenseStatus;
use RouteMaps\Core\Domain\Licensing\ValidityMode;
use RouteMaps\Core\Domain\Sharing\LicenseUserRepositoryInterface;
use RouteMaps\Core\Domain\Sharing\ShareAcceptanceService;
use RouteMaps\Core\Domain\Routes\Route;
use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use RouteMaps\Core\Viewer\LoginController;
use RouteMaps\Core\Viewer\RouteRewriteManager;
use WP_UnitTestCase;

final class LoginRewriteTest extends WP_UnitTestCase {
    public function test_rewrite_rules_cover_access_login_and_invite(): void {
        $manager = new RouteRewriteManager();
        $manager->registerRules();

        global $wp_rewrite;
        $rules = $wp_rewrite->extra_rules_top;

        self::assertArrayHasKey('^routemaps/access/([0-9a-f]{64})/?$', $rules);
        self::assertArrayHasKey('^routemaps/login/?$', $rules);
        self::assertArrayHasKey('^routemaps/invite/([0-9a-f]{64})/?$', $rules);
    }

    public function test_login_continuation_is_opaque_internal_and_remember_is_forwarded_to_wp_signon(): void {
        $controller = new LoginController(new RateLimiter());
        $token = str_repeat('a', 64);
        $path = '/routemaps/access/' . $token;
        $continuation = $controller->encodeContinuation($path);

        self::assertStringNotContainsString($token, $continuation);
        self::assertSame($path, $controller->decodeContinuation($continuation));

        $userId = self::factory()->user->create([
            'user_login' => 'route-traveller',
            'user_email' => 'traveller@example.test',
            'user_pass' => 'strong-test-password',
        ]);
        self::assertGreaterThan(0, $userId);

        $rememberSeen = null;
        $captureRemember = static function (int $seconds, int $capturedUserId, bool $remember) use (&$rememberSeen): int {
            $rememberSeen = $remember;
            return $seconds;
        };
        add_filter('auth_cookie_expiration', $captureRemember, 10, 3);
        $wcSession = function_exists('WC') ? WC()->session : null;
        $wcCookieHookRemoved = is_object($wcSession)
            && is_callable([$wcSession, 'set_customer_session_cookie'])
            && false !== has_action('set_logged_in_cookie', [$wcSession, 'set_customer_session_cookie']);
        if ($wcCookieHookRemoved) {
            remove_action('set_logged_in_cookie', [$wcSession, 'set_customer_session_cookie'], 10);
        }
        try {
            $redirect = $controller->authenticate([
                '_routemaps_login_nonce' => wp_create_nonce('routemaps_login'),
                'log' => 'route-traveller',
                'pwd' => 'strong-test-password',
                'remember' => '1',
                'continue' => $continuation,
            ]);
        } finally {
            remove_filter('auth_cookie_expiration', $captureRemember, 10);
            if ($wcCookieHookRemoved) {
                add_action('set_logged_in_cookie', [$wcSession, 'set_customer_session_cookie'], 10, 6);
            }
            wp_set_current_user(0);
        }

        self::assertTrue($rememberSeen);
        self::assertSame(home_url($path), $redirect);
    }

    public function test_anonymous_protected_route_redirects_to_login_and_authenticated_user_stays_on_route(): void {
        $controller = new LoginController(new RateLimiter());
        $token = str_repeat('b', 64);
        $path = '/routemaps/access/' . $token;

        self::assertSame(
            home_url('/routemaps/login/') . '?continue=' . rawurlencode($controller->encodeContinuation($path)),
            $controller->protectedRouteRedirect('access', $token, false)
        );
        self::assertNull($controller->protectedRouteRedirect('access', $token, true));
        self::assertSame(home_url('/'), $controller->protectedRouteRedirect('external', $token, false));
    }

    public function test_authenticated_access_token_redirects_to_canonical_app_without_token(): void {
        $token = str_repeat('c', 64);
        $license = new License(
            5, '22222222-2222-4222-8222-222222222222', hash('sha256', $token), 10, 11, 12, 20, 30,
            LicenseStatus::ACTIVE, ValidityMode::UNLIMITED, null, null, null, null, 5, 0, false, 0, null, null, '', ''
        );
        $route = new Route(20, '11111111-1111-4111-8111-111111111111', 'Douro', 'douro', 'published', null, 7, null, 30, '', '');
        $licenses = $this->createMock(LicenseRepositoryInterface::class);
        $licenses->method('findByTokenHash')->with(hash('sha256', $token))->willReturn($license);
        $routes = $this->createMock(RouteRepositoryInterface::class);
        $routes->method('find')->with(20)->willReturn($route);
        $controller = new LoginController(new RateLimiter(), null, $licenses, $routes);

        $redirect = $controller->accessRedirect($token);

        self::assertStringNotContainsString($token, $redirect);
        self::assertSame(
            add_query_arg(['license' => $license->uuid()], home_url('/routemaps/app/' . $route->uuid())),
            $redirect
        );
    }

    public function test_canonical_app_continuation_round_trips_safely(): void {
        $controller = new LoginController(new RateLimiter());
        $path = '/routemaps/app/11111111-1111-4111-8111-111111111111?license=22222222-2222-4222-8222-222222222222';

        $opaque = $controller->encodeContinuation($path);

        self::assertSame($path, $controller->decodeContinuation($opaque));
    }

    public function test_external_continuation_is_rejected(): void {
        $controller = new LoginController(new RateLimiter());
        $opaque = rtrim(strtr(base64_encode('https://evil.example/steal'), '+/', '-_'), '=');

        self::assertSame('/', $controller->decodeContinuation($opaque));
    }


    public function test_sensitive_rate_limit_buckets_are_one_way_hashed(): void {
        $captured = [];
        $capture = static function (mixed $pre, string $bucket) use (&$captured): bool {
            $captured[] = $bucket;
            return false;
        };
        add_filter('routemaps_rate_limiter_pre_consume', $capture, 10, 2);

        try {
            $controller = new LoginController(new RateLimiter());
            try {
                $controller->authenticate([
                    '_routemaps_login_nonce' => wp_create_nonce('routemaps_login'),
                    'log' => 'Sensitive.Person@Example.test',
                    'pwd' => 'not-used-because-rate-limit-blocks-first',
                ]);
                self::fail('Expected login_rate_limited');
            } catch (LogicException $error) {
                self::assertSame('login_rate_limited', $error->getMessage());
            }

            $licenses = $this->createMock(LicenseRepositoryInterface::class);
            $routes = $this->createMock(RouteRepositoryInterface::class);
            $access = new LoginController(new RateLimiter(), null, $licenses, $routes);
            $token = str_repeat('d', 64);
            try {
                $access->accessRedirect($token);
                self::fail('Expected access_rate_limited');
            } catch (LogicException $error) {
                self::assertSame('access_rate_limited', $error->getMessage());
            }

            $members = $this->createMock(LicenseUserRepositoryInterface::class);
            $acceptance = new ShareAcceptanceService($members, new TokenService());
            $invite = new LoginController(new RateLimiter(), $acceptance, $licenses, $routes);
            $inviteToken = str_repeat('e', 64);
            try {
                $invite->acceptInvite($inviteToken, 123);
                self::fail('Expected share_invite_rate_limited');
            } catch (LogicException $error) {
                self::assertSame('share_invite_rate_limited', $error->getMessage());
            }
        } finally {
            remove_filter('routemaps_rate_limiter_pre_consume', $capture, 10);
        }

        self::assertCount(3, $captured);
        self::assertMatchesRegularExpression('/^login:[0-9a-f]{64}$/', $captured[0]);
        self::assertMatchesRegularExpression('/^access_token:[0-9a-f]{64}$/', $captured[1]);
        self::assertMatchesRegularExpression('/^invite_token:[0-9a-f]{64}$/', $captured[2]);
        self::assertStringNotContainsString('sensitive.person@example.test', strtolower(implode('|', $captured)));
        self::assertStringNotContainsString($token, implode('|', $captured));
        self::assertStringNotContainsString($inviteToken, implode('|', $captured));
    }

    public function test_rate_limiter_normalizes_bucket_and_blocks_after_limit(): void {
        $limiter = new RateLimiter();
        $bucket = ' Login:Traveller@Example.Test ';

        self::assertTrue($limiter->consume($bucket, 2, 60));
        self::assertTrue($limiter->consume('login:traveller@example.test', 2, 60));
        self::assertFalse($limiter->consume('LOGIN:TRAVELLER@EXAMPLE.TEST', 2, 60));
    }
}