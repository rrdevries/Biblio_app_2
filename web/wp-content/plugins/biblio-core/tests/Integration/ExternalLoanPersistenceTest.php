<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Borrowing\ExternalLoanStatus;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbExternalLoanRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbExternalLoanWriter;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use DateTimeImmutable;
use DateTimeZone;

final class ExternalLoanPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testExternalLoanWithoutDueDateRoundTripsForOwner(): void
    {
        $work = $this->persistWork();
        $loan = ExternalLoan::active(
            new ExternalLoanId("loan-x"),
            new UserId("user-x"),
            $work->id(),
            $this->utc("2026-08-10 09:30:00.123456")
        );
        $repository = $this->externalLoanRepository();

        $this->externalLoanWriter()->add($loan);
        $stored = $repository->findForUser(
            $loan->id(),
            $loan->userId()
        );

        self::assertNotNull($stored);
        self::assertTrue($loan->id()->equals($stored->id()));
        self::assertTrue($loan->userId()->equals($stored->userId()));
        self::assertTrue($work->id()->equals($stored->workId()));
        self::assertSame(ExternalLoanStatus::Active, $stored->status());
        self::assertSame(
            "2026-08-10 09:30:00.123456+00:00",
            $stored->borrowedAt()->format("Y-m-d H:i:s.uP")
        );
        self::assertNull($stored->dueAt());
    }

    public function testExternalLoanWithDueDateRoundTripsInUtc(): void
    {
        $work = $this->persistWork();
        $loan = ExternalLoan::active(
            new ExternalLoanId("loan-x"),
            new UserId("user-x"),
            $work->id(),
            new DateTimeImmutable("2026-08-10 11:30:00.100000+02:00"),
            new DateTimeImmutable("2026-08-24 17:45:00.654321+02:00")
        );
        $repository = $this->externalLoanRepository();

        $this->externalLoanWriter()->add($loan);
        $stored = $repository->findForUser(
            $loan->id(),
            $loan->userId()
        );

        self::assertNotNull($stored);
        self::assertSame(
            "2026-08-10 09:30:00.100000+00:00",
            $stored->borrowedAt()->format("Y-m-d H:i:s.uP")
        );
        self::assertNotNull($stored->dueAt());
        self::assertSame(
            "2026-08-24 15:45:00.654321+00:00",
            $stored->dueAt()->format("Y-m-d H:i:s.uP")
        );
    }

    public function testZeroLibraryAccountOwnsLoanWithoutProvisioning(): void
    {
        $wordpressUserId = wp_insert_user([
            "user_login" => "external-loan-zero-library",
            "user_pass" => "integration-test-only",
            "user_email" => "external-zero@example.invalid",
        ]);
        self::assertIsInt($wordpressUserId);
        $userId = new UserId((string) $wordpressUserId);
        $work = $this->persistWork();
        $loan = ExternalLoan::active(
            new ExternalLoanId("loan-zero-library"),
            $userId,
            $work->id(),
            $this->utc("2026-08-10 09:30:00.000000")
        );
        $repository = $this->externalLoanRepository();
        $this->externalLoanWriter()->add($loan);

        $stored = (new GetOwnedExternalLoanService(
            new ControllableAuthenticatedUser($userId),
            $repository
        ))->get($loan->id());

        self::assertNotNull($stored);
        self::assertTrue($userId->equals($stored->userId()));
        self::assertSame(0, $this->tableCount($this->tableNames->libraries()));
        self::assertSame(0, $this->tableCount(
            $this->tableNames->memberships()
        ));
        self::assertSame(0, $this->tableCount(
            $this->tableNames->personalLibraryDesignations()
        ));
        self::assertSame(1, $this->tableCount(
            $this->tableNames->externalLoans()
        ));
    }

    public function testLibraryRolesNeverGrantAccessToForeignLoan(): void
    {
        $work = $this->persistWork();
        $ownerX = new UserId("user-x");
        $libraryOwner = new UserId("library-owner");
        $manager = new UserId("library-manager");
        $directMember = new UserId("direct-member");
        $loan = ExternalLoan::active(
            new ExternalLoanId("loan-x"),
            $ownerX,
            $work->id(),
            $this->utc("2026-08-10 09:30:00.000000")
        );
        $repository = $this->externalLoanRepository();
        $this->externalLoanWriter()->add($loan);
        $libraryId = new LibraryId("library-a");
        $this->createLibrary($libraryId, $libraryOwner);
        $this->addMembership(
            $libraryId,
            $manager,
            ManagementRole::Manager,
            UseAccess::Direct
        );
        $this->addMembership(
            $libraryId,
            $directMember,
            ManagementRole::Member,
            UseAccess::Direct
        );
        $actor = new ControllableAuthenticatedUser($ownerX);
        $service = new GetOwnedExternalLoanService($actor, $repository);

        self::assertNotNull($service->get($loan->id()));

        foreach ([$libraryOwner, $manager, $directMember] as $foreignActor) {
            $actor->authenticateAs($foreignActor);
            self::assertNull($service->get($loan->id()));
        }
        self::assertNull($repository->findForUser(
            $loan->id(),
            $libraryOwner
        ));
    }

    public function testUnknownWorkIsRejected(): void
    {
        try {
            $loan = ExternalLoan::active(
                new ExternalLoanId("loan-x"),
                new UserId("user-x"),
                new WorkId("missing-work"),
                $this->utc("2026-08-10 09:30:00.000000")
            );
            $this->externalLoanWriter()->add($loan);
            self::fail("External Loan without Work was accepted.");
        } catch (PersistenceException) {
            self::assertSame(0, $this->tableCount(
                $this->tableNames->externalLoans()
            ));
        }
    }

    public function testTwoUsersCanBorrowSameWork(): void
    {
        $work = $this->persistWork();
        $repository = $this->externalLoanRepository();
        $loanX = ExternalLoan::active(
            new ExternalLoanId("loan-x"),
            new UserId("user-x"),
            $work->id(),
            $this->utc("2026-08-10 09:30:00.000000")
        );
        $loanY = ExternalLoan::active(
            new ExternalLoanId("loan-y"),
            new UserId("user-y"),
            $work->id(),
            $this->utc("2026-08-11 10:00:00.000000")
        );

        $this->externalLoanWriter()->add($loanX);
        $this->externalLoanWriter()->add($loanY);

        self::assertNotNull($repository->findForUser(
            $loanX->id(),
            $loanX->userId()
        ));
        self::assertNotNull($repository->findForUser(
            $loanY->id(),
            $loanY->userId()
        ));
        self::assertSame(1, $this->tableCount($this->tableNames->works()));
        self::assertSame(2, $this->tableCount(
            $this->tableNames->externalLoans()
        ));
    }

    public function testExternalLoanIdIsUnique(): void
    {
        $work = $this->persistWork();
        $repository = $this->externalLoanRepository();
        $firstLoan = ExternalLoan::active(
            new ExternalLoanId("loan-shared"),
            new UserId("user-x"),
            $work->id(),
            $this->utc("2026-08-10 09:30:00.000000")
        );
        $this->externalLoanWriter()->add($firstLoan);

        try {
            $secondLoan = ExternalLoan::active(
                new ExternalLoanId("loan-shared"),
                new UserId("user-y"),
                $work->id(),
                $this->utc("2026-08-11 09:30:00.000000")
            );
            $this->externalLoanWriter()->add($secondLoan);
            self::fail("Duplicate External Loan ID was accepted.");
        } catch (PersistenceException) {
            self::assertSame(1, $this->tableCount(
                $this->tableNames->externalLoans()
            ));
        }
    }

    public function testReadAndWritePersistenceAdaptersAreSeparated(): void
    {
        self::assertFalse(method_exists(
            $this->externalLoanRepository(),
            "add"
        ));
        self::assertFalse(method_exists(
            $this->externalLoanWriter(),
            "findForUser"
        ));
    }

    private function persistWork(): Work
    {
        $work = new Work(new WorkId("work-w"), "Shared Work");
        (new WpdbWorkRepository(
            $this->database,
            $this->tableNames
        ))->add($work);

        return $work;
    }

    private function createLibrary(LibraryId $libraryId, UserId $owner): void
    {
        (new CreateLibraryService(
            new WpdbLibraryRepository($this->database, $this->tableNames),
            $this->membershipRepository(),
            $this->classificationSeedEvolution(),
            new WpdbTransactionManager($this->database)
        ))->create(Library::privateLibrary($libraryId), $owner);
    }

    private function addMembership(
        LibraryId $libraryId,
        UserId $userId,
        ManagementRole $role,
        UseAccess $useAccess
    ): void {
        $this->membershipRepository()->add(
            new LibraryMembershipAssignment(
                $libraryId,
                $userId,
                new LibraryMembership(
                    $role,
                    $useAccess,
                    MembershipStatus::Active
                )
            )
        );
    }

    private function externalLoanRepository(): WpdbExternalLoanRepository
    {
        return new WpdbExternalLoanRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function externalLoanWriter(): WpdbExternalLoanWriter
    {
        return new WpdbExternalLoanWriter(
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

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone("UTC"));
    }
}
