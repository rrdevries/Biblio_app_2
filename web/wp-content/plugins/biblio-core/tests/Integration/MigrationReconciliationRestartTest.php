<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Migration\Author\{
    CatalogAuthorMigrationParticipant,
    CatalogAuthorPlan,
    CatalogWorkContributorMigrationParticipant,
    CatalogWorkContributorPlan
};
use Biblio\Core\Application\Migration\Catalog\{
    CatalogEditionMigrationParticipant,
    CatalogEditionPlan,
    CatalogItemMigrationParticipant,
    CatalogItemPlan,
    CatalogWorkMigrationParticipant,
    CatalogWorkPlan
};
use Biblio\Core\Application\Migration\Notes\{
    PrivateNoteMigrationParticipant,
    PrivateNotePlan
};
use Biblio\Core\Application\Migration\Reading\{
    ReadingRoundMigrationParticipant,
    ReadingRoundPlan
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationApplyRunner,
    MigrationBuildProvenance,
    MigrationEnvironment,
    MigrationParticipantRegistry,
    MigrationParticipant,
    MigrationPlanningTarget,
    MigrationRunner,
    MigrationSourceAdapter,
    MigrationSourceAdapterRegistry,
    MigrationSourceCategoryStrategy,
    MigrationSourcePackage,
    MigrationSourceProfile,
    MigrationSourceRecord,
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
use Biblio\Core\Application\Migration\{
    BeginMigrationRunService,
    CommitMigrationRecordService,
    MigrationRunLifecycleService,
    MigrationRunStatus,
    MigrationDisposition,
    MigrationLedgerObservation,
    MigrationLedgerSnapshot,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    ObserveSourceRecordService,
    QuarantineReason,
    SourceObservation
};
use Biblio\Core\Catalog\Classification\{
    LibraryBookTypeId,
    LibraryCatalogSelection
};
use Biblio\Core\Catalog\{
    ContributorPosition,
    ContributorRole,
    EditionIsbnMetadata
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\Migration\{
    FilesystemMigrationArtifactWriter,
    FilesystemMigrationSourcePackageFactory
};
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
use Biblio\Core\Notes\StrictPrivateNoteContentPolicy;
use Biblio\Core\Reading\{ReadingDate, ReadingPeriod, ReadingRoundOutcome};
use DateTimeImmutable;

final readonly class ReconciliationFullAdapter implements MigrationSourceAdapter
{
    public function __construct(
        private UserId $userId,
        private LibraryId $libraryId,
        private LibraryBookTypeId $bookTypeId
    ) {
    }

    public function adapterId(): string { return "synthetic-reconciliation"; }
    public function sourceFamily(): string { return "synthetic-reconciliation"; }

    public function profile(MigrationSourcePackage $package): MigrationSourceProfile
    {
        unset($package);
        return new MigrationSourceProfile("reconciliation-test-1", [
            "authors" => 1,
            "books" => 3,
            "notes" => 1,
            "reading" => 1,
            "relationships" => 1,
        ], categoryStrategies: [
            new MigrationSourceCategoryStrategy("authors", [
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
            ]),
            new MigrationSourceCategoryStrategy("books", [
                CatalogWorkMigrationParticipant::SOURCE_TYPE,
                CatalogEditionMigrationParticipant::SOURCE_TYPE,
                CatalogItemMigrationParticipant::SOURCE_TYPE,
            ]),
            new MigrationSourceCategoryStrategy("notes", [
                PrivateNoteMigrationParticipant::SOURCE_TYPE,
            ]),
            new MigrationSourceCategoryStrategy("reading", [
                ReadingRoundMigrationParticipant::SOURCE_TYPE,
            ]),
            new MigrationSourceCategoryStrategy("relationships", [
                CatalogWorkContributorMigrationParticipant::SOURCE_TYPE,
            ]),
        ]);
    }

    public function supportsVersion(string $sourceVersion): bool
    {
        return $sourceVersion === "reconciliation-test-1";
    }

    public function records(
        MigrationSourcePackage $package,
        MigrationSourceProfile $profile
    ): iterable {
        unset($package, $profile);
        yield MigrationSourceRecord::typed(
            CatalogWorkContributorMigrationParticipant::SOURCE_TYPE,
            "contributor/1",
            new CatalogWorkContributorPlan(
                "author/1",
                "work/1",
                ContributorRole::Author,
                new ContributorPosition(1),
                "Synthetic Author"
            )
        );
        yield MigrationSourceRecord::typed(
            PrivateNoteMigrationParticipant::SOURCE_TYPE,
            "note/1",
            new PrivateNotePlan(
                $this->userId,
                "work/1",
                (new StrictPrivateNoteContentPolicy())->sanitize(
                    "<p>private-reconciliation-sentinel</p>"
                ),
                new DateTimeImmutable("2020-01-02T03:04:05.123456+00:00"),
                new DateTimeImmutable("2020-01-03T04:05:06.654321+00:00")
            )
        );
        yield MigrationSourceRecord::typed(
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            "item/1",
            new CatalogItemPlan(
                "edition/1",
                $this->libraryId,
                new LibraryCatalogSelection($this->bookTypeId)
            )
        );
        yield MigrationSourceRecord::typed(
            CatalogAuthorMigrationParticipant::SOURCE_TYPE,
            "author/1",
            new CatalogAuthorPlan("Synthetic Author")
        );
        yield MigrationSourceRecord::typed(
            ReadingRoundMigrationParticipant::SOURCE_TYPE,
            "round/1",
            new ReadingRoundPlan(
                $this->userId,
                "work/1",
                ReadingRoundOutcome::Completed,
                ReadingPeriod::ended(
                    ReadingDate::year(2018),
                    ReadingDate::month(2019, 7)
                )
            )
        );
        yield MigrationSourceRecord::typed(
            CatalogEditionMigrationParticipant::SOURCE_TYPE,
            "edition/1",
            new CatalogEditionPlan(
                "work/1",
                "Synthetic Edition",
                EditionIsbnMetadata::withoutIsbn()
            )
        );
        yield MigrationSourceRecord::typed(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "work/1",
            new CatalogWorkPlan("Synthetic Work")
        );
    }
}

final readonly class ReconciliationEnvironment implements MigrationEnvironment
{
    public function assertHealthy(): void
    {
    }

    public function provenance(): MigrationBuildProvenance
    {
        return new MigrationBuildProvenance(
            "v2.001",
            1026,
            "2.33.0",
            str_repeat("c", 40),
            false
        );
    }
}

final readonly class ReconciliationAccountingPlan implements TypedMigrationPlan
{
    public function __construct(private string $kind)
    {
    }

    public function kind(): string { return $this->kind; }
    public function canonicalPayload(): array { return ["kind" => $this->kind]; }
}

final readonly class ReconciliationAccountingAdapter implements MigrationSourceAdapter
{
    public function __construct(private bool $includeUnaccountedCategory = false)
    {
    }
    public function adapterId(): string { return "synthetic-accounting"; }
    public function sourceFamily(): string { return "synthetic-accounting"; }
    public function profile(MigrationSourcePackage $package): MigrationSourceProfile
    {
        unset($package);
        $counts = ["facts" => 5];
        if ($this->includeUnaccountedCategory) {
            $counts["unaccounted"] = 1;
        }
        return new MigrationSourceProfile(
            "accounting-test-1",
            $counts,
            categoryStrategies: [new MigrationSourceCategoryStrategy("facts", [
                "dependency_fact",
                "duplicate_fact",
                "preserved_fact",
                "quarantine_fact",
                "unsupported_fact",
            ])]
        );
    }
    public function supportsVersion(string $sourceVersion): bool
    {
        return $sourceVersion === "accounting-test-1";
    }
    public function records(MigrationSourcePackage $package, MigrationSourceProfile $profile): iterable
    {
        unset($package, $profile);
        foreach ([
            "dependency_fact" => "dependency",
            "duplicate_fact" => "duplicate",
            "preserved_fact" => "preserved",
            "quarantine_fact" => "quarantine",
            "unsupported_fact" => "unsupported",
        ] as $type => $kind) {
            yield MigrationSourceRecord::typed(
                $type,
                $kind . "/1",
                new ReconciliationAccountingPlan($kind)
            );
        }
    }
}

final readonly class ReconciliationAccountingParticipant implements MigrationParticipant
{
    public function __construct(private string $type)
    {
    }
    public function sourceType(): string { return $this->type; }
    public function plan(MigrationSourceRecord $record, MigrationPlanningTarget $target): PlannedMigrationRecord
    {
        unset($target);
        /** @var ReconciliationAccountingPlan $typed */
        $typed = $record->typedPlan();
        return match ($typed->kind()) {
            "preserved" => new PlannedMigrationRecord(
                MigrationDisposition::PreservedDeferred,
                reasonCode: "future_target_deferred",
                typedPlan: $typed
            ),
            "quarantine" => new PlannedMigrationRecord(
                MigrationDisposition::Quarantined,
                reasonCode: "unsupported_target_representation",
                safeExplanation: "Synthetic target representation is unavailable.",
                typedPlan: $typed
            ),
            "duplicate" => new PlannedMigrationRecord(
                MigrationDisposition::Mapped,
                [["operation" => "duplicate_mapping_probe"]],
                typedPlan: $typed
            ),
            default => new PlannedMigrationRecord(
                MigrationDisposition::Mapped,
                [["operation" => "requires_missing_dependency"]],
                dependencies: [[
                    "source_type" => "missing_fact",
                    "source_id" => "missing/1",
                ]],
                typedPlan: $typed
            ),
        };
    }
    public function apply(
        MigrationSourceRecord $record,
        SourceObservation $observation,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): MigrationRecordOutcome {
        unset($record, $observation, $target);
        /** @var ReconciliationAccountingPlan $typed */
        $typed = $plan->typedPlan();
        return match ($typed->kind()) {
            "preserved" => MigrationRecordOutcome::preserved("future_target_deferred"),
            "quarantine" => MigrationRecordOutcome::quarantined(
                QuarantineReason::UnsupportedTargetRepresentation,
                "Synthetic target representation is unavailable."
            ),
            "duplicate" => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping(
                    "synthetic_entity",
                    "synthetic-target-a",
                    \Biblio\Core\Application\Migration\MappingDisposition::Created
                ),
                new MigrationTargetMapping(
                    "synthetic_entity",
                    "synthetic-target-b",
                    \Biblio\Core\Application\Migration\MappingDisposition::Created
                ),
            ]),
            default => throw new \RuntimeException("Dependency guard did not run."),
        };
    }
}

