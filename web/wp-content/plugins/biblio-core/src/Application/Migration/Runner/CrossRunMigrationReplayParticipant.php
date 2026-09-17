<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

interface CrossRunMigrationReplayParticipant
{
    /**
     * Returns true only when an exact committed prior observation already owns
     * the semantic result. Divergence must throw before the caller writes.
     */
    public function hasEquivalentPrior(
        MigrationSourceRecord $record,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): bool;
}
