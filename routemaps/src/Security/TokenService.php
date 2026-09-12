<?php

declare(strict_types=1);

namespace RouteMaps\Core\Security;

final class TokenService {
    /** @return array{plain:string,hash:string} */
    public function generate(): array {
        $plain = bin2hex(random_bytes(32));
        return ['plain' => $plain, 'hash' => hash('sha256', $plain)];
    }

    public function hash(string $plain): string {
        return hash('sha256', strtolower(trim($plain)));
    }

    public function isValidPlainToken(string $plain): bool {
        return 1 === preg_match('/^[0-9a-f]{64}$/', strtolower(trim($plain)));
    }
}
