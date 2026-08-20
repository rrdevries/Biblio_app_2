<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Library\EnsurePersonalPrivateLibraryService;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\LibraryStatus;
use Biblio\Core\Library\LibraryType;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\PersonalLibraryDesignationConflict;
use Biblio\Core\Library\PersonalLibraryRepository;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Library\WritableLibraryMembershipRepository;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use RuntimeException;

final class PersonalLibraryProvisioningTest extends
    PersistenceIntegrationTestCase
{
    public function testAccountWithoutLibraryIsValid(): void
    {
        $userId = new UserId("user-without-library");

        self::assertNull($this->personalLibraryRepository()->findForUser(
            $userId
        ));
        self::assertSame(0, $this->libraryCount());
        self::assertSame(0, $this->membershipCount());
        self::assertSame(0, $this->designationCount());
    }

    public function testFirstProvisioningCreatesCanonicalState(): void
    {
        $userId = new UserId("user-x");
        $libraryId = $this->ensureService($userId)->ensure();
        $library = $this->libraryRepository()->find($libraryId);
        $membership = $this->membershipRepository()->findFor(
            $libraryId,
            $userId
        );

        self::assertNotNull($library);
        self::assertSame(LibraryType::PrivateLibrary, $library->type());
        self::assertSame(LibraryStatus::Active, $library->status());
        self::assertNotNull($membership);
        self::assertSame(
            ManagementRole::Owner,
            $membership->membership()->managementRole()
        );
        self::assertSame(
            UseAccess::Direct,
            $membership->membership()->useAccess()
        );
        self::assertSame(
            MembershipStatus::Active,
            $membership->membership()->status()
        );
        self::assertTrue($libraryId->equals(
            $this->personalLibraryRepository()->findForUser($userId)
        ));
        self::assertSame(1, $this->libraryCount());
        self::assertSame(1, $this->membershipCount());
        self::assertSame(1, $this->designationCount());
    }

    public function testRepeatedProvisioningReusesExactlyOneLibrary(): void
    {
        $userId = new UserId("user-x");
        $service = $this->ensureService($userId);

        $first = $service->ensure();
        $second = $service->ensure();
        $third = $service->ensure();

        self::assertTrue($first->equals($second));
        self::assertTrue($first->equals($third));
        self::assertSame(1, $this->libraryCount());
        self::assertSame(1, $this->membershipCount());
        self::assertSame(1, $this->designationCount());
    }

    public function testDesignationsAreIsolatedByUser(): void
    {
        $userX = new UserId("user-x");
        $userY = new UserId("user-y");
        $libraryX = $this->ensureService($userX)->ensure();
        $libraryY = $this->ensureService($userY)->ensure();

        self::assertFalse($libraryX->equals($libraryY));
        self::assertTrue($libraryX->equals(
            $this->personalLibraryRepository()->findForUser($userX)
        ));
        self::assertTrue($libraryY->equals(
            $this->personalLibraryRepository()->findForUser($userY)
        ));
        self::assertSame(2, $this->libraryCount());
        self::assertSame(2, $this->membershipCount());
        self::assertSame(2, $this->designationCount());
    }

    public function testOwnershipOfAnotherLibraryIsNotUsedAsDesignation(): void
    {
        $userId = new UserId("user-x");
        $otherLibraryId = new LibraryId("other-owned-library");
        $this->createLibraryService()->create(
            Library::privateLibrary($otherLibraryId),
            $userId
        );

        $personalLibraryId = $this->ensureService($userId)->ensure();

        self::assertFalse($otherLibraryId->equals($personalLibraryId));
        self::assertTrue($personalLibraryId->equals(
            $this->personalLibraryRepository()->findForUser($userId)
        ));
        self::assertSame(2, $this->libraryCount());
        self::assertSame(2, $this->membershipCount());
        self::assertSame(1, $this->designationCount());
    }

    public function testDatabaseRejectsDuplicateUserAndLibraryDesignations(): void
    {
        $userX = new UserId("user-x");
        $userY = new UserId("user-y");
        $personalLibraryId = $this->ensureService($userX)->ensure();
        $otherLibraryId = new LibraryId("other-owned-library");
        $this->createLibraryService()->create(
            Library::privateLibrary($otherLibraryId),
            $userX
        );
        $repository = $this->personalLibraryRepository();

        try {
            $repository->designate($userX, $otherLibraryId);
            self::fail("A second designation for one user was accepted.");
        } catch (PersonalLibraryDesignationConflict $exception) {
            self::assertSame(
                FailureReason::PersonalLibraryAlreadyProvisioned,
                $exception->reason()
            );
            self::assertNotNull($exception->getPrevious());
            self::assertTrue($personalLibraryId->equals(
                $repository->findForUser($userX)
            ));
        }

        $this->membershipRepository()->add(
            new LibraryMembershipAssignment(
                $personalLibraryId,
                $userY,
                LibraryMembership::safeDefault()
            )
        );

        try {
            $repository->designate($userY, $personalLibraryId);
            self::fail("One Library was designated to two users.");
        } catch (PersonalLibraryDesignationConflict $exception) {
            self::assertSame(
                FailureReason::PersonalLibraryDesignationConflict,
                $exception->reason()
            );
            self::assertNotNull($exception->getPrevious());
            self::assertNull($repository->findForUser($userY));
        }

        self::assertSame(1, $this->designationCount());
    }

    public function testDesignationRequiresExistingLibraryAndMembership(): void
    {
        $repository = $this->personalLibraryRepository();
        $userId = new UserId("user-x");
        $libraryId = new LibraryId("library-a");

        try {
            $repository->designate($userId, $libraryId);
            self::fail("Designation without a Library was accepted.");
        } catch (PersistenceException $exception) {
            self::assertSame(
                FailureReason::PersistenceWriteFailed,
                $exception->reason()
            );
            self::assertStringNotContainsString(
                "foreign key constraint fails",
                strtolower($exception->getMessage())
            );
            self::assertNotNull($exception->getPrevious());
            self::assertSame(0, $this->designationCount());
        }

        $this->libraryRepository()->add(Library::privateLibrary($libraryId));

        try {
            $repository->designate($userId, $libraryId);
            self::fail("Designation without membership was accepted.");
        } catch (PersistenceException) {
            self::assertSame(0, $this->designationCount());
        }
    }

    public function testFailureAfterLibraryWriteRollsBackEverything(): void
    {
        $realMembershipRepository = $this->membershipRepository();
        $failingMembershipRepository = new class(
            $realMembershipRepository
        ) implements WritableLibraryMembershipRepository {
            public function __construct(
                private WritableLibraryMembershipRepository $repository
            ) {
            }

            public function add(
                LibraryMembershipAssignment $assignment
            ): void {
                throw new RuntimeException(
                    "Forced failure before membership write."
                );
            }

            public function findFor(
                LibraryId $libraryId,
                UserId $userId
            ): ?LibraryMembershipAssignment {
                return $this->repository->findFor($libraryId, $userId);
            }
        };
        $service = $this->ensureService(
            new UserId("user-x"),
            $failingMembershipRepository
        );

        try {
            $service->ensure();
            self::fail("Forced failure did not occur.");
        } catch (RuntimeException $exception) {
            self::assertSame(
                "Forced failure before membership write.",
                $exception->getMessage()
            );
        }

        $this->assertEmptyProvisioningState();
    }

    public function testFailureAfterMembershipWriteRollsBackEverything(): void
    {
        $realPersonalLibraryRepository = $this->personalLibraryRepository();
        $failingPersonalLibraryRepository = new class(
            $realPersonalLibraryRepository
        ) implements PersonalLibraryRepository {
            public function __construct(
                private PersonalLibraryRepository $repository
            ) {
            }

            public function findForUser(UserId $userId): ?LibraryId
            {
                return $this->repository->findForUser($userId);
            }

            public function designate(
                UserId $userId,
                LibraryId $libraryId
            ): void {
                throw new RuntimeException(
                    "Forced failure before designation write."
                );
            }
        };
        $service = $this->ensureService(
            new UserId("user-x"),
            null,
            $failingPersonalLibraryRepository
        );

        try {
            $service->ensure();
            self::fail("Forced failure did not occur.");
        } catch (RuntimeException $exception) {
            self::assertSame(
                "Forced failure before designation write.",
                $exception->getMessage()
            );
        }

        $this->assertEmptyProvisioningState();
    }

    private function ensureService(
        UserId $userId,
        ?WritableLibraryMembershipRepository $membershipRepository = null,
        ?PersonalLibraryRepository $personalLibraryRepository = null
    ): EnsurePersonalPrivateLibraryService {
        $personalLibraryRepository ??= $this->personalLibraryRepository();

        return new EnsurePersonalPrivateLibraryService(
            new ControllableAuthenticatedUser($userId),
            $personalLibraryRepository,
            $this->createLibraryService($membershipRepository)
        );
    }

    private function createLibraryService(
        ?WritableLibraryMembershipRepository $membershipRepository = null
    ): CreateLibraryService {
        return new CreateLibraryService(
            $this->libraryRepository(),
            $membershipRepository ?? $this->membershipRepository(),
            $this->classificationSeedEvolution(),
            new WpdbTransactionManager($this->database)
        );
    }

    private function libraryRepository(): WpdbLibraryRepository
    {
        return new WpdbLibraryRepository($this->database, $this->tableNames);
    }

    private function membershipRepository(): WpdbLibraryMembershipRepository
    {
        return new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function personalLibraryRepository(): WpdbPersonalLibraryRepository
    {
        return new WpdbPersonalLibraryRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function assertEmptyProvisioningState(): void
    {
        self::assertSame(0, $this->libraryCount());
        self::assertSame(0, $this->membershipCount());
        self::assertSame(0, $this->designationCount());
    }

    private function libraryCount(): int
    {
        return $this->tableCount($this->tableNames->libraries());
    }

    private function membershipCount(): int
    {
        return $this->tableCount($this->tableNames->memberships());
    }

    private function designationCount(): int
    {
        return $this->tableCount(
            $this->tableNames->personalLibraryDesignations()
        );
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }
}
