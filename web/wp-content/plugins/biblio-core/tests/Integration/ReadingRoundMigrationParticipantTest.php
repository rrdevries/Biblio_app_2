<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Identity\{
    AuthenticatedUser,
    PersonalMigrationTarget,
    PersonalMigrationTargetReadiness,
    PlatformUserDirectory
};
use Biblio\Core\Application\Migration\{
    CommitMigrationRecordService,
    MappingDisposition,
    MigrationClock,
    MigrationLedgerRepository,
    MigrationMode,
    MigrationReconciliation,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    ObserveSourceRecordService,
    SourceObservation
};
use Biblio\Core\Application\Migration\Catalog\{
    CatalogItemMigrationParticipant,
    CatalogWorkMigrationParticipant
};
use Biblio\Core\Application\Migration\Reading\{
    ReadingRoundMigrationFailure,
    ReadingRoundMigrationParticipant,
    ReadingRoundMigrationReason,
    ReadingRoundMigrationWriter,
    ReadingRoundPlan
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationPlanningTarget,
    MigrationSourceRecord
};
use Biblio\Core\Application\Reading\History\{
    GetMyReadingHistoryForWorkService,
    ReadingHistoryPageSize
};
use Biblio\Core\Application\Reading\ReadingRoundCreation;
use Biblio\Core\Application\Reading\DeleteHistoricalReadingRoundService;
use Biblio\Core\Application\Reading\GetReadingSequenceService;
use Biblio\Core\Catalog\{
    Edition,
    EditionId,
    Item,
    ItemId,
    Work,
    WorkId
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbEditionRepository,
    WpdbCatalogUiReadRepository,
    WpdbItemRepository,
    WpdbLibraryRepository,
    WpdbMigrationLedgerRepository,
    WpdbPersonalWorkReadingMutationLock,
    WpdbReadingHistoryReadRepository,
    WpdbReadingRoundRepository,
    WpdbTransactionManager,
    WpdbWorkRepository
};
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\{Library,LibraryId,LibraryName};
use Biblio\Core\Reading\{
    ReadingDate,
    ReadingPeriod,
    ReadingRoundClock,
    ReadingRoundDeletionNotAllowed,
    ReadingRoundId,
    ReadingRoundIdGenerator,
    ReadingRoundOutcome,
    ReadingRoundProvenance,
    ReadingRoundVersion,
    ReadingSequenceClassification
};
use DateTimeImmutable;

final class ReadingRoundMigrationTestClock implements MigrationClock, ReadingRoundClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-15T12:00:00.123456+00:00");
    }
}

final class ReadingRoundMigrationTestIds implements ReadingRoundIdGenerator
{
    private int $next = 0;

    public function __construct(private readonly string $suffix)
    {
    }

    public function next(): ReadingRoundId
    {
        return new ReadingRoundId(
            "migrated-round-{$this->suffix}-" . ++$this->next
        );
    }
}

final readonly class ReadingRoundMigrationTestUsers implements PlatformUserDirectory
{
    public function isActive(UserId $userId): bool
    {
        return !str_starts_with($userId->value(), "inactive-");
    }
}

final readonly class ReadingRoundMigrationAuthenticatedUser implements AuthenticatedUser
{
    public function __construct(private UserId $userId)
    {
    }

    public function requireUserId(): UserId { return $this->userId; }
}

