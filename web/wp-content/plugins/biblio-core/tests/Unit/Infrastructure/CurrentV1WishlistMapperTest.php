<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Identity\{PersonalMigrationTarget,PersonalMigrationTargetReadiness};
use Biblio\Core\Application\Migration\Preservation\{PreservedSourceEvidenceMigrationParticipant,PreservedSourceEvidencePlan,PreservedSourceEvidencePrivacy};
use Biblio\Core\Application\Migration\Runner\{DeterministicJson,MigrationPlanningTarget,MigrationRunnerFailure,MigrationSourceInspection,MigrationSourceMappingResult,MigrationSourcePackage,MigrationSourceProfile,MigrationSourceRecord};
use Biblio\Core\Application\Migration\Wishlist\{WishlistMigrationParticipant,WishlistPlan};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\{CurrentV1CatalogMapper,CurrentV1CatalogSourceIds,CurrentV1ReviewedWishlistContract,CurrentV1SourceAdapter,CurrentV1WishlistMapper,CurrentV1WishlistMappingReason};
use Biblio\Core\Library\{LibraryId,LibraryName};
use PHPUnit\Framework\TestCase;

final class CurrentV1WishlistMapperTest extends TestCase
{
    private const DIGEST = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    public function testReviewedLegacyEditionProducesWorkOnlyPlanAndOnePreservation(): void
    {
        $result = $this->map($this->wishlist());
        $active = $this->record($result, WishlistMigrationParticipant::SOURCE_TYPE);
        $plan = $active->typedPlan();
        self::assertInstanceOf(WishlistPlan::class, $plan);
        self::assertSame("target-user", $plan->targetUserId()->value());
        self::assertSame(CurrentV1CatalogSourceIds::work("book-1"), $plan->workSourceId());
        self::assertSame("work_only", $plan->canonicalPayload()["specificity"]);
        self::assertNull($plan->canonicalPayload()["edition_source_id"]);
        self::assertSame("active", $plan->canonicalPayload()["lifecycle"]);
        self::assertSame("2026-09-01T10:00:00.000000Z", $plan->canonicalPayload()["created_at"]);
        self::assertSame("2026-09-02T11:30:00.000000Z", $plan->canonicalPayload()["updated_at"]);
        self::assertSame(
            ["catalog_work:" . CurrentV1CatalogSourceIds::work("book-1")],
            $active->references()
        );

        $preserved = $this->record(
            $result,
            PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE
        );
        $evidence = $preserved->typedPlan();
        self::assertInstanceOf(PreservedSourceEvidencePlan::class, $evidence);
        self::assertSame("v1.wishlist_item/wish-1/auxiliary", $evidence->sourceIdentity());
        self::assertSame("current_v1_wishlist_auxiliary", $evidence->evidenceType());
        self::assertSame("wishlist_auxiliary_evidence_preserved", $evidence->reasonCode());
        self::assertSame(PreservedSourceEvidencePrivacy::RestrictedSource, $evidence->privacy());
        self::assertSame("wishlistItems", $evidence->sourceCollection());
        self::assertSame("auxiliaryEvidence", $evidence->sourceField());
        self::assertSame(DeterministicJson::hash([
            "source_slot" => "wishlistItems",
            "raw_type" => "edition",
            "title_group_key" => "private-group",
            "desired_carrier" => "Fysiek boek",
        ]), $evidence->evidenceSha256());

        $payload = $plan->canonicalPayload();
        self::assertArrayNotHasKey("title_group_key", $payload);
        self::assertArrayNotHasKey("desired_carrier", $payload);
        self::assertArrayNotHasKey("fulfilled_copy_id", $payload);
    }

    public function testMetadataDoesNotChangeIdentityAndPayloadsDivergeOnlyByOwnedSemantics(): void
    {
        $first = $this->map($this->wishlist());
        $metadataChanged = $this->map($this->wishlist([
            "titleSnapshot" => "Different private snapshot",
        ]));
        $updatedChanged = $this->map($this->wishlist([
            "updatedAt" => "2026-09-03T11:30:00.000Z",
        ]));
        $auxiliaryChanged = $this->map($this->wishlist([
            "titleGroupKey" => "different-private-group",
        ]));

        $firstActive = $this->record($first, WishlistMigrationParticipant::SOURCE_TYPE);
        $firstPreserved = $this->record($first, PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE);
        foreach ([$metadataChanged, $updatedChanged, $auxiliaryChanged] as $result) {
            self::assertSame(
                $firstActive->sourceId(),
                $this->record($result, WishlistMigrationParticipant::SOURCE_TYPE)->sourceId()
            );
            self::assertSame(
                $firstPreserved->sourceId(),
                $this->record($result, PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE)->sourceId()
            );
        }
        self::assertSame(
            $firstActive->payloadHash(),
            $this->record($metadataChanged, WishlistMigrationParticipant::SOURCE_TYPE)->payloadHash()
        );
        self::assertNotSame(
            $firstActive->payloadHash(),
            $this->record($updatedChanged, WishlistMigrationParticipant::SOURCE_TYPE)->payloadHash()
        );
        self::assertSame(
            $firstPreserved->payloadHash(),
            $this->record($updatedChanged, PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE)->payloadHash()
        );
        self::assertNotSame(
            $firstPreserved->payloadHash(),
            $this->record($auxiliaryChanged, PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE)->payloadHash()
        );
    }

