<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Identity\PersonalMigrationTarget;
use Biblio\Core\Application\Identity\PersonalMigrationTargetReadiness;
use Biblio\Core\Application\Migration\Catalog\CatalogEditionMigrationParticipant;
use Biblio\Core\Application\Migration\Catalog\CatalogEditionPlan;
use Biblio\Core\Application\Migration\Catalog\CatalogItemMigrationParticipant;
use Biblio\Core\Application\Migration\Catalog\CatalogItemPlan;
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Catalog\CatalogWorkPlan;
use Biblio\Core\Application\Migration\Runner\MigrationPlanningTarget;
use Biblio\Core\Application\Migration\Runner\MigrationSourceInspection;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackage;
use Biblio\Core\Application\Migration\Runner\MigrationSourceProfile;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\CurrentV1CatalogItemDependencies;
use Biblio\Core\Infrastructure\Migration\CurrentV1CatalogItemDependencyProvider;
use Biblio\Core\Infrastructure\Migration\CurrentV1CatalogMapper;
use Biblio\Core\Infrastructure\Migration\CurrentV1CatalogMappingReason;
use Biblio\Core\Infrastructure\Migration\CurrentV1CatalogSourceIds;
use Biblio\Core\Infrastructure\Migration\CurrentV1ItemLocalMapper;
use Biblio\Core\Infrastructure\Migration\CurrentV1ItemLocalMappingReason;
use Biblio\Core\Infrastructure\Migration\CurrentV1ReviewedItemLocalContract;
use Biblio\Core\Infrastructure\Migration\CurrentV1SourceAdapter;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryName;
use PHPUnit\Framework\TestCase;

final class CurrentV1CatalogMapperTest extends TestCase
{
    public function testKnownAndUnknownBooksProduceStableSeparatePlans(): void
    {
        $result = $this->map([
            $this->book("known", "A shared title", "9780306406157"),
            $this->book("unknown-a", "A shared title"),
            $this->book("unknown-b", "A shared title"),
        ]);
        $records = $this->records($result->records());

        self::assertCount(6, $records);
        $known = $records[
            CatalogEditionMigrationParticipant::SOURCE_TYPE . ":"
            . CurrentV1CatalogSourceIds::edition("known")
        ]->typedPlan();
        self::assertInstanceOf(CatalogEditionPlan::class, $known);
        self::assertSame("canonical", $known->canonicalPayload()["isbn_state"]);

        foreach (["unknown-a", "unknown-b"] as $bookId) {
            $edition = $records[
                CatalogEditionMigrationParticipant::SOURCE_TYPE . ":"
                . CurrentV1CatalogSourceIds::edition($bookId)
            ]->typedPlan();
            self::assertInstanceOf(CatalogEditionPlan::class, $edition);
            self::assertSame("unknown", $edition->canonicalPayload()["isbn_state"]);
            self::assertNull($edition->aliasOfSourceId());
        }
        self::assertSame(
            array_map(
                static fn (MigrationSourceRecord $record): string => $record->payloadHash(),
                $result->records()
            ),
            array_map(
                static fn (MigrationSourceRecord $record): string => $record->payloadHash(),
                $this->map([
                    $this->book("known", "A shared title", "9780306406157"),
                    $this->book("unknown-a", "A shared title"),
                    $this->book("unknown-b", "A shared title"),
                ])->records()
            )
        );
    }

    public function testNumericSourceIdsRemainExactStrings(): void
    {
        $result = $this->map([
            $this->book("123", "Numeric source identity", "9780306406157"),
        ], [
            $this->copy("456", "123", "LEGACY-001"),
        ]);
        $records = $this->records($result->records());

        self::assertArrayHasKey(
            CatalogWorkMigrationParticipant::SOURCE_TYPE . ":v1.book/123/work",
            $records
        );
        self::assertArrayHasKey(
            CatalogEditionMigrationParticipant::SOURCE_TYPE . ":v1.book/123/edition",
            $records
        );
        self::assertContains(
            "456",
            array_map(static fn ($finding): string => $finding->sourceId(), $result->findings())
        );
    }

    public function testInvalidIsbnQuarantinesWithoutEditionIdentity(): void
    {
        $result = $this->map([
            $this->book("invalid", "Invalid evidence", "9780306406158"),
        ]);

        self::assertSame([], $result->records());
        self::assertContains(
            CurrentV1CatalogMappingReason::InvalidIsbn->value,
            $this->reasons($result->findings())
        );
    }

