<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Catalog\AcquisitionMethod;
use Biblio\Core\Catalog\DustJacketState;
use Biblio\Core\Catalog\Iso4217Currency;
use Biblio\Core\Catalog\ItemAcquisitionDate;
use Biblio\Core\Catalog\ItemCondition;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemLocalDetails;
use Biblio\Core\Catalog\ItemLocalDetailsStale;
use Biblio\Core\Catalog\ItemLocalDetailsState;
use Biblio\Core\Catalog\ItemLocalDetailsVersion;
use Biblio\Core\Catalog\ItemRepository;
use Biblio\Core\Catalog\PaidAmount;
use Biblio\Core\Catalog\WritableItemLocalDetailsRepository;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Application\Catalog\ItemLocalDetailsRecorder;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItemLocalDetailsTest extends TestCase
{
    public function testApprovedStateRoundTripsWithoutTextCanonicalization(): void
    {
        $state = new ItemLocalDetailsState(
            ItemCondition::ZeerGoed,
            new ItemAcquisitionDate(1987, 4),
            AcquisitionMethod::Purchased,
            "  Antiquariaat De Uil  ",
            new PaidAmount("12.3400", new Iso4217Currency("EUR")),
            true,
            "Auteur A",
            "17/250",
            DustJacketState::Present,
            false,
            "Uit de collectie van familie B",
            "Met losse kaart"
        );

        self::assertSame("  Antiquariaat De Uil  ", $state->acquiredVia());
        self::assertSame("12.34", $state->paidAmount()?->decimal());
        self::assertSame("EUR", $state->paidAmount()?->currency()->value());
        self::assertSame(["year" => 1987, "month" => 4, "day" => null], $state->inLibrarySince()?->toArray());
        self::assertFalse($state->inscription());
        self::assertFalse($state->isUnknown());
    }

    public function testUnknownStateDoesNotManufactureNegatives(): void
    {
        $state = ItemLocalDetailsState::unknown();
        self::assertTrue($state->isUnknown());
        self::assertNull($state->signed());
        self::assertNull($state->inscription());
        self::assertNull($state->acquisitionMethod());
        self::assertNull($state->paidAmount());
    }

    public function testApprovedControlledVocabulariesAreExact(): void
    {
        self::assertSame([
            "nieuwstaat", "zeer_goed", "goed", "redelijk", "matig", "slecht",
        ], array_column(ItemCondition::cases(), "value"));
        self::assertSame([
            "Nieuwstaat", "Zeer goed", "Goed", "Redelijk", "Matig", "Slecht",
        ], array_map(static fn (ItemCondition $condition): string => $condition->label(), ItemCondition::cases()));
        self::assertSame([
            "zelf_aangeschaft", "gekregen", "anders",
        ], array_column(AcquisitionMethod::cases(), "value"));
        self::assertSame([
            "present", "missing", "not_applicable",
        ], array_column(DustJacketState::cases(), "value"));
    }

    public function testPaidAmountSupportsExactBoundsAndHistoricalCurrency(): void
    {
        self::assertSame(
            "0",
            (new PaidAmount("0.0000", new Iso4217Currency("EUR")))->decimal()
        );
        self::assertSame(
            "999999999999999.9999",
            (new PaidAmount(
                "999999999999999.9999",
                new Iso4217Currency("NLG")
            ))->decimal()
        );
    }

    public function testUnknownCurrencyIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        new Iso4217Currency("ZZZ");
    }

    public function testSignedByRequiresExplicitSignedTrue(): void
    {
        $this->expectException(ValidationException::class);
        new ItemLocalDetailsState(signed: false, signedBy: "Auteur A");
    }

    public function testExactReplayUsesLockedCurrentStateNotInitialSnapshot(): void
    {
        $libraryId = new LibraryId("details-lock-library");
        $itemId = new ItemId("details-lock-item");
        $snapshotState = new ItemLocalDetailsState(condition: ItemCondition::Goed);
        $lockedState = new ItemLocalDetailsState(condition: ItemCondition::Slecht);
        $repository = new ChangingItemLocalDetailsRepository(
            new ItemLocalDetails(
                $libraryId,
                $itemId,
                $snapshotState,
                new ItemLocalDetailsVersion(1)
            ),
            new ItemLocalDetails(
                $libraryId,
                $itemId,
                $lockedState,
                new ItemLocalDetailsVersion(2)
            )
        );
        $recorder = new ItemLocalDetailsRecorder(
            new SingleItemRepository(Item::active(
                $itemId,
                $libraryId,
                new EditionId("details-lock-edition")
            )),
            $repository
        );

        $this->expectException(ItemLocalDetailsStale::class);
        $recorder->recordForLibrary($libraryId, $itemId, $snapshotState);
    }

    #[DataProvider("invalidDates")]
    public function testPartialDateRejectsInvalidCalendarState(int $year, ?int $month, ?int $day): void
    {
        $this->expectException(ValidationException::class);
        new ItemAcquisitionDate($year, $month, $day);
    }

    /** @return iterable<string, array{int,int|null,int|null}> */
    public static function invalidDates(): iterable
    {
        yield "year" => [999, null, null];
        yield "month" => [2020, 13, null];
        yield "day without month" => [2020, null, 1];
        yield "non leap day" => [2021, 2, 29];
    }

    #[DataProvider("invalidAmounts")]
    public function testPaidAmountRejectsNonExactOrOutOfRangeInput(string $amount): void
    {
        $this->expectException(ValidationException::class);
        new PaidAmount($amount, new Iso4217Currency("EUR"));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAmounts(): iterable
    {
        yield "negative" => ["-1"];
        yield "float notation" => ["1e2"];
        yield "too precise" => ["1.00001"];
        yield "too large" => ["1000000000000000"];
        yield "grouped" => ["1,000"];
    }

    #[DataProvider("invalidTexts")]
    public function testBoundedTextRejectsUnsafeInput(string $text): void
    {
        $this->expectException(ValidationException::class);
        new ItemLocalDetailsState(acquiredVia: $text);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTexts(): iterable
    {
        yield "empty" => [""];
        yield "whitespace" => ["   "];
        yield "Unicode whitespace" => ["\u{00A0}\u{2003}"];
        yield "control" => ["winkel\nnaam"];
        yield "overlong" => [str_repeat("a", 513)];
    }
}

final readonly class SingleItemRepository implements ItemRepository
{
    public function __construct(private Item $item) {}

    public function findInLibrary(ItemId $itemId, LibraryId $libraryId): ?Item
    {
        return $this->item->id()->equals($itemId)
            && $this->item->libraryId()->equals($libraryId)
            ? $this->item
            : null;
    }

    public function findManyInLibrary(LibraryId $libraryId, array $itemIds): array
    {
        $result = [];
        foreach ($itemIds as $itemId) {
            $result[$itemId->value()] = $this->findInLibrary($itemId, $libraryId);
        }
        return $result;
    }
}

final class ChangingItemLocalDetailsRepository implements WritableItemLocalDetailsRepository
{
    public function __construct(
        private ItemLocalDetails $snapshot,
        private ItemLocalDetails $locked
    ) {}

    public function find(LibraryId $libraryId, ItemId $itemId): ?ItemLocalDetails
    {
        return $this->snapshot;
    }

    public function findForUpdate(LibraryId $libraryId, ItemId $itemId): ?ItemLocalDetails
    {
        return $this->locked;
    }

    public function findMany(LibraryId $libraryId, array $itemIds): array
    {
        return [$this->locked->itemId()->value() => $this->locked];
    }

    public function add(ItemLocalDetails $details): void
    {
        throw new \LogicException("Unexpected add.");
    }

    public function replaceIfVersionMatches(
        ItemLocalDetails $replacement,
        ItemLocalDetailsVersion $expectedVersion
    ): bool {
        throw new \LogicException("Unexpected replace.");
    }
}
