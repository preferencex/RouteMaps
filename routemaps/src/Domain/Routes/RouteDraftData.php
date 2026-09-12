<?php

declare(strict_types=1);

namespace RouteMaps\Core\Domain\Routes;

use InvalidArgumentException;

final class RouteDraftData {
    /**
     * @param array<string,mixed> $geometry
     * @param list<array<string,mixed>> $stops
     * @param array<string,mixed> $style
     * @param array<string,mixed> $viewport
     * @param array<string,mixed> $supportOverrides
     * @param list<array<string,mixed>> $pois
     * @param list<array<string,mixed>> $categories
     * @param array<string,mixed> $display
     */
    public function __construct(
        private string $title,
        private array $geometry,
        private array $stops,
        private array $style,
        private array $viewport,
        private array $supportOverrides = [],
        private ?string $mapSourceOverride = null,
        private array $pois = [],
        private array $categories = [],
        private array $display = []
    ) {
        $this->validate();
    }

    public function title(): string { return $this->title; }

    /** @return array<string,mixed> */
    public function geometry(): array { return $this->geometry; }

    /** @return list<array<string,mixed>> */
    public function stops(): array { return $this->stops; }

    /** @return array<string,mixed> */
    public function style(): array { return $this->style; }

    /** @return array<string,mixed> */
    public function viewport(): array { return $this->viewport; }

    /** @return array<string,mixed> */
    public function supportOverrides(): array { return $this->supportOverrides; }

    public function mapSourceOverride(): ?string { return $this->mapSourceOverride; }

    /** @return list<array<string,mixed>> */
    public function pois(): array { return $this->pois; }

    /** @return list<array<string,mixed>> */
    public function categories(): array { return $this->categories; }

    /** @return array<string,mixed> */
    public function display(): array { return $this->display; }

    /** @return array<string,mixed> */
    public function toArray(): array {
        return [
            'title' => $this->title,
            'geometry' => $this->geometry,
            'stops' => $this->stops,
            'style' => $this->style,
            'viewport' => $this->viewport,
            'support_overrides' => $this->supportOverrides,
            'map_source_override' => $this->mapSourceOverride,
            'pois' => $this->pois,
            'categories' => $this->categories,
            'display' => $this->display,
        ];
    }

    private function validate(): void {
        if ('' === trim($this->title)) {
            throw new InvalidArgumentException('route_title_required');
        }

        $type = $this->geometry['type'] ?? null;
        if (!is_string($type) || !in_array($type, ['LineString', 'MultiLineString'], true)) {
            throw new InvalidArgumentException('invalid_route_geometry');
        }

        if (!isset($this->geometry['coordinates']) || !is_array($this->geometry['coordinates']) || [] === $this->geometry['coordinates']) {
            throw new InvalidArgumentException('invalid_route_geometry');
        }

        foreach ($this->stops as $stop) {
            $uuid = $stop['entity_uuid'] ?? null;
            $coordinates = $stop['coordinates'] ?? null;

            if (!is_string($uuid) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
                throw new InvalidArgumentException('invalid_stop_entity_uuid');
            }

            if (!is_array($coordinates) || 2 !== count($coordinates) || !is_numeric($coordinates[0]) || !is_numeric($coordinates[1])) {
                throw new InvalidArgumentException('invalid_stop_coordinates');
            }
        }
    }
}
