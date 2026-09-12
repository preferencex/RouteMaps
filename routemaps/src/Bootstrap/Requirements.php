<?php

declare(strict_types=1);

namespace RouteMaps\Core\Bootstrap;

final class Requirements {
    public function __construct(
        private string $phpVersion,
        private string $wpVersion,
        private string $wcVersion,
        private bool $https
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function check(): array {
        $errors = [];

        if (version_compare($this->phpVersion, '8.2.0', '<')) {
            $errors['php'] = 'PHP 8.2+';
        }

        if (version_compare($this->wpVersion, '6.8.0', '<')) {
            $errors['wordpress'] = 'WordPress 6.8+';
        }

        if (version_compare($this->wcVersion, '10.0.0', '<')) {
            $errors['woocommerce'] = 'WooCommerce 10.0+';
        }

        if (!$this->https) {
            $errors['https'] = 'HTTPS';
        }

        return $errors;
    }
}
