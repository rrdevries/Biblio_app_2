<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

final readonly class MigrationTraceabilityQuery
{
    public function __construct(private MigrationLedgerRepository $ledger)
    {
    }

    /** @return list<MigrationTraceEntry> */
    public function targetsForSource(MigrationRun $run, string $type, string $id): array
    {
        return $this->ledger->sourceTargets($run, $type, $id);
    }

    /** @return list<MigrationTraceEntry> */
    public function sourcesForTarget(MigrationRun $run, string $type, string $id): array
    {
        return $this->ledger->targetSources($run, $type, $id);
    }
}
