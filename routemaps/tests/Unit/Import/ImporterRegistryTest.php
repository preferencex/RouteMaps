<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use RouteMaps\Core\Domain\Routes\RouteDraftData;
use RouteMaps\Core\Import\Exception\UnsupportedImportFormat;
use RouteMaps\Core\Import\ImportFile;
use RouteMaps\Core\Import\ImportMapping;
use RouteMaps\Core\Import\ImporterRegistry;
use RouteMaps\Core\Import\ImportPreview;
use RouteMaps\Core\Import\RouteImporterInterface;

final class ImporterRegistryTest extends TestCase {
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }

    public function test_returns_the_only_importer_that_supports_file(): void {
        $supported = $this->importer(true);
        $registry = new ImporterRegistry([$this->importer(false), $supported]);

        self::assertSame($supported, $registry->for($this->file('route.kml')));
    }

    public function test_throws_when_no_importer_supports_file(): void {
        $registry = new ImporterRegistry([$this->importer(false)]);

        $this->expectException(UnsupportedImportFormat::class);
        $this->expectExceptionMessage('unsupported_import_format');
        $registry->for($this->file('route.txt'));
    }

    public function test_throws_when_more_than_one_importer_claims_file(): void {
        $registry = new ImporterRegistry([$this->importer(true), $this->importer(true)]);

        $this->expectException(UnsupportedImportFormat::class);
        $this->expectExceptionMessage('ambiguous_import_format');
        $registry->for($this->file('route.kml'));
    }

    private function importer(bool $supports): RouteImporterInterface {
        return new class($supports) implements RouteImporterInterface {
            public function __construct(private bool $supports) {}
            public function supports(ImportFile $file): bool { return $this->supports; }
            public function inspect(ImportFile $file): ImportPreview {
                return new ImportPreview('test', null, [], [], [], [], []);
            }
            public function import(ImportFile $file, ImportMapping $mapping): RouteDraftData {
                return new RouteDraftData(
                    'Imported',
                    ['type' => 'LineString', 'coordinates' => [[-8.6, 41.1], [-7.8, 41.2]]],
                    [],
                    [],
                    ['center' => [-8.2, 41.15], 'zoom' => 9]
                );
            }
        };
    }

    private function file(string $name): ImportFile {
        $path = tempnam(sys_get_temp_dir(), 'routemaps-registry-');
        self::assertIsString($path);
        file_put_contents($path, 'x');
        $this->tempFiles[] = $path;
        return new ImportFile($path, $name, null);
    }
}
