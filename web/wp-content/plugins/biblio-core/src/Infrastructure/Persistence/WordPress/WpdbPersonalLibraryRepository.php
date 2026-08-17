<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\PersonalLibraryDesignationConflict;
use Biblio\Core\Library\PersonalLibraryRepository;
use Throwable;
use wpdb;

final readonly class WpdbPersonalLibraryRepository implements
    PersonalLibraryRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function findForUser(UserId $userId): ?LibraryId
    {
        $table = $this->tableNames->personalLibraryDesignations();
        $libraryId = $this->database->get_var($this->database->prepare(
            "SELECT library_id FROM `{$table}` WHERE user_id = %s",
            $userId->value()
        ));

        if ($libraryId === null) {
            return null;
        }

        try {
            return new LibraryId((string) $libraryId);
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored personal Library designation is invalid.",
                0,
                $exception
            );
        }
    }

    public function designate(UserId $userId, LibraryId $libraryId): void
    {
        $table = $this->tableNames->personalLibraryDesignations();
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->query($this->database->prepare(
                "INSERT INTO `{$table}` (user_id, library_id) "
                . "VALUES (%s, %s) "
                . "ON DUPLICATE KEY UPDATE library_id = library_id",
                $userId->value(),
                $libraryId->value()
            ));
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result === 1) {
            return;
        }

        if ($result === 0) {
            throw new PersonalLibraryDesignationConflict(
                "A personal Library designation already exists."
            );
        }

        throw new PersistenceException(
            "Could not persist personal Library designation: "
            . $this->database->last_error
        );
    }
}
