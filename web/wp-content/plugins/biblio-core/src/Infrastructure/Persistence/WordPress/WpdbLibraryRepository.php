<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryRepository;
use Biblio\Core\Library\LibraryStatus;
use Biblio\Core\Library\LibraryType;
use Throwable;
use wpdb;

final readonly class WpdbLibraryRepository implements LibraryRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
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
                    "library_type" => $library->type()->value,
                    "library_status" => $library->status()->value,
                ],
                ["%s", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result !== 1) {
            throw new PersistenceException(
                "Could not persist Library: " . $this->database->last_error
            );
        }
    }

    public function find(LibraryId $libraryId): ?Library
    {
        $table = $this->tableNames->libraries();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT library_id, library_type, library_status "
            . "FROM `{$table}` WHERE library_id = %s",
            $libraryId->value()
        ));

        if ($row === null) {
            return null;
        }

        try {
            return new Library(
                new LibraryId($row->library_id),
                LibraryType::from($row->library_type),
                LibraryStatus::from($row->library_status)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Library data is invalid.",
                0,
                $exception
            );
        }
    }
}
