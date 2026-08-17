<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Library\LibraryId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CatalogEntitiesTest extends TestCase
{
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
    }
}
