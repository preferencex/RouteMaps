<?php

declare(strict_types=1);

namespace RouteMaps\Core\Tests\Integration\Activation;

use RouteMaps\Core\Infrastructure\Database\MigrationInterface;
use RouteMaps\Core\Infrastructure\Database\MigrationManager;
use RouteMaps\Core\Infrastructure\Database\Migrations\Migration001RoutesVersions;
use WP_UnitTestCase;

final class Migration001Test extends WP_UnitTestCase {
    public function test_migration_creates_route_tables_and_required_unique_indexes(): void {
        global $wpdb;

        delete_option('routemaps_db_version');

        $manager = new MigrationManager($wpdb, [new Migration001RoutesVersions()]);
        $manager->migrate();

        $routesTable   = $wpdb->prefix . 'routemaps_routes';
        $versionsTable = $wpdb->prefix . 'routemaps_route_versions';

        self::assertTrue($this->tableExists($routesTable));
        self::assertTrue($this->tableExists($versionsTable));

        $routeIndexes = $wpdb->get_results("SHOW INDEX FROM {$routesTable}", ARRAY_A);
        self::assertContains('uuid', array_column($routeIndexes, 'Column_name'));
        self::assertContains('slug', array_column($routeIndexes, 'Column_name'));

        $versionIndexes = $wpdb->get_results("SHOW INDEX FROM {$versionsTable}", ARRAY_A);
        $uniqueColumns  = [];
        foreach ($versionIndexes as $index) {
            if (0 === (int) $index['Non_unique']) {
                $uniqueColumns[$index['Key_name']][] = $index['Column_name'];
            }
        }

        self::assertContains(['route_id', 'version_number'], array_values($uniqueColumns), true);
        self::assertSame(1, (int) get_option('routemaps_db_version'));
    }

    public function test_migration_is_idempotent(): void {
        global $wpdb;

        delete_option('routemaps_db_version');
        $manager = new MigrationManager($wpdb, [new Migration001RoutesVersions()]);

        $manager->migrate();
        $manager->migrate();

        self::assertSame(1, (int) get_option('routemaps_db_version'));
    }
    public function test_failed_migration_does_not_advance_database_version(): void {
        global $wpdb;

        delete_option('routemaps_db_version');
        $migration = new class implements MigrationInterface {
            public function version(): int {
                return 1;
            }

            public function up(\wpdb $db): void {
                throw new \RuntimeException('migration_failed');
            }
        };

        try {
            (new MigrationManager($wpdb, [$migration]))->migrate();
            self::fail('Expected migration failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('migration_failed', $exception->getMessage());
        }

        self::assertSame(0, (int) get_option('routemaps_db_version', 0));
    }

    private function tableExists(string $table): bool {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
        $wpdb->suppress_errors($previous);

        return is_array($columns) && [] !== $columns;
    }

}