<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Infrastructure\Persistence\TransactionConnection;
use wpdb;

final readonly class WpdbTransactionConnection implements TransactionConnection
{
    public function __construct(private wpdb $database)
    {
    }

    public function isTransactionActive(): ?bool
    {
        $this->database->last_error = "";
        $active = $this->database->get_var("SELECT @@in_transaction");

        if ($active === null && $this->database->last_error !== "") {
            return null;
        }

        return (int) $active === 1;
    }

    public function begin(): bool
    {
        return $this->database->query("START TRANSACTION") !== false;
    }

    public function commit(): bool
    {
        return $this->database->query("COMMIT") !== false;
    }

    public function rollback(): bool
    {
        return $this->database->query("ROLLBACK") !== false;
    }

    public function lastError(): string
    {
        return $this->database->last_error;
    }
}
