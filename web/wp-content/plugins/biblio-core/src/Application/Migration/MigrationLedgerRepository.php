<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

use DateTimeImmutable;

interface MigrationLedgerRepository
{
    public function beginOrResume(MigrationRun $run): MigrationRun;
    public function findRun(string $runId): ?MigrationRun;
    public function saveRun(MigrationRun $run): void;
    public function releaseRunLock(string $runId): void;
    public function addOrFindObservation(SourceObservation $observation): SourceObservation;
    public function lockObservation(string $runId, string $observationId): SourceObservation;
    public function commitOutcome(
        SourceObservation $observation,
        MigrationRecordOutcome $outcome,
        DateTimeImmutable $at
    ): void;
    public function reconciliation(string $runId): MigrationReconciliation;

    /** @return list<MigrationTargetMapping> */
    public function priorTargets(
        MigrationRun $run,
        SourceObservation $observation
    ): array;

    /** @return list<MigrationTraceEntry> */
    public function sourceTargets(
        MigrationRun $run,
        string $sourceType,
        string $sourceId
    ): array;

    /** @return list<MigrationTraceEntry> */
    public function targetSources(MigrationRun $run, string $targetType, string $targetId): array;
}
