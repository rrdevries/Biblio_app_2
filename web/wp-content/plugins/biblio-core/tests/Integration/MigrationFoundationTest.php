<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Identity\PersonalMigrationTargetInvalid;
use Biblio\Core\Application\Identity\PersonalMigrationTarget;
use Biblio\Core\Application\Migration\BeginMigrationRunService;
use Biblio\Core\Application\Migration\CommitMigrationRecordService;
use Biblio\Core\Application\Migration\MappingDisposition;
use Biblio\Core\Application\Migration\MigrationClock;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\MigrationEvidence;
use Biblio\Core\Application\Migration\MigrationMode;
use Biblio\Core\Application\Migration\MigrationRecordOutcome;
use Biblio\Core\Application\Migration\MigrationReconciliationQuery;
use Biblio\Core\Application\Migration\MigrationRun;
use Biblio\Core\Application\Migration\MigrationRunIdGenerator;
use Biblio\Core\Application\Migration\MigrationRunLifecycleService;
use Biblio\Core\Application\Migration\MigrationRunStatus;
use Biblio\Core\Application\Migration\MigrationTargetMapping;
use Biblio\Core\Application\Migration\MigrationTraceabilityQuery;
use Biblio\Core\Application\Migration\ObserveSourceRecordService;
use Biblio\Core\Application\Migration\QuarantineReason;
use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbMigrationLedgerRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use RuntimeException;

final class FoundationMigrationClock implements MigrationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-09T12:00:00.123456+00:00");
    }
}

final class FoundationMigrationIds implements MigrationRunIdGenerator
{
    private int $next = 1;

    public function next(): string
    {
        return "migration-run-test-" . $this->next++;
    }
}

