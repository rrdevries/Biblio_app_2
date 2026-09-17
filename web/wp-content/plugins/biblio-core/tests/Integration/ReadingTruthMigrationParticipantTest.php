<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Identity\{
    PersonalMigrationTarget,
    PersonalMigrationTargetReadiness,
    PlatformUserDirectory
};
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\{
    CommitMigrationRecordService,
    MappingDisposition,
    MigrationClock,
    MigrationLedgerRepository,
    MigrationMode,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    ObserveSourceRecordService,
    SourceObservation
};
use Biblio\Core\Application\Migration\Reading\{
    ReadingTruthMigrationFailure,
    ReadingTruthMigrationParticipant,
    ReadingTruthMigrationReason,
    ReadingTruthMigrationWriter,
    ReadingTruthPlan
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationPlanningTarget,
    MigrationSourceRecord
};
use Biblio\Core\Application\Reading\PersonalReadingTruthRecorder;
use Biblio\Core\Catalog\{Work,WorkId};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbLibraryRepository,
    WpdbMigrationLedgerRepository,
    WpdbPersonalReadingTruthRepository,
    WpdbPersonalWorkReadingMutationLock,
    WpdbReadingRoundRepository,
    WpdbTransactionManager,
    WpdbWorkRepository
};
use Biblio\Core\Library\{Library,LibraryId,LibraryName};
use Biblio\Core\Reading\{
    PersonalReadingTruthClock,
    PersonalReadingTruthState
};
use DateTimeImmutable;
use DateTimeZone;

final class ReadingTruthMigrationTestClock implements MigrationClock, PersonalReadingTruthClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            "2026-09-16 12:00:00.123456",
            new DateTimeZone("UTC")
        );
    }
}

final readonly class ReadingTruthMigrationTestUsers implements PlatformUserDirectory
{
    public function isActive(UserId $userId): bool
    {
        return !str_starts_with($userId->value(), "inactive-");
    }
}

final class ReadingTruthMigrationParticipantTest extends PersistenceIntegrationTestCase
{
    public function testAllStatesWriteForExactOwnerAndReplayWithoutDuplicateRows(): void
    {
        $fixture = $this->fixture("states");
        $states = [
            "known" => PersonalReadingTruthState::ReadKnownDateUnknown,
            "not-read" => PersonalReadingTruthState::ExplicitNotRead,
            "unknown" => PersonalReadingTruthState::Unknown,
        ];
        foreach ($states as $suffix => $state) {
            $this->mapWork($fixture, "work/{$suffix}", "target-work-{$suffix}");
            $result = $this->apply(
                $fixture,
                $this->truth($fixture, "truth/{$suffix}", "work/{$suffix}", $state)
            );
            self::assertSame(
                MappingDisposition::Created,
                $result["outcome"]->mappings()[0]->disposition()
            );
            self::assertSame(
                "target-work-{$suffix}",
                $result["outcome"]->mappings()[0]->targetId()
            );
        }
        self::assertSame(3, $this->rows($this->tableNames->personalReadingTruths()));
        self::assertSame(0, $this->rows($this->tableNames->readingRounds()));

        $fixture = $this->laterRun($fixture, "states-replay");
        foreach ($states as $suffix => $state) {
            $replay = $this->apply(
                $fixture,
                $this->truth($fixture, "truth/{$suffix}", "work/{$suffix}", $state)
            );
            self::assertSame(
                MappingDisposition::Reused,
                $replay["outcome"]->mappings()[0]->disposition()
            );
        }
        self::assertSame(3, $this->rows($this->tableNames->personalReadingTruths()));
    }