final readonly class ReadingRoundFailingOutcomeLedger implements
    MigrationLedgerRepository
{
    public function __construct(private MigrationLedgerRepository $inner)
    {
    }

    public function beginOrResume(MigrationRun $run): MigrationRun
    {
        return $this->inner->beginOrResume($run);
    }
    public function findRun(string $runId): ?MigrationRun
    {
        return $this->inner->findRun($runId);
    }
    public function saveRun(MigrationRun $run): void { $this->inner->saveRun($run); }
    public function releaseRunLock(string $runId): void
    {
        $this->inner->releaseRunLock($runId);
    }
    public function addOrFindObservation(SourceObservation $observation): SourceObservation
    {
        return $this->inner->addOrFindObservation($observation);
    }
    public function lockObservation(string $runId, string $observationId): SourceObservation
    {
        return $this->inner->lockObservation($runId, $observationId);
    }
    public function commitOutcome(
        SourceObservation $observation,
        MigrationRecordOutcome $outcome,
        DateTimeImmutable $at
    ): void {
        $this->inner->commitOutcome($observation, $outcome, $at);
        throw new PersistenceException("Injected late migration outcome failure.");
    }
    public function reconciliation(string $runId): MigrationReconciliation
    {
        return $this->inner->reconciliation($runId);
    }
    public function snapshot(string $runId): \Biblio\Core\Application\Migration\MigrationLedgerSnapshot
    {
        return $this->inner->snapshot($runId);
    }
    public function priorTargets(
        MigrationRun $run,
        SourceObservation $observation
    ): array {
        return $this->inner->priorTargets($run, $observation);
    }
    public function sourceTargets(
        MigrationRun $run,
        string $sourceType,
        string $sourceId
    ): array {
        return $this->inner->sourceTargets($run, $sourceType, $sourceId);
    }
    public function targetSources(
        MigrationRun $run,
        string $targetType,
        string $targetId
    ): array {
        return $this->inner->targetSources($run, $targetType, $targetId);
    }
}

final class ReadingRoundMigrationParticipantTest extends PersistenceIntegrationTestCase
{
    public function testCompletedStoppedAndRereadsStayDistinctAndReplayExactly(): void
    {
        $fixture = $this->fixture("rounds");
        $this->mapWork($fixture, "work/source", "work-target");
        $records = [
            $this->round(
                $fixture,
                "round/first",
                ReadingRoundOutcome::Completed,
                ReadingPeriod::ended(null, ReadingDate::year(2020))
            ),
            $this->round(
                $fixture,
                "round/reread",
                ReadingRoundOutcome::Completed,
                ReadingPeriod::ended(
                    ReadingDate::month(2020, 6),
                    ReadingDate::exact(2020, 6, 30)
                )
            ),
            $this->round(
                $fixture,
                "round/stopped",
                ReadingRoundOutcome::Stopped,
                ReadingPeriod::ended(null, ReadingDate::year(2020))
            ),
        ];

        $targetIds = [];
        foreach ($records as $record) {
            $targetIds[] = $this->apply($fixture, $record)["outcome"]
                ->mappings()[0]->targetId();
        }
        self::assertCount(3, array_unique($targetIds));
        self::assertSame(3, $this->rows($this->tableNames->readingRounds()));
        self::assertSame(0, $this->rows($this->tableNames->personalReadingTruths()));

        $fixture = $this->laterRun($fixture, "rounds-replay");
        $replay = $this->apply($fixture, $records[0])["outcome"];
        self::assertSame($targetIds[0], $replay->mappings()[0]->targetId());
        self::assertSame(
            MappingDisposition::Reused,
            $replay->mappings()[0]->disposition()
        );
        self::assertSame(3, $this->rows($this->tableNames->readingRounds()));

        $changed = $this->round(
            $fixture,
            "round/first",
            ReadingRoundOutcome::Stopped,
            ReadingPeriod::ended(null, ReadingDate::year(2020))
        );
        try {
            $this->apply($fixture, $changed);
            self::fail("Changed Reading Round payload was silently reused.");
        } catch (ConflictException $failure) {
            self::assertStringContainsString(
                "different content",
                $failure->getMessage()
            );
        }
        self::assertSame(3, $this->rows($this->tableNames->readingRounds()));
    }

    public function testActiveRequiresExplicitMappedItemAndDeclaresOnlyExactDependencies(): void
    {
        $fixture = $this->fixture("active");
        $this->mapWork($fixture, "work/source", "work-target");
        $this->mapItem(
            $fixture,
            "item/source",
            "item-target",
            "edition-target",
            "work-target"
        );
        $record = $this->round(
            $fixture,
            "round/active",
            null,
            ReadingPeriod::active(ReadingDate::exact(2024, 5, 6)),
            "item/source"
        );
        $result = $this->apply($fixture, $record);

        self::assertSame([
            [
                "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
                "source_id" => "work/source",
            ],
            [
                "source_type" => CatalogItemMigrationParticipant::SOURCE_TYPE,
                "source_id" => "item/source",
            ],
        ], $result["plan"]->dependencies());
        self::assertArrayNotHasKey("typed_plan", $result["plan"]->toArray());
        $round = $fixture["rounds"]->findForUser(
            new ReadingRoundId($result["outcome"]->mappings()[0]->targetId()),
            $fixture["user"]
        );
        self::assertNull($round?->outcome());
        self::assertSame("item-target", $round?->source()?->itemId()?->value());
        self::assertSame(
            ReadingRoundProvenance::MigrationImported,
            $round?->provenance()
        );

        $corrected = $fixture["transactions"]->run(function () use (
            $fixture,
            $round
        ) {
            self::assertNotNull($round);
            $replacement = $round->correctSource(null, $fixture["clock"]->now());
            self::assertTrue($fixture["rounds"]->replaceIfVersionMatches(
                $fixture["user"],
                $replacement,
                $round->version(),
                $round->lifecycle()
            ));

            return $fixture["rounds"]->findForUserForUpdate(
                $round->id(),
                $fixture["user"]
            );
        });
        self::assertNull($corrected?->source());
        self::assertSame(
            ReadingRoundProvenance::MigrationImported,
            $corrected?->provenance()
        );
    }

