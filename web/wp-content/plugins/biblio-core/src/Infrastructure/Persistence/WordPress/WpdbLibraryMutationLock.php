<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMutationLock;
use wpdb;

final readonly class WpdbLibraryMutationLock implements LibraryMutationLock
{
    private WpdbTransactionConnection $transactionConnection;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
        $this->transactionConnection = new WpdbTransactionConnection(
            $database
        );
    }

    public function acquire(LibraryId $libraryId): void
    {
        if ($this->transactionConnection->isTransactionActive() !== true) {
            throw new PersistenceException(
                "Library mutation locking requires an active transaction.",
                0,
                null,
                FailureReason::PersistenceWriteFailed
            );
        }

        $table = $this->tableNames->libraries();
        $storedId = $this->database->get_var($this->database->prepare(
            "SELECT library_id FROM `{$table}` WHERE library_id = %s "
            . "FOR UPDATE",
            $libraryId->value()
        ));

        if ($storedId === null) {
            throw new PersistenceException(
                "Cannot lock a missing Library.",
                0,
                null,
                FailureReason::PersistenceReadFailed
            );
        }
    }
}
