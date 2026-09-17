<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Application\Identity\{
    MigrationTargetValidator,
    PersonalMigrationTargetInvalid
};
use Biblio\Core\Application\Migration\{
    BeginMigrationRunService,
    CommitMigrationRecordService,
    MappingDisposition,
    MigrationDisposition,
    MigrationLedgerObservation,
    MigrationLedgerRepository,
    MigrationMode,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationRunLifecycleService,
    MigrationRunStatus,
    ObserveSourceRecordService,
    SourceObservation
};
use Biblio\Core\Application\Migration\Reconciliation\{
    MigrationReconciliationReport,
    MigrationReconciliationService
};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Throwable;

/**
 * Internal source-neutral apply/restart coordinator. It is deliberately not
 * registered as a production CLI command until a reviewed current adapter exists.
 */
final readonly class MigrationApplyRunner
{
    public function __construct(
        private MigrationRunner $runner,
        private MigrationTargetValidator $targets,
        private MigrationEnvironment $environment,
        private BeginMigrationRunService $runs,
        private ObserveSourceRecordService $observations,
        private CommitMigrationRecordService $commits,
        private MigrationRunLifecycleService $lifecycle,
        private MigrationLedgerRepository $ledger,
        private MigrationReconciliationService $reconciliation,
        private string $migratorVersion
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $this->migratorVersion) !== 1) {
            throw new ValidationException("Migrator version is invalid.");
        }
    }

    public function apply(
        string $sourceRoot,
        string $adapterId,
        UserId $targetUserId,
        LibraryId $targetLibraryId,
        string $acceptedPlanSetDigest,
        ?int $interruptAfter = null
    ): MigrationApplyResult {
        if ($interruptAfter !== null && $interruptAfter < 1) {
            throw new ValidationException("Interruption boundary must be positive.");
        }
        $inspection = $this->runner->inspectSource($sourceRoot, $adapterId);
        $this->environment->assertHealthy();
        try {
            $validated = $this->targets->validate($targetUserId, $targetLibraryId);
        } catch (PersonalMigrationTargetInvalid $exception) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::InvalidTarget,
                "The explicit migration target is invalid.",
                $exception
            );
        }
        $target = new MigrationPlanningTarget($validated);
        $prepared = $this->runner->prepare($inspection, $target);
        if (
            preg_match('/^[a-f0-9]{64}$/D', $acceptedPlanSetDigest) !== 1
            || !hash_equals($prepared->planSetDigest(), $acceptedPlanSetDigest)
        ) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::PreparedPlanMismatch,
                "Apply preparation does not match the accepted dry-run plan."
            );
        }
        $priorReplays = $this->priorReplays($prepared);
        $run = $this->runs->begin(
            $inspection->adapter()->sourceFamily(),
            $inspection->package()->manifestDigest(),
            $inspection->package()->manifestDigest(),
            $inspection->profile()->sourceVersion(),
            $this->migratorVersion,
            $targetUserId,
            $targetLibraryId,
            MigrationMode::Apply
        );

        $execution = $this->executionCounts();
        if ($run->status() === MigrationRunStatus::Completed) {
            $execution = $this->completedExecution($run, $prepared);
            return $this->result($run, $prepared, $execution);
        }
        if (in_array($run->status(), [MigrationRunStatus::Interrupted, MigrationRunStatus::Failed], true)) {
            $run = $this->lifecycle->resume($run);
        }

        try {
            [$planned, $earlyFailures] = $this->plans($prepared);
        } catch (Throwable $exception) {
            $this->lifecycle->interrupt($run);
            throw $exception;
        }
        foreach ($earlyFailures as [$record, $reason]) {
            $observation = $this->observe($run, $record);
            if (!$this->processable($observation)) {
                $this->accountSkipped($observation, $execution);
                continue;
            }
            $this->commits->commit(
                $run,
                $observation,
                static fn (): MigrationRecordOutcome =>
                    MigrationRecordOutcome::failed($reason, false)
            );
            ++$execution["committed_observations"];
            if ($this->shouldInterrupt($execution, $interruptAfter)) {
                return $this->result(
                    $this->lifecycle->interrupt($run),
                    $prepared,
                    $execution
                );
            }
        }

        $resolved = $this->resolvedDependencies($run);
        foreach ($this->dependencyOrder($planned) as [$record, $participant, $plan]) {
            $key = $this->recordKey($record);
            if (isset($priorReplays[$key])) {
                ++$execution["reused_prior_preservations"];
                continue;
            }
            $observation = $this->observe($run, $record);
            if (!$this->processable($observation)) {
                $this->accountSkipped($observation, $execution);
                continue;
            }
            if (!$this->dependenciesResolved($plan, $resolved)) {
                $outcome = $this->commits->commit(
                    $run,
                    $observation,
                    static fn (): MigrationRecordOutcome =>
                        MigrationRecordOutcome::failed(
                            "unresolved_required_reference",
                            false
                        )
                );
            } else {
                try {
                    $outcome = $this->commits->commit(
                        $run,
                        $observation,
                        static fn (): MigrationRecordOutcome => $participant->apply(
                            $record,
                            $observation,
                            $plan,
                            $target
                        )
                    );
                } catch (Throwable $exception) {
                    if (!$exception instanceof MigrationParticipantFailure) {
                        $this->lifecycle->interrupt($run);
                        throw $exception;
                    }
                    $outcome = $this->commits->commit(
                        $run,
                        $observation,
                        static fn (): MigrationRecordOutcome =>
                            MigrationRecordOutcome::failed(
                                $exception->reasonCode(),
                                false
                            )
                    );
                }
            }
            ++$execution["committed_observations"];
            foreach ($outcome->mappings() as $mapping) {
                ++$execution[$mapping->disposition() === MappingDisposition::Created
                    ? "created_targets"
                    : "reused_targets"];
            }
            if ($this->resolvesDependencies($outcome)) {
                $resolved[$key] = true;
            }
            if ($this->shouldInterrupt($execution, $interruptAfter)) {
                return $this->result(
                    $this->lifecycle->interrupt($run),
                    $prepared,
                    $execution
                );
            }
        }

        $report = $this->reconciliation->reconcile($run, $prepared, $execution);
        if ($report->accepted()) {
            $run = $this->lifecycle->complete($run);
        } else {
            $run = $this->lifecycle->fail($run);
        }
        return $this->result($run, $prepared, $execution);
    }

    public function dryRunArtifact(
        string $sourceRoot,
        string $adapterId,
        UserId $targetUserId,
        LibraryId $targetLibraryId
    ): MigrationArtifact {
        return $this->runner->dryRun(
            $sourceRoot,
            $adapterId,
            $targetUserId,
            $targetLibraryId,
            false
        );
    }

    /**
     * @return array{list<array{MigrationSourceRecord,MigrationParticipant,PlannedMigrationRecord}>,list<array{MigrationSourceRecord,string}>}
     */
    private function plans(PreparedMigrationPlan $prepared): array
    {
        $planned = [];
        $failures = [];
        foreach ($prepared->records() as $item) {
            $planned[] = [$item->record(), $item->participant(), $item->plan()];
        }
        foreach ($prepared->failures() as $failure) {
            $failures[] = [$failure->record(), $failure->reasonCode()];
        }
        return [$planned, $failures];
    }

    /** @return array<string, true> */
    private function priorReplays(PreparedMigrationPlan $prepared): array
    {
        $replays = [];
        foreach ($prepared->records() as $item) {
            $participant = $item->participant();
            if (
                $participant instanceof CrossRunMigrationReplayParticipant
                && $participant->hasEquivalentPrior(
                    $item->record(),
                    $item->plan(),
                    $prepared->target()
                )
            ) {
                $replays[$this->recordKey($item->record())] = true;
            }
        }
        return $replays;
    }

    /**
     * @param list<array{MigrationSourceRecord,MigrationParticipant,PlannedMigrationRecord}> $planned
     * @return list<array{MigrationSourceRecord,MigrationParticipant,PlannedMigrationRecord}>
     */
    private function dependencyOrder(array $planned): array
    {
        $pending = [];
        foreach ($planned as $entry) {
            $pending[$this->recordKey($entry[0])] = $entry;
        }
        ksort($pending, SORT_STRING);
        $ordered = [];
        while ($pending !== []) {
            $selected = null;
            foreach ($pending as $key => $entry) {
                $blocked = false;
                foreach ($entry[2]->dependencies() as $dependency) {
                    if (isset($pending[$this->dependencyKey($dependency)])) {
                        $blocked = true;
                        break;
                    }
                }
                if (!$blocked) {
                    $selected = $key;
                    break;
                }
            }
            $selected ??= array_key_first($pending);
            $ordered[] = $pending[$selected];
            unset($pending[$selected]);
        }
        return $ordered;
    }

    /** @return array<string, true> */
    private function resolvedDependencies(MigrationRun $run): array
    {
        $resolved = [];
        foreach ($this->ledger->snapshot($run->id())->observations() as $observation) {
            if (
                in_array(
                    $observation->disposition(),
                    [MigrationDisposition::Mapped, MigrationDisposition::Transformed],
                    true
                )
                || (
                    $observation->disposition()
                        === MigrationDisposition::PreservedDeferred
                    && $observation->mappings() !== []
                )
            ) {
                $resolved[$observation->identityKey()] = true;
            }
        }
        return $resolved;
    }

    private function resolvesDependencies(MigrationRecordOutcome $outcome): bool
    {
        return in_array(
            $outcome->disposition(),
            [MigrationDisposition::Mapped, MigrationDisposition::Transformed],
            true
        ) || (
            $outcome->disposition() === MigrationDisposition::PreservedDeferred
            && $outcome->mappings() !== []
        );
    }

    private function observe(MigrationRun $run, MigrationSourceRecord $record): SourceObservation
    {
        return $this->observations->observe(
            $run,
            $record->sourceType(),
            $record->sourceId(),
            $record->payloadHash(),
            $record->payload()
        );
    }

    private function processable(SourceObservation $observation): bool
    {
        return in_array(
            $observation->processingStatus(),
            ["observed", "retryable_failure"],
            true
        );
    }

    /** @param array<string, true> $resolved */
    private function dependenciesResolved(
        PlannedMigrationRecord $plan,
        array $resolved
    ): bool {
        foreach ($plan->dependencies() as $dependency) {
            if (!isset($resolved[$this->dependencyKey($dependency)])) {
                return false;
            }
        }
        return true;
    }

    /** @param array{source_type:string,source_id:string} $dependency */
    private function dependencyKey(array $dependency): string
    {
        return $dependency["source_type"] . "\0" . $dependency["source_id"];
    }

    private function recordKey(MigrationSourceRecord $record): string
    {
        return $record->sourceType() . "\0" . $record->sourceId();
    }

    /** @param array<string, int> $execution */
    private function shouldInterrupt(array $execution, ?int $limit): bool
    {
        return $limit !== null && $execution["committed_observations"] >= $limit;
    }

    /** @return array<string, int> */
    private function executionCounts(): array
    {
        return [
            "committed_observations" => 0,
            "skipped_committed_observations" => 0,
            "skipped_terminal_observations" => 0,
            "created_targets" => 0,
            "reused_targets" => 0,
            "reused_prior_preservations" => 0,
        ];
    }

    /** @param array<string, int> $execution */
    private function accountSkipped(
        SourceObservation|MigrationLedgerObservation $observation,
        array &$execution
    ): void {
        $key = $observation->processingStatus() === "committed"
            ? "skipped_committed_observations"
            : "skipped_terminal_observations";
        ++$execution[$key];
    }

    /** @return array<string, int> */
    private function completedExecution(
        MigrationRun $run,
        PreparedMigrationPlan $prepared
    ): array {
        $execution = $this->executionCounts();
        $stored = [];
        foreach ($this->ledger->snapshot($run->id())->observations() as $observation) {
            $stored[$observation->identityKey()] = $observation;
        }
        foreach ($prepared->executableRecords() as $record) {
            $observation = $stored[$this->recordKey($record)] ?? null;
            if (
                $observation !== null
                && hash_equals($record->payloadHash(), $observation->payloadHash())
            ) {
                $this->accountSkipped($observation, $execution);
            }
        }
        return $execution;
    }

    /** @param array<string, int> $execution */
    private function result(
        MigrationRun $run,
        PreparedMigrationPlan $prepared,
        array $execution
    ): MigrationApplyResult {
        $inspection = $prepared->inspection();
        $report = $this->reconciliation->reconcile($run, $prepared, $execution);
        $artifact = new MigrationArtifact(
            "apply-reconciliation",
            $inspection->package()->manifestDigest(),
            [
                "migration_artifact_version" => MigrationRunner::ARTIFACT_VERSION,
                "artifact_kind" => "apply_reconciliation",
                "mode" => "apply_reconciliation",
                "build" => $this->environment->provenance()->toArray(),
                "source" => $inspection->sourcePayload(),
                "target" => [
                    "target_user_id" => $run->targetUserId()->value(),
                    "target_library_id" => $run->targetLibraryId()->value(),
                    "validation" => "valid",
                ],
                "prepared_plan" => $prepared->safeProvenance(),
                "run" => [
                    "run_id" => $run->id(),
                    "status" => $run->status()->value,
                    "summary_status" => $run->summaryStatus(),
                    "migrator_version" => $run->migratorVersion(),
                ],
                "reconciliation" => $report->toArray(),
            ]
        );
        return new MigrationApplyResult($run, $report, $artifact);
    }
}
