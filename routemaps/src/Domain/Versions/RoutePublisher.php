<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Versions;

use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use RouteMaps\Core\Infrastructure\Database\TransactionManager;
use RuntimeException;

final class RoutePublisher {
    public function __construct(
        private RouteRepositoryInterface $routes,
        private RouteVersionRepositoryInterface $versions,
        private RouteSnapshotBuilder $snapshotBuilder,
        private TransactionManager $transactions
    ) {
    }

    public function publish(int $routeId, int $userId, bool $critical, ?string $summary): RouteVersion {
        $route = $this->routes->find($routeId);
        if (null === $route) {
            throw new RuntimeException('route_not_found');
        }
        $draft = $this->versions->findDraftForRoute($routeId);
        if (null === $draft) {
            throw new RuntimeException('route_draft_not_found');
        }

        $snapshot = $this->snapshotBuilder->build($route, $draft);
        $publishedAt = gmdate('Y-m-d H:i:s');

        $published = $this->transactions->run(
            function () use ($routeId, $draft, $snapshot, $critical, $summary, $publishedAt): RouteVersion {
                $this->versions->updateSnapshot(
                    $draft->id(),
                    $snapshot->canonicalJson(),
                    $snapshot->contentHash()
                );
                $publishedVersion = $this->versions->markPublished(
                    $draft->id(),
                    $critical,
                    $summary,
                    $publishedAt
                );
                $this->routes->updatePublishedVersion($routeId, $publishedVersion->id());

                return $publishedVersion;
            }
        );

        $updatedRoute = $this->routes->find($routeId);
        if (null === $updatedRoute) {
            throw new RuntimeException('route_not_found_after_publish');
        }

        do_action('routemaps_route_published', $updatedRoute, $published);

        return $published;
    }
}
