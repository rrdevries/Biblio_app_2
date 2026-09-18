<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Identity\{PersonalMigrationTarget,PersonalMigrationTargetReadiness};
use Biblio\Core\Application\Migration\{
    CommitMigrationRecordService,
    MappingDisposition,
    MigrationClock,
    MigrationDisposition,
    MigrationMode,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    ObserveSourceRecordService,
    SourceObservation
};
use Biblio\Core\Application\Migration\Catalog\{CatalogWorkMigrationParticipant,CatalogWorkPlan};
use Biblio\Core\Application\Migration\Catalog\CatalogMigrationItemRepository;
use Biblio\Core\Application\Migration\Preservation\{
    PreservedSourceEvidenceAdmission,
    PreservedSourceEvidenceAdmissionRegistry,
    PreservedSourceEvidenceMigrationParticipant,
    PreservedSourceEvidencePlan,
    PreservedSourceEvidencePlanGuard,
    PreservedSourceEvidencePrivacy
};
use Biblio\Core\Application\Migration\Reconciliation\{CoreMigrationTargetInspector,CurrentMigrationMappingContracts,MigrationReconciliationService};
use Biblio\Core\Application\Migration\Runner\{DeterministicJson,MigrationParticipant,MigrationPlanningTarget,MigrationSourceAdapter,MigrationSourceInspection,MigrationSourcePackage,MigrationSourceProfile,MigrationSourceRecord,PlannedMigrationRecord,PreparedMigrationPlan,PreparedMigrationRecord};
use Biblio\Core\Application\Migration\Series\{
    CatalogSeriesMigrationParticipant,
    CatalogSeriesPlan,
    CatalogWorkSeriesMigrationParticipant,
    CatalogWorkSeriesPlan,
    SeriesMigrationFailure,
    SeriesMigrationReason,
    SeriesMigrationWriter,
    SeriesPreservationPromotionPolicy
};
use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditRepository;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextRepository;
use Biblio\Core\Catalog\{AuthorRepository,EditionIdentifierClaimRepository,EditionRepository,ItemLocalDetailsRepository,Series,SeriesId,SeriesPosition,Work,WorkId};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbLibraryRepository,WpdbMigrationLedgerRepository,WpdbSeriesRepository,WpdbTransactionManager,WpdbWorkRepository};
use Biblio\Core\Library\{Library,LibraryId,LibraryName};
use Biblio\Core\Notes\PrivateNoteRepository;
use Biblio\Core\Reading\ReadingRoundRepository;
use DateTimeImmutable;

final readonly class SeriesMigrationTestClock implements MigrationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-17T10:00:00.123456+00:00");
    }
}

final class SeriesMigrationParticipantTest extends PersistenceIntegrationTestCase
{
    public function testSeriesUsesDeterministicIdentityWithoutNameReuseAndReplaysExactly(): void
    {
        $fixture = $this->fixture("series-replay");
        $fixture["series"]->save(new Series(new SeriesId("unrelated-series"), "Exact Name"));
        $record = $this->seriesRecord("series/source", "Exact Name");

        $first = $this->apply($fixture, $fixture["series_participant"], $record);
        $targetId = $first["outcome"]->mappings()[0]->targetId();
        self::assertSame(
            SeriesMigrationWriter::targetSeriesId("series/source")->value(),
            $targetId
        );
        self::assertNotSame("unrelated-series", $targetId);
        self::assertSame(MappingDisposition::Created, $first["outcome"]->mappings()[0]->disposition());
        self::assertTrue($this->targetExists($fixture, $record, $first));

        $later = $this->laterRun($fixture, "series-replay-later");
        $replay = $this->apply($later, $later["series_participant"], $record);
        self::assertSame($targetId, $replay["outcome"]->mappings()[0]->targetId());
        self::assertSame(MappingDisposition::Reused, $replay["outcome"]->mappings()[0]->disposition());

        $changed = $this->laterRun($later, "series-replay-changed");
        try {
            $this->apply(
                $changed,
                $changed["series_participant"],
                $this->seriesRecord("series/source", "Changed Name")
            );
            self::fail("Changed Series bytes were silently replayed.");
        } catch (SeriesMigrationFailure $failure) {
            self::assertSame(SeriesMigrationReason::DivergentReplay, $failure->reason());
        }
        self::assertSame("Exact Name", $fixture["series"]->find(new SeriesId($targetId))?->displayName());
    }