    public function testWrongUserAndMissingOrWrongWorkMappingFailClosed(): void
    {
        $fixture = $this->fixture("guards");
        $wrongUser = MigrationSourceRecord::typed(
            ReadingTruthMigrationParticipant::SOURCE_TYPE,
            "truth/wrong-user",
            new ReadingTruthPlan(
                new UserId("foreign-user"),
                "work/missing",
                PersonalReadingTruthState::Unknown
            )
        );
        try {
            $fixture["participant"]->plan($wrongUser, $fixture["target"]);
            self::fail("Foreign Reading Truth owner was accepted.");
        } catch (ReadingTruthMigrationFailure $failure) {
            self::assertSame(ReadingTruthMigrationReason::CrossTargetMapping, $failure->reason());
        }

        $missing = $this->truth(
            $fixture,
            "truth/missing-work",
            "work/missing",
            PersonalReadingTruthState::Unknown
        );
        $plan = $fixture["participant"]->plan($missing, $fixture["target"]);
        $observation = $this->observe($fixture, $missing);
        try {
            $fixture["commit"]->commit(
                $fixture["run"],
                $observation,
                fn (): MigrationRecordOutcome => $fixture["participant"]->apply(
                    $missing,
                    $observation,
                    $plan,
                    $fixture["target"]
                )
            );
            self::fail("Missing Work mapping was accepted.");
        } catch (ReadingTruthMigrationFailure $failure) {
            self::assertSame(ReadingTruthMigrationReason::MissingWorkMapping, $failure->reason());
        }
        self::assertSame(0, $this->rows($this->tableNames->personalReadingTruths()));
    }

    public function testReplayRejectsChangedCanonicalTargetState(): void
    {
        $fixture = $this->fixture("divergence");
        $this->mapWork($fixture, "work/source", "target-work");
        $record = $this->truth(
            $fixture,
            "truth/source",
            "work/source",
            PersonalReadingTruthState::ReadKnownDateUnknown
        );
        $this->apply($fixture, $record);

        $fixture["transactions"]->run(fn () => $fixture["recorder"]->recordForOwner(
            $fixture["user"],
            new WorkId("target-work"),
            PersonalReadingTruthState::Unknown
        ));
        $fixture = $this->laterRun($fixture, "divergence-replay");
        $plan = $fixture["participant"]->plan($record, $fixture["target"]);
        $observation = $this->observe($fixture, $record);
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
            self::fail("Divergent Reading Truth replay was accepted.");
        } catch (ReadingTruthMigrationFailure $failure) {
            self::assertSame(ReadingTruthMigrationReason::DivergentReplay, $failure->reason());
        }
        self::assertSame(1, $this->rows($this->tableNames->personalReadingTruths()));
    }

    /** @return array<string,mixed> */
    private function fixture(string $suffix): array
    {
        $library = new LibraryId("reading-truth-library-{$suffix}");
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($library, new LibraryName("Library {$suffix}"))
        );
        $user = new UserId("reading-truth-user-{$suffix}");
        $clock = new ReadingTruthMigrationTestClock();
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $run = $ledger->beginOrResume(MigrationRun::start(
            "reading-truth-run-{$suffix}",
            "synthetic-reading-truth",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.41.0",
            $user,
            $library,
            MigrationMode::Apply,
            $clock->now()
        ));
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $truths = new WpdbPersonalReadingTruthRepository(
            $this->database,
            $this->tableNames
        );
        $transactions = new WpdbTransactionManager($this->database);
        $readingLock = new WpdbPersonalWorkReadingMutationLock(
            $this->database,
            $this->tableNames
        );
        $recorder = new PersonalReadingTruthRecorder(
            new ReadingTruthMigrationTestUsers(),
            $works,
            new WpdbReadingRoundRepository($this->database, $this->tableNames),
            $truths,
            $readingLock,
            $clock
        );
        $writer = new ReadingTruthMigrationWriter(
            $ledger,
            new ReadingTruthMigrationTestUsers(),
            $works,
            $truths,
            $recorder,
            $readingLock
        );
        return $this->withRunServices([
            "library" => $library,
            "user" => $user,
            "clock" => $clock,
            "ledger" => $ledger,
            "run" => $run,
            "works" => $works,
            "truths" => $truths,
            "transactions" => $transactions,
            "recorder" => $recorder,
            "participant" => new ReadingTruthMigrationParticipant($writer),
        ]);
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
            "reading-truth-run-{$suffix}",
            "synthetic-reading-truth",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.41.0",
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
        $record = new MigrationSourceRecord(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            ["approved_target_id" => $targetId]
        );
        $observation = $this->observe($fixture, $record);
        $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping("work", $targetId, MappingDisposition::Reused),
            ])
        );
    }

    /** @param array<string,mixed> $fixture */
    private function truth(
        array $fixture,
        string $sourceId,
        string $workSourceId,
        PersonalReadingTruthState $state
    ): MigrationSourceRecord {
        return MigrationSourceRecord::typed(
            ReadingTruthMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new ReadingTruthPlan($fixture["user"], $workSourceId, $state)
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

    private function rows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
