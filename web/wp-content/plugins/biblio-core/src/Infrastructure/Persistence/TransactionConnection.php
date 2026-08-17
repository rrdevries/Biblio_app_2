<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence;

interface TransactionConnection
{
    public function isTransactionActive(): ?bool;

    public function begin(): bool;

    public function commit(): bool;

    public function rollback(): bool;

    public function lastError(): string;
}