    public function testUnreviewedLifecycleFulfillmentAndChronologyFailClosed(): void
    {
        foreach ([
            [["type" => "work"], CurrentV1WishlistMappingReason::UnreviewedType],
            [["status" => "fulfilled"], CurrentV1WishlistMappingReason::InvalidLifecycle],
            [["fulfilledCopyId" => "copy-1"], CurrentV1WishlistMappingReason::UnsupportedFulfillment],
            [["createdAt" => "2026-09-03T10:00:00.000Z"], CurrentV1WishlistMappingReason::InvalidTimestamp],
        ] as [$change, $reason]) {
            $result = $this->map($this->wishlist($change));
            self::assertSame([], $this->records($result, WishlistMigrationParticipant::SOURCE_TYPE));
            self::assertSame([], $this->records($result, PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE));
            self::assertSame(1, $this->quarantineCount($result));
            self::assertSame($reason->value, $this->quarantineReason($result));
        }
    }

    public function testManifestDriftFailsClosed(): void
    {
        $this->expectException(MigrationRunnerFailure::class);
        $this->expectExceptionMessage("Wishlist contract does not match");
        $this->map($this->wishlist(), str_repeat("b", 64));
    }

    private function map(
        MigrationSourceRecord $wishlist,
        string $contractDigest = self::DIGEST
    ): MigrationSourceMappingResult {
        $book = new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, "book-1", [
            "id" => "book-1",
            "title" => "Synthetic Work",
            "isbn" => "",
            "isbn10" => "",
            "isbn13" => "",
            "variantOfBookId" => "",
            "containedWorks" => [],
        ]);
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], self::DIGEST),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            [$book, $wishlist],
            []
        );
        return (new CurrentV1CatalogMapper(
            wishlistMapper: new CurrentV1WishlistMapper(
                new CurrentV1ReviewedWishlistContract($contractDigest)
            )
        ))->map($inspection, $this->target());
    }

    /** @param array<string,mixed> $replace */
    private function wishlist(array $replace = []): MigrationSourceRecord
    {
        $payload = array_replace([
            "authorSnapshot" => "Private Author",
            "bookId" => "book-1",
            "createdAt" => "2026-09-01T10:00:00.000Z",
            "desiredBinding" => "",
            "desiredCarrier" => "Fysiek boek",
            "desiredLanguage" => "",
            "fulfilledAt" => "",
            "fulfilledCopyId" => "",
            "id" => "wish-1",
            "notes" => "",
            "priority" => "",
            "status" => "active",
            "titleGroupKey" => "private-group",
            "titleSnapshot" => "Private Title",
            "type" => "edition",
            "updatedAt" => "2026-09-02T11:30:00.000Z",
        ], $replace);
        $references = [CurrentV1SourceAdapter::BOOK . ":book-1"];
        if (($payload["fulfilledCopyId"] ?? "") !== "") {
            $references[] = CurrentV1SourceAdapter::COPY . ":" . $payload["fulfilledCopyId"];
        }
        return new MigrationSourceRecord(
            CurrentV1SourceAdapter::WISHLIST_ITEM,
            "wish-1",
            $payload,
            $references
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

    /** @return list<MigrationSourceRecord> */
    private function records(MigrationSourceMappingResult $result, string $type): array
    {
        return array_values(array_filter(
            $result->records(),
            static fn (MigrationSourceRecord $record): bool =>
                $record->sourceType() === $type
        ));
    }

    private function record(MigrationSourceMappingResult $result, string $type): MigrationSourceRecord
    {
        $records = $this->records($result, $type);
        self::assertCount(1, $records);
        return $records[0];
    }

    private function quarantineCount(MigrationSourceMappingResult $result): int
    {
        $count = 0;
        foreach ($result->findings() as $finding) {
            if ($finding->disposition()->value === "quarantined") {
                $count += $finding->toArray()["occurrence_count"];
            }
        }
        return $count;
    }

    private function quarantineReason(MigrationSourceMappingResult $result): string
    {
        foreach ($result->findings() as $finding) {
            if ($finding->disposition()->value === "quarantined") {
                return $finding->reasonCode();
            }
        }
        self::fail("Expected one quarantined Wishlist finding.");
    }
}