    public function testExactOwnershipPrivacyAndNormalHistoryReadAreZeroWrite(): void
    {
        $fixture = $this->fixture("read");
        $this->mapWork($fixture, "work/source", "work-target");
        $this->mapItem(
            $fixture,
            "item/read-projection",
            "item-read-projection",
            "edition-read-projection",
            "work-target"
        );
        $result = $this->apply($fixture, $this->round(
            $fixture,
            "round/read",
            ReadingRoundOutcome::Completed,
            ReadingPeriod::ended(
                ReadingDate::year(2019),
                ReadingDate::month(2020, 2)
            )
        ));
        $roundId = new ReadingRoundId(
            $result["outcome"]->mappings()[0]->targetId()
        );
        self::assertNotNull($fixture["rounds"]->findForUser(
            $roundId,
            $fixture["user"]
        ));
        self::assertNull($fixture["rounds"]->findForUser(
            $roundId,
            new UserId("other-user")
        ));

        $before = $this->writeTableCounts();
        $history = new GetMyReadingHistoryForWorkService(
            new ReadingRoundMigrationAuthenticatedUser($fixture["user"]),
            new WpdbReadingHistoryReadRepository(
                $this->database,
                $this->tableNames
            )
        );
        $page = $history->forWork(
            new WorkId("work-target"),
            pageSize: new ReadingHistoryPageSize(10)
        );
        self::assertCount(1, $page->entries());
        self::assertSame(
            ReadingRoundOutcome::Completed,
            $page->entries()[0]->outcome()
        );
        self::assertFalse($page->entries()[0]->historicalRegistration());
        $sequence = (new GetReadingSequenceService(
            new ReadingRoundMigrationAuthenticatedUser($fixture["user"]),
            $fixture["rounds"]
        ))->forWork(new WorkId("work-target"));
        self::assertCount(1, $sequence);
        self::assertSame(
            ReadingSequenceClassification::FirstRead,
            $sequence[0]->classification()
        );
        $catalog = (new WpdbCatalogUiReadRepository(
            $this->database,
            $this->tableNames
        ))->activeDetail(
            $fixture["library"],
            new ItemId("item-read-projection"),
            $fixture["user"]
        );
        self::assertSame(1, $catalog?->completedRoundCount());
        self::assertSame(0, $catalog?->historicalCompletedRoundCount());
        self::assertSame($before, $this->writeTableCounts());

        try {
            (new DeleteHistoricalReadingRoundService(
                new ReadingRoundMigrationAuthenticatedUser($fixture["user"]),
                $fixture["rounds"],
                $fixture["transactions"],
                readingLock: new WpdbPersonalWorkReadingMutationLock(
                    $this->database,
                    $this->tableNames
                )
            ))->delete($roundId, new ReadingRoundVersion(1));
            self::fail("Imported Reading Round received manual-history hard delete.");
        } catch (ReadingRoundDeletionNotAllowed) {
        }
        self::assertNotNull($fixture["rounds"]->findForUser(
            $roundId,
            $fixture["user"]
        ));
    }

