<?php

declare(strict_types=1);

namespace RouteMaps\Core\Import;

use RouteMaps\Core\Domain\Routes\RouteDraftData;

interface RouteImporterInterface {
    public function supports(ImportFile $file): bool;
    public function inspect(ImportFile $file): ImportPreview;
    public function import(ImportFile $file, ImportMapping $mapping): RouteDraftData;
}
