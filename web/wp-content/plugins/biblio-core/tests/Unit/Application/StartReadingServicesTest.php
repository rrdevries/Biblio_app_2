<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\NextReading\ConsumeNextReadingAfterStartService;
use Biblio\Core\Application\Reading\CreateActiveReadingRoundService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Application\Reading\GetOwnedReadingRoundService;
use Biblio\Core\Application\Reading\StartReadingFromExternalLoanService;
use Biblio\Core\Application\Reading\StartReadingFromLibraryItemService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Borrowing\ExternalLoanRepository;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\EditionRepository;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemRepository;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\LibraryMembershipRepository;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\NextReading\{NextReadingList,WritableNextReadingRepository};
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundLifecycle;
use Biblio\Core\Reading\ReadingRoundRepository;
use Biblio\Core\Reading\ReadingRoundVersion;
use Biblio\Core\Reading\ReadingSource;
use Biblio\Core\Reading\ReadingSourceUnavailable;
use Biblio\Core\Reading\WritableReadingRoundRepository;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use DateTimeImmutable;
use Biblio\Core\Infrastructure\WordPress\OpaqueReadingRoundIdGenerator;
use Biblio\Core\Infrastructure\WordPress\SystemReadingRoundClock;
use Biblio\Core\Infrastructure\WordPress\SystemNextReadingClock;
use PHPUnit\Framework\TestCase;

final class ReadingInMemoryItemRepository implements ItemRepository
{
    public array $items = [];

    public function add(Item $item): void
    {
        $this->items[$item->id()->value()] = $item;
    }

    public function findInLibrary(
        ItemId $itemId,
        LibraryId $libraryId
    ): ?Item {
        $item = $this->items[$itemId->value()] ?? null;

        return $item !== null && $libraryId->equals($item->libraryId())
            ? $item
            : null;
    }

    public function findManyInLibrary(LibraryId $libraryId, array $itemIds): array
    {
        $result = [];
        foreach ($itemIds as $itemId) { $result[$itemId->value()] = $this->findInLibrary($itemId, $libraryId); }
        return $result;
    }
}

final class ReadingInMemoryEditionRepository implements EditionRepository
{
    public array $editions = [];

    public function add(Edition $edition): void
    {
        $this->editions[$edition->id()->value()] = $edition;
    }

    public function find(EditionId $editionId): ?Edition
    {
        return $this->editions[$editionId->value()] ?? null;
    }
}

final class ReadingInMemoryMembershipRepository implements
    LibraryMembershipRepository
{
    public array $assignments = [];

    public function add(LibraryMembershipAssignment $assignment): void
    {
        $this->assignments[
            $assignment->libraryId()->value()
            . "|"
            . $assignment->userId()->value()
        ] = $assignment;
    }

    public function findFor(
        LibraryId $libraryId,
        UserId $userId
    ): ?LibraryMembershipAssignment {
        return $this->assignments[
            $libraryId->value() . "|" . $userId->value()
        ] ?? null;
    }
}

final class ReadingInMemoryExternalLoanRepository implements
    ExternalLoanRepository
{
    public array $loans = [];

    public function add(ExternalLoan $externalLoan): void
    {
        $this->loans[$externalLoan->id()->value()] = $externalLoan;
    }

    public function findForUser(
        ExternalLoanId $externalLoanId,
        UserId $userId
    ): ?ExternalLoan {
        $loan = $this->loans[$externalLoanId->value()] ?? null;

        return $loan !== null && $userId->equals($loan->userId())
            ? $loan
            : null;
    }
}

final class ReadingInMemoryRoundRepository implements
    WritableReadingRoundRepository
{
    public array $rounds = [];

    public function addForUser(
        UserId $authenticatedUserId,
        ReadingRound $readingRound
    ): void {
        if ($authenticatedUserId->equals($readingRound->userId())) {
            $this->rounds[$readingRound->id()->value()] = $readingRound;
        }
    }

    public function findForUser(
        ReadingRoundId $readingRoundId,
        UserId $userId
    ): ?ReadingRound {
        $round = $this->rounds[$readingRoundId->value()] ?? null;

        return $round !== null && $userId->equals($round->userId())
            ? $round
            : null;
    }

    public function findActiveForUserAndSource(
        UserId $userId,
        ReadingSource $source
    ): ?ReadingRound {
        foreach ($this->rounds as $round) {
            if (
                $userId->equals($round->userId())
                && $source->equals($round->source())
            ) {
                return $round;
            }
        }

        return null;
    }

    public function findForUserForUpdate(
        ReadingRoundId $readingRoundId,
        UserId $userId
    ): ?ReadingRound {
        return $this->findForUser($readingRoundId, $userId);
    }

    public function findAllForUserAndWork(
        UserId $userId,
        WorkId $workId
    ): array {
        return array_values(array_filter(
            $this->rounds,
            static fn (ReadingRound $round): bool =>
                $userId->equals($round->userId())
                && $workId->equals($round->workId())
        ));
    }

    public function replaceIfVersionMatches(
        UserId $authenticatedUserId,
        ReadingRound $replacement,
        ReadingRoundVersion $expectedVersion,
        ReadingRoundLifecycle $expectedLifecycle
    ): bool {
        $this->rounds[$replacement->id()->value()] = $replacement;
        return true;
    }

    public function deleteHistoricalIfVersionMatches(
        UserId $authenticatedUserId,
        ReadingRoundId $readingRoundId,
        ReadingRoundVersion $expectedVersion
    ): bool {
        unset($this->rounds[$readingRoundId->value()]);
        return true;
    }
}

