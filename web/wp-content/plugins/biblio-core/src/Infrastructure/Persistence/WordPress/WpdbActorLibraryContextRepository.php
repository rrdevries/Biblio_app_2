<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Library\ActorLibraryContext;
use Biblio\Core\Application\Library\ActorLibraryContextRepository;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\AdditionalPermissions;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\LibraryName;
use Biblio\Core\Library\LibraryStatus;
use Biblio\Core\Library\LibraryType;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Throwable;
use wpdb;

final readonly class WpdbActorLibraryContextRepository implements
    ActorLibraryContextRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function findForActor(
        LibraryId $libraryId,
        UserId $actorId
    ): ?ActorLibraryContext {
        $row = $this->database->get_row($this->database->prepare(
            $this->selectSql()
            . " WHERE m.library_id = %s AND m.user_id = %s",
            $libraryId->value(),
            $actorId->value()
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function listForActor(UserId $actorId): array
    {
        $rows = $this->database->get_results($this->database->prepare(
            $this->selectSql()
            . " WHERE m.user_id = %s "
            . "ORDER BY l.library_name, l.library_id",
            $actorId->value()
        ));

        return array_map(
            fn (object $row): ActorLibraryContext => $this->hydrate($row),
            $rows
        );
    }

    private function selectSql(): string
    {
        $libraries = $this->tableNames->libraries();
        $memberships = $this->tableNames->memberships();
        $designations = $this->tableNames->personalLibraryDesignations();

        return "SELECT l.library_id, l.library_name, l.library_type, "
            . "l.library_status, m.user_id, m.membership_status, "
            . "m.management_role, m.use_access, m.additional_permissions, "
            . "CASE WHEN p.user_id IS NULL THEN 0 ELSE 1 END "
            . "AS designated_personal "
            . "FROM `{$memberships}` m "
            . "INNER JOIN `{$libraries}` l ON l.library_id = m.library_id "
            . "LEFT JOIN `{$designations}` p "
            . "ON p.user_id = m.user_id AND p.library_id = m.library_id";
    }

    private function hydrate(object $row): ActorLibraryContext
    {
        try {
            $permissionValues = json_decode(
                (string) $row->additional_permissions,
                false,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($permissionValues) || !array_is_list($permissionValues)) {
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

            $libraryId = new LibraryId((string) $row->library_id);
            $userId = new UserId((string) $row->user_id);

            return new ActorLibraryContext(
                new Library(
                    $libraryId,
                    new LibraryName((string) $row->library_name),
                    LibraryType::from((string) $row->library_type),
                    LibraryStatus::from((string) $row->library_status)
                ),
                new LibraryMembershipAssignment(
                    $libraryId,
                    $userId,
                    new LibraryMembership(
                        ManagementRole::from((string) $row->management_role),
                        UseAccess::from((string) $row->use_access),
                        MembershipStatus::from((string) $row->membership_status),
                        AdditionalPermissions::fromValues(...$permissionValues)
                    )
                ),
                (int) $row->designated_personal === 1
            );
        } catch (Throwable $exception) {
            if ($exception instanceof PersistenceException) {
                throw $exception;
            }

            throw new PersistenceException(
                "Stored actor Library context is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }
}
