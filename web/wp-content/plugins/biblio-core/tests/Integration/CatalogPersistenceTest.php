<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;

final class CatalogPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testWorkAndEditionRoundTripPlatformWide(): void
    {
        $work = new Work(new WorkId("work-w"), "Platform-wide Work");
        $edition = new Edition(new EditionId("edition-e"), $work->id());

        $this->workRepository()->add($work);
        $this->editionRepository()->add($edition);
        $storedWork = $this->workRepository()->find($work->id());
        $storedEdition = $this->editionRepository()->find($edition->id());

        self::assertNotNull($storedWork);
        self::assertTrue($work->id()->equals($storedWork->id()));
        self::assertSame("Platform-wide Work", $storedWork->title());
        self::assertNotNull($storedEdition);
        self::assertTrue($edition->id()->equals($storedEdition->id()));
        self::assertTrue($work->id()->equals($storedEdition->workId()));
    }

    public function testTwoLibrariesCanUseSamePlatformEdition(): void
    {
        [$libraryA, $libraryB, $editionId] = $this->sharedCatalogFixture();
        $itemA = Item::active(new ItemId("item-a"), $libraryA, $editionId);
        $itemB = Item::active(new ItemId("item-b"), $libraryB, $editionId);
        $repository = $this->itemRepository();

        $repository->add($itemA);
        $repository->add($itemB);

        $storedA = $repository->findInLibrary($itemA->id(), $libraryA);
        $storedB = $repository->findInLibrary($itemB->id(), $libraryB);

        self::assertNotNull($storedA);
        self::assertNotNull($storedB);
        self::assertTrue($editionId->equals($storedA->editionId()));
        self::assertTrue($editionId->equals($storedB->editionId()));
        self::assertSame(ItemStatus::Active, $storedA->status());
        self::assertSame(ItemStatus::Active, $storedB->status());
        self::assertNull($repository->findInLibrary($itemA->id(), $libraryB));
        self::assertNull($repository->findInLibrary($itemB->id(), $libraryA));
        self::assertSame(1, $this->tableCount($this->tableNames->works()));
        self::assertSame(1, $this->tableCount($this->tableNames->editions()));
        self::assertSame(2, $this->tableCount($this->tableNames->items()));
    }

    public function testEditionWithUnknownWorkIsRejected(): void
    {
        try {
            $this->editionRepository()->add(new Edition(
                new EditionId("edition-e"),
                new WorkId("missing-work")
            ));
            self::fail("Edition without Work was accepted.");
        } catch (PersistenceException) {
            self::assertSame(0, $this->tableCount(
                $this->tableNames->editions()
            ));
        }
    }

    public function testItemWithUnknownEditionIsRejected(): void
    {
        $libraryId = new LibraryId("library-a");
        $this->libraryRepository()->add(Library::privateLibrary($libraryId));

        try {
            $this->itemRepository()->add(Item::active(
                new ItemId("item-a"),
                $libraryId,
                new EditionId("missing-edition")
            ));
            self::fail("Item without Edition was accepted.");
        } catch (PersistenceException) {
            self::assertSame(0, $this->tableCount($this->tableNames->items()));
        }
    }

    public function testItemWithUnknownLibraryIsRejected(): void
    {
        $work = new Work(new WorkId("work-w"), "Work");
        $edition = new Edition(new EditionId("edition-e"), $work->id());
        $this->workRepository()->add($work);
        $this->editionRepository()->add($edition);

        try {
            $this->itemRepository()->add(Item::active(
                new ItemId("item-a"),
                new LibraryId("missing-library"),
                $edition->id()
            ));
            self::fail("Item without Library was accepted.");
        } catch (PersistenceException) {
            self::assertSame(0, $this->tableCount($this->tableNames->items()));
        }
    }

    public function testCatalogIdentifiersRemainUnique(): void
    {
        [$libraryA, $libraryB, $editionId] = $this->sharedCatalogFixture();

        try {
            $this->workRepository()->add(new Work(
                new WorkId("work-w"),
                "Duplicate Work"
            ));
            self::fail("Duplicate Work ID was accepted.");
        } catch (PersistenceException) {
            self::assertSame(1, $this->tableCount($this->tableNames->works()));
        }

        try {
            $this->editionRepository()->add(new Edition(
                new EditionId("edition-e"),
                new WorkId("work-w")
            ));
            self::fail("Duplicate Edition ID was accepted.");
        } catch (PersistenceException) {
            self::assertSame(1, $this->tableCount(
                $this->tableNames->editions()
            ));
        }

        $repository = $this->itemRepository();
        $repository->add(Item::active(
            new ItemId("item-shared-id"),
            $libraryA,
            $editionId
        ));

        try {
            $repository->add(Item::active(
                new ItemId("item-shared-id"),
                $libraryB,
                $editionId
            ));
            self::fail("Duplicate Item ID was accepted.");
        } catch (PersistenceException) {
            self::assertSame(1, $this->tableCount($this->tableNames->items()));
        }
    }

    private function sharedCatalogFixture(): array
    {
        $libraryA = new LibraryId("library-a");
        $libraryB = new LibraryId("library-b");
        $this->libraryRepository()->add(Library::privateLibrary($libraryA));
        $this->libraryRepository()->add(Library::privateLibrary($libraryB));
        $work = new Work(new WorkId("work-w"), "Shared Work");
        $edition = new Edition(new EditionId("edition-e"), $work->id());
        $this->workRepository()->add($work);
        $this->editionRepository()->add($edition);

        return [$libraryA, $libraryB, $edition->id()];
    }

    private function workRepository(): WpdbWorkRepository
    {
        return new WpdbWorkRepository($this->database, $this->tableNames);
    }

    private function editionRepository(): WpdbEditionRepository
    {
        return new WpdbEditionRepository($this->database, $this->tableNames);
    }

    private function itemRepository(): WpdbItemRepository
    {
        return new WpdbItemRepository($this->database, $this->tableNames);
    }

    private function libraryRepository(): WpdbLibraryRepository
    {
        return new WpdbLibraryRepository($this->database, $this->tableNames);
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }
}
