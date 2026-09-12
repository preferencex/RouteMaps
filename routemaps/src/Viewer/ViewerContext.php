<?php

declare(strict_types=1);

namespace RouteMaps\Core\Viewer;

final class ViewerContext {
    public function __construct(
        private int $userId,
        private int $licenseId,
        private int $routeId,
        private int $versionId,
        private string $sessionUuid
    ) {
    }

    public function userId(): int { return $this->userId; }
    public function licenseId(): int { return $this->licenseId; }
    public function routeId(): int { return $this->routeId; }
    public function versionId(): int { return $this->versionId; }
    public function sessionUuid(): string { return $this->sessionUuid; }
}