final class MigrationFoundationTest extends PersistenceIntegrationTestCase
{
    /** @var list<int> */
    private array $createdUsers = [];

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $userId) {
            $this->database->delete($this->database->usermeta, ["user_id" => $userId], ["%d"]);
            $this->database->delete($this->database->users, ["ID" => $userId], ["%d"]);
        }
        parent::tearDown();
    }

    public function testDryRunValidatesExplicitTargetButWritesNoLedgerOrProductData(): void
    {
        [$begin, , , , $target] = $this->foundation("dry-run");

        $run = $begin->begin(
            "biblio-v1",
            "synthetic-snapshot",
            str_repeat("a", 64),
            "synthetic-1",
            "mig-test-1",
            $target->userId(),
            $target->libraryId(),
            MigrationMode::DryRun
        );

        self::assertSame(MigrationMode::DryRun, $run->mode());
        self::assertSame(MigrationRunStatus::Running, $run->status());
        self::assertSame(0, $this->countRows($this->tableNames->migrationRuns()));
        self::assertSame(0, $this->countRows($this->tableNames->migrationSourceObservations()));

        $this->expectException(PersonalMigrationTargetInvalid::class);
        $begin->begin(
            "biblio-v1",
            "synthetic-snapshot",
            str_repeat("a", 64),
            null,
            "mig-test-1",
            $target->userId(),
            new LibraryId("not-the-designated-library"),
            MigrationMode::DryRun
        );
    }

    public function testApplyRunIsIdempotentTraceableReconciledAndTargetIsolated(): void
    {
        [$begin, $observe, $commit, $lifecycle, $target, $ledger] = $this->foundation("apply-main");
        $run = $this->beginApply($begin, $target, "snapshot-a", "a");
        $same = $this->beginApply($begin, $target, "snapshot-a", "a");
        self::assertSame($run->id(), $same->id());
        self::assertSame(1, $this->countRows($this->tableNames->migrationRuns()));

        $payload = ["title" => "Synthetic", "nested" => ["b" => 2, "a" => 1]];
        $hash = MigrationEvidence::hash($payload);
        $observation = $observe->observe($run, "book", "books/1", $hash, $payload);
        $sameObservation = $observe->observe($run, "book", "books/1", $hash, $payload);
        self::assertSame($observation->id(), $sameObservation->id());

        $outcome = $commit->commit($run, $observation, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::transformed([
                new MigrationTargetMapping("work", "work-1", MappingDisposition::Created),
                new MigrationTargetMapping("edition", "edition-1", MappingDisposition::Created),
                new MigrationTargetMapping("item", "item-1", MappingDisposition::Reused),
            ], "work_edition_item_split")
        );
        self::assertSame(MigrationDisposition::Transformed, $outcome->disposition());
        $duplicateProductWrites = 0;
        try {
            $commit->commit($run, $observation, static function () use (&$duplicateProductWrites): MigrationRecordOutcome {
                $duplicateProductWrites++;
                return MigrationRecordOutcome::mapped([
                    new MigrationTargetMapping("item", "duplicate-item", MappingDisposition::Created),
                ]);
            });
            self::fail("A committed observation was processed twice.");
        } catch (ValidationException) {
            self::assertSame(0, $duplicateProductWrites);
        }

        $trace = new MigrationTraceabilityQuery($ledger);
        self::assertCount(3, $trace->targetsForSource($run, "book", "books/1"));
        self::assertSame("books/1", $trace->sourcesForTarget($run, "item", "item-1")[0]->sourceId());
        $summary = (new MigrationReconciliationQuery($ledger))->forRun($run->id());
        self::assertSame(1, $summary->sourceTotal());
        self::assertSame(1, $summary->dispositions()["transformed"]);
        self::assertSame(3, $summary->mappingEdges());
        self::assertSame(2, $summary->createdTargets());
        self::assertSame(1, $summary->reusedTargets());

        $interrupted = $lifecycle->interrupt($run);
        self::assertSame(MigrationRunStatus::Interrupted, $interrupted->status());
        $resumed = $lifecycle->resume($interrupted);
        self::assertSame(MigrationRunStatus::Running, $resumed->status());
        $completed = $lifecycle->complete($resumed);
        self::assertSame(MigrationRunStatus::Completed, $completed->status());
        self::assertSame(0, $this->countRows($this->tableNames->migrationRunLocks()));
        $completedRerun = $this->beginApply($begin, $target, "snapshot-a", "a");
        self::assertSame($completed->id(), $completedRerun->id());
        self::assertSame(MigrationRunStatus::Completed, $completedRerun->status());
        self::assertSame(0, $this->countRows($this->tableNames->migrationRunLocks()));

        $later = $this->beginApply($begin, $target, "snapshot-b", "b");
        $laterObservation = $observe->observe($later, "book", "books/1", $hash, $payload);
        $priorTargets = $ledger->priorTargets($later, $laterObservation);
        self::assertCount(3, $priorTargets);
        foreach ($priorTargets as $mapping) {
            self::assertSame(MappingDisposition::Reused, $mapping->disposition());
        }
        $commit->commit($later, $laterObservation, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::mapped($priorTargets)
        );
        $laterSummary = (new MigrationReconciliationQuery($ledger))->forRun($later->id());
        self::assertSame(1, $laterSummary->sourceTotal());
        self::assertSame(3, $laterSummary->reusedTargets());
        $lifecycle->complete($later);

        $changed = $this->beginApply($begin, $target, "snapshot-c", "c");
        $changedPayload = ["title" => "Synthetic, revised"];
        $changedObservation = $observe->observe(
            $changed,
            "book",
            "books/1",
            MigrationEvidence::hash($changedPayload),
            $changedPayload
        );
        self::assertNotSame($laterObservation->id(), $changedObservation->id());
        self::assertSame([], $ledger->priorTargets($changed, $changedObservation));

        [, , , , $otherTarget] = $this->foundation("apply-other");
        $otherRun = $this->beginApply($begin, $otherTarget, "snapshot-other", "c");
        self::assertSame($otherTarget->userId()->value(), $otherRun->targetUserId()->value());
        self::assertNotSame($changed->targetLibraryId()->value(), $otherRun->targetLibraryId()->value());
        $otherObservation = $observe->observe($otherRun, "book", "books/1", $hash, $payload);
        self::assertSame([], $ledger->priorTargets($otherRun, $otherObservation));
        self::assertSame([], $trace->targetsForSource($otherRun, "book", "books/1"));
        self::assertSame([], $trace->sourcesForTarget($otherRun, "item", "item-1"));
    }

    public function testPreservationQuarantineFailureDropAndRetryRemainDistinct(): void
    {
        [$begin, $observe, $commit, $lifecycle, $target, $ledger] = $this->foundation("outcomes");
        $run = $this->beginApply($begin, $target, "snapshot-outcomes", "d");

        $preserved = $this->observation($observe, $run, "wishlist", "wishlist/1");
        $commit->commit($run, $preserved, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::preserved(
                "wishlist_target_deferred",
                ["intent" => "synthetic"],
                "artifact://snapshot-outcomes/wishlist-1"
            )
        );

        $quarantined = $this->observation($observe, $run, "book", "books/invalid");
        $commit->commit($run, $quarantined, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::quarantined(
                QuarantineReason::InvalidIsbnClaim,
                "The synthetic ISBN claim is invalid.",
                ["field" => "isbn"]
            )
        );

        $failed = $this->observation($observe, $run, "author", "authors/1");
        $commit->commit($run, $failed, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::failed("temporary_dependency_failure", true)
        );
        try {
            $lifecycle->complete($run);
            self::fail("A run with a failed observation completed.");
        } catch (ValidationException $exception) {
            self::assertSame(
                "Migration run cannot complete before exact reconciliation without failed records.",
                $exception->getMessage()
            );
        }
        $commit->commit($run, $failed, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::mapped([
                new MigrationTargetMapping("author", "author-1", MappingDisposition::Created),
            ])
        );
        self::assertSame(
            "temporary_dependency_failure",
            $this->database->get_var(
                "SELECT reason_code FROM " . $this->tableNames->migrationSourceObservations()
                    . " WHERE observation_id='" . $failed->id() . "'"
            )
        );

        $dropped = $this->observation($observe, $run, "preference", "preferences/legacy");
        $commit->commit($run, $dropped, static fn (): MigrationRecordOutcome =>
            MigrationRecordOutcome::intentionallyDropped("explicitly_approved_legacy_preference")
        );

        $summary = (new MigrationReconciliationQuery($ledger))->forRun($run->id());
        self::assertSame(4, $summary->sourceTotal());
        self::assertSame(1, $summary->dispositions()["mapped"]);
        self::assertSame(1, $summary->dispositions()["preserved_deferred"]);
        self::assertSame(1, $summary->dispositions()["quarantined"]);
        self::assertSame(1, $summary->dispositions()["intentionally_dropped"]);
        self::assertSame(0, $summary->dispositions()["failed"]);
        self::assertSame(1, $this->countRows($this->tableNames->migrationPreservations()));
        self::assertSame(1, $this->countRows($this->tableNames->migrationQuarantine()));
        self::assertSame(MigrationRunStatus::Completed, $lifecycle->complete($run)->status());
    }

    public function testProductRollbackCannotLeaveFalseSuccessfulMappingAndRunLockFailsClosed(): void
    {
        [$begin, $observe, $commit, , $target] = $this->foundation("rollback");
        $run = $this->beginApply($begin, $target, "snapshot-rollback", "e");
        $observation = $this->observation($observe, $run, "book", "books/rollback");

        try {
            $commit->commit($run, $observation, function (): MigrationRecordOutcome {
                $this->database->insert(
                    $this->tableNames->works(),
                    ["work_id" => "rolled-back-work", "work_title" => "Synthetic rollback"]
                );
                throw new RuntimeException("synthetic product failure");
            });
            self::fail("Synthetic product failure was hidden.");
        } catch (RuntimeException $exception) {
            self::assertSame("synthetic product failure", $exception->getMessage());
        }

        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM " . $this->tableNames->works()
                . " WHERE work_id='rolled-back-work'"
        ));
        self::assertSame(0, $this->countRows($this->tableNames->migrationTargetMappings()));
        self::assertNull($this->database->get_var(
            "SELECT disposition FROM " . $this->tableNames->migrationSourceObservations()
                . " WHERE observation_id='" . $observation->id() . "'"
        ));

        $this->expectException(ConflictException::class);
        $this->beginApply($begin, $target, "competing-snapshot", "f");
    }

    private function beginApply(
        BeginMigrationRunService $begin,
        PersonalMigrationTarget $target,
        string $snapshot,
        string $hashSeed
    ): MigrationRun
    {
        return $begin->begin(
            "biblio-v1",
            $snapshot,
            str_repeat($hashSeed, 64),
            "synthetic-1",
            "mig-test-1",
            $target->userId(),
            $target->libraryId(),
            MigrationMode::Apply
        );
    }

    private function observation(
        ObserveSourceRecordService $observe,
        MigrationRun $run,
        string $type,
        string $id
    ): \Biblio\Core\Application\Migration\SourceObservation {
        $payload = ["source_id" => $id];
        return $observe->observe($run, $type, $id, MigrationEvidence::hash($payload), $payload);
    }

    /** @return array{BeginMigrationRunService,ObserveSourceRecordService,CommitMigrationRecordService,MigrationRunLifecycleService,PersonalMigrationTarget,WpdbMigrationLedgerRepository} */
    private function foundation(string $login): array
    {
        $userId = wp_create_user($login, "synthetic-test-password", $login . "@example.invalid");
        if (!is_int($userId)) {
            throw new RuntimeException("Could not create synthetic migration test user.");
        }
        $this->createdUsers[] = $userId;
        $application = (new ProductionComposition($this->database))->application();
        $target = $application->personalMigrationTargets()->bootstrap(new UserId((string) $userId));
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $clock = new FoundationMigrationClock();
        $begin = new BeginMigrationRunService(
            $application->personalMigrationTargets(),
            $ledger,
            $transactions,
            $clock,
            new FoundationMigrationIds()
        );

        return [
            $begin,
            new ObserveSourceRecordService($ledger, $clock),
            new CommitMigrationRecordService($ledger, $transactions, $clock),
            new MigrationRunLifecycleService($ledger, $transactions, $clock),
            $target,
            $ledger,
        ];
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM " . $table);
    }
}
