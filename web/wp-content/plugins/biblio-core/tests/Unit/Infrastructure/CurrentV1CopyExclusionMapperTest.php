<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Identity\{
    PersonalMigrationTarget,
    PersonalMigrationTargetReadiness
};
use Biblio\Core\Application\Migration\Catalog\{
    CatalogEditionMigrationParticipant,
    CatalogItemMigrationParticipant,
    CatalogWorkMigrationParticipant
};
use Biblio\Core\Application\Migration\Preservation\{
    PreservedSourceEvidenceMigrationParticipant,
    PreservedSourceEvidencePlan,
    PreservedSourceEvidencePrivacy
};
use Biblio\Core\Application\Migration\Reading\{
    ReadingRoundMigrationParticipant,
    ReadingTruthMigrationParticipant
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationPlanningTarget,
    MigrationRunnerFailure,
    MigrationSourceInspection,
    MigrationSourcePackage,
    MigrationSourceProfile,
    MigrationSourceRecord
};
use Biblio\Core\Application\Migration\Wishlist\WishlistMigrationParticipant;
use Biblio\Core\Catalog\Classification\{
    LibraryBookTypeId,
    LibraryCatalogSelection
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\{
    CurrentV1CatalogItemDependencies,
    CurrentV1CatalogItemDependencyProvider,
    CurrentV1CatalogMapper,
    CurrentV1CatalogSourceIds,
    CurrentV1ItemEligibility,
    CurrentV1ItemLocalMapper,
    CurrentV1ItemLocalMappingReason,
    CurrentV1ReadingMapper,
    CurrentV1ReviewedCopyExclusionContract,
    CurrentV1ReviewedItemLocalContract,
    CurrentV1ReviewedReadingContract,
    CurrentV1ReviewedWishlistContract,
    CurrentV1SourceAdapter,
    CurrentV1WishlistMapper
};
use Biblio\Core\Library\{LibraryId,LibraryName};
use PHPUnit\Framework\TestCase;

final class CurrentV1CopyExclusionMapperTest extends TestCase
{
    private const MANIFEST =
        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    public function testTerminalExclusionOverridesEveryItemDependencyWithoutSpreading(): void
    {
        $excluded = $this->copy("excluded", "book-z", [
            "acquisition" => [
                "type" => "bought",
                "date" => ["value" => "2020-02-29", "precision" => "day"],
                "source" => "Private source",
            ],
            "notes" => "Private Copy note",
            "archived" => true,
            "archivedAt" => "2024-01-01T10:00:00.000Z",
            "status" => "disposed",
            "ownershipStatus" => "none",
            "archiveReason" => "duplicate_correction",
        ]);
        $legitimate = $this->copy("legitimate", "book-z");
        $borrowed = $this->copy("borrowed", "book-a", [
            "acquisition" => ["type" => "borrowed", "source" => "Private lender"],
        ]);
        $contract = new CurrentV1ReviewedCopyExclusionContract(
            self::MANIFEST,
            [[
                "source_id" => $excluded->sourceId(),
                "payload_hash" => $excluded->payloadHash(),
            ]]
        );
        $provider = new CopyExclusionReadyDependencies(["legitimate"]);

        $result = $this->mapper($provider, $contract)->map(
            $this->inspection([
                $this->book("book-a", "Same Edition", "9780306406157"),
                $this->book("book-z", "Same Edition", "9780306406157"),
                $excluded,
                $legitimate,
                $borrowed,
            ]),
            $this->target()
        );
        $records = $this->records($result->records());

        self::assertArrayHasKey(
            CatalogWorkMigrationParticipant::SOURCE_TYPE . ":"
                . CurrentV1CatalogSourceIds::work("book-z"),
            $records
        );
        self::assertArrayHasKey(
            CatalogEditionMigrationParticipant::SOURCE_TYPE . ":"
                . CurrentV1CatalogSourceIds::edition("book-z"),
            $records
        );
        self::assertArrayNotHasKey(
            CatalogItemMigrationParticipant::SOURCE_TYPE . ":"
                . CurrentV1CatalogSourceIds::item("excluded"),
            $records
        );
        self::assertArrayHasKey(
            CatalogItemMigrationParticipant::SOURCE_TYPE . ":"
                . CurrentV1CatalogSourceIds::item("legitimate"),
            $records
        );
        self::assertArrayNotHasKey(
            CatalogItemMigrationParticipant::SOURCE_TYPE . ":"
                . CurrentV1CatalogSourceIds::item("borrowed"),
            $records
        );
        self::assertSame("book-z", $legitimate->payload()["bookId"]);
        self::assertSame("book-z", $excluded->payload()["bookId"]);

        $preservedKey = PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE
            . ":v1.copy/excluded/erroneous-legacy-copy";
        self::assertArrayHasKey($preservedKey, $records);
        $plan = $records[$preservedKey]->typedPlan();
        self::assertInstanceOf(PreservedSourceEvidencePlan::class, $plan);
        self::assertSame("current_v1_erroneous_legacy_copy", $plan->evidenceType());
        self::assertSame(
            CurrentV1ItemLocalMappingReason::
                ErroneousLegacyCopyNotCarriedForward->value,
            $plan->reasonCode()
        );
        self::assertSame(PreservedSourceEvidencePrivacy::RestrictedSource, $plan->privacy());
        self::assertSame("data/books.json#copies/excluded/record", $plan->locator());
        self::assertSame($excluded->payloadHash(), $plan->evidenceSha256());
        self::assertSame([[
            "source_type" => CatalogItemMigrationParticipant::SOURCE_TYPE,
            "source_id" => CurrentV1CatalogSourceIds::item("excluded"),
        ]], $plan->forbiddenSourceIdentities());
        self::assertStringNotContainsString(
            "Private Copy note",
            json_encode($plan->canonicalPayload(), JSON_THROW_ON_ERROR)
        );

        $excludedFindings = array_values(array_filter(
            $result->findings(),
            static fn ($finding): bool =>
                $finding->sourceType() === CurrentV1SourceAdapter::COPY
                && $finding->sourceId() === "excluded"
        ));
        self::assertCount(1, $excludedFindings);
        self::assertSame(
            [[
                "source_type" => PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
                "source_id" => "v1.copy/excluded/erroneous-legacy-copy",
            ]],
            $excludedFindings[0]->toArray()["planned_identities"]
        );

        $reasons = array_map(
            static fn ($finding): string => $finding->reasonCode(),
            $result->findings()
        );
        self::assertSame(1, count(array_filter(
            $reasons,
            static fn (string $reason): bool => $reason ===
                CurrentV1ItemLocalMappingReason::
                    ErroneousLegacyCopyNotCarriedForward->value
        )));
        self::assertContains(
            CurrentV1ItemLocalMappingReason::ExternalBorrowedCopyPreserved->value,
            $reasons
        );
    }

    public function testExactSetDriftFailsClosed(): void
    {
        $reviewed = $this->copy("excluded", "book-a", [
            "archived" => true,
            "archivedAt" => "2024-01-01T10:00:00.000Z",
            "status" => "disposed",
            "ownershipStatus" => "none",
            "archiveReason" => "incorrectly_registered",
        ]);
        $contract = new CurrentV1ReviewedCopyExclusionContract(
            self::MANIFEST,
            [[
                "source_id" => $reviewed->sourceId(),
                "payload_hash" => $reviewed->payloadHash(),
            ]]
        );
        $changedPayload = $reviewed->payload();
        $changedPayload["archiveReason"] = "wishlist_correction";

        $this->expectException(MigrationRunnerFailure::class);
        $this->expectExceptionMessage("Copy exclusion set does not match");
        $this->mapper(new CopyExclusionReadyDependencies([]), $contract)->map(
            $this->inspection([new MigrationSourceRecord(
                CurrentV1SourceAdapter::COPY,
                "excluded",
                $changedPayload
            )]),
            $this->target()
        );
    }

    public function testExclusionLeavesWishlistReadingCirculationAndArchiveLanesUntouched(): void
    {
        $round = [
            "id" => "round-1",
            "startedAt" => "",
            "startedAtPartial" => ["value" => "2024-01", "precision" => "month"],
            "finishedAt" => "",
            "finishedAtPartial" => ["value" => "2024-02", "precision" => "month"],
            "stoppedAt" => "",
            "stoppedAtPartial" => null,
            "stopReason" => "",
            "pauses" => [],
        ];
        $roundBook = $this->readingBook(
            "book-round",
            "Round Work",
            "finished",
            "yes",
            [$round],
            null
        );
        $truthBook = $this->readingBook(
            "book-truth",
            "Truth Work",
            "finished",
            "yes",
            [],
            ["mode" => "unknown_date"]
        );
        $activeRoundCopy = $this->copy("copy-round", "book-round");
        $activeTruthCopy = $this->copy("copy-truth", "book-truth");
        $excludedRoundCopy = $this->erroneous(
            $activeRoundCopy,
            "wishlist_correction"
        );
        $excludedTruthCopy = $this->erroneous($activeTruthCopy);
        $wishlist = $this->wishlist("wish-1", "book-round");
        $readingRound = new MigrationSourceRecord(
            CurrentV1SourceAdapter::READING_ROUND,
            "round-1",
            ["book_id" => "book-round", "record" => $round]
        );
        $circulation = new MigrationSourceRecord(
            CurrentV1SourceAdapter::CIRCULATION_ROUND,
            "circulation-1",
            ["reviewed" => "unchanged"]
        );

        $baseline = $this->compositeMap(
            [$roundBook, $truthBook, $activeRoundCopy, $activeTruthCopy,
                $wishlist, $readingRound, $circulation],
            new CurrentV1ReviewedCopyExclusionContract(self::MANIFEST, [])
        );
        $excluded = $this->compositeMap(
            [$roundBook, $truthBook, $excludedRoundCopy, $excludedTruthCopy,
                $wishlist, $readingRound, $circulation],
            new CurrentV1ReviewedCopyExclusionContract(self::MANIFEST, [
                [
                    "source_id" => $excludedRoundCopy->sourceId(),
                    "payload_hash" => $excludedRoundCopy->payloadHash(),
                ],
                [
                    "source_id" => $excludedTruthCopy->sourceId(),
                    "payload_hash" => $excludedTruthCopy->payloadHash(),
                ],
            ])
        );

        foreach ([
            WishlistMigrationParticipant::SOURCE_TYPE,
            ReadingRoundMigrationParticipant::SOURCE_TYPE,
            ReadingTruthMigrationParticipant::SOURCE_TYPE,
            CurrentV1SourceAdapter::CIRCULATION_ROUND,
        ] as $sourceType) {
            self::assertSame(
                $this->recordHashes($baseline->records(), $sourceType),
                $this->recordHashes($excluded->records(), $sourceType),
                $sourceType
            );
        }
        self::assertCount(1, $this->recordHashes(
            $excluded->records(),
            WishlistMigrationParticipant::SOURCE_TYPE
        ));
        self::assertCount(1, $this->recordHashes(
            $excluded->records(),
            ReadingRoundMigrationParticipant::SOURCE_TYPE
        ));
        self::assertCount(1, $this->recordHashes(
            $excluded->records(),
            ReadingTruthMigrationParticipant::SOURCE_TYPE
        ));
        self::assertCount(1, $this->recordHashes(
            $excluded->records(),
            CurrentV1SourceAdapter::CIRCULATION_ROUND
        ));
        self::assertSame([], array_values(array_filter(
            $excluded->records(),
            static fn (MigrationSourceRecord $record): bool =>
                str_contains($record->sourceType(), "archive")
        )));
        $mappedPayloads = json_encode(array_map(
            static fn (MigrationSourceRecord $record): array => $record->payload(),
            $excluded->records()
        ), JSON_THROW_ON_ERROR);
        foreach ([
            '"archive_reason"',
            '"archived_at"',
            '"restore"',
            "wishlist_correction",
            "duplicate_correction",
        ] as $forbiddenArchiveSemantic) {
            self::assertStringNotContainsString(
                $forbiddenArchiveSemantic,
                $mappedPayloads
            );
        }
    }

    private function mapper(
        CurrentV1CatalogItemDependencyProvider $provider,
        CurrentV1ReviewedCopyExclusionContract $contract
    ): CurrentV1CatalogMapper {
        return new CurrentV1CatalogMapper(
            $provider,
            itemLocalMapper: new CurrentV1ItemLocalMapper(
                new CurrentV1ReviewedItemLocalContract(self::MANIFEST),
                $contract
            )
        );
    }

    /** @param list<MigrationSourceRecord> $records */
    private function compositeMap(
        array $records,
        CurrentV1ReviewedCopyExclusionContract $contract
    ): \Biblio\Core\Application\Migration\Runner\MigrationSourceMappingResult {
        return (new CurrentV1CatalogMapper(
            itemLocalMapper: new CurrentV1ItemLocalMapper(
                new CurrentV1ReviewedItemLocalContract(self::MANIFEST),
                $contract
            ),
            readingMapper: new CurrentV1ReadingMapper(
                new CurrentV1ReviewedReadingContract(self::MANIFEST)
            ),
            wishlistMapper: new CurrentV1WishlistMapper(
                new CurrentV1ReviewedWishlistContract(self::MANIFEST)
            )
        ))->map($this->inspection($records), $this->target());
    }

    /** @param list<MigrationSourceRecord> $records */
    private function inspection(array $records): MigrationSourceInspection
    {
        return new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], self::MANIFEST),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            $records,
            []
        );
    }

    private function target(): MigrationPlanningTarget
    {
        return new MigrationPlanningTarget(new PersonalMigrationTarget(
            new UserId("target-user"),
            new LibraryId("target-library"),
            LibraryName::personalDefault(),
            new PersonalMigrationTargetReadiness([])
        ));
    }

    private function book(string $id, string $title, string $isbn): MigrationSourceRecord
    {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, $id, [
            "id" => $id,
            "title" => $title,
            "isbn" => $isbn,
            "isbn10" => "",
            "isbn13" => "",
            "variantOfBookId" => "",
            "containedWorks" => [],
        ]);
    }

    /** @param array<string,mixed> $replace */
    private function copy(string $id, string $bookId, array $replace = []): MigrationSourceRecord
    {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::COPY, $id, array_replace([
            "id" => $id,
            "bookId" => $bookId,
            "acquisition" => null,
            "archiveReason" => "",
            "archived" => false,
            "archivedAt" => "",
            "condition" => "",
            "copyNumber" => "COPY-" . $id,
            "disposal" => null,
            "exemplarPhotos" => ["front" => "", "back" => "", "spine" => ""],
            "legacyBookNumber" => "",
            "notes" => "",
            "ownershipStatus" => "owned",
            "sourceBookNumber" => "",
            "status" => "active",
        ], $replace));
    }

    private function erroneous(
        MigrationSourceRecord $copy,
        string $reason = "duplicate_correction"
    ): MigrationSourceRecord
    {
        return new MigrationSourceRecord(
            $copy->sourceType(),
            $copy->sourceId(),
            array_replace($copy->payload(), [
                "archived" => true,
                "archivedAt" => "2024-03-01T10:00:00.000Z",
                "status" => "disposed",
                "ownershipStatus" => "none",
                "archiveReason" => $reason,
            ])
        );
    }

    /** @param list<array<string,mixed>> $rounds @param array<string,mixed>|null $registration */
    private function readingBook(
        string $id,
        string $title,
        string $status,
        string $marker,
        array $rounds,
        ?array $registration
    ): MigrationSourceRecord {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, $id, [
            "id" => $id,
            "title" => $title,
            "isbn" => "",
            "isbn10" => "",
            "isbn13" => "",
            "variantOfBookId" => "",
            "containedWorks" => [],
            "readingRounds" => $rounds,
            "readRegistration" => $registration,
            "readStatus" => $status,
            "readMarker" => $marker,
            "readHistory" => [],
        ]);
    }

    private function wishlist(string $id, string $bookId): MigrationSourceRecord
    {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::WISHLIST_ITEM, $id, [
            "authorSnapshot" => "Private Author",
            "bookId" => $bookId,
            "createdAt" => "2026-09-01T10:00:00.000Z",
            "desiredBinding" => "",
            "desiredCarrier" => "Fysiek boek",
            "desiredLanguage" => "",
            "fulfilledAt" => "",
            "fulfilledCopyId" => "",
            "id" => $id,
            "notes" => "",
            "priority" => "",
            "status" => "active",
            "titleGroupKey" => "private-group",
            "titleSnapshot" => "Private Title",
            "type" => "edition",
            "updatedAt" => "2026-09-02T11:30:00.000Z",
        ], [CurrentV1SourceAdapter::BOOK . ":" . $bookId]);
    }

    /**
     * @param list<MigrationSourceRecord> $records
     * @return array<string,string>
     */
    private function recordHashes(array $records, string $sourceType): array
    {
        $hashes = [];
        foreach ($records as $record) {
            if ($record->sourceType() === $sourceType) {
                $hashes[$record->sourceId()] = $record->payloadHash();
            }
        }
        ksort($hashes, SORT_STRING);
        return $hashes;
    }

    /** @param list<MigrationSourceRecord> $records @return array<string,MigrationSourceRecord> */
    private function records(array $records): array
    {
        $indexed = [];
        foreach ($records as $record) {
            $indexed[$record->sourceType() . ":" . $record->sourceId()] = $record;
        }
        return $indexed;
    }
}

final readonly class CopyExclusionReadyDependencies implements
    CurrentV1CatalogItemDependencyProvider
{
    /** @param list<string> $copyIds */
    public function __construct(private array $copyIds)
    {
    }

    public function forCopy(
        MigrationSourceRecord $copy,
        MigrationSourceRecord $book,
        MigrationPlanningTarget $target
    ): CurrentV1CatalogItemDependencies {
        unset($book, $target);
        if (!in_array($copy->sourceId(), $this->copyIds, true)) {
            throw new \RuntimeException(
                "Dependency provider must not receive a terminal CURRENT Copy."
            );
        }
        return new CurrentV1CatalogItemDependencies(
            new LibraryCatalogSelection(new LibraryBookTypeId("type-a")),
            true,
            null,
            CurrentV1ItemEligibility::ItemEligible
        );
    }
}