final class StartReadingServicesTest extends TestCase
{
    private const STARTED_AT = "2026-08-16T10:00:00+00:00";

    public function testLibraryItemFlowDerivesWorkFromAuthorizedSource(): void
    {
        $user = new UserId("user-x");
        [$service, $rounds, $items, $editions, $memberships] =
            $this->libraryFixture($user);
        $library = new LibraryId("library-a");
        $edition = new Edition(
            new EditionId("edition-e"),
            new WorkId("work-from-item")
        );
        $item = Item::active(new ItemId("item-a"), $library, $edition->id());
        $items->add($item);
        $editions->add($edition);
        $memberships->add($this->membership(
            $library,
            $user,
            UseAccess::Direct
        ));

        $round = $service->start(
            $library,
            $item->id(),
            ReadingDate::exact(2026, 8, 16)
        );

        self::assertSame("work-from-item", $round->workId()->value());
        self::assertSame("item-a", $round->source()->itemId()?->value());
        self::assertCount(1, $rounds->rounds);
    }

    public function testLibraryItemFlowRejectsNonDirectAndWrongContext(): void
    {
        $user = new UserId("user-x");
        [$service, $rounds, $items, $editions, $memberships] =
            $this->libraryFixture($user);
        $libraryA = new LibraryId("library-a");
        $libraryB = new LibraryId("library-b");
        $edition = new Edition(new EditionId("edition-e"), new WorkId("work-w"));
        $item = Item::active(new ItemId("item-a"), $libraryA, $edition->id());
        $items->add($item);
        $editions->add($edition);
        $memberships->add($this->membership(
            $libraryA,
            $user,
            UseAccess::Borrow
        ));

        foreach ([$libraryA, $libraryB] as $contextLibrary) {
            try {
                $service->start(
                    $contextLibrary,
                    $item->id(),
                    ReadingDate::exact(2026, 8, 16)
                );
                self::fail("Unavailable Item source was accepted.");
            } catch (ReadingSourceUnavailable) {
                self::assertCount(0, $rounds->rounds);
            }
        }
    }

    public function testMissingEditionLeavesNoRound(): void
    {
        $user = new UserId("user-x");
        [$service, $rounds, $items, , $memberships] =
            $this->libraryFixture($user);
        $library = new LibraryId("library-a");
        $item = Item::active(
            new ItemId("item-a"),
            $library,
            new EditionId("missing-edition")
        );
        $items->add($item);
        $memberships->add($this->membership(
            $library,
            $user,
            UseAccess::Direct
        ));

        try {
            $service->start(
                $library,
                $item->id(),
                ReadingDate::exact(2026, 8, 16)
            );
            self::fail("Item without Edition was accepted.");
        } catch (ReadingSourceUnavailable) {
            self::assertCount(0, $rounds->rounds);
        }
    }

    public function testMismatchedItemAndEditionCannotCreateRound(): void
    {
        $user = new UserId("user-x");
        $rounds = new ReadingInMemoryRoundRepository();
        $service = $this->creator(
            new ControllableAuthenticatedUser($user),
            $rounds
        );
        $item = Item::active(
            new ItemId("item-a"),
            new LibraryId("library-a"),
            new EditionId("edition-a")
        );
        $wrongEdition = new Edition(
            new EditionId("edition-b"),
            new WorkId("work-b")
        );

        try {
            $service->createFromLibraryItem(
                $item,
                $wrongEdition,
                ReadingDate::exact(2026, 8, 16)
            );
            self::fail("Mismatched Item and Edition were accepted.");
        } catch (ReadingSourceUnavailable) {
            self::assertCount(0, $rounds->rounds);
        }
    }

    public function testExternalLoanFlowDerivesWorkWithoutLibraryContext(): void
    {
        $user = new UserId("user-x");
        [$service, $rounds, $loans] = $this->externalLoanFixture($user);
        $loan = $this->loan($user);
        $loans->add($loan);

        $round = $service->start(
            $loan->id(),
            ReadingDate::exact(2026, 8, 16)
        );

        self::assertSame("work-from-loan", $round->workId()->value());
        self::assertSame(
            "loan-l",
            $round->source()->externalLoanId()?->value()
        );
        self::assertCount(1, $rounds->rounds);
    }