    public function testDuplicateIsbnUsesStableRepresentativeAndTraceableAliases(): void
    {
        $result = $this->map([
            $this->book("book-z", "Exact edition", "9780306406157"),
            $this->book("book-a", "Exact edition", "0-306-40615-2"),
        ]);
        $records = $this->records($result->records());
        $aliasWork = $records[
            CatalogWorkMigrationParticipant::SOURCE_TYPE . ":"
            . CurrentV1CatalogSourceIds::work("book-z")
        ]->typedPlan();
        $aliasEdition = $records[
            CatalogEditionMigrationParticipant::SOURCE_TYPE . ":"
            . CurrentV1CatalogSourceIds::edition("book-z")
        ]->typedPlan();

        self::assertInstanceOf(CatalogWorkPlan::class, $aliasWork);
        self::assertInstanceOf(CatalogEditionPlan::class, $aliasEdition);
        self::assertSame(
            CurrentV1CatalogSourceIds::work("book-a"),
            $aliasWork->aliasOfSourceId()
        );
        self::assertSame(
            CurrentV1CatalogSourceIds::edition("book-a"),
            $aliasEdition->aliasOfSourceId()
        );
        self::assertArrayHasKey(
            CatalogWorkMigrationParticipant::SOURCE_TYPE . ":"
                . CurrentV1CatalogSourceIds::work("book-a"),
            $records
        );
        self::assertContains(
            CurrentV1CatalogMappingReason::DuplicateIsbnAlias->value,
            $this->reasons($result->findings())
        );
    }

    public function testDuplicateIsbnTitleConflictFailsClosed(): void
    {
        $result = $this->map([
            $this->book("book-a", "First title", "9780306406157"),
            $this->book("book-b", "Conflicting title", "9780306406157"),
        ]);

        self::assertSame([], $result->records());
        self::assertSame(2, count(array_filter(
            $this->reasons($result->findings()),
            static fn (string $reason): bool =>
                $reason === CurrentV1CatalogMappingReason::DuplicateIsbnWorkConflict->value
        )));
    }

    public function testCopiesRemainBlockedUntilBothReviewedDependenciesExist(): void
    {
        $result = $this->map(
            [$this->book("book-a", "Edition", "9780306406157")],
            [$this->copy("copy-a", "book-a", "LEGACY-001")]
        );
        $records = $this->records($result->records());

        self::assertCount(2, $records);
        self::assertContains(
            CurrentV1CatalogMappingReason::UnresolvedClassificationDependency->value,
            $this->reasons($result->findings())
        );
        self::assertContains(
            CurrentV1CatalogMappingReason::UnresolvedItemLocalDependency->value,
            $this->reasons($result->findings())
        );
        self::assertContains(
            CurrentV1CatalogMappingReason::DeferredItemSourceNumbers->value,
            $this->reasons($result->findings())
        );
    }

    public function testReviewedCopiesProduceDistinctItemsWithoutInventoryInference(): void
    {
        $provider = new SyntheticCurrentV1CatalogItemDependencyProvider([
            "copy-a" => CurrentV1CatalogItemDependencies::reviewed(
                new LibraryCatalogSelection(new LibraryBookTypeId("type-a")),
                null
            ),
            "copy-b" => CurrentV1CatalogItemDependencies::reviewed(
                new LibraryCatalogSelection(new LibraryBookTypeId("type-a")),
                null
            ),
        ]);
        $result = $this->map(
            [$this->book("book-a", "Edition", "9780306406157")],
            [
                $this->copy("copy-a", "book-a", "LEGACY-001"),
                $this->copy("copy-b", "book-a", "LEGACY-002"),
            ],
            $provider
        );
        $records = $this->records($result->records());

        foreach (["copy-a", "copy-b"] as $copyId) {
            $plan = $records[
                CatalogItemMigrationParticipant::SOURCE_TYPE . ":"
                    . CurrentV1CatalogSourceIds::item($copyId)
            ]->typedPlan();
            self::assertInstanceOf(CatalogItemPlan::class, $plan);
            self::assertSame(
                CurrentV1CatalogSourceIds::edition("book-a"),
                $plan->editionSourceId()
            );
            self::assertNull($plan->inventoryNumber());
            self::assertNull($plan->locationId());
            self::assertNull($plan->localDetails());
        }
    }

