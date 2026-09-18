<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

interface PreparedMigrationPreflightParticipant
{
    public function assertPreparedPreflight(
        MigrationSourceRecord $record,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): void;
}