    public function testExternalLoanFlowRejectsForeignAndUnknownLoan(): void
    {
        $owner = new UserId("user-x");
        [$service, $rounds, $loans, $actor] =
            $this->externalLoanFixture($owner);
        $foreignUser = new UserId("user-y");
        $active = $this->loan($owner);
        $loans->add($active);

        $actor->authenticateAs($foreignUser);

        try {
            $service->start(
                $active->id(),
                ReadingDate::exact(2026, 8, 16)
            );
            self::fail("Foreign loan was accepted.");
        } catch (ReadingSourceUnavailable) {
            self::assertCount(0, $rounds->rounds);
        }

        $actor->authenticateAs($owner);

        try {
            $service->start(
                new ExternalLoanId("unknown-loan"),
                ReadingDate::exact(2026, 8, 16)
            );
            self::fail("Unknown loan was accepted.");
        } catch (ReadingSourceUnavailable) {
            self::assertCount(0, $rounds->rounds);
        }
    }

    public function testForeignLoanCannotCreateRoundThroughInternalCreator(): void
    {
        $owner = new UserId("user-x");
        $rounds = new ReadingInMemoryRoundRepository();
        $service = $this->creator(
            new ControllableAuthenticatedUser(new UserId("user-y")),
            $rounds
        );

        try {
            $service->createFromExternalLoan(
                $this->loan($owner),
                ReadingDate::exact(2026, 8, 16)
            );
            self::fail("Foreign External Loan was accepted by creator.");
        } catch (ReadingSourceUnavailable) {
            self::assertCount(0, $rounds->rounds);
        }
    }

    public function testOwnedReadRejectsForeignRoundFromFaultyAdapter(): void
    {
        $round = ReadingRound::active(
            new ReadingRoundId("round-x"),
            new UserId("user-x"),
            new WorkId("work-w"),
            ReadingSource::libraryItem(new ItemId("item-a")),
            ReadingDate::exact(2026, 8, 16),
            new DateTimeImmutable(self::STARTED_AT)
        );
        $faultyRepository = new class($round) implements ReadingRoundRepository {
            public function __construct(private ReadingRound $round)
            {
            }

            public function findForUser(
                ReadingRoundId $readingRoundId,
                UserId $userId
            ): ?ReadingRound {
                return $this->round;
            }

            public function findActiveForUserAndSource(
                UserId $userId,
                ReadingSource $source
            ): ?ReadingRound {
                return $this->round;
            }

            public function findForUserForUpdate(
                ReadingRoundId $readingRoundId,
                UserId $userId
            ): ?ReadingRound {
                return $this->round;
            }

            public function findAllForUserAndWork(
                UserId $userId,
                WorkId $workId
            ): array {
                return [$this->round];
            }
        };

        self::assertNull((new GetOwnedReadingRoundService(
            new ControllableAuthenticatedUser(new UserId("user-y")),
            $faultyRepository
        ))->get($round->id()));
    }

    private function libraryFixture(UserId $userId): array
    {
        $items = new ReadingInMemoryItemRepository();
        $editions = new ReadingInMemoryEditionRepository();
        $memberships = new ReadingInMemoryMembershipRepository();
        $rounds = new ReadingInMemoryRoundRepository();
        $actor = new ControllableAuthenticatedUser($userId);
        $access = new GetAccessibleLibraryItemService(
            $actor,
            $items,
            new LibraryAccessService(
                $memberships,
                new LibraryAuthorizationPolicy()
            )
        );

        return [
            new StartReadingFromLibraryItemService(
                $access,
                $editions,
                $this->creator($actor, $rounds)
            ),
            $rounds,
            $items,
            $editions,
            $memberships,
        ];
    }

    private function externalLoanFixture(UserId $userId): array
    {
        $loans = new ReadingInMemoryExternalLoanRepository();
        $rounds = new ReadingInMemoryRoundRepository();
        $actor = new ControllableAuthenticatedUser($userId);

        return [
            new StartReadingFromExternalLoanService(
                new GetOwnedExternalLoanService($actor, $loans),
                $this->creator($actor, $rounds)
            ),
            $rounds,
            $loans,
            $actor,
        ];
    }

    private function membership(
        LibraryId $libraryId,
        UserId $userId,
        UseAccess $useAccess
    ): LibraryMembershipAssignment {
        return new LibraryMembershipAssignment(
            $libraryId,
            $userId,
            new LibraryMembership(
                ManagementRole::Member,
                $useAccess,
                MembershipStatus::Active
            )
        );
    }

    private function creator(
        ControllableAuthenticatedUser $actor,
        ReadingInMemoryRoundRepository $rounds
    ): CreateActiveReadingRoundService {
        $nextReading = $this->createStub(WritableNextReadingRepository::class);
        $nextReading->method("lockForUser")->willReturnCallback(
            static fn (UserId $userId): NextReadingList => NextReadingList::empty($userId)
        );
        $transactions = new class implements TransactionManager {
            public function run(callable $operation): mixed { return $operation(); }
        };
        $clock = new SystemReadingRoundClock();

        return new CreateActiveReadingRoundService(
            $actor,
            $rounds,
            new OpaqueReadingRoundIdGenerator(),
            $clock,
            $transactions,
            new ConsumeNextReadingAfterStartService(
                $nextReading,
                new SystemNextReadingClock()
            )
        );
    }

    private function loan(UserId $userId): ExternalLoan
    {
        return ExternalLoan::active(
            new ExternalLoanId("loan-l"),
            $userId,
            new WorkId("work-from-loan"),
            new DateTimeImmutable(self::STARTED_AT)
        );
    }
}