    public function testMembershipUsesExactDependenciesConvergesAndRejectsPositionConflict(): void
    {
        $fixture = $this->fixture("membership");
        $this->mapWork($fixture, "work/source", "work-target");
        $this->apply(
            $fixture,
            $fixture["series_participant"],
            $this->seriesRecord("series/source", "Membership Series")
        );

        $firstRecord = $this->membershipRecord("membership/representative", "2");
        $first = $this->apply(
            $fixture,
            $fixture["membership_participant"],
            $firstRecord
        );
        $second = $this->apply(
            $fixture,
            $fixture["membership_participant"],
            $this->membershipRecord("membership/alias", "2")
        );
        self::assertSame(MappingDisposition::Created, $first["outcome"]->mappings()[0]->disposition());
        self::assertSame(MappingDisposition::Reused, $second["outcome"]->mappings()[0]->disposition());
        self::assertSame(
            $first["outcome"]->mappings()[0]->targetId(),
            $second["outcome"]->mappings()[0]->targetId()
        );
        self::assertCount(1, $fixture["series"]->membershipsForWorks([new WorkId("work-target")])["work-target"]);
        self::assertTrue($this->targetExists($fixture, $firstRecord, $first));

        try {
            $this->apply(
                $fixture,
                $fixture["membership_participant"],
                $this->membershipRecord("membership/conflict", "3")
            );
            self::fail("Incompatible positive Series position was silently reused.");
        } catch (SeriesMigrationFailure $failure) {
            self::assertSame(SeriesMigrationReason::MembershipConflict, $failure->reason());
        }
        self::assertSame(
            "2",
            $fixture["series"]->membershipsForWorks([new WorkId("work-target")])["work-target"][0]->position()->value()
        );

        $later = $this->laterRun($fixture, "membership-replay");
        $replay = $this->apply(
            $later,
            $later["membership_participant"],
            $firstRecord
        );
        self::assertSame(MappingDisposition::Reused, $replay["outcome"]->mappings()[0]->disposition());

        $changed = $this->laterRun($later, "membership-changed");
        try {
            $this->apply(
                $changed,
                $changed["membership_participant"],
                $this->membershipRecord("membership/representative", "3")
            );
            self::fail("Changed membership payload was silently replayed.");
        } catch (SeriesMigrationFailure $failure) {
            self::assertSame(SeriesMigrationReason::DivergentReplay, $failure->reason());
        }
    }

    public function testUnknownPositionRemainsNullAndMissingDependenciesFailClosed(): void
    {
        $fixture = $this->fixture("unknown");
        $this->mapWork($fixture, "work/source", "work-target");
        $this->apply(
            $fixture,
            $fixture["series_participant"],
            $this->seriesRecord("series/source", "Unknown Position")
        );
        $this->apply(
            $fixture,
            $fixture["membership_participant"],
            $this->membershipRecord("membership/unknown", null)
        );
        self::assertNull(
            $fixture["series"]->membershipsForWorks([new WorkId("work-target")])["work-target"][0]->position()->value()
        );

        $missing = $this->fixture("missing");
        try {
            $this->apply(
                $missing,
                $missing["membership_participant"],
                $this->membershipRecord("membership/missing", "1")
            );
            self::fail("Missing Work/Series dependencies were accepted.");
        } catch (SeriesMigrationFailure $failure) {
            self::assertSame(SeriesMigrationReason::MissingTargetReference, $failure->reason());
        }
    }

    public function testOccupiedDeterministicTargetWithoutMappingFailsClosed(): void
    {
        $fixture = $this->fixture("collision");
        $target = SeriesMigrationWriter::targetSeriesId("series/collision");
        $fixture["series"]->save(new Series($target, "Unrelated Existing Series"));

        try {
            $this->apply(
                $fixture,
                $fixture["series_participant"],
                $this->seriesRecord("series/collision", "Source Series")
            );
            self::fail("Occupied deterministic Series target was reused.");
        } catch (SeriesMigrationFailure $failure) {
            self::assertSame(SeriesMigrationReason::TargetCollision, $failure->reason());
        }
        self::assertSame("Unrelated Existing Series", $fixture["series"]->find($target)?->displayName());
    }