    public function testMissingWorkAndWrongTargetFailClosedWithoutWrites(): void
    {
        $fixture = $this->fixture("fail-closed");
        $record = $this->round(
            $fixture,
            "round/missing-work",
            ReadingRoundOutcome::Completed,
            ReadingPeriod::ended(null, ReadingDate::exact(2020, 1, 2))
        );
        try {
            $this->apply($fixture, $record);
            self::fail("Missing Work mapping was accepted.");
        } catch (ReadingRoundMigrationFailure $failure) {
            self::assertSame(
                ReadingRoundMigrationReason::MissingWorkMapping,
                $failure->reason()
            );
        }
        self::assertSame(0, $this->rows($this->tableNames->readingRounds()));
        self::assertSame(0, $this->rows($this->tableNames->migrationTargetMappings()));

        $this->mapWork($fixture, "work/source", "work-target");
        $wrongLibraryRecord = $this->round(
            $fixture,
            "round/wrong-library",
            ReadingRoundOutcome::Completed,
            ReadingPeriod::ended(null, ReadingDate::year(2020))
        );
        $wrongLibraryPlan = $fixture["participant"]->plan(
            $wrongLibraryRecord,
            $fixture["target"]
        );
        $wrongLibraryObservation = $this->observe(
            $fixture,
            $wrongLibraryRecord
        );
        $wrongLibraryTarget = new MigrationPlanningTarget(
            new PersonalMigrationTarget(
                $fixture["user"],
                new LibraryId("another-library"),
                new LibraryName("Another Library"),
                new PersonalMigrationTargetReadiness([])
            )
        );
        try {
            $fixture["commit"]->commit(
                $fixture["run"],
                $wrongLibraryObservation,
                fn (): MigrationRecordOutcome => $fixture["participant"]->apply(
                    $wrongLibraryRecord,
                    $wrongLibraryObservation,
                    $wrongLibraryPlan,
                    $wrongLibraryTarget
                )
            );
            self::fail("Wrong planning Library was accepted at apply.");
        } catch (ReadingRoundMigrationFailure $failure) {
            self::assertSame(
                ReadingRoundMigrationReason::CrossTargetMapping,
                $failure->reason()
            );
        }
        self::assertSame(0, $this->rows($this->tableNames->readingRounds()));

        $wrongTarget = MigrationSourceRecord::typed(
            ReadingRoundMigrationParticipant::SOURCE_TYPE,
            "round/wrong-user",
            new ReadingRoundPlan(
                new UserId("another-user"),
                "work/source",
                ReadingRoundOutcome::Completed,
                ReadingPeriod::ended(null, ReadingDate::year(2020))
            )
        );
        $this->expectException(ReadingRoundMigrationFailure::class);
        $fixture["participant"]->plan($wrongTarget, $fixture["target"]);
    }

    public function testLateLedgerFailureRollsBackRoundAndMappingAtomically(): void
    {
        $fixture = $this->fixture("rollback");
        $this->mapWork($fixture, "work/source", "work-target");
        $record = $this->round(
            $fixture,
            "round/rollback",
            ReadingRoundOutcome::Completed,
            ReadingPeriod::ended(null, ReadingDate::year(2020))
        );
        $plan = $fixture["participant"]->plan($record, $fixture["target"]);
        $observation = $this->observe($fixture, $record);
        $mappingsBefore = $this->rows(
            $this->tableNames->migrationTargetMappings()
        );
        $commit = new CommitMigrationRecordService(
            new ReadingRoundFailingOutcomeLedger($fixture["ledger"]),
            $fixture["transactions"],
            $fixture["clock"]
        );

        try {
            $commit->commit(
                $fixture["run"],
                $observation,
                fn (): MigrationRecordOutcome => $fixture["participant"]->apply(
                    $record,
                    $observation,
                    $plan,
                    $fixture["target"]
                )
            );
            self::fail("Late outcome failure was hidden.");
        } catch (PersistenceException) {
        }

        self::assertSame(0, $this->rows($this->tableNames->readingRounds()));
        self::assertSame(
            $mappingsBefore,
            $this->rows($this->tableNames->migrationTargetMappings())
        );
    }

    public function testRoundPersistenceFailureRollsBackWithoutFalseMapping(): void
    {
        $fixture = $this->fixture("round-write-failure");
        $this->mapWork($fixture, "work/source", "work-target");
        $record = $this->round(
            $fixture,
            "round/write-failure",
            ReadingRoundOutcome::Completed,
            ReadingPeriod::ended(null, ReadingDate::year(2020))
        );
        $this->assertTriggeredCommitFailureRollsBack(
            $fixture,
            $record,
            $this->tableNames->readingRounds(),
            "reading_migration_round_write_failure"
        );
    }

