<?php

declare(strict_types=1);

namespace RouteMaps\Core\Import;

use InvalidArgumentException;
use RouteMaps\Core\Import\Exception\UnsupportedImportFormat;

final class ImporterRegistry {
    /** @var list<RouteImporterInterface> */
    private array $importers;

    /** @param iterable<RouteImporterInterface> $importers */
    public function __construct(iterable $importers) {
        $this->importers = [];
        foreach ($importers as $importer) {
            if (!$importer instanceof RouteImporterInterface) {
                throw new InvalidArgumentException('invalid_route_importer');
            }
            $this->importers[] = $importer;
        }
    }

    public function for(ImportFile $file): RouteImporterInterface {
        $matches = [];
        foreach ($this->importers as $importer) {
            if ($importer->supports($file)) {
                $matches[] = $importer;
            }
        }

        if (0 === count($matches)) {
            throw new UnsupportedImportFormat('unsupported_import_format');
        }
        if (1 !== count($matches)) {
            throw new UnsupportedImportFormat('ambiguous_import_format');
        }

        return $matches[0];
    }
}
