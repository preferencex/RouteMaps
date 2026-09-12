<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RouteMaps\Core\Support\Logger;

final class LoggerTest extends TestCase {
    public function test_sensitive_context_is_redacted_recursively_but_domain_ids_remain(): void {
        $sink = new class() {
            /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
            public array $entries = [];
            public function info(string $message, array $context = []): void { $this->entries[] = ['level' => 'info', 'message' => $message, 'context' => $context]; }
            public function error(string $message, array $context = []): void { $this->entries[] = ['level' => 'error', 'message' => $message, 'context' => $context]; }
        };

        $logger = new Logger($sink);
        $logger->info('access_check', [
            'route_id' => 91,
            'license_id' => 17,
            'token' => 'plain-token',
            'public_token' => 'public-secret',
            'invite_token' => 'invite-secret',
            'password' => 'hunter2',
            'authorization' => 'Bearer abc',
            'nested' => [
                'email' => 'person@example.test',
                'api_key' => 'provider-secret',
            ],
        ]);

        self::assertCount(1, $sink->entries);
        $context = $sink->entries[0]['context'];
        self::assertSame('routemaps', $context['source']);
        self::assertSame(91, $context['route_id']);
        self::assertSame(17, $context['license_id']);
        self::assertSame('[redacted]', $context['token']);
        self::assertSame('[redacted]', $context['public_token']);
        self::assertSame('[redacted]', $context['invite_token']);
        self::assertSame('[redacted]', $context['password']);
        self::assertSame('[redacted]', $context['authorization']);
        self::assertSame('[redacted]', $context['nested']['email']);
        self::assertSame('[redacted]', $context['nested']['api_key']);
    }

    public function test_token_like_values_inside_urls_and_bearer_strings_are_scrubbed(): void {
        $sink = new class() {
            public array $entries = [];
            public function info(string $message, array $context = []): void { $this->entries[] = $context; }
            public function error(string $message, array $context = []): void { $this->entries[] = $context; }
        };
        $logger = new Logger($sink);
        $logger->error('request_failed', [
            'url' => 'https://example.test/routemaps/access/' . str_repeat('a', 64) . '?token=' . str_repeat('b', 64),
            'header' => 'Bearer super-secret-value',
        ]);

        $encoded = json_encode($sink->entries, JSON_UNESCAPED_SLASHES);
        self::assertIsString($encoded);
        self::assertStringNotContainsString(str_repeat('a', 64), $encoded);
        self::assertStringNotContainsString(str_repeat('b', 64), $encoded);
        self::assertStringNotContainsString('super-secret-value', $encoded);
        self::assertStringContainsString('[redacted]', $encoded);
    }
}
