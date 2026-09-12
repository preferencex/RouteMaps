<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Security;

use RouteMaps\Core\Security\RateLimiter;
use RouteMaps\Core\Security\UrlPolicy;
use RouteMaps\Core\Security\ViewerHeaders;
use WP_REST_Response;
use WP_UnitTestCase;

final class ViewerSecurityTest extends WP_UnitTestCase {
    public function test_protected_response_headers_prevent_public_caching_and_reduce_leakage(): void {
        $headers = new ViewerHeaders();
        $values = $headers->values();

        self::assertStringContainsString('private', strtolower($values['Cache-Control']));
        self::assertStringContainsString('no-store', strtolower($values['Cache-Control']));
        self::assertSame('no-cache', $values['Pragma']);
        self::assertSame('nosniff', $values['X-Content-Type-Options']);
        self::assertSame('no-referrer', $values['Referrer-Policy']);
        self::assertSame('SAMEORIGIN', $values['X-Frame-Options']);

        $response = $headers->applyToRestResponse(new WP_REST_Response(['allowed' => false, 'reason' => 'invalid_token'], 403));
        self::assertSame('private, no-store, max-age=0, must-revalidate', $response->get_headers()['Cache-Control']);
        self::assertSame(['allowed' => false, 'reason' => 'invalid_token'], $response->get_data());
        self::assertArrayNotHasKey('snapshot', $response->get_data());
        self::assertArrayNotHasKey('geometry', $response->get_data());
    }

    public function test_url_policy_rejects_executable_or_credential_urls(): void {
        $policy = new UrlPolicy();

        self::assertSame('https://example.test/path?q=1', $policy->external('https://example.test/path?q=1'));
        self::assertSame('http://example.test/path', $policy->external('http://example.test/path'));
        self::assertNull($policy->external('javascript:alert(1)'));
        self::assertNull($policy->external('data:text/html;base64,PHNjcmlwdD4='));
        self::assertNull($policy->external('ftp://example.test/file'));
        self::assertNull($policy->external('https://user:password@example.test/private'));
        self::assertNull($policy->external('https://localhost/internal'));
    }

    public function test_rate_limit_bucket_never_contains_raw_login_or_token(): void {
        $limiter = new RateLimiter();
        $login = 'Person@Example.test';
        $token = str_repeat('a', 64);

        $loginBucket = $limiter->bucket('login', $login);
        $tokenBucket = $limiter->bucket('access_token', $token);

        self::assertStringStartsWith('login:', $loginBucket);
        self::assertStringStartsWith('access_token:', $tokenBucket);
        self::assertStringNotContainsString(strtolower($login), strtolower($loginBucket));
        self::assertStringNotContainsString($token, $tokenBucket);
        self::assertMatchesRegularExpression('/^login:[0-9a-f]{64}$/', $loginBucket);
        self::assertMatchesRegularExpression('/^access_token:[0-9a-f]{64}$/', $tokenBucket);
    }
}
