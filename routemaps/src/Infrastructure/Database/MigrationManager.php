<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database;

use wpdb;

final class MigrationManager {
    private const OPTION_KEY = 'routemaps_db_version';

    /** @var list<MigrationInterface> */
    private array $migrations;

    /**
     * @param iterable<MigrationInterface> $migrations
     */
    public function __construct(
        private wpdb $db,
        iterable $migrations
    ) {
        $this->migrations = is_array($migrations) ? array_values($migrations) : iterator_to_array($migrations, false);
        usort(
            $this->migrations,
            static fn (MigrationInterface $left, MigrationInterface $right): int => $left->version() <=> $right->version()
        );
    }

    public function migrate(): void {
        $currentVersion = (int) get_option(self::OPTION_KEY, 0);

        foreach ($this->migrations as $migration) {
            if ($migration->version() <= $currentVersion) {
                continue;
            }

            $migration->up($this->db);
            update_option(self::OPTION_KEY, $migration->version());
            $currentVersion = $migration->version();
        }
    }
}
