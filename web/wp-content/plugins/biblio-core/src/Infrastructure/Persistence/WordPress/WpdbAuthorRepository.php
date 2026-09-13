<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\{Author,AuthorDisplayNameStatus,AuthorId,AuthorIdentityStatus,AuthorVersion,CatalogRecordAlreadyExists,ContributorPosition,ContributorRole,WorkContributor,WorkId,WritableAuthorRepository};
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Throwable;
use wpdb;

final readonly class WpdbAuthorRepository implements WritableAuthorRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function add(Author $author): void
    {
        if ($author->version()->value() !== AuthorVersion::initial()->value()) {
            throw new \InvalidArgumentException(
                "New Author must start at the initial version."
            );
        }
        $table = $this->tables->authors();
        $previous = $this->database->suppress_errors(true);
        try {
            $result = $this->database->insert($table, [
                "author_id" => $author->id()->value(),
                "display_name" => $author->displayName(),
                "identity_status" => $author->identityStatus()->value,
                "display_name_status" => $author->displayNameStatus()->value,
                "author_version" => $author->version()->value(),
            ], ["%s", "%s", "%s", "%s", "%d"]);
        } finally {
            $this->database->suppress_errors($previous);
        }
        if ($result === 1) {
            return;
        }
        if (WpdbErrorTranslator::conflict($this->database->last_error) !== null) {
            throw new CatalogRecordAlreadyExists(WpdbErrorTranslator::diagnostic(
                "Author insert",
                $this->database->last_error
            ));
        }
        throw WpdbErrorTranslator::writeFailure(
            "Could not persist Author.",
            $this->database->last_error
        );
    }

    public function replaceIfVersionMatches(
        Author $replacement,
        AuthorVersion $expectedVersion
    ): bool {
        if (
            $replacement->version()->value()
            !== $expectedVersion->value() + 1
        ) {
            throw new \InvalidArgumentException(
                "Replacement Author version must follow the expected version."
            );
        }

        $table = $this->tables->authors();
        $result = $this->database->query($this->database->prepare(
            "UPDATE `{$table}` SET display_name=%s,identity_status=%s,"
                . "display_name_status=%s,author_version=%d "
                . "WHERE author_id=%s AND author_version=%d "
                . "AND (identity_status<>'resolved' OR %s='resolved') "
                . "AND (display_name_status<>'librarian_confirmed' "
                . "OR %s='librarian_confirmed')",
            $replacement->displayName(),
            $replacement->identityStatus()->value,
            $replacement->displayNameStatus()->value,
            $replacement->version()->value(),
            $replacement->id()->value(),
            $expectedVersion->value(),
            $replacement->identityStatus()->value,
            $replacement->displayNameStatus()->value
        ));
        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not replace Author.",
                $this->database->last_error
            );
        }
        return $result === 1;
    }

    public function addContributor(WorkContributor $contributor): void
    {
        $previous = $this->database->suppress_errors(true);
        try {
            $result = $this->database->insert($this->tables->workContributors(), [
                "work_id" => $contributor->workId()->value(),
                "author_id" => $contributor->authorId()->value(),
                "contributor_role" => $contributor->role()->value,
                "contributor_position" => $contributor->position()->value(),
            ], ["%s", "%s", "%s", "%d"]);
        } finally {
            $this->database->suppress_errors($previous);
        }
        if ($result === 1) {
            return;
        }
        if (WpdbErrorTranslator::conflict($this->database->last_error) !== null) {
            throw new CatalogRecordAlreadyExists(WpdbErrorTranslator::diagnostic(
                "Work contributor insert",
                $this->database->last_error
            ));
        }
        throw WpdbErrorTranslator::writeFailure(
            "Could not persist Work contributor.",
            $this->database->last_error
        );
    }

    public function find(AuthorId $authorId): ?Author
    {
        $table = $this->tables->authors();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT author_id,display_name,identity_status,display_name_status,"
                . "author_version FROM `{$table}` WHERE author_id=%s",
            $authorId->value()
        ));
        if ($row === null) {
            return null;
        }
        try {
            return $this->hydrateAuthor($row);
        } catch (Throwable $exception) {
            throw new PersistenceException("Stored Author data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed);
        }
    }

    /**
     * @param list<AuthorId> $authorIds
     * @return array<string, Author>
     */
    public function findMany(array $authorIds): array
    {
        if ($authorIds === []) {
            return [];
        }

        $table = $this->tables->authors();
        $placeholders = implode(",", array_fill(0, count($authorIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT author_id,display_name,identity_status,display_name_status,"
                . "author_version FROM `{$table}` "
                . "WHERE author_id IN ({$placeholders}) ORDER BY author_id",
            ...array_map(
                static fn (AuthorId $id): string => $id->value(),
                $authorIds
            )
        ));

        try {
            $result = [];
            foreach ($rows as $row) {
                $author = $this->hydrateAuthor($row);
                $result[$author->id()->value()] = $author;
            }

            return $result;
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Author data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    /**
     * @param list<WorkId> $workIds
     * @return array<string, list<WorkContributor>>
     */
    public function contributorsForWorks(array $workIds): array
    {
        $result = [];
        foreach ($workIds as $workId) {
            $result[$workId->value()] = [];
        }
        if ($workIds === []) {
            return $result;
        }
        $table = $this->tables->workContributors();
        $placeholders = implode(",", array_fill(0, count($workIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT work_id,author_id,contributor_role,contributor_position "
                . "FROM `{$table}` WHERE work_id IN ({$placeholders}) "
                . "ORDER BY work_id,contributor_position,author_id",
            ...array_map(static fn (WorkId $id): string => $id->value(), $workIds)
        ));
        try {
            foreach ($rows as $row) {
                $result[(string) $row->work_id][] = $this->hydrateContributor($row);
            }
            return $result;
        } catch (Throwable $exception) {
            throw new PersistenceException("Stored Work contributor data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed);
        }
    }

    /**
     * @param list<AuthorId> $authorIds
     * @return array<string, list<WorkId>>
     */
    public function workIdsForAuthors(array $authorIds): array
    {
        $result = [];
        foreach ($authorIds as $authorId) {
            $result[$authorId->value()] = [];
        }
        if ($authorIds === []) {
            return $result;
        }
        $table = $this->tables->workContributors();
        $placeholders = implode(",", array_fill(0, count($authorIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT author_id,work_id FROM `{$table}` WHERE author_id IN ({$placeholders}) "
                . "ORDER BY author_id,work_id",
            ...array_map(static fn (AuthorId $id): string => $id->value(), $authorIds)
        ));
        try {
            foreach ($rows as $row) {
                $result[(string) $row->author_id][] = new WorkId((string) $row->work_id);
            }
            return $result;
        } catch (Throwable $exception) {
            throw new PersistenceException("Stored Author relationship data is invalid.", 0, $exception, FailureReason::PersistenceReadFailed);
        }
    }

    private function hydrateContributor(object $row): WorkContributor
    {
        return new WorkContributor(
            new WorkId((string) $row->work_id),
            new AuthorId((string) $row->author_id),
            ContributorRole::from((string) $row->contributor_role),
            new ContributorPosition((int) $row->contributor_position)
        );
    }

    private function hydrateAuthor(object $row): Author
    {
        return new Author(
            new AuthorId((string) $row->author_id),
            (string) $row->display_name,
            AuthorIdentityStatus::from((string) $row->identity_status),
            AuthorDisplayNameStatus::from((string) $row->display_name_status),
            new AuthorVersion((int) $row->author_version)
        );
    }
}
