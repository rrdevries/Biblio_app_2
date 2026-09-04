<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Catalog\{Edition,EditionId,Item,ItemId,LibraryLocation,LocationId,Work,WorkId};
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbEditionRepository,WpdbItemRepository,WpdbLibraryRepository,WpdbLocationRepository,WpdbWorkRepository};
use Biblio\Core\Library\{Library,LibraryId};

final class LocationPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testLocationAndOptionalItemRelationRoundTripAndReplace(): void
    {
        [$libraryA, , $edition] = $this->fixture();
        $locations = $this->locations();
        $first = new LibraryLocation(new LocationId("location-a"), $libraryA, "Kast A");
        $second = new LibraryLocation(new LocationId("location-b"), $libraryA, "Kast B");
        $locations->save($first);
        $locations->save($second);

        $items = new WpdbItemRepository($this->database, $this->tableNames);
        $item = Item::active(new ItemId("item-a"), $libraryA, $edition);
        $items->add($item);
        self::assertNull($items->findInLibrary($item->id(), $libraryA)?->locationId());

        $locations->assignToItem($libraryA, $item->id(), $first->id());
        self::assertSame("location-a", $items->findInLibrary($item->id(), $libraryA)?->locationId()?->value());
        $locations->assignToItem($libraryA, $item->id(), $second->id());
        self::assertSame("location-b", $items->findInLibrary($item->id(), $libraryA)?->locationId()?->value());
        $locations->assignToItem($libraryA, $item->id(), null);
        self::assertNull($items->findInLibrary($item->id(), $libraryA)?->locationId());
    }

    public function testLibraryScopedBatchReadsDoNotEnumerateForeignIds(): void
    {
        [$libraryA, $libraryB, $edition] = $this->fixture();
        $locations = $this->locations();
        $locationA = new LibraryLocation(new LocationId("location-a"), $libraryA, "Zelfde naam");
        $locationA2 = new LibraryLocation(new LocationId("location-a2"), $libraryA, "Zelfde naam");
        $locationB = new LibraryLocation(new LocationId("location-b"), $libraryB, "B");
        foreach ([$locationA, $locationA2, $locationB] as $location) { $locations->save($location); }
        $items = new WpdbItemRepository($this->database, $this->tableNames);
        $itemA = Item::active(new ItemId("item-a"), $libraryA, $edition, null, $locationA->id());
        $itemB = Item::active(new ItemId("item-b"), $libraryB, $edition, null, $locationB->id());
        $items->add($itemA);
        $items->add($itemB);

        self::assertCount(2, $locations->forLibrary($libraryA));
        self::assertSame(["item-a"], array_map(
            static fn (ItemId $id): string => $id->value(),
            $locations->itemIdsForLocations($libraryA, [$locationA->id(), $locationB->id()])["location-a"]
        ));
        self::assertSame([], $locations->itemIdsForLocations($libraryA, [$locationB->id()])["location-b"]);
        $byItem = $locations->forItems($libraryA, [$itemA->id(), $itemB->id()]);
        self::assertSame("location-a", $byItem["item-a"]?->id()->value());
        self::assertNull($byItem["item-b"]);
    }

    public function testCrossLibraryAndDanglingRelationsAreRejected(): void
    {
        [$libraryA, $libraryB, $edition] = $this->fixture();
        $locations = $this->locations();
        $foreign = new LibraryLocation(new LocationId("foreign-location"), $libraryB, "Foreign");
        $locations->save($foreign);
        $items = new WpdbItemRepository($this->database, $this->tableNames);

        foreach ([new LocationId("foreign-location"), new LocationId("missing-location")] as $locationId) {
            try {
                $items->add(Item::active(new ItemId("item-" . $locationId->value()), $libraryA, $edition, null, $locationId));
                self::fail("Invalid Item Location relation was accepted.");
            } catch (PersistenceException) {
                self::assertNull($items->findInLibrary(new ItemId("item-" . $locationId->value()), $libraryA));
            }
        }

        $validItem = Item::active(new ItemId("valid-item"), $libraryA, $edition);
        $items->add($validItem);
        foreach ([new LocationId("foreign-location"), new LocationId("missing-location")] as $locationId) {
            try {
                $locations->assignToItem($libraryA, $validItem->id(), $locationId);
                self::fail("Invalid replacement Location was accepted.");
            } catch (PersistenceException) {
                self::assertNull($items->findInLibrary($validItem->id(), $libraryA)?->locationId());
            }
        }
    }

    /** @return array{LibraryId,LibraryId,EditionId} */
    private function fixture(): array
    {
        $libraryA = new LibraryId("library-a");
        $libraryB = new LibraryId("library-b");
        $libraries = new WpdbLibraryRepository($this->database, $this->tableNames);
        $libraries->add(Library::privateLibrary($libraryA));
        $libraries->add(Library::privateLibrary($libraryB));
        $work = new Work(new WorkId("work-a"), "Work");
        (new WpdbWorkRepository($this->database, $this->tableNames))->add($work);
        $edition = new Edition(new EditionId("edition-a"), $work->id());
        (new WpdbEditionRepository($this->database, $this->tableNames))->add($edition);
        return [$libraryA, $libraryB, $edition->id()];
    }

    private function locations(): WpdbLocationRepository
    {
        return new WpdbLocationRepository($this->database, $this->tableNames);
    }
}
