<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reading;

use Biblio\Core\Application\Identity\PlatformUserDirectory;
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\{
    MappingDisposition,
    MigrationLedgerRepository,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    SourceObservation
};
use Biblio\Core\Application\Reading\PersonalReadingTruthRecorder;
use Biblio\Core\Catalog\{WorkId,WorkRepository};
use Biblio\Core\Reading\{
    PersonalReadingTruthContradiction,
    PersonalReadingTruthRepository,
    PersonalWorkReadingMutationLock
};

/** Joins the caller-owned MIG-FND transaction and never retries it. */
final readonly class ReadingTruthMigrationWriter
{
    public function __construct(
        private MigrationLedgerRepository $ledger,
        private PlatformUserDirectory $users,
        private WorkRepository $works,
        private PersonalReadingTruthRepository $truths,
        private PersonalReadingTruthRecorder $recorder,
        private PersonalWorkReadingMutationLock $lock
    ) {
    }

    public function apply(
        SourceObservation $observation,
        ReadingTruthPlan $plan
    ): MigrationRecordOutcome {
        $run = $this->requireRun($observation);
        $userId = $plan->targetUserId();
        if (!$run->targetUserId()->equals($userId)) {
            throw $this->failure(
                ReadingTruthMigrationReason::CrossTargetMapping,
                "Reading Truth plan does not match the migration target user."
            );
        }
        if (!$this->users->isActive($userId)) {
            throw $this->failure(
                ReadingTruthMigrationReason::CrossTargetMapping,
                "Reading Truth target user is not active."
            );
        }

        $workId = new WorkId($this->requireMappedWork($run, $plan));
        if ($this->works->find($workId) === null) {
            throw $this->failure(
                ReadingTruthMigrationReason::MissingWorkMapping,
                "Mapped Work dependency does not exist."
            );
        }

        $this->lock->acquire($userId, $workId);

        $mappedWorkId = $this->mappedTruthWork($run, $observation);
        if ($mappedWorkId !== null) {
            $truth = $this->truths->findForUserAndWork($userId, $workId);
            if (
                $mappedWorkId !== $workId->value()
                || $truth === null
                || $truth->state() !== $plan->state()
            ) {
                throw $this->failure(
                    ReadingTruthMigrationReason::DivergentReplay,
                    "Mapped Reading Truth no longer matches canonical state."
                );
            }
            $this->assertExclusiveSourceIdentity($run, $observation, $workId);

            return MigrationRecordOutcome::mapped([
                $this->mapping($workId, MappingDisposition::Reused),
            ]);
        }

        $this->assertExclusiveSourceIdentity($run, $observation, $workId);
        $existing = $this->truths->findForUserAndWork($userId, $workId);
        try {
            $truth = $this->recorder->recordForOwner(
                $userId,
                $workId,
                $plan->state()
            );
        } catch (PersonalReadingTruthContradiction $exception) {
            throw $this->failure(
                ReadingTruthMigrationReason::ContradictoryTruth,
                "Reading Truth contradicts an existing completed round.",
                $exception
            );
        }
        if ($truth->state() !== $plan->state()) {
            throw $this->failure(
                ReadingTruthMigrationReason::DivergentReplay,
                "Recorded Reading Truth diverges from its typed plan."
            );
        }

        return MigrationRecordOutcome::mapped([
            $this->mapping(
                $workId,
                $existing === null
                    ? MappingDisposition::Created
                    : MappingDisposition::Reused
            ),
        ]);
    }

    private function requireRun(SourceObservation $observation): MigrationRun
    {
        return $this->ledger->findRun($observation->runId())
            ?? throw $this->failure(
                ReadingTruthMigrationReason::CrossTargetMapping,
                "Migration run is unavailable."
            );
    }

    private function requireMappedWork(
        MigrationRun $run,
        ReadingTruthPlan $plan
    ): string {
        $targets = [];
        foreach ($this->ledger->sourceTargets(
            $run,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $plan->workSourceId()
        ) as $trace) {
            if ($trace->targetType() === "work") {
                $targets[$trace->targetId()] = true;
            }
        }
        if (count($targets) !== 1) {
            throw $this->failure(
                ReadingTruthMigrationReason::MissingWorkMapping,
                "Reading Truth requires one exact committed Work mapping."
            );
        }

        return (string) array_key_first($targets);
    }

    private function mappedTruthWork(
        MigrationRun $run,
        SourceObservation $observation
    ): ?string {
        $targets = [];
        foreach ($this->ledger->sourceTargets(
            $run,
            ReadingTruthMigrationParticipant::SOURCE_TYPE,
            $observation->sourceId()
        ) as $trace) {
            if ($trace->targetType() !== "personal_reading_truth") {
                throw $this->failure(
                    ReadingTruthMigrationReason::ConflictingTruthMapping,
                    "Reading Truth source has an unexpected target mapping type."
                );
            }
            if ($trace->payloadHash() !== $observation->payloadHash()) {
                throw $this->failure(
                    ReadingTruthMigrationReason::DivergentReplay,
                    "Changed source payload cannot reuse a Reading Truth mapping."
                );
            }
            $targets[$trace->targetId()] = true;
        }
        if (count($targets) > 1) {
            throw $this->failure(
                ReadingTruthMigrationReason::ConflictingTruthMapping,
                "Reading Truth source has conflicting target mappings."
            );
        }

        return array_key_first($targets);
    }

    private function assertExclusiveSourceIdentity(
        MigrationRun $run,
        SourceObservation $observation,
        WorkId $workId
    ): void {
        foreach ($this->ledger->targetSources(
            $run,
            "personal_reading_truth",
            $workId->value()
        ) as $trace) {
            if (
                $trace->sourceType()
                    !== ReadingTruthMigrationParticipant::SOURCE_TYPE
                || $trace->sourceId() !== $observation->sourceId()
            ) {
                throw $this->failure(
                    ReadingTruthMigrationReason::ConflictingTruthMapping,
                    "Personal Reading Truth is mapped from another source fact."
                );
            }
        }
    }

    private function mapping(
        WorkId $workId,
        MappingDisposition $disposition
    ): MigrationTargetMapping {
        return new MigrationTargetMapping(
            "personal_reading_truth",
            $workId->value(),
            $disposition
        );
    }

    private function failure(
        ReadingTruthMigrationReason $reason,
        string $message,
        ?\Throwable $previous = null
    ): ReadingTruthMigrationFailure {
        return new ReadingTruthMigrationFailure($reason, $message, $previous);
    }
}
