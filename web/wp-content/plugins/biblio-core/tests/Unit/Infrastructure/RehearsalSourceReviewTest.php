<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Cutover\{ApprovedRehearsalSource, FinalPopulationContractBundle, FinalSourceCompatibilityReport, FinalSourcePlanningContext, RehearsalFailure, ReviewedSourceProfile, SourceDrift, SourceDriftCategory};
use Biblio\Core\Application\Migration\Runner\{DeterministicJson, MigrationBuildProvenance, MigrationEnvironment, MigrationParticipant, MigrationParticipantRegistry, MigrationPlanPreparer, MigrationPlanningTarget, MigrationSourceFinding, MigrationSourceInspection, MigrationSourceMapperRegistry, MigrationSourceProfile, PlannedMigrationRecord};
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Identity\{PersonalMigrationTarget, PersonalMigrationTargetReadiness};
use Biblio\Core\Application\Migration\Circulation\CirculationMigrationParticipant;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\{CurrentV1FinalSourceSnapshotBuilder, CurrentV1RehearsalMapper, CurrentV1SourceAdapter, FilesystemMigrationSourcePackageFactory, FinalSourceInspector, RehearsalComposition};
use Biblio\Core\Library\{LibraryId, LibraryName};
use Biblio\Core\Tests\Support\RehearsalFixture;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/Support/RehearsalFixture.php";
require_once __DIR__ . "/CurrentV1ClassificationMapperTest.php";

