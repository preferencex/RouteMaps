<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Versions;

final class RouteVersion {
    public function __construct(
        private int $id,
        private int $routeId,
        private int $versionNumber,
        private string $state,
        private bool $critical,
        private string $snapshotJson,
        private ?string $changeSummary,
        private string $contentHash,
        private int $createdBy,
        private string $createdAt,
        private ?string $publishedAt
    ) {
    }

    public function id(): int { return $this->id; }
    public function routeId(): int { return $this->routeId; }
    public function versionNumber(): int { return $this->versionNumber; }
    public function state(): string { return $this->state; }
    public function isCritical(): bool { return $this->critical; }
    public function snapshotJson(): string { return $this->snapshotJson; }
    public function changeSummary(): ?string { return $this->changeSummary; }
    public function contentHash(): string { return $this->contentHash; }
    public function createdBy(): int { return $this->createdBy; }
    public function createdAt(): string { return $this->createdAt; }
    public function publishedAt(): ?string { return $this->publishedAt; }
}
