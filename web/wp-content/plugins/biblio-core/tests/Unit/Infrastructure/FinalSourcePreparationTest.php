<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Cutover\FinalPopulationContractBundle;
use Biblio\Core\Application\Migration\Cutover\FinalSourceApprovalState;
use Biblio\Core\Application\Migration\Cutover\FinalSourceDriftEngine;
use Biblio\Core\Application\Migration\Cutover\FinalSourceExportProvenance;
use Biblio\Core\Application\Migration\Cutover\FinalSourceObservation;
use Biblio\Core\Application\Migration\Cutover\FinalSourcePackageIdentity;
use Biblio\Core\Application\Migration\Cutover\FinalSourceRetentionMetadata;
use Biblio\Core\Application\Migration\Cutover\FinalSourceSnapshot;
use Biblio\Core\Application\Migration\Cutover\FinalSourceToolProvenance;
use Biblio\Core\Application\Migration\Cutover\SourceDriftCategory;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;
use Biblio\Core\Infrastructure\Migration\CurrentV1FinalSourceSnapshotBuilder;
use Biblio\Core\Infrastructure\Migration\CurrentV1SourceAdapter;
use Biblio\Core\Infrastructure\Migration\FilesystemMigrationSourcePackageFactory;
use Biblio\Core\Infrastructure\Migration\FinalSourceEvidenceWriter;
use Biblio\Core\Infrastructure\Migration\FinalSourceIntakeService;
use Biblio\Core\Infrastructure\Migration\FinalSourceInspector;
use Biblio\Core\Infrastructure\Migration\FinalSourceRecoveryVerifier;
use Biblio\Core\Infrastructure\Migration\CurrentV1RestrictedSourceEvidenceResolver;
use Biblio\Core\Application\Migration\Runner as Runner;
use Biblio\Core\Application\Migration\Preservation as Preservation;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class FinalSourcePreparationTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    public function testFinderMetadataAddsNoMappedPreparedPreservedOrQuarantinedPlans(): void
    {
        $root = $this->source(extraBook: true);
        $books = json_decode(file_get_contents($root . "/data/books.json"), true, 512, JSON_THROW_ON_ERROR);
        $books["books"][1]["title"] = ""; // Positive, unchanged quarantine control.
        $this->write($root, "books.json", $books);
        $this->write($root, "reading_goals.json", ["schemaVersion" => 2, "goals" => [[
            "id" => "goal-1", "title" => "Synthetic goal", "type" => "books", "active" => false,
            "config" => ["targetBooks" => 12], "createdAt" => "2026-01-01T00:00:00Z",
            "updatedAt" => "2026-01-01T00:00:00Z",
        ]]]);
        $target = new Runner\MigrationPlanningTarget(new \Biblio\Core\Application\Identity\PersonalMigrationTarget(
            new \Biblio\Core\Identity\UserId("synthetic-user"),
            new \Biblio\Core\Library\LibraryId("synthetic-library"),
            \Biblio\Core\Library\LibraryName::personalDefault(),
            new \Biblio\Core\Application\Identity\PersonalMigrationTargetReadiness([])
        ));
        $environment = $this->createStub(Runner\MigrationEnvironment::class);
        $environment->method("provenance")->willReturn(new Runner\MigrationBuildProvenance(
            "v2.001", 1026, "2.51.1", str_repeat("a", 40), true
        ));
        $results = [];
        foreach ([false, true] as $withMetadata) {
            if ($withMetadata) { file_put_contents($root . "/data/.DS_Store", "synthetic metadata"); }
            $inspection = (new FinalSourceInspector(new FilesystemMigrationSourcePackageFactory()))->inspect($root, new CurrentV1SourceAdapter());
            $mapper = new \Biblio\Core\Infrastructure\Migration\CurrentV1CatalogMapper(
                readingGoalMapper: new \Biblio\Core\Infrastructure\Migration\CurrentV1ReadingGoalMapper(
                    new \Biblio\Core\Infrastructure\Migration\CurrentV1ReviewedReadingGoalContract($inspection->package()->manifestDigest())
                )
            );
            $mapped = $mapper->map($inspection, $target);
            $participants = [];
            foreach ($mapped->records() as $record) {
                $type = $record->sourceType();
                if (isset($participants[$type])) { continue; }
                // Synthetic target participants isolate package admission from target persistence.
                // The real mapper and real plan preparer still run; apply must never run.
                $participant = $this->createMock(Runner\MigrationParticipant::class);
                $participant->method("sourceType")->willReturn($type);
                $participant->expects(self::never())->method("apply");
                $participant->method("plan")->willReturnCallback(static function (Runner\MigrationSourceRecord $record): Runner\PlannedMigrationRecord {
                    $preserved = $record->typedPlan() instanceof Preservation\PreservedSourceEvidencePlan;
                    return new Runner\PlannedMigrationRecord(
                        $preserved ? \Biblio\Core\Application\Migration\MigrationDisposition::PreservedDeferred : \Biblio\Core\Application\Migration\MigrationDisposition::Mapped,
                        $preserved ? [] : [["operation" => "synthetic_plan_only"]],
                        $preserved ? $record->typedPlan()->reasonCode() : null,
                        typedPlan: $record->typedPlan()
                    );
                });
                $participants[$type] = $participant;
            }
            $prepared = (new Runner\MigrationPlanPreparer(
                new Runner\MigrationParticipantRegistry(array_values($participants)),
                new Runner\MigrationSourceMapperRegistry([$mapper]), $environment
            ))->prepare($inspection, $target);
            self::assertSame([], $prepared->failures());
            $population = array_map(static fn ($item): array => [
                "source_type" => $item->record()->sourceType(), "source_id" => $item->record()->sourceId(),
                "plan" => $item->plan()->toArray(),
            ], $prepared->records());
            $findings = array_map(static fn ($finding): array => [
                "disposition" => $finding->toArray()["disposition"], "reason" => $finding->reasonCode(),
            ], $prepared->findings());
            self::assertNotEmpty(array_filter($population, static fn ($p): bool => $p["plan"]["disposition"] === "preserved_deferred"));
            self::assertNotEmpty(array_filter($population, static fn ($p): bool => $p["plan"]["disposition"] === "mapped"));
            self::assertNotEmpty(array_filter($findings, static fn ($finding): bool => $finding["disposition"] === "quarantined"));
            $results[] = ["population" => $population, "findings" => $findings, "digest" => $prepared->planSetDigest()];
        }
        self::assertSame($results[0]["population"], $results[1]["population"]);
        self::assertSame($results[0]["findings"], $results[1]["findings"]);
        // Full-package provenance changes, although no semantic plan is added or changed.
        self::assertNotSame($results[0]["digest"], $results[1]["digest"]);
    }

    public function testExactFinderMetadataRemainsInFullManifestWithoutSemanticObservations(): void
    {
        $root = $this->source();
        $factory = new FilesystemMigrationSourcePackageFactory();
        $adapter = new CurrentV1SourceAdapter();
        $before = $factory->build($root);
        $profile = $adapter->profile($before);
        $records = iterator_to_array($adapter->records($before, $profile));
        $snapshot = $this->snapshot($root, "metadata-reference", str_repeat("1", 64));
        $bytes = "\x00\xffFinder metadata sentinel; not JSON or source data";
        file_put_contents($root . "/data/.DS_Store", $bytes);
        $after = $factory->build($root);
        $afterProfile = $adapter->profile($after);
        $afterRecords = iterator_to_array($adapter->records($after, $afterProfile));

        self::assertNotSame($before->manifestDigest(), $after->manifestDigest());
        self::assertCount(count($before->files()) + 1, $after->files());
        self::assertEquals($profile, $afterProfile);
        self::assertEquals($records, $afterRecords);
        $afterSnapshot = $this->snapshot($root, "metadata-candidate", str_repeat("2", 64));
        self::assertEquals($snapshot->observations(), $afterSnapshot->observations());
        self::assertSame($snapshot->sourceTypeCounts(), $afterSnapshot->sourceTypeCounts());
        self::assertSame($snapshot->quarantineCandidates(), $afterSnapshot->quarantineCandidates());
        self::assertSame($snapshot->circulationProfile(), $afterSnapshot->circulationProfile());
        $report = (new FinalSourceDriftEngine())->compare($snapshot, $afterSnapshot);
        self::assertSame("MAPPING_CONTRACT_COMPATIBLE", $report->compatibility());
        self::assertSame([SourceDriftCategory::A], array_values(array_unique($this->categories($report->drift()), SORT_REGULAR)));
        self::assertSame([], $adapter->packageMetadata($before));
        self::assertSame([[
            "relative_path" => "data/.DS_Store", "byte_size" => strlen($bytes),
            "sha256" => hash("sha256", $bytes), "classification" => "NON_SOURCE_PACKAGE_METADATA",
        ]], array_map(static fn ($file): array => $file->toArray(), $adapter->packageMetadata($after)));

        $archive = $this->zip($root);
        $archiveHash = hash_file("sha256", $archive);
        $archiveBytes = filesize($archive);
        $receipt = $this->metadataIntake($archive, $after->manifestDigest(), $this->directory());
        self::assertSame($after->manifestDigest(), $receipt->identity()->manifestSha256());
        self::assertSame($after->toArray(), $receipt->package()->toArray());
        self::assertSame($adapter->packageMetadata($after)[0]->toArray(), $receipt->toArray()["package_metadata"][0]);
        self::assertSame($bytes, file_get_contents($receipt->extractionRoot() . "/data/.DS_Store"));
        self::assertSame(0400, fileperms($receipt->extractionRoot() . "/data/.DS_Store") & 0777);
        self::assertSame($bytes, file_get_contents($root . "/data/.DS_Store"));
        self::assertSame($after->manifestDigest(), $factory->build($root)->manifestDigest());
        self::assertSame($archiveHash, hash_file("sha256", $archive));
        self::assertSame($archiveBytes, filesize($archive));
        self::assertStringNotContainsString("Finder metadata sentinel", json_encode($receipt->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testFinderMetadataAdmissionDoesNotAdmitOtherUnexpectedPaths(): void
    {
        foreach ([".DS_Store", "data/foo/.DS_Store", "data/.hidden", "data/unknown.json", "data/.DS_Store.extra", "data/cover-cache/.DS_Store"] as $path) {
            $root = $this->source();
            if (!is_dir(dirname($root . "/" . $path))) { mkdir(dirname($root . "/" . $path), 0700, true); }
            file_put_contents($root . "/data/.DS_Store", "approved metadata");
            file_put_contents($root . "/" . $path, "unexpected");
            $package = (new FilesystemMigrationSourcePackageFactory())->build($root);
            try {
                $this->metadataIntake($this->zip($root), $package->manifestDigest(), $this->directory());
                self::fail("Unexpected package path must fail closed.");
            } catch (MigrationRunnerFailure $failure) {
                self::assertSame(MigrationRunnerReason::UnsupportedStructure, $failure->reason());
            }
        }
    }

    public function testMetadataCannotMaskMalformedOrUnreviewedDomainInput(): void
    {
        foreach (["malformed", "unreviewed"] as $case) {
            $root = $this->source();
            file_put_contents($root . "/data/.DS_Store", "metadata");
            if ($case === "malformed") {
                file_put_contents($root . "/data/books.json", "{");
            } else {
                $books = json_decode(file_get_contents($root . "/data/books.json"), true, 512, JSON_THROW_ON_ERROR);
                $books["books"][0]["unreviewedField"] = true;
                $this->write($root, "books.json", $books);
            }
            try {
                (new CurrentV1SourceAdapter())->profile((new FilesystemMigrationSourcePackageFactory())->build($root));
                self::fail("Domain input must still fail closed.");
            } catch (MigrationRunnerFailure $failure) {
                self::assertSame(MigrationRunnerReason::UnsupportedStructure, $failure->reason());
            }
        }
    }

    public function testMetadataAdmissionPreservesHardenedZipSecurityAndNoOverwrite(): void
    {
        foreach (["traversal", "absolute", "symlink", "fifo", "duplicate"] as $case) {
            $archive = $this->directory() . "/unsafe.zip";
            $zip = new ZipArchive();
            self::assertTrue($zip->open($archive, ZipArchive::CREATE));
            $member = match ($case) {
                "traversal" => "data/../.DS_Store", "absolute" => "/data/.DS_Store",
                default => "data/.DS_Store",
            };
            self::assertTrue($zip->addFromString($member, "synthetic metadata"));
            if ($case === "symlink" || $case === "fifo") {
                self::assertTrue($zip->setExternalAttributesName($member, ZipArchive::OPSYS_UNIX, ($case === "symlink" ? 0120777 : 0010600) << 16));
            }
            if ($case === "duplicate") { self::assertTrue($zip->addEmptyDir("data/.DS_Store")); }
            self::assertTrue($zip->close());
            try {
                $this->metadataIntake($archive, str_repeat("0", 64), $this->directory());
                self::fail("Unsafe archive must fail before manifest acceptance.");
            } catch (MigrationRunnerFailure $failure) {
                self::assertSame(MigrationRunnerReason::SourceUnsafe, $failure->reason());
            }
        }
        $root = $this->source();
        file_put_contents($root . "/data/.DS_Store", "metadata");
        $archive = $this->zip($root);
        $manifest = (new FilesystemMigrationSourcePackageFactory())->build($root)->manifestDigest();
        $destination = $this->directory();
        $receipt = $this->metadataIntake($archive, $manifest, $destination);
        try {
            $this->metadataIntake($archive, $manifest, $destination);
            self::fail("Existing intake may not be overwritten.");
        } catch (MigrationRunnerFailure $failure) {
            self::assertSame(MigrationRunnerReason::SourceUnsafe, $failure->reason());
        }
        self::assertSame($manifest, (new FilesystemMigrationSourcePackageFactory())->build($receipt->extractionRoot())->manifestDigest());
    }

    private function metadataIntake(string $archive, string $manifest, string $destination): \Biblio\Core\Application\Migration\Cutover\FinalSourceIntakeReceipt
    {
        return (new FinalSourceIntakeService(new FilesystemMigrationSourcePackageFactory()))->intake(
            $archive, hash_file("sha256", $archive), $manifest, "final-metadata-test", $destination,
            new CurrentV1SourceAdapter(), CurrentV1SourceAdapter::SOURCE_VERSION,
            new FinalSourceExportProvenance("2026-09-23T12:00:00Z", "synthetic", "synthetic", "1", str_repeat("a", 64), "synthetic", "php", "synthetic-freeze"),
            new FinalSourceRetentionMetadata("synthetic-retained", "not_verified", "not_verified")
        );
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->directories) as $directory) {
            $this->remove($directory);
        }
        parent::tearDown();
    }

    public function testIdenticalSourceIsCompatibleAndBundleReplayIsDeterministic(): void
    {
        $root = $this->source();
        $reference = $this->snapshot($root, "reviewed-current", str_repeat("1", 64));
        $candidate = $this->snapshot($root, "candidate-final", str_repeat("2", 64));
        $report = (new FinalSourceDriftEngine())->compare($reference, $candidate);

        self::assertSame("MAPPING_CONTRACT_COMPATIBLE", $report->compatibility());
        self::assertSame(FinalSourceApprovalState::MechanicallyCompatible, $report->approvalState());
        self::assertNotEmpty($report->drift());
        self::assertSame(
            [SourceDriftCategory::A],
            array_values(array_unique(array_map(
                static fn ($drift): SourceDriftCategory => $drift->category(),
                $report->drift()
            ), SORT_REGULAR))
        );
        $first = FinalPopulationContractBundle::fromReport($candidate, $report);
        $second = FinalPopulationContractBundle::fromReport($candidate, $report);
        self::assertSame($first->digest(), $second->digest());
        self::assertSame("mechanically_compatible", $first->mapperContract("classification")["approval_state"]);
    }

    public function testSupportedAdditionNewEnumDeletionPrivateChangeAndSlotShiftAreDistinct(): void
    {
        $referenceRoot = $this->source();
        $reference = $this->snapshot($referenceRoot, "reviewed-current", str_repeat("1", 64));
        $engine = new FinalSourceDriftEngine();

        $addedRoot = $this->source(extraBook: true);
        $added = $engine->compare(
            $reference,
            $this->snapshot($addedRoot, "candidate-added", str_repeat("2", 64))
        );
        self::assertContains(SourceDriftCategory::D, $this->categories($added->drift()));
        self::assertSame(
            "MAPPING_CONTRACT_COMPATIBLE",
            $added->compatibility(),
            json_encode($added->toArray(), JSON_THROW_ON_ERROR)
        );

        $enumRoot = $this->source(acquisitionType: "new-hypothetical-kind");
        $enum = $engine->compare(
            $reference,
            $this->snapshot($enumRoot, "candidate-enum", str_repeat("3", 64))
        );
        self::assertContains(SourceDriftCategory::E, $this->categories($enum->drift()));
        self::assertSame("CONTRACT_REVIEW_REQUIRED", $enum->compatibility());

        $deletedRoot = $this->source(includeNote: false);
        $deleted = $engine->compare(
            $reference,
            $this->snapshot($deletedRoot, "candidate-deleted", str_repeat("4", 64))
        );
        self::assertContains(SourceDriftCategory::H, $this->categories($deleted->drift()));

        $privateRoot = $this->source(noteBody: "different private body sentinel");
        $private = $engine->compare(
            $reference,
            $this->snapshot($privateRoot, "candidate-private", str_repeat("5", 64))
        );
        $privateJson = json_encode($private->toArray(), JSON_THROW_ON_ERROR);
        self::assertContains(SourceDriftCategory::C, $this->categories($private->drift()));
        self::assertStringNotContainsString("private note body", $privateJson);
        self::assertStringNotContainsString("different private body sentinel", $privateJson);

        $structuralReference = $this->snapshot(
            $this->source(contained: [
                ["title" => "One", "author" => "A", "isbn" => "", "series" => "", "seriesIndex" => ""],
                ["title" => "Two", "author" => "B", "isbn" => "", "series" => "", "seriesIndex" => ""],
            ]),
            "structural-reference",
            str_repeat("6", 64)
        );
        $shifted = $engine->compare(
            $structuralReference,
            $this->snapshot(
                $this->source(contained: [
                    ["title" => "Two", "author" => "B", "isbn" => "", "series" => "", "seriesIndex" => ""],
                    ["title" => "One", "author" => "A", "isbn" => "", "series" => "", "seriesIndex" => ""],
                ]),
                "structural-shift",
                str_repeat("7", 64)
            )
        );
        self::assertContains(SourceDriftCategory::I, $this->categories($shifted->drift()));
    }

    public function testUnknownSourceTypeAndCirculationStatesFailClosedWithoutPrivateText(): void
    {
        $root = $this->source();
        $reference = $this->snapshot($root, "reference", str_repeat("1", 64));
        $unknownObservations = $reference->observations();
        $unknownObservations[] = new FinalSourceObservation(
            "unsupported_source_types",
            "v1.future_type",
            "future-1",
            str_repeat("a", 64),
            str_repeat("b", 64),
            "unreviewed",
            false,
            "ordinary"
        );
        $unknown = new FinalSourceSnapshot(
            new FinalSourcePackageIdentity(
                "candidate-unknown",
                str_repeat("2", 64),
                $reference->package()->manifestSha256(),
                CurrentV1SourceAdapter::SOURCE_FAMILY,
                CurrentV1SourceAdapter::SOURCE_VERSION,
                CurrentV1SourceAdapter::ADAPTER_ID
            ),
            $unknownObservations,
            array_merge($reference->sourceTypeCounts(), ["v1.future_type" => 1]),
            $reference->circulationProfile(),
            $reference->quarantineCandidates()
        );
        $unknownReport = (new FinalSourceDriftEngine())->compare($reference, $unknown);
        self::assertContains(SourceDriftCategory::J, $this->categories($unknownReport->drift()));

        self::assertSame(1, $reference->circulationProfile()["contradictory"]);
        self::assertCount(1, $reference->quarantineCandidates());
        $resolved = $this->snapshot(
            $this->source(copyLoanClosed: false),
            "candidate-resolved",
            str_repeat("3", 64)
        );
        $resolvedReport = (new FinalSourceDriftEngine())->compare($reference, $resolved);
        self::assertSame(0, $resolved->circulationProfile()["contradictory"]);
        self::assertSame(1, $resolved->circulationProfile()["open_total"]);
        self::assertSame("CIRCULATION_CUTOVER_REVIEW_REQUIRED", $resolvedReport->toArray()["circulation_cutover_gate"]);
        $json = json_encode($resolvedReport->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("Private Counterparty", $json);
    }

    public function testArchiveIntakeIsImmutableDeterministicAndRejectsTraversal(): void
    {
        $source = $this->source();
        $archive = $this->zip($source);
        $factory = new FilesystemMigrationSourcePackageFactory();
        $expectedManifest = $factory->build($source)->manifestDigest();
        $intakeRoot = $this->directory();
        $receipt = (new FinalSourceIntakeService($factory))->intake(
            $archive,
            hash_file("sha256", $archive),
            $expectedManifest,
            "final-current-20260918-safe",
            $intakeRoot,
            new CurrentV1SourceAdapter(),
            CurrentV1SourceAdapter::SOURCE_VERSION,
            new FinalSourceExportProvenance(
                "2026-09-18T12:00:00+00:00",
                "operator-1",
                "synthetic-export",
                "1",
                str_repeat("a", 64),
                "synthetic-v1-build",
                "synthetic-runtime",
                "freeze-evidence-1"
            ),
            new FinalSourceRetentionMetadata(
                "retained-final-current-20260918-safe",
                "not_verified",
                "not_verified"
            )
        );
        self::assertSame($expectedManifest, $receipt->identity()->manifestSha256());
        self::assertSame(0400, fileperms($receipt->extractionRoot() . "/data/books.json") & 0777);
        $json = json_encode($receipt->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(sys_get_temp_dir(), $json);
        self::assertStringNotContainsString("private note body", $json);
        (new FinalSourceRecoveryVerifier(
            $factory,
            new CurrentV1RestrictedSourceEvidenceResolver()
        ))->verify(
            $receipt->identity(),
            $archive,
            $receipt->extractionRoot()
        );

        try {
            (new FinalSourceIntakeService($factory))->intake(
                $archive,
                hash_file("sha256", $archive),
                $expectedManifest,
                "../escaped-package",
                $this->directory(),
                new CurrentV1SourceAdapter(),
                CurrentV1SourceAdapter::SOURCE_VERSION,
                new FinalSourceExportProvenance(
                    "2026-09-18T12:00:00+00:00", "operator-1", "synthetic-export", "1",
                    str_repeat("a", 64), "synthetic-v1-build", "synthetic-runtime", "freeze-1"
                ),
                new FinalSourceRetentionMetadata("retained-invalid-id", "not_verified", "not_verified")
            );
            self::fail("Logical package path traversal must fail before extraction.");
        } catch (MigrationRunnerFailure $failure) {
            self::assertSame(MigrationRunnerReason::SourceUnsafe, $failure->reason());
        }

        $unsafe = $this->directory() . "/unsafe.zip";
        $zip = new ZipArchive();
        self::assertTrue($zip->open($unsafe, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString("../escaped.txt", "unsafe");
        $zip->close();
        try {
            (new FinalSourceIntakeService($factory))->intake(
                $unsafe,
                hash_file("sha256", $unsafe),
                str_repeat("0", 64),
                "final-current-20260918-unsafe",
                $this->directory(),
                new CurrentV1SourceAdapter(),
                CurrentV1SourceAdapter::SOURCE_VERSION,
                new FinalSourceExportProvenance(
                    "2026-09-18T12:00:00+00:00",
                    "operator-1",
                    "synthetic-export",
                    "1",
                    str_repeat("a", 64),
                    "synthetic-v1-build",
                    "synthetic-runtime",
                    "freeze-evidence-1"
                ),
                new FinalSourceRetentionMetadata("retained-unsafe", "not_verified", "not_verified")
            );
            self::fail("Archive traversal must fail closed.");
        } catch (MigrationRunnerFailure $failure) {
            self::assertSame(MigrationRunnerReason::SourceUnsafe, $failure->reason());
        }
    }

    public function testEvidenceIsNoOverwriteProvenanceBoundAndPrivatePayloadFree(): void
    {
        $source = $this->source();
        $archive = $this->zip($source);
        $factory = new FilesystemMigrationSourcePackageFactory();
        $manifest = $factory->build($source)->manifestDigest();
        $intake = (new FinalSourceIntakeService($factory))->intake(
            $archive,
            hash_file("sha256", $archive),
            $manifest,
            "final-current-evidence-test",
            $this->directory(),
            new CurrentV1SourceAdapter(),
            CurrentV1SourceAdapter::SOURCE_VERSION,
            new FinalSourceExportProvenance(
                "2026-09-18T12:00:00+00:00", "operator-1", "synthetic-export", "1",
                str_repeat("a", 64), "synthetic-v1-build", "synthetic-runtime", "freeze-1"
            ),
            new FinalSourceRetentionMetadata("retained-evidence-test", "verified", "verified")
        );
        $reference = $this->snapshot($source, "reference-evidence", str_repeat("1", 64));
        $candidateInspection = (new FinalSourceInspector($factory))->inspect(
            $intake->extractionRoot(),
            new CurrentV1SourceAdapter()
        );
        $candidate = (new CurrentV1FinalSourceSnapshotBuilder())->build(
            $candidateInspection,
            $intake->identity()
        );
        $report = (new FinalSourceDriftEngine())->compare($reference, $candidate);
        $bundle = FinalPopulationContractBundle::fromReport($candidate, $report);
        $writer = new FinalSourceEvidenceWriter();
        $output = $this->directory();
        $receipt = $writer->writePreparation(
            $intake,
            $report,
            $bundle,
            new FinalSourceToolProvenance(
                str_repeat("b", 40), false, "v2.001", 1026, "2.50.0", "0.20.0", "php=8.3"
            ),
            str_repeat("c", 64),
            "final-dry-run-synthetic-1",
            $output
        );
        self::assertSame($receipt->checksum(), hash_file("sha256", $receipt->artifactPath()));
        $artifact = file_get_contents($receipt->artifactPath());
        self::assertIsString($artifact);
        self::assertStringContainsString($bundle->digest(), $artifact);
        self::assertStringContainsString(str_repeat("b", 40), $artifact);
        self::assertStringNotContainsString("private note body", $artifact);
        self::assertStringNotContainsString("Private Counterparty", $artifact);

        $this->expectException(MigrationRunnerFailure::class);
        $writer->writePreparation(
            $intake,
            $report,
            $bundle,
            new FinalSourceToolProvenance(
                str_repeat("b", 40), false, "v2.001", 1026, "2.50.0", "0.20.0", "php=8.3"
            ),
            str_repeat("c", 64),
            "final-dry-run-synthetic-1",
            $output
        );
    }

    /**
     * @param list<object> $drift
     * @return list<SourceDriftCategory>
     */
    private function categories(array $drift): array
    {
        return array_map(static fn ($item): SourceDriftCategory => $item->category(), $drift);
    }

    private function snapshot(string $root, string $id, string $archiveHash): FinalSourceSnapshot
    {
        $adapter = new CurrentV1SourceAdapter();
        $inspection = (new FinalSourceInspector(
            new FilesystemMigrationSourcePackageFactory()
        ))->inspect($root, $adapter);
        return (new CurrentV1FinalSourceSnapshotBuilder())->build(
            $inspection,
            new FinalSourcePackageIdentity(
                $id,
                $archiveHash,
                $inspection->package()->manifestDigest(),
                $adapter->sourceFamily(),
                $inspection->profile()->sourceVersion(),
                $adapter->adapterId()
            )
        );
    }

    /** @param list<array<string,mixed>> $contained */
    private function source(
        bool $extraBook = false,
        string $acquisitionType = "bought",
        bool $includeNote = true,
        string $noteBody = "private note body",
        array $contained = [],
        bool $copyLoanClosed = true
    ): string {
        $root = $this->directory();
        mkdir($root . "/data", 0700, true);
        $loan = [
            "id" => "loan-1",
            "type" => "borrowed",
            "counterparty" => "Private Counterparty",
            "startDate" => ["value" => "2020-01-02", "precision" => "day"],
            "endDate" => null,
            "notes" => "private circulation note",
        ];
        $book = [
            "id" => "book-1",
            "title" => "Synthetic Book",
            "authors" => ["Private Author"],
            "authorIds" => ["author-1"],
            "bookType" => "Leesboek",
            "carrier" => "Fysiek boek",
            "collectionStatus" => "owned",
            "ownershipStatus" => "owned",
            "readMarker" => "yes",
            "readStatus" => "finished",
            "readingRounds" => [["id" => "round-1", "startedAt" => "2020-01-02", "finishedAt" => "2020-01-03", "pauses" => []]],
            "notes" => $includeNote ? [["id" => "note-1", "text" => $noteBody, "createdAt" => "2020-01-02T00:00:00Z", "updatedAt" => "2020-01-03T00:00:00Z"]] : [],
            "circulationRounds" => [$loan],
            "containedWorks" => $contained,
            "categories" => [],
            "genres" => [],
            "seriesName" => "",
            "rating" => 4,
            "acquisition" => ["type" => $acquisitionType, "source" => "Private Shop", "date" => ["value" => "2020", "precision" => "year"]],
            "variantOfBookId" => null,
        ];
        $books = [$book];
        if ($extraBook) {
            $second = $book;
            $second["id"] = "book-2";
            $second["title"] = "Another Synthetic Book";
            $second["readingRounds"] = [];
            $second["notes"] = [];
            $second["circulationRounds"] = [];
            $second["rating"] = 0;
            $books[] = $second;
        }
        $copyLoan = $loan;
        if ($copyLoanClosed) {
            $copyLoan["endDate"] = ["value" => "2020-01-04", "precision" => "day"];
        }
        $copies = [[
            "id" => "copy-1",
            "bookId" => "book-1",
            "status" => "owned",
            "ownershipStatus" => "owned",
            "condition" => "",
            "archived" => false,
            "archiveReason" => "",
            "notes" => "",
            "acquisition" => ["type" => $acquisitionType, "source" => "Private Shop", "date" => ["value" => "2020", "precision" => "year"]],
            "circulationRounds" => [$copyLoan],
        ]];
        $this->write($root, "books.json", [
            "schemaVersion" => 29,
            "books" => $books,
            "copies" => $copies,
            "wishlistItems" => [["id" => "wish-1", "bookId" => "book-1", "fulfilledCopyId" => "", "status" => "active", "type" => "edition"]],
        ]);
        $this->write($root, "authors.json", ["schemaVersion" => 2, "authors" => [["id" => "author-1", "displayName" => "Private Author"]]]);
        $this->write($root, "reading_goals.json", ["schemaVersion" => 2, "goals" => [["id" => "goal-1", "title" => "Private Goal"]]]);
        foreach (["book_types.json", "carriers.json", "categories.json", "genres.json", "releases_excluded.json", "releases_merged.json", "taxonomy_review_queue.json"] as $file) {
            $this->write($root, $file, []);
        }
        foreach ([
            "book_enrich_cache.json" => [1, ["entries"]],
            "cache.json" => [1, ["entries"]],
            "home_prefs.json" => [1, ["hiddenWidgets", "widgetOrder"]],
            "recommendations_ignored.json" => [1, ["ignoredBookIds"]],
            "recommendations_prefs.json" => [2, ["ignored", "shown"]],
            "releases.json" => [1, ["byAuthor", "lastCheckedAt", "lastRun", "mergedDiff"]],
            "releases_prefs.json" => [1, ["trackedAuthorIds"]],
        ] as $file => [$version, $fields]) {
            $payload = ["schemaVersion" => $version];
            foreach ($fields as $field) { $payload[$field] = []; }
            $this->write($root, $file, $payload);
        }
        $this->write($root, "next_to_read.json", ["items" => []]);
        $this->write($root, "releases_dismissed.json", ["version" => 1, "dismissed" => []]);
        $this->write($root, "taxonomy_aliases.json", ["version" => 2, "rules" => []]);
        return $root;
    }

    private function write(string $root, string $file, mixed $payload): void
    {
        file_put_contents($root . "/data/" . $file, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . "/biblio-final-source-" . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $this->directories[] = $directory;
        return $directory;
    }

    private function zip(string $source): string
    {
        $archive = $this->directory() . "/source.zip";
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            self::fail("Could not create synthetic source archive.");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $relative = substr($entry->getPathname(), strlen($source) + 1);
                $zip->addFile($entry->getPathname(), str_replace(DIRECTORY_SEPARATOR, "/", $relative));
            }
        }
        $zip->close();
        return $archive;
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        chmod($directory, 0700);
        $parents = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($parents as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { chmod($entry->getPathname(), 0700); }
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) { chmod($path, 0700); rmdir($path); }
            else { chmod($path, 0600); unlink($path); }
        }
        rmdir($directory);
    }
}
