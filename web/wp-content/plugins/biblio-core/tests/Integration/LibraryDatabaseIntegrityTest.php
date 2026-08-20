<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\LibraryMembershipRepository;
use Biblio\Core\Library\WritableLibraryMembershipRepository;
use RuntimeException;

final class FailingLibraryMembershipRepository implements
    WritableLibraryMembershipRepository
{
    public function __construct(
        private LibraryMembershipRepository $repository
    ) {
    }

    public function add(LibraryMembershipAssignment $assignment): void
    {
        throw new RuntimeException("Forced membership persistence failure.");
    }

    public function findFor(
        LibraryId $libraryId,
        UserId $userId
    ): ?LibraryMembershipAssignment {
        return $this->repository->findFor($libraryId, $userId);
    }
}

final class LibraryDatabaseIntegrityTest extends PersistenceIntegrationTestCase
{
    public function testDuplicateLibraryAndUserMembershipIsRejected(): void
    {
        $libraryId = new LibraryId("library-a");
        $userId = new UserId("user-x");
        $this->libraryRepository()->add(Library::privateLibrary($libraryId));
        $repository = $this->membershipRepository();
        $assignment = new LibraryMembershipAssignment(
            $libraryId,
            $userId,
            LibraryMembership::safeDefault()
        );
        $repository->add($assignment);

        try {
            $repository->add($assignment);
            self::fail("Duplicate membership was accepted.");
        } catch (PersistenceException) {
            self::assertSame(1, $this->membershipCount());
        }
    }

    public function testMembershipForMissingLibraryIsRejected(): void
    {
        $assignment = new LibraryMembershipAssignment(
            new LibraryId("missing-library"),
            new UserId("user-x"),
            LibraryMembership::safeDefault()
        );

        try {
            $this->membershipRepository()->add($assignment);
            self::fail("Membership without Library was accepted.");
        } catch (PersistenceException) {
            self::assertSame(0, $this->membershipCount());
        }
    }

    public function testSecondActiveOwnerForLibraryIsRejected(): void
    {
        $libraryId = new LibraryId("library-a");
        $this->libraryRepository()->add(Library::privateLibrary($libraryId));
        $repository = $this->membershipRepository();
        $repository->add(new LibraryMembershipAssignment(
            $libraryId,
            new UserId("owner-a"),
            LibraryMembership::owner()
        ));

        try {
            $repository->add(new LibraryMembershipAssignment(
                $libraryId,
                new UserId("owner-b"),
                LibraryMembership::owner()
            ));
            self::fail("Second active Owner was accepted.");
        } catch (PersistenceException) {
            self::assertSame(1, $this->activeOwnerCount($libraryId));
        }
    }

    public function testFailedLibraryAndOwnerWriteRollsBackCompletely(): void
    {
        $libraryRepository = $this->libraryRepository();
        $realMembershipRepository = $this->membershipRepository();
        $service = new CreateLibraryService(
            $libraryRepository,
            new FailingLibraryMembershipRepository(
                $realMembershipRepository
            ),
            $this->classificationSeedEvolution(),
            new WpdbTransactionManager($this->database)
        );
        $libraryId = new LibraryId("library-a");

        try {
            $service->create(
                Library::privateLibrary($libraryId),
                new UserId("owner-a")
            );
            self::fail("Forced transaction failure did not occur.");
        } catch (RuntimeException $exception) {
            self::assertSame(
                "Forced membership persistence failure.",
                $exception->getMessage()
            );
            self::assertNull($libraryRepository->find($libraryId));
            self::assertSame(0, $this->membershipCount());
        }
    }

    private function libraryRepository(): WpdbLibraryRepository
    {
        return new WpdbLibraryRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function membershipRepository(): WpdbLibraryMembershipRepository
    {
        return new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function membershipCount(): int
    {
        $table = $this->tableNames->memberships();

        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }

    private function activeOwnerCount(LibraryId $libraryId): int
    {
        $table = $this->tableNames->memberships();

        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$table}` "
            . "WHERE library_id = %s AND management_role = 'owner' "
            . "AND membership_status = 'active'",
            $libraryId->value()
        ));
    }
}
