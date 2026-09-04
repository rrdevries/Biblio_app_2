<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\{ItemArchiveActivity,ItemArchiveNotAvailable,ManageLibraryItemArchiveService};
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Audit\{ActivityActorSnapshot,ActivityEntityIdentity,ActivityEvent,ActivityEventAppender,ActivityEventFactory,ActivityEventId,ActivityEventKey,ActivityEventSource,ActivityLabel};
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\{EditionId,Item,ItemArchiveClock,ItemArchivePeriod,ItemArchiveReason,ItemArchiveStale,ItemArchiveTransitionUnavailable,ItemId,ItemStatus,ItemVersion,WritableItemArchiveRepository};
use Biblio\Core\Collections\CollectionMembershipArchivePort;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{LibraryId,LibraryMembership,LibraryMembershipAssignment,LibraryMembershipRepository,ManagementRole,MembershipStatus,UseAccess};
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ArchiveApplicationRepository implements WritableItemArchiveRepository, CollectionMembershipArchivePort
{
    /** @var list<ItemArchivePeriod> */ public array $periods = [];
    public int $collectionDeactivations = 0;
    public function __construct(public Item $item) {}
    public function findItemForUpdate(ItemId $itemId, LibraryId $libraryId): ?Item
    { return $itemId->equals($this->item->id()) && $libraryId->equals($this->item->libraryId()) ? $this->item : null; }
    public function saveArchive(Item $replacement, ItemVersion $expectedVersion, ItemArchivePeriod $period): bool
    { if (!$this->item->version()->equals($expectedVersion)) { return false; } $this->item = $replacement; $this->periods[] = $period; return true; }
    public function saveRestore(Item $replacement, ItemVersion $expectedVersion, ItemArchivePeriod $period): bool
    { if (!$this->item->version()->equals($expectedVersion)) { return false; } $this->item = $replacement; $this->periods[array_key_last($this->periods)] = $period; return true; }
    public function openPeriod(ItemId $itemId, LibraryId $libraryId): ?ItemArchivePeriod
    { foreach (array_reverse($this->periods) as $period) { if ($period->isOpen()) { return $period; } } return null; }
    public function periodsForItems(LibraryId $libraryId, array $itemIds): array
    { $result = []; foreach ($itemIds as $id) { $result[$id->value()] = array_values(array_filter($this->periods, fn (ItemArchivePeriod $period): bool => $period->itemId()->equals($id) && $period->libraryId()->equals($libraryId))); } return $result; }
    public function deactivateForArchivedItem(LibraryId $libraryId, ItemId $itemId, DateTimeImmutable $archivedAt): void
    { ++$this->collectionDeactivations; }
}

final class ArchiveApplicationMemberships implements LibraryMembershipRepository
{
    public function __construct(private ?LibraryMembershipAssignment $assignment) {}
    public function findFor(LibraryId $libraryId, UserId $userId): ?LibraryMembershipAssignment
    { return $this->assignment !== null && $this->assignment->libraryId()->equals($libraryId) && $this->assignment->userId()->equals($userId) ? $this->assignment : null; }
}

final class ArchiveApplicationClock implements ItemArchiveClock
{
    public function __construct(private DateTimeImmutable $instant) {}
    public function now(): DateTimeImmutable { return $this->instant; }
}

final class ArchiveApplicationEvents implements ActivityEventAppender
{
    /** @var list<ActivityEvent> */ public array $events = [];
    public function append(ActivityEvent $event): void { $this->events[] = $event; }
}

final class ArchiveApplicationTransactions implements TransactionManager
{
    public int $calls = 0;
    public function run(callable $operation): mixed { ++$this->calls; return $operation(); }
}

final class ArchiveApplicationEventFactory implements ActivityEventFactory
{
    public function create(UserId $actorId, LibraryId $libraryId, ActivityEventKey $eventKey, ActivityEntityIdentity $primaryEntity, array $relatedEntities, array $changes): ActivityEvent
    { return new ActivityEvent(new ActivityEventId("event-" . $eventKey->value()), $libraryId, new DateTimeImmutable("2026-09-04 10:00:00+00:00"), new ActivityActorSnapshot($actorId, new ActivityLabel("Actor")), new ActivityEventSource("core.item_archive"), $eventKey, $primaryEntity, $relatedEntities, $changes); }
}

final class ManageLibraryItemArchiveServiceTest extends TestCase
{
    public function testOwnerArchivesRestoresAndArchivesAgainWithSameIdentityAndHistory(): void
    {
        [$service, $repository, $events, $transactions, $item] = $this->fixture(ManagementRole::Owner);
        $archived = $service->archive($item->libraryId(), $item->id(), ItemArchiveReason::Sold, ItemVersion::initial());
        $restored = $service->restore($item->libraryId(), $item->id(), $archived->version());
        $again = $service->archive($item->libraryId(), $item->id(), ItemArchiveReason::Lost, $restored->version());

        self::assertSame(ItemStatus::Archived, $again->status());
        self::assertTrue($item->id()->equals($again->id()));
        self::assertSame(4, $again->version()->value());
        self::assertCount(2, $repository->periods);
        self::assertSame(2, $repository->collectionDeactivations);
        self::assertFalse($repository->periods[0]->isOpen());
        self::assertSame(ItemArchiveReason::Lost, $repository->periods[1]->reason());
        self::assertCount(3, $events->events);
        self::assertSame(3, $transactions->calls);
    }

