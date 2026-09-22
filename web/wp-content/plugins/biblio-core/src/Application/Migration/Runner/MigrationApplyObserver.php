<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Application\Migration\{MigrationRecordOutcome, MigrationRun};
use Biblio\Core\Application\Migration\Reconciliation\MigrationReconciliationReport;

/** Internal orchestration seam; never a public flag that weakens apply checks. */
interface MigrationApplyObserver
{
    public function beforeBegin(PreparedMigrationPlan $prepared): void;
    public function begun(MigrationRun $run): void;
    /** Return true only for an explicitly authorized rehearsal interruption. */
    public function committed(MigrationRun $run, MigrationSourceRecord $record, MigrationRecordOutcome $outcome): bool;
    public function beforeCompletion(MigrationRun $run, PreparedMigrationPlan $prepared, MigrationReconciliationReport $report): void;
}
