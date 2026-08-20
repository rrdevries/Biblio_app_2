<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryCatalogContext;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextAlreadyExists;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextVersion;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Classification\LibrarySubjectId;
use Biblio\Core\Catalog\Classification\WritableLibraryCatalogContextRepository;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Throwable;
use wpdb;

final readonly class WpdbLibraryCatalogContextRepository implements
    WritableLibraryCatalogContextRepository
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

    public function add(LibraryCatalogContext $context): void
    {
        $this->assertTransactionActive();

        if ($context->version()->value() !== 1) {
            throw new ValidationException(
                "A new Library Catalog Context must start at version 1."
            );
        }

        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $this->tableNames->libraryCatalogContexts(),
                [
                    "library_id" => $context->libraryId()->value(),
                    "work_id" => $context->workId()->value(),
                    "book_type_id" => $context
                        ->classification()->bookTypeId()->value(),
                    "context_version" => 1,
                ],
                ["%s", "%s", "%s", "%d"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result !== 1) {
            $error = $this->database->last_error;

            if (
                WpdbErrorTranslator::conflict($error)?->constraintName()
                === "PRIMARY"
            ) {
                throw new LibraryCatalogContextAlreadyExists(
                    WpdbErrorTranslator::diagnostic(
                        "Library Catalog Context insert",
                        $error
                    )
                );
            }

            throw WpdbErrorTranslator::writeFailure(
                "Could not persist Library Catalog Context.",
                $error
            );
        }

        $this->insertGenres($context);
        $this->insertSubjects($context);
    }

    public function replaceIfVersionMatches(
        LibraryCatalogContext $replacement,
        LibraryCatalogContextVersion $expectedVersion
    ): bool {
        $this->assertTransactionActive();

        if (
            $replacement->version()->value()
            !== $expectedVersion->value() + 1
        ) {
            throw new ValidationException(
                "A Library Catalog Context replacement must increment "
                . "the expected version exactly once."
            );
        }

        $table = $this->tableNames->libraryCatalogContexts();
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->query($this->database->prepare(
                "UPDATE `{$table}` SET book_type_id = %s, "
                . "context_version = %d WHERE library_id = %s "
                . "AND work_id = %s AND context_version = %d",
                $replacement->classification()->bookTypeId()->value(),
                $replacement->version()->value(),
                $replacement->libraryId()->value(),
                $replacement->workId()->value(),
                $expectedVersion->value()
            ));
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not update Library Catalog Context.",
                $this->database->last_error
            );
        }

        if ($result === 0) {
            return false;
        }

        $this->replaceGenres($replacement);
        $this->replaceSubjects($replacement);

        return true;
    }

    public function find(
        LibraryId $libraryId,
        WorkId $workId
    ): ?LibraryCatalogContext {
        return $this->findContext($libraryId, $workId, false);
    }

    public function findForUpdate(
        LibraryId $libraryId,
        WorkId $workId
    ): ?LibraryCatalogContext {
        $this->assertTransactionActive();

        return $this->findContext($libraryId, $workId, true);
    }

    private function findContext(
        LibraryId $libraryId,
        WorkId $workId,
        bool $forUpdate
    ): ?LibraryCatalogContext {
        $table = $this->tableNames->libraryCatalogContexts();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT library_id, work_id, book_type_id, context_version "
            . "FROM `{$table}` WHERE library_id = %s AND work_id = %s"
            . ($forUpdate ? " FOR UPDATE" : ""),
            $libraryId->value(),
            $workId->value()
        ));

        if ($row === null) {
            return null;
        }

        try {
            return new LibraryCatalogContext(
                new LibraryId((string) $row->library_id),
                new WorkId((string) $row->work_id),
                new LibraryCatalogSelection(
                    new LibraryBookTypeId((string) $row->book_type_id),
                    array_map(
                        static fn (string $id): LibraryGenreId =>
                            new LibraryGenreId($id),
                        $this->junctionIds(
                            $this->tableNames
                                ->libraryCatalogContextGenres(),
                            "genre_id",
                            $libraryId,
                            $workId
                        )
                    ),
                    array_map(
                        static fn (string $id): LibrarySubjectId =>
                            new LibrarySubjectId($id),
                        $this->junctionIds(
                            $this->tableNames
                                ->libraryCatalogContextSubjects(),
                            "subject_id",
                            $libraryId,
                            $workId
                        )
                    )
                ),
                new LibraryCatalogContextVersion(
                    (int) $row->context_version
                )
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Library Catalog Context data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function insertGenres(LibraryCatalogContext $context): void
    {
        foreach ($context->classification()->genreIds() as $id) {
            $this->insertJunction(
                $this->tableNames->libraryCatalogContextGenres(),
                "genre_id",
                $context,
                $id->value()
            );
        }
    }

    private function insertSubjects(LibraryCatalogContext $context): void
    {
        foreach ($context->classification()->subjectIds() as $id) {
            $this->insertJunction(
                $this->tableNames->libraryCatalogContextSubjects(),
                "subject_id",
                $context,
                $id->value()
            );
        }
    }

    private function insertJunction(
        string $table,
        string $idColumn,
        LibraryCatalogContext $context,
        string $termId
    ): void {
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $table,
                [
                    "library_id" => $context->libraryId()->value(),
                    "work_id" => $context->workId()->value(),
                    $idColumn => $termId,
                ],
                ["%s", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist Library Catalog Context term set.",
                $this->database->last_error
            );
        }
    }

    private function replaceGenres(LibraryCatalogContext $context): void
    {
        $this->replaceJunctionSet(
            $this->tableNames->libraryCatalogContextGenres(),
            "genre_id",
            $context,
            array_map(
                static fn (LibraryGenreId $id): string => $id->value(),
                $context->classification()->genreIds()
            )
        );
    }

    private function replaceSubjects(LibraryCatalogContext $context): void
    {
        $this->replaceJunctionSet(
            $this->tableNames->libraryCatalogContextSubjects(),
            "subject_id",
            $context,
            array_map(
                static fn (LibrarySubjectId $id): string => $id->value(),
                $context->classification()->subjectIds()
            )
        );
    }

    /** @param list<string> $desiredIds */
    private function replaceJunctionSet(
        string $table,
        string $idColumn,
        LibraryCatalogContext $context,
        array $desiredIds
    ): void {
        $storedIds = $this->junctionIds(
            $table,
            $idColumn,
            $context->libraryId(),
            $context->workId()
        );

        foreach (array_diff($storedIds, $desiredIds) as $obsoleteId) {
            $this->deleteJunction(
                $table,
                $idColumn,
                $context,
                $obsoleteId
            );
        }

        foreach (array_diff($desiredIds, $storedIds) as $newId) {
            $this->insertJunction($table, $idColumn, $context, $newId);
        }
    }

    private function deleteJunction(
        string $table,
        string $idColumn,
        LibraryCatalogContext $context,
        string $termId
    ): void {
        $result = $this->database->delete(
            $table,
            [
                "library_id" => $context->libraryId()->value(),
                "work_id" => $context->workId()->value(),
                $idColumn => $termId,
            ],
            ["%s", "%s", "%s"]
        );

        if ($result !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not update Library Catalog Context term set.",
                $this->database->last_error
            );
        }
    }

    /** @return list<string> */
    private function junctionIds(
        string $table,
        string $idColumn,
        LibraryId $libraryId,
        WorkId $workId
    ): array {
        $values = $this->database->get_col($this->database->prepare(
            "SELECT `{$idColumn}` FROM `{$table}` "
            . "WHERE library_id = %s AND work_id = %s "
            . "ORDER BY `{$idColumn}`",
            $libraryId->value(),
            $workId->value()
        ));

        return array_map(
            static fn (mixed $value): string => (string) $value,
            $values
        );
    }

    private function assertTransactionActive(): void
    {
        if ($this->transactionConnection->isTransactionActive() !== true) {
            throw new PersistenceException(
                "Library Catalog Context writes and locking reads require "
                . "an active transaction.",
                0,
                null,
                FailureReason::PersistenceWriteFailed
            );
        }
    }
}
