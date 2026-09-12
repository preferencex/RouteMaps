<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Routes;

final class Route {
    public function __construct(
        private int $id,
        private string $uuid,
        private string $title,
        private string $slug,
        private string $status,
        private ?int $coverAttachmentId,
        private ?int $currentPublishedVersionId,
        private ?string $mapSourceId,
        private int $createdBy,
        private string $createdAt,
        private string $updatedAt
    ) {
    }

    public function id(): int { return $this->id; }
    public function uuid(): string { return $this->uuid; }
    public function title(): string { return $this->title; }
    public function slug(): string { return $this->slug; }
    public function status(): string { return $this->status; }
    public function coverAttachmentId(): ?int { return $this->coverAttachmentId; }
    public function currentPublishedVersionId(): ?int { return $this->currentPublishedVersionId; }
    public function mapSourceId(): ?string { return $this->mapSourceId; }
    public function createdBy(): int { return $this->createdBy; }
    public function createdAt(): string { return $this->createdAt; }
    public function updatedAt(): string { return $this->updatedAt; }
}