final class RehearsalSourceReviewTest extends TestCase
{
    public function testPreApprovalPlanningRejectsUnreviewedBundle(): void
    {
        $directory = sys_get_temp_dir() . "/biblio-preapproval-block-" . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $fixture = RehearsalFixture::source($directory);
            $inspection = (new FinalSourceInspector(new FilesystemMigrationSourcePackageFactory()))
                ->inspect($fixture->intake->extractionRoot(), new CurrentV1SourceAdapter());
            $snapshot = (new CurrentV1FinalSourceSnapshotBuilder())->build($inspection, $fixture->intake->identity());
            $report = new FinalSourceCompatibilityReport($snapshot, $snapshot, [new SourceDrift(
                "catalog_books", CurrentV1SourceAdapter::BOOK, "book-1", SourceDriftCategory::E,
                "unreviewed_source_shape", str_repeat("a", 64), str_repeat("b", 64)
            )]);
            $bundle = FinalPopulationContractBundle::fromReport($snapshot, $report);
            $review = $fixture->review;
            unset($review["state"], $review["review_id"]);
            $review["bundle_digest"] = $bundle->digest();
            try {
                new FinalSourcePlanningContext($fixture->intake, $bundle, $review, $directory . "/synthetic.zip");
                self::fail("Unreviewed FINAL bundle accepted for planning.");
            } catch (RehearsalFailure $failure) {
                self::assertSame("contract_review_required", $failure->reason);
            }
        } finally {
            RehearsalFixture::remove($directory);
        }
    }

    public function testPreApprovalContextUsesTheSameMapperAndPlanSetWithoutApply(): void
    {
        $directory = sys_get_temp_dir() . "/biblio-preapproval-plan-" . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $fixture = RehearsalFixture::source($directory, true);
            $review = $fixture->review;
            unset($review["state"], $review["review_id"]);
            $context = new FinalSourcePlanningContext(
                $fixture->intake, $fixture->bundle, $review, $directory . "/synthetic.zip"
            );
            $context->verify(new FilesystemMigrationSourcePackageFactory());
            $inspection = (new FinalSourceInspector(new FilesystemMigrationSourcePackageFactory()))
                ->inspect($fixture->intake->extractionRoot(), new CurrentV1SourceAdapter());
            $target = new MigrationPlanningTarget(new PersonalMigrationTarget(
                new UserId("synthetic-user"), new LibraryId("synthetic-library"),
                LibraryName::personalDefault(), new PersonalMigrationTargetReadiness([])
            ));
            $bookTypes = new ClassificationBookTypeRepositoryStub([
                "book_type.reading_book" => "synthetic-reading", "book_type.knowledge_book" => "synthetic-knowledge",
                "book_type.cookbook" => "synthetic-cook", "book_type.comic_book" => "synthetic-comic",
                "book_type.study_book" => "synthetic-study",
            ]);
            $genres = new ClassificationGenreRepositoryStub([
                "genre.fantasy" => "synthetic-fantasy", "genre.science_fiction" => "synthetic-scifi",
                "genre.thriller" => "synthetic-thriller",
            ]);
            $makeMapper = static fn (FinalSourcePlanningContext $source): CurrentV1RehearsalMapper =>
                new CurrentV1RehearsalMapper($source, $bookTypes, $genres);
            $mapping = $makeMapper($context)->map($inspection, $target);
            $participants = [];
            foreach ($mapping->records() as $record) {
                $type = $record->sourceType();
                if (isset($participants[$type])) { continue; }
                if ($type === CurrentV1SourceAdapter::CIRCULATION_ROUND) {
                    $participants[$type] = new CirculationMigrationParticipant();
                    continue;
                }
                $participant = $this->createMock(MigrationParticipant::class);
                $participant->method("sourceType")->willReturn($type);
                $participant->expects(self::never())->method("apply");
                $participant->method("plan")->willReturn(new PlannedMigrationRecord(
                    MigrationDisposition::Mapped, [["operation" => "synthetic_plan_only"]]
                ));
                $participants[$type] = $participant;
            }
            $environment = $this->createStub(MigrationEnvironment::class);
            $environment->method("provenance")->willReturn(new MigrationBuildProvenance(
                "v2.001", 1026, "2.51.1", str_repeat("a", 40), false
            ));
            $registry = new MigrationParticipantRegistry(array_values($participants));
            $prepare = static fn (FinalSourcePlanningContext $source) => (new MigrationPlanPreparer(
                $registry, new MigrationSourceMapperRegistry([$makeMapper($source)]),
                $environment, $source->bundle->digest()
            ))->prepare($inspection, $target);
            $preApproval = $prepare($context);
            $approved = $prepare($fixture->planningContext());
            self::assertSame([], $preApproval->failures());
            self::assertSame($approved->planSetDigest(), $preApproval->planSetDigest());
            self::assertSame($fixture->bundle->digest(), $preApproval->finalPopulationBundleDigest());
            self::assertTrue($preApproval->profileReview()?->matches($inspection, $fixture->bundle->digest()));
            self::assertSame("mechanically_compatible", $fixture->bundle->approvalState()->value);
            $circulation = array_values(array_filter($preApproval->records(), static fn ($item): bool =>
                $item->record()->sourceType() === CurrentV1SourceAdapter::CIRCULATION_ROUND));
            self::assertCount(1, $circulation);
            self::assertSame("quarantined", $circulation[0]->plan()->disposition()->value);
            self::assertSame([], $circulation[0]->plan()->operations());
            $this->assertMapperDiagnosticAdmission($preApproval);
            $route = new \ReflectionMethod(RehearsalComposition::class, "preApprovalPlanner");
            self::assertSame(FinalSourcePlanningContext::class, $route->getParameters()[0]->getType()?->getName());
            self::assertSame(\Biblio\Core\Application\Migration\Runner\MigrationRunner::class, $route->getReturnType()?->getName());
            $applyRoute = new \ReflectionMethod(RehearsalComposition::class, "create");
            self::assertSame(ApprovedRehearsalSource::class, $applyRoute->getParameters()[0]->getType()?->getName());
            self::assertFalse(method_exists(\Biblio\Core\Application\Migration\Runner\MigrationRunner::class, "apply"));
            self::assertSame($inspection->package()->manifestDigest(),
                (new FilesystemMigrationSourcePackageFactory())->build($fixture->intake->extractionRoot())->manifestDigest());
            foreach (["package_digest", "bundle_digest"] as $field) {
                $changed = $review;
                $changed[$field] = str_repeat("f", 64);
                try {
                    new FinalSourcePlanningContext($fixture->intake, $fixture->bundle, $changed, $directory . "/synthetic.zip");
                    self::fail("Wrong FINAL planning binding accepted.");
                } catch (RehearsalFailure $failure) {
                    self::assertContains($failure->reason, ["review_package_mismatch", "review_bundle_mismatch"]);
                }
            }
            $missingArchive = new FinalSourcePlanningContext(
                $fixture->intake, $fixture->bundle, $review, $directory . "/missing.zip"
            );
            try {
                $missingArchive->verify(new FilesystemMigrationSourcePackageFactory());
                self::fail("Missing FINAL archive accepted.");
            } catch (RehearsalFailure $failure) {
                self::assertSame("candidate_archive_unavailable", $failure->reason);
            }
        } finally {
            RehearsalFixture::remove($directory);
        }
    }

    private function assertMapperDiagnosticAdmission(\Biblio\Core\Application\Migration\Runner\PreparedMigrationPlan $prepared): void
    {
        $describe = \Biblio\Core\Application\Migration\Cutover\RehearsalPlanEvidence::describe(...);
        $copy = static fn (array $findings, ?array $records = null) => new \Biblio\Core\Application\Migration\Runner\PreparedMigrationPlan(
            $prepared->inspection(), $prepared->target(), $records ?? $prepared->records(), $prepared->failures(),
            $findings, $prepared->mappingContracts(), $prepared->planSetDigest(),
            $prepared->finalPopulationBundleDigest(), $prepared->profileReview(), $prepared->registryDigest()
        );
        $baseline = $describe($prepared, []);
        self::assertCount(1, $baseline["quarantine"]);
        self::assertSame("ambiguous_circulation_semantics", $baseline["quarantine"][0]["reason_code"]);
        $pairs = [
            ["v1.copy", "catalog_book_quarantined"],
            ["v1.author_occurrence", "compound_author_scalar"],
            ["v1.author_occurrence", "invalid_author_placeholder"],
            ["v1.author_occurrence", "malformed_author_scalar"],
            ["v1.book", "converged_classification_conflict"],
            ["v1.classification_conflict_member", "converged_classification_conflict_member"],
            ["v1.book", "invalid_isbn"],
            ["v1.book", "unresolved_work_identity"],
        ];
        foreach ($pairs as [$type, $reason]) {
            $finding = new \Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding(
                $type, "synthetic-diagnostic", MigrationDisposition::Quarantined, $reason
            );
            $withFinding = $copy([...$prepared->findings(), $finding]);
            $before = array_map(static fn ($item) => [$item->record()->identityArray(), $item->plan()->toArray()], $withFinding->records());
            $evidence = $describe($withFinding, []);
            self::assertSame($prepared->records(), $withFinding->records());
            self::assertSame($before, array_map(static fn ($item) => [$item->record()->identityArray(), $item->plan()->toArray()], $withFinding->records()));
            self::assertSame($prepared->planSetDigest(), $withFinding->planSetDigest());
            self::assertSame([...$prepared->findings(), $finding], $withFinding->findings());
            self::assertSame($baseline["quarantine"], $evidence["quarantine"]);
            self::assertSame($baseline["expected_operations"], $evidence["expected_operations"]);
            self::assertNotSame($baseline["target_neutral_plan_digest"], $evidence["target_neutral_plan_digest"], "Diagnostic must remain bound in intent evidence.");
            foreach ([MigrationDisposition::Failed, MigrationDisposition::Quarantined] as $disposition) {
                $blocked = new \Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding(
                    $type, "synthetic-diagnostic", $disposition, $reason,
                    $disposition === MigrationDisposition::Failed ? [] : [["source_type" => "catalog_work", "source_id" => "unexpected-target"]]
                );
                try { $describe($copy([$blocked]), []); self::fail("Executable or failed diagnostic admitted."); }
                catch (RehearsalFailure $failure) { self::assertSame("unresolved_mapping_finding", $failure->reason); }
            }
        }
        foreach ([["v1.book", "unknown_reason"], ["v1.reading_round", "unresolved_work_identity"],
            ["v1.copy", "invalid_isbn"], ["new.source", "catalog_book_quarantined"]] as [$type, $reason]) {
            $finding = new \Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding(
                $type, "synthetic-unknown", MigrationDisposition::Quarantined, $reason
            );
            try { $describe($copy([$finding]), []); self::fail("Unknown diagnostic shape admitted."); }
            catch (RehearsalFailure $failure) { self::assertSame("unresolved_mapping_finding", $failure->reason); }
        }
        $record = $prepared->records()[0];
        $dependent = new \Biblio\Core\Application\Migration\Runner\PreparedMigrationRecord(
            $record->record(), $record->participant(), new PlannedMigrationRecord(MigrationDisposition::Mapped,
                [["operation" => "synthetic_plan_only"]], dependencies: [["source_type" => "v1.book", "source_id" => "synthetic-diagnostic"]])
        );
        $finding = new \Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding(
            "v1.book", "synthetic-diagnostic", MigrationDisposition::Quarantined, "unresolved_work_identity"
        );
        try { $describe($copy([$finding], [$dependent]), []); self::fail("Diagnostic substituted for required executable identity."); }
        catch (RehearsalFailure $failure) { self::assertSame("missing_dependency", $failure->reason); }
    }

    public function testExactProfileApprovalAndUnknownOrMalformedAdmissionRefusal(): void
    {
        $directory = sys_get_temp_dir() . "/biblio-rehearsal-review-" . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $source = RehearsalFixture::source($directory);
            $factory = new FilesystemMigrationSourcePackageFactory();
            $inspection = (new FinalSourceInspector($factory))->inspect($source->intake->extractionRoot(), new CurrentV1SourceAdapter());
            $admitted = ReviewedSourceProfile::forApprovedCandidate($source, $inspection);
            self::assertTrue($admitted->matches($inspection, $source->bundle->digest()));
            self::assertFalse($admitted->matches($inspection, str_repeat("f", 64)));
            foreach (["unreviewed_reason", "malformed_record", "unreadable_record"] as $reason) {
                $profile = $inspection->profile();
                $changed = new MigrationSourceInspection($inspection->package(), $inspection->adapter(),
                    new MigrationSourceProfile($profile->sourceVersion(), $profile->categoryCounts(), $profile->unknownCategories(),
                        [...$profile->findings(), new MigrationSourceFinding($reason, "synthetic", "No private content")], $profile->categoryStrategies()),
                    $inspection->records(), $inspection->typeCounts());
                self::assertFalse($admitted->matches($changed, $source->bundle->digest()));
                $review = $source->review;
                $review["source_profile_digest"] = DeterministicJson::hash($changed->sourcePayload());
                $attempt = new ApprovedRehearsalSource($source->intake, $source->bundle, $review, $directory . "/synthetic.zip");
                try { ReviewedSourceProfile::forApprovedCandidate($attempt, $changed); self::fail("Unreviewed source shape admitted"); }
                catch (RehearsalFailure $failure) { self::assertSame("unreviewed_source_finding", $failure->reason); }
            }
            $profile = $inspection->profile();
            $knownMalformed = new MigrationSourceInspection($inspection->package(), $inspection->adapter(),
                new MigrationSourceProfile($profile->sourceVersion(), $profile->categoryCounts(), $profile->unknownCategories(),
                    [...$profile->findings(), new MigrationSourceFinding("malformed_value",
                        "data/books.json#copies/*/acquisition", "objects=77; missing_type=1; price_fields=0; currency_fields=0.")],
                    $profile->categoryStrategies()), $inspection->records(), $inspection->typeCounts());
            self::assertFalse($admitted->matches($knownMalformed, $source->bundle->digest()));
            $malformedReview = $source->review;
            $malformedReview["source_profile_digest"] = DeterministicJson::hash($knownMalformed->sourcePayload());
            $malformedSource = new ApprovedRehearsalSource($source->intake, $source->bundle, $malformedReview,
                $directory . "/synthetic.zip");
            self::assertTrue(ReviewedSourceProfile::forApprovedCandidate($malformedSource, $knownMalformed)
                ->matches($knownMalformed, $source->bundle->digest()));
            foreach (["state" => "mechanically_compatible", "bundle_digest" => str_repeat("f", 64), "package_digest" => str_repeat("e", 64)] as $field => $value) {
                $review = $source->review;
                $review[$field] = $value;
                try { new ApprovedRehearsalSource($source->intake, $source->bundle, $review, $directory . "/synthetic.zip"); self::fail("Invalid approval accepted"); }
                catch (RehearsalFailure) { $this->addToAssertionCount(1); }
            }
            $source->verify($factory);
            file_put_contents($directory . "/synthetic.zip", "synthetic changed archive", FILE_APPEND);
            try { $source->verify($factory); self::fail("Changed archive accepted"); }
            catch (RehearsalFailure $failure) { self::assertSame("candidate_archive_changed", $failure->reason); }
        } finally { RehearsalFixture::remove($directory); }
    }
}
