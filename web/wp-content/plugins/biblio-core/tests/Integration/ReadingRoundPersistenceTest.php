<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\Reading\CreateActiveReadingRoundService;
use Biblio\Core\Application\Reading\CorrectEndedReadingRoundService;
use Biblio\Core\Application\Reading\CorrectReadingRoundSourceService;
use Biblio\Core\Application\Reading\DeleteHistoricalReadingRoundService;
use Biblio\Core\Application\Reading\FinishReadingRoundService;
use Biblio\Core\Application\Reading\GetPersonalWorkReadingStatusService;
use Biblio\Core\Application\Reading\GetReadingSequenceService;
use Biblio\Core\Application\Reading\GetOwnedReadingRoundService;
use Biblio\Core\Application\Reading\ReadingRoundCreation;
use Biblio\Core\Application\Reading\ReadingRoundEnd;
use Biblio\Core\Application\Reading\RegisterHistoricalReadingRoundService;
use Biblio\Core\Application\Reading\StartReadingFromExternalLoanService;
use Biblio\Core\Application\Reading\StartReadingFromLibraryItemService;
use Biblio\Core\Application\Reading\StopReadingRoundService;
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
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbExternalLoanRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbExternalLoanWriter;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\WordPress\OpaqueReadingRoundIdGenerator;
use Biblio\Core\Infrastructure\WordPress\SystemReadingRoundClock;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Reading\ActiveReadingRoundAlreadyExists;
use Biblio\Core\Reading\PersonalWorkReadingStatus;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingPeriod;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundClock;
use Biblio\Core\Reading\ReadingRoundDeletionNotAllowed;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundIdCollisionExhausted;
use Biblio\Core\Reading\ReadingRoundIdGenerator;
use Biblio\Core\Reading\ReadingRoundNotAvailable;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\Reading\ReadingRoundProvenance;
use Biblio\Core\Reading\ReadingRoundSourceCorrectionUnavailable;
use Biblio\Core\Reading\ReadingRoundStale;
use Biblio\Core\Reading\ReadingRoundVersion;
use Biblio\Core\Reading\ReadingSequenceClassification;
use Biblio\Core\Reading\ReadingSource;
use Biblio\Core\Reading\ReadingSourceUnavailable;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use DateTimeImmutable;
use DateTimeZone;

final class IntegrationSequenceReadingRoundIdGenerator implements
    ReadingRoundIdGenerator
{
    public int $calls = 0;

    /** @param list<string> $values */
    public function __construct(private array $values)
    {
    }

    public function next(): ReadingRoundId
    {
        $value = $this->values[$this->calls] ?? "unexpected-{$this->calls}";
        $this->calls++;

        return new ReadingRoundId($value);
    }
}

final readonly class IntegrationFixedReadingRoundClock implements
    ReadingRoundClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            "2026-08-22 12:00:00.123456",
            new DateTimeZone("UTC")
        );
    }
}

