<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Application\Migration\MigrationRecordOutcome;
use Biblio\Core\Application\Migration\SourceObservation;

interface MigrationParticipant
{
    public function sourceType(): string;

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord;

    /**
     * Future apply runners call this exact planned operation from inside
     * CommitMigrationRecordService's transaction boundary.
     */
    public function apply(
        MigrationSourceRecord $record,
        SourceObservation $observation,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): MigrationRecordOutcome;
}
