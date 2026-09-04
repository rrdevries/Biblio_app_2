<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Catalog\{Edition,EditionId,Item,ItemArchivePeriod,ItemArchiveReason,ItemId,ItemStatus,ItemVersion,LibraryLocation,LocationId,Work,WorkId};
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbEditionRepository,WpdbItemArchiveRepository,WpdbItemRepository,WpdbLibraryRepository,WpdbLocationRepository,WpdbTransactionManager,WpdbWorkRepository};
use Biblio\Core\Library\{Library,LibraryId};
use DateTimeImmutable;

final class ItemArchivePersistenceTest extends PersistenceIntegrationTestCase
{
    public function testArchiveRestoreAndSecondArchivePreserveItemAndHistoryLosslessly(): void
    {
        [$libraryId, $item] = $this->fixture("a");
        $archives = new WpdbItemArchiveRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $firstTime = new DateTimeImmutable("2026-09-04 10:11:12.123456+00:00");
        $restoreTime = new DateTimeImmutable("2026-09-05 11:12:13.234567+00:00");
        $secondTime = new DateTimeImmutable("2026-09-06 12:13:14.345678+00:00");

        $transactions->run(function () use ($archives, $libraryId, $item, $firstTime): void {
            $locked = $archives->findItemForUpdate($item->id(), $libraryId);
            self::assertNotNull($locked);
            $replacement = $locked->archive();
            self::assertTrue($archives->saveArchive($replacement, $locked->version(), new ItemArchivePeriod($libraryId, $item->id(), $replacement->version(), ItemArchiveReason::Sold, $firstTime)));
        });
        $transactions->run(function () use ($archives, $libraryId, $item, $restoreTime): void {
            $locked = $archives->findItemForUpdate($item->id(), $libraryId);
            $open = $archives->openPeriod($item->id(), $libraryId);
            self::assertNotNull($locked);
            self::assertNotNull($open);
            $replacement = $locked->restore();
            self::assertTrue($archives->saveRestore($replacement, $locked->version(), new ItemArchivePeriod($libraryId, $item->id(), $open->archiveVersion(), $open->reason(), $open->archivedAt(), $replacement->version(), $restoreTime)));
        });
        $transactions->run(function () use ($archives, $libraryId, $item, $secondTime): void {
            $locked = $archives->findItemForUpdate($item->id(), $libraryId);
            self::assertNotNull($locked);
            $replacement = $locked->archive();
            self::assertTrue($archives->saveArchive($replacement, $locked->version(), new ItemArchivePeriod($libraryId, $item->id(), $replacement->version(), ItemArchiveReason::Donated, $secondTime)));
        });

        $stored = (new WpdbItemRepository($this->database, $this->tableNames))->findInLibrary($item->id(), $libraryId);
        $periods = $archives->periodsForItems($libraryId, [$item->id()])[$item->id()->value()];
        self::assertNotNull($stored);
        self::assertSame(ItemStatus::Archived, $stored->status());
        self::assertSame(4, $stored->version()->value());
        self::assertTrue($item->id()->equals($stored->id()));
        self::assertTrue($item->editionId()->equals($stored->editionId()));
        self::assertSame($item->locationId()?->value(), $stored->locationId()?->value());
        self::assertCount(2, $periods);
        self::assertSame(ItemArchiveReason::Sold, $periods[0]->reason());
        self::assertSame("123456", $periods[0]->archivedAt()->format("u"));
        self::assertSame("234567", $periods[0]->restoredAt()?->format("u"));
        self::assertSame(ItemArchiveReason::Donated, $periods[1]->reason());
        self::assertTrue($periods[1]->isOpen());
    }

    public function testBatchReadsAreDeterministicAndDoNotEnumerateForeignItems(): void
    {
        [$libraryA, $itemA] = $this->fixture("a");
        [$libraryB, $itemB] = $this->fixture("b");
        $items = new WpdbItemRepository($this->database, $this->tableNames);
        $batch = $items->findManyInLibrary($libraryA, [$itemB->id(), $itemA->id()]);

        self::assertSame(["item-b", "item-a"], array_keys($batch));
        self::assertNull($batch["item-b"]);
        self::assertNotNull($batch["item-a"]);
        $history = (new WpdbItemArchiveRepository($this->database, $this->tableNames))->periodsForItems($libraryA, [$itemB->id(), $itemA->id()]);
        self::assertSame([], $history["item-b"]);
        self::assertSame([], $history["item-a"]);
        self::assertNotSame($libraryA->value(), $libraryB->value());
    }

    public function testDatabaseRejectsDanglingAndCrossLibraryArchiveHistory(): void
    {
        [$libraryA, $itemA] = $this->fixture("a");
        [$libraryB] = $this->fixture("b");
        $previous = $this->database->suppress_errors(true);
        try {
            foreach ([["missing", $libraryA], [$itemA->id()->value(), $libraryB]] as [$itemId, $libraryId]) {
                $result = $this->database->insert($this->tableNames->itemArchivePeriods(), [
                    "library_id" => $libraryId->value(), "item_id" => $itemId,
                    "archive_version" => 2, "archive_reason" => "lost",
                    "archived_at" => "2026-09-04 10:00:00.000000",
                ]);
                self::assertFalse($result);
            }
        } finally {
            $this->database->suppress_errors($previous);
        }
    }

    /** @return array{LibraryId,Item} */
    private function fixture(string $suffix): array
    {
        $libraryId = new LibraryId("library-{$suffix}");
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(Library::privateLibrary($libraryId));
        $work = new Work(new WorkId("work-{$suffix}"), "Work {$suffix}");
        (new WpdbWorkRepository($this->database, $this->tableNames))->add($work);
        $edition = new Edition(new EditionId("edition-{$suffix}"), $work->id());
        (new WpdbEditionRepository($this->database, $this->tableNames))->add($edition);
        $location = new LibraryLocation(new LocationId("location-{$suffix}"), $libraryId, "Kast {$suffix}");
        (new WpdbLocationRepository($this->database, $this->tableNames))->save($location);
        $item = Item::active(new ItemId("item-{$suffix}"), $libraryId, $edition->id(), null, $location->id());
        (new WpdbItemRepository($this->database, $this->tableNames))->add($item);
        return [$libraryId, $item];
    }
}
