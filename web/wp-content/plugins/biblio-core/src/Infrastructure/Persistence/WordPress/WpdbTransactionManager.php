<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\TransactionException;
use Biblio\Core\Infrastructure\Persistence\TransactionConnection;
use Throwable;
use wpdb;

final class WpdbTransactionManager implements TransactionManager
{
    private bool $active = false;
    private readonly TransactionConnection $connection;

    public function __construct(wpdb|TransactionConnection $connection)
    {
        $this->connection = $connection instanceof wpdb
            ? new WpdbTransactionConnection($connection)
            : $connection;
    }

    public function run(callable $operation): mixed
    {
        if ($this->active) {
            throw $this->nestedTransactionFailure();
        }

        try {
            $connectionActive = $this->connection->isTransactionActive();
        } catch (Throwable $exception) {
            throw new TransactionException(
                "Could not inspect database transaction state.",
                FailureReason::TransactionBeginFailed,
                previous: $exception
            );
        }

        if ($connectionActive === null) {
            throw new TransactionException(
                "Could not inspect database transaction state.",
                FailureReason::TransactionBeginFailed,
                previous: $this->diagnostic("transaction state inspection")
            );
        }

        if ($connectionActive) {
            throw $this->nestedTransactionFailure();
        }

        $beginFailure = $this->commandFailure(
            fn (): bool => $this->connection->begin(),
            "transaction begin"
        );

        if ($beginFailure !== null) {
            throw new TransactionException(
                "Could not begin database transaction.",
                FailureReason::TransactionBeginFailed,
                previous: $beginFailure
            );
        }

        $this->active = true;

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $rollbackFailure = $this->rollbackFailure();
            $this->active = false;

            if ($rollbackFailure !== null) {
                throw new TransactionException(
                    "Database rollback failed after operation failure; "
                        . "transaction outcome may be uncertain.",
                    FailureReason::TransactionRollbackFailed,
                    $exception,
                    $rollbackFailure,
                    $exception
                );
            }

            throw $exception;
        }

        $commitDiagnostic = $this->commandFailure(
            fn (): bool => $this->connection->commit(),
            "transaction commit"
        );

        if ($commitDiagnostic !== null) {
            $commitFailure = new TransactionException(
                "Database transaction commit failed; commit was not confirmed.",
                FailureReason::TransactionCommitFailed,
                previous: $commitDiagnostic
            );
            $rollbackFailure = $this->rollbackFailure();
            $this->active = false;

            if ($rollbackFailure !== null) {
                throw new TransactionException(
                    "Database rollback also failed after an unconfirmed commit; "
                        . "transaction outcome is uncertain.",
                    FailureReason::TransactionRollbackFailed,
                    $commitFailure,
                    $rollbackFailure,
                    $commitFailure
                );
            }

            throw $commitFailure;
        }

        $this->active = false;

        return $result;
    }

    private function rollbackFailure(): ?Throwable
    {
        return $this->commandFailure(
            fn (): bool => $this->connection->rollback(),
            "transaction rollback"
        );
    }

    private function commandFailure(
        callable $command,
        string $operation
    ): ?Throwable {
        try {
            return $command() ? null : $this->diagnostic($operation);
        } catch (Throwable $exception) {
            return $exception;
        }
    }

    private function diagnostic(string $operation): Throwable
    {
        return WpdbErrorTranslator::diagnostic(
            $operation,
            $this->connection->lastError()
        );
    }

    private function nestedTransactionFailure(): TransactionException
    {
        return new TransactionException(
            "Nested transactions are not supported.",
            FailureReason::NestedTransactionNotSupported
        );
    }
}
