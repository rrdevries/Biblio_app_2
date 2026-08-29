<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\Read\CatalogDataState;
use Biblio\Core\Application\Catalog\Read\CatalogActiveReadingRoundView;
use Biblio\Core\Application\Catalog\Read\CatalogItemNotAvailable;
use Biblio\Core\Application\Catalog\Read\CatalogItemReadRecord;
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Application\Catalog\Read\CatalogTextListValue;
use Biblio\Core\Application\Catalog\Read\CatalogTextValue;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Reading\PersonalWorkReadingStatus;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogUiReadModelsTest extends TestCase
{
    public function testOptionalMetadataStatesAreExplicit(): void
    {
        self::assertSame(CatalogDataState::Known, CatalogTextValue::known("Boek")->state());
        self::assertSame("Boek", CatalogTextValue::known("Boek")->value());
        self::assertSame(CatalogDataState::Missing, CatalogTextValue::missing()->state());
        self::assertSame(CatalogDataState::NotApplicable, CatalogTextValue::notApplicable()->state());
        self::assertSame(CatalogDataState::Unknown, CatalogTextValue::unknown()->state());
        self::assertNull(CatalogTextValue::unknown()->value());
        self::assertSame(CatalogDataState::Unknown, CatalogTextListValue::unknown()->state());
        self::assertSame([], CatalogTextListValue::unknown()->values());
        self::assertSame(["Auteur"], CatalogTextListValue::known(["Auteur"])->values());
    }

    /** @return iterable<string, array{int, int, PersonalWorkReadingStatus}> */
    public static function statusCases(): iterable
    {
        yield "never read" => [0, 0, PersonalWorkReadingStatus::NotRead];
        yield "stopped only" => [0, 0, PersonalWorkReadingStatus::NotRead];
        yield "completed" => [0, 1, PersonalWorkReadingStatus::Read];
        yield "active wins over completed" => [1, 2, PersonalWorkReadingStatus::Reading];
    }

    #[DataProvider("statusCases")]
    public function testReadingStatusIsDerivedFromRoundFacts(
        int $active,
        int $completed,
        PersonalWorkReadingStatus $expected
    ): void {
        $record = new CatalogItemReadRecord(
            new ItemId("item"),
            new WorkId("work"),
            new EditionId("edition"),
            "Titel",
            ItemStatus::Active,
            $active,
            $completed,
            1,
            $completed,
            null
        );

        self::assertSame($expected, $record->readingStatus());
    }

    public function testActiveReadingRoundViewContainsOnlySliceFields(): void
    {
        $startedOn = ReadingDate::exact(2026, 8, 29);
        $round = new CatalogActiveReadingRoundView(
            new ReadingRoundId("round-item-exact"),
            new ReadingRoundVersion(4),
            $startedOn
        );

        self::assertSame("round-item-exact", $round->readingRoundId()->value());
        self::assertSame(4, $round->version()->value());
        self::assertSame($startedOn, $round->startedOn());
    }

    public function testPageSizeIsBounded(): void
    {
        self::assertSame(24, (new CatalogOverviewPageSize())->value());
        self::assertSame(100, (new CatalogOverviewPageSize(100))->value());

        foreach ([0, 101] as $invalid) {
            try {
                new CatalogOverviewPageSize($invalid);
                self::fail("Invalid catalog page size was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testUnavailableItemHasStableNonEnumeratingReason(): void
    {
        $exception = new CatalogItemNotAvailable();

        self::assertSame(FailureReason::CatalogItemNotAvailable, $exception->reason());
        self::assertSame(
            "Catalog Item is not available in this Library context.",
            $exception->getMessage()
        );
    }
}
