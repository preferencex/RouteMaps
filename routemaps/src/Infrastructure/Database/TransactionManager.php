<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database;

use Throwable;
use wpdb;

final class TransactionManager {
    private static int $savepointSequence = 0;

    public function __construct(private wpdb $db) {
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed {
        // WordPress' test suite (and callers that disable autocommit) already owns
        // the surrounding transaction. MySQL does not expose MariaDB's
        // @@in_transaction variable, so use the portable autocommit state here.
        $nested = 0 === (int) $this->db->get_var('SELECT @@autocommit');
        $savepoint = null;

        if ($nested) {
            $savepoint = 'routemaps_' . (++self::$savepointSequence);
            $this->db->query("SAVEPOINT {$savepoint}");
        } else {
            $this->db->query('START TRANSACTION');
        }

        try {
            $result = $callback();
            if (null !== $savepoint) {
                $this->db->query("RELEASE SAVEPOINT {$savepoint}");
            } else {
                $this->db->query('COMMIT');
            }

            return $result;
        } catch (Throwable $throwable) {
            if (null !== $savepoint) {
                $this->db->query("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->db->query("RELEASE SAVEPOINT {$savepoint}");
            } else {
                $this->db->query('ROLLBACK');
            }
            throw $throwable;
        }
    }
}