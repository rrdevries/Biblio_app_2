<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Migration\{
    CommitMigrationRecordService,
    MappingDisposition,
    MigrationClock,
    MigrationMode,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    ObserveSourceRecordService
};
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Reading\{
    ReadingRoundMigrationParticipant,
    ReadingRoundPlan
};
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Catalog\{Work,WorkId};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbLibraryRepository,
    WpdbMigrationLedgerRepository,
    WpdbTransactionManager,
    WpdbWorkRepository
};
use Biblio\Core\Library\{Library,LibraryId,LibraryName};
use Biblio\Core\Reading\{ReadingDate,ReadingPeriod,ReadingRoundOutcome};
use DateTimeImmutable;
use RuntimeException;

final class ReadingRoundMigrationConcurrencyClock implements MigrationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-15T12:00:00.123456+00:00");
    }
}

final class ReadingRoundMigrationConcurrencyTest extends PersistenceIntegrationTestCase
{
    public function testSimultaneousExactObservationCreatesOneRoundAndOneMapping(): void
    {
        $this->runRace(
            ["round/race", "round/race"],
            ["already_committed", "committed"],
            1
        );
    }

    public function testDifferentRoundsForSameUserAndWorkSerializeWithoutCollapsing(): void
    {
        $this->runRace(
            ["round/race-a", "round/race-b"],
            ["committed", "committed"],
            2
        );
    }

    /** @param array{string,string} $roundSources @param array{string,string} $expectedStatuses */
    private function runRace(
        array $roundSources,
        array $expectedStatuses,
        int $expectedRounds
    ): void {
        $library = new LibraryId("reading-migration-race-library");
        $user = new UserId("reading-migration-race-user");
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($library, new LibraryName("Race Library"))
        );
        (new WpdbWorkRepository($this->database, $this->tableNames))->add(
            new Work(new WorkId("reading-migration-race-work"), "Race Work")
        );
        $clock = new ReadingRoundMigrationConcurrencyClock();
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $run = $ledger->beginOrResume(MigrationRun::start(
            "reading-migration-race-run",
            "synthetic",
            "reading-migration-race-snapshot",
            hash("sha256", "reading-migration-race-snapshot"),
            "test-1",
            "2.31.0",
            $user,
            $library,
            MigrationMode::Apply,
            $clock->now()
        ));
        $observe = new ObserveSourceRecordService($ledger, $clock);
        $commit = new CommitMigrationRecordService(
            $ledger,
            new WpdbTransactionManager($this->database),
            $clock
        );
        $workRecord = new MigrationSourceRecord(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "work/race",
            ["approved_target_id" => "reading-migration-race-work"]
        );
        $workObservation = $observe->observe(
            $run,
            $workRecord->sourceType(),
            $workRecord->sourceId(),
            $workRecord->payloadHash(),
            $workRecord->payload()
        );
        $commit->commit(
            $run,
            $workObservation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping(
                    "work",
                    "reading-migration-race-work",
                    MappingDisposition::Reused
                ),
            ])
        );

        foreach (array_unique($roundSources) as $roundSource) {
            $roundRecord = MigrationSourceRecord::typed(
                ReadingRoundMigrationParticipant::SOURCE_TYPE,
                $roundSource,
                new ReadingRoundPlan(
                    $user,
                    "work/race",
                    ReadingRoundOutcome::Completed,
                    ReadingPeriod::ended(null, ReadingDate::year(2020))
                )
            );
            $observe->observe(
                $run,
                $roundRecord->sourceType(),
                $roundRecord->sourceId(),
                $roundRecord->payloadHash(),
                $roundRecord->payload()
            );
        }

        $directory = sys_get_temp_dir() . "/biblio-reading-migration-race-"
            . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create race directory.");
        }
        $release = $directory . "/release";
        $ready = [$directory . "/a-ready", $directory . "/b-ready"];
        $workers = [];

        try {
            foreach ($ready as $index => $readyPath) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    __DIR__ . "/Support/ReadingRoundMigrationWorker.php",
                    $run->id(),
                    $user->value(),
                    $library->value(),
                    $roundSources[$index],
                    "work/race",
                    $readyPath,
                    $release,
                ], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException("Could not start migration race worker.");
                }
                $workers[] = ["process" => $process, "pipes" => $pipes];
            }

            $deadline = microtime(true) + 15;
            while (!is_file($ready[0]) || !is_file($ready[1])) {
                foreach ($workers as $worker) {
                    if (!proc_get_status($worker["process"])["running"]) {
                        throw new RuntimeException(
                            "Migration worker exited early: "
                                . stream_get_contents($worker["pipes"][2])
                        );
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Migration workers missed barrier.");
                }
                usleep(10_000);
            }
            if (file_put_contents($release, "release") === false) {
                throw new RuntimeException("Could not release migration workers.");
            }

            $results = [$this->finish($workers[0]), $this->finish($workers[1])];
            $statuses = array_column($results, "status");
            sort($statuses);
            sort($expectedStatuses);
            self::assertSame($expectedStatuses, $statuses);
            self::assertSame(
                $expectedRounds,
                $this->rows($this->tableNames->readingRounds())
            );
            self::assertSame($expectedRounds, (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->migrationTargetMappings()}` "
                    . "WHERE target_entity_type='reading_round'"
            ));
            self::assertSame(1, (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->personalWorkReadingLocks()}` "
                    . "WHERE user_id='reading-migration-race-user' "
                    . "AND work_id='reading-migration-race-work'"
            ));
        } finally {
            foreach ($workers as $worker) {
                if (
                    is_resource($worker["process"])
                    && proc_get_status($worker["process"])["running"]
                ) {
                    proc_terminate($worker["process"]);
                    proc_close($worker["process"]);
                }
            }
            foreach ([...$ready, $release] as $path) {
                if (is_file($path)) { unlink($path); }
            }
            rmdir($directory);
        }
    }

    /** @param array{process:resource,pipes:array<int,resource>} $worker */
    private function finish(array $worker): array
    {
        $output = stream_get_contents($worker["pipes"][1]);
        $error = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exit = proc_close($worker["process"]);
        if ($exit !== 0) {
            throw new RuntimeException("Migration race worker failed: {$error}");
        }

        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }

    private function rows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
