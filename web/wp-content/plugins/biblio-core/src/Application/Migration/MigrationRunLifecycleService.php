<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Exception\ValidationException;

final readonly class MigrationRunLifecycleService
{
    public function __construct(
        private MigrationLedgerRepository $ledger,
        private TransactionManager $transactions,
        private MigrationClock $clock
    ) {
    }

    public function interrupt(MigrationRun $run): MigrationRun
    {
        if ($run->status() !== MigrationRunStatus::Running) {
            throw new ValidationException("Only a running migration can be interrupted.");
        }
        return $this->transition($run, MigrationRunStatus::Interrupted);
    }

    public function resume(MigrationRun $run): MigrationRun
    {
        if (!in_array($run->status(), [MigrationRunStatus::Interrupted, MigrationRunStatus::Failed], true)) {
            throw new ValidationException("Only interrupted or failed runs can resume.");
        }
        return $this->transition($run, MigrationRunStatus::Running);
    }

    public function fail(MigrationRun $run): MigrationRun
    {
        if (!in_array($run->status(), [MigrationRunStatus::Running, MigrationRunStatus::Interrupted], true)) {
            throw new ValidationException("Migration run cannot fail from its current state.");
        }
        return $this->transition($run, MigrationRunStatus::Failed);
    }

    public function complete(MigrationRun $run): MigrationRun
    {
        if ($run->status() !== MigrationRunStatus::Running) {
            throw new ValidationException("Only a running migration can complete.");
        }
        $completed = $run->withStatus(MigrationRunStatus::Completed, $this->clock->now());
        if ($run->mode() === MigrationMode::DryRun) {
            return $completed;
        }

        return $this->transactions->run(function () use ($run, $completed): MigrationRun {
            $summary = $this->ledger->reconciliation($run->id());
            if (
                !$summary->isExact()
                || $summary->uncommitted() !== 0
                || $summary->dispositions()[MigrationDisposition::Failed->value] !== 0
            ) {
                throw new ValidationException(
                    "Migration run cannot complete before exact reconciliation without failed records."
                );
            }
            $this->ledger->saveRun($completed);
            $this->ledger->releaseRunLock($run->id());

            return $completed;
        });
    }

    private function transition(MigrationRun $run, MigrationRunStatus $status): MigrationRun
    {
        $changed = $run->withStatus($status, $this->clock->now());
        if ($run->mode() === MigrationMode::DryRun) {
            return $changed;
        }

        return $this->transactions->run(function () use ($changed): MigrationRun {
            $this->ledger->saveRun($changed);
            return $changed;
        });
    }
}
