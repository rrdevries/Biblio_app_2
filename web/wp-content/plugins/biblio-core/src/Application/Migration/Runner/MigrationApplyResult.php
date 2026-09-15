<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Application\Migration\MigrationRun;
use Biblio\Core\Application\Migration\Reconciliation\MigrationReconciliationReport;

final readonly class MigrationApplyResult
{
    public function __construct(
        private MigrationRun $run,
        private MigrationReconciliationReport $reconciliation,
        private MigrationArtifact $artifact
    ) {
    }

    public function run(): MigrationRun { return $this->run; }
    public function reconciliation(): MigrationReconciliationReport
    {
        return $this->reconciliation;
    }
    public function artifact(): MigrationArtifact { return $this->artifact; }
}
