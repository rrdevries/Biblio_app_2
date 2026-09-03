<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\Read\LibraryItemMetadataQueryService;
use Biblio\Core\Application\Library\{ActorLibraryContext,ActorLibraryContextRepository,LibraryContextQueryService};
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\{InventoryNumber,ItemId,LibraryItemMetadataRepository};
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{Library,LibraryId,LibraryMembership,LibraryMembershipAssignment,LibraryName};
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use PHPUnit\Framework\TestCase;

final class ItemMetadataContextRepository implements ActorLibraryContextRepository
{
    public function __construct(private ActorLibraryContext $record) {}

    public function findForActor(LibraryId $libraryId, UserId $actorId): ?ActorLibraryContext
    {
        return $this->record->library()->id()->equals($libraryId)
            && $this->record->membership()->userId()->equals($actorId)
                ? $this->record
                : null;
    }

    public function listForActor(UserId $actorId): array { return []; }
}

final class RecordingItemMetadataRepository implements LibraryItemMetadataRepository
{
    public int $calls = 0;

    public function inventoryNumbersForItems(LibraryId $libraryId, array $itemIds): array
    {
        ++$this->calls;

        return [$itemIds[0]->value() => new InventoryNumber("INV-1")];
    }
}

final class LibraryItemMetadataQueryServiceTest extends TestCase
{
    public function testInventoryReadRequiresAuthorizedLibraryContext(): void
    {
        $actor = new UserId("actor");
        $libraryId = new LibraryId("library-a");
        $record = new ActorLibraryContext(
            Library::privateLibrary($libraryId, new LibraryName("A")),
            new LibraryMembershipAssignment($libraryId, $actor, LibraryMembership::owner()),
            true
        );
        $repository = new RecordingItemMetadataRepository();
        $service = new LibraryItemMetadataQueryService(
            new LibraryContextQueryService(
                new ControllableAuthenticatedUser($actor),
                new ItemMetadataContextRepository($record),
                new LibraryAuthorizationPolicy()
            ),
            $repository
        );

        self::assertSame("INV-1", $service->inventoryNumbers($libraryId, [new ItemId("item-1")])["item-1"]?->value());
        self::assertSame(1, $repository->calls);

        try {
            $service->inventoryNumbers(new LibraryId("foreign"), [new ItemId("item-1")]);
            self::fail("Foreign inventory metadata was exposed.");
        } catch (AuthorizationException) {
            self::assertSame(1, $repository->calls);
        }
    }
}