    public function testContainedSeriesPromotionProcessesExactPriorEvidenceAndRollsBackOnDivergence(): void
    {
        $fixture = $this->fixture("promotion");
        $this->mapWork($fixture, "work/source", "work-target");
        $this->apply(
            $fixture,
            $fixture["series_participant"],
            $this->seriesRecord("series/source", "Promoted Series")
        );
        $priorFixture = $this->laterRunWithSameSnapshot(
            $fixture,
            "promotion-preservation",
            "2.48.0"
        );
        $preservation = $this->promotionPlan(
            $priorFixture,
            "exact-evidence"
        );
        $stored = $this->apply(
            $priorFixture,
            $priorFixture["preservation_participant"],
            $this->preservationRecord($preservation)
        );

        $prior = $this->preservationRow(
            $priorFixture,
            $stored["observation"]->id()
        );
        self::assertSame("awaiting_future_processing", $prior["processing_status"]);
        self::assertNull($prior["processed_at"]);

        $later = $this->laterRunWithSameSnapshot(
            $priorFixture,
            "promotion-later",
            "2.49.0"
        );
        $workApplied = $this->mapWork($later, "work/source", "work-target");
        $seriesApplied = $this->apply(
            $later,
            $later["series_participant"],
            $this->seriesRecord("series/source", "Promoted Series")
        );
        $membershipApplied = $this->apply(
            $later,
            $later["membership_participant"],
            $this->membershipRecord("membership/promoted", "2", $preservation)
        );

        $processed = $this->preservationRow($later, $stored["observation"]->id());
        self::assertSame("processed", $processed["processing_status"]);
        self::assertNotNull($processed["processed_at"]);
        self::assertSame($prior["reason_code"], $processed["reason_code"]);
        self::assertSame($prior["evidence_json"], $processed["evidence_json"]);
        self::assertSame($prior["evidence_reference"], $processed["evidence_reference"]);
        self::assertCount(1, $later["series"]->membershipsForWorks([new WorkId("work-target")])["work-target"]);

        $reconciliation = $this->reconciliation($later);
        $priorReport = $reconciliation->reconcile(
            $priorFixture["run"],
            $this->preparedPlan($priorFixture, [$stored])
        );
        self::assertTrue(
            $priorReport->accepted(),
            json_encode($priorReport->toArray(), JSON_THROW_ON_ERROR)
        );
        self::assertTrue($reconciliation->reconcile(
            $later["run"],
            $this->preparedPlan(
                $later,
                [$workApplied, $seriesApplied, $membershipApplied]
            )
        )->accepted());

        $this->database->update(
            $this->tableNames->migrationPreservations(),
            ["evidence_json" => "{}"],
            ["observation_id" => $stored["observation"]->id()],
            ["%s"],
            ["%s"]
        );
        $mutated = $reconciliation->reconcile(
            $priorFixture["run"],
            $this->preparedPlan($priorFixture, [$stored])
        );
        self::assertFalse($mutated->accepted());
        self::assertSame(1, $mutated->brokenTargetCount());

        $divergent = $this->fixture("promotion-divergent");
        $this->mapWork($divergent, "work/source", "work-target-divergent");
        $this->apply(
            $divergent,
            $divergent["series_participant"],
            $this->seriesRecord("series/divergent", "Divergent Series")
        );
        $divergentPreservation = $this->laterRunWithSameSnapshot(
            $divergent,
            "promotion-divergent-preservation",
            "2.48.0"
        );
        $storedDivergent = $this->apply(
            $divergentPreservation,
            $divergentPreservation["preservation_participant"],
            $this->preservationRecord($this->promotionPlan(
                $divergentPreservation,
                "old-evidence"
            ))
        );
        $laterDivergent = $this->laterRunWithSameSnapshot(
            $divergentPreservation,
            "promotion-divergent-later",
            "2.49.0"
        );
        $this->mapWork(
            $laterDivergent,
            "work/source",
            "work-target-divergent"
        );
        $this->apply(
            $laterDivergent,
            $laterDivergent["series_participant"],
            $this->seriesRecord("series/divergent", "Divergent Series")
        );

        try {
            $this->apply(
                $laterDivergent,
                $laterDivergent["membership_participant"],
                $this->membershipRecord(
                    "membership/promoted",
                    "2",
                    $this->promotionPlan($laterDivergent, "changed-evidence"),
                    "series/divergent"
                )
            );
            self::fail("Divergent prior contained-Series evidence was silently promoted.");
        } catch (SeriesMigrationFailure $failure) {
            self::assertSame(SeriesMigrationReason::DivergentReplay, $failure->reason());
        }
        self::assertSame(
            "awaiting_future_processing",
            $this->preservationRow($laterDivergent, $storedDivergent["observation"]->id())["processing_status"]
        );
        self::assertSame(
            [],
            $laterDivergent["series"]->membershipsForWorks([new WorkId("work-target-divergent")])["work-target-divergent"]
        );
    }

