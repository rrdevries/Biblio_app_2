<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\Read\LibraryItemArchiveQueryService;
use Biblio\Core\Application\Library\{ActorLibraryContext,ActorLibraryContextRepository,LibraryContextQueryService};
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\{Item,ItemArchivePeriod,ItemArchiveReason,ItemArchiveRepository,ItemId,ItemRepository,ItemVersion};
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{Library,LibraryId,LibraryMembership,LibraryMembershipAssignment};
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ArchiveQueryContexts implements ActorLibraryContextRepository
{
    public function __construct(private ActorLibraryContext $record) {}
    public function listForActor(UserId $actorId): array { return [$this->record]; }
    public function findForActor(LibraryId $libraryId, UserId $actorId): ?ActorLibraryContext
    { return $this->record->library()->id()->equals($libraryId) && $this->record->membership()->userId()->equals($actorId) ? $this->record : null; }
}

final class ArchiveQueryItems implements ItemRepository
{
    public int $batchCalls = 0;
    public function __construct(private Item $item) {}
    public function findInLibrary(ItemId $itemId, LibraryId $libraryId): ?Item { return null; }
    public function findManyInLibrary(LibraryId $libraryId, array $itemIds): array
    { ++$this->batchCalls; $result = []; foreach ($itemIds as $id) { $result[$id->value()] = $id->equals($this->item->id()) ? $this->item : null; } return $result; }
}

final class ArchiveQueryPeriods implements ItemArchiveRepository
{
    public int $batchCalls = 0;
    public function __construct(private ItemArchivePeriod $period) {}
    public function periodsForItems(LibraryId $libraryId, array $itemIds): array
    { ++$this->batchCalls; $result = []; foreach ($itemIds as $id) { $result[$id->value()] = $id->equals($this->period->itemId()) ? [$this->period] : []; } return $result; }
}

final class LibraryItemArchiveQueryServiceTest extends TestCase
{
    public function testAuthorizedReadsAreLibraryScopedAndBatchFirst(): void
    {
        $libraryId = new LibraryId("library-a"); $userId = new UserId("user-a"); $itemId = new ItemId("item-a");
        $item = Item::active($itemId, $libraryId, new \Biblio\Core\Catalog\EditionId("edition-a"))->archive();
        $items = new ArchiveQueryItems($item);
        $archives = new ArchiveQueryPeriods(new ItemArchivePeriod($libraryId, $itemId, $item->version(), ItemArchiveReason::Sold, new DateTimeImmutable("2026-09-04 10:00:00.123456+00:00")));
        $service = new LibraryItemArchiveQueryService($this->contexts($libraryId, $userId), $items, $archives);

        self::assertSame($item, $service->items($libraryId, [$itemId, new ItemId("missing")])["item-a"]);
        self::assertCount(1, $service->periods($libraryId, [$itemId])["item-a"]);
        self::assertSame(1, $items->batchCalls);
        self::assertSame(1, $archives->batchCalls);
    }

    public function testForeignLibraryIsRejectedBeforeRepositoryAccess(): void
    {
        $libraryId = new LibraryId("library-a"); $userId = new UserId("user-a"); $itemId = new ItemId("item-a");
        $item = Item::active($itemId, $libraryId, new \Biblio\Core\Catalog\EditionId("edition-a"));
        $items = new ArchiveQueryItems($item);
        $archives = new ArchiveQueryPeriods(new ItemArchivePeriod($libraryId, $itemId, new ItemVersion(2), ItemArchiveReason::Lost, new DateTimeImmutable("2026-09-04 10:00:00+00:00")));
        $service = new LibraryItemArchiveQueryService($this->contexts($libraryId, $userId), $items, $archives);

        try { $service->items(new LibraryId("foreign"), [$itemId]); self::fail("Foreign Library was exposed."); }
        catch (AuthorizationException) { self::assertSame(0, $items->batchCalls); self::assertSame(0, $archives->batchCalls); }
    }

    private function contexts(LibraryId $libraryId, UserId $userId): LibraryContextQueryService
    {
        $library = Library::privateLibrary($libraryId);
        $assignment = new LibraryMembershipAssignment($libraryId, $userId, LibraryMembership::owner());
        return new LibraryContextQueryService(new ControllableAuthenticatedUser($userId), new ArchiveQueryContexts(new ActorLibraryContext($library, $assignment, true)), new LibraryAuthorizationPolicy());
    }
}
