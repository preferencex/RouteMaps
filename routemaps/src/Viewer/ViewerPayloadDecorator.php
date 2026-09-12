<?php

declare(strict_types=1);

namespace RouteMaps\Core\Viewer;

interface ViewerPayloadDecorator {
    public function decorate(ViewerPayload $payload, ViewerContext $context): ViewerPayload;
}
