<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Migration\{
    BeginMigrationRunService,
    CommitMigrationRecordService,
    MappingDisposition,
    MigrationLedgerObservation,
    MigrationLedgerSnapshot,
    MigrationMode,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationRunLifecycleService,
    MigrationTargetMapping,
    ObserveSourceRecordService,
    MigrationDisposition,
    SourceObservation
};
use Biblio\Core\Application\Migration\Preservation\{
    PreservedSourceEvidenceAdmission,
    PreservedSourceEvidenceAdmissionRegistry,
    PreservedSourceEvidenceMigrationFailure,
    PreservedSourceEvidenceMigrationParticipant,
    PreservedSourceEvidencePlan,
    PreservedSourceEvidencePlanGuard,
    PreservedSourceEvidencePrivacy
};
use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationApplyRunner,
    MigrationBuildProvenance,
    MigrationEnvironment,
    MigrationParticipantRegistry,
    MigrationParticipant,
    MigrationPlanningTarget,
    MigrationRunner,
    MigrationRunnerFailure,
    MigrationSourceAdapter,
    MigrationSourceAdapterRegistry,
    MigrationSourceCategoryStrategy,
    MigrationSourcePackage,
    MigrationSourceProfile,
    MigrationSourceRecord,
    MigrationSourceInspection,
    MigrationSourceMapper,
    MigrationSourceMapperRegistry,
    MigrationSourceMappingFinding,
    MigrationSourceMappingResult,
    PlannedMigrationRecord,
    TypedMigrationPlan
};
use Biblio\Core\Application\Migration\Reconciliation\{
    MigrationMappingContract,
    MigrationMappingContractRegistry,
    MigrationMappingKind,
    MigrationMappingRule,
    MigrationReconciliationService,
    MigrationTargetInspector
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\FilesystemMigrationSourcePackageFactory;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbMigrationLedgerRepository,
    WpdbTransactionManager
};
use Biblio\Core\Infrastructure\WordPress\Migration\{
    OpaqueMigrationRunIdGenerator,
    SystemMigrationClock
};
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;

final readonly class SyntheticPreservationEnvironment implements MigrationEnvironment
{
    public function assertHealthy(): void
    {
    }

    public function provenance(): MigrationBuildProvenance
    {
        return new MigrationBuildProvenance(
            "v2.001",
            1026,
            "2.44.0",
            str_repeat("d", 40),
            false
        );
    }
}

final readonly class SyntheticPreservationAdapter implements MigrationSourceAdapter
{
    public const ADAPTER_ID = "synthetic-preservation";
    public const SOURCE_FAMILY = "synthetic-preservation";
    public const SOURCE_VERSION = "synthetic-preservation-1";

    public function __construct(
        private string $reasonCode = "synthetic_target_deferred",
        private string $mappingContract = "synthetic-preservation-contract-v1",
        private string $sourceField = "body",
        private ?string $evidenceHashOverride = null,
        private ?string $reportedMappingContract = null,
        private ?string $planAdapterId = null,
        private ?string $planSourceFamily = null,
        private ?string $planSourceVersion = null,
        private ?string $planManifest = null,
        private bool $includeActive = false,
        /** @var list<array{source_type:string,source_id:string}> */
        private array $forbiddenSourceIdentities = []
    ) {
    }

    public function adapterId(): string { return self::ADAPTER_ID; }
    public function sourceFamily(): string { return self::SOURCE_FAMILY; }
    public function mappingContract(): string
    {
        return $this->reportedMappingContract ?? $this->mappingContract;
    }
    public function includesActive(): bool { return $this->includeActive; }

    public function profile(MigrationSourcePackage $package): MigrationSourceProfile
    {
        $rows = $this->rows($package);
        return new MigrationSourceProfile(
            self::SOURCE_VERSION,
            ["evidence" => count($rows) + ($this->includeActive ? 1 : 0)],
            categoryStrategies: [new MigrationSourceCategoryStrategy(
                "evidence",
                array_values(array_filter([
                    PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
                    $this->includeActive ? SyntheticActiveParticipant::SOURCE_TYPE : null,
                ]))
            )]
        );
    }

    public function supportsVersion(string $sourceVersion): bool
    {
        return $sourceVersion === self::SOURCE_VERSION;
    }

    public function records(
        MigrationSourcePackage $package,
        MigrationSourceProfile $profile
    ): iterable {
        unset($profile);
        if ($this->includeActive) {
            yield MigrationSourceRecord::typed(
                SyntheticActiveParticipant::SOURCE_TYPE,
                "v1.copy/copy-1/item",
                new SyntheticActivePlan("synthetic-target-1")
            );
        }
        foreach ($this->rows($package) as $row) {
            $sourceIdentity = "synthetic:evidence:" . $row["id"];
            $body = $row[$this->sourceField] ?? null;
            if (!is_string($body) || $body === "") {
                throw new \RuntimeException("Synthetic restricted evidence is invalid.");
            }
            $plan = new PreservedSourceEvidencePlan(
                $sourceIdentity,
                "synthetic_private_evidence",
                $this->reasonCode,
                $this->planAdapterId ?? self::ADAPTER_ID,
                $this->planSourceFamily ?? self::SOURCE_FAMILY,
                $this->planSourceVersion ?? self::SOURCE_VERSION,
                $this->planManifest ?? $package->manifestDigest(),
                $this->mappingContract,
                "source.json",
                "records",
                $row["id"],
                $this->sourceField,
                $this->evidenceHashOverride ?? DeterministicJson::hash([
                    "source_slot" => $sourceIdentity,
                    "body" => $body,
                ]),
                PreservedSourceEvidencePrivacy::RestrictedSource,
                forbiddenSourceIdentities: $this->forbiddenSourceIdentities
            );
            yield MigrationSourceRecord::typed(
                PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
                $sourceIdentity,
                $plan
            );
        }
    }

    /** @return list<array{id:string,body:string,alternate_body:string}> */
    private function rows(MigrationSourcePackage $package): array
    {
        $decoded = json_decode($package->read("source.json"), true, 16, JSON_THROW_ON_ERROR);
        $rows = $decoded["records"] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException("Synthetic source is invalid.");
        }
        return $rows;
    }
}