    public function testReviewedCopyOfAliasBookUsesItsAliasEditionIdentity(): void
    {
        $provider = new SyntheticCurrentV1CatalogItemDependencyProvider([
            "copy-alias" => CurrentV1CatalogItemDependencies::reviewed(
                new LibraryCatalogSelection(new LibraryBookTypeId("type-a")),
                null
            ),
        ]);
        $result = $this->map(
            [
                $this->book("book-z", "Exact edition", "9780306406157"),
                $this->book("book-a", "Exact edition", "0-306-40615-2"),
            ],
            [$this->copy("copy-alias", "book-z", "LEGACY-ALIAS")],
            $provider
        );
        $records = $this->records($result->records());
        $plan = $records[
            CatalogItemMigrationParticipant::SOURCE_TYPE . ":"
                . CurrentV1CatalogSourceIds::item("copy-alias")
        ]->typedPlan();

        self::assertInstanceOf(CatalogItemPlan::class, $plan);
        self::assertSame(
            CurrentV1CatalogSourceIds::edition("book-z"),
            $plan->editionSourceId()
        );
        self::assertNotSame(
            CurrentV1CatalogSourceIds::edition("book-a"),
            $plan->editionSourceId()
        );
    }

    public function testItemLocalReviewedNullAndTypedStateBothCompleteItemPlans(): void
    {
        $selection = new LibraryCatalogSelection(new LibraryBookTypeId("type-a"));
        $provider = new SyntheticCurrentV1CatalogItemDependencyProvider([
            "copy-null" => CurrentV1CatalogItemDependencies::reviewed($selection, null),
            "copy-details" => CurrentV1CatalogItemDependencies::reviewed($selection, null),
        ]);
        $null = $this->copy("copy-null", "book-a", "LEGACY-NULL");
        $detailsPayload = $this->copy(
            "copy-details",
            "book-a",
            "LEGACY-DETAILS"
        )->payload();
        $detailsPayload["acquisition"] = [
            "type" => "bought",
            "date" => ["value" => "1999-08", "precision" => "month"],
            "source" => "Synthetic seller",
        ];
        $details = new MigrationSourceRecord(
            CurrentV1SourceAdapter::COPY,
            "copy-details",
            $detailsPayload
        );

        $result = $this->map(
            [$this->book("book-a", "Edition", "9780306406157")],
            [$null, $details],
            $provider,
            $this->itemLocalMapper()
        );
        $records = $this->records($result->records());
        $nullPlan = $records[
            CatalogItemMigrationParticipant::SOURCE_TYPE . ":"
                . CurrentV1CatalogSourceIds::item("copy-null")
        ]->typedPlan();
        $detailsPlan = $records[
            CatalogItemMigrationParticipant::SOURCE_TYPE . ":"
                . CurrentV1CatalogSourceIds::item("copy-details")
        ]->typedPlan();

        self::assertInstanceOf(CatalogItemPlan::class, $nullPlan);
        self::assertNull($nullPlan->localDetails());
        self::assertNotNull($nullPlan->preservation());
        self::assertInstanceOf(CatalogItemPlan::class, $detailsPlan);
        self::assertSame(
            "zelf_aangeschaft",
            $detailsPlan->localDetails()?->acquisitionMethod()?->value
        );
        self::assertSame(
            ["year" => 1999, "month" => 8, "day" => null],
            $detailsPlan->localDetails()?->inLibrarySince()?->toArray()
        );
    }

    public function testExternalBorrowedExclusionOverridesAvailableClassification(): void
    {
        $copy = $this->copy("borrowed", "book-a", "LEGACY-BORROWED");
        $payload = $copy->payload();
        $payload["acquisition"] = [
            "type" => "borrowed",
            "source" => "Private counterparty",
        ];
        $copy = new MigrationSourceRecord(
            CurrentV1SourceAdapter::COPY,
            "borrowed",
            $payload
        );
        $provider = new SyntheticCurrentV1CatalogItemDependencyProvider([
            "borrowed" => CurrentV1CatalogItemDependencies::reviewed(
                new LibraryCatalogSelection(new LibraryBookTypeId("type-a")),
                null
            ),
        ]);

        $result = $this->map(
            [$this->book("book-a", "Edition", "9780306406157")],
            [$copy],
            $provider,
            $this->itemLocalMapper()
        );

        self::assertArrayNotHasKey(
            CatalogItemMigrationParticipant::SOURCE_TYPE . ":"
                . CurrentV1CatalogSourceIds::item("borrowed"),
            $this->records($result->records())
        );
        self::assertContains(
            CurrentV1ItemLocalMappingReason::ExternalBorrowedCopyPreserved->value,
            $this->reasons($result->findings())
        );
        self::assertNotContains(
            CurrentV1CatalogMappingReason::CatalogItemPlanned->value,
            $this->reasons($result->findings())
        );
    }

