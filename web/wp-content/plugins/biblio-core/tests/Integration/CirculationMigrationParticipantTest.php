<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Migration\Circulation\{
    CirculationMigrationParticipant,
    CirculationPlan
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationApplyRunner,
    MigrationBuildProvenance,
    MigrationEnvironment,
    MigrationRunner,
    MigrationSourceAdapter,
    MigrationSourceAdapterRegistry,
    MigrationSourceCategoryStrategy,
    MigrationSourcePackage,
    MigrationSourceProfile,
    MigrationSourceRecord
};
use Biblio\Core\Application\Migration\{
    BeginMigrationRunService,
    CommitMigrationRecordService,
    MigrationDisposition,
    MigrationEvidence,
    MigrationRun,
    MigrationRunLifecycleService,
    ObserveSourceRecordService
};
use Biblio\Core\Exception\ConflictException;
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

final readonly class SyntheticCirculationAdapter implements MigrationSourceAdapter
{
    public function adapterId(): string { return "synthetic-circulation"; }
    public function sourceFamily(): string { return "biblio-v1"; }

    public function profile(MigrationSourcePackage $package): MigrationSourceProfile
    {
        unset($package);
        return new MigrationSourceProfile(
            "circulation-test-1",
            ["circulation" => 3],
            categoryStrategies: [new MigrationSourceCategoryStrategy(
                "circulation",
                [CirculationMigrationParticipant::SOURCE_TYPE]
            )]
        );
    }

    public function supportsVersion(string $sourceVersion): bool
    {
        return $sourceVersion === "circulation-test-1";
    }

    public function records(
        MigrationSourcePackage $package,
        MigrationSourceProfile $profile
    ): iterable {
        unset($package, $profile);
        yield $this->record("borrowed-open", "borrowed", null, true);
        yield $this->record("lent-open", "lent_out", null, false);
        yield $this->record(
            "borrowed-conflict",
            "borrowed",
            ["value" => "2024-04-06", "precision" => "day"],
            true
        );
    }

    /** @param array{value:string,precision:string}|null $copyEnd */
    private function record(
        string $id,
        string $type,
        ?array $copyEnd,
        bool $includeBook
    ): MigrationSourceRecord {
        $source = [
            "id" => $id,
            "type" => $type,
            "counterparty" => "restricted-circulation-sentinel",
            "startDate" => ["value" => "2024-04-05", "precision" => "day"],
            "endDate" => null,
            "notes" => "restricted-circulation-note-sentinel",
        ];
        $books = $includeBook ? [[
            "book_id" => "book-{$id}",
            "record" => $source,
        ]] : [];
        $source["endDate"] = $copyEnd;
        $copies = [[
            "copy_id" => "copy-{$id}",
            "book_id" => "book-{$id}",
            "record" => $source,
        ]];

        return MigrationSourceRecord::typed(
            CirculationMigrationParticipant::SOURCE_TYPE,
            $id,
            new CirculationPlan($id, $books, $copies),
            ["v1.book:book-{$id}", "v1.copy:copy-{$id}"]
        );
    }
}

final readonly class SyntheticCirculationEnvironment implements MigrationEnvironment
{
    public function assertHealthy(): void
    {
    }

    public function provenance(): MigrationBuildProvenance
    {
        return new MigrationBuildProvenance(
            "v2.001",
            1026,
            "2.35.0",
            str_repeat("c", 40),
            false
        );
    }
}