final readonly class SyntheticPreservationMapper implements MigrationSourceMapper
{
    public function __construct(private SyntheticPreservationAdapter $adapter)
    {
    }

    public function adapterId(): string
    {
        return SyntheticPreservationAdapter::ADAPTER_ID;
    }

    public function map(
        MigrationSourceInspection $inspection,
        MigrationPlanningTarget $target
    ): MigrationSourceMappingResult {
        unset($target);
        $findings = [new MigrationSourceMappingFinding(
            "synthetic.preservation",
            "mapping_contract:" . $this->adapter->mappingContract(),
            MigrationDisposition::Mapped,
            "synthetic_mapping_contract_applied"
        )];
        foreach ($inspection->records() as $record) {
            if (
                $record->sourceType()
                !== PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE
            ) {
                continue;
            }
            $findings[] = new MigrationSourceMappingFinding(
                "synthetic.preservation",
                $record->sourceId(),
                MigrationDisposition::PreservedDeferred,
                "synthetic_evidence_preserved",
                [[
                    "source_type" => $record->sourceType(),
                    "source_id" => $record->sourceId(),
                ]]
            );
        }
        return new MigrationSourceMappingResult(
            $inspection->records(),
            $findings
        );
    }
}

final readonly class SyntheticActivePlan implements TypedMigrationPlan
{
    public function __construct(private string $targetId)
    {
    }

    public function targetId(): string { return $this->targetId; }
    public function canonicalPayload(): array { return ["target_id" => $this->targetId]; }
}

final readonly class SyntheticActiveParticipant implements MigrationParticipant
{
    public const SOURCE_TYPE = "catalog_item";

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        unset($target);
        $typed = $record->typedPlan();
        if (!$typed instanceof SyntheticActivePlan) {
            throw new \RuntimeException("Synthetic active plan is invalid.");
        }
        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [["operation" => "create_synthetic_active"]],
            typedPlan: $typed
        );
    }

    public function apply(
        MigrationSourceRecord $record,
        SourceObservation $observation,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): MigrationRecordOutcome {
        unset($record, $observation, $target);
        $typed = $plan->typedPlan();
        if (!$typed instanceof SyntheticActivePlan) {
            throw new \RuntimeException("Synthetic active plan is invalid.");
        }
        return MigrationRecordOutcome::mapped([new MigrationTargetMapping(
            "synthetic_entity",
            $typed->targetId(),
            MappingDisposition::Created
        )]);
    }
}

