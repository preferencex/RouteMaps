<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;

final class JavaScriptI18nTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname(__DIR__, 3);
    }

    public function test_wordpress_i18n_dependency_and_shared_adapter_are_present(): void {
        $package = json_decode((string) file_get_contents($this->root . '/package.json'), true);

        self::assertSame('6.27.0', $package['dependencies']['@wordpress/i18n'] ?? null);

        $adapter = (string) file_get_contents($this->root . '/assets-src/shared/i18n.js');
        self::assertStringContainsString("from '@wordpress/i18n'", $adapter);
        self::assertStringContainsString("'routemaps'", $adapter);
    }

    public function test_admin_and_viewer_use_shared_translation_adapter(): void {
        $files = [
            '/assets-src/admin/index.js',
            '/assets-src/viewer/index.js',
            '/assets-src/viewer/state.js',
            '/assets-src/viewer/pois.js',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($this->root . $file);
            self::assertStringContainsString("../shared/i18n.js", $source, $file);
        }
    }

    public function test_known_user_facing_import_and_category_strings_are_not_left_raw(): void {
        $sources = [
            (string) file_get_contents($this->root . '/assets-src/admin/index.js'),
            (string) file_get_contents($this->root . '/assets-src/viewer/index.js'),
        ];
        $combined = implode("\n", $sources);

        foreach ([
            "this.showImportStatus('A analisar…')",
            "this.showImportStatus('Ficheiro analisado.'",
            "source.name || 'Categoria'",
            "category.name || 'Categoria'",
            "this.showImportStatus('A criar rascunho…')",
            "} pontos`",
        ] as $rawUiLiteral) {
            self::assertStringNotContainsString($rawUiLiteral, $combined, $rawUiLiteral);
        }
    }

    public function test_php_bootstraps_expose_translated_javascript_catalogues(): void {
        $admin = (string) file_get_contents($this->root . '/src/Admin/RoutesPage.php');
        $viewer = (string) file_get_contents($this->root . '/src/Viewer/ViewerController.php');

        self::assertStringContainsString("'i18n' =>", $admin);
        self::assertStringContainsString("'i18n' =>", $viewer);
    }
}
