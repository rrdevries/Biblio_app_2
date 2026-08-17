<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Exception\FailureReason;
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
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    public function designate(UserId $userId, LibraryId $libraryId): void
    {
        $table = $this->tableNames->personalLibraryDesignations();
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $table,
                [
                    "user_id" => $userId->value(),
                    "library_id" => $libraryId->value(),
                ],
                ["%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result === 1) {
            return;
        }

        $conflict = WpdbErrorTranslator::conflict(
            $this->database->last_error
        );

        if ($conflict !== null && in_array($conflict->constraintName(), [
            "PRIMARY",
            "one_personal_user_per_library",
        ], true)) {
            throw new PersonalLibraryDesignationConflict(
                $conflict->constraintName() === "PRIMARY"
                    ? FailureReason::PersonalLibraryAlreadyProvisioned
                    : FailureReason::PersonalLibraryDesignationConflict,
                WpdbErrorTranslator::diagnostic(
                    "personal Library designation insert",
                    $this->database->last_error
                )
            );
        }

        throw WpdbErrorTranslator::writeFailure(
            "Could not persist personal Library designation.",
            $this->database->last_error
        );
    }
}
