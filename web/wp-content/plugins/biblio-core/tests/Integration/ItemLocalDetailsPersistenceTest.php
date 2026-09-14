<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\ItemLocalDetailsNotAvailable;
use Biblio\Core\Application\Catalog\ItemLocalDetailsRecorder;
use Biblio\Core\Catalog\{AcquisitionMethod,DustJacketState,Edition,EditionId,Iso4217Currency,Item,ItemAcquisitionDate,ItemCondition,ItemId,ItemLocalDetailsStale,ItemLocalDetailsState,ItemLocalDetailsVersion,PaidAmount,Work,WorkId};
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbEditionRepository,WpdbItemLocalDetailsRepository,WpdbItemRepository,WpdbLibraryRepository,WpdbTransactionManager,WpdbWorkRepository};
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\{Library,LibraryId,LibraryName};
use RuntimeException;

final class ItemLocalDetailsPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testRoundTripCasClearAndArchiveIndependence(): void
    {
        [$library, $item] = $this->fixture("a");
        $details = new WpdbItemLocalDetailsRepository($this->database, $this->tableNames);
        $recorder = new ItemLocalDetailsRecorder(
            new WpdbItemRepository($this->database, $this->tableNames),
            $details
        );
        $transactions = new WpdbTransactionManager($this->database);
        $state = new ItemLocalDetailsState(
            ItemCondition::ZeerGoed,
            new ItemAcquisitionDate(1998, 7),
            AcquisitionMethod::Received,
            "Boekenmarkt",
            new PaidAmount("12.3400", new Iso4217Currency("NLG")),
            true,
            "Schrijver A",
            "17/250",
            DustJacketState::Missing,
            true,
            "Collectie B",
            "Kaart ontbreekt"
        );

        $created = $transactions->run(
            fn () => $recorder->recordForLibrary($library, $item->id(), $state)
        );
        self::assertSame(1, $created?->version()->value());
        $stored = $details->find($library, $item->id());
        self::assertNotNull($stored);
        self::assertTrue($stored->state()->equals($state));
        self::assertSame("12.34", $stored->state()->paidAmount()?->decimal());
        self::assertSame("NLG", $stored->state()->paidAmount()?->currency()->value());

        $replayed = $transactions->run(
            fn () => $recorder->recordForLibrary($library, $item->id(), $state)
        );
        self::assertSame(1, $replayed?->version()->value());

        $cleared = $transactions->run(fn () => $recorder->recordForLibrary(
            $library,
            $item->id(),
            ItemLocalDetailsState::unknown(),
            new ItemLocalDetailsVersion(1)
        ));
        self::assertSame(2, $cleared?->version()->value());
        self::assertTrue($cleared?->state()->isUnknown());

        $this->database->update(
            $this->tableNames->items(),
            ["item_status" => "archived", "item_version" => 2],
            ["library_id" => $library->value(), "item_id" => $item->id()->value()]
        );
        $afterArchive = $details->find($library, $item->id());
        self::assertSame(2, $afterArchive?->version()->value());
        self::assertTrue($afterArchive?->state()->isUnknown());
        self::assertSame(
            "Work a",
            $this->database->get_var($this->database->prepare(
                "SELECT work_title FROM `{$this->tableNames->works()}` WHERE work_id=%s",
                "details-work-a"
            ))
        );
        self::assertSame(
            "Edition a",
            $this->database->get_var($this->database->prepare(
                "SELECT edition_title FROM `{$this->tableNames->editions()}` WHERE edition_id=%s",
                "details-edition-a"
            ))
        );
    }

    public function testNeverWrittenUnknownRemainsAbsentAndDivergenceRequiresCas(): void
    {
        [$library, $item] = $this->fixture("unknown");
        $details = new WpdbItemLocalDetailsRepository($this->database, $this->tableNames);
        $recorder = new ItemLocalDetailsRecorder(
            new WpdbItemRepository($this->database, $this->tableNames),
            $details
        );
        $transactions = new WpdbTransactionManager($this->database);

        self::assertNull($transactions->run(fn () => $recorder->recordForLibrary(
            $library,
            $item->id(),
            ItemLocalDetailsState::unknown()
        )));
        self::assertNull($details->find($library, $item->id()));

        $created = $transactions->run(fn () => $recorder->recordForLibrary(
            $library,
            $item->id(),
            new ItemLocalDetailsState(condition: ItemCondition::Goed)
        ));
        self::assertSame(1, $created?->version()->value());

        $this->expectException(ItemLocalDetailsStale::class);
        $transactions->run(fn () => $recorder->recordForLibrary(
            $library,
            $item->id(),
            new ItemLocalDetailsState(condition: ItemCondition::Slecht)
        ));
    }

    public function testCrossLibraryAndRollbackFailClosed(): void
    {
        [$libraryA, $itemA] = $this->fixture("a2");
        [$libraryB] = $this->fixture("b2");
        $details = new WpdbItemLocalDetailsRepository($this->database, $this->tableNames);
        $recorder = new ItemLocalDetailsRecorder(
            new WpdbItemRepository($this->database, $this->tableNames),
            $details
        );
        $transactions = new WpdbTransactionManager($this->database);

        try {
            $transactions->run(fn () => $recorder->recordForLibrary(
                $libraryB,
                $itemA->id(),
                new ItemLocalDetailsState(condition: ItemCondition::Goed)
            ));
            self::fail("Cross-Library details write was accepted.");
        } catch (ItemLocalDetailsNotAvailable) {
            self::assertNull($details->find($libraryA, $itemA->id()));
        }

        try {
            $transactions->run(function () use ($recorder, $libraryA, $itemA): void {
                $recorder->recordForLibrary(
                    $libraryA,
                    $itemA->id(),
                    new ItemLocalDetailsState(condition: ItemCondition::Goed)
                );
                throw new RuntimeException("synthetic rollback");
            });
            self::fail("Synthetic rollback did not fail.");
        } catch (RuntimeException $exception) {
            self::assertSame("synthetic rollback", $exception->getMessage());
        }
        self::assertNull($details->find($libraryA, $itemA->id()));
    }

    public function testInvalidStoredCurrencyFailsClosedOnHydration(): void
    {
        [$library, $item] = $this->fixture("invalid-stored");
        self::assertSame(1, $this->database->insert(
            $this->tableNames->itemLocalDetails(),
            [
                "library_id" => $library->value(),
                "item_id" => $item->id()->value(),
                "paid_amount" => "1.0000",
                "paid_currency" => "ZZZ",
                "details_version" => 1,
            ]
        ));

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage("Stored Item-local details are invalid.");
        (new WpdbItemLocalDetailsRepository(
            $this->database,
            $this->tableNames
        ))->find($library, $item->id());
    }

    /** @return array{LibraryId,Item} */
    private function fixture(string $suffix): array
    {
        $library = new LibraryId("details-library-{$suffix}");
        $work = new Work(new WorkId("details-work-{$suffix}"), "Work {$suffix}");
        $edition = new Edition(
            new EditionId("details-edition-{$suffix}"),
            $work->id(),
            "Edition {$suffix}"
        );
        $item = Item::active(new ItemId("details-item-{$suffix}"), $library, $edition->id());
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($library, new LibraryName("Library {$suffix}"))
        );
        (new WpdbWorkRepository($this->database, $this->tableNames))->add($work);
        (new WpdbEditionRepository($this->database, $this->tableNames))->add($edition);
        (new WpdbItemRepository($this->database, $this->tableNames))->add($item);
        return [$library, $item];
    }
}
