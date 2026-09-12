<?php

declare(strict_types=1);

namespace RouteMaps\Core\Maps;

use InvalidArgumentException;

final class MapSourceRegistry {
    /** @var array<string,MapSourceProviderInterface> */
    private array $providers = [];

    /** @param iterable<MapSourceProviderInterface> $providers */
    public function __construct(iterable $providers = []) {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(MapSourceProviderInterface $provider): void {
        $id = sanitize_key($provider->get_id());
        if ('' === $id) {
            throw new InvalidArgumentException('map_source_id_invalid');
        }
        if (isset($this->providers[$id])) {
            throw new InvalidArgumentException('map_source_id_duplicate');
        }
        $this->providers[$id] = $provider;
    }

    public function get(string $id): ?MapSourceProviderInterface {
        return $this->providers[sanitize_key($id)] ?? null;
    }

    /** @return list<MapSourceProviderInterface> */
    public function orderedCandidates(string $primaryId, string $fallbackId): array {
        $ids = array_values(array_unique(array_filter([
            sanitize_key($primaryId),
            sanitize_key($fallbackId),
        ])));

        $result = [];
        foreach ($ids as $id) {
            $provider = $this->providers[$id] ?? null;
            if (null !== $provider && $provider->is_configured()) {
                $result[] = $provider;
            }
        }
        return $result;
    }

    public function resolve(string $primaryId, string $fallbackId): MapSourceSelection {
        $lastHealth = null;
        foreach ($this->orderedCandidates($primaryId, $fallbackId) as $provider) {
            $health = $provider->health_check();
            $lastHealth = $health;
            if ($health->ok()) {
                return new MapSourceSelection($provider, $health);
            }
        }

        return new MapSourceSelection(
            null,
            new MapSourceHealth(
                false,
                'map_source_unavailable',
                null !== $lastHealth ? $lastHealth->message() : __('Nenhuma fonte de mapa está configurada.', 'routemaps')
            )
        );
    }

    /** @return list<string> */
    public function ids(): array {
        return array_keys($this->providers);
    }
}