final class CirculationMigrationParticipantTest extends PersistenceIntegrationTestCase
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

    public function testApplyPreservesQuarantinesReconcilesAndReplaysWithoutProductWrites(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application);
        $runner = $this->runner($application);
        $source = $this->source();

        $result = $runner->apply(
            $source,
            "synthetic-circulation",
            $user,
            $library,
            $this->digest($runner, $source, $user, $library)
        );
        $report = $result->reconciliation()->toArray();
        self::assertTrue($result->reconciliation()->accepted());
        self::assertSame(3, $result->reconciliation()->sourceObservationCount());
        self::assertSame(2, $report["disposition_counts"]["preserved_deferred"]);
        self::assertSame(1, $report["disposition_counts"]["quarantined"]);
        self::assertSame(0, $report["unexplained_drop_count"]);
        self::assertSame(0, $report["mapping_counts"]["total"]);
        self::assertSame(3, $report["by_participant"]
            [CirculationMigrationParticipant::SOURCE_TYPE]["observed"]);
        self::assertSame(
            2,
            $report["preservation"]["by_source_type"]
                [CirculationMigrationParticipant::SOURCE_TYPE]
        );
        self::assertSame(
            1,
            $report["quarantine"]["by_source_type"]
                [CirculationMigrationParticipant::SOURCE_TYPE]
        );
        self::assertSame(2, $this->countRows(
            $this->tableNames->migrationPreservations()
        ));
        self::assertSame(1, $this->countRows(
            $this->tableNames->migrationQuarantine()
        ));
        self::assertSame(0, $this->countRows(
            $this->tableNames->migrationTargetMappings()
        ));
        self::assertSame(0, $this->countRows($this->tableNames->externalLoans()));

        $storedEvidence = (string) $this->database->get_var(
            "SELECT evidence_json FROM `{$this->tableNames->migrationPreservations()}` "
                . "ORDER BY preservation_id LIMIT 1"
        );
        self::assertStringContainsString(
            "restricted-circulation-sentinel",
            $storedEvidence
        );
        self::assertStringContainsString('"source_state":"open"', $storedEvidence);

        $artifact = json_encode(
            $result->artifact()->payload(),
            JSON_THROW_ON_ERROR
        );
        self::assertStringNotContainsString(
            "restricted-circulation-sentinel",
            $artifact
        );
        self::assertStringNotContainsString(
            "restricted-circulation-note-sentinel",
            $artifact
        );

        $replay = $runner->apply(
            $source,
            "synthetic-circulation",
            $user,
            $library,
            $this->digest($runner, $source, $user, $library)
        );
        self::assertTrue($replay->reconciliation()->accepted());
        self::assertSame(2, $this->countRows(
            $this->tableNames->migrationPreservations()
        ));
        self::assertSame(1, $this->countRows(
            $this->tableNames->migrationQuarantine()
        ));
        self::assertSame(2, $replay->reconciliation()->toArray()
            ["execution"]["skipped_committed_observations"]);
        self::assertSame(1, $replay->reconciliation()->toArray()
            ["execution"]["skipped_terminal_observations"]);
    }

    public function testChangedPayloadUsesExistingDivergentObservationConflict(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        [$user, $library] = $this->target($application);
        $runner = $this->runner($application);
        $source = $this->source();
        $result = $runner->apply(
            $source,
            "synthetic-circulation",
            $user,
            $library,
            $this->digest($runner, $source, $user, $library)
        );

        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $observe = new ObserveSourceRecordService($ledger, new SystemMigrationClock());
        $changedPayload = ["changed" => true];
        $storedRun = $result->run();
        $runningRun = MigrationRun::start(
            $storedRun->id(),
            $storedRun->sourceFamily(),
            $storedRun->sourceSnapshot(),
            $storedRun->sourceFingerprint(),
            $storedRun->sourceVersion(),
            $storedRun->migratorVersion(),
            $storedRun->targetUserId(),
            $storedRun->targetLibraryId(),
            $storedRun->mode(),
            $storedRun->createdAt()
        );
        $this->expectException(ConflictException::class);
        $observe->observe(
            $runningRun,
            CirculationMigrationParticipant::SOURCE_TYPE,
            "borrowed-open",
            MigrationEvidence::hash($changedPayload),
            $changedPayload
        );
    }

    private function runner(CoreApplication $application): MigrationApplyRunner
    {
        $participants = $application->migrationParticipants();
        $targets = $application->personalMigrationTargets();
        $environment = new SyntheticCirculationEnvironment();
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $clock = new SystemMigrationClock();
        $planning = new MigrationRunner(
            new FilesystemMigrationSourcePackageFactory(),
            new MigrationSourceAdapterRegistry([new SyntheticCirculationAdapter()]),
            $participants,
            $targets,
            $environment
        );

        return new MigrationApplyRunner(
            $planning,
            $targets,
            $environment,
            new BeginMigrationRunService(
                $targets,
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
            "mig-02-circ-1"
        );
    }

    private function digest(
        MigrationApplyRunner $runner,
        string $source,
        UserId $user,
        LibraryId $library
    ): string {
        $digest = $runner->dryRunArtifact(
            $source,
            "synthetic-circulation",
            $user,
            $library
        )->payload()["prepared_plan"]["plan_set_digest"] ?? null;
        self::assertIsString($digest);
        return $digest;
    }

    /** @return array{UserId,LibraryId} */
    private function target(CoreApplication $application): array
    {
        $suffix = bin2hex(random_bytes(6));
        $userId = wp_create_user(
            "circulation-migration-" . $suffix,
            "synthetic-test-password",
            "circulation-migration-" . $suffix . "@example.invalid"
        );
        self::assertIsInt($userId);
        $this->createdUsers[] = $userId;
        $user = new UserId((string) $userId);
        $target = $application->personalMigrationTargets()->bootstrap($user);
        return [$user, $target->libraryId()];
    }

    private function source(): string
    {
        $path = sys_get_temp_dir() . "/biblio-circulation-" . bin2hex(random_bytes(8));
        mkdir($path, 0700, true);
        file_put_contents($path . "/source.json", "{}\n");
        $this->temporaryDirectories[] = $path;
        return $path;
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === "." || $item === "..") {
                continue;
            }
            $path = $directory . "/" . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
