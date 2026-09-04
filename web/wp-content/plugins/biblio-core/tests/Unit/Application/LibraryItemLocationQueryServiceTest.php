<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\Read\LibraryItemLocationQueryService;
use Biblio\Core\Application\Library\{ActorLibraryContext,ActorLibraryContextRepository,LibraryContextQueryService};
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\{ItemId,LocationId,LocationRepository};
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{Library,LibraryId,LibraryMembership,LibraryMembershipAssignment,LibraryName};
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use PHPUnit\Framework\TestCase;

final class LocationContextRepository implements ActorLibraryContextRepository
{
    public function __construct(private ActorLibraryContext $record) {}
    public function findForActor(LibraryId $libraryId, UserId $actorId): ?ActorLibraryContext
    {
        return $this->record->library()->id()->equals($libraryId)
            && $this->record->membership()->userId()->equals($actorId) ? $this->record : null;
    }
    public function listForActor(UserId $actorId): array { return []; }
}

final class RecordingLocationRepository implements LocationRepository
{
    public int $calls = 0;
    public function forLibrary(LibraryId $libraryId): array { ++$this->calls; return []; }
    public function forItems(LibraryId $libraryId, array $itemIds): array { ++$this->calls; return []; }
    public function itemIdsForLocations(LibraryId $libraryId, array $locationIds): array { ++$this->calls; return []; }
}

final class LibraryItemLocationQueryServiceTest extends TestCase
{
    public function testEveryReadRequiresAuthorizedLibraryContextBeforeRepositoryAccess(): void
    {
        $actor = new UserId("actor");
        $libraryId = new LibraryId("library-a");
        $record = new ActorLibraryContext(
            Library::privateLibrary($libraryId, new LibraryName("A")),
            new LibraryMembershipAssignment($libraryId, $actor, LibraryMembership::owner()),
            true
        );
        $repository = new RecordingLocationRepository();
        $service = new LibraryItemLocationQueryService(
            new LibraryContextQueryService(
                new ControllableAuthenticatedUser($actor),
                new LocationContextRepository($record),
                new LibraryAuthorizationPolicy()
            ),
            $repository
        );

        $service->locations($libraryId);
        $service->locationsForItems($libraryId, [new ItemId("item-a")]);
        $service->itemIdsForLocations($libraryId, [new LocationId("location-a")]);
        self::assertSame(3, $repository->calls);

        foreach (["locations", "locationsForItems", "itemIdsForLocations"] as $method) {
            try {
                match ($method) {
                    "locations" => $service->locations(new LibraryId("foreign")),
                    "locationsForItems" => $service->locationsForItems(new LibraryId("foreign"), [new ItemId("item-a")]),
                    default => $service->itemIdsForLocations(new LibraryId("foreign"), [new LocationId("location-a")]),
                };
                self::fail("Foreign Location read was exposed.");
            } catch (AuthorizationException) {
                self::assertSame(3, $repository->calls);
            }
        }
    }
}
