<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Cutover\{
    FinalPopulationContractBundle, FinalSourceApprovalState, FinalSourceCompatibilityReport,
    FinalSourceDriftEngine, FinalSourceObservation, FinalSourcePackageIdentity, FinalSourceSnapshot,
    MapperContractInventory, ReviewedClassificationQueueDisposition, SourceDrift, SourceDriftCategory
};
use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewedClassificationQueueDispositionTest extends TestCase
{
    private const OLD_QUEUE = "a37d40c73f73b496500d87cbc564127b00d54f828c0e34739fb3a1864ea4b864";
    private const NEW_QUEUE = "4a0e25f651005c83f021fd9d7350cbab03196fff2c9dd663affb520e28ab0bcd";
    private const SHAPE = "dcb452a982945e5e2957930d83d36af5ceee19805ec0c3b30529ae8f44f6e49e";

    public function testExactReviewedQueueKeepsMechanicalIAndBindsPreservationInReportAndBundle(): void
    {
        $old = $this->snapshot(false);
        $new = $this->snapshot(true);
        $report = (new FinalSourceDriftEngine())->compare($old, $new);
        $row = $this->queueDrift($report);
        self::assertSame(SourceDriftCategory::I, $row->category());
        self::assertSame(SourceDriftCategory::C, $row->effectiveCategory());
        $evidence = $row->toArray();
        self::assertSame("stable_identity_material_or_structural_evidence_changed", $evidence["reason_code"]);
        self::assertSame(self::OLD_QUEUE, $evidence["reference_payload_hash"]);
        self::assertSame(self::NEW_QUEUE, $evidence["candidate_payload_hash"]);
        self::assertTrue($evidence["contract_review_required"]);
        self::assertFalse($evidence["effective_contract_review_required"]);
        $review = $evidence["reviewed_disposition"];
        self::assertSame("CONTRACT COMPATIBLE WITH PRESERVATION", $review["reviewed_disposition"]);
        self::assertSame(446, $review["reviewed_queue_population"]["reference_count"]);
        self::assertSame(450, $review["reviewed_queue_population"]["candidate_count"]);
        self::assertSame(1208, $review["unchanged_classification_observations"]["count"]);
        self::assertTrue($review["preservation"]["required"]);
        self::assertFalse($review["preservation"]["context_is_book_identity"]);
        self::assertFalse($review["classification_mapper_semantics_changed"]);
        self::assertSame(FinalSourceApprovalState::ReviewedCompatible, $report->approvalState());
        self::assertSame("MAPPING_CONTRACT_COMPATIBLE", $report->compatibility());
        self::assertSame(1, $report->toArray()["category_counts"]["I"]);
        self::assertSame(0, $report->toArray()["effective_category_counts"]["I"]);
        self::assertSame(1, $report->toArray()["effective_category_counts"]["C"]);
        $bundle = FinalPopulationContractBundle::fromReport($new, $report);
        self::assertSame($report->reviewedDispositions(), $bundle->toArray()["reviewed_dispositions"]);
        self::assertSame($bundle->digest(), FinalPopulationContractBundle::fromReport(
            $new, (new FinalSourceDriftEngine())->compare($old, $new)
        )->digest());
        $mechanical = new FinalSourceCompatibilityReport($old, $new, [new SourceDrift(
            "classifications", "v1.classification_review_vector", "classification-review-queue",
            SourceDriftCategory::I, "stable_identity_material_or_structural_evidence_changed",
            self::OLD_QUEUE, self::NEW_QUEUE
        )]);
        self::assertNotSame($report->digest(), $mechanical->digest());
        self::assertNotSame($bundle->digest(), FinalPopulationContractBundle::fromReport($new, $mechanical)->digest());
    }

    #[DataProvider("packageMismatches")]
    public function testDifferentPackageOrManifestFailsClosed(bool $candidate, string $field): void
    {
        $value = str_ends_with($field, "sha256") ? str_repeat("f", 64) : "other-package-value";
        $this->assertHeld(
            $this->snapshot(false, packageChanges: $candidate ? [] : [$field => $value]),
            $this->snapshot(true, packageChanges: $candidate ? [$field => $value] : [])
        );
    }

    public static function packageMismatches(): iterable
    {
        foreach ([false, true] as $candidate) {
            foreach (["logical_package_id", "archive_sha256", "manifest_sha256", "source_family", "source_version", "adapter_id"] as $field) {
                yield ($candidate ? "candidate " : "reference ") . $field => [$candidate, $field];
            }
        }
    }

    #[DataProvider("populationMismatches")]
    public function testChangedPopulationCannotBorrowExactPackageClaim(string $type, string $change): void
    {
        $rows = $this->population();
        foreach ($rows as $index => $row) {
            if ($row["source_type"] !== $type) { continue; }
            if ($change === "removed") { unset($rows[$index]); }
            elseif ($change === "renamed") { $rows[$index]["source_id"] .= "/renamed"; }
            else { $rows[$index]["payload_hash"] = DeterministicJson::hash(["synthetic_change" => $change]); }
            break;
        }
        // Also changes on the reference side must not be silently accepted.
        $this->assertHeld($this->snapshot(false), $this->snapshot(true, population: array_values($rows)));
        $this->assertHeld($this->snapshot(false, population: array_values($rows)), $this->snapshot(true));
    }

    public static function populationMismatches(): iterable
    {
        yield "changed classification definition" => ["v1.classification_definition", "changed definition"];
        yield "renamed classification identity" => ["v1.classification_definition", "renamed"];
        yield "changed alias rule" => ["v1.classification_review_vector", "changed alias"];
        yield "removed alias population" => ["v1.classification_review_vector", "removed"];
        yield "changed Book assignment" => ["v1.classification_vector", "changed assignment"];
        yield "changed assignment array order" => ["v1.classification_vector", "changed ordered source array"];
        yield "removed Book assignment" => ["v1.classification_vector", "removed"];
        yield "unreviewed taxonomy value" => ["v1.classification_definition", "new taxonomy value"];
    }

    #[DataProvider("queueMismatches")]
    public function testAnyUnreviewedQueuePayloadFailsClosed(array $syntheticQueue): void
    {
        // Complete payload hashing preserves contexts, status, resolution, order and row population.
        $hash = DeterministicJson::hash($syntheticQueue);
        $this->assertHeld($this->snapshot(false), $this->snapshot(true, queueChanges: ["payload_hash" => $hash]));
        $this->assertHeld($this->snapshot(false, queueChanges: ["payload_hash" => $hash]), $this->snapshot(true));
    }

    public static function queueMismatches(): iterable
    {
        $entry = ["term" => "Synthetic term", "source" => "open_library", "status" => "pending", "resolution" => null, "contexts" => ["Synthetic private context"]];
        yield "removed queue entry" => [[]];
        yield "different queue population" => [[$entry, $entry]];
        yield "changed resolution" => [[array_replace($entry, ["resolution" => ["action" => "map"]])]];
        yield "changed review status" => [[array_replace($entry, ["status" => "ignored"])]];
        yield "changed accepted status" => [[array_replace($entry, ["status" => true])]];
        yield "context supplied as Book identity" => [[array_replace($entry, ["contexts" => ["book-identity-sentinel"]])]];
        yield "context removed" => [[array_replace($entry, ["contexts" => []])]];
        yield "unreviewed taxonomy term" => [[array_replace($entry, ["term" => "Anders"])]];
    }

    public function testOtherShapeStateTypeAndAdditionalClassificationObservationFailClosed(): void
    {
        foreach ([
            ["shape_hash" => str_repeat("f", 64)], ["state" => "conflict"],
            ["semantic_class" => "unreviewed"], ["structural" => false],
            ["source_type" => "v1.classification_vector"],
        ] as $changes) {
            $this->assertHeld($this->snapshot(false), $this->snapshot(true, queueChanges: $changes));
        }
        $extra = new FinalSourceObservation("classifications", "v1.classification_vector", "context-inferred-book", str_repeat("a", 64), self::SHAPE, "reviewed_current_v1_shape");
        $this->assertHeld($this->snapshot(false), $this->snapshot(true, extra: [$extra]));
    }

    public function testBothApprovedDispositionsRetainIndependentReadingRoundHold(): void
    {
        $old = $this->snapshot(false, extra: $this->authorAndReadingObservations(false));
        $new = $this->snapshot(true, extra: $this->authorAndReadingObservations(true));
        $report = (new FinalSourceDriftEngine())->compare($old, $new);
        self::assertCount(2, $report->reviewedDispositions());
        self::assertSame(2, $report->toArray()["category_counts"]["E"]);
        self::assertSame(1, $report->toArray()["category_counts"]["I"]);
        self::assertSame(1, $report->toArray()["effective_category_counts"]["E"]);
        self::assertSame(0, $report->toArray()["effective_category_counts"]["I"]);
        self::assertSame(2, $report->toArray()["effective_category_counts"]["C"]);
        $remaining = array_values(array_filter($report->drift(), fn ($d): bool => $d->effectiveCategory()->requiresContractReview()));
        self::assertCount(1, $remaining);
        self::assertSame("rr-1-20260915T133135697-na", $remaining[0]->toArray()["source_id"]);
        self::assertNull($remaining[0]->reviewedDisposition());
        self::assertSame("CONTRACT_REVIEW_REQUIRED", $report->compatibility());
        self::assertSame(FinalSourceApprovalState::ReviewRequired, FinalPopulationContractBundle::fromReport($new, $report)->approvalState());
    }

    public function testReportAndBundleRejectRebindingAndRehearsalApproval(): void
    {
        $old = $this->snapshot(false); $new = $this->snapshot(true);
        $report = (new FinalSourceDriftEngine())->compare($old, $new);
        foreach (["report", "bundle", "approval"] as $case) {
            try {
                if ($case === "report") {
                    new FinalSourceCompatibilityReport($old, $this->snapshot(true, queueChanges: ["payload_hash" => str_repeat("f", 64)]), $report->drift());
                } elseif ($case === "bundle") {
                    FinalPopulationContractBundle::fromReport($this->snapshot(true, packageChanges: ["logical_package_id" => "different"]), $report);
                } else {
                    new FinalPopulationContractBundle($new, $report, new MapperContractInventory(), FinalSourceApprovalState::ApprovedForRehearsal);
                }
                self::fail("Disposition was rebound or granted rehearsal authority.");
            } catch (ValidationException) { self::assertTrue(true); }
        }
    }

    private function assertHeld(FinalSourceSnapshot $old, FinalSourceSnapshot $new): void
    {
        self::assertNull(ReviewedClassificationQueueDisposition::forSnapshots($old, $new));
        $report = (new FinalSourceDriftEngine())->compare($old, $new);
        self::assertSame([], $report->reviewedDispositions());
        self::assertSame("CONTRACT_REVIEW_REQUIRED", $report->compatibility());
        self::assertStringNotContainsString("Synthetic private context", json_encode($report->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString("book-identity-sentinel", json_encode($report->toArray(), JSON_THROW_ON_ERROR));
    }

    private function queueDrift(FinalSourceCompatibilityReport $report): SourceDrift
    {
        foreach ($report->drift() as $d) {
            if ($d->toArray()["source_id"] === "classification-review-queue") { return $d; }
        }
        self::fail("Missing queue observation.");
    }

    /** @return list<array<string,mixed>> */
    private function population(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__ . "/../../Fixtures/final-classification-reviewed-observations.json"), true, 512, JSON_THROW_ON_ERROR);
        $rows = [];
        foreach ($fixture["groups"] as $group) {
            foreach ($group["source_ids"] as $id) {
                $rows[] = [...$group["observation"], "source_id" => $id];
            }
        }
        return $rows;
    }

    private function snapshot(bool $candidate, array $packageChanges = [], array $queueChanges = [], ?array $population = null, array $extra = []): FinalSourceSnapshot
    {
        $p = array_replace([
            "logical_package_id" => $candidate ? "final-current-20260923t124538449z-89b2ba3e94f9" : "reviewed-current-35a18156490f",
            "archive_sha256" => $candidate ? "89b2ba3e94f916d45e63f5aaa3595afac6241473f89d11eb062a165f053c8d9a" : "835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c",
            "manifest_sha256" => $candidate ? "43306b888056daeed2c3837aba6538befbb94caccc43b1c5d37c77dc8ff52480" : "35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67",
            "source_family" => "biblio-v1", "source_version" => "books-29.authors-2.reading-goals-2", "adapter_id" => "current-v1-json-29",
        ], $packageChanges);
        $rows = $population ?? $this->population();
        $rows[] = array_replace([
            "domain" => "classifications", "source_type" => "v1.classification_review_vector", "source_id" => "classification-review-queue",
            "payload_hash" => $candidate ? self::NEW_QUEUE : self::OLD_QUEUE, "shape_hash" => self::SHAPE,
            "semantic_class" => "reviewed_current_v1_shape", "structural" => true, "state" => "ordinary",
        ], $queueChanges);
        $observations = array_map(static fn (array $r): FinalSourceObservation => new FinalSourceObservation(
            $r["domain"], $r["source_type"], $r["source_id"], $r["payload_hash"], $r["shape_hash"], $r["semantic_class"], $r["structural"], $r["state"]
        ), $rows);
        return new FinalSourceSnapshot(new FinalSourcePackageIdentity(...array_values($p)), [...$observations, ...$extra], ["v1.book" => 1139], [], []);
    }

    /** @return list<FinalSourceObservation> */
    private function authorAndReadingObservations(bool $candidate): array
    {
        return [
            new FinalSourceObservation("catalog_books", "v1.book", "1771014295306",
                $candidate ? "d7228e61b0c367a78dce0fe5bdd1a8cfdbebc36ce97864df208f8294f7490c0a" : "6e4a63b4ef007488a788ad4b7c1dc74a2e4b6cc5ae773a0a23193e6baaaa6f72",
                $candidate ? "768ef09a41016085f7834f467585c01c7ce5e9af3d991315a38b441e11388c0d" : "b955461a1b74ed184d83f00fbbf1a6a5d25365dbad580e10a3d51fff2f8d6710",
                "reviewed_current_v1_shape"),
            new FinalSourceObservation("authors_contributors", "v1.contributor_vector", "v1.book/1771014295306/contributors",
                "4ba30f5f02b3d18cfd2895913bd9209dad78b9a8415bffc856d990dc1fb88c00", self::SHAPE, "reviewed_current_v1_shape"),
            new FinalSourceObservation("reading_rounds", "v1.reading_round", "rr-1-20260915T133135697-na",
                $candidate ? "145d3f0e42f2451e0d68a63c1c6bd0aa60e33faea0278df7a081ffb9b08abdf0" : "06e3d14f8a801bc2094333ae5f8fe97e1e388c3fe076202e883d05416e75dff7",
                str_repeat($candidate ? "e" : "d", 64), "reviewed_current_v1_shape"),
        ];
    }
}
