<?php

declare(strict_types=1);

namespace RouteMaps\Core\Import;

final class ImportPreview {
    /**
     * @param array<string,mixed>       $geometry
     * @param list<array<string,mixed>> $points
     * @param list<array<string,mixed>> $categories
     * @param list<string>              $warnings
     * @param array<string,mixed>       $metadata
     */
    public function __construct(
        private string $format,
        private ?string $title,
        private array $geometry,
        private array $points,
        private array $categories = [],
        private array $warnings = [],
        private array $metadata = []
    ) {
    }

    public function format(): string { return $this->format; }
    public function title(): ?string { return $this->title; }

    /** @return array<string,mixed> */
    public function geometry(): array { return $this->geometry; }

    /** @return list<array<string,mixed>> */
    public function points(): array { return $this->points; }

    /** @return list<array<string,mixed>> */
    public function categories(): array { return $this->categories; }

    /** @return list<string> */
    public function warnings(): array { return $this->warnings; }

    /** @return array<string,mixed> */
    public function metadata(): array { return $this->metadata; }

    /** @return array<string,mixed> */
    public function toArray(): array {
        return [
            'format' => $this->format,
            'title' => $this->title,
            'geometry' => $this->geometry,
            'points' => $this->points,
            'categories' => $this->categories,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata,
        ];
    }
}