    public function testIdenticalArchiveAndRestoreRetriesAreNoOpSuccesses(): void
    {
        [$service, , $events, , $item] = $this->fixture(ManagementRole::Owner);
        $archived = $service->archive($item->libraryId(), $item->id(), ItemArchiveReason::Donated, $item->version());
        $retry = $service->archive($item->libraryId(), $item->id(), ItemArchiveReason::Donated, $item->version());
        self::assertSame(2, $retry->version()->value());
        self::assertCount(1, $events->events);
        $restored = $service->restore($item->libraryId(), $item->id(), $archived->version());
        $restoreRetry = $service->restore($item->libraryId(), $item->id(), $archived->version());
        self::assertSame($restored->version()->value(), $restoreRetry->version()->value());
        self::assertCount(2, $events->events);
    }

    public function testStaleAndDivergentTransitionsAreTyped(): void
    {
        [$service, , , , $item] = $this->fixture(ManagementRole::Owner);
        try { $service->archive($item->libraryId(), $item->id(), ItemArchiveReason::Sold, new ItemVersion(9)); self::fail("Stale transition accepted."); }
        catch (ItemArchiveStale $exception) { self::assertSame(1, $exception->current()->version()->value()); }
        $service->archive($item->libraryId(), $item->id(), ItemArchiveReason::Sold, $item->version());
        $this->expectException(ItemArchiveTransitionUnavailable::class);
        $service->archive($item->libraryId(), $item->id(), ItemArchiveReason::Lost, $item->version());
    }

    public function testRestoreRejectsActiveItemWithInconsistentOpenPeriod(): void
    {
        [$service, $repository, , , $item] = $this->fixture(ManagementRole::Owner);
        $repository->periods[] = new ItemArchivePeriod(
            $item->libraryId(),
            $item->id(),
            new ItemVersion(2),
            ItemArchiveReason::Lost,
            new DateTimeImmutable("2026-09-04 09:00:00.000001+00:00")
        );

        $this->expectException(ItemArchiveTransitionUnavailable::class);
        $service->restore($item->libraryId(), $item->id(), $item->version());
    }

    public function testManagerIsAuthorizedButMemberAndForeignItemAreNonEnumerating(): void
    {
        [$manager, , , , $item] = $this->fixture(ManagementRole::Manager);
        self::assertSame(ItemStatus::Archived, $manager->archive($item->libraryId(), $item->id(), ItemArchiveReason::GivenAway, $item->version())->status());
        [$member] = $this->fixture(ManagementRole::Member);
        foreach ([[$member, $item->libraryId(), $item->id()], [$manager, $item->libraryId(), new ItemId("foreign")]] as [$service, $libraryId, $itemId]) {
            try { $service->archive($libraryId, $itemId, ItemArchiveReason::Lost, ItemVersion::initial()); self::fail("Unavailable Item was exposed."); }
            catch (ItemArchiveNotAvailable $exception) { self::assertSame("catalog_item_not_available", $exception->reason()->value); }
        }
    }

    /** @return array{ManageLibraryItemArchiveService,ArchiveApplicationRepository,ArchiveApplicationEvents,ArchiveApplicationTransactions,Item} */
    private function fixture(ManagementRole $role): array
    {
        $libraryId = new LibraryId("library-a"); $userId = new UserId("user-a");
        $item = Item::active(new ItemId("item-a"), $libraryId, new EditionId("edition-a"));
        $repository = new ArchiveApplicationRepository($item); $events = new ArchiveApplicationEvents(); $transactions = new ArchiveApplicationTransactions();
        $membership = new LibraryMembership($role, $role === ManagementRole::Owner ? UseAccess::Direct : UseAccess::ViewOnly, MembershipStatus::Active);
        $service = new ManageLibraryItemArchiveService(
            new ControllableAuthenticatedUser($userId),
            new LibraryAccessService(new ArchiveApplicationMemberships(new LibraryMembershipAssignment($libraryId, $userId, $membership)), new LibraryAuthorizationPolicy()),
            $repository,
            $repository,
            new ArchiveApplicationClock(new DateTimeImmutable("2026-09-04 10:11:12.123456+00:00")),
            new ItemArchiveActivity(new ArchiveApplicationEventFactory()),
            $events,
            $transactions
        );
        return [$service, $repository, $events, $transactions, $item];
    }
}
