<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\Read\BibliographicMetadataQueryService;
use Biblio\Core\Catalog\{AlternateWorkTitle,CatalogRecordAlreadyExists,ContainmentPosition,Edition,EditionId,EditionIsbnMetadata,InventoryNumber,Isbn10,Isbn13,Item,ItemId,Work,WorkContainment,WorkId};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbBibliographicMetadataRepository,WpdbEditionRepository,WpdbItemRepository,WpdbLibraryRepository,WpdbWorkRepository};
use Biblio\Core\Library\{Library,LibraryId,LibraryName};

final class SearchMetadataPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testAlternateTitlesAndContainmentsRoundTripInOneQueryPerBatch(): void
    {
        $this->seedWorks("bundle", "part-1", "part-2", "empty");
        $repository = new WpdbBibliographicMetadataRepository($this->database, $this->tableNames);
        $repository->addAlternateTitle(new AlternateWorkTitle(new WorkId("bundle"), "  Omnibus  "));
        $repository->addAlternateTitle(new AlternateWorkTitle(new WorkId("bundle"), "Collected works"));
        $repository->addContainment(new WorkContainment(new WorkId("bundle"), new WorkId("part-2"), new ContainmentPosition(2)));
        $repository->addContainment(new WorkContainment(new WorkId("bundle"), new WorkId("part-1"), new ContainmentPosition(1)));
        $query = new BibliographicMetadataQueryService($repository);

        $before = $this->database->num_queries;
        $titles = $query->alternateTitles([new WorkId("bundle"), new WorkId("empty")]);
        self::assertSame(1, $this->database->num_queries - $before);
        self::assertSame(["Collected works", "  Omnibus  "], array_map(static fn (AlternateWorkTitle $title): string => $title->value(), $titles["bundle"]));
        self::assertSame([], $titles["empty"]);

        $before = $this->database->num_queries;
        $contained = $query->containedWorks([new WorkId("bundle")]);
        self::assertSame(1, $this->database->num_queries - $before);
        self::assertSame(["part-1", "part-2"], array_map(static fn (WorkContainment $relation): string => $relation->containedWorkId()->value(), $contained["bundle"]));

        $before = $this->database->num_queries;
        $parents = $query->parentWorks([new WorkId("part-1"), new WorkId("part-2")]);
        self::assertSame(1, $this->database->num_queries - $before);
        self::assertSame("bundle", $parents["part-1"][0]->parentWorkId()->value());
    }

    public function testRelationshipIntegrityRejectsDuplicatesDanglingAndCycles(): void
    {
        $this->seedWorks("a", "b", "c");
        $repository = new WpdbBibliographicMetadataRepository($this->database, $this->tableNames);
        $title = new AlternateWorkTitle(new WorkId("a"), "Alias");
        $repository->addAlternateTitle($title);
        $repository->addContainment(new WorkContainment(new WorkId("a"), new WorkId("b"), new ContainmentPosition(1)));
        $repository->addContainment(new WorkContainment(new WorkId("b"), new WorkId("c"), new ContainmentPosition(1)));

        foreach ([
            static fn () => $repository->addAlternateTitle(new AlternateWorkTitle(new WorkId("a"), " alias ")),
            static fn () => $repository->addContainment(new WorkContainment(new WorkId("a"), new WorkId("b"), new ContainmentPosition(2))),
        ] as $duplicate) {
            try {
                $duplicate();
                self::fail("Duplicate metadata was accepted.");
            } catch (CatalogRecordAlreadyExists) {
                self::addToAssertionCount(1);
            }
        }

        try {
            $repository->addAlternateTitle(new AlternateWorkTitle(new WorkId("missing"), "Dangling"));
            self::fail("Dangling alternate title was accepted.");
        } catch (PersistenceException) {
            self::addToAssertionCount(1);
        }

        try {
            $repository->addContainment(new WorkContainment(new WorkId("a"), new WorkId("missing"), new ContainmentPosition(2)));
            self::fail("Dangling containment was accepted.");
        } catch (PersistenceException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(ValidationException::class);
        $repository->addContainment(new WorkContainment(new WorkId("c"), new WorkId("a"), new ContainmentPosition(1)));
    }

    public function testEditionIsbnStatesAndBatchIndexesRoundTrip(): void
    {
        $this->seedWorks("work-1", "work-2");
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $metadata = new WpdbBibliographicMetadataRepository($this->database, $this->tableNames);
        $editions->add(new Edition(new EditionId("unknown"), new WorkId("work-1")));
        $editions->add(new Edition(new EditionId("none"), new WorkId("work-1"), EditionIsbnMetadata::withoutIsbn()));
        foreach (["known-1", "known-2"] as $id) {
            $editions->add(new Edition(new EditionId($id), new WorkId("work-2"), EditionIsbnMetadata::identified(new Isbn10("0306406152"), new Isbn13("9780306406157"))));
        }

        self::assertFalse($editions->find(new EditionId("unknown"))?->isbnMetadata()->isExplicitlyWithoutIsbn());
        self::assertTrue($editions->find(new EditionId("none"))?->isbnMetadata()->isExplicitlyWithoutIsbn());
        $before = $this->database->num_queries;
        $byWork = $metadata->editionsForWorks([new WorkId("work-1"), new WorkId("work-2")]);
        self::assertSame(1, $this->database->num_queries - $before);
        self::assertCount(2, $byWork["work-1"]);
        $before = $this->database->num_queries;
        $byIsbn = $metadata->editionsForIsbns([new Isbn10("0306406152"), new Isbn13("9780306406157")]);
        self::assertSame(1, $this->database->num_queries - $before);
        self::assertCount(2, $byIsbn["0306406152"]);
        self::assertCount(2, $byIsbn["9780306406157"]);
    }

    public function testInventoryNumberIsUniqueWithinLibraryAndReadsStayScoped(): void
    {
        $this->seedWorks("work-1");
        $edition = new Edition(new EditionId("edition-1"), new WorkId("work-1"));
        (new WpdbEditionRepository($this->database, $this->tableNames))->add($edition);
        $libraries = new WpdbLibraryRepository($this->database, $this->tableNames);
        $libraryA = new LibraryId("library-a");
        $libraryB = new LibraryId("library-b");
        $libraries->add(Library::privateLibrary($libraryA, new LibraryName("A")));
        $libraries->add(Library::privateLibrary($libraryB, new LibraryName("B")));
        $items = new WpdbItemRepository($this->database, $this->tableNames);
        $items->add(Item::active(new ItemId("a-1"), $libraryA, $edition->id(), new InventoryNumber("INV-1")));
        $items->add(Item::active(new ItemId("a-2"), $libraryA, $edition->id()));
        $items->add(Item::active(new ItemId("b-1"), $libraryB, $edition->id(), new InventoryNumber("INV-1")));

        try {
            $items->add(Item::active(new ItemId("a-3"), $libraryA, $edition->id(), new InventoryNumber("INV-1")));
            self::fail("Duplicate Library inventory number was accepted.");
        } catch (CatalogRecordAlreadyExists) {
            self::addToAssertionCount(1);
        }

        $before = $this->database->num_queries;
        $values = $items->inventoryNumbersForItems($libraryA, [new ItemId("a-1"), new ItemId("a-2"), new ItemId("b-1")]);
        self::assertSame(1, $this->database->num_queries - $before);
        self::assertSame("INV-1", $values["a-1"]?->value());
        self::assertNull($values["a-2"]);
        self::assertNull($values["b-1"]);
        self::assertSame("INV-1", $items->findInLibrary(new ItemId("a-1"), $libraryA)?->inventoryNumber()?->value());
    }

    public function testCentralMetadataCannotEnumerateItemsAndRelationsRestrictDeletion(): void
    {
        $this->seedWorks("parent", "contained");
        $repository = new WpdbBibliographicMetadataRepository($this->database, $this->tableNames);
        $repository->addAlternateTitle(new AlternateWorkTitle(new WorkId("parent"), "Alias"));
        $repository->addContainment(new WorkContainment(new WorkId("parent"), new WorkId("contained"), new ContainmentPosition(1)));

        foreach ([$this->tableNames->workAlternateTitles(), $this->tableNames->workContainments()] as $table) {
            $columns = $this->database->get_col($this->database->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
                DB_NAME,
                $table
            ));
            self::assertNotContains("library_id", $columns);
            self::assertNotContains("item_id", $columns);
            self::assertNotContains("user_id", $columns);
        }

        $previous = $this->database->suppress_errors(true);
        try {
            self::assertFalse($this->database->delete($this->tableNames->works(), ["work_id" => "parent"]));
            self::assertFalse($this->database->delete($this->tableNames->works(), ["work_id" => "contained"]));
        } finally {
            $this->database->suppress_errors($previous);
        }
    }

    private function seedWorks(string ...$ids): void
    {
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        foreach ($ids as $id) {
            $works->add(new Work(new WorkId($id), "Title {$id}"));
        }
    }
}
