<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Release;

use PHPUnit\Framework\TestCase;

final class ReleaseToolingTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname(__DIR__, 3);
    }

    public function test_release_environment_scripts_exist_and_are_shell_scripts(): void {
        foreach (['build/bootstrap-release-env.sh', 'build/release-gate.sh'] as $relative) {
            $path = $this->root . '/' . $relative;
            self::assertFileExists($path);
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertStringStartsWith('#!/usr/bin/env bash', $contents);
            self::assertStringContainsString('set -euo pipefail', $contents);
        }
    }

    public function test_bootstrap_generates_lockfiles_transactionally(): void {
        $contents = file_get_contents($this->root . '/build/bootstrap-release-env.sh');
        self::assertIsString($contents);
        self::assertStringContainsString('mktemp -d', $contents);
        self::assertStringContainsString('composer update --no-install', $contents);
        self::assertStringContainsString('npm install --package-lock-only', $contents);
        self::assertStringContainsString('cp "$tmp/composer.lock"', $contents);
        self::assertStringContainsString('cp "$tmp/package-lock.json"', $contents);
        self::assertStringContainsString('trap \'rm -rf "${tmp:-}"\' EXIT', $contents);
        self::assertStringContainsString('trap - EXIT', $contents);
    }

    public function test_generated_release_directories_are_git_ignored(): void {
        $gitignore = file_get_contents($this->root . '/.gitignore');
        self::assertIsString($gitignore);
        foreach ([
            '/vendor/',
            '/node_modules/',
            '/dist/',
            '/assets/admin/',
            '/assets/viewer/',
            '/playwright-report/',
            '/test-results/',
            '/.phpunit.cache/',
        ] as $path) {
            self::assertStringContainsString($path, $gitignore);
        }
    }

    public function test_wordpress_phpunit_polyfills_are_declared_and_wired(): void {
        $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame('^4.0', $composer['require-dev']['yoast/phpunit-polyfills'] ?? null);
        self::assertSame('^9.6', $composer['require-dev']['phpunit/phpunit'] ?? null);

        $bootstrap = file_get_contents($this->root . '/tests/bootstrap.php');
        self::assertIsString($bootstrap);
        self::assertStringContainsString('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $bootstrap);
        self::assertStringContainsString('/vendor/yoast/phpunit-polyfills', $bootstrap);
    }

    public function test_release_gate_runs_the_five_approved_commands(): void {
        $contents = file_get_contents($this->root . '/build/release-gate.sh');
        self::assertIsString($contents);
        foreach ([
            'composer quality',
            'npm test -- --run',
            'npm run build',
            'npx playwright test',
            'bash build/package.sh',
        ] as $command) {
            self::assertStringContainsString($command, $contents);
        }
    }
}
