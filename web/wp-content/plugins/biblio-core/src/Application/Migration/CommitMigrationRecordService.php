<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Exception\ValidationException;

final readonly class CommitMigrationRecordService
{
    public function __construct(
        private MigrationLedgerRepository $ledger,
        private TransactionManager $transactions,
        private MigrationClock $clock
    ) {
    }

    /** @param callable(): MigrationRecordOutcome $productWrite */
    public function commit(
        MigrationRun $run,
        SourceObservation $observation,
        callable $productWrite
    ): MigrationRecordOutcome {
        if ($run->mode() !== MigrationMode::Apply) {
            throw new ValidationException(
                "Dry-run records cannot use the product-write transaction boundary."
            );
        }
        if ($run->status() !== MigrationRunStatus::Running) {
            throw new ValidationException("Migration run is not running.");
        }
        if ($observation->runId() !== $run->id()) {
            throw new ValidationException("Source observation belongs to another run.");
        }

        return $this->transactions->run(function () use ($run, $observation, $productWrite): MigrationRecordOutcome {
            $locked = $this->ledger->lockObservation($run->id(), $observation->id());
            if (!in_array($locked->processingStatus(), ["observed", "retryable_failure"], true)) {
                throw new ValidationException("Source observation already has a committed outcome.");
            }

            $outcome = $productWrite();
            $this->ledger->commitOutcome($locked, $outcome, $this->clock->now());

            return $outcome;
        });
    }
}
