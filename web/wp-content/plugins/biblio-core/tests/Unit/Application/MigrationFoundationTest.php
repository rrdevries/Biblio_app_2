<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Migration\MappingDisposition;
use Biblio\Core\Application\Migration\MigrationEvidence;
use Biblio\Core\Application\Migration\MigrationMode;
use Biblio\Core\Application\Migration\MigrationRecordOutcome;
use Biblio\Core\Application\Migration\MigrationRun;
use Biblio\Core\Application\Migration\MigrationTargetMapping;
use Biblio\Core\Application\Migration\QuarantineReason;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MigrationFoundationTest extends TestCase
{
    public function testEvidenceSerializationIsDeterministicAndBounded(): void
    {
        $left = ["z" => 1, "a" => ["b" => 2, "a" => 1]];
        $right = ["a" => ["a" => 1, "b" => 2], "z" => 1];

        self::assertSame(MigrationEvidence::canonicalJson($left), MigrationEvidence::canonicalJson($right));
        self::assertSame(MigrationEvidence::hash($left), MigrationEvidence::hash($right));
        self::assertSame('{"a":{"a":1,"b":2},"z":1}', MigrationEvidence::canonicalJson($left));

        try {
            MigrationEvidence::canonicalJson(["value" => str_repeat("x", MigrationEvidence::MAX_JSON_BYTES)]);
            self::fail("Oversized migration evidence was accepted.");
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
    }

    public function testLogicalObservationIdentityIncludesSnapshotAndPayloadHash(): void
    {
        $runA = $this->migrationRun("run-a", "snapshot-a");
        $runB = $this->migrationRun("run-b", "snapshot-b");
        $payloadA = ["title" => "A"];
        $payloadB = ["title" => "B"];
        $at = new DateTimeImmutable("2026-09-09T12:00:00+00:00");

        $first = \Biblio\Core\Application\Migration\SourceObservation::observe(
            $runA,
            "book",
            "books/1",
            MigrationEvidence::hash($payloadA),
            $payloadA,
            null,
            $at
        );
        $same = \Biblio\Core\Application\Migration\SourceObservation::observe(
            $runA,
            "book",
            "books/1",
            MigrationEvidence::hash($payloadA),
            $payloadA,
            null,
            $at
        );
        $later = \Biblio\Core\Application\Migration\SourceObservation::observe(
            $runB,
            "book",
            "books/1",
            MigrationEvidence::hash($payloadB),
            $payloadB,
            null,
            $at
        );

        self::assertSame($first->id(), $same->id());
        self::assertNotSame($first->id(), $later->id());
        self::assertSame($first->sourceFamily(), $later->sourceFamily());
        self::assertSame($first->sourceId(), $later->sourceId());
    }

    public function testLossPolicyRequiresExplicitValidOutcomes(): void
    {
        $mapping = new MigrationTargetMapping("work", "work-1", MappingDisposition::Created);
        self::assertCount(1, MigrationRecordOutcome::mapped([$mapping])->mappings());
        self::assertSame(
            "invalid_isbn_claim",
            MigrationRecordOutcome::quarantined(
                QuarantineReason::InvalidIsbnClaim,
                "Synthetic invalid claim."
            )->reasonCode()
        );
        self::assertSame(
            \Biblio\Core\Application\Migration\MigrationDisposition::PreservedDeferred,
            MigrationRecordOutcome::preserved("wishlist_deferred")->disposition()
        );

        $this->expectException(ValidationException::class);
        MigrationRecordOutcome::intentionallyDropped(" ");
    }

    public function testDuplicateMappingAndMappedWithoutTargetFailClosed(): void
    {
        $mapping = new MigrationTargetMapping("item", "item-1", MappingDisposition::Created);
        try {
            MigrationRecordOutcome::mapped([]);
            self::fail("Mapped outcome without target was accepted.");
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(ValidationException::class);
        MigrationRecordOutcome::mapped([$mapping, $mapping]);
    }

    public function testKnownMig01GapCategoriesAreRepresentableWithoutDomainBehavior(): void
    {
        $deferredReasons = [
            "wishlist_target_deferred",
            "unknown_date_reading_truth",
            "legacy_archive_reason",
            "open_circulation_deferred",
            "rating_source_time_unknown",
            "cover_reference_deferred",
            "reading_goal_deferred",
        ];
        foreach ($deferredReasons as $reason) {
            self::assertSame($reason, MigrationRecordOutcome::preserved($reason)->reasonCode());
        }
        foreach ([
            QuarantineReason::InvalidIsbnClaim,
            QuarantineReason::AmbiguousContributor,
            QuarantineReason::UnknownTaxonomyMapping,
        ] as $reason) {
            self::assertSame(
                $reason->value,
                MigrationRecordOutcome::quarantined($reason, "Synthetic conflict.")->reasonCode()
            );
        }

        $this->expectException(ValidationException::class);
        MigrationRecordOutcome::failed("unsafe reason with spaces", true);
    }

    private function migrationRun(string $id, string $snapshot): MigrationRun
    {
        return MigrationRun::start(
            $id,
            "biblio-v1",
            $snapshot,
            str_repeat("a", 64),
            "synthetic-1",
            "mig-test-1",
            new UserId("100"),
            new LibraryId("library-1"),
            MigrationMode::Apply,
            new DateTimeImmutable("2026-09-09T12:00:00+00:00")
        );
    }
}
