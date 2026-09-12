<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Routes;

use JsonException;
use RouteMaps\Core\Domain\Versions\RouteVersion;
use RouteMaps\Core\Domain\Versions\RouteVersionRepositoryInterface;

final class RouteDraftService {
    public function __construct(private RouteVersionRepositoryInterface $versions) {
    }

    /** @throws JsonException */
    public function save(int $routeId, RouteDraftData $draft, int $userId): RouteVersion {
        $snapshotJson = json_encode(
            $draft->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $existing = $this->versions->findDraftForRoute($routeId);

        if (null !== $existing) {
            return $this->versions->updateDraft($existing->id(), $snapshotJson, $userId);
        }

        return $this->versions->createDraft(
            $routeId,
            $this->versions->nextVersionNumber($routeId),
            $snapshotJson,
            $userId
        );
    }
}
