<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Identity\{
    PersonalMigrationTarget,
    PersonalMigrationTargetReadiness
};
use Biblio\Core\Application\Migration\{
    CommitMigrationRecordService,
    MappingDisposition,
    MigrationClock,
    MigrationMode,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    ObserveSourceRecordService,
    SourceObservation
};
use Biblio\Core\Application\Migration\Catalog\{
    CatalogMigrationItemRepository,
    CatalogWorkContainmentMigrationFailure,
    CatalogWorkContainmentMigrationParticipant,
    CatalogWorkContainmentMigrationReason,
    CatalogWorkContainmentMigrationWriter,
    CatalogWorkContainmentPlan,
    CatalogWorkMigrationParticipant,
    CatalogWorkPlan
};
use Biblio\Core\Application\Migration\Reconciliation\CoreMigrationTargetInspector;
use Biblio\Core\Application\Migration\Runner\{
    MigrationPlanningTarget,
    MigrationSourceRecord
};
use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditRepository;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextRepository;
use Biblio\Core\Catalog\{
    AuthorRepository,
    ContainmentPosition,
    EditionIdentifierClaimRepository,
    EditionRepository,
    ItemLocalDetailsRepository,
    Work,
    WorkId,
    WorkTitleStatus
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbBibliographicMetadataRepository,
    WpdbLibraryRepository,
    WpdbMigrationLedgerRepository,
    WpdbTransactionManager,
    WpdbWorkRepository
};
use Biblio\Core\Library\{Library,LibraryId,LibraryName};
use Biblio\Core\Notes\PrivateNoteRepository;
use Biblio\Core\Reading\ReadingRoundRepository;
use DateTimeImmutable;

final readonly class WorkContainmentMigrationTestClock implements MigrationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-18T10:00:00.123456+00:00");
    }
}

