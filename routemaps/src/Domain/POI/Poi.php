<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\POI;

final class Poi {
    /**
     * @param list<int> $gallery
     * @param array<string,mixed> $cta
     */
    public function __construct(
        private int $id,
        private string $uuid,
        private string $name,
        private int $categoryId,
        private float $latitude,
        private float $longitude,
        private string $description,
        private string $address,
        private string $phone,
        private string $website,
        private string $openingHours,
        private string $routeNote,
        private ?int $mainAttachmentId,
        private array $gallery,
        private string $icon,
        private string $color,
        private array $cta,
        private string $status,
        private int $createdBy,
        private int $updatedBy,
        private string $createdAt,
        private string $updatedAt
    ) {
    }

    public function id(): int { return $this->id; }
    public function uuid(): string { return $this->uuid; }
    public function name(): string { return $this->name; }
    public function categoryId(): int { return $this->categoryId; }
    public function latitude(): float { return $this->latitude; }
    public function longitude(): float { return $this->longitude; }
    public function description(): string { return $this->description; }
    public function address(): string { return $this->address; }
    public function phone(): string { return $this->phone; }
    public function website(): string { return $this->website; }
    public function openingHours(): string { return $this->openingHours; }
    public function routeNote(): string { return $this->routeNote; }
    public function mainAttachmentId(): ?int { return $this->mainAttachmentId; }

    /** @return list<int> */
    public function gallery(): array { return $this->gallery; }

    public function icon(): string { return $this->icon; }
    public function color(): string { return $this->color; }

    /** @return array<string,mixed> */
    public function cta(): array { return $this->cta; }

    public function status(): string { return $this->status; }
    public function createdBy(): int { return $this->createdBy; }
    public function updatedBy(): int { return $this->updatedBy; }
    public function createdAt(): string { return $this->createdAt; }
    public function updatedAt(): string { return $this->updatedAt; }
}