    public function testContainedSeriesPromotionRejectsUnrelatedIdentityAndUnadmittedLane(): void
    {
        $fixture = $this->fixture("promotion-policy");
        $this->mapWork($fixture, "work/source", "work-target-policy");
        $this->apply(
            $fixture,
            $fixture["series_participant"],
            $this->seriesRecord("series/source", "Policy Series")
        );

        foreach ([
            [
                "record" => "membership/promoted",
                "plan" => $this->promotionPlan(
                    $fixture,
                    "exact-evidence",
                    "membership/unrelated"
                ),
            ],
            [
                "record" => "membership/promoted-other-lane",
                "plan" => $this->promotionPlan(
                    $fixture,
                    "exact-evidence",
                    "membership/promoted-other-lane",
                    "current_v1_contained_work_isbn",
                    "contained_work_isbn_deferred"
                ),
            ],
        ] as $case) {
            try {
                $this->apply(
                    $fixture,
                    $fixture["membership_participant"],
                    $this->membershipRecord(
                        $case["record"],
                        "2",
                        $case["plan"]
                    )
                );
                self::fail("Unadmitted Series preservation promotion was accepted.");
            } catch (SeriesMigrationFailure $failure) {
                self::assertSame(
                    SeriesMigrationReason::DivergentReplay,
                    $failure->reason()
                );
            }
        }

        self::assertSame(
            [],
            $fixture["series"]->membershipsForWorks([new WorkId("work-target-policy")])["work-target-policy"]
        );
    }

