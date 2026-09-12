<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Unit\Import;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RouteMaps\Core\Import\ImportFile;
use RouteMaps\Core\Import\Security\ImportFileValidator;

final class ImportFileValidatorTest extends TestCase {
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }

    public function test_rejects_file_larger_than_configured_maximum(): void {
        $file = $this->file('route.kml', str_repeat('x', 65), 'application/vnd.google-earth.kml+xml');
        $validator = new ImportFileValidator(64, 1024, 50.0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('import_file_too_large');
        $validator->validate($file);
    }

    public function test_rejects_unsupported_extension(): void {
        $file = $this->file('route.txt', 'plain text', 'text/plain');
        $validator = new ImportFileValidator(1024, 4096, 50.0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('import_extension_unsupported');
        $validator->validate($file);
    }

    public function test_rejects_mismatched_extension_and_mime(): void {
        $file = $this->file('route.kml', $this->zipBytes([['name' => 'doc.kml', 'contents' => '<kml/>']]), 'application/zip');
        $validator = new ImportFileValidator(1024 * 1024, 2 * 1024 * 1024, 50.0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('import_mime_mismatch');
        $validator->validate($file);
    }

    public function test_rejects_kml_external_entity_declaration(): void {
        $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE kml [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<kml><Document><name>&xxe;</name></Document></kml>
XML;
        $file = $this->file('route.kml', $xml, 'application/xml');
        $validator = new ImportFileValidator(1024 * 1024, 2 * 1024 * 1024, 50.0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('import_kml_external_entity');
        $validator->validate($file);
    }

    public function test_rejects_kmz_path_traversal_entry(): void {
        $file = $this->file(
            'route.kmz',
            $this->zipBytes([
                ['name' => 'doc.kml', 'contents' => '<kml/>'],
                ['name' => '../evil.php', 'contents' => '<?php echo 1;'],
            ]),
            'application/zip'
        );
        $validator = new ImportFileValidator(1024 * 1024, 2 * 1024 * 1024, 50.0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('import_kmz_path_traversal');
        $validator->validate($file);
    }

    public function test_rejects_encrypted_kmz_entry(): void {
        $file = $this->file(
            'route.kmz',
            $this->zipBytes([['name' => 'doc.kml', 'contents' => '<kml/>', 'flags' => 0x0001]]),
            'application/zip'
        );
        $validator = new ImportFileValidator(1024 * 1024, 2 * 1024 * 1024, 50.0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('import_kmz_encrypted_entry');
        $validator->validate($file);
    }

    public function test_rejects_kmz_expansion_ratio_above_ceiling(): void {
        $file = $this->file(
            'route.kmz',
            $this->zipBytes([[
                'name' => 'doc.kml',
                'contents' => 'x',
                'reported_compressed_size' => 1,
                'reported_uncompressed_size' => 1000,
            ]]),
            'application/zip'
        );
        $validator = new ImportFileValidator(1024 * 1024, 2 * 1024 * 1024, 10.0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('import_kmz_expansion_limit');
        $validator->validate($file);
    }

    #[DataProvider('supportedFileProvider')]
    public function test_accepts_supported_safe_files(string $name, string $contents, string $mime): void {
        $file = $this->file($name, $contents, $mime);
        $validator = new ImportFileValidator(1024 * 1024, 2 * 1024 * 1024, 50.0);

        $validator->validate($file);
        self::assertTrue(true);
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function supportedFileProvider(): iterable {
        yield 'kml' => ['route.kml', '<?xml version="1.0"?><kml><Document/></kml>', 'application/xml'];
        yield 'geojson' => ['route.geojson', '{"type":"FeatureCollection","features":[]}', 'application/json'];
    }

    private function file(string $name, string $contents, ?string $clientMime): ImportFile {
        $path = tempnam(sys_get_temp_dir(), 'routemaps-import-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return new ImportFile($path, $name, $clientMime);
    }

    /**
     * Build a minimal stored ZIP with a valid central directory so security
     * metadata can be tested without relying on ZipArchive in the test helper.
     *
     * @param list<array{name:string,contents:string,flags?:int,reported_compressed_size?:int,reported_uncompressed_size?:int}> $entries
     */
    private function zipBytes(array $entries): string {
        $local = '';
        $central = '';
        $offset = 0;

        foreach ($entries as $entry) {
            $name = $entry['name'];
            $contents = $entry['contents'];
            $flags = $entry['flags'] ?? 0;
            $crc = (int) sprintf('%u', crc32($contents));
            $actualSize = strlen($contents);
            $compressedSize = $entry['reported_compressed_size'] ?? $actualSize;
            $uncompressedSize = $entry['reported_uncompressed_size'] ?? $actualSize;
            $nameLength = strlen($name);

            $localHeader = "PK\x03\x04"
                . pack('vvvvvVVVvv', 20, $flags, 0, 0, 0, $crc, $compressedSize, $uncompressedSize, $nameLength, 0)
                . $name;
            $local .= $localHeader . $contents;

            $central .= "PK\x01\x02"
                . pack('vvvvvvVVVvvvvvVV', 20, 20, $flags, 0, 0, 0, $crc, $compressedSize, $uncompressedSize, $nameLength, 0, 0, 0, 0, 0, $offset)
                . $name;
            $offset += strlen($localHeader) + $actualSize;
        }

        $count = count($entries);
        return $local
            . $central
            . "PK\x05\x06"
            . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), strlen($local), 0);
    }
}
