<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

interface PreparedMigrationContextParticipant
{
    /** @param list<string> $mappingContracts */
    public function assertPreparedContext(
        MigrationSourceRecord $record,
        PlannedMigrationRecord $plan,
        MigrationSourceInspection $inspection,
        array $mappingContracts
    ): void;
}