final readonly class ReconciliationAlwaysExistingTargetInspector implements MigrationTargetInspector
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

final readonly class ReconciliationUnexpectedPlanningAdapter implements MigrationSourceAdapter
{
    public function adapterId(): string { return "synthetic-unexpected-planning"; }
    public function sourceFamily(): string { return "synthetic-unexpected-planning"; }
    public function profile(MigrationSourcePackage $package): MigrationSourceProfile
    {
        unset($package);
        return new MigrationSourceProfile(
            "unexpected-planning-1",
            ["facts" => 1],
            categoryStrategies: [new MigrationSourceCategoryStrategy(
                "facts",
                ["unexpected_planning_fact"]
            )]
        );
    }
    public function supportsVersion(string $sourceVersion): bool
    {
        return $sourceVersion === "unexpected-planning-1";
    }
    public function records(MigrationSourcePackage $package, MigrationSourceProfile $profile): iterable
    {
        unset($package, $profile);
        yield MigrationSourceRecord::typed(
            "unexpected_planning_fact",
            "unexpected/1",
            new ReconciliationAccountingPlan("unexpected")
        );
    }
}

final readonly class ReconciliationUnexpectedPlanningParticipant implements MigrationParticipant
{
    public function sourceType(): string { return "unexpected_planning_fact"; }
    public function plan(MigrationSourceRecord $record, MigrationPlanningTarget $target): PlannedMigrationRecord
    {
        unset($record, $target);
        throw new \RuntimeException("unexpected planning defect");
    }
    public function apply(
        MigrationSourceRecord $record,
        SourceObservation $observation,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): MigrationRecordOutcome {
        unset($record, $observation, $plan, $target);
        throw new \LogicException("Unexpected planning must prevent apply.");
    }
}

