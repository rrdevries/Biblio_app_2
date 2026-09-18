<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Runner\MigrationSourceInspection;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackage;
use Biblio\Core\Application\Migration\Runner\MigrationSourceProfile;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Catalog\AcquisitionMethod;
use Biblio\Core\Infrastructure\Migration\CurrentV1ItemEligibility;
use Biblio\Core\Infrastructure\Migration\CurrentV1ItemLocalMapper;
use Biblio\Core\Infrastructure\Migration\CurrentV1ItemLocalMappingReason;
use Biblio\Core\Infrastructure\Migration\CurrentV1ReviewedItemLocalContract;
use Biblio\Core\Infrastructure\Migration\CurrentV1ReviewedCopyExclusionContract;
use Biblio\Core\Infrastructure\Migration\CurrentV1SourceAdapter;
use PHPUnit\Framework\TestCase;

final class CurrentV1ItemLocalMapperTest extends TestCase
{
    private const MANIFEST =
        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    public function testExplicitAcquisitionValuesMapWithoutDefaults(): void
    {
        $result = $this->map([
            $this->copy("bought", [
                "type" => "bought",
                "date" => ["value" => "1987", "precision" => "year"],
                "source" => "Synthetic shop",
            ]),
            $this->copy("received", [
                "type" => "received",
                "date" => ["value" => "2004-09", "precision" => "month"],
            ]),
            $this->copy("missing-type", [
                "date" => ["value" => "2020-02-29", "precision" => "day"],
            ]),
            $this->copy("absent", null),
        ]);

        $bought = $result->forCopy("bought");
        self::assertSame(CurrentV1ItemEligibility::ItemEligible, $bought?->eligibility());
        self::assertSame(
            AcquisitionMethod::Purchased,
            $bought?->localDetails()?->acquisitionMethod()
        );
        self::assertSame("Synthetic shop", $bought?->localDetails()?->acquiredVia());
        self::assertSame(
            ["year" => 1987, "month" => null, "day" => null],
            $bought?->localDetails()?->inLibrarySince()?->toArray()
        );
        self::assertNull($bought?->localDetails()?->paidAmount());
        self::assertNull($bought?->localDetails()?->condition());
        self::assertNull($bought?->localDetails()?->signed());
        self::assertNull($bought?->localDetails()?->signedBy());
        self::assertNull($bought?->localDetails()?->copyLimitation());
        self::assertNull($bought?->localDetails()?->dustJacket());
        self::assertNull($bought?->localDetails()?->inscription());
        self::assertNull($bought?->localDetails()?->provenance());
        self::assertNull($bought?->localDetails()?->completeness());

        $received = $result->forCopy("received")?->localDetails();
        self::assertSame(AcquisitionMethod::Received, $received?->acquisitionMethod());
        self::assertSame(
            ["year" => 2004, "month" => 9, "day" => null],
            $received?->inLibrarySince()?->toArray()
        );

        $missing = $result->forCopy("missing-type")?->localDetails();
        self::assertNull($missing?->acquisitionMethod());
        self::assertSame(
            ["year" => 2020, "month" => 2, "day" => 29],
            $missing?->inLibrarySince()?->toArray()
        );
        self::assertNull($result->forCopy("absent")?->localDetails());
        self::assertNotContains(
            AcquisitionMethod::Other,
            array_map(
                static fn (string $id): ?AcquisitionMethod =>
                    $result->forCopy($id)?->localDetails()?->acquisitionMethod(),
                ["bought", "received", "missing-type", "absent"]
            )
        );
    }

    public function testBorrowedIsTerminalAndCannotCarryAnItemPlan(): void
    {
        $result = $this->map([$this->copy("borrowed", [
            "type" => "borrowed",
            "date" => ["value" => "2024-01-02", "precision" => "day"],
            "source" => "Private counterparty",
        ])]);
        $mapping = $result->forCopy("borrowed");

        self::assertSame(
            CurrentV1ItemEligibility::NotLibraryItemExternalBorrowed,
            $mapping?->eligibility()
        );
        self::assertFalse($mapping?->isItemEligible());
        self::assertNull($mapping?->localDetails());
        self::assertNull($mapping?->preservation());
        self::assertTrue($mapping?->preservationRequired());
        self::assertSame(
            CurrentV1ItemLocalMappingReason::ExternalBorrowedCopyPreserved,
            $mapping?->preservationReason()
        );
        self::assertContains(
            CurrentV1ItemLocalMappingReason::ExternalBorrowedCopyPreserved->value,
            $this->reasons($result->findings())
        );
        self::assertNotContains(
            CurrentV1ItemLocalMappingReason::CopyAuxiliaryEvidencePreserved->value,
            $this->reasons($result->findings())
        );
    }

