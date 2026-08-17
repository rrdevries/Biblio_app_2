<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\Reading\CreateActiveReadingRoundService;
use Biblio\Core\Application\Reading\GetOwnedReadingRoundService;
use Biblio\Core\Application\Reading\StartReadingFromExternalLoanService;
use Biblio\Core\Application\Reading\StartReadingFromLibraryItemService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Borrowing\ExternalLoanStatus;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbExternalLoanRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Reading\ActiveReadingRoundAlreadyExists;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingSource;
use Biblio\Core\Reading\ReadingSourceUnavailable;
use DateTimeImmutable;
use DateTimeZone;

final class ReadingRoundPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testDirectItemCreatesRoundAndOtherAccessModesDoNot(): void
    {
        $library = new LibraryId("library-a");
        $owner = new UserId("owner-a");
        $direct = new UserId("direct-user");
        $borrow = new UserId("borrow-user");
        $viewOnly = new UserId("view-user");
        $this->createLibrary($library, $owner);
        $this->addMembership($library, $direct, UseAccess::Direct);
        $this->addMembership($library, $borrow, UseAccess::Borrow);
        $this->addMembership($library, $viewOnly, UseAccess::ViewOnly);
        $item = $this->persistItem($library, "work-w", "edition-e", "item-a");
        $service = $this->itemService();

        $round = $service->start(
            $direct,
            new LibraryContext($library, $direct),
            $item->id(),
            $this->startedAt()
        );

        self::assertTrue($direct->equals($round->userId()));
        self::assertSame("work-w", $round->workId()->value());
        self::assertSame("item-a", $round->source()->itemId()?->value());
        self::assertFalse($this->columnExists(
            $this->tableNames->readingRounds(),
            "library_id"
        ));

        foreach ([$borrow, $viewOnly] as $user) {
            $this->assertItemStartRejected(
                $service,
                $user,
                new LibraryContext($library, $user),
                $item->id()
            );
        }

        self::assertSame(1, $this->roundCount());
    }

    public function testWrongContextAndUnknownItemLeaveNoRound(): void
    {
        $libraryA = new LibraryId("library-a");
        $libraryB = new LibraryId("library-b");
        $user = new UserId("user-x");
        $this->createLibrary($libraryA, new UserId("owner-a"));
        $this->createLibrary($libraryB, new UserId("owner-b"));
        $this->addMembership($libraryA, $user, UseAccess::Direct);
        $this->addMembership($libraryB, $user, UseAccess::Direct);
        $item = $this->persistItem(
            $libraryA,
            "work-w",
            "edition-e",
            "item-a"
        );
        $service = $this->itemService();

        $this->assertItemStartRejected(
            $service,
            $user,
            new LibraryContext($libraryB, $user),
            $item->id()
        );
        $this->assertItemStartRejected(
            $service,
            $user,
            new LibraryContext($libraryA, $user),
            new ItemId("unknown-item")
        );

        self::assertSame(0, $this->roundCount());
    }

    public function testExternalLoanOwnerStartsWithoutLibraryContext(): void
    {
        $user = new UserId("user-x");
        $work = $this->persistWork("work-w");
        $loan = $this->persistLoan($user, $work->id(), "loan-l");

        $round = $this->loanService()->start(
            $user,
            $loan->id(),
            $this->startedAt()
        );

        self::assertTrue($user->equals($round->userId()));
        self::assertSame("work-w", $round->workId()->value());
        self::assertSame(
            "loan-l",
            $round->source()->externalLoanId()?->value()
        );
        self::assertSame(1, $this->roundCount());
    }

    public function testForeignAndUnknownExternalLoansAreRejected(): void
    {
        $owner = new UserId("user-x");
        $foreign = new UserId("user-y");
        $work = $this->persistWork("work-w");
        $active = $this->persistLoan($owner, $work->id(), "loan-active");
        $this->createLibrary(new LibraryId("library-a"), $foreign);
        $service = $this->loanService();

        foreach ([$active->id(), new ExternalLoanId("unknown")] as $loanId) {
            $actingUser = $loanId->equals($active->id())
                ? $foreign
                : $owner;

            try {
                $service->start($actingUser, $loanId, $this->startedAt());
                self::fail("Unavailable External Loan source was accepted.");
            } catch (ReadingSourceUnavailable) {
                self::assertSame(0, $this->roundCount());
            }
        }
    }

    public function testSameWorkWithItemAndExternalLoanMayBothBeActive(): void
    {
        $library = new LibraryId("library-a");
        $user = new UserId("user-x");
        $this->createLibrary($library, $user);
        $item = $this->persistItem($library, "work-w", "edition-e", "item-a");
        $loan = $this->persistLoan(
            $user,
            new WorkId("work-w"),
            "loan-l"
        );

        $itemRound = $this->itemService()->start(
            $user,
            new LibraryContext($library, $user),
            $item->id(),
            $this->startedAt()
        );
        $loanRound = $this->loanService()->start(
            $user,
            $loan->id(),
            $this->startedAt()
        );

        self::assertTrue($itemRound->workId()->equals($loanRound->workId()));
        self::assertFalse($itemRound->source()->equals($loanRound->source()));
        self::assertSame(2, $this->roundCount());
    }

    public function testTwoItemsForSameWorkMayBothBeActive(): void
    {
        $library = new LibraryId("library-a");
        $user = new UserId("user-x");
        $this->createLibrary($library, $user);
        $itemA = $this->persistItem(
            $library,
            "work-w",
            "edition-e",
            "item-a"
        );
        $itemB = Item::active(
            new ItemId("item-b"),
            $library,
            $itemA->editionId()
        );
        $this->itemRepository()->add($itemB);
        $service = $this->itemService();
        $context = new LibraryContext($library, $user);

        $service->start($user, $context, $itemA->id(), $this->startedAt());
        $service->start($user, $context, $itemB->id(), $this->startedAt());

        self::assertSame(2, $this->roundCount());
    }

    public function testDuplicateItemAndExternalLoanReturnControlledConflict(): void
    {
        $library = new LibraryId("library-a");
        $user = new UserId("user-x");
        $this->createLibrary($library, $user);
        $item = $this->persistItem($library, "work-w", "edition-e", "item-a");
        $loan = $this->persistLoan(
            $user,
            new WorkId("work-w"),
            "loan-l"
        );
        $itemService = $this->itemService();
        $context = new LibraryContext($library, $user);
        $loanService = $this->loanService();
        $itemService->start($user, $context, $item->id(), $this->startedAt());
        $loanService->start($user, $loan->id(), $this->startedAt());

        foreach ([
            fn () => $itemService->start(
                $user,
                $context,
                $item->id(),
                $this->startedAt()
            ),
            fn () => $loanService->start(
                $user,
                $loan->id(),
                $this->startedAt()
            ),
        ] as $duplicateStart) {
            try {
                $duplicateStart();
                self::fail("Duplicate active source was accepted.");
            } catch (ActiveReadingRoundAlreadyExists $exception) {
                self::assertSame(
                    "An active Reading Round already exists for this source.",
                    $exception->getMessage()
                );
                self::assertSame(
                    FailureReason::ReadingRoundAlreadyActiveForSource,
                    $exception->reason()
                );
                self::assertNotNull($exception->getPrevious());
            }
        }

        self::assertSame(2, $this->roundCount());
    }

    public function testSameSharedItemMayBeActiveForTwoUsers(): void
    {
        $library = new LibraryId("library-a");
        $userX = new UserId("user-x");
        $userY = new UserId("user-y");
        $this->createLibrary($library, new UserId("owner-a"));
        $this->addMembership($library, $userX, UseAccess::Direct);
        $this->addMembership($library, $userY, UseAccess::Direct);
        $item = $this->persistItem($library, "work-w", "edition-e", "item-a");
        $service = $this->itemService();

        foreach ([$userX, $userY] as $user) {
            $service->start(
                $user,
                new LibraryContext($library, $user),
                $item->id(),
                $this->startedAt()
            );
        }

        self::assertSame(2, $this->roundCount());
    }

    public function testOwnerScopedReadsGiveNoLibraryRoleBypass(): void
    {
        $library = new LibraryId("library-a");
        $owner = new UserId("library-owner");
        $manager = new UserId("library-manager");
        $reader = new UserId("reader-x");
        $this->createLibrary($library, $owner);
        $this->addMembership(
            $library,
            $manager,
            UseAccess::Direct,
            ManagementRole::Manager
        );
        $this->addMembership($library, $reader, UseAccess::Direct);
        $item = $this->persistItem($library, "work-w", "edition-e", "item-a");
        $round = $this->itemService()->start(
            $reader,
            new LibraryContext($library, $reader),
            $item->id(),
            $this->startedAt()
        );
        $service = new GetOwnedReadingRoundService($this->roundRepository());

        self::assertNotNull($service->get($reader, $round->id()));
        self::assertNull($service->get($owner, $round->id()));
        self::assertNull($service->get($manager, $round->id()));
        self::assertNull($this->roundRepository()->findForUser(
            $round->id(),
            new UserId("unrelated-user")
        ));
    }

    public function testRepositoryRejectsWriteForAnotherUser(): void
    {
        $library = new LibraryId("library-a");
        $this->createLibrary($library, new UserId("owner-a"));
        $item = $this->persistItem(
            $library,
            "work-w",
            "edition-e",
            "item-a"
        );
        $round = ReadingRound::active(
            new ReadingRoundId("round-x"),
            new UserId("user-x"),
            new WorkId("work-w"),
            ReadingSource::libraryItem($item->id()),
            $this->startedAt()
        );

        try {
            $this->roundRepository()->addForUser(
                new UserId("user-y"),
                $round
            );
            self::fail("Foreign user write was accepted.");
        } catch (AuthorizationException $exception) {
            self::assertSame(
                FailureReason::AuthorizationDenied,
                $exception->reason()
            );
            self::assertSame(0, $this->roundCount());
        }
    }

    public function testDatabaseRequiresExactlyOneValidConcreteSource(): void
    {
        $library = new LibraryId("library-a");
        $user = new UserId("user-x");
        $this->createLibrary($library, $user);
        $item = $this->persistItem($library, "work-w", "edition-e", "item-a");
        $loan = $this->persistLoan(
            $user,
            new WorkId("work-w"),
            "loan-l"
        );

        self::assertFalse($this->rawRoundInsert("round-none", null, null));
        self::assertFalse($this->rawRoundInsert(
            "round-both",
            $item->id()->value(),
            $loan->id()->value()
        ));
        self::assertFalse($this->rawRoundInsert(
            "round-missing-item",
            "missing-item",
            null
        ));
        self::assertFalse($this->rawRoundInsert(
            "round-missing-loan",
            null,
            "missing-loan"
        ));
        self::assertSame(0, $this->roundCount());
    }

    public function testSourceCannotBeDeletedWhileRoundKeepsHistoryReference(): void
    {
        $library = new LibraryId("library-a");
        $user = new UserId("user-x");
        $this->createLibrary($library, $user);
        $item = $this->persistItem($library, "work-w", "edition-e", "item-a");
        $this->itemService()->start(
            $user,
            new LibraryContext($library, $user),
            $item->id(),
            $this->startedAt()
        );
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $deleted = $this->database->delete(
                $this->tableNames->items(),
                ["item_id" => $item->id()->value()],
                ["%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        self::assertFalse($deleted);
        self::assertSame(1, $this->roundCount());
    }

    private function itemService(): StartReadingFromLibraryItemService
    {
        return new StartReadingFromLibraryItemService(
            new GetAccessibleLibraryItemService(
                $this->itemRepository(),
                new LibraryAccessService(
                    $this->membershipRepository(),
                    new LibraryAuthorizationPolicy()
                )
            ),
            new WpdbEditionRepository($this->database, $this->tableNames),
            new CreateActiveReadingRoundService($this->roundRepository())
        );
    }

    private function loanService(): StartReadingFromExternalLoanService
    {
        $loans = new WpdbExternalLoanRepository(
            $this->database,
            $this->tableNames
        );

        return new StartReadingFromExternalLoanService(
            new GetOwnedExternalLoanService($loans),
            new CreateActiveReadingRoundService($this->roundRepository())
        );
    }

    private function createLibrary(LibraryId $libraryId, UserId $owner): void
    {
        (new CreateLibraryService(
            new WpdbLibraryRepository($this->database, $this->tableNames),
            $this->membershipRepository(),
            new WpdbTransactionManager($this->database)
        ))->create(Library::privateLibrary($libraryId), $owner);
    }

    private function addMembership(
        LibraryId $libraryId,
        UserId $userId,
        UseAccess $useAccess,
        ManagementRole $role = ManagementRole::Member
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

    private function persistItem(
        LibraryId $libraryId,
        string $workId,
        string $editionId,
        string $itemId
    ): Item {
        $work = $this->persistWork($workId);
        $edition = new Edition(new EditionId($editionId), $work->id());
        (new WpdbEditionRepository(
            $this->database,
            $this->tableNames
        ))->add($edition);
        $item = Item::active(new ItemId($itemId), $libraryId, $edition->id());
        $this->itemRepository()->add($item);

        return $item;
    }

    private function persistWork(string $id): Work
    {
        $work = new Work(new WorkId($id), "Reading Work");
        (new WpdbWorkRepository(
            $this->database,
            $this->tableNames
        ))->add($work);

        return $work;
    }

    private function persistLoan(
        UserId $userId,
        WorkId $workId,
        string $loanId,
        ExternalLoanStatus $status = ExternalLoanStatus::Active
    ): ExternalLoan {
        $loan = new ExternalLoan(
            new ExternalLoanId($loanId),
            $userId,
            $workId,
            $status,
            $this->startedAt(),
            null
        );
        (new WpdbExternalLoanRepository(
            $this->database,
            $this->tableNames
        ))->add($loan);

        return $loan;
    }

    private function itemRepository(): WpdbItemRepository
    {
        return new WpdbItemRepository($this->database, $this->tableNames);
    }

    private function membershipRepository(): WpdbLibraryMembershipRepository
    {
        return new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function roundRepository(): WpdbReadingRoundRepository
    {
        return new WpdbReadingRoundRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function assertItemStartRejected(
        StartReadingFromLibraryItemService $service,
        UserId $user,
        LibraryContext $context,
        ItemId $itemId
    ): void {
        try {
            $service->start($user, $context, $itemId, $this->startedAt());
            self::fail("Unavailable Item source was accepted.");
        } catch (ReadingSourceUnavailable) {
            self::assertTrue(true);
        }
    }

    private function rawRoundInsert(
        string $roundId,
        ?string $itemId,
        ?string $loanId
    ): bool {
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $this->tableNames->readingRounds(),
                [
                    "reading_round_id" => $roundId,
                    "user_id" => "user-x",
                    "work_id" => "work-w",
                    "item_id" => $itemId,
                    "external_loan_id" => $loanId,
                    "round_status" => "active",
                    "started_at" => "2026-08-16 10:00:00.000000",
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        return $result !== false;
    }

    private function roundCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->readingRounds()}`"
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s "
            . "AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            $column
        )) === 1;
    }

    private function startedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            "2026-08-16 10:00:00.123456",
            new DateTimeZone("UTC")
        );
    }
}
