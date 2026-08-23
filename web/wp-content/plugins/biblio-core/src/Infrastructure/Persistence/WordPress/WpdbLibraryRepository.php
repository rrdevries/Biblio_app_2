<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryName;
use Biblio\Core\Library\WritableLibraryRepository;
use Biblio\Core\Library\LibraryStatus;
use Biblio\Core\Library\LibraryType;
use Throwable;
use wpdb;

final readonly class WpdbLibraryRepository implements WritableLibraryRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames,
        private bool $identityAvailable = true
    ) {
    }

    public function add(Library $library): void
    {
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $this->tableNames->libraries(),
                [
                    "library_id" => $library->id()->value(),
                    "library_name" => $library->name()->value(),
                    "library_type" => $library->type()->value,
                    "library_status" => $library->status()->value,
                ],
                ["%s", "%s", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist Library.",
                $this->database->last_error
            );
        }
    }

    public function find(LibraryId $libraryId): ?Library
    {
        $table = $this->tableNames->libraries();
        $columns = $this->selectColumns();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT {$columns} FROM `{$table}` WHERE library_id = %s",
            $libraryId->value()
        ));

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function all(): array
    {
        $table = $this->tableNames->libraries();
        $columns = $this->selectColumns();
        $rows = $this->database->get_results(
            "SELECT {$columns} FROM `{$table}` ORDER BY library_id"
        );

        return array_map(
            fn (object $row): Library => $this->hydrate($row),
            $rows
        );
    }

    private function hydrate(object $row): Library
    {
        try {
            return new Library(
                new LibraryId((string) $row->library_id),
                isset($row->library_name)
                    ? new LibraryName((string) $row->library_name)
                    : LibraryName::personalDefault(),
                LibraryType::from((string) $row->library_type),
                LibraryStatus::from((string) $row->library_status)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Library data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function selectColumns(): string
    {
        return $this->identityAvailable
            ? "library_id, library_name, library_type, library_status"
            : "library_id, library_type, library_status";
    }
}
