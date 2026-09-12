<?php

declare(strict_types=1);

namespace RouteMaps\Core\Import;

use InvalidArgumentException;
use RouteMaps\Core\Domain\Routes\RouteDraftData;
use RouteMaps\Core\Import\Security\ImportFileValidator;
use RuntimeException;
use ZipArchive;

final class KmzRouteImporter implements RouteImporterInterface {
    public function __construct(
        private ?ImportFileValidator $validator = null,
        private ?KmlRouteImporter $kml = null
    ) {
        $this->validator ??= new ImportFileValidator();
        $this->kml ??= new KmlRouteImporter($this->validator);
    }

    public function supports(ImportFile $file): bool {
        return 'kmz' === $file->extension();
    }

    public function inspect(ImportFile $file): ImportPreview {
        $this->validator->validate($file);
        [$entry, $xml] = $this->readKml($file);
        return $this->kml->inspectXml($xml, 'kmz', ['kmz_entry' => $entry]);
    }

    public function import(ImportFile $file, ImportMapping $mapping): RouteDraftData {
        $this->validator->validate($file);
        [$entry, $xml] = $this->readKml($file);
        return $this->kml->importXml(
            $xml,
            $mapping,
            'kmz',
            ['kmz_entry' => $entry],
            pathinfo($file->originalName(), PATHINFO_FILENAME)
        );
    }

    /** @return array{0:string,1:string} */
    private function readKml(ImportFile $file): array {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('zip_extension_required');
        }
        $archive = new ZipArchive();
        $opened = $archive->open($file->path(), ZipArchive::RDONLY);
        if (true !== $opened) {
            throw new InvalidArgumentException('import_kmz_invalid_archive');
        }
        try {
            $selectedIndex = null;
            $selectedName = null;
            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $stat = $archive->statIndex($index);
                if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
                    continue;
                }
                $name = $stat['name'];
                if (!str_ends_with(strtolower($name), '.kml')) {
                    continue;
                }
                if (null === $selectedIndex || 'doc.kml' === strtolower(basename($name))) {
                    $selectedIndex = $index;
                    $selectedName = $name;
                }
                if ('doc.kml' === strtolower(basename($name))) {
                    break;
                }
            }
            if (null === $selectedIndex || null === $selectedName) {
                throw new InvalidArgumentException('import_kmz_kml_missing');
            }
            $xml = $archive->getFromIndex($selectedIndex);
            if (!is_string($xml) || '' === trim($xml)) {
                throw new InvalidArgumentException('import_kmz_kml_invalid');
            }
            return [$selectedName, $xml];
        } finally {
            $archive->close();
        }
    }
}