    /** @return array<string,mixed> */
    private function fixture(string $suffix): array
    {
        $library = new LibraryId("series-migration-library-{$suffix}");
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($library, new LibraryName("Library {$suffix}"))
        );
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $clock = new SeriesMigrationTestClock();
        $run = $ledger->beginOrResume(MigrationRun::start(
            "series-migration-run-{$suffix}",
            "synthetic",
            hash("sha256", "snapshot-{$suffix}"),
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.45.0",
            new UserId("series-migration-user-{$suffix}"),
            $library,
            MigrationMode::Apply,
            $clock->now()
        ));
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $series = new WpdbSeriesRepository($this->database, $this->tableNames);
        $writer = new SeriesMigrationWriter(
            $ledger,
            $series,
            $works,
            new SeriesPreservationPromotionPolicy(
                "current_v1_contained_work_series",
                "contained_work_series_deferred",
                "synthetic-adapter",
                $run->sourceFamily(),
                (string) $run->sourceVersion(),
                $run->sourceSnapshot(),
                "series-test-contract",
                "data/books.json",
                "books",
                "containedWorks"
            )
        );
        $preservationGuard = new PreservedSourceEvidencePlanGuard(
            new PreservedSourceEvidenceAdmissionRegistry([
                new PreservedSourceEvidenceAdmission(
                    "current_v1_contained_work_series",
                    "contained_work_series_deferred",
                    PreservedSourceEvidencePrivacy::OrdinarySource
                ),
            ])
        );
        return $this->withRunServices([
            "library" => $library,
            "ledger" => $ledger,
            "transactions" => new WpdbTransactionManager($this->database),
            "clock" => $clock,
            "run" => $run,
            "works" => $works,
            "series" => $series,
            "series_participant" => new CatalogSeriesMigrationParticipant($writer),
            "membership_participant" => new CatalogWorkSeriesMigrationParticipant($writer),
            "preservation_participant" => new PreservedSourceEvidenceMigrationParticipant(
                $ledger,
                $preservationGuard
            ),
        ]);
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function withRunServices(array $fixture): array
    {
        $fixture["target"] = new MigrationPlanningTarget(new PersonalMigrationTarget(
            $fixture["run"]->targetUserId(),
            $fixture["library"],
            new LibraryName("Migration target"),
            new PersonalMigrationTargetReadiness([])
        ));
        $fixture["observe"] = new ObserveSourceRecordService($fixture["ledger"], $fixture["clock"]);
        $fixture["commit"] = new CommitMigrationRecordService(
            $fixture["ledger"],
            $fixture["transactions"],
            $fixture["clock"]
        );
        return $fixture;
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function laterRun(array $fixture, string $suffix): array
    {
        $fixture["ledger"]->releaseRunLock($fixture["run"]->id());
        $fixture["run"] = $fixture["ledger"]->beginOrResume(MigrationRun::start(
            "series-migration-run-{$suffix}",
            "synthetic",
            hash("sha256", "snapshot-{$suffix}"),
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.45.0",
            $fixture["run"]->targetUserId(),
            $fixture["library"],
            MigrationMode::Apply,
            $fixture["clock"]->now()
        ));
        return $this->withRunServices($fixture);
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function laterRunWithSameSnapshot(
        array $fixture,
        string $suffix,
        string $migratorVersion
    ): array
    {
        $fixture["ledger"]->releaseRunLock($fixture["run"]->id());
        $fixture["run"] = $fixture["ledger"]->beginOrResume(MigrationRun::start(
            "series-migration-run-{$suffix}",
            $fixture["run"]->sourceFamily(),
            $fixture["run"]->sourceSnapshot(),
            $fixture["run"]->sourceFingerprint(),
            $fixture["run"]->sourceVersion(),
            $migratorVersion,
            $fixture["run"]->targetUserId(),
            $fixture["library"],
            MigrationMode::Apply,
            $fixture["clock"]->now()
        ));
        return $this->withRunServices($fixture);
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function mapWork(array $fixture, string $sourceId, string $targetId): array
    {
        if ($fixture["works"]->find(new WorkId($targetId)) === null) {
            $fixture["works"]->add(new Work(new WorkId($targetId), "Mapped Work"));
        }
        $record = MigrationSourceRecord::typed(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new CatalogWorkPlan(
                "Mapped Work",
                approvedExistingWorkId: new WorkId($targetId)
            )
        );
        $observation = $this->observe($fixture, $record);
        $outcome = $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping("work", $targetId, MappingDisposition::Reused),
            ])
        );
        $plan = new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [[
                "operation" => "create_or_reuse_work",
                "target_type" => "work",
                "target_id" => $targetId,
            ]],
            typedPlan: $record->typedPlan()
        );
        return compact(
            "record",
            "observation",
            "outcome",
            "plan"
        );
    }

    private function seriesRecord(string $sourceId, string $name): MigrationSourceRecord
    {
        return MigrationSourceRecord::typed(
            CatalogSeriesMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new CatalogSeriesPlan($name, "series-test-contract")
        );
    }

    private function membershipRecord(
        string $sourceId,
        ?string $position,
        ?PreservedSourceEvidencePlan $promotedPriorPreservation = null,
        string $seriesSourceId = "series/source"
    ): MigrationSourceRecord
    {
        return MigrationSourceRecord::typed(
            CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new CatalogWorkSeriesPlan(
                "work/source",
                $seriesSourceId,
                $position === null ? SeriesPosition::unknown() : SeriesPosition::known($position),
                "series-test-contract",
                $promotedPriorPreservation
            )
        );
    }

    /** @param array<string,mixed> $fixture */
    private function promotionPlan(
        array $fixture,
        string $evidence,
        string $sourceIdentity = "membership/promoted",
        string $evidenceType = "current_v1_contained_work_series",
        string $reasonCode = "contained_work_series_deferred"
    ): PreservedSourceEvidencePlan
    {
        return new PreservedSourceEvidencePlan(
            $sourceIdentity,
            $evidenceType,
            $reasonCode,
            "synthetic-adapter",
            $fixture["run"]->sourceFamily(),
            (string) $fixture["run"]->sourceVersion(),
            $fixture["run"]->sourceSnapshot(),
            "series-test-contract",
            "data/books.json",
            "books",
            "parent-1",
            "containedWorks",
            DeterministicJson::hash(["series" => $evidence]),
            PreservedSourceEvidencePrivacy::OrdinarySource
        );
    }

    private function preservationRecord(PreservedSourceEvidencePlan $plan): MigrationSourceRecord
    {
        return MigrationSourceRecord::typed(
            PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
            $plan->sourceIdentity(),
            $plan
        );
    }

    /**
     * @param array<string,mixed> $fixture
     * @return array{reason_code:string,processing_status:string,processed_at:?string,evidence_json:string,evidence_reference:string}
     */
    private function preservationRow(array $fixture, string $observationId): array
    {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT reason_code,processing_status,processed_at,evidence_json,evidence_reference "
                . "FROM `{$this->tableNames->migrationPreservations()}` WHERE observation_id=%s",
            $observationId
        ), ARRAY_A);
        self::assertIsArray($row);
        return $row;
    }

    /**
     * @param array<string,mixed> $fixture
     * @param list<array<string,mixed>> $applications
     */
    private function preparedPlan(
        array $fixture,
        array $applications
    ): PreparedMigrationPlan {
        $run = $fixture["run"];
        $adapter = new readonly class($run) implements MigrationSourceAdapter {
            public function __construct(private MigrationRun $run) {}
            public function adapterId(): string { return "synthetic-adapter"; }
            public function sourceFamily(): string
            {
                return $this->run->sourceFamily();
            }
            public function profile(MigrationSourcePackage $package): MigrationSourceProfile
            {
                unset($package);
                return new MigrationSourceProfile(
                    (string) $this->run->sourceVersion(),
                    []
                );
            }
            public function supportsVersion(string $sourceVersion): bool
            {
                return $sourceVersion === $this->run->sourceVersion();
            }
            public function records(
                MigrationSourcePackage $package,
                MigrationSourceProfile $profile
            ): iterable {
                unset($package, $profile);
                return [];
            }
        };
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], $run->sourceSnapshot()),
            $adapter,
            new MigrationSourceProfile((string) $run->sourceVersion(), []),
            [],
            []
        );
        $prepared = [];
        foreach ($applications as $application) {
            $prepared[] = new PreparedMigrationRecord(
                $application["record"],
                $application["participant"]
                    ?? $fixture["membership_participant"],
                $application["plan"]
            );
        }
        return new PreparedMigrationPlan(
            $inspection,
            $fixture["target"],
            $prepared,
            [],
            [],
            ["series-test-contract"],
            DeterministicJson::hash(["run" => $run->id()])
        );
    }

    /** @param array<string,mixed> $fixture */
    private function reconciliation(array $fixture): MigrationReconciliationService
    {
        return new MigrationReconciliationService(
            $fixture["ledger"],
            CurrentMigrationMappingContracts::create(),
            new CoreMigrationTargetInspector(
                $fixture["works"],
                $this->createStub(EditionRepository::class),
                $this->createStub(CatalogMigrationItemRepository::class),
                $this->createStub(EditionIdentifierClaimRepository::class),
                $this->createStub(LibraryCatalogContextRepository::class),
                $this->createStub(ItemLocalDetailsRepository::class),
                $this->createStub(AuthorRepository::class),
                $this->createStub(AuthorContributorCreditRepository::class),
                $this->createStub(ReadingRoundRepository::class),
                $this->createStub(PrivateNoteRepository::class),
                series: $fixture["series"]
            )
        );
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function apply(array $fixture, MigrationParticipant $participant, MigrationSourceRecord $record): array
    {
        $plan = $participant->plan($record, $fixture["target"]);
        $observation = $this->observe($fixture, $record);
        $outcome = $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            fn (): MigrationRecordOutcome => $participant->apply(
                $record,
                $observation,
                $plan,
                $fixture["target"]
            )
        );
        return compact("plan", "observation", "outcome", "participant", "record");
    }

    /** @param array<string,mixed> $fixture */
    private function observe(array $fixture, MigrationSourceRecord $record): SourceObservation
    {
        return $fixture["observe"]->observe(
            $fixture["run"],
            $record->sourceType(),
            $record->sourceId(),
            $record->payloadHash(),
            $record->payload()
        );
    }

    /** @param array<string,mixed> $fixture @param array<string,mixed> $applied */
    private function targetExists(array $fixture, MigrationSourceRecord $record, array $applied): bool
    {
        $snapshot = $fixture["ledger"]->snapshot($fixture["run"]->id());
        $stored = null;
        foreach ($snapshot->observations() as $observation) {
            if (
                $observation->sourceType() === $record->sourceType()
                && $observation->sourceId() === $record->sourceId()
            ) {
                $stored = $observation;
                break;
            }
        }
        self::assertNotNull($stored);
        $inspector = new CoreMigrationTargetInspector(
            $fixture["works"],
            $this->createStub(EditionRepository::class),
            $this->createStub(CatalogMigrationItemRepository::class),
            $this->createStub(EditionIdentifierClaimRepository::class),
            $this->createStub(LibraryCatalogContextRepository::class),
            $this->createStub(ItemLocalDetailsRepository::class),
            $this->createStub(AuthorRepository::class),
            $this->createStub(AuthorContributorCreditRepository::class),
            $this->createStub(ReadingRoundRepository::class),
            $this->createStub(PrivateNoteRepository::class),
            series: $fixture["series"]
        );
        return $inspector->exists(
            $fixture["run"],
            $record,
            $stored,
            $applied["outcome"]->mappings()[0],
            $snapshot
        );
    }
}