final readonly class SyntheticExistingTargetInspector implements MigrationTargetInspector
{
    public function exists(
        MigrationRun $run,
        MigrationSourceRecord $record,
        MigrationLedgerObservation $observation,
        MigrationTargetMapping $mapping,
        MigrationLedgerSnapshot $snapshot
    ): bool {
        unset($run, $record, $observation, $mapping, $snapshot);
        return true;
    }
}

final class PreservedSourceEvidenceMigrationTest extends PersistenceIntegrationTestCase
{
    /** @var list<int> */
    private array $createdUsers = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $userId) {
            $this->database->delete(
                $this->database->usermeta,
                ["user_id" => $userId],
                ["%d"]
            );
            $this->database->delete(
                $this->database->users,
                ["ID" => $userId],
                ["%d"]
            );
        }
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeDirectory($directory);
        }
        parent::tearDown();
    }

    public function testNoTargetCommitReplayAndRunStatusIndependentLookup(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application, "preserve-replay");
        $source = $this->source(["private-preservation-sentinel"]);
        $adapter = new SyntheticPreservationAdapter();
        $firstRunner = $this->runner($application, $adapter, "preserve-test-v1");

        $dryRun = $firstRunner->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        );
        self::assertSame(0, $this->observationCount());
        self::assertStringNotContainsString(
            "private-preservation-sentinel",
            $dryRun->canonicalJson()
        );

        $first = $firstRunner->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $this->digest($dryRun->payload())
        );
        self::assertTrue($first->reconciliation()->accepted());
        self::assertSame(1, $this->observationCount());
        self::assertSame(1, $this->preservationCount());
        self::assertSame(0, $this->mappingCount());
        self::assertSame(
            1,
            $first->reconciliation()->toArray()["disposition_counts"]["preserved_deferred"]
        );
        self::assertSame(
            "synthetic_target_deferred",
            $first->reconciliation()->toArray()["preservation"]["records"][0]["reason_code"]
        );
        self::assertStringNotContainsString(
            "private-preservation-sentinel",
            $first->artifact()->canonicalJson()
        );

        foreach (["interrupted", "failed", "running"] as $index => $status) {
            $this->database->update(
                $this->tableNames->migrationRuns(),
                ["run_status" => $status],
                ["run_id" => $first->run()->id()],
                ["%s"],
                ["%s"]
            );
            $runner = $this->runner(
                $application,
                $adapter,
                "preserve-test-replay-" . ($index + 2)
            );
            $artifact = $runner->dryRunArtifact(
                $source,
                SyntheticPreservationAdapter::ADAPTER_ID,
                $user,
                $library
            );
            $replay = $runner->apply(
                $source,
                SyntheticPreservationAdapter::ADAPTER_ID,
                $user,
                $library,
                $this->digest($artifact->payload())
            );
            self::assertTrue($replay->reconciliation()->accepted());
            self::assertSame(
                1,
                $replay->reconciliation()->toArray()["execution"]["reused_prior_preservations"]
            );
            self::assertSame(1, $this->observationCount());
            self::assertSame(1, $this->preservationCount());
        }
    }

    public function testCommittedForbiddenSourceMappingFailsBeforeNewRunWrite(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application, "preserve-forbidden");
        $source = $this->source(["private-terminal-preservation-sentinel"]);
        $initialAdapter = new SyntheticPreservationAdapter(includeActive: true);
        $initialRunner = $this->runner(
            $application,
            $initialAdapter,
            "preserve-forbidden-v1"
        );
        $initialArtifact = $initialRunner->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        );
        $initial = $initialRunner->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $this->digest($initialArtifact->payload())
        );
        self::assertTrue($initial->reconciliation()->accepted());
        self::assertSame(1, $this->mappingCount());

        $correctedAdapter = new SyntheticPreservationAdapter(
            forbiddenSourceIdentities: [[
                "source_type" => "catalog_item",
                "source_id" => "v1.copy/copy-1/item",
            ]]
        );
        $counts = [
            "runs" => $this->runCount(),
            "observations" => $this->observationCount(),
            "preservations" => $this->preservationCount(),
            "mappings" => $this->mappingCount(),
        ];

        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $planning = $this->planning($application, $correctedAdapter, $ledger);
        $inspection = $planning->inspectSource(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID
        );
        $prepared = $planning->prepare(
            $inspection,
            new MigrationPlanningTarget(
                $application->personalMigrationTargets()->validate($user, $library)
            )
        );
        try {
            $application->migrationReconciliation()->reconcile(
                $initial->run(),
                $prepared
            );
            self::fail("Reconciliation must reject a committed forbidden mapping.");
        } catch (PreservedSourceEvidenceMigrationFailure $failure) {
            self::assertSame(
                "prior_mapping_conflicts_with_terminal_preservation",
                $failure->reasonCode()
            );
        }

        foreach (["completed", "failed", "interrupted", "running"] as $status) {
            $this->database->update(
                $this->tableNames->migrationRuns(),
                ["run_status" => $status],
                ["run_id" => $initial->run()->id()],
                ["%s"],
                ["%s"]
            );
            $runner = $this->runner(
                $application,
                $correctedAdapter,
                "preserve-forbidden-" . $status
            );
            $artifact = $runner->dryRunArtifact(
                $source,
                SyntheticPreservationAdapter::ADAPTER_ID,
                $user,
                $library
            );
            try {
                $runner->apply(
                    $source,
                    SyntheticPreservationAdapter::ADAPTER_ID,
                    $user,
                    $library,
                    $this->digest($artifact->payload())
                );
                self::fail("Committed forbidden mapping must stop apply preflight.");
            } catch (PreservedSourceEvidenceMigrationFailure $failure) {
                self::assertSame(
                    "prior_mapping_conflicts_with_terminal_preservation",
                    $failure->reasonCode()
                );
            }
            self::assertSame($counts["runs"], $this->runCount());
            self::assertSame($counts["observations"], $this->observationCount());
            self::assertSame($counts["preservations"], $this->preservationCount());
            self::assertSame($counts["mappings"], $this->mappingCount());
        }
    }

    public function testInterruptionResumesOnlyMissingPreservations(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application, "preserve-resume");
        $source = $this->source(["private-one", "private-two", "private-three"]);
        $runner = $this->runner(
            $application,
            new SyntheticPreservationAdapter(),
            "preserve-resume-v1"
        );
        $artifact = $runner->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        );
        $digest = $this->digest($artifact->payload());

        $interrupted = $runner->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $digest,
            1
        );
        self::assertSame("interrupted", $interrupted->run()->status()->value);
        self::assertSame(1, $this->observationCount());

        $resumed = $runner->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $digest
        );
        self::assertSame("completed", $resumed->run()->status()->value);
        self::assertTrue($resumed->reconciliation()->accepted());
        self::assertSame(3, $this->observationCount());
        self::assertSame(3, $this->preservationCount());
        self::assertSame(0, $this->mappingCount());
        self::assertSame(
            1,
            $resumed->reconciliation()->toArray()["execution"]["reused_prior_preservations"]
        );
    }

    public function testSeveralCommittedThenFailedResumeRejectsDivergenceAndCompletesMissing(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application, "preserve-failed-resume");
        $source = $this->source([
            "private-first",
            "private-second",
            "private-third",
            "private-fourth",
        ]);
        $adapter = new SyntheticPreservationAdapter();
        $runner = $this->runner($application, $adapter, "preserve-failed-resume-v1");
        $dryRun = $runner->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        );
        $digest = $this->digest($dryRun->payload());
        $interrupted = $runner->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $digest,
            2
        );
        self::assertSame("interrupted", $interrupted->run()->status()->value);
        self::assertSame(2, $this->observationCount());
        self::assertSame(2, $this->preservationCount());
        $this->failRun($interrupted->run()->id());

        $divergentAdapter = new SyntheticPreservationAdapter(
            mappingContract: "synthetic-preservation-contract-v2"
        );
        $divergent = $this->runner(
            $application,
            $divergentAdapter,
            "preserve-failed-resume-v1"
        );
        $divergentDryRun = $divergent->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        );
        try {
            $divergent->apply(
                $source,
                SyntheticPreservationAdapter::ADAPTER_ID,
                $user,
                $library,
                $this->digest($divergentDryRun->payload())
            );
            self::fail("Divergent resume must fail before a new write.");
        } catch (PreservedSourceEvidenceMigrationFailure $exception) {
            self::assertSame(
                "divergent_preserved_source_evidence",
                $exception->reasonCode()
            );
        }
        self::assertSame(1, $this->runCount());
        self::assertSame(2, $this->observationCount());

        $resumed = $runner->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $digest
        );
        self::assertSame("completed", $resumed->run()->status()->value);
        self::assertSame(4, $this->observationCount());
        self::assertSame(4, $this->preservationCount());
        self::assertSame(
            2,
            $resumed->reconciliation()->toArray()["execution"]["reused_prior_preservations"]
        );
    }

    public function testDivergentPriorEvidenceFailsBeforeAnyNewWrite(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application, "preserve-diverge");
        $source = $this->source(["private-original"]);
        $initial = $this->runner(
            $application,
            new SyntheticPreservationAdapter(),
            "preserve-diverge-v1"
        );
        $initialDryRun = $initial->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        );
        $initial->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $this->digest($initialDryRun->payload())
        );
        $runCount = $this->runCount();

        $variants = [
            new SyntheticPreservationAdapter(
                evidenceHashOverride: str_repeat("a", 64)
            ),
            new SyntheticPreservationAdapter(reasonCode: "synthetic_reason_changed"),
            new SyntheticPreservationAdapter(sourceField: "alternate_body"),
            new SyntheticPreservationAdapter(
                mappingContract: "synthetic-preservation-contract-v2"
            ),
        ];
        foreach ($variants as $index => $variant) {
            $runner = $this->runner(
                $application,
                $variant,
                "preserve-diverge-variant-" . $index
            );
            $dryRun = $runner->dryRunArtifact(
                $source,
                SyntheticPreservationAdapter::ADAPTER_ID,
                $user,
                $library
            );
            try {
                $runner->apply(
                    $source,
                    SyntheticPreservationAdapter::ADAPTER_ID,
                    $user,
                    $library,
                    $this->digest($dryRun->payload())
                );
                self::fail("Divergent prior preservation must fail closed.");
            } catch (PreservedSourceEvidenceMigrationFailure $exception) {
                self::assertSame(
                    "divergent_preserved_source_evidence",
                    $exception->reasonCode()
                );
                self::assertStringNotContainsString(
                    "private-original",
                    $exception->getMessage()
                );
            }
            self::assertSame($runCount, $this->runCount());
            self::assertSame(1, $this->observationCount());
        }

        $changedSource = $this->source(["private-source-changed"]);
        $changedRunner = $this->runner(
            $application,
            new SyntheticPreservationAdapter(),
            "preserve-diverge-source"
        );
        $changedDryRun = $changedRunner->dryRunArtifact(
            $changedSource,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        );
        $this->expectException(PreservedSourceEvidenceMigrationFailure::class);
        try {
            $changedRunner->apply(
                $changedSource,
                SyntheticPreservationAdapter::ADAPTER_ID,
                $user,
                $library,
                $this->digest($changedDryRun->payload())
            );
        } finally {
            self::assertSame($runCount, $this->runCount());
            self::assertSame(1, $this->observationCount());
        }
    }

    public function testStalePreparedDigestFailsBeforeRunWrite(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application, "preserve-digest");
        $source = $this->source(["private-digest"]);
        $runner = $this->runner(
            $application,
            new SyntheticPreservationAdapter(),
            "preserve-digest-v1"
        );
        $accepted = $this->digest($runner->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        )->payload());

        [$otherUser, $otherLibrary] = $this->target(
            $application,
            "preserve-digest-other"
        );
        $otherDigest = $this->digest($runner->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $otherUser,
            $otherLibrary
        )->payload());
        self::assertNotSame($accepted, $otherDigest);
        try {
            $runner->apply(
                $source,
                SyntheticPreservationAdapter::ADAPTER_ID,
                $otherUser,
                $otherLibrary,
                $accepted
            );
            self::fail("Prepared digest from another target must fail.");
        } catch (MigrationRunnerFailure $exception) {
            self::assertSame("prepared_plan_mismatch", $exception->reason()->value);
        }
        self::assertSame(0, $this->runCount());

        $changedRunner = $this->runner(
            $application,
            new SyntheticPreservationAdapter(
                mappingContract: "synthetic-preservation-contract-v2"
            ),
            "preserve-digest-v2"
        );
        try {
            $changedRunner->apply(
                $source,
                SyntheticPreservationAdapter::ADAPTER_ID,
                $user,
                $library,
                $accepted
            );
            self::fail("Stale prepared digest must fail before writes.");
        } catch (MigrationRunnerFailure $exception) {
            self::assertSame("prepared_plan_mismatch", $exception->reason()->value);
        }
        self::assertSame(0, $this->runCount());
        self::assertSame(0, $this->observationCount());
    }

    public function testInvalidTypedProvenanceFailsDuringPreparationBeforeWrites(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application, "preserve-provenance");
        $source = $this->source(["private-provenance"]);
        $variants = [
            new SyntheticPreservationAdapter(planAdapterId: "wrong-adapter"),
            new SyntheticPreservationAdapter(planSourceFamily: "wrong-family"),
            new SyntheticPreservationAdapter(planSourceVersion: "wrong-version"),
            new SyntheticPreservationAdapter(planManifest: str_repeat("a", 64)),
            new SyntheticPreservationAdapter(
                mappingContract: "synthetic-preservation-contract-v2",
                reportedMappingContract: "synthetic-preservation-contract-v1"
            ),
        ];

        foreach ($variants as $index => $adapter) {
            $runner = $this->runner(
                $application,
                $adapter,
                "preserve-provenance-" . $index
            );
            try {
                $runner->dryRunArtifact(
                    $source,
                    SyntheticPreservationAdapter::ADAPTER_ID,
                    $user,
                    $library
                );
                self::fail("Invalid typed preservation provenance must fail preparation.");
            } catch (PreservedSourceEvidenceMigrationFailure $exception) {
                self::assertSame(
                    "invalid_preserved_source_evidence_provenance",
                    $exception->reasonCode()
                );
                self::assertStringNotContainsString(
                    "private-provenance",
                    $exception->getMessage()
                );
            }
        }
        self::assertSame(0, $this->runCount());
        self::assertSame(0, $this->observationCount());
    }

    public function testReconciliationRejectsWrongPreservationDescriptorOrLocator(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application, "preserve-reconcile");
        $source = $this->source(["private-reconcile"]);
        $adapter = new SyntheticPreservationAdapter();
        $runner = $this->runner($application, $adapter, "preserve-reconcile-v1");
        $dryRun = $runner->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        );
        $result = $runner->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $this->digest($dryRun->payload())
        );
        self::assertTrue($result->reconciliation()->accepted());

        $this->database->update(
            $this->tableNames->migrationPreservations(),
            ["evidence_reference" => "source.json#records/record-1/wrong"],
            ["run_id" => $result->run()->id()],
            ["%s"],
            ["%s"]
        );
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $planning = $this->planning($application, $adapter, $ledger);
        $inspection = $planning->inspectSource(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID
        );
        $prepared = $planning->prepare(
            $inspection,
            new MigrationPlanningTarget(
                $application->personalMigrationTargets()->validate($user, $library)
            )
        );
        $report = $application->migrationReconciliation()->reconcile(
            $result->run(),
            $prepared
        )->toArray();
        self::assertFalse($report["accepted"]);
        self::assertSame(1, $report["broken_target_count"]);
        self::assertSame(
            "preservation_outcome_mismatch",
            $report["broken_targets"][0]["reason_code"]
        );
    }

    public function testActiveAndIndependentPreservedPlansCoexistWithoutFindingDoubleCount(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application, "preserve-coexist");
        $source = $this->source(["private-coexist"]);
        $adapter = new SyntheticPreservationAdapter(includeActive: true);
        $runner = $this->runner($application, $adapter, "preserve-coexist-v1");
        $dryRun = $runner->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        );
        $payload = $dryRun->payload();
        self::assertSame(2, $payload["planning_reconciliation"]["planned_observations"]);
        self::assertSame(1, $payload["plan"]["disposition_counts"]["mapped"]);
        self::assertSame(
            1,
            $payload["plan"]["disposition_counts"]["preserved_deferred"]
        );
        self::assertSame(
            1,
            $payload["plan"]["source_mapping_finding_counts"][
                "synthetic_evidence_preserved"
            ]
        );

        $interrupted = $runner->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $this->digest($payload),
            1
        );
        self::assertSame("interrupted", $interrupted->run()->status()->value);
        self::assertSame(1, $this->observationCount());
        self::assertSame(0, $this->preservationCount());
        self::assertSame(1, $this->mappingCount());

        $result = $runner->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $this->digest($payload)
        );
        $report = $result->reconciliation()->toArray();
        self::assertTrue($report["accepted"]);
        self::assertSame(2, $report["source"]["enumerated_observations"]);
        self::assertSame(1, $report["preservation"]["total"]);
        self::assertSame(1, $report["mapping_counts"]["total"]);
        self::assertSame(2, $this->observationCount());
        self::assertSame(1, $this->preservationCount());
        self::assertSame(1, $this->mappingCount());
    }

    public function testMultiplePriorEvidenceRequiresEveryMatchToBeEquivalent(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application, "preserve-multiple");
        $source = $this->source(["private-multiple"]);
        $adapter = new SyntheticPreservationAdapter();
        $first = $this->runner($application, $adapter, "preserve-multiple-v1");
        $dryRun = $first->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        );
        $first->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $this->digest($dryRun->payload())
        );

        $this->commitDirect(
            $application,
            $adapter,
            $source,
            $user,
            $library,
            "preserve-multiple-v2"
        );
        self::assertSame(2, $this->observationCount());
        self::assertSame(2, $this->preservationCount());

        $equivalentRunner = $this->runner(
            $application,
            $adapter,
            "preserve-multiple-v3"
        );
        $equivalentDryRun = $equivalentRunner->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        );
        $equivalent = $equivalentRunner->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $this->digest($equivalentDryRun->payload())
        );
        self::assertTrue($equivalent->reconciliation()->accepted());
        self::assertSame(2, $this->observationCount());

        $divergentAdapter = new SyntheticPreservationAdapter(
            mappingContract: "synthetic-preservation-contract-v2"
        );
        $this->commitDirect(
            $application,
            $divergentAdapter,
            $source,
            $user,
            $library,
            "preserve-multiple-v4"
        );
        self::assertSame(3, $this->observationCount());

        $runner = $this->runner(
            $application,
            $adapter,
            "preserve-multiple-v5"
        );
        $artifact = $runner->dryRunArtifact(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library
        );
        $this->expectException(PreservedSourceEvidenceMigrationFailure::class);
        $runner->apply(
            $source,
            SyntheticPreservationAdapter::ADAPTER_ID,
            $user,
            $library,
            $this->digest($artifact->payload())
        );
    }

    /** @return array{UserId,LibraryId} */
    private function target(CoreApplication $application, string $prefix): array
    {
        $userId = wp_create_user(
            $prefix . "-user",
            "synthetic-test-password",
            $prefix . "@example.invalid"
        );
        self::assertIsInt($userId);
        $this->createdUsers[] = $userId;
        $user = new UserId((string) $userId);
        return [$user, $application->personalMigrationTargets()->bootstrap($user)->libraryId()];
    }

    private function runner(
        CoreApplication $application,
        SyntheticPreservationAdapter $adapter,
        string $migratorVersion
    ): MigrationApplyRunner {
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $clock = new SystemMigrationClock();
        $planning = $this->planning($application, $adapter, $ledger);
        $reconciliation = $adapter->includesActive()
            ? new MigrationReconciliationService(
                $ledger,
                new MigrationMappingContractRegistry([
                    new MigrationMappingContract(
                        PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
                        []
                    ),
                    new MigrationMappingContract(
                        SyntheticActiveParticipant::SOURCE_TYPE,
                        [new MigrationMappingRule(
                            "synthetic_entity",
                            MigrationMappingKind::Entity,
                            true
                        )]
                    ),
                ]),
                new SyntheticExistingTargetInspector()
            )
            : $application->migrationReconciliation();
        return new MigrationApplyRunner(
            $planning,
            $application->personalMigrationTargets(),
            new SyntheticPreservationEnvironment(),
            new BeginMigrationRunService(
                $application->personalMigrationTargets(),
                $ledger,
                $transactions,
                $clock,
                new OpaqueMigrationRunIdGenerator()
            ),
            new ObserveSourceRecordService($ledger, $clock),
            new CommitMigrationRecordService($ledger, $transactions, $clock),
            new MigrationRunLifecycleService($ledger, $transactions, $clock),
            $ledger,
            $reconciliation,
            $migratorVersion
        );
    }

    private function planning(
        CoreApplication $application,
        SyntheticPreservationAdapter $adapter,
        WpdbMigrationLedgerRepository $ledger
    ): MigrationRunner {
        $admissions = new PreservedSourceEvidenceAdmissionRegistry([
            new PreservedSourceEvidenceAdmission(
                "synthetic_private_evidence",
                "synthetic_target_deferred",
                PreservedSourceEvidencePrivacy::RestrictedSource
            ),
            new PreservedSourceEvidenceAdmission(
                "synthetic_private_evidence",
                "synthetic_reason_changed",
                PreservedSourceEvidencePrivacy::RestrictedSource
            ),
        ]);
        $participant = new PreservedSourceEvidenceMigrationParticipant(
            $ledger,
            new PreservedSourceEvidencePlanGuard($admissions)
        );
        $participantList = [$participant];
        if ($adapter->includesActive()) {
            $participantList[] = new SyntheticActiveParticipant();
        }
        $participants = new MigrationParticipantRegistry($participantList);
        $environment = new SyntheticPreservationEnvironment();
        return new MigrationRunner(
            new FilesystemMigrationSourcePackageFactory(),
            new MigrationSourceAdapterRegistry([$adapter]),
            $participants,
            $application->personalMigrationTargets(),
            $environment,
            new MigrationSourceMapperRegistry([
                new SyntheticPreservationMapper($adapter),
            ])
        );
    }

    private function commitDirect(
        CoreApplication $application,
        SyntheticPreservationAdapter $adapter,
        string $source,
        UserId $user,
        LibraryId $library,
        string $migratorVersion
    ): void {
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $clock = new SystemMigrationClock();
        $participant = new PreservedSourceEvidenceMigrationParticipant(
            $ledger,
            new PreservedSourceEvidencePlanGuard(
                new PreservedSourceEvidenceAdmissionRegistry([
                    new PreservedSourceEvidenceAdmission(
                        "synthetic_private_evidence",
                        "synthetic_target_deferred",
                        PreservedSourceEvidencePrivacy::RestrictedSource
                    ),
                ])
            )
        );
        $package = (new FilesystemMigrationSourcePackageFactory())->build($source);
        $profile = $adapter->profile($package);
        $records = iterator_to_array($adapter->records($package, $profile), false);
        self::assertCount(1, $records);
        $record = $records[0];
        self::assertInstanceOf(MigrationSourceRecord::class, $record);
        $target = new MigrationPlanningTarget(
            $application->personalMigrationTargets()->validate($user, $library)
        );
        $planned = $participant->plan($record, $target);
        $run = (new BeginMigrationRunService(
            $application->personalMigrationTargets(),
            $ledger,
            $transactions,
            $clock,
            new OpaqueMigrationRunIdGenerator()
        ))->begin(
            $adapter->sourceFamily(),
            $package->manifestDigest(),
            $package->manifestDigest(),
            $profile->sourceVersion(),
            $migratorVersion,
            $user,
            $library,
            MigrationMode::Apply
        );
        $observation = (new ObserveSourceRecordService($ledger, $clock))->observe(
            $run,
            $record->sourceType(),
            $record->sourceId(),
            $record->payloadHash(),
            $record->payload()
        );
        (new CommitMigrationRecordService($ledger, $transactions, $clock))->commit(
            $run,
            $observation,
            static fn () => $participant->apply(
                $record,
                $observation,
                $planned,
                $target
            )
        );
        (new MigrationRunLifecycleService($ledger, $transactions, $clock))->complete($run);
    }

    /** @param array<string, mixed> $payload */
    private function digest(array $payload): string
    {
        $digest = $payload["prepared_plan"]["plan_set_digest"] ?? null;
        self::assertIsString($digest);
        return $digest;
    }

    /** @param list<string> $bodies */
    private function source(array $bodies): string
    {
        $directory = sys_get_temp_dir() . "/biblio-preservation-" . bin2hex(random_bytes(8));
        mkdir($directory, 0750, true);
        $this->temporaryDirectories[] = $directory;
        $records = [];
        foreach ($bodies as $index => $body) {
            $records[] = [
                "id" => "record-" . ($index + 1),
                "body" => $body,
                "alternate_body" => $body,
            ];
        }
        file_put_contents(
            $directory . "/source.json",
            json_encode(["records" => $records], JSON_THROW_ON_ERROR) . "\n"
        );
        return $directory;
    }

    private function runCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->migrationRuns()}`"
        );
    }

    private function observationCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->migrationSourceObservations()}`"
        );
    }

    private function preservationCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->migrationPreservations()}`"
        );
    }

    private function mappingCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->migrationTargetMappings()}`"
        );
    }

    private function failRun(string $runId): void
    {
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $run = $ledger->findRun($runId);
        self::assertNotNull($run);
        (new MigrationRunLifecycleService(
            $ledger,
            new WpdbTransactionManager($this->database),
            new SystemMigrationClock()
        ))->fail($run);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== "." && $entry !== "..") {
                unlink($directory . "/" . $entry);
            }
        }
        rmdir($directory);
    }
}
