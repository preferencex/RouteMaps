<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database;

use wpdb;

interface MigrationInterface {
    public function version(): int;

    public function up(wpdb $db): void;
}