    public function testMappingPersistenceFailureRollsBackCreatedRound(): void
    {
        $fixture = $this->fixture("mapping-write-failure");
        $this->mapWork($fixture, "work/source", "work-target");
        $record = $this->round(
            $fixture,
            "round/mapping-failure",
            ReadingRoundOutcome::Completed,
            ReadingPeriod::ended(null, ReadingDate::year(2020))
        );
        $this->assertTriggeredCommitFailureRollsBack(
            $fixture,
            $record,
            $this->tableNames->migrationTargetMappings(),
            "reading_migration_mapping_write_failure"
        );
    }

    public function testWrongWorkTargetTypeAndReverseMappingConflictFailClosed(): void
    {
        $wrongType = $this->fixture("wrong-work-type");
        $this->map(
            $wrongType,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "work/source",
            "edition",
            "edition-not-a-work"
        );
        try {
            $this->apply($wrongType, $this->round(
                $wrongType,
                "round/wrong-work-type",
                ReadingRoundOutcome::Completed,
                ReadingPeriod::ended(null, ReadingDate::year(2020))
            ));
            self::fail("Wrong Work target type was accepted.");
        } catch (ReadingRoundMigrationFailure $failure) {
            self::assertSame(
                ReadingRoundMigrationReason::MissingWorkMapping,
                $failure->reason()
            );
        }
        self::assertSame(0, $this->rows($this->tableNames->readingRounds()));

        $fixture = $this->fixture("reverse-conflict");
        $this->mapWork($fixture, "work/source", "work-target");
        $first = $this->round(
            $fixture,
            "round/source-a",
            ReadingRoundOutcome::Completed,
            ReadingPeriod::ended(null, ReadingDate::year(2020))
        );
        $targetId = $this->apply($fixture, $first)["outcome"]
            ->mappings()[0]->targetId();
        $fixture = $this->laterRun($fixture, "reverse-conflict-replay");
        $other = $this->round(
            $fixture,
            "round/source-b",
            ReadingRoundOutcome::Completed,
            ReadingPeriod::ended(null, ReadingDate::year(2020))
        );
        $otherObservation = $this->observe($fixture, $other);
        $fixture["commit"]->commit(
            $fixture["run"],
            $otherObservation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping(
                    "reading_round",
                    $targetId,
                    MappingDisposition::Reused
                ),
            ])
        );
        try {
            $this->apply($fixture, $first);
            self::fail("Reverse-mapped Reading Round target was reused.");
        } catch (ReadingRoundMigrationFailure $failure) {
            self::assertSame(
                ReadingRoundMigrationReason::ConflictingRoundMapping,
                $failure->reason()
            );
        }
        self::assertSame(1, $this->rows($this->tableNames->readingRounds()));
    }

    /** @return array<string,mixed> */
    private function fixture(string $suffix): array
    {
        $library = new LibraryId("reading-migration-library-{$suffix}");
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($library, new LibraryName("Library {$suffix}"))
        );
        $user = new UserId("reading-migration-user-{$suffix}");
        $clock = new ReadingRoundMigrationTestClock();
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $run = $ledger->beginOrResume(MigrationRun::start(
            "reading-migration-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.31.0",
            $user,
            $library,
            MigrationMode::Apply,
            $clock->now()
        ));
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $items = new WpdbItemRepository($this->database, $this->tableNames);
        $rounds = new WpdbReadingRoundRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $writer = new ReadingRoundMigrationWriter(
            $ledger,
            new ReadingRoundMigrationTestUsers(),
            $works,
            $editions,
            $items,
            $rounds,
            new ReadingRoundCreation(
                new ReadingRoundMigrationTestIds($suffix),
                $rounds
            ),
            new WpdbPersonalWorkReadingMutationLock(
                $this->database,
                $this->tableNames
            ),
            $clock
        );
        $fixture = [
            "library" => $library,
            "user" => $user,
            "clock" => $clock,
            "ledger" => $ledger,
            "run" => $run,
            "works" => $works,
            "editions" => $editions,
            "items" => $items,
            "rounds" => $rounds,
            "transactions" => $transactions,
            "participant" => new ReadingRoundMigrationParticipant($writer),
        ];

        return $this->withRunServices($fixture);
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function withRunServices(array $fixture): array
    {
        $fixture["target"] = new MigrationPlanningTarget(
            new PersonalMigrationTarget(
                $fixture["user"],
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
            "reading-migration-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.31.0",
            $fixture["user"],
            $fixture["library"],
            MigrationMode::Apply,
            $fixture["clock"]->now()
        ));

        return $this->withRunServices($fixture);
    }

    /** @param array<string,mixed> $fixture */
    private function mapWork(array $fixture, string $sourceId, string $targetId): void
    {
        $fixture["works"]->add(new Work(new WorkId($targetId), "Mapped Work"));
        $this->map(
            $fixture,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            "work",
            $targetId
        );
    }

    /** @param array<string,mixed> $fixture */
    private function mapItem(
        array $fixture,
        string $sourceId,
        string $targetId,
        string $editionId,
        string $workId
    ): void {
        $fixture["editions"]->add(new Edition(
            new EditionId($editionId),
            new WorkId($workId),
            "Mapped Edition"
        ));
        $fixture["items"]->add(Item::active(
            new ItemId($targetId),
            $fixture["library"],
            new EditionId($editionId)
        ));
        $this->map(
            $fixture,
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            "item",
            $targetId
        );
    }

    /** @param array<string,mixed> $fixture */
    private function map(
        array $fixture,
        string $sourceType,
        string $sourceId,
        string $targetType,
        string $targetId
    ): void {
        $record = new MigrationSourceRecord(
            $sourceType,
            $sourceId,
            ["approved_target_id" => $targetId]
        );
        $observation = $this->observe($fixture, $record);
        $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping(
                    $targetType,
                    $targetId,
                    MappingDisposition::Reused
                ),
            ])
        );
    }

    /** @param array<string,mixed> $fixture */
    private function round(
        array $fixture,
        string $sourceId,
        ?ReadingRoundOutcome $outcome,
        ReadingPeriod $period,
        ?string $itemSourceId = null
    ): MigrationSourceRecord {
        return MigrationSourceRecord::typed(
            ReadingRoundMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new ReadingRoundPlan(
                $fixture["user"],
                "work/source",
                $outcome,
                $period,
                $itemSourceId
            )
        );
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function apply(
        array $fixture,
        MigrationSourceRecord $record
    ): array {
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

    private function rows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    /** @param array<string,mixed> $fixture */
    private function assertTriggeredCommitFailureRollsBack(
        array $fixture,
        MigrationSourceRecord $record,
        string $targetTable,
        string $trigger
    ): void {
        $plan = $fixture["participant"]->plan($record, $fixture["target"]);
        $observation = $this->observe($fixture, $record);
        $mappingsBefore = $this->rows(
            $this->tableNames->migrationTargetMappings()
        );
        self::assertNotFalse($this->database->query(
            "CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$targetTable}` "
                . "FOR EACH ROW SIGNAL SQLSTATE '45000' "
                . "SET MESSAGE_TEXT='injected ReadingRound migration failure'"
        ));
        try {
            try {
                $fixture["commit"]->commit(
                    $fixture["run"],
                    $observation,
                    fn (): MigrationRecordOutcome => $fixture["participant"]->apply(
                        $record,
                        $observation,
                        $plan,
                        $fixture["target"]
                    )
                );
                self::fail("Injected persistence failure was hidden.");
            } catch (PersistenceException) {
            }
        } finally {
            $this->database->query("DROP TRIGGER IF EXISTS `{$trigger}`");
        }

        self::assertSame(0, $this->rows($this->tableNames->readingRounds()));
        self::assertSame(
            $mappingsBefore,
            $this->rows($this->tableNames->migrationTargetMappings())
        );
        self::assertSame("observed", $this->database->get_var(
            $this->database->prepare(
                "SELECT processing_status FROM `{$this->tableNames->migrationSourceObservations()}` "
                    . "WHERE observation_id=%s",
                $observation->id()
            )
        ));
    }

    /** @return array<string,int> */
    private function writeTableCounts(): array
    {
        return [
            "rounds" => $this->rows($this->tableNames->readingRounds()),
            "truths" => $this->rows($this->tableNames->personalReadingTruths()),
            "observations" => $this->rows(
                $this->tableNames->migrationSourceObservations()
            ),
            "mappings" => $this->rows(
                $this->tableNames->migrationTargetMappings()
            ),
        ];
    }
}
