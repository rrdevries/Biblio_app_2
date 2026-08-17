<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\AdditionalPermissions;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Library\WritableLibraryMembershipRepository;
use JsonException;
use Throwable;
use wpdb;

final readonly class WpdbLibraryMembershipRepository implements
    WritableLibraryMembershipRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function add(LibraryMembershipAssignment $assignment): void
    {
        try {
            $permissions = json_encode(
                $assignment->membership()->additionalPermissions()->values(),
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new PersistenceException(
                "Could not serialize additional permissions.",
                0,
                $exception
            );
        }

        $membership = $assignment->membership();
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $this->tableNames->memberships(),
                [
                    "library_id" => $assignment->libraryId()->value(),
                    "user_id" => $assignment->userId()->value(),
                    "membership_status" => $membership->status()->value,
                    "management_role" => $membership->managementRole()->value,
                    "use_access" => $membership->useAccess()->value,
                    "additional_permissions" => $permissions,
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist Library membership.",
                $this->database->last_error
            );
        }
    }

    public function findFor(
        LibraryId $libraryId,
        UserId $userId
    ): ?LibraryMembershipAssignment {
        $table = $this->tableNames->memberships();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT library_id, user_id, membership_status, "
            . "management_role, use_access, additional_permissions "
            . "FROM `{$table}` WHERE library_id = %s AND user_id = %s",
            $libraryId->value(),
            $userId->value()
        ));

        if ($row === null) {
            return null;
        }

        try {
            $permissionValues = json_decode(
                $row->additional_permissions,
                false,
                512,
                JSON_THROW_ON_ERROR
            );

            if (
                !is_array($permissionValues)
                || !array_is_list($permissionValues)
            ) {
                throw new PersistenceException(
                    "Stored additional permissions are not a list.",
                    failureReason: FailureReason::PersistenceReadFailed
                );
            }

            foreach ($permissionValues as $permissionValue) {
                if (!is_string($permissionValue)) {
                    throw new PersistenceException(
                        "Stored additional permission is not a string.",
                        failureReason: FailureReason::PersistenceReadFailed
                    );
                }
            }

            return new LibraryMembershipAssignment(
                new LibraryId($row->library_id),
                new UserId($row->user_id),
                new LibraryMembership(
                    ManagementRole::from($row->management_role),
                    UseAccess::from($row->use_access),
                    MembershipStatus::from($row->membership_status),
                    AdditionalPermissions::fromValues(...$permissionValues)
                )
            );
        } catch (Throwable $exception) {
            if ($exception instanceof PersistenceException) {
                throw $exception;
            }

            throw new PersistenceException(
                "Stored Library membership data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }
}