    public function testVariantContainedAndEditionEvidenceRemainExplicitlyDeferred(): void
    {
        $book = $this->book("book-a", "Edition", "9780306406157");
        $payload = $book->payload();
        $payload["variantOfBookId"] = "book-b";
        $payload["containedWorks"] = [["title" => "Child"]];
        $payload["publisher"] = "Private publisher evidence";

        $result = $this->map([
            new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, "book-a", $payload),
        ]);
        $reasons = $this->reasons($result->findings());
        self::assertContains(CurrentV1CatalogMappingReason::DeferredVariantRelation->value, $reasons);
        self::assertContains(CurrentV1CatalogMappingReason::DeferredContainedWork->value, $reasons);
        self::assertContains(CurrentV1CatalogMappingReason::DeferredEditionEvidence->value, $reasons);
        self::assertContains(CurrentV1CatalogMappingReason::CatalogSourceEvidenceRetained->value, $reasons);
    }

    /**
     * @param list<MigrationSourceRecord> $books
     * @param list<MigrationSourceRecord> $copies
     */
    private function map(
        array $books,
        array $copies = [],
        ?CurrentV1CatalogItemDependencyProvider $provider = null,
        ?CurrentV1ItemLocalMapper $itemLocalMapper = null
    ): \Biblio\Core\Application\Migration\Runner\MigrationSourceMappingResult {
        $records = [...$books, ...$copies];
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], str_repeat("0", 64)),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            $records,
            []
        );
        $target = new MigrationPlanningTarget(new PersonalMigrationTarget(
            new UserId("user-a"),
            new LibraryId("library-a"),
            LibraryName::personalDefault(),
            new PersonalMigrationTargetReadiness([])
        ));

        return (new CurrentV1CatalogMapper(
            $provider,
            itemLocalMapper: $itemLocalMapper
        ))->map($inspection, $target);
    }

    private function book(string $id, string $title, ?string $isbn = null): MigrationSourceRecord
    {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, $id, [
            "id" => $id,
            "title" => $title,
            "isbn" => $isbn ?? "",
            "isbn10" => "",
            "isbn13" => "",
            "variantOfBookId" => "",
            "containedWorks" => [],
        ]);
    }

    private function copy(string $id, string $bookId, string $number): MigrationSourceRecord
    {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::COPY, $id, [
            "id" => $id,
            "bookId" => $bookId,
            "copyNumber" => $number,
            "legacyBookNumber" => "",
            "sourceBookNumber" => "",
            "acquisition" => null,
            "notes" => "",
            "disposal" => null,
            "exemplarPhotos" => [],
        ]);
    }

    private function itemLocalMapper(): CurrentV1ItemLocalMapper
    {
        return new CurrentV1ItemLocalMapper(
            new CurrentV1ReviewedItemLocalContract(str_repeat("0", 64))
        );
    }

    /** @param list<MigrationSourceRecord> $records */
    private function records(array $records): array
    {
        $indexed = [];
        foreach ($records as $record) {
            $indexed[$record->sourceType() . ":" . $record->sourceId()] = $record;
        }
        return $indexed;
    }

    /** @param list<\Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding> $findings */
    private function reasons(array $findings): array
    {
        return array_map(static fn ($finding): string => $finding->reasonCode(), $findings);
    }
}

final readonly class SyntheticCurrentV1CatalogItemDependencyProvider implements
    CurrentV1CatalogItemDependencyProvider
{
    /** @param array<string, CurrentV1CatalogItemDependencies> $dependencies */
    public function __construct(private array $dependencies)
    {
    }

    public function forCopy(
        MigrationSourceRecord $copy,
        MigrationSourceRecord $book,
        MigrationPlanningTarget $target
    ): CurrentV1CatalogItemDependencies {
        unset($book, $target);
        return $this->dependencies[$copy->sourceId()]
            ?? CurrentV1CatalogItemDependencies::unresolved();
    }
}
