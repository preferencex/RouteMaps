<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;

final class PackagingFilesTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname(__DIR__, 3);
    }

    public function test_release_files_and_catalogue_exist(): void {
        self::assertFileExists($this->root . '/.distignore');
        self::assertFileExists($this->root . '/build/package.sh');
        self::assertFileExists($this->root . '/languages/routemaps.pot');
    }

    public function test_distignore_excludes_development_only_content(): void {
        $ignore = (string) file_get_contents($this->root . '/.distignore');

        foreach (['/tests/', '/assets-src/', '/node_modules/', '/build/', '/dist/'] as $entry) {
            self::assertStringContainsString($entry, $ignore);
        }
    }

    public function test_packager_uses_clean_dependency_installs_and_inspects_archive(): void {
        $script = (string) file_get_contents($this->root . '/build/package.sh');

        self::assertStringContainsString('php build/make-pot.php', $script);
        self::assertStringContainsString('composer install --no-dev', $script);
        self::assertStringContainsString('npm ci', $script);
        self::assertStringContainsString('npm run build', $script);
        self::assertStringContainsString('unzip -l', $script);
    }
}
