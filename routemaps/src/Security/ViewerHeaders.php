<?php

declare(strict_types=1);

namespace RouteMaps\Core\Security;

use WP_REST_Response;

final class ViewerHeaders {
    /** @return array<string,string> */
    public function values(): array {
        return [
            'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'SAMEORIGIN',
        ];
    }

    public function send(): void {
        foreach ($this->values() as $name => $value) {
            header($name . ': ' . $value, true);
        }
    }

    public function applyToRestResponse(WP_REST_Response $response): WP_REST_Response {
        foreach ($this->values() as $name => $value) {
            $response->header($name, $value);
        }
        return $response;
    }
}
