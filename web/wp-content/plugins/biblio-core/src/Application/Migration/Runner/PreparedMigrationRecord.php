<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

final readonly class PreparedMigrationRecord
{
    public function __construct(
        private MigrationSourceRecord $record,
        private MigrationParticipant $participant,
        private PlannedMigrationRecord $plan
    ) {
    }

    public function record(): MigrationSourceRecord { return $this->record; }
    public function participant(): MigrationParticipant { return $this->participant; }
    public function plan(): PlannedMigrationRecord { return $this->plan; }
}
