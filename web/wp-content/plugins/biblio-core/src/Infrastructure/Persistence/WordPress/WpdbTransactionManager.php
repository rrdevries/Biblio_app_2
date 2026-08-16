<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Throwable;
use wpdb;

final readonly class WpdbTransactionManager implements TransactionManager
{
    public function __construct(private wpdb $database)
    {
    }

    public function run(callable $operation): mixed
    {
        $this->execute("START TRANSACTION");

        try {
            $result = $operation();
            $this->execute("COMMIT");

            return $result;
        } catch (Throwable $exception) {
            $this->database->query("ROLLBACK");

            throw $exception;
        }
    }

    private function execute(string $sql): void
    {
        if ($this->database->query($sql) === false) {
            throw new PersistenceException(
                "Database transaction failed: "
                . $this->database->last_error
            );
        }
    }
}