final class MigrationReconciliationRestartTest extends PersistenceIntegrationTestCase
{
    /** @var list<int> */
    private array $createdUsers = [];
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $userId) {
            $this->database->delete($this->database->usermeta, ["user_id" => $userId], ["%d"]);
            $this->database->delete($this->database->users, ["ID" => $userId], ["%d"]);
        }
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeDirectory($directory);
        }
        parent::tearDown();
    }

    public function testFullRunResumeSecondRunChangedSourceAndBrokenTarget(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$userA, $libraryA, $bookTypeA] = $this->target($application, "recon-a");
        $source = $this->source();
        file_put_contents(
            $source . "/source.json",
            '{"opaque":"api-key-private-evidence-sentinel"}'
        );
        $runnerA = $this->applyRunner(
            $application,
            new ReconciliationFullAdapter($userA, $libraryA, $bookTypeA)
        );

        $interrupted = $runnerA->apply(
            $source,
            "synthetic-reconciliation",
            $userA,
            $libraryA,
            3
        );
        self::assertSame(MigrationRunStatus::Interrupted, $interrupted->run()->status());
        self::assertFalse($interrupted->reconciliation()->accepted());
        self::assertGreaterThan(
            0,
            $interrupted->reconciliation()->toArray()["unexplained_drop_count"]
        );

        $resumed = $runnerA->apply(
            $source,
            "synthetic-reconciliation",
            $userA,
            $libraryA
        );
        self::assertSame(MigrationRunStatus::Completed, $resumed->run()->status());
        self::assertTrue($resumed->reconciliation()->accepted());
        self::assertSame(7, $resumed->reconciliation()->sourceObservationCount());
        self::assertSame(0, $resumed->reconciliation()->uncommittedCount());
        self::assertSame(0, $resumed->reconciliation()->unexplainedDropCount());
        self::assertSame(0, $resumed->reconciliation()->unresolvedDependencyCount());
        self::assertSame(0, $resumed->reconciliation()->brokenTargetCount());
        $reconciliation = $resumed->reconciliation()->toArray();
        self::assertSame(7, $reconciliation["source"]["enumerated_observations"]);
        self::assertSame(0, $reconciliation["uncommitted_count"]);
        self::assertSame(0, $reconciliation["unexplained_drop_count"]);
        self::assertSame(0, $reconciliation["broken_target_count"]);
        self::assertSame(7, $reconciliation["mapping_counts"]["entity"]["total"]);
        self::assertSame(2, $reconciliation["mapping_counts"]["relation"]["total"]);
        self::assertSame(7, $reconciliation["mapping_counts"]["entity"]["created"]);
        self::assertSame(2, $reconciliation["mapping_counts"]["relation"]["created"]);
        self::assertSame(9, $reconciliation["mapping_counts"]["created"]);

        $graphA = $this->targetGraph($userA, $libraryA);
        self::assertSame([
            "authors" => 1,
            "author_credits" => 1,
            "catalog_contexts" => 1,
            "editions" => 1,
            "items" => 1,
            "notes" => 1,
            "reading_rounds" => 1,
            "work_contributors" => 1,
            "works" => 1,
        ], $graphA);

        $second = $runnerA->apply(
            $source,
            "synthetic-reconciliation",
            $userA,
            $libraryA
        );
        self::assertSame($resumed->run()->id(), $second->run()->id());
        self::assertSame(0, $second->reconciliation()->toArray()["execution"]["created_targets"]);
        self::assertSame(7, $second->reconciliation()->toArray()["execution"]["skipped_committed_observations"]);
        self::assertSame($graphA, $this->targetGraph($userA, $libraryA));
        $repeat = $runnerA->apply(
            $source,
            "synthetic-reconciliation",
            $userA,
            $libraryA
        );
        self::assertSame($second->artifact()->checksum(), $repeat->artifact()->checksum());

        $artifactText = $second->artifact()->canonicalJson();
        self::assertStringNotContainsString("private-reconciliation-sentinel", $artifactText);
        self::assertStringNotContainsString("api-key-private-evidence-sentinel", $artifactText);
        self::assertStringNotContainsString("payload_json", $artifactText);
        $artifactPayload = json_decode($artifactText, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(
            $second->artifact()->sourceDigest(),
            $artifactPayload["source"]["package"]["manifest_digest"]
        );
        self::assertSame("synthetic-reconciliation", $artifactPayload["source"]["adapter_id"]);
        self::assertSame("reconciliation-test-1", $artifactPayload["source"]["source_version"]);
        self::assertSame("v2.001", $artifactPayload["build"]["product_version"]);
        self::assertSame(1026, $artifactPayload["build"]["schema_version"]);
        self::assertSame("2.33.0", $artifactPayload["build"]["core_version"]);
        self::assertSame(str_repeat("c", 40), $artifactPayload["build"]["git_revision"]);
        self::assertFalse($artifactPayload["build"]["working_tree_dirty"]);
        self::assertSame($userA->value(), $artifactPayload["target"]["target_user_id"]);
        self::assertSame($libraryA->value(), $artifactPayload["target"]["target_library_id"]);
        self::assertSame($second->run()->id(), $artifactPayload["run"]["run_id"]);
        $receipt = (new FilesystemMigrationArtifactWriter())->write(
            $second->artifact(),
            $this->directory(),
            $source
        );
        self::assertSame($second->artifact()->checksum(), $receipt->checksum());
        self::assertFileExists($receipt->checksumPath());

        [$userB, $libraryB, $bookTypeB] = $this->target($application, "recon-b");
        $clean = $this->applyRunner(
            $application,
            new ReconciliationFullAdapter($userB, $libraryB, $bookTypeB)
        )->apply(
            $source,
            "synthetic-reconciliation",
            $userB,
            $libraryB
        );
        self::assertTrue($clean->reconciliation()->accepted());
        $graphB = $this->targetGraph($userB, $libraryB);
        foreach (["catalog_contexts", "items", "notes", "reading_rounds"] as $localKey) {
            self::assertSame($graphA[$localKey], $graphB[$localKey]);
        }
        self::assertSame(
            $resumed->reconciliation()->toArray()["mapping_counts"],
            $clean->reconciliation()->toArray()["mapping_counts"]
        );
        self::assertSame(
            $this->semanticMappingGraph($resumed->run()),
            $this->semanticMappingGraph($clean->run())
        );
        foreach (["authors", "author_credits", "editions", "work_contributors", "works"] as $globalKey) {
            self::assertSame(2, $graphB[$globalKey]);
        }

        file_put_contents($source . "/source.json", "{\"changed\":true}\n");
        $changed = $runnerA->apply(
            $source,
            "synthetic-reconciliation",
            $userA,
            $libraryA
        );
        self::assertNotSame($second->run()->id(), $changed->run()->id());
        self::assertNotSame(
            $second->artifact()->sourceDigest(),
            $changed->artifact()->sourceDigest()
        );
        self::assertSame(0, $changed->reconciliation()->toArray()["execution"]["created_targets"]);
        self::assertSame(9, $changed->reconciliation()->toArray()["execution"]["reused_targets"]);
        self::assertSame(7, $changed->reconciliation()->toArray()["mapping_counts"]["entity"]["reused"]);
        self::assertSame(2, $changed->reconciliation()->toArray()["mapping_counts"]["relation"]["reused"]);
        self::assertSame($graphB, $this->targetGraph($userA, $libraryA));

        $noteId = $this->database->get_var(
            "SELECT private_note_id FROM `{$this->tableNames->privateNotes()}` "
                . "WHERE user_id='" . esc_sql($userA->value()) . "' LIMIT 1"
        );
        self::assertIsString($noteId);
        $originalWorkId = $this->database->get_var($this->database->prepare(
            "SELECT work_id FROM `{$this->tableNames->privateNotes()}` WHERE private_note_id=%s",
            $noteId
        ));
        self::assertIsString($originalWorkId);
        $wrongWorkId = $this->database->get_var($this->database->prepare(
            "SELECT work_id FROM `{$this->tableNames->works()}` WHERE work_id<>%s ORDER BY work_id LIMIT 1",
            $originalWorkId
        ));
        self::assertIsString($wrongWorkId);
        self::assertSame(1, $this->database->update(
            $this->tableNames->privateNotes(),
            ["work_id" => $wrongWorkId],
            ["private_note_id" => $noteId],
            ["%s"],
            ["%s"]
        ));
        $wrongTarget = $runnerA->apply(
            $source,
            "synthetic-reconciliation",
            $userA,
            $libraryA
        );
        self::assertFalse($wrongTarget->reconciliation()->accepted());
        self::assertSame(1, $wrongTarget->reconciliation()->brokenTargetCount());
        self::assertSame($wrongWorkId, $this->database->get_var($this->database->prepare(
            "SELECT work_id FROM `{$this->tableNames->privateNotes()}` WHERE private_note_id=%s",
            $noteId
        )));
        self::assertSame(1, $this->database->delete(
            $this->tableNames->privateNotes(),
            ["private_note_id" => $noteId],
            ["%s"]
        ));
        $broken = $runnerA->apply(
            $source,
            "synthetic-reconciliation",
            $userA,
            $libraryA
        );
        self::assertFalse($broken->reconciliation()->accepted());
        self::assertSame(1, $broken->reconciliation()->toArray()["broken_target_count"]);
        self::assertNull($this->database->get_var($this->database->prepare(
            "SELECT private_note_id FROM `{$this->tableNames->privateNotes()}` WHERE private_note_id=%s",
            $noteId
        )));

        $inspection = (new MigrationRunner(
            new FilesystemMigrationSourcePackageFactory(),
            new MigrationSourceAdapterRegistry([
                new ReconciliationFullAdapter($userA, $libraryA, $bookTypeA),
            ]),
            $application->migrationParticipants(),
            $application->personalMigrationTargets(),
            new ReconciliationEnvironment()
        ))->inspectSource($source, "synthetic-reconciliation");
        $storedRun = $changed->run();
        $foreignScopeRun = new MigrationRun(
            $storedRun->id(),
            $storedRun->sourceFamily(),
            $storedRun->sourceSnapshot(),
            $storedRun->sourceFingerprint(),
            $storedRun->sourceVersion(),
            $storedRun->migratorVersion(),
            $userB,
            $libraryB,
            $storedRun->mode(),
            $storedRun->status(),
            $storedRun->summaryStatus(),
            $storedRun->createdAt(),
            $storedRun->startedAt(),
            $storedRun->finishedAt()
        );
        try {
            $application->migrationReconciliation()->reconcile(
                $foreignScopeRun,
                $inspection
            );
            self::fail("Caller-supplied target scope must not override ledger scope.");
        } catch (ValidationException $exception) {
            self::assertStringContainsString("authoritative ledger scope", $exception->getMessage());
        }

        $foreignObservationId = $this->database->get_var($this->database->prepare(
            "SELECT observation_id FROM `{$this->tableNames->migrationSourceObservations()}` "
                . "WHERE run_id=%s ORDER BY observation_id LIMIT 1",
            $clean->run()->id()
        ));
        self::assertIsString($foreignObservationId);
        self::assertSame(1, $this->database->insert(
            $this->tableNames->migrationTargetMappings(),
            [
                "mapping_id" => hash("sha256", "cross-run-mapping-probe"),
                "run_id" => $changed->run()->id(),
                "observation_id" => $foreignObservationId,
                "target_entity_type" => "work",
                "target_entity_id" => $originalWorkId,
                "mapping_disposition" => "reused",
                "mapping_status" => "committed",
                "reason_code" => null,
                "created_at" => "2026-09-15 12:00:00.000000",
            ],
            ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s"]
        ));
        $mappingAnomaly = $runnerA->apply(
            $source,
            "synthetic-reconciliation",
            $userA,
            $libraryA
        )->reconciliation()->toArray();
        self::assertSame(1, $mappingAnomaly["mapping_anomaly_count"]);
        self::assertSame(1, $mappingAnomaly["mapping_counts"]["unclassified"]["reused"]);
        self::assertContains(
            "mapping_observation_scope_mismatch",
            array_column($mappingAnomaly["broken_targets"], "reason_code")
        );

        self::assertSame(1, $this->database->update(
            $this->tableNames->migrationSourceObservations(),
            ["source_family" => "corrupt-family"],
            [
                "run_id" => $changed->run()->id(),
                "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
            ],
            ["%s"],
            ["%s", "%s"]
        ));
        $scopeAnomaly = $runnerA->apply(
            $source,
            "synthetic-reconciliation",
            $userA,
            $libraryA
        )->reconciliation()->toArray();
        self::assertSame(1, $scopeAnomaly["observation_scope_anomaly_count"]);
        self::assertSame(1, $scopeAnomaly["unexplained_drop_count"]);
        self::assertSame(1, $scopeAnomaly["unexpected_observation_count"]);
        self::assertContains(
            "observation_run_scope_mismatch",
            array_column($scopeAnomaly["broken_targets"], "reason_code")
        );
    }

    public function testPreservationQuarantineUnsupportedAndDependencyRemainAccounted(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application, "recon-accounting");
        $source = $this->source();
        $adapter = new ReconciliationAccountingAdapter();
        $participants = new MigrationParticipantRegistry([
            new ReconciliationAccountingParticipant("dependency_fact"),
            new ReconciliationAccountingParticipant("duplicate_fact"),
            new ReconciliationAccountingParticipant("preserved_fact"),
            new ReconciliationAccountingParticipant("quarantine_fact"),
        ]);
        $environment = new ReconciliationEnvironment();
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $clock = new SystemMigrationClock();
        $reconciliation = new MigrationReconciliationService(
            $ledger,
            new MigrationMappingContractRegistry([
                new MigrationMappingContract("dependency_fact", []),
                new MigrationMappingContract("duplicate_fact", [
                    new MigrationMappingRule(
                        "synthetic_entity",
                        MigrationMappingKind::Entity,
                        true
                    ),
                ]),
                new MigrationMappingContract("preserved_fact", []),
                new MigrationMappingContract("quarantine_fact", []),
            ]),
            new ReconciliationAlwaysExistingTargetInspector()
        );
        $planningRunner = new MigrationRunner(
            new FilesystemMigrationSourcePackageFactory(),
            new MigrationSourceAdapterRegistry([$adapter]),
            $participants,
            $application->personalMigrationTargets(),
            $environment
        );
        $runner = new MigrationApplyRunner(
            $planningRunner,
            $participants,
            $application->personalMigrationTargets(),
            $environment,
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
            "mig-02-recon-1"
        );

        $result = $runner->apply(
            $source,
            "synthetic-accounting",
            $user,
            $library
        );
        $report = $result->reconciliation()->toArray();
        self::assertSame(MigrationRunStatus::Failed, $result->run()->status());
        self::assertFalse($result->reconciliation()->accepted());
        self::assertTrue($report["acceptance_flags"]["source_accounting_exact"]);
        self::assertSame(5, array_sum($report["disposition_counts"]));
        self::assertSame(1, $report["preservation"]["total"]);
        self::assertSame(1, $report["quarantine"]["total"]);
        self::assertSame([[
            "source_type" => "preserved_fact",
            "source_id" => "preserved/1",
            "reason_code" => "future_target_deferred",
        ]], $report["preservation"]["records"]);
        self::assertSame([[
            "source_type" => "quarantine_fact",
            "source_id" => "quarantine/1",
            "reason_code" => "unsupported_target_representation",
        ]], $report["quarantine"]["records"]);
        self::assertSame(2, $report["failed_count"]);
        self::assertSame(1, $report["unresolved_dependency_count"]);
        self::assertSame(["unsupported_fact"], $report["unsupported_source_types"]);
        self::assertSame(0, $report["uncommitted_count"]);
        self::assertSame(0, $report["unexplained_drop_count"]);
        self::assertSame(1, $report["broken_target_count"]);
        self::assertSame(
            "target_mapping_cardinality_exceeded",
            $report["broken_targets"][0]["reason_code"]
        );
        self::assertSame(1, $report["reason_code_summaries"]["future_target_deferred"]);
        self::assertSame(1, $report["reason_code_summaries"]["unsupported_source_type"]);

        $gapInspection = (new MigrationRunner(
            new FilesystemMigrationSourcePackageFactory(),
            new MigrationSourceAdapterRegistry([
                new ReconciliationAccountingAdapter(true),
            ]),
            $participants,
            $application->personalMigrationTargets(),
            $environment
        ))->inspectSource($source, "synthetic-accounting");
        $gapReport = $reconciliation->reconcile(
            $result->run(),
            $gapInspection
        )->toArray();
        self::assertFalse($gapReport["acceptance_flags"]["source_strategies_complete"]);
        self::assertContains(
            "missing_category_strategy:unaccounted",
            $gapReport["source"]["category_strategy_errors"]
        );
    }

    public function testUnexpectedPlanningDefectInterruptsAndRethrows(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application, "recon-unexpected");
        $participants = new MigrationParticipantRegistry([
            new ReconciliationUnexpectedPlanningParticipant(),
        ]);
        $runner = $this->customApplyRunner(
            $application,
            new ReconciliationUnexpectedPlanningAdapter(),
            $participants
        );

        try {
            $runner->apply(
                $this->source(),
                "synthetic-unexpected-planning",
                $user,
                $library
            );
            self::fail("Unexpected planning defect must be rethrown.");
        } catch (\RuntimeException $exception) {
            self::assertSame("unexpected planning defect", $exception->getMessage());
        }

        self::assertSame("interrupted", $this->database->get_var($this->database->prepare(
            "SELECT run_status FROM `{$this->tableNames->migrationRuns()}` "
                . "WHERE source_family=%s AND target_user_id=%s AND target_library_id=%s",
            "synthetic-unexpected-planning",
            $user->value(),
            $library->value()
        )));
        self::assertSame(0, (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$this->tableNames->migrationSourceObservations()}` "
                . "WHERE run_id IN (SELECT run_id FROM `{$this->tableNames->migrationRuns()}` "
                . "WHERE source_family=%s)",
            "synthetic-unexpected-planning"
        )));
    }

    /** @return array{UserId,LibraryId,LibraryBookTypeId} */
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
        $target = $application->personalMigrationTargets()->bootstrap($user);
        $bookTypeId = $this->database->get_var($this->database->prepare(
            "SELECT book_type_id FROM `{$this->tableNames->libraryBookTypes()}` "
                . "WHERE library_id=%s AND term_status='active' ORDER BY book_type_id LIMIT 1",
            $target->libraryId()->value()
        ));
        self::assertIsString($bookTypeId);
        return [$user, $target->libraryId(), new LibraryBookTypeId($bookTypeId)];
    }

    private function applyRunner(
        CoreApplication $application,
        MigrationSourceAdapter $adapter
    ): MigrationApplyRunner {
        return $this->customApplyRunner(
            $application,
            $adapter,
            $application->migrationParticipants()
        );
    }

    private function customApplyRunner(
        CoreApplication $application,
        MigrationSourceAdapter $adapter,
        MigrationParticipantRegistry $participants
    ): MigrationApplyRunner {
        $environment = new ReconciliationEnvironment();
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $clock = new SystemMigrationClock();
        $runner = new MigrationRunner(
            new FilesystemMigrationSourcePackageFactory(),
            new MigrationSourceAdapterRegistry([$adapter]),
            $participants,
            $application->personalMigrationTargets(),
            $environment
        );
        return new MigrationApplyRunner(
            $runner,
            $participants,
            $application->personalMigrationTargets(),
            $environment,
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
            $application->migrationReconciliation(),
            "mig-02-recon-1"
        );
    }

    /** @return array<string, int> */
    private function targetGraph(UserId $user, LibraryId $library): array
    {
        return [
            "authors" => (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->authors()}`"
            ),
            "author_credits" => (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->authorContributorCredits()}`"
            ),
            "catalog_contexts" => (int) $this->database->get_var($this->database->prepare(
                "SELECT COUNT(*) FROM `{$this->tableNames->libraryCatalogContexts()}` WHERE library_id=%s",
                $library->value()
            )),
            "editions" => (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->editions()}`"
            ),
            "items" => (int) $this->database->get_var($this->database->prepare(
                "SELECT COUNT(*) FROM `{$this->tableNames->items()}` WHERE library_id=%s",
                $library->value()
            )),
            "notes" => (int) $this->database->get_var($this->database->prepare(
                "SELECT COUNT(*) FROM `{$this->tableNames->privateNotes()}` WHERE user_id=%s",
                $user->value()
            )),
            "reading_rounds" => (int) $this->database->get_var($this->database->prepare(
                "SELECT COUNT(*) FROM `{$this->tableNames->readingRounds()}` WHERE user_id=%s",
                $user->value()
            )),
            "work_contributors" => (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->workContributors()}`"
            ),
            "works" => (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->works()}`"
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function semanticMappingGraph(MigrationRun $run): array
    {
        $snapshot = (new WpdbMigrationLedgerRepository(
            $this->database,
            $this->tableNames
        ))->snapshot($run->id());
        $graph = [];
        foreach ($snapshot->observations() as $observation) {
            $mappings = array_map(
                static fn (MigrationTargetMapping $mapping): array => [
                    "target_type" => $mapping->targetType(),
                    "disposition" => $mapping->disposition()->value,
                ],
                $observation->mappings()
            );
            usort(
                $mappings,
                static fn (array $left, array $right): int => $left <=> $right
            );
            $graph[] = [
                "source_type" => $observation->sourceType(),
                "source_id" => $observation->sourceId(),
                "disposition" => $observation->disposition()?->value,
                "mappings" => $mappings,
            ];
        }
        return $graph;
    }

    private function source(): string
    {
        $directory = $this->directory();
        file_put_contents($directory . "/source.json", "{\"synthetic\":true}\n");
        return $directory;
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . "/biblio-reconciliation-" . bin2hex(random_bytes(8));
        mkdir($directory, 0750, true);
        $this->temporaryDirectories[] = $directory;
        return $directory;
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
