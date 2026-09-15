<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reconciliation;

use Biblio\Core\Application\Migration\MigrationLedgerObservation;
use Biblio\Core\Application\Migration\MigrationLedgerSnapshot;
use Biblio\Core\Application\Migration\MigrationRun;
use Biblio\Core\Application\Migration\MigrationTargetMapping;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;

interface MigrationTargetInspector
{
    public function exists(
        MigrationRun $run,
        MigrationSourceRecord $record,
        MigrationLedgerObservation $observation,
        MigrationTargetMapping $mapping,
        MigrationLedgerSnapshot $snapshot
    ): bool;
}
