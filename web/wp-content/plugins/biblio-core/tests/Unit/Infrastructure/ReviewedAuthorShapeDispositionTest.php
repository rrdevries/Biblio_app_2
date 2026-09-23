<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Cutover\{
    FinalPopulationContractBundle, FinalSourceApprovalState,
    FinalSourceCompatibilityReport, FinalSourceDriftEngine, FinalSourceObservation,
    FinalSourcePackageIdentity, FinalSourceSnapshot, SourceDrift, SourceDriftCategory
};
use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewedAuthorShapeDispositionTest extends TestCase
{
    // Golden privacy-safe observations, not private source fixtures or overrideable approval rules.
    private const OLD_PAYLOAD = "6e4a63b4ef007488a788ad4b7c1dc74a2e4b6cc5ae773a0a23193e6baaaa6f72";
    private const NEW_PAYLOAD = "d7228e61b0c367a78dce0fe5bdd1a8cfdbebc36ce97864df208f8294f7490c0a";
    private const OLD_SHAPE = "b955461a1b74ed184d83f00fbbf1a6a5d25365dbad580e10a3d51fff2f8d6710";
    private const NEW_SHAPE = "768ef09a41016085f7834f467585c01c7ce5e9af3d991315a38b441e11388c0d";

    public function testExactApprovedObservationKeepsMechanicalEAndSeparatelyReviewsAsC(): void
    {
        $old = $this->snapshot(false);
        $new = $this->snapshot(true);
        $report = (new FinalSourceDriftEngine())->compare($old, $new);
        $row = $this->bookDrift($report);
        self::assertSame(SourceDriftCategory::E, $row->category());
        self::assertSame(SourceDriftCategory::C, $row->effectiveCategory());
        $evidence = $row->toArray();
        self::assertSame("new_raw_value_enum_or_source_shape", $evidence["reason_code"]);
        self::assertSame(self::OLD_PAYLOAD, $evidence["reference_payload_hash"]);
        self::assertSame(self::NEW_PAYLOAD, $evidence["candidate_payload_hash"]);
        self::assertTrue($evidence["contract_review_required"]);
        self::assertFalse($evidence["effective_contract_review_required"]);
        self::assertSame([
            "authorIds" => ["before" => "ABSENT", "after" => "EMPTY_ARRAY"],
            "authorsLocked" => ["before" => "ABSENT", "after" => "FALSE"],
        ], $evidence["reviewed_disposition"]["field_transitions"]);
        self::assertSame(1, $report->toArray()["category_counts"]["E"]);
        self::assertSame(0, $report->toArray()["effective_category_counts"]["E"]);
        self::assertSame(1, $report->toArray()["effective_category_counts"]["C"]);
        self::assertSame(FinalSourceApprovalState::ReviewedCompatible, $report->approvalState());
        self::assertSame("MAPPING_CONTRACT_COMPATIBLE", $report->compatibility());
        self::assertCount(1, $report->reviewedDispositions());

        $bundle = FinalPopulationContractBundle::fromReport($new, $report);
        self::assertSame($report->reviewedDispositions(), $bundle->toArray()["reviewed_dispositions"]);
        self::assertSame($report->digest(), $bundle->toArray()["compatibility_report_digest"]);
        self::assertSame($bundle->digest(), FinalPopulationContractBundle::fromReport(
            $new, (new FinalSourceDriftEngine())->compare($old, $new)
        )->digest());

        $mechanical = new FinalSourceCompatibilityReport($old, $new, [new SourceDrift(
            "catalog_books", "v1.book", "1771014295306", SourceDriftCategory::E,
            "new_raw_value_enum_or_source_shape", self::OLD_PAYLOAD, self::NEW_PAYLOAD
        )]);
        self::assertSame("CONTRACT_REVIEW_REQUIRED", $mechanical->compatibility());
        self::assertNotSame($mechanical->digest(), $report->digest());
        self::assertNotSame(FinalPopulationContractBundle::fromReport($new, $mechanical)->digest(), $bundle->digest());
    }

    #[DataProvider("bindingMismatches")]
    public function testOtherPackageOrManifestNeverReceivesDisposition(bool $candidate, string $field): void
    {
        $old = $this->snapshot(false, packageChanges: $candidate ? [] : [$field => $this->otherValue($field)]);
        $new = $this->snapshot(true, packageChanges: $candidate ? [$field => $this->otherValue($field)] : []);
        $this->assertHeld((new FinalSourceDriftEngine())->compare($old, $new));
    }

    public static function bindingMismatches(): iterable
    {
        foreach ([false, true] as $candidate) {
            foreach (["logical_package_id", "archive_sha256", "manifest_sha256", "source_family", "source_version", "adapter_id"] as $field) {
                yield ($candidate ? "candidate " : "reference ") . $field => [$candidate, $field];
            }
        }
    }

    #[DataProvider("unapprovedFields")]
    public function testChangedAuthorPayloadCannotReuseGoldenApproval(array $fields): void
    {
        // In production the builder supplies a full raw payload hash. No raw fields are
        // ignored by the disposition. Synthetic changed payloads must fail even if a
        // caller retains the reviewed package claim, Book ID and shape hash.
        $hash = DeterministicJson::hash(["id" => "1771014295306", ...$fields]);
        $report = (new FinalSourceDriftEngine())->compare(
            $this->snapshot(false), $this->snapshot(true, observationChanges: ["payload_hash" => $hash])
        );
        $this->assertHeld($report);
        self::assertStringNotContainsString("Synthetic secret", json_encode($report->toArray(), JSON_THROW_ON_ERROR));
    }

    public static function unapprovedFields(): iterable
    {
        $base = ["authors" => ["Synthetic secret A"], "authorIds" => [], "authorsLocked" => false];
        foreach ([
            "null authorIds" => ["authorIds" => null],
            "new nonempty authorIds" => ["authorIds" => ["stable-a"]],
            "changed stable author ID" => ["authorIds" => ["stable-b"]],
            "true lock" => ["authorsLocked" => true],
            "null lock" => ["authorsLocked" => null],
            "changed names" => ["authors" => ["Synthetic secret changed"]],
            "changed ordering" => ["authors" => ["Synthetic secret B", "Synthetic secret A"]],
            "changed count" => ["authors" => []],
            "occurrence object" => ["authors" => [["name" => "Synthetic secret A"]]],
            "extra author field" => ["authorConfirmed" => true],
            "extra unrelated shape" => ["unexpectedField" => true],
        ] as $label => $changes) {
            yield $label => [array_replace($base, $changes)];
        }
    }

    public function testOtherBookIdAndReferencePayloadCannotReuseApproval(): void
    {
        $this->assertHeld((new FinalSourceDriftEngine())->compare(
            $this->snapshot(false, observationChanges: ["source_id" => "different-book"]),
            $this->snapshot(true, observationChanges: ["source_id" => "different-book"])
        ));
        $this->assertHeld((new FinalSourceDriftEngine())->compare(
            $this->snapshot(false, observationChanges: ["payload_hash" => str_repeat("a", 64)]),
            $this->snapshot(true)
        ));
    }

    public function testChangedShapeStateAndContributorVectorFailClosed(): void
    {
        foreach ([
            ["shape_hash" => str_repeat("f", 64)], ["state" => "conflict"],
            ["semantic_class" => "unreviewed"], ["structural" => true],
        ] as $changes) {
            $this->assertHeld((new FinalSourceDriftEngine())->compare(
                $this->snapshot(false), $this->snapshot(true, observationChanges: $changes)
            ));
        }
        $this->assertHeld((new FinalSourceDriftEngine())->compare(
            $this->snapshot(false), $this->snapshot(true, vectorHash: str_repeat("f", 64))
        ));
        $this->assertHeld((new FinalSourceDriftEngine())->compare(
            $this->snapshot(false), $this->snapshot(true, omitVector: true)
        ));
    }

    public function testAnotherUnreviewedDriftStillBlocksTheReportAndBundle(): void
    {
        $extraOld = new FinalSourceObservation("catalog_books", "v1.book", "other", str_repeat("a", 64), str_repeat("b", 64), "ordinary");
        $extraNew = new FinalSourceObservation("catalog_books", "v1.book", "other", str_repeat("c", 64), str_repeat("d", 64), "ordinary");
        $new = $this->snapshot(true, extra: [$extraNew]);
        $report = (new FinalSourceDriftEngine())->compare($this->snapshot(false, extra: [$extraOld]), $new);
        self::assertSame(SourceDriftCategory::C, $this->bookDrift($report)->effectiveCategory());
        self::assertSame(2, $report->toArray()["category_counts"]["E"]);
        self::assertSame(1, $report->toArray()["effective_category_counts"]["E"]);
        self::assertSame("CONTRACT_REVIEW_REQUIRED", $report->compatibility());
        self::assertSame(FinalSourceApprovalState::ReviewRequired, FinalPopulationContractBundle::fromReport($new, $report)->approvalState());
    }

    public function testDispositionCannotBeReattachedToAnotherReportBinding(): void
    {
        $old = $this->snapshot(false);
        $new = $this->snapshot(true);
        $report = (new FinalSourceDriftEngine())->compare($old, $new);
        $this->expectException(\Biblio\Core\Exception\ValidationException::class);
        new FinalSourceCompatibilityReport($old, $this->snapshot(true, packageChanges: ["logical_package_id" => "other-package"]), $report->drift());
    }

    public function testReviewedCompatibilityCannotBeRelabelledMechanicallyCompatible(): void
    {
        $new = $this->snapshot(true);
        $report = (new FinalSourceDriftEngine())->compare($this->snapshot(false), $new);
        $this->expectException(\Biblio\Core\Exception\ValidationException::class);
        new FinalPopulationContractBundle($new, $report,
            new \Biblio\Core\Application\Migration\Cutover\MapperContractInventory(),
            FinalSourceApprovalState::MechanicallyCompatible);
    }

    public function testBundleRejectsDifferentCandidatePackageOrPopulation(): void
    {
        $report = (new FinalSourceDriftEngine())->compare($this->snapshot(false), $this->snapshot(true));
        foreach ([
            $this->snapshot(true, packageChanges: ["logical_package_id" => "other-package"]),
            $this->snapshot(true, extra: [new FinalSourceObservation("catalog_books", "v1.book", "another", str_repeat("a", 64), str_repeat("b", 64), "ordinary")]),
        ] as $candidate) {
            try {
                FinalPopulationContractBundle::fromReport($candidate, $report);
                self::fail("Reviewed report rebound to a different candidate.");
            } catch (\Biblio\Core\Exception\ValidationException $exception) {
                self::assertSame("Final population bundle snapshot does not match its report.", $exception->getMessage());
            }
        }
    }

    public function testReviewedCompatibilityNeedsExplicitApprovalAndExactBinding(): void
    {
        $new = $this->snapshot(true);
        $report = (new FinalSourceDriftEngine())->compare($this->snapshot(false), $new);
        $bundle = FinalPopulationContractBundle::fromReport($new, $report);
        $intake = new \Biblio\Core\Application\Migration\Cutover\FinalSourceIntakeReceipt(
            $new->package(),
            new \Biblio\Core\Application\Migration\Cutover\FinalSourceExportProvenance(
                "2026-09-23T12:00:00Z", "synthetic-operator", "synthetic-export", "1", str_repeat("a", 64),
                "synthetic-build", "synthetic-runtime", "synthetic-freeze"
            ),
            new \Biblio\Core\Application\Migration\Cutover\FinalSourceRetentionMetadata("synthetic-retained", "not_verified", "not_verified"),
            new \Biblio\Core\Application\Migration\Runner\MigrationSourcePackage("/synthetic", [], $new->package()->manifestSha256()),
            "synthetic.zip", 1, "synthetic-locator"
        );
        $planningReview = [
            "package_digest" => $new->package()->digest(), "bundle_digest" => $bundle->digest(),
            "source_profile_digest" => str_repeat("a", 64), "quarantine" => [],
            "author_exceptions" => [], "occurrence_exceptions" => [], "copy_exclusions" => [],
        ];
        $planning = new \Biblio\Core\Application\Migration\Cutover\FinalSourcePlanningContext(
            $intake, $bundle, $planningReview, "/synthetic.zip"
        );
        self::assertSame(FinalSourceApprovalState::ReviewedCompatible, $planning->bundle->approvalState());
        self::assertSame($bundle->digest(), $planning->review["bundle_digest"]);
        $approvedReview = ["state" => "approved_for_rehearsal", "review_id" => "synthetic-review", ...$planningReview];
        $approved = new \Biblio\Core\Application\Migration\Cutover\ApprovedRehearsalSource(
            $intake, $bundle, $approvedReview, "/synthetic.zip"
        );
        self::assertSame($planningReview, $approved->planningContext()->review);
        foreach ([
            "no approval" => $planningReview,
            "wrong state" => ["state" => "reviewed_compatible", "review_id" => "synthetic-review", ...$planningReview],
            "wrong package" => array_replace($approvedReview, ["package_digest" => str_repeat("b", 64)]),
            "wrong bundle" => array_replace($approvedReview, ["bundle_digest" => str_repeat("b", 64)]),
        ] as $label => $review) {
            try {
                new \Biblio\Core\Application\Migration\Cutover\ApprovedRehearsalSource(
                    $intake, $bundle, $review, "/synthetic.zip"
                );
                self::fail($label . " accepted for rehearsal.");
            } catch (\Biblio\Core\Application\Migration\Cutover\RehearsalFailure) {
                $this->addToAssertionCount(1);
            }
        }
        $unreviewed = FinalPopulationContractBundle::fromReport($new, new FinalSourceCompatibilityReport(
            $this->snapshot(false), $new, [new SourceDrift(
                "catalog_books", "v1.book", "1771014295306", SourceDriftCategory::E,
                "new_raw_value_enum_or_source_shape", self::OLD_PAYLOAD, self::NEW_PAYLOAD
            )]
        ));
        try {
            new \Biblio\Core\Application\Migration\Cutover\ApprovedRehearsalSource(
                $intake, $unreviewed, array_replace($approvedReview, ["bundle_digest" => $unreviewed->digest()]), "/synthetic.zip"
            );
            self::fail("Unreviewed incompatible source accepted for rehearsal.");
        } catch (\Biblio\Core\Application\Migration\Cutover\RehearsalFailure $failure) {
            self::assertSame("contract_review_required", $failure->reason);
        }
    }

    private function assertHeld(FinalSourceCompatibilityReport $report): void
    {
        self::assertSame([], $report->reviewedDispositions());
        self::assertSame("CONTRACT_REVIEW_REQUIRED", $report->compatibility());
        foreach ($report->drift() as $row) {
            self::assertSame($row->category(), $row->effectiveCategory());
        }
    }

    private function bookDrift(FinalSourceCompatibilityReport $report): SourceDrift
    {
        foreach ($report->drift() as $row) {
            if ($row->domain() === "catalog_books") { return $row; }
        }
        self::fail("Book drift is missing.");
    }

    private function otherValue(string $field): string
    {
        return str_ends_with($field, "sha256") ? str_repeat("f", 64) : "other-reviewed-value";
    }

    private function snapshot(
        bool $candidate,
        array $packageChanges = [],
        array $observationChanges = [],
        ?string $vectorHash = null,
        bool $omitVector = false,
        array $extra = []
    ): FinalSourceSnapshot {
        $p = array_replace([
            "logical_package_id" => $candidate ? "final-current-20260923t124538449z-89b2ba3e94f9" : "reviewed-current-35a18156490f",
            "archive_sha256" => $candidate ? "89b2ba3e94f916d45e63f5aaa3595afac6241473f89d11eb062a165f053c8d9a" : "835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c",
            "manifest_sha256" => $candidate ? "43306b888056daeed2c3837aba6538befbb94caccc43b1c5d37c77dc8ff52480" : "35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67",
            "source_family" => "biblio-v1", "source_version" => "books-29.authors-2.reading-goals-2", "adapter_id" => "current-v1-json-29",
        ], $packageChanges);
        $o = array_replace([
            "domain" => "catalog_books", "source_type" => "v1.book", "source_id" => "1771014295306",
            "payload_hash" => $candidate ? self::NEW_PAYLOAD : self::OLD_PAYLOAD,
            "shape_hash" => $candidate ? self::NEW_SHAPE : self::OLD_SHAPE,
            "semantic_class" => "reviewed_current_v1_shape", "structural" => false, "state" => "ordinary",
        ], $observationChanges);
        $observations = [new FinalSourceObservation(...array_values($o)), ...$extra];
        if (!$omitVector) {
            $observations[] = new FinalSourceObservation(
                "authors_contributors", "v1.contributor_vector", "v1.book/1771014295306/contributors",
                $vectorHash ?? "4ba30f5f02b3d18cfd2895913bd9209dad78b9a8415bffc856d990dc1fb88c00",
                "dcb452a982945e5e2957930d83d36af5ceee19805ec0c3b30529ae8f44f6e49e", "reviewed_current_v1_shape"
            );
        }
        return new FinalSourceSnapshot(new FinalSourcePackageIdentity(...array_values($p)), $observations, ["v1.book" => 1], [], []);
    }
}
