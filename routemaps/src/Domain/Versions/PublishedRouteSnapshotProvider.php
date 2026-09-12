<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Versions;

interface PublishedRouteSnapshotProvider {
    public function get_published_snapshot(int $route_id, ?int $version_id = null): RouteSnapshot;
}