final class WorkContainmentMigrationParticipantTest extends
    PersistenceIntegrationTestCase
{
    public function testWorkTargetInspectionRequiresExactTitleAndProvisionalStatus(): void
    {
        $fixture = $this->fixture("work-inspection");
        $cases = [
            ["exact", "Expected title", WorkTitleStatus::Provisional, true],
            ["wrong-title", "Different title", WorkTitleStatus::Provisional, false],
            ["confirmed", "Expected title", WorkTitleStatus::LibrarianConfirmed, false],
        ];

        foreach ($cases as [$suffix, $storedTitle, $status, $expected]) {
            $targetId = "work-inspection-{$suffix}";
            $record = MigrationSourceRecord::typed(
                CatalogWorkMigrationParticipant::SOURCE_TYPE,
                "work/{$suffix}",
                new CatalogWorkPlan("Expected title")
            );
            $fixture["works"]->add(new Work(
                new WorkId($targetId),
                $storedTitle,
                $status
            ));
            $observation = $this->observe($fixture, $record);
            $outcome = $fixture["commit"]->commit(
                $fixture["run"],
                $observation,
                static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                    new MigrationTargetMapping(
                        "work",
                        $targetId,
                        MappingDisposition::Created
                    ),
                ])
            );

            self::assertSame(
                $expected,
                $this->targetExists($fixture, $record, ["outcome" => $outcome]),
                "Unexpected Work inspection result for {$suffix}."
            );
        }
    }

    public function testExactRelationCreatesInspectsAndReplays(): void
    {
        $fixture = $this->fixture("replay");
        $this->mapWork($fixture, "parent/source", "parent-target");
        $this->mapWork($fixture, "child/source", "child-target");
        $record = $this->record("relation/source", 2);

        $first = $this->apply($fixture, $record);
        self::assertSame(
            MappingDisposition::Created,
            $first["outcome"]->mappings()[0]->disposition()
        );
        self::assertTrue($this->targetExists($fixture, $record, $first));

        $later = $this->laterRun($fixture, "replay-later");
        $replay = $this->apply($later, $record);
        self::assertSame(
            MappingDisposition::Reused,
            $replay["outcome"]->mappings()[0]->disposition()
        );
        self::assertSame(
            $first["outcome"]->mappings()[0]->targetId(),
            $replay["outcome"]->mappings()[0]->targetId()
        );

        $changed = $this->laterRun($later, "replay-changed");
        try {
            $this->apply($changed, $this->record("relation/source", 3));
            self::fail("Changed containment payload was silently replayed.");
        } catch (CatalogWorkContainmentMigrationFailure $failure) {
            self::assertSame(
                CatalogWorkContainmentMigrationReason::DivergentReplay,
                $failure->reason()
            );
        }
    }

    public function testDuplicateRelationReusesButOccupiedPositionFailsClosed(): void
    {
        $fixture = $this->fixture("duplicates");
        $this->mapWork($fixture, "parent/source", "parent-target");
        $this->mapWork($fixture, "child/source", "child-target");
        $this->mapWork($fixture, "other/source", "other-target");
        $this->apply($fixture, $this->record("relation/first", 1));

        $duplicate = $this->apply(
            $fixture,
            $this->record("relation/duplicate", 1)
        );
        self::assertSame(
            MappingDisposition::Reused,
            $duplicate["outcome"]->mappings()[0]->disposition()
        );

        try {
            $this->apply(
                $fixture,
                $this->record(
                    "relation/conflict",
                    1,
                    "parent/source",
                    "other/source"
                )
            );
            self::fail("Occupied containment position was accepted.");
        } catch (CatalogWorkContainmentMigrationFailure $failure) {
            self::assertSame(
                CatalogWorkContainmentMigrationReason::RelationConflict,
                $failure->reason()
            );
        }
    }

    public function testSelfReferenceAndCycleFailClosed(): void
    {
        $self = $this->fixture("self");
        $this->mapWork($self, "parent/source", "same-target");
        $this->mapExistingWork($self, "child/source", "same-target");
        try {
            $this->apply($self, $this->record("relation/self", 1));
            self::fail("Self-containment was accepted.");
        } catch (CatalogWorkContainmentMigrationFailure $failure) {
            self::assertSame(
                CatalogWorkContainmentMigrationReason::RelationConflict,
                $failure->reason()
            );
        }

        $cycle = $this->fixture("cycle");
        $this->mapWork($cycle, "parent/source", "work-a");
        $this->mapWork($cycle, "child/source", "work-b");
        $this->apply($cycle, $this->record("relation/a-b", 1));
        try {
            $this->apply(
                $cycle,
                $this->record(
                    "relation/b-a",
                    1,
                    "child/source",
                    "parent/source"
                )
            );
            self::fail("Containment cycle was accepted.");
        } catch (CatalogWorkContainmentMigrationFailure $failure) {
            self::assertSame(
                CatalogWorkContainmentMigrationReason::RelationConflict,
                $failure->reason()
            );
        }
    }

    /** @return array<string,mixed> */
    private function fixture(string $suffix): array
    {
        $library = new LibraryId("containment-library-{$suffix}");
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($library, new LibraryName("Library {$suffix}"))
        );
        $ledger = new WpdbMigrationLedgerRepository(
            $this->database,
            $this->tableNames
        );
        $clock = new WorkContainmentMigrationTestClock();
        $run = $ledger->beginOrResume(MigrationRun::start(
            "containment-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.49.0",
            new UserId("containment-user-{$suffix}"),
            $library,
            MigrationMode::Apply,
            $clock->now()
        ));
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $metadata = new WpdbBibliographicMetadataRepository(
            $this->database,
            $this->tableNames
        );
        $participant = new CatalogWorkContainmentMigrationParticipant(
            new CatalogWorkContainmentMigrationWriter(
                $ledger,
                $works,
                $metadata,
                $metadata
            )
        );
        return $this->withRunServices([
            "library" => $library,
            "ledger" => $ledger,
            "transactions" => new WpdbTransactionManager($this->database),
            "clock" => $clock,
            "run" => $run,
            "works" => $works,
            "metadata" => $metadata,
            "participant" => $participant,
        ]);
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function withRunServices(array $fixture): array
    {
        $fixture["target"] = new MigrationPlanningTarget(
            new PersonalMigrationTarget(
                $fixture["run"]->targetUserId(),
                $fixture["library"],
                new LibraryName("Migration target"),
                new PersonalMigrationTargetReadiness([])
            )
        );
        $fixture["observe"] = new ObserveSourceRecordService(
            $fixture["ledger"],
            $fixture["clock"]
        );
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
            "containment-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.49.0",
            $fixture["run"]->targetUserId(),
            $fixture["library"],
            MigrationMode::Apply,
            $fixture["clock"]->now()
        ));
        return $this->withRunServices($fixture);
    }

    /** @param array<string,mixed> $fixture */
    private function mapWork(
        array $fixture,
        string $sourceId,
        string $targetId
    ): void {
        $fixture["works"]->add(new Work(
            new WorkId($targetId),
            "Mapped {$targetId}"
        ));
        $this->mapExistingWork($fixture, $sourceId, $targetId);
    }

    /** @param array<string,mixed> $fixture */
    private function mapExistingWork(
        array $fixture,
        string $sourceId,
        string $targetId
    ): void {
        $record = new MigrationSourceRecord(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            ["approved_work_id" => $targetId]
        );
        $observation = $this->observe($fixture, $record);
        $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            static fn (): MigrationRecordOutcome =>
                MigrationRecordOutcome::mapped([
                    new MigrationTargetMapping(
                        "work",
                        $targetId,
                        MappingDisposition::Reused
                    ),
                ])
        );
    }

    private function record(
        string $sourceId,
        int $position,
        string $parent = "parent/source",
        string $child = "child/source"
    ): MigrationSourceRecord {
        return MigrationSourceRecord::typed(
            CatalogWorkContainmentMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new CatalogWorkContainmentPlan(
                $parent,
                $child,
                new ContainmentPosition($position),
                "containment-test-contract"
            )
        );
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function apply(array $fixture, MigrationSourceRecord $record): array
    {
        $plan = $fixture["participant"]->plan($record, $fixture["target"]);
        $observation = $this->observe($fixture, $record);
        $outcome = $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            fn (): MigrationRecordOutcome => $fixture["participant"]->apply(
                $record,
                $observation,
                $plan,
                $fixture["target"]
            )
        );
        return compact("plan", "observation", "outcome");
    }

    /** @param array<string,mixed> $fixture */
    private function observe(
        array $fixture,
        MigrationSourceRecord $record
    ): SourceObservation {
        return $fixture["observe"]->observe(
            $fixture["run"],
            $record->sourceType(),
            $record->sourceId(),
            $record->payloadHash(),
            $record->payload()
        );
    }

    /**
     * @param array<string,mixed> $fixture
     * @param array<string,mixed> $applied
     */
    private function targetExists(
        array $fixture,
        MigrationSourceRecord $record,
        array $applied
    ): bool {
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
            bibliographicMetadata: $fixture["metadata"]
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