    public function testAuxiliaryEvidenceIsHashedAndNeverPromotedToItemFields(): void
    {
        $copy = $this->copy("auxiliary", null, [
            "notes" => "Private Copy note",
            "disposal" => ["reason" => "Private disposal", "date" => null],
            "exemplarPhotos" => ["front" => "private-path", "back" => "", "spine" => ""],
        ]);
        $result = $this->map([$copy]);
        $mapping = $result->forCopy("auxiliary");
        $preservation = $mapping?->preservation();

        self::assertNull($mapping?->localDetails());
        self::assertNotNull($preservation);
        self::assertTrue($mapping?->preservationRequired());
        self::assertSame(
            CurrentV1ItemLocalMappingReason::CopyAuxiliaryEvidencePreserved,
            $mapping?->preservationReason()
        );
        self::assertSame(
            CurrentV1ItemLocalMappingReason::CopyAuxiliaryEvidencePreserved->value,
            $preservation?->reason()
        );
        self::assertSame($copy->payloadHash(), $preservation?->canonicalPayload()["source_evidence_hash"]);
        self::assertSame([
            "copy_note_present" => true,
            "disposal_present" => true,
            "exemplar_photo_slot_count" => 1,
            "source_number_field_count" => 3,
        ], $preservation?->canonicalPayload()["evidence"]);
        $encoded = json_encode(
            $preservation?->canonicalPayload(),
            JSON_THROW_ON_ERROR
        );
        self::assertStringNotContainsString("Private Copy note", $encoded);
        self::assertStringNotContainsString("Private disposal", $encoded);
        self::assertStringNotContainsString("private-path", $encoded);
    }

    public function testInvalidDateFailsClosedWithoutCurrentTimeSubstitution(): void
    {
        $result = $this->map([$this->copy("invalid-date", [
            "type" => "bought",
            "date" => ["value" => "2021-02-29", "precision" => "day"],
        ])]);
        $mapping = $result->forCopy("invalid-date");

        self::assertSame(
            CurrentV1ItemEligibility::InvalidItemLocalAcquisitionDate,
            $mapping?->eligibility()
        );
        self::assertNull($mapping?->localDetails());
        self::assertContains(
            CurrentV1ItemLocalMappingReason::InvalidAcquisitionDate->value,
            $this->reasons($result->findings())
        );
    }

    public function testSamePayloadIsDeterministicAndChangedPayloadChangesEvidenceHash(): void
    {
        $first = $this->copy("replay", ["type" => "bought"]);
        $same = $this->copy("replay", ["type" => "bought"]);
        $changed = $this->copy("replay", ["type" => "received"]);

        $firstPayload = $this->map([$first])->forCopy("replay")
            ?->preservation()?->canonicalPayload();
        $samePayload = $this->map([$same])->forCopy("replay")
            ?->preservation()?->canonicalPayload();
        $changedPayload = $this->map([$changed])->forCopy("replay")
            ?->preservation()?->canonicalPayload();

        self::assertSame($firstPayload, $samePayload);
        self::assertNotSame(
            $firstPayload["source_evidence_hash"],
            $changedPayload["source_evidence_hash"]
        );
    }

    public function testBookLegacyAcquisitionEvidenceIsOnePrivacySafeFinding(): void
    {
        $book = new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, "book-a", [
            "id" => "book-a",
            "acquisition" => ["type" => "received", "source" => "Private giver"],
            "acquiredVia" => "Private legacy context",
            "giftFrom" => "Private giver",
        ]);
        $result = $this->map([$this->copy("copy-a", null)], [$book]);
        $reasons = $this->reasons($result->findings());

        self::assertSame(1, count(array_filter(
            $reasons,
            static fn (string $reason): bool => $reason ===
                CurrentV1ItemLocalMappingReason::BookAcquisitionEvidencePreserved->value
        )));
        $encoded = json_encode(
            array_map(static fn ($finding): array => $finding->toArray(), $result->findings()),
            JSON_THROW_ON_ERROR
        );
        self::assertStringNotContainsString("Private giver", $encoded);
        self::assertStringNotContainsString("Private legacy context", $encoded);
    }

    /**
     * @param list<MigrationSourceRecord> $copies
     * @param list<MigrationSourceRecord> $books
     */
    private function map(array $copies, array $books = []):
        \Biblio\Core\Infrastructure\Migration\CurrentV1ItemLocalMappingResult
    {
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], self::MANIFEST),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            [...$books, ...$copies],
            []
        );
        $byCopy = [];
        foreach ($copies as $copy) {
            $byCopy["source:" . $copy->sourceId()] = $copy;
        }
        $byBook = [];
        foreach ($books as $book) {
            $byBook["source:" . $book->sourceId()] = $book;
        }
        return (new CurrentV1ItemLocalMapper(
            new CurrentV1ReviewedItemLocalContract(self::MANIFEST),
            new CurrentV1ReviewedCopyExclusionContract(self::MANIFEST, [])
        ))->map($inspection, $byCopy, $byBook);
    }

    /** @param array<string,mixed>|null $acquisition @param array<string,mixed> $extra */
    private function copy(
        string $id,
        ?array $acquisition,
        array $extra = []
    ): MigrationSourceRecord {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::COPY, $id, [
            "id" => $id,
            "bookId" => "book-a",
            "acquisition" => $acquisition,
            "condition" => "",
            "copyNumber" => "COPY-" . $id,
            "legacyBookNumber" => "LEGACY-" . $id,
            "sourceBookNumber" => "SOURCE-" . $id,
            "notes" => "",
            "disposal" => null,
            "exemplarPhotos" => ["front" => "", "back" => "", "spine" => ""],
            ...$extra,
        ]);
    }

    /** @param list<\Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding> $findings */
    private function reasons(array $findings): array
    {
        return array_map(static fn ($finding): string => $finding->reasonCode(), $findings);
    }
}
