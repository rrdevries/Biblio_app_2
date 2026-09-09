<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

final readonly class MigrationReconciliationQuery
{
    public function __construct(private MigrationLedgerRepository $ledger)
    {
    }

    public function forRun(string $runId): MigrationReconciliation
    {
        return $this->ledger->reconciliation($runId);
    }
}
