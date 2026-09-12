<?php

declare(strict_types=1);

namespace RouteMaps\Core\Infrastructure\Database;

use Throwable;
use wpdb;

final class TransactionManager {
    public function __construct(private wpdb $db) {
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed {
        $this->db->query('START TRANSACTION');

        try {
            $result = $callback();
            $this->db->query('COMMIT');

            return $result;
        } catch (Throwable $throwable) {
            $this->db->query('ROLLBACK');
            throw $throwable;
        }
    }
}