final class ReadingRoundPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testLifecycleHistoryPrecisionAndDerivedTruthRoundTrip(): void
    {
        $library = new LibraryId("library-lifecycle");
        $user = new UserId("reader-lifecycle");
        $this->createLibrary($library, $user);
        $item = $this->persistItem(
            $library,
            "work-lifecycle",
            "edition-lifecycle",
            "item-lifecycle"
        );
        $loan = $this->persistLoan(
            $user,
            new WorkId("work-lifecycle"),
            "loan-lifecycle"
        );
        $actor = new ControllableAuthenticatedUser($user);
        $ids = new IntegrationSequenceReadingRoundIdGenerator([
            "round-item",
            "round-loan",
            "round-year",
            "round-month",
            "round-day",
        ]);
        $clock = new IntegrationFixedReadingRoundClock();
        $repository = $this->roundRepository();
        $creator = new CreateActiveReadingRoundService(
            $actor,
            $repository,
            $ids,
            $clock
        );
        $itemStart = new StartReadingFromLibraryItemService(
            new GetAccessibleLibraryItemService(
                $actor,
                $this->itemRepository(),
                new LibraryAccessService(
                    $this->membershipRepository(),
                    new LibraryAuthorizationPolicy()
                )
            ),
            new WpdbEditionRepository($this->database, $this->tableNames),
            $creator
        );
        $loanStart = new StartReadingFromExternalLoanService(
            new GetOwnedExternalLoanService(
                $actor,
                new WpdbExternalLoanRepository(
                    $this->database,
                    $this->tableNames
                )
            ),
            $creator
        );
        $transactions = new WpdbTransactionManager($this->database);
        $end = new ReadingRoundEnd(
            $actor,
            $repository,
            $clock,
            $transactions
        );
        $itemRound = $itemStart->start(
            $library,
            $item->id(),
            ReadingDate::exact(2024, 1, 10)
        );
        $loanRound = $loanStart->start(
            $loan->id(),
            ReadingDate::exact(2024, 2, 10)
        );

        $completed = (new FinishReadingRoundService($end))->finish(
            $itemRound->id(),
            $itemRound->version(),
            ReadingDate::exact(2024, 1, 20)
        );
        $stopped = (new StopReadingRoundService($end))->stop(
            $loanRound->id(),
            $loanRound->version(),
            ReadingDate::exact(2024, 2, 20)
        );

        self::assertSame(ReadingRoundOutcome::Completed, $completed->outcome());
        self::assertSame(ReadingRoundOutcome::Stopped, $stopped->outcome());
        self::assertSame(2, $completed->version()->value());
        self::assertSame(2, $stopped->version()->value());

        $historical = new RegisterHistoricalReadingRoundService(
            $actor,
            new WpdbWorkRepository($this->database, $this->tableNames),
            new ReadingRoundCreation($ids, $repository),
            $clock,
            $transactions
        );
        $year = $historical->register(
            new WorkId("work-lifecycle"),
            ReadingPeriod::ended(null, ReadingDate::year(2019))
        );
        $month = $historical->register(
            new WorkId("work-lifecycle"),
            ReadingPeriod::ended(
                ReadingDate::month(2020, 5),
                ReadingDate::month(2020, 6)
            )
        );
        $day = $historical->register(
            new WorkId("work-lifecycle"),
            ReadingPeriod::ended(
                ReadingDate::exact(2021, 7, 1),
                ReadingDate::exact(2021, 7, 8)
            )
        );

        foreach ([$year, $month, $day] as $round) {
            $stored = $repository->findForUser($round->id(), $user);
            self::assertNotNull($stored);
            self::assertNull($stored->source());
            self::assertSame(
                ReadingRoundProvenance::HistoricalManual,
                $stored->provenance()
            );
        }
        $storedYear = $repository->findForUser($year->id(), $user);
        $storedMonth = $repository->findForUser($month->id(), $user);
        $storedDay = $repository->findForUser($day->id(), $user);
        self::assertNull($storedYear?->period()->finishedOn()?->monthValue());
        self::assertNull($storedYear?->period()->finishedOn()?->dayValue());
        self::assertSame(6, $storedMonth?->period()->finishedOn()?->monthValue());
        self::assertNull($storedMonth?->period()->finishedOn()?->dayValue());
        self::assertSame(8, $storedDay?->period()->finishedOn()?->dayValue());

        $correctedStopped = (new CorrectEndedReadingRoundService(
            $actor,
            $repository,
            $clock,
            $transactions
        ))->correct(
            $day->id(),
            $day->version(),
            ReadingRoundOutcome::Stopped,
            $day->period()
        );
        self::assertSame(ReadingRoundOutcome::Stopped, $correctedStopped->outcome());

        self::assertSame(
            PersonalWorkReadingStatus::Read,
            (new GetPersonalWorkReadingStatusService($actor, $repository))->get(
                new WorkId("work-lifecycle")
            )
        );
        $sequence = (new GetReadingSequenceService($actor, $repository))
            ->forWork(new WorkId("work-lifecycle"));
        self::assertSame(
            ReadingSequenceClassification::FirstRead,
            $sequence[0]->classification()
        );
        self::assertContains(
            ReadingSequenceClassification::Reread,
            array_map(
                static fn ($entry) => $entry->classification(),
                $sequence
            )
        );
        self::assertSame(5, $ids->calls);
    }

    public function testOverlappingDatePrecisionMakesChronologyIndeterminate(): void
    {
        $user = new UserId("reader-indeterminate");
        $work = new WorkId("work-indeterminate");
        $this->persistWork($work->value());
        $actor = new ControllableAuthenticatedUser($user);
        $repository = $this->roundRepository();
        $clock = new IntegrationFixedReadingRoundClock();
        $registration = new RegisterHistoricalReadingRoundService(
            $actor,
            new WpdbWorkRepository($this->database, $this->tableNames),
            new ReadingRoundCreation(
                new IntegrationSequenceReadingRoundIdGenerator([
                    "round-year-indeterminate",
                    "round-month-indeterminate",
                ]),
                $repository
            ),
            $clock,
            new WpdbTransactionManager($this->database)
        );
        $registration->register(
            $work,
            ReadingPeriod::ended(null, ReadingDate::year(2020))
        );
        $registration->register(
            $work,
            ReadingPeriod::ended(null, ReadingDate::month(2020, 6))
        );

        $sequence = (new GetReadingSequenceService($actor, $repository))
            ->forWork($work);

        self::assertCount(2, $sequence);
        foreach ($sequence as $entry) {
            self::assertSame(
                ReadingSequenceClassification::ChronologyIndeterminate,
                $entry->classification()
            );
        }
    }

    public function testSourceCorrectionOwnershipProvenanceDeleteAndNoAudit(): void
    {
        $library = new LibraryId("library-correction");
        $owner = new UserId("round-owner");
        $manager = new UserId("library-manager");
        $this->createLibrary($library, $owner);
        $this->addMembership(
            $library,
            $manager,
            UseAccess::Direct,
            ManagementRole::Manager
        );
        $itemA = $this->persistItem(
            $library,
            "work-correction",
            "edition-correction",
            "item-correction-a"
        );
        $itemB = Item::active(
            new ItemId("item-correction-b"),
            $library,
            $itemA->editionId()
        );
        $this->itemRepository()->add($itemB);
        $wrongItem = $this->persistItem(
            $library,
            "work-other",
            "edition-other",
            "item-other"
        );
        $loan = $this->persistLoan(
            $owner,
            new WorkId("work-correction"),
            "loan-correction"
        );
        $actor = new ControllableAuthenticatedUser($owner);
        $clock = new IntegrationFixedReadingRoundClock();
        $repository = $this->roundRepository();
        $transactions = new WpdbTransactionManager($this->database);
        $accessible = new GetAccessibleLibraryItemService(
            $actor,
            $this->itemRepository(),
            new LibraryAccessService(
                $this->membershipRepository(),
                new LibraryAuthorizationPolicy()
            )
        );
        $loans = new GetOwnedExternalLoanService(
            $actor,
            new WpdbExternalLoanRepository($this->database, $this->tableNames)
        );
        $ids = new IntegrationSequenceReadingRoundIdGenerator([
            "round-correction",
            "round-historical-delete",
        ]);
        $creator = new CreateActiveReadingRoundService(
            $actor,
            $repository,
            $ids,
            $clock
        );
        $round = (new StartReadingFromLibraryItemService(
            $accessible,
            new WpdbEditionRepository($this->database, $this->tableNames),
            $creator
        ))->start(
            $library,
            $itemA->id(),
            ReadingDate::exact(2026, 1, 1)
        );
        $sourceCorrection = new CorrectReadingRoundSourceService(
            $actor,
            $repository,
            $accessible,
            new WpdbEditionRepository($this->database, $this->tableNames),
            $loans,
            $clock,
            $transactions
        );
        $corrected = $sourceCorrection->correctToLibraryItem(
            $round->id(),
            $round->version(),
            $library,
            $itemB->id()
        );
        self::assertSame("item-correction-b", $corrected->source()?->itemId()?->value());
        self::assertSame("work-correction", $corrected->workId()->value());
        self::assertSame(ReadingRoundProvenance::SourceStarted, $corrected->provenance());

        try {
            $sourceCorrection->correctToLibraryItem(
                $corrected->id(),
                $corrected->version(),
                $library,
                $wrongItem->id()
            );
            self::fail("Cross-Work source correction was accepted.");
        } catch (ReadingRoundSourceCorrectionUnavailable) {
            self::assertSame("work-correction", $corrected->workId()->value());
        }

        $ended = (new FinishReadingRoundService(new ReadingRoundEnd(
            $actor,
            $repository,
            $clock,
            $transactions
        )))->finish(
            $corrected->id(),
            $corrected->version(),
            ReadingDate::exact(2026, 2, 1)
        );
        $endedWithLoan = $sourceCorrection->correctToExternalLoan(
            $ended->id(),
            $ended->version(),
            $loan->id()
        );
        self::assertSame(
            "loan-correction",
            $endedWithLoan->source()?->externalLoanId()?->value()
        );
        self::assertSame(ReadingRoundProvenance::SourceStarted, $endedWithLoan->provenance());

        $actor->authenticateAs($manager);
        try {
            $sourceCorrection->correctToUnknown(
                $endedWithLoan->id(),
                $endedWithLoan->version(),
                true
            );
            self::fail("Library Manager changed another user's Reading Round.");
        } catch (ReadingRoundNotAvailable) {
            self::assertNotNull($repository->findForUser($endedWithLoan->id(), $owner));
        }
        $actor->authenticateAs($owner);

        $historical = (new RegisterHistoricalReadingRoundService(
            $actor,
            new WpdbWorkRepository($this->database, $this->tableNames),
            new ReadingRoundCreation($ids, $repository),
            $clock,
            $transactions
        ))->register(
            new WorkId("work-correction"),
            ReadingPeriod::ended(null, ReadingDate::year(2020))
        );
        $historicalWithSource = $sourceCorrection->correctToLibraryItem(
            $historical->id(),
            $historical->version(),
            $library,
            $itemB->id()
        );
        self::assertSame(
            ReadingRoundProvenance::HistoricalManual,
            $historicalWithSource->provenance()
        );
        $deletion = new DeleteHistoricalReadingRoundService(
            $actor,
            $repository,
            $transactions
        );
        $actor->authenticateAs($manager);
        try {
            $deletion->delete(
                $historicalWithSource->id(),
                $historicalWithSource->version()
            );
            self::fail("Another user deleted private manual history.");
        } catch (ReadingRoundNotAvailable) {
            self::assertNotNull(
                $repository->findForUser($historicalWithSource->id(), $owner)
            );
        }
        $actor->authenticateAs($owner);
        $deletion->delete(
            $historicalWithSource->id(),
            $historicalWithSource->version()
        );
        self::assertNull($repository->findForUser($historical->id(), $owner));

        try {
            $deletion->delete($endedWithLoan->id(), $endedWithLoan->version());
            self::fail("Source-started Reading Round was hard deleted.");
        } catch (ReadingRoundDeletionNotAllowed) {
            self::assertNotNull($repository->findForUser($endedWithLoan->id(), $owner));
        }

        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}`"
        ));
    }

    public function testNoOpStaleAndIdCollisionRetryBudget(): void
    {
        $library = new LibraryId("library-cas");
        $user = new UserId("reader-cas");
        $this->createLibrary($library, $user);
        $item = $this->persistItem($library, "work-cas", "edition-cas", "item-cas");
        $actor = new ControllableAuthenticatedUser($user);
        $clock = new IntegrationFixedReadingRoundClock();
        $repository = $this->roundRepository();
        $transactions = new WpdbTransactionManager($this->database);
        $ids = new IntegrationSequenceReadingRoundIdGenerator(["round-cas"]);
        $round = (new StartReadingFromLibraryItemService(
            new GetAccessibleLibraryItemService(
                $actor,
                $this->itemRepository(),
                new LibraryAccessService(
                    $this->membershipRepository(),
                    new LibraryAuthorizationPolicy()
                )
            ),
            new WpdbEditionRepository($this->database, $this->tableNames),
            new CreateActiveReadingRoundService($actor, $repository, $ids, $clock)
        ))->start($library, $item->id(), ReadingDate::exact(2026, 3, 1));
        $finish = new FinishReadingRoundService(new ReadingRoundEnd(
            $actor,
            $repository,
            $clock,
            $transactions
        ));
        $ended = $finish->finish(
            $round->id(),
            $round->version(),
            ReadingDate::exact(2026, 3, 8)
        );
        $staleNoOp = $finish->finish(
            $round->id(),
            $round->version(),
            ReadingDate::exact(2026, 3, 8)
        );
        self::assertSame(2, $staleNoOp->version()->value());

        try {
            (new CorrectEndedReadingRoundService(
                $actor,
                $repository,
                $clock,
                $transactions
            ))->correct(
                $round->id(),
                $round->version(),
                ReadingRoundOutcome::Stopped,
                ReadingPeriod::ended(
                    ReadingDate::exact(2026, 3, 1),
                    ReadingDate::exact(2026, 3, 9)
                )
            );
            self::fail("Divergent stale correction was accepted.");
        } catch (ReadingRoundStale $stale) {
            self::assertSame(2, $stale->current()->version()->value());
        }

        $creation = new ReadingRoundCreation(
            new IntegrationSequenceReadingRoundIdGenerator([
                "round-cas",
                "round-after-collision",
            ]),
            $repository
        );
        $createdAfterCollision = $creation->create(
            $user,
            fn (ReadingRoundId $id): ReadingRound => ReadingRound::historical(
                $id,
                $user,
                new WorkId("work-cas"),
                ReadingPeriod::ended(null, ReadingDate::year(2018)),
                $clock->now()
            )
        );
        self::assertSame("round-after-collision", $createdAfterCollision->id()->value());

        $exhaustedIds = new IntegrationSequenceReadingRoundIdGenerator([
            "round-cas",
            "round-cas",
            "round-cas",
            "round-cas",
            "should-not-be-used",
        ]);
        try {
            (new ReadingRoundCreation($exhaustedIds, $repository))->create(
                $user,
                fn (ReadingRoundId $id): ReadingRound => ReadingRound::historical(
                    $id,
                    $user,
                    new WorkId("work-cas"),
                    ReadingPeriod::ended(null, ReadingDate::year(2017)),
                    $clock->now()
                )
            );
            self::fail("Reading Round ID collision retry budget was unbounded.");
        } catch (ReadingRoundIdCollisionExhausted $exception) {
            self::assertSame(
                FailureReason::ReadingRoundIdCollisionExhausted,
                $exception->reason()
            );
            self::assertSame(4, $exhaustedIds->calls);
        }
        self::assertSame(ReadingRoundOutcome::Completed, $ended->outcome());
    }
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
        $service = $this->itemService($direct);

        $round = $service->start(
            $library,
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
                $this->itemService($user),
                $library,
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
        $this->assertItemStartRejected(
            $this->itemService($user),
            $libraryB,
            $item->id()
        );
        $this->assertItemStartRejected(
            $this->itemService($user),
            $libraryA,
            new ItemId("unknown-item")
        );

        self::assertSame(0, $this->roundCount());
    }

    public function testExternalLoanOwnerStartsWithoutLibraryContext(): void
    {
        $user = new UserId("user-x");
        $work = $this->persistWork("work-w");
        $loan = $this->persistLoan($user, $work->id(), "loan-l");

        $round = $this->loanService($user)->start(
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
        foreach ([$active->id(), new ExternalLoanId("unknown")] as $loanId) {
            $actingUser = $loanId->equals($active->id())
                ? $foreign
                : $owner;

            try {
                $this->loanService($actingUser)->start(
                    $loanId,
                    $this->startedAt()
                );
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

        $itemRound = $this->itemService($user)->start(
            $library,
            $item->id(),
            $this->startedAt()
        );
        $loanRound = $this->loanService($user)->start(
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
        $service = $this->itemService($user);

        $service->start($library, $itemA->id(), $this->startedAt());
        $service->start($library, $itemB->id(), $this->startedAt());

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
        $itemService = $this->itemService($user);
        $loanService = $this->loanService($user);
        $itemService->start($library, $item->id(), $this->startedAt());
        $loanService->start($loan->id(), $this->startedAt());

        foreach ([
            fn () => $itemService->start(
                $library,
                $item->id(),
                $this->startedAt()
            ),
            fn () => $loanService->start(
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
        foreach ([$userX, $userY] as $user) {
            $this->itemService($user)->start(
                $library,
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
        $round = $this->itemService($reader)->start(
            $library,
            $item->id(),
            $this->startedAt()
        );
        $actor = new ControllableAuthenticatedUser($reader);
        $service = new GetOwnedReadingRoundService(
            $actor,
            $this->roundRepository()
        );

        self::assertNotNull($service->get($round->id()));
        $actor->authenticateAs($owner);
        self::assertNull($service->get($round->id()));
        $actor->authenticateAs($manager);
        self::assertNull($service->get($round->id()));
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

    public function testRepositoryRejectsItemWorkMismatch(): void
    {
        $library = new LibraryId("library-a");
        $user = new UserId("user-x");
        $this->createLibrary($library, $user);
        $item = $this->persistItem(
            $library,
            "work-from-item",
            "edition-a",
            "item-a"
        );
        $wrongWork = $this->persistWork("wrong-work");
        $round = ReadingRound::active(
            new ReadingRoundId("round-mismatched-item"),
            $user,
            $wrongWork->id(),
            ReadingSource::libraryItem($item->id()),
            $this->startedAt()
        );

        try {
            $this->roundRepository()->addForUser($user, $round);
            self::fail("Item/Work mismatch was persisted.");
        } catch (PersistenceException $exception) {
            self::assertSame(
                FailureReason::PersistenceWriteFailed,
                $exception->reason()
            );
            self::assertSame(0, $this->roundCount());
        }
    }

    public function testRepositoryRejectsExternalLoanWorkMismatch(): void
    {
        $user = new UserId("user-x");
        $loanWork = $this->persistWork("work-from-loan");
        $wrongWork = $this->persistWork("wrong-work");
        $loan = $this->persistLoan(
            $user,
            $loanWork->id(),
            "loan-a"
        );
        $round = ReadingRound::active(
            new ReadingRoundId("round-mismatched-loan"),
            $user,
            $wrongWork->id(),
            ReadingSource::externalLoan($loan->id()),
            $this->startedAt()
        );

        try {
            $this->roundRepository()->addForUser($user, $round);
            self::fail("ExternalLoan/Work mismatch was persisted.");
        } catch (PersistenceException $exception) {
            self::assertSame(
                FailureReason::PersistenceWriteFailed,
                $exception->reason()
            );
            self::assertSame(0, $this->roundCount());
        }
    }

    public function testDatabaseAllowsZeroOrOneSourceButNeverBoth(): void
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

        self::assertTrue($this->rawRoundInsert("round-none", null, null));
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
        self::assertSame(1, $this->roundCount());
    }

    public function testSourceCannotBeDeletedWhileRoundKeepsHistoryReference(): void
    {
        $library = new LibraryId("library-a");
        $user = new UserId("user-x");
        $this->createLibrary($library, $user);
        $item = $this->persistItem($library, "work-w", "edition-e", "item-a");
        $this->itemService($user)->start(
            $library,
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

    private function itemService(UserId $actor): StartReadingFromLibraryItemService
    {
        $authenticatedUser = new ControllableAuthenticatedUser($actor);

        return new StartReadingFromLibraryItemService(
            new GetAccessibleLibraryItemService(
                $authenticatedUser,
                $this->itemRepository(),
                new LibraryAccessService(
                    $this->membershipRepository(),
                    new LibraryAuthorizationPolicy()
                )
            ),
            new WpdbEditionRepository($this->database, $this->tableNames),
            new CreateActiveReadingRoundService(
                $authenticatedUser,
                $this->roundRepository(),
                new OpaqueReadingRoundIdGenerator(),
                new SystemReadingRoundClock()
            )
        );
    }

    private function loanService(UserId $actor): StartReadingFromExternalLoanService
    {
        $authenticatedUser = new ControllableAuthenticatedUser($actor);
        $loans = new WpdbExternalLoanRepository(
            $this->database,
            $this->tableNames
        );

        return new StartReadingFromExternalLoanService(
            new GetOwnedExternalLoanService($authenticatedUser, $loans),
            new CreateActiveReadingRoundService(
                $authenticatedUser,
                $this->roundRepository(),
                new OpaqueReadingRoundIdGenerator(),
                new SystemReadingRoundClock()
            )
        );
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
        (new WpdbExternalLoanWriter(
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
        LibraryId $libraryId,
        ItemId $itemId
    ): void {
        try {
            $service->start($libraryId, $itemId, $this->startedAt());
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
                    "started_at" => null,
                    "round_outcome" => null,
                    "provenance" => "source_started",
                    "reading_started_year" => 2026,
                    "reading_started_month" => 8,
                    "reading_started_day" => 16,
                    "reading_finished_year" => null,
                    "reading_finished_month" => null,
                    "reading_finished_day" => null,
                    "created_at" => "2026-08-16 10:00:00.000000",
                    "updated_at" => "2026-08-16 10:00:00.000000",
                    "ended_at" => null,
                    "round_version" => 1,
                ],
                [
                    "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s",
                    "%d", "%d", "%d", "%d", "%d", "%d", "%s", "%s",
                    "%s", "%d",
                ]
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
