<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Catalog\LibraryLocation;
use Biblio\Core\Catalog\LocationId;
use Biblio\Core\Catalog\{ItemArchivePeriod,ItemArchiveReason,ItemArchiveTransitionUnavailable,ItemVersion};
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Library\LibraryId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CatalogEntitiesTest extends TestCase
{
    public function testCanonicalArchiveReasonsAreExactAndLossless(): void
    {
        self::assertSame([
            "sold",
            "given_away",
            "donated",
            "lost",
            "damaged_discarded",
            "not_returned",
        ], array_map(
            static fn (ItemArchiveReason $reason): string => $reason->value,
            ItemArchiveReason::cases()
        ));
    }

    public function testItemArchiveLifecyclePreservesIdentityMetadataAndVersion(): void
    {
        $item = Item::active(
            new ItemId("item-a"),
            new LibraryId("library-a"),
            new EditionId("edition-a"),
            null,
            new LocationId("location-a")
        );

        $archived = $item->archive();
        $restored = $archived->restore();

        self::assertSame(ItemStatus::Archived, $archived->status());
        self::assertSame(2, $archived->version()->value());
        self::assertSame(ItemStatus::Active, $restored->status());
        self::assertSame(3, $restored->version()->value());
        self::assertTrue($item->id()->equals($restored->id()));
        self::assertTrue($item->editionId()->equals($restored->editionId()));
        self::assertSame($item->locationId()?->value(), $restored->locationId()?->value());
    }

    public function testInvalidItemArchiveTransitionsAreTyped(): void
    {
        $item = Item::active(new ItemId("item-a"), new LibraryId("library-a"), new EditionId("edition-a"));
        foreach ([$item->restore(...), $item->archive()->archive(...)] as $transition) {
            try { $transition(); self::fail("Invalid lifecycle transition was accepted."); }
            catch (ItemArchiveTransitionUnavailable) { self::assertTrue(true); }
        }
    }

    public function testArchivePeriodPreservesReasonAndMicrosecondTime(): void
    {
        $archivedAt = new \DateTimeImmutable("2026-09-04 10:11:12.123456+00:00");
        $restoredAt = new \DateTimeImmutable("2026-09-05 11:12:13.654321+00:00");
        $period = new ItemArchivePeriod(
            new LibraryId("library-a"),
            new ItemId("item-a"),
            new ItemVersion(2),
            ItemArchiveReason::DamagedDiscarded,
            $archivedAt,
            new ItemVersion(3),
            $restoredAt
        );

        self::assertSame(ItemArchiveReason::DamagedDiscarded, $period->reason());
        self::assertSame("123456", $period->archivedAt()->format("u"));
        self::assertSame("654321", $period->restoredAt()?->format("u"));
        self::assertFalse($period->isOpen());
    }
    public function testWorkRequiresMeaningfulTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Work(new WorkId("work-w"), "   ");
    }

    public function testWorkAcceptsMaximumPersistedTitleLength(): void
    {
        $title = str_repeat("é", Work::MAX_TITLE_LENGTH);

        self::assertSame(
            $title,
            (new Work(new WorkId("work-w"), $title))->title()
        );
    }

    public function testWorkRejectsOverlongTitleBeforePersistence(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Work(
            new WorkId("work-w"),
            str_repeat("é", Work::MAX_TITLE_LENGTH + 1)
        );
    }

    public function testWorkRejectsInvalidUtf8Title(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Work(new WorkId("work-w"), "invalid-\xFF");
    }

    public function testCatalogIdentifiersRejectEmptyValues(): void
    {
        foreach ([WorkId::class, EditionId::class, ItemId::class] as $class) {
            try {
                new $class("  ");
                self::fail("{$class} accepted an empty value.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testItemCarriesOneLibraryAndPlatformEdition(): void
    {
        $workId = new WorkId("work-w");
        $edition = new Edition(new EditionId("edition-e"), $workId);
        $libraryId = new LibraryId("library-a");
        $item = Item::active(
            new ItemId("item-a"),
            $libraryId,
            $edition->id()
        );

        self::assertTrue($libraryId->equals($item->libraryId()));
        self::assertTrue($edition->id()->equals($item->editionId()));
        self::assertSame(ItemStatus::Active, $item->status());
        self::assertNull($item->locationId());
    }

    public function testLibraryLocationIsTypedOwnedAndLossless(): void
    {
        $location = new LibraryLocation(
            new LocationId("location-a"),
            new LibraryId("library-a"),
            "Kast Één"
        );
        self::assertSame("location-a", $location->id()->value());
        self::assertSame("library-a", $location->libraryId()->value());
        self::assertSame("Kast Één", $location->displayName());
    }

    #[DataProvider("invalidLocationNames")]
    public function testLibraryLocationRejectsInvalidNames(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LibraryLocation(new LocationId("location-a"), new LibraryId("library-a"), $name);
    }

    public static function invalidLocationNames(): array
    {
        return [["   "], ["invalid-\xFF"], [str_repeat("é", LibraryLocation::MAX_NAME_LENGTH + 1)]];
    }
}
