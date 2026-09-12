<?php

declare(strict_types=1);

namespace RouteMaps\Core\Viewer;

use InvalidArgumentException;

final class ExtensionRegistry {
    /** @var array<string,array{decorator:ViewerPayloadDecorator,priority:int}> */
    private array $decorators = [];
    private bool $booted = false;

    public function registerViewerDecorator(string $id, ViewerPayloadDecorator $decorator, int $priority = 10): void {
        $id = sanitize_key($id);
        if ('' === $id) {
            throw new InvalidArgumentException('viewer_decorator_id_invalid');
        }
        if (isset($this->decorators[$id])) {
            throw new InvalidArgumentException('viewer_decorator_id_duplicate');
        }
        $this->decorators[$id] = ['decorator' => $decorator, 'priority' => $priority];
    }

    public function bootExtensions(): void {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        do_action('routemaps_extension_registry', $this);
    }

    public function decorate(ViewerPayload $payload, ViewerContext $context): ViewerPayload {
        $decorators = $this->decorators;
        uasort($decorators, static function (array $left, array $right): int {
            return $left['priority'] <=> $right['priority'];
        });
        foreach ($decorators as $entry) {
            $payload = $entry['decorator']->decorate($payload, $context);
        }
        return $payload;
    }
}
